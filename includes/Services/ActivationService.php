<?php

/**
 * Deactivation service class for the plugin
 * @package AgentMod
 * @subpackage Services
 * @since 1.0.0
 */

namespace AgentMod\Services;

defined('ABSPATH') || exit;

class ActivationService
{
	public function activate(): void
	{
		//Define custom activation hook
		do_action('agent_mod_activation');
	}
}
