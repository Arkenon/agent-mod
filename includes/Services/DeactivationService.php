<?php

/**
 * Activation service class for the plugin
 * @package AgentMod
 * @subpackage Services
 * @since 1.0.0
 */

namespace AgentMod\Services;

defined('ABSPATH') || exit;

class DeactivationService
{
	public function deactivate(): void
	{
		//Define custom deactivation hook
		do_action('agent_mod_deactivation');
	}
}
