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

final class ControllerInit
{

	/**
	 * List of controllers to be initialized for Admin area
	 * @var array
	 * @since 1.0.0
	 */
	private array $adminControllers =  [
		AdminController::class,
		AIChatWidgetController::class,
		AbilitiesController::class,
	];

	/**
	 * Controllers that must load on every request (e.g. REST controllers).
	 *
	 * REST controllers register their routes on the `rest_api_init` hook, which
	 * fires during REST requests where `is_admin()` is false. They therefore must
	 * be instantiated regardless of the admin context, otherwise their routes are
	 * never registered and the endpoint returns "No route was found".
	 *
	 * @var array
	 * @since 1.0.0
	 */
	private array $restControllers = [
		AIChatRestController::class,
	];

	/**
	 * Initialize the program
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function __construct()
	{
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
		// REST controllers must load on every request so their routes register
		// on rest_api_init (where is_admin() is false).
		foreach ($this->restControllers as $controller) {
			DI::container()->get($controller);
		}

		// Admin-only controllers (menus, widgets, assets).
		if (is_admin()) {
			foreach ($this->adminControllers as $controller) {
				DI::container()->get($controller);
			}
		}
	}
}