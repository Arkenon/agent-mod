<?php
/**
 * Import Export controller class
 * Responsible for rendering import/export page and handling related actions.
 *
 * @package NativeCustomFields
 * @subpackage Presentation\Admin\Controllers
 * @since 1.0.5
 */

namespace NativeCustomFields\Presentation\Admin\Controllers;

use Exception;
use NativeCustomFields\Common\Helper;
use NativeCustomFields\Services\ImportExportService;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

final class ImportExportController
{

    /**
     * @var ImportExportService
     * @since 1.0.5
     */
    private ImportExportService $importExportService;

    public function __construct(ImportExportService $import_export_service)
    {
        $this->importExportService = $import_export_service;

        add_action('admin_menu', [$this, 'addSubmenu']);
        add_action('admin_post_native_custom_fields_export', [$this, 'exportData']);
        add_action('admin_post_native_custom_fields_import', [$this, 'importData']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueStyles']);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
    }

    /**
     * Enqueue styles for the import/export page.
     *
     * @return void
     * @since 1.0.5
     */
    public function enqueueStyles(): void
    {
        wp_enqueue_style(
            'native-custom-fields-import-export-admin',
            NATIVE_CUSTOM_FIELDS_URL . 'includes/Presentation/Admin/Assets/css/native-custom-fields-import-export.css',
            [],
            NATIVE_CUSTOM_FIELDS_VERSION
        );
    }

    /**
     * Handle import data action.
     *
     * @return void
     * @since 1.0.5
     */
    public function importData(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'native-custom-fields'));
        }

        $nonce = Helper::sanitize('native_custom_fields_import_nonce', 'post');
        if (!wp_verify_nonce($nonce, 'native_custom_fields_import_action')) {
            wp_die(esc_html__('Security check failed.', 'native-custom-fields'));
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- handled by Helper::getJsonFromFileUpload()
        $data = isset($_FILES['native_custom_fields_import_file'])
            ? Helper::getJsonFromFileUpload($_FILES['native_custom_fields_import_file']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            : [];

        $result = $this->importExportService->importData($data);

        if ($result) {
            wp_safe_redirect(admin_url('admin.php?page=native-custom-fields-import-export&result=success'));
        } else {
            wp_safe_redirect(admin_url('admin.php?page=native-custom-fields-import-export&result=error'));
        }
        exit;
    }

    /**
     * Handle export data action.
     *
     * @return void
     * @since 1.0.5
     */
    public function exportData(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'native-custom-fields'));
        }

        $nonce = Helper::sanitize('native_custom_fields_export_nonce', 'post');
        if (!wp_verify_nonce($nonce, 'native_custom_fields_export_action')) {
            wp_die(esc_html__('Security check failed.', 'native-custom-fields'));
        }

        $choices = isset($_POST['native_custom_fields_export_forms'])
            ? Helper::sanitizeArray(wp_unslash($_POST['native_custom_fields_export_forms'])) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            : [];

        $result = $this->importExportService->exportData($choices);

        if ($result['success']) {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="native-custom-fields-export_' . current_time('mysql') . '.json"');
            header('Pragma: no-cache');
            header('Expires: 0');
            echo wp_json_encode($result['data'], JSON_PRETTY_PRINT);
            exit;
        }

        wp_safe_redirect(admin_url('admin.php?page=native-custom-fields-import-export&result=error'));
        exit;
    }

    /**
     * Register REST API routes.
     *
     * @return void
     * @since 1.0.5
     */
    public function registerRestRoutes(): void
    {
        register_rest_route('native-custom-fields/v1', 'import-export/create-php', [
            'methods' => 'POST',
            'callback' => [$this, 'createPhp'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'args' => [
                'choices' => ['required' => true, 'type' => 'array'],
            ],
        ]);
    }

    /**
     * REST callback: Generate PHP code from selected export choices.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     * @since 1.0.5
     */
    public function createPhp(WP_REST_Request $request): WP_REST_Response
    {
        $choices = $request->get_param('choices');

        if (empty($choices) || !is_array($choices)) {
            return new WP_REST_Response(
                ['success' => false, 'message' => __('No choices provided.', 'native-custom-fields')],
                400
            );
        }

        $choices = Helper::sanitizeArray($choices);
        $result = $this->importExportService->exportData($choices);

        if (empty($result['success']) || empty($result['data'])) {
            return new WP_REST_Response(
                ['success' => false, 'message' => __('No data to convert.', 'native-custom-fields')],
                200
            );
        }

        $code = $this->importExportService->createPhpFromExport($result['data']);

        return new WP_REST_Response(['success' => true, 'code' => $code], 200);
    }

    /**
     * Add Import/Export submenu under the main plugin menu.
     *
     * @return void
     * @since 1.0.5
     */
    public function addSubmenu(): void
    {
        add_submenu_page(
            'native-custom-fields',
            esc_html__('Import/Export', 'native-custom-fields'),
            esc_html__('Import/Export', 'native-custom-fields'),
            'manage_options',
            'native-custom-fields-import-export',
            [$this, 'renderImportExportPage']
        );
    }

    /**
     * Render the import/export page.
     *
     * @return void
     * @since 1.0.5
     */
    public function renderImportExportPage(): void
    {
        ob_start();
        try {
            include NATIVE_CUSTOM_FIELDS_INCLUDES_PATH . 'Presentation/Admin/Views/admin-import-export-page.php';
            echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } catch (Exception $e) {
            ob_end_clean();
        }
    }
}