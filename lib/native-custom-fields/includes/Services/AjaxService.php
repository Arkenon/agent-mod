<?php

/**
 * Ajax Service class for handling AJAX requests
 *
 * @package NativeCustomFields
 * @subpackage Services
 * @since 1.0.0
 */

namespace NativeCustomFields\Services;

use NativeCustomFields\Common\Helper;

defined('ABSPATH') || exit;

class AjaxService
{
    /**
     * Constructor
     */
    public function __construct()
    {
        add_action('wp_ajax_native_custom_fields_upload_files', [$this, 'handleFileUpload']);
    }

    /**
     * Handle file upload
     *
     * @return void
     * @since 1.0.0
     */
    public function handleFileUpload()
    {
        // Verify nonce
        Helper::ajaxGuard();

        // Upload files using Helper class
        $uploaded_files = Helper::uploadFiles();

        if ($uploaded_files === false) {
            wp_send_json_error(['message' => __('Error uploading files', 'native-custom-fields')]);
        }

        wp_send_json_success([
            'message' => __('Files uploaded successfully', 'native-custom-fields'),
            'files'   => $uploaded_files,
        ]);
    }
}
