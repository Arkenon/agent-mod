<?php
/**
 * Uninstall file for the plugin
 * Runs on uninstallation of the plugin
 * @package NativeCustomFields
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

// If plugin basename is not set, then return.
if ( __FILE__ != WP_UNINSTALL_PLUGIN ) {
	return;
}

// If current user can't activate plugins, then exit.
if ( ! current_user_can( 'activate_plugins' ) ) {
	return;
}
