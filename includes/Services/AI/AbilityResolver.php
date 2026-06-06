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

		if ('selected' === $agent->abilitySource) {
			return $this->resolveSelected($agent->allowedAbilities);
		}

		return array_values(wp_get_abilities());
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
