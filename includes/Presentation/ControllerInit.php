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
		// Initialize controllers
		if (is_admin()) {
			foreach ($this->adminControllers as $controller) {
				DI::container()->get($controller);
			}
		}
	}
}