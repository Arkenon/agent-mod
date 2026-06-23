<?php

declare(strict_types=1);
/**
 * Plugin Name: Native Custom Fields
 * Plugin URI: https://nativecustomfields.com
 * Description: A WordPress plugin for creating custom fields using Gutenberg components
 * Version: 1.2.6
 * Author: Kadim Gültekin
 * Author URI: https://profiles.wordpress.org/arkenon/
 * Text Domain: native-custom-fields
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * @package NativeCustomFields
 */

defined('ABSPATH') || exit;

use NativeCustomFields\App;
use NativeCustomFields\Services\ActivationService;
use NativeCustomFields\Services\DeactivationService;

if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

define('NATIVE_CUSTOM_FIELDS_URL', rtrim(plugin_dir_url(__FILE__), '/') . '/');
define('NATIVE_CUSTOM_FIELDS_PATH', plugin_dir_path(__FILE__));
define('NATIVE_CUSTOM_FIELDS_INCLUDES_PATH', plugin_dir_path(__FILE__) . 'includes/');

//Activation
if (!function_exists('nativeCustomFieldsInitActivation')) {
    function nativeCustomFieldsInitActivation()
    {
        $activation_service = new ActivationService();
        $activation_service->activate();
    }

    register_activation_hook(__FILE__, 'nativeCustomFieldsInitActivation');
}

//Deactivation
if (!function_exists('nativeCustomFieldsInitDeactivation')) {
    function nativeCustomFieldsInitDeactivation()
    {
        $deactivation_service = new DeactivationService();
        $deactivation_service->deactivate();
    }

    register_deactivation_hook(__FILE__, 'nativeCustomFieldsInitDeactivation');
}

//Run plugin
if (class_exists(App::class)) {
    try {
        $native_custom_fields_app = new App();
        $native_custom_fields_app::boot();
    } catch (Exception $e) {
        wp_die(esc_html($e->getMessage()));
    }
}
