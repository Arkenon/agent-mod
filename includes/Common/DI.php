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
	 * Dependency Injection Container
	 * @return Container
	 * @throws Exception
	 * @since 1.0.0
	 */
	public static function container(): Container
	{
		$containerBuilder = new ContainerBuilder();

		return $containerBuilder->build();
	}
}
