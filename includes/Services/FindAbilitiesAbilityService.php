<?php

/**
 * Find-abilities ability service.
 *
 * Registers agent-mod/find-abilities, the entry point of dynamic tool loading:
 * the agent searches the abilities it may use by keywords or category, and
 * every match is added to its tool list from the next request on (see
 * AbilityCatalog and AIClientAdapter). The search runs only inside an agent
 * run, against exactly the abilities that run allows.
 *
 * @package AgentMod
 * @subpackage Services
 * @since 1.3.4
 */

namespace AgentMod\Services;

use WP_Error;
use AgentMod\Common\Constants;
use AgentMod\Services\AI\AbilityCatalog;

defined('ABSPATH') || exit;

class FindAbilitiesAbilityService
{
	/**
	 * Ability category slug.
	 *
	 * @var string
	 * @since 1.3.4
	 */
	private const CATEGORY = Constants::SYSTEM_ABILITY_CATEGORY;

	/**
	 * Run-scoped ability catalog.
	 *
	 * @var AbilityCatalog
	 * @since 1.3.4
	 */
	private AbilityCatalog $catalog;

	/**
	 * Constructor. Binds the abilities API hook.
	 *
	 * @param AbilityCatalog $catalog Run-scoped ability catalog.
	 *
	 * @since 1.3.4
	 */
	public function __construct(AbilityCatalog $catalog)
	{
		$this->catalog = $catalog;

		add_action('wp_abilities_api_init', [$this, 'registerAbilities']);
	}

	/**
	 * Registers agent-mod/find-abilities.
	 *
	 * Annotated read-only: it changes nothing on the site, so it is available
	 * in every mode and never reaches the write-confirmation gate.
	 *
	 * @return void
	 * @since 1.3.4
	 */
	public function registerAbilities(): void
	{
		if (! function_exists('wp_register_ability')) {
			return;
		}

		wp_register_ability(
			AbilityCatalog::FIND,
			[
				'label'               => __('Find Abilities', 'agent-mod'),
				'description'         => sprintf(
					/* translators: %d: most results per call. */
					__('Searches the tools you can use and loads the matches: every tool it returns can be called directly by its "tool" name from your next step on. Most tools are not loaded until you find them, so call this whenever none of your current tools fits the task. Search with short English keywords for the object and the action (e.g. "update navigation menu", "list posts", "install plugin"), optionally limited to one category. Returns at most %d tools; search again with other keywords if none fits. Do not search for tools you already have.', 'agent-mod'),
					Constants::AI_FIND_ABILITIES_LIMIT
				),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'execute'],
				'permission_callback' => static function (): bool {
					return current_user_can('read');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'query'    => [
							'type'        => 'string',
							'description' => __('Short English keywords naming the object and the action, e.g. "create page" or "global styles".', 'agent-mod'),
						],
						'category' => [
							'type'        => 'string',
							'description' => __('Optional category slug from the tool category list in your instructions.', 'agent-mod'),
						],
					],
					'required'   => ['query'],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
					'show_in_rest' => false,
				],
			]
		);
	}

	/**
	 * Runs the search for the current agent run.
	 *
	 * @param array<string, mixed>|null $input Ability input.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.3.4
	 */
	public function execute($input = null)
	{
		// Outside a run there is no allowed set to search, and searching the
		// whole registry would reveal abilities the caller may not use.
		if (! $this->catalog->isScoped()) {
			return new WP_Error(
				'find_abilities_unavailable',
				__('Tools can only be searched during an agent run.', 'agent-mod')
			);
		}

		$input    = is_array($input) ? $input : [];
		$query    = sanitize_text_field((string) ($input['query'] ?? ''));
		$category = sanitize_key((string) ($input['category'] ?? ''));

		$results = $this->catalog->search($query, $category);

		if (empty($results)) {
			return [
				'abilities' => [],
				'note'      => __('No tool matched. Try other or fewer keywords, or another category. If no tool can do it, tell the user.', 'agent-mod'),
			];
		}

		return [
			'abilities' => $results,
			'note'      => __('These tools are now loaded. Call the one that fits directly by its "tool" name; its full description and parameters are in your tool list. Tools with readonly false change the site.', 'agent-mod'),
		];
	}
}
