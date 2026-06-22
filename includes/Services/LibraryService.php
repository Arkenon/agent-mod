<?php

/**
 * Include external libraries
 * @package AgentMod
 * @subpackage Services
 * @since 1.0.0
 */

namespace AgentMod\Services;

defined('ABSPATH') || exit;

class LibraryService
{
	public function __construct()
	{
		//Load Native Custom Fields library
		$this->includeNCF();
	}

    /**
	 * Includes the bundled Native Custom Fields library when the standalone
	 * plugin is not already active.
	 *
	 * Called directly from the constructor (we are already inside plugins_loaded
	 * priority 10 at this point, so hooks like admin_menu have not fired yet —
	 * NCF can still register everything it needs).
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function includeNCF(): void
	{
		$activePlugins = (array) get_option('active_plugins', []);
		$isActive      = in_array('native-custom-fields/native-custom-fields.php', $activePlugins, true);

		if (! $isActive) {
			$file = AGENT_MOD_PATH . 'lib/native-custom-fields/native-custom-fields.php';
			if (is_readable($file)) {
				require_once $file;
			}
		}
	}
}
