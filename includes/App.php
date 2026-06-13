<?php

/**
 * Main plugin class
 * Runs on plugins_loaded action
 * @package AgentMod
 * @since 1.0.0
 */

namespace AgentMod;

use DI\DependencyException;
use DI\NotFoundException;
use Exception;
use AgentMod\Services\AgentCPTService;
use AgentMod\Services\BlockService;
use AgentMod\Services\AbilityRegistrarService;
use AgentMod\Common\DI;
use AgentMod\Presentation\ControllerInit;

defined('ABSPATH') || exit;

final class App
{

	/**
	 * List of services to be initialized
	 * @var array
	 * @since 1.0.0
	 */
	private array $services = [
		//AgentCPTService::class,
		BlockService::class,
		AbilityRegistrarService::class,
	];

	/**
	 * Include Presentation layer base class - ControllerInit.php
	 * Contains all controllers
	 * @var array
	 * @since 1.0.0
	 */
	private array $controllers = [
		ControllerInit::class
	];

	/**
	 * Run all services and controllers
	 * @return void
	 * @since 1.0.0
	 */
	public function run(): void
	{
		//Define a hook runs before initializing the plugin
		do_action('agent_mod_before_init');

		// Load all services
		add_action('plugins_loaded', [$this, 'initPluginServices']);

		//Load all controllers
		add_action('init', [$this, 'initPluginControllers'], 5);

		//Define a hook runs after initializing the plugin
		do_action('agent_mod_after_init');
	}

	/**
	 * Initialize all services and controllers
	 * @throws DependencyException
	 * @throws NotFoundException
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function initPluginServices(): void
	{
		//Initialize all services
		foreach ($this->services as $service) {
			DI::container()->get($service);
		}
	}

	/**
	 * Initialize all controllers
	 * @throws Exception
	 * @throws DependencyException
	 * @throws NotFoundException
	 * @since 1.0.0
	 */
	public function initPluginControllers(): void
	{
		//Initialize all controllers
		foreach ($this->controllers as $controller) {
			DI::container()->get($controller);
		}
	}
}
