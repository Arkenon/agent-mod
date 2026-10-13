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
		// Flag the one-shot redirect to the setup wizard. This must happen here: on the
		// activation request the plugin loads after plugins_loaded, so SetupController
		// is not yet listening to the hook below.
		if (! get_option('agent_mod_setup_completed')) {
			set_transient('agent_mod_do_setup_redirect', 1, 60);
		}

		//Define custom activation hook
		do_action('agent_mod_activation');
	}
}
