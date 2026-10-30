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
use AgentMod\Common\Constants;
use AgentMod\Services\AI\DTO\AgentConfig;
use AgentMod\Services\AskUserAbilityService;
use AgentMod\Services\SettingsService;

defined('ABSPATH') || exit;

class AbilityResolver
{
	/**
	 * Name prefix of the MCP Adapter plugin's abilities, which are never resolved.
	 *
	 * @var string
	 * @since 1.3.4
	 */
	private const MCP_ADAPTER_PREFIX = 'mcp-adapter/';

	/**
	 * Settings service; supplies the tool loading strategy.
	 *
	 * @var SettingsService
	 * @since 1.3.4
	 */
	private SettingsService $settings;

	/**
	 * Constructor (PHP-DI autowired).
	 *
	 * @param SettingsService $settings Settings service.
	 *
	 * @since 1.3.4
	 */
	public function __construct(SettingsService $settings)
	{
		$this->settings = $settings;
	}

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

		// System abilities are added below and in selectTools() exactly when they
		// apply, never because they were selected or registered.
		$abilities = array_values(array_filter($abilities, static function ($ability): bool {
			return ! $ability instanceof WP_Ability || self::isSelectable($ability);
		}));

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
	 * Whether an ability can be part of an ability selection.
	 *
	 * Two kinds of abilities never are, so selection lists should not offer them
	 * and resolve() drops them from every list:
	 *
	 *  - AgentMod's system abilities (Constants::SYSTEM_ABILITY_CATEGORY): part
	 *    of how the agent works; AgentMod offers them when they apply.
	 *  - The MCP Adapter's abilities: discover / get-info / execute are a proxy
	 *    for every other ability. execute-ability would run any of them under
	 *    its own name, past the per-name write confirmation, auto-approval and
	 *    a PRO agent's allowlist; the agent calls abilities directly anyway.
	 *
	 * @param WP_Ability $ability The ability.
	 *
	 * @return bool
	 * @since 1.3.4
	 */
	public static function isSelectable(WP_Ability $ability): bool
	{
		return ! self::isSystemAbility($ability) && 0 !== strpos($ability->get_name(), self::MCP_ADAPTER_PREFIX);
	}

	/**
	 * Whether an ability is one of AgentMod's system abilities.
	 *
	 * @param WP_Ability $ability The ability.
	 *
	 * @return bool
	 * @since 1.3.4
	 */
	public static function isSystemAbility(WP_Ability $ability): bool
	{
		return Constants::SYSTEM_ABILITY_CATEGORY === $ability->get_category();
	}

	/**
	 * Splits the resolved abilities into what may run and what is sent as tools.
	 *
	 * Under dynamic tool loading only a small set goes out as tool definitions:
	 * the system abilities (find-abilities, the interactive chat tools), the core abilities
	 * (agent_mod_core_abilities), the abilities the user highlighted and those
	 * preloaded from recent turns. Everything else is reached through
	 * find-abilities, which loads its matches into the tool list during the run.
	 * The executable set is the resolved set plus find-abilities, so the write
	 * gate, auto-approval and read-only rules keep working on real ability names.
	 *
	 * Small sets (up to agent_mod_dynamic_tools_threshold abilities) and the
	 * 'all' strategy send everything, as before.
	 *
	 * @param AgentConfig  $agent     The agent configuration.
	 * @param WP_Ability[] $abilities Abilities from resolve().
	 *
	 * @return array{abilities: WP_Ability[], tools: WP_Ability[]|null} tools is null when every ability is sent.
	 * @since 1.3.4
	 */
	public function selectTools(AgentConfig $agent, array $abilities): array
	{
		$everything = ['abilities' => $abilities, 'tools' => null];

		/**
		 * Filters the tool loading strategy of a run: 'dynamic' or 'all'.
		 *
		 * @param string      $strategy Strategy from the settings page.
		 * @param AgentConfig $agent    The agent configuration.
		 *
		 * @since 1.3.4
		 */
		$strategy = (string) apply_filters('agent_mod_tool_loading', $this->settings->getToolLoading(), $agent);

		/**
		 * Filters the ability count up to which dynamic loading still sends every ability.
		 *
		 * @param int $threshold Ability count.
		 *
		 * @since 1.3.4
		 */
		$threshold = (int) apply_filters('agent_mod_dynamic_tools_threshold', Constants::AI_DYNAMIC_TOOLS_THRESHOLD);

		$find = function_exists('wp_get_ability') ? wp_get_ability(AbilityCatalog::FIND) : null;

		if ('dynamic' !== $strategy || count($abilities) <= $threshold || ! $find instanceof WP_Ability) {
			return $everything;
		}

		/**
		 * Filters the abilities that are always sent as tools under dynamic loading.
		 *
		 * Only names that are also allowed for the run are used.
		 *
		 * @param string[]    $names Ability names.
		 * @param AgentConfig $agent The agent configuration.
		 *
		 * @since 1.3.4
		 */
		$core = (array) apply_filters('agent_mod_core_abilities', [], $agent);

		$initial = array_merge($core, $agent->emphasizedAbilities, $agent->preloadedAbilities);

		$abilities   = array_values(array_filter($abilities, static function ($ability): bool {
			return $ability instanceof WP_Ability;
		}));
		$abilities[] = $find;

		usort($abilities, static function (WP_Ability $a, WP_Ability $b): int {
			return strcmp($a->get_name(), $b->get_name());
		});

		// Filtering the name-sorted set keeps the tool block in the same order
		// on every request, which provider prompt caching depends on.
		$tools = array_values(array_filter($abilities, static function (WP_Ability $ability) use ($initial): bool {
			return self::isSystemAbility($ability) || in_array($ability->get_name(), $initial, true);
		}));

		return ['abilities' => $abilities, 'tools' => $tools];
	}

	/**
	 * Offers the interactive chat tools exactly when somebody can use them.
	 *
	 * ask-user is offered on an interactive channel; present-plan only there
	 * and in Plan mode; request-approval only there, in Execute mode with
	 * manual approval. They are system abilities, so resolve() has already
	 * dropped them from the list whether or not they were selected; on an
	 * interactive channel they are added back here. Everywhere else (widgets,
	 * scheduled runs) they stay out: nobody would see the questions or the
	 * review options.
	 *
	 * @param WP_Ability[] $abilities Resolved abilities, without system abilities.
	 * @param AgentConfig  $agent     The agent configuration.
	 *
	 * @return WP_Ability[]
	 * @since 1.3.1
	 */
	private function applyInteractiveTools(array $abilities, AgentConfig $agent): array
	{
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
