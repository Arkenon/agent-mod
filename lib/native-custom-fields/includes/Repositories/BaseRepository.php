<?php

/**
 * Base repository class for the plugin
 * @package NativeCustomFields
 * @subpackage Repositories
 * @since 1.0.0
 */

namespace NativeCustomFields\Repositories;

defined('ABSPATH') || exit;

class BaseRepository
{
	/**
	 * Get configurations data
	 *
	 * @param string $config_name
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function getConfigurations(string $config_name): array
	{
		return get_option($config_name, []);
	}

	/**
	 * Save configurations
	 *
	 * @param array $config
	 * @param string $config_name
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function saveConfigurations(array $config, string $config_name): bool
	{
		return update_option($config_name, $config);
	}

	/**
	 * Delete configurations
	 *
	 * @param string $config_name
	 * @param string $object_type_key
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function deleteConfigurations(string $config_name, string $object_type_key): bool
	{
		$get_config = $this->getConfigurations($config_name);

		foreach ($get_config as $key => $config) {
			if ($key === $object_type_key) {
				unset($get_config[$key]);
			}
		}

		return $this->saveConfigurations($get_config, $config_name);
	}
}
