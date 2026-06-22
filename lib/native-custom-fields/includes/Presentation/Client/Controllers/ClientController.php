<?php
/**
 * Client controller class
 * Creates main menu and submenus for admin area
 * @package NativeCustomFields
 * @subpackage Presentation\Client\Controllers
 * @since 1.0.0
 */


namespace NativeCustomFields\Presentation\Client\Controllers;

use NativeCustomFields\Services\FieldService;

defined('ABSPATH') || exit;


final class ClientController
{
    private FieldService $fieldService;

    public function __construct( FieldService $fieldService )
    {
        // Inject dependencies
        $this->fieldService = $fieldService;

        // Register hooks
        add_action('wp_enqueue_scripts', [$this, 'enqueueScripts']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueStyles']);
    }


    /**
     * Enqueue scripts for the client area
     * @return void
     * @since 1.0.0
     */
    public function enqueueScripts(): void
    {
        // Enqueue script for client side
        $asset_file = require  NATIVE_CUSTOM_FIELDS_PATH . '/build/client/index.asset.php';
        wp_enqueue_script(
            'native-custom-fields',
            NATIVE_CUSTOM_FIELDS_URL . '/build/client/index.js',
            $asset_file['dependencies'],
            $asset_file['version'],
            true
        );

        wp_localize_script('native-custom-fields', 'nativeCustomFieldsData', [
            'nonce'          => wp_create_nonce('native_custom_fields'),
            'assets_url'     => NATIVE_CUSTOM_FIELDS_URL . '/includes/Presentation/Client/Assets/',
            'rest_url'       => esc_url_raw(rest_url()),
            'ajax_url'       => esc_url_raw(admin_url('admin-ajax.php')),
            'field_types'    => $this->fieldService->getFieldTypes(),
            'container_types' => $this->fieldService->getContainerTypes(),
            'dashboard_items' => $this->fieldService->getDashboardItems()
        ]);
    }

    /**
     * Enqueue styles for the client area
     * @return void
     * @since 1.0.0
     */

    public function enqueueStyles(): void
    {
        // Enqueue styles for client side
        wp_enqueue_style(
            'native-custom-fields',
            NATIVE_CUSTOM_FIELDS_URL . '/build/client/index.css',
            ['wp-components'],
            NATIVE_CUSTOM_FIELDS_VERSION
        );
    }
}
