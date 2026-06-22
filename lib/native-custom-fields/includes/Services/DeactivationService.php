<?php

/**
 * Activation service class for the plugin
 * @package NativeCustomFields
 * @subpackage Services
 * @since 1.0.0
 */

namespace NativeCustomFields\Services;

defined('ABSPATH') || exit;

class DeactivationService
{
	public function deactivate(): void
	{
		//Define custom deactivation hook
		do_action('native_custom_fields_deactivation');
	}
}
