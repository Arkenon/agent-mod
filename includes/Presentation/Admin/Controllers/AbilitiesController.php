<?php

/**
 * Admin controller for the Abilities page.
 *
 * Registers the "Abilities" submenu under AgentMod, enqueues the React bundle
 * only on that page, and renders the PHP wrapper template.
 *
 * @package    AgentMod
 * @subpackage Presentation\Admin\Controllers
 * @since      1.0.0
 */

namespace AgentMod\Presentation\Admin\Controllers;

use Exception;
use AgentMod\Common\Constants;

defined('ABSPATH') || exit;

final class AbilitiesController
{
	/**
	 * Registers admin hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct()
	{
		add_action('admin_menu', [$this, 'addSubMenu']);
		add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
	}

	/**
	 * Registers the Abilities submenu page.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function addSubMenu(): void
	{
		add_submenu_page(
			'agent-mod',
			esc_html__('Abilities', 'agent-mod'),
			esc_html__('Abilities', 'agent-mod'),
			'manage_options',
			'agent-mod-abilities',
			[$this, 'renderPage']
		);
	}

	/**
	 * Enqueues the Abilities React bundle. Runs only on the Abilities admin page.
	 *
	 * @param string $hookSuffix Current admin page hook suffix.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueueAssets(string $hookSuffix): void
	{
		if ('agentmod_page_agent-mod-abilities' !== $hookSuffix) {
			return;
		}

		$assetFile = AGENT_MOD_PATH . 'build/ability-list/index.asset.php';

		if (! is_readable($assetFile)) {
			return;
		}

		$asset = require $assetFile;

		wp_enqueue_script(
			'agent-mod-ability-list',
			AGENT_MOD_URL . 'build/ability-list/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style('wp-components');

		wp_enqueue_style(
			'agent-mod-ability-list',
			AGENT_MOD_URL . 'build/ability-list/style-index.css',
			['wp-components'],
			$asset['version']
		);

		wp_localize_script(
			'agent-mod-ability-list',
			'agentModAbilityList',
			['abilitiesEndpoint' => '/wp-abilities/v1/abilities']
		);
	}

	/**
	 * Renders the PHP wrapper template for the Abilities page.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function renderPage(): void
	{
		ob_start();
		try {
			include Constants::INCLUDES_PATH . 'Presentation/Admin/Views/abilities-content.php';
			echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted internal admin view template.
		} catch (Exception $e) {
			ob_end_clean();
		}
	}
}
