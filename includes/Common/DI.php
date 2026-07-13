<?php

/**
 * Dependency Injection Container Configurations
 * @package AgentMod
 * @subpackage Common
 * @since 1.0.0
 */

namespace AgentMod\Common;

defined('ABSPATH') || exit;

use DI\Container;
use DI\ContainerBuilder;
use Exception;

class DI
{
	/**
	 * Dependency Injection Container Instance
	 * @var Container|null
	 * @since 1.0.0
	 */
	private static ?Container $container = null;

	/**
	 * Dependency Injection Container
	 * @return Container
	 * @throws Exception
	 * @since 1.0.0
	 */
	public static function container(): Container
	{
		if (self::$container === null) {
			$containerBuilder = new ContainerBuilder();
			$containerBuilder->useAutowiring(true);
			self::$container = $containerBuilder->build();
		}
		return self::$container;
	}
}
