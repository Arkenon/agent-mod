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

		return (array) apply_filters('agent_mod_resolved_abilities', $abilities, $agent);
	}

	/**
	 * Keeps only abilities explicitly annotated as read-only.
	 *
	 * Abilities without a readonly annotation are treated as writable and removed.
	 *
	 * @param WP_Ability[] $abilities Resolved abilities.
	 *
	 * @return WP_Ability[]
	 * @since 1.1.0
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
	 * @param string[] $allowedAbilities Allowed ability names.
	 *
	 * @return WP_Ability[]
	 * @since 1.0.0
	 */
	private function resolveSelected(array $allowedAbilities): array
	{
		$abilities = [];

		foreach ($allowedAbilities as $name) {
			$ability = wp_get_ability((string) $name);
			if ($ability instanceof WP_Ability) {
				$abilities[] = $ability;
			}
		}

		return $abilities;
	}
}
