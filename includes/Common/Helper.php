<?php

/**
 * Helper class for common functions
 *
 * @package AgentMod
 * @subpackage Common
 * @since 1.0.0
 */

namespace AgentMod\Common;

defined('ABSPATH') || exit;

class Helper
{
	/**
	 * Sanitize input
	 *
	 * @param string $name Input name
	 * @param string $method GET or POST or REQUEST
	 * @param string $type Type of input (title, id, textarea, url, email, username, text, bool)
	 *
	 * @return bool|int|string|null
	 * @since 1.0.0
	 */
	public static function sanitize(string $name, string $method, string $type = "text")
	{

		$value  = "";
		$method = strtolower($method);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a helper method for sanitization, not directly processing form data
		$input = $method === 'post' ? $_POST : ($method === 'get' ? $_GET : $_REQUEST);

		if (isset($input[$name])) {
			$value = wp_unslash($input[$name]);
			$value = is_array($value) ? self::sanitizeArray($value) : sanitize_text_field($value);
		}

		if (isset($value)) {
			switch ($type) {
				case "title":
					return sanitize_title($value);
				case "id":
					return absint($value);
				case "textarea":
					return sanitize_textarea_field($value);
				case "url":
					return esc_url_raw($value);
				case "email":
					return sanitize_email($value);
				case "username":
					return sanitize_user($value);
				case "bool":
					return rest_sanitize_boolean($value);
				case "key":
					return sanitize_key($value);
				default:
					return $value;
			}
		}

		return null;
	}

	/**
	 * Sanitize array recursively
	 *
	 * @param array $array
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public static function sanitizeArray(array $array): array
	{
		return array_map(function ($value) {
			if (is_array($value)) {
				return self::sanitizeArray($value);
			}

			return sanitize_text_field($value);
		}, array_combine(
			array_map('sanitize_text_field', array_keys($array)),
			$array
		));
	}
}
