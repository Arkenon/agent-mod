<?php

/**
 * Base class for Presentation layer
 * Contains all controllers
 * @since 1.0.0
 * @package AgentMod
 * @subpackage Presentation
 */

namespace AgentMod\Presentation;

defined('ABSPATH') || exit;

use Exception;
use AgentMod\Common\DI;
use AgentMod\Presentation\Admin\Controllers\AdminController;
use AgentMod\Presentation\Admin\Controllers\AbilitiesController;
use AgentMod\Presentation\Admin\Controllers\AIChatRestController;
use AgentMod\Presentation\Admin\Controllers\AIChatWidgetController;
use AgentMod\Presentation\Admin\Controllers\SettingsController;

final class ControllerInit
{

	/**
	 * List of controllers to be initialized for Admin area
	 * @var array
	 * @since 1.0.0
	 */
	private array $adminControllers =  [
		AdminController::class,
		SettingsController::class,
		AIChatWidgetController::class,
		AbilitiesController::class,
	];

	/**
	 * List of controllers to be initialized for Client-side
	 * @var array
	 * @since 1.0.0
	 */
	private array $clientControllers = [
		AIChatRestController::class,
	];

	/**
	 * Initialize the program
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function __construct()
	{

		// Add filters to allow plugins to add their own admin controllers
		$this->adminControllers = apply_filters(
			'agent_mod_admin_controllers',
			$this->adminControllers
		);

		// Add filters to allow plugins to add their own client-side controllers
		$this->clientControllers = apply_filters(
			'agent_mod_client_controllers',
			$this->clientControllers
		);

		// Initialize controllers
		$this->initControllers();
	}

	/**
	 * Initialize controllers
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function initControllers()
	{
		// Client side controllers must load on every request
		foreach ($this->clientControllers as $controller) {
			DI::container()->get($controller);
		}

		// Admin controllers (menus, widgets, assets) and REST API requests.
		// is_admin() is false during REST API requests, so we explicitly check for JSON/REST requests
		// to ensure controllers like SettingsController can register their fields for NCF.
		$is_rest = (defined('REST_REQUEST') && REST_REQUEST) || (function_exists('wp_is_json_request') && wp_is_json_request());

		if (is_admin() || $is_rest) {
			foreach ($this->adminControllers as $controller) {
				DI::container()->get($controller);
			}
		}
	}
}