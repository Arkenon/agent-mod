<?php

/**
 * Option repository class for the plugin
 * @package NativeCustomFields
 * @subpackage Repositories
 * @since 1.0.0
 */

namespace NativeCustomFields\Repositories;

defined('ABSPATH') || exit;

class OptionRepository extends BaseRepository
{

	/**
	 * Get option values
	 *
	 * @param string $option_name Option name
	 *
	 * @return mixed Value of the option. A value of any type may be returned, including
	 *               scalar (string, boolean, float, integer), null, array, object.
	 *               Scalar and null values will be returned as strings as long as they originate
	 *               from a database stored option value. If there is no option in the database,
	 *               an array is returned.
	 * @since 1.0.0
	 */
	public function getOptions(string $option_name)
	{
		return get_option($option_name, []);
	}


	/**
	 * Save option values
	 *
	 * @param array $values Values to save
	 * @param string $option_name Option name
	 *
	 * @return bool True, if saved successfully, array with an error message otherwise
	 * @since 1.0.0
	 */
	public function saveOptions(array $values, string $option_name): bool
	{
		$current_values = $this->getOptions($option_name);

		if ($current_values === $values) {
			return true;
		}

		return update_option($option_name, $values);
	}
}
