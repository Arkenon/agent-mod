<?php
declare(strict_types=1);
/**
 * Plugin Name:       AgentMod
 * Description:       AgentMod empowers your site with intelligent AI agents to automate content creation, customer support, and data management, making your WordPress site smarter and more efficient.
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Version:           1.0.9
 * Author:            Kadim Gültekin
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agent-mod
 *
 * @package AgentMod
 */

defined('ABSPATH') || exit;

use DI\DependencyException;
use DI\NotFoundException;
use AgentMod\App;
use AgentMod\Services\ActivationService;
use AgentMod\Services\DeactivationService;


if (is_readable(__DIR__ . '/vendor/autoload.php')) {
	require_once __DIR__ . '/vendor/autoload.php';
}

define('AGENT_MOD_VERSION', get_file_data(__FILE__, array('version' => 'Version'))['version']);
define('AGENT_MOD_URL', rtrim(plugin_dir_url(__FILE__), '/') . '/');
define('AGENT_MOD_PATH', plugin_dir_path(__FILE__));

//Activation
if (! function_exists('agentModInitActivation')) {
	/**
	 * @throws DependencyException
	 * @throws NotFoundException
	 * @throws Exception
	 * @since 1.0.0
	 */
	function agentModInitActivation(): void
	{
		$activation_service = new ActivationService();
		$activation_service->activate();
	}

	register_activation_hook(__FILE__, 'agentModInitActivation');
}

//Deactivation
if (! function_exists('agentModInitDeactivation')) {
	/**
	 * @throws DependencyException
	 * @throws NotFoundException
	 * @throws Exception
	 * @since 1.0.0
	 */
	function agentModInitDeactivation(): void
	{
		$deactivation_service = new DeactivationService();
		$deactivation_service->deactivate();
	}

	register_deactivation_hook(__FILE__, 'agentModInitDeactivation');
}

//Run plugin
if (class_exists(App::class)) {
	/**
	 * @throws DependencyException
	 * @throws NotFoundException
	 * @throws Exception
	 * @since 1.0.0
	 */
	try {
		$agent_mod_app = new App();
		$agent_mod_app->run();
	} catch (DependencyException | Exception $e) {
		wp_die(esc_html($e->getMessage()));
	}
}
