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
		if (! is_plugin_active('native-custom-fields/native-custom-fields.php')) {
			$file = AGENT_MOD_PATH . 'lib/vendor/native-custom-fields/native-custom-fields.php';
			if (is_readable($file)) {
				require_once $file;
			}

			//Hide NCF Admin Menu
			add_action("admin_menu", array($this, "removeNCFMenu"), 999);
		}
	}

	/**
	 * Remove NCF Admin Menu
	 * @return void
	 * @since 1.0.0
	 */
	public function removeNCFMenu(): void
	{
		remove_menu_page("native-custom-fields");
	}
}
