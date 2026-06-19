<?php

/**
 * Admin controller class
 * Creates main menu and submenus for admin area
 * @package AgentMod
 * @subpackage Presentation\Admin\Controllers
 * @since 1.0.0
 */

namespace AgentMod\Presentation\Admin\Controllers;

use Exception;
use AgentMod\Common\Constants;

defined('ABSPATH') || exit;

final class AdminController
{
	public function __construct()
	{
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueScripts' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueStyles' ] );
		add_action('admin_enqueue_scripts', [$this, 'enqueueDashboardAssets']);
		add_action('admin_menu', [$this, 'addMenu']);
	}

	/**
	 * Enqueue scripts for the admin area
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueueScripts(): void {
		//Admin scripts
		wp_enqueue_script( 'agent-mod-admin', Constants::INCLUDES_URL . '/Presentation/Admin/Assets/Js/agent-mod-admin.js', array( 'jquery' ), AGENT_MOD_VERSION, true );
	}

	/**
	 * Enqueue styles for the admin area
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueueStyles(): void {
		//Admin styles
		wp_enqueue_style( 'agent-mod-admin', Constants::INCLUDES_URL . '/Presentation/Admin/Assets/Css/agent-mod-admin.css', array(), AGENT_MOD_VERSION );
	}

	/**
	 * Enqueues the dashboard React bundle. Runs only on the agent-mod admin page.
	 *
	 * @param string $hookSuffix Current admin page hook suffix.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueueDashboardAssets(string $hookSuffix): void
	{
		if ('toplevel_page_agent-mod' !== $hookSuffix) {
			return;
		}

		$assetFile = AGENT_MOD_PATH . 'build/dashboard/index.asset.php';

		if (! is_readable($assetFile)) {
			return;
		}

		$asset = require $assetFile;

		wp_enqueue_script(
			'agent-mod-dashboard',
			AGENT_MOD_URL . 'build/dashboard/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style('wp-components');

		wp_enqueue_style(
			'agent-mod-dashboard',
			AGENT_MOD_URL . 'build/dashboard/style-index.css',
			['wp-components'],
			$asset['version']
		);

		wp_localize_script(
			'agent-mod-dashboard',
			'agentModDashboard',
			[
				'logoUrl' => AGENT_MOD_URL . 'includes/Presentation/Admin/Assets/img/agent_mod_logo.jpg',
			]
		);
	}

	/**
	 * Add a menu for the plugin
	 * @return void
	 * @since 1.0.0
	 */
	public function addMenu()
	{
		add_menu_page(
			esc_html__('Agent Mod', 'agent-mod'),
			esc_html__('Agent Mod', 'agent-mod'),
			'manage_options',
			'agent-mod',
			[$this, 'renderDashboard'],
			AGENT_MOD_URL . 'includes/Presentation/Admin/Assets/img/agent_mod_colored_icon.png'
		);
	}

	/**
	 * Render HTML output for dashboard
	 * @return void
	 * @since 1.0.0
	 */
	public function renderDashboard(): void
	{
		ob_start();
		try {
			include Constants::INCLUDES_PATH . 'Presentation/Admin/Views/admin-menu-content.php';
			echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted internal admin view template; output is escaped within the template.
		} catch (Exception $e) {
			ob_end_clean();
		}
	}
}
