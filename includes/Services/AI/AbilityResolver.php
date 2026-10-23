<?php

/**
 * Ability resolver.
 *
 * Resolves the list of WP_Ability instances an agent is allowed to use, based on
 * its ability source strategy ('all' or 'selected').
 *
 * @package AgentMod
 * @subpackage Services\AI
 * @since 1.0.0
 */

namespace AgentMod\Services\AI;

use WP_Ability;
use AgentMod\Services\AI\DTO\AgentConfig;
use AgentMod\Services\AskUserAbilityService;

defined('ABSPATH') || exit;

class AbilityResolver
{
	/**
	 * Resolves the abilities available to an agent.
	 *
	 * @param AgentConfig $agent The agent configuration.
	 *
	 * @return WP_Ability[] List of resolved abilities.
	 * @since 1.0.0
	 */
	public function resolve(AgentConfig $agent): array
	{
		if (! function_exists('wp_get_abilities')) {
			return [];
		}

		$abilities = 'selected' === $agent->abilitySource
			? $this->resolveSelected($agent->allowedAbilities)
			: array_values(wp_get_abilities());

		// Ask/Plan modes only get read-only abilities; Pro keeps final say via the filter below.
		if ('execute' !== $agent->mode) {
			$abilities = $this->filterReadonly($abilities);
		}

		$abilities = $this->applyInteractiveTools($abilities, $agent);

		// Stable, name-sorted tool ordering keeps the serialized tool block
		// byte-identical across requests, which lets providers with automatic
		// prefix caching (e.g. OpenAI) reuse the cached prompt prefix.
		usort($abilities, static function ($a, $b): int {
			$aName = $a instanceof WP_Ability ? $a->get_name() : '';
			$bName = $b instanceof WP_Ability ? $b->get_name() : '';

			return strcmp($aName, $bName);
		});

		return (array) apply_filters('agent_mod_resolved_abilities', $abilities, $agent);
	}

	/**
	 * Offers the interactive chat tools exactly when somebody can use them.
	 *
	 * ask-user is offered on an interactive channel; present-plan only there
	 * and in Plan mode; request-approval only there, in Execute mode with
	 * manual approval. On such a channel they are added even when the agent
	 * uses a "selected" ability list, because they are part of the chat itself
	 * rather than site capabilities. Everywhere else (widgets, scheduled runs)
	 * they are removed, even when "all" abilities were resolved: nobody would
	 * see the questions or the review options.
	 *
	 * @param WP_Ability[] $abilities Resolved abilities.
	 * @param AgentConfig  $agent     The agent configuration.
	 *
	 * @return WP_Ability[]
	 * @since x.x.x
	 */
	private function applyInteractiveTools(array $abilities, AgentConfig $agent): array
	{
		$interactiveTools = [
			AskUserAbilityService::NAME,
			AskUserAbilityService::PRESENT_PLAN,
			AskUserAbilityService::REQUEST_APPROVAL,
		];

		$abilities = array_values(array_filter($abilities, static function ($ability) use ($interactiveTools): bool {
			return ! ($ability instanceof WP_Ability && in_array($ability->get_name(), $interactiveTools, true));
		}));

		if (! $agent->interactive) {
			return $abilities;
		}

		$offered = [AskUserAbilityService::NAME];

		if ('plan' === $agent->mode) {
			$offered[] = AskUserAbilityService::PRESENT_PLAN;
		} elseif ('execute' === $agent->mode && 'manual' === $agent->approvalMode) {
			// Auto mode means the user handed over control: no go-ahead stops.
			$offered[] = AskUserAbilityService::REQUEST_APPROVAL;
		}

		foreach ($offered as $name) {
			$ability = wp_get_ability($name);

			if ($ability instanceof WP_Ability) {
				$abilities[] = $ability;
			}
		}

		return $abilities;
	}

	/**
	 * Keeps only abilities explicitly annotated as read-only.
	 *
	 * Abilities without a readonly annotation are treated as writable and removed.
	 *
	 * @param WP_Ability[] $abilities Resolved abilities.
	 *
	 * @return WP_Ability[]
	 * @since 1.0.0
	 */
	private function filterReadonly(array $abilities): array
	{
		return array_values(array_filter($abilities, static function ($ability): bool {
			if (! $ability instanceof WP_Ability) {
				return false;
			}

			$meta = (array) $ability->get_meta();

			return true === ($meta['annotations']['readonly'] ?? false);
		}));
	}

	/**
	 * Resolves the subset of abilities whose names are in the allowed list.
	 *
	 * Indexes the registry once via wp_get_abilities() instead of calling
	 * wp_get_ability() per name, so stale or invalid names (e.g. a select
	 * field's placeholder value) are silently dropped instead of raising a
	 * _doing_it_wrong notice for every unregistered name.
	 *
	 * @param string[] $allowedAbilities Allowed ability names.
	 *
	 * @return WP_Ability[]
	 * @since 1.0.0
	 */
	private function resolveSelected(array $allowedAbilities): array
	{
		$registry = [];
		foreach (wp_get_abilities() as $ability) {
			$registry[$ability->get_name()] = $ability;
		}

		$abilities = [];

		foreach ($allowedAbilities as $name) {
			$ability = $registry[(string) $name] ?? null;
			if ($ability instanceof WP_Ability) {
				$abilities[] = $ability;
			}
		}

		return $abilities;
	}
}
