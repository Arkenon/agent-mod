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
		if (! is_plugin_active( 'native-custom-fields/native-custom-fields.php' )
			&& class_exists( '\NativeCustomFields\App' )
		) {
			// Create an instance of Native Custom Fields
			$ncf = new \NativeCustomFields\App();

			//Boot App with it's absolute path and url, 
			$ncf::boot(
				[
					'path' => AGENT_MOD_PATH . 'vendor/native-custom-fields/native-custom-fields/',
					'url'  => AGENT_MOD_URL . 'vendor/native-custom-fields/native-custom-fields/',
				]
			);

			// Remove NCF admin menu after it is registered
			// If NCF will be active, then we don't need to remove it
			add_action(
				'admin_menu',
				array( $this, 'removeNCFMenu' ),
				999
			);
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
