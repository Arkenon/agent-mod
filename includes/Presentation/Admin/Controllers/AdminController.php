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
		add_action('admin_enqueue_scripts', [$this, 'enqueueScripts']);
		add_action('admin_enqueue_scripts', [$this, 'enqueueStyles']);
		add_action('admin_menu', [$this, 'addMenu']);
	}

	/**
	 * Enqueue scripts for the admin area
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueueScripts(): void
	{
		//Admin scripts
		wp_enqueue_script('agent-mod-admin', Constants::INCLUDES_URL . '/Presentation/Admin/Assets/Js/agent-mod-admin.js', array('jquery'), AGENT_MOD_VERSION, true);
	}

	/**
	 * Enqueue styles for the admin area
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueueStyles(): void
	{
		//Admin styles
		wp_enqueue_style('agent-mod-admin', Constants::INCLUDES_URL . '/Presentation/Admin/Assets/Css/agent-mod-admin.css', array(), AGENT_MOD_VERSION);
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
			'dashicons-admin-generic',
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
			echo ob_get_clean();
		} catch (Exception $e) {
			ob_end_clean();
		}
	}
}
