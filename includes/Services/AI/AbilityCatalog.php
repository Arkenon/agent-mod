<?php

/**
 * Ability catalog.
 *
 * Backs dynamic tool loading. Instead of sending every allowed ability as a
 * tool on every request, the agent starts with a small tool set and searches
 * this catalog through agent-mod/find-abilities; the matches are then added
 * to the tool list of the following requests (see AIClientAdapter).
 *
 * The catalog is scoped to one run: the adapter hands it the abilities the
 * agent may call before the tool-calling loop starts and clears it afterwards,
 * so a search can never reveal or load an ability outside that set (Ask/Plan
 * read-only filtering, a PRO agent's own allowlist, a widget's scope).
 *
 * @package AgentMod
 * @subpackage Services\AI
 * @since 1.3.4
 */

namespace AgentMod\Services\AI;

use WP_Ability;
use WP_AI_Client_Ability_Function_Resolver;
use AgentMod\Common\Constants;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

defined('ABSPATH') || exit;

class AbilityCatalog
{
	/**
	 * Find-abilities ability name.
	 *
	 * @var string
	 * @since 1.3.4
	 */
	public const FIND = 'agent-mod/find-abilities';

	/**
	 * Characters kept from an ability description in a search result. The full
	 * description arrives with the tool definition once the ability is loaded.
	 *
	 * @var int
	 * @since 1.3.4
	 */
	private const RESULT_DESCRIPTION_LENGTH = 300;

	/**
	 * Abilities of the current run keyed by name, or null outside a run.
	 *
	 * @var array<string, WP_Ability>|null
	 * @since 1.3.4
	 */
	private ?array $scope = null;

	/**
	 * Scopes the catalog to the abilities of the current run.
	 *
	 * @param WP_Ability[] $abilities Abilities the agent may call.
	 *
	 * @return void
	 * @since 1.3.4
	 */
	public function scope(array $abilities): void
	{
		$this->scope = [];

		foreach ($abilities as $ability) {
			if ($ability instanceof WP_Ability) {
				$this->scope[$ability->get_name()] = $ability;
			}
		}
	}

	/**
	 * Ends the run scope; searches fail until the next scope() call.
	 *
	 * @return void
	 * @since 1.3.4
	 */
	public function clear(): void
	{
		$this->scope = null;
	}

	/**
	 * Whether a run scope is active.
	 *
	 * @return bool
	 * @since 1.3.4
	 */
	public function isScoped(): bool
	{
		return null !== $this->scope;
	}

	/**
	 * Searches the scoped abilities by keywords and/or category.
	 *
	 * Plain keyword scoring, no embeddings: each query word scores by where it
	 * occurs (name > label and category > description). A trailing plural "s"
	 * is dropped so "posts" still matches "list-post". Matches scoring below half
	 * of the best match are left out. System abilities are always loaded when
	 * offered, so they are never returned.
	 *
	 * @param string $query    Keywords, e.g. "update menu navigation".
	 * @param string $category Optional category slug to restrict the search to.
	 * @param int    $limit    Most results returned.
	 *
	 * @return array<int, array{name: string, tool: string, description: string, category: string, readonly: bool}>
	 * @since 1.3.4
	 */
	public function search(string $query, string $category = '', int $limit = Constants::AI_FIND_ABILITIES_LIMIT): array
	{
		$words    = $this->queryWords($query);
		$category = sanitize_key($category);
		$scored   = [];

		foreach ((array) $this->scope as $name => $ability) {
			if (AbilityResolver::isSystemAbility($ability)) {
				continue;
			}

			if ('' !== $category && $category !== $ability->get_category()) {
				continue;
			}

			$score = empty($words) ? 1 : $this->score($ability, $words);

			if ($score > 0) {
				$scored[$name] = $score;
			}
		}

		uksort($scored, static function (string $a, string $b) use ($scored): int {
			return [$scored[$b], $a] <=> [$scored[$a], $b];
		});

		// Every result becomes a tool definition in the following requests, so
		// matches that share only a common word ("update", "list") with the
		// best one are dropped rather than loaded.
		$cutoff = (int) ceil((empty($scored) ? 0 : reset($scored)) / 2);
		$scored = array_filter($scored, static function (int $score) use ($cutoff): bool {
			return $score >= $cutoff;
		});

		$results = [];

		foreach (array_slice(array_keys($scored), 0, max(1, $limit)) as $name) {
			$ability = $this->scope[$name];

			$results[] = [
				'name'        => $name,
				'tool'        => WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name($name),
				'description' => mb_substr(wp_strip_all_tags($ability->get_description()), 0, self::RESULT_DESCRIPTION_LENGTH),
				'category'    => (string) $ability->get_category(),
				'readonly'    => $this->isReadonly($ability),
			];
		}

		return $results;
	}

	/**
	 * Lists the categories of the given abilities with their ability counts.
	 *
	 * Printed in the system prompt so the agent knows what it can search for
	 * without receiving any of the definitions.
	 *
	 * @param WP_Ability[] $abilities Abilities the agent may call.
	 *
	 * @return array<string, array{label: string, count: int}> Keyed by category slug.
	 * @since 1.3.4
	 */
	public function categoryIndex(array $abilities): array
	{
		$index = [];

		foreach ($abilities as $ability) {
			if (! $ability instanceof WP_Ability || AbilityResolver::isSystemAbility($ability)) {
				continue;
			}

			$slug = (string) $ability->get_category();

			if (! isset($index[$slug])) {
				$categoryObject = function_exists('wp_get_ability_category') && '' !== $slug ? wp_get_ability_category($slug) : null;

				$index[$slug] = [
					'label' => null !== $categoryObject ? (string) $categoryObject->get_label() : $slug,
					'count' => 0,
				];
			}

			$index[$slug]['count']++;
		}

		ksort($index);

		return $index;
	}

	/**
	 * Ability names loaded during the run, in the order they first appeared.
	 *
	 * Derived from the history instead of tracked state, so a run resumed after
	 * a confirmation or a question carries the same tools without anything
	 * extra being stored: every ability a find-abilities response returned,
	 * plus every ability the model called (a call to an ability that is not in
	 * the tool list still runs when it is allowed, and keeping it loaded lets
	 * the model call it properly from then on).
	 *
	 * @param Message[] $history Wire-format history of the run.
	 *
	 * @return string[]
	 * @since 1.3.4
	 */
	public function loadedNames(array $history): array
	{
		$names = [];

		foreach ($history as $message) {
			if (! $message instanceof Message) {
				continue;
			}

			foreach ($message->getParts() as $part) {
				$call = $part->getFunctionCall();

				if ($call instanceof FunctionCall) {
					$names[] = WP_AI_Client_Ability_Function_Resolver::function_name_to_ability_name((string) $call->getName());
					continue;
				}

				$response = $part->getFunctionResponse();

				if (! $response instanceof FunctionResponse
					|| self::FIND !== WP_AI_Client_Ability_Function_Resolver::function_name_to_ability_name((string) $response->getName())
				) {
					continue;
				}

				$payload = $response->getResponse();

				foreach ((array) (is_array($payload) ? ($payload['abilities'] ?? []) : []) as $found) {
					if (is_array($found) && isset($found['name'])) {
						$names[] = (string) $found['name'];
					}
				}
			}
		}

		return array_values(array_unique($names));
	}

	/**
	 * Splits a search query into lowercase, singularized words.
	 *
	 * @param string $query Search query.
	 *
	 * @return string[]
	 * @since 1.3.4
	 */
	private function queryWords(string $query): array
	{
		$parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY);
		$words = [];

		foreach ((array) $parts as $word) {
			if (mb_strlen($word) < 2) {
				continue;
			}

			if (mb_strlen($word) > 3 && 's' === mb_substr($word, -1) && 'ss' !== mb_substr($word, -2)) {
				$word = mb_substr($word, 0, -1);
			}

			$words[] = $word;
		}

		return array_values(array_unique($words));
	}

	/**
	 * Scores an ability against the query words.
	 *
	 * @param WP_Ability $ability The ability.
	 * @param string[]   $words   Query words.
	 *
	 * @return int 0 when no word matches.
	 * @since 1.3.4
	 */
	private function score(WP_Ability $ability, array $words): int
	{
		$fields = [
			3 => mb_strtolower($ability->get_name()),
			2 => mb_strtolower($ability->get_label() . ' ' . $ability->get_category()),
			1 => mb_strtolower(wp_strip_all_tags($ability->get_description())),
		];

		$score = 0;

		foreach ($words as $word) {
			foreach ($fields as $weight => $text) {
				if (false !== mb_strpos($text, $word)) {
					$score += $weight;
				}
			}
		}

		return $score;
	}

	/**
	 * Whether the ability is explicitly annotated read-only.
	 *
	 * @param WP_Ability $ability The ability.
	 *
	 * @return bool
	 * @since 1.3.4
	 */
	private function isReadonly(WP_Ability $ability): bool
	{
		$annotations = $ability->get_meta_item('annotations', []);

		return is_array($annotations) && true === ($annotations['readonly'] ?? null);
	}
}
