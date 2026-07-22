<?php

/**
 * Helper class for common functions
 *
 * @package NativeCustomFields
 * @subpackage Common
 * @since 1.0.0
 */

namespace NativeCustomFields\Common;

use NativeCustomFields\Models\Common\FieldsConfigModel;

defined('ABSPATH') || exit;

class Helper
{

    /**
     * Field types whose value must keep line breaks (sanitized with sanitize_textarea_field()
     * instead of sanitize_text_field(), which collapses \r\n\t and repeated spaces into a single space).
     *
     * @since 1.2.9
     */
    private const MULTILINE_FIELD_TYPES = ['textarea', 'code', 'wysiwyg'];

    /**
     * Get the raw (unslashed, unsanitized) value of a GET/POST/REQUEST input
     *
     * @param string $name Input name
     * @param string $method GET or POST or REQUEST
     *
     * @return mixed|null
     * @since 1.2.9
     */
    public static function getRawValue(string $name, string $method = 'post')
    {
        $method = strtolower($method);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- This is a helper method for sanitization, not directly processing form data.
        $input = $method === 'post' ? $_POST : ($method === 'get' ? $_GET : $_REQUEST);

        if (! isset($input[$name])) {
            return null;
        }

        return wp_unslash($input[$name]);
    }

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
        $value = self::getRawValue($name, $method);

        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return self::sanitizeArray($value);
        }

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
                return sanitize_text_field($value);
        }
    }

    /**
     * Sanitize array recursively
     * Blind, field-type-agnostic sanitization (everything treated as single-line text).
     * Use sanitizeFieldValue()/sanitizeFieldsByConfig() instead when field type configuration
     * (e.g. textarea) is available and line breaks must be preserved.
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

    /**
     * Sanitize a single field value according to its field type.
     * Arrays are handled recursively: 'repeater' values are lists of items each matching $fields,
     * 'group' values (or any array paired with $fields) are associative and matched by sub-field name.
     * Arrays without a matching $fields configuration (e.g. plain multi-select values) are sanitized
     * per-item using $field_type.
     *
     * @param mixed $value Raw (already unslashed) value
     * @param string $field_type Field type, e.g. 'text', 'textarea', 'number', 'toggle', 'group', 'repeater'
     * @param array $fields Sub-field configuration (for 'group'/'repeater' fields), each with 'name', 'fieldType', and optionally 'fields'
     *
     * @return mixed
     * @since 1.2.9
     */
    public static function sanitizeFieldValue($value, string $field_type = 'text', array $fields = [])
    {
        if (! is_array($value)) {
            return self::sanitizeScalarValue($value, $field_type);
        }

        if ($field_type === 'repeater') {
            return array_map(function ($item) use ($fields) {
                return is_array($item) ? self::sanitizeFieldsByConfig($item, $fields) : self::sanitizeScalarValue($item, 'text');
            }, $value);
        }

        if ($field_type === 'group' || ! empty($fields)) {
            return self::sanitizeFieldsByConfig($value, $fields);
        }

        return array_map(function ($item) use ($field_type) {
            return is_array($item) ? self::sanitizeArray($item) : self::sanitizeScalarValue($item, $field_type);
        }, $value);
    }

    /**
     * Sanitize an associative array of field values against a field configuration tree,
     * dispatching each value to sanitizeFieldValue() using its matching field's 'fieldType'
     * (and recursing into 'fields' for nested group/repeater sub-fields).
     * Keys without a matching field fall back to plain text sanitization.
     *
     * @param array $values Values keyed by field name
     * @param array $fields Field configuration list, each with 'name', 'fieldType', and optionally 'fields'
     *
     * @return array
     * @since 1.2.9
     */
    public static function sanitizeFieldsByConfig(array $values, array $fields): array
    {
        $field_map = [];
        foreach ($fields as $field) {
            if (isset($field['name'])) {
                $field_map[$field['name']] = $field;
            }
        }

        $result = [];
        foreach ($values as $key => $value) {
            $sanitized_key = is_string($key) ? sanitize_text_field($key) : $key;

            $field      = $field_map[$key] ?? null;
            $field_type = $field['fieldType'] ?? 'text';
            $sub_fields = $field['fields'] ?? [];

            $result[$sanitized_key] = self::sanitizeFieldValue($value, $field_type, $sub_fields);
        }

        return $result;
    }

    /**
     * Sanitize a scalar field value according to its field type
     *
     * @param mixed $value
     * @param string $field_type
     *
     * @return mixed
     * @since 1.2.9
     */
    private static function sanitizeScalarValue($value, string $field_type)
    {
        if (! is_scalar($value)) {
            return $value;
        }

        if (in_array($field_type, self::MULTILINE_FIELD_TYPES, true)) {
            return sanitize_textarea_field((string) $value);
        }

        switch ($field_type) {
            case 'url':
            case 'external_link':
                return esc_url_raw((string) $value);
            case 'email':
                return sanitize_email((string) $value);
            case 'number':
            case 'range':
                return is_numeric($value) ? $value + 0 : sanitize_text_field((string) $value);
            case 'toggle':
            case 'checkbox':
                return rest_sanitize_boolean($value);
            default:
                return sanitize_text_field((string) $value);
        }
    }

    /**
     * Get allowed endpoint mask list for permalink structure
     *
     * @return array
     * @since 1.0.0
     */
    public static function getAllowedEpMaskList(): array
    {
        return [
            'EP_ALL',
            'EP_NONE',
            'EP_ALL_ARCHIVES',
            'EP_ATTACHMENT',
            'EP_AUTHORS',
            'EP_CATEGORIES',
            'EP_COMMENTS',
            'EP_DATE',
            'EP_DAY',
            'EP_MONTH',
            'EP_YEAR',
            'EP_PAGES',
            'EP_PERMALINK',
            'EP_ROOT',
            'EP_SEARCH',
            'EP_TAGS',
            'EP_TAGS'
        ];
    }

    /**
     * Verify AJAX nonce for Native Custom Fields actions
     *
     * Uses general nonce 'native_custom_fields' for all AJAX actions.
     * The nonce is created in ClientController and AdminController.
     *
     * @param string $action Nonce action name (default: 'native_custom_fields')
     * @param string $query_arg Query argument name in $_REQUEST (default: 'nonce')
     *
     * @return void Dies with JSON error if nonce is invalid
     * @since 1.0.0
     */
    public static function ajaxGuard(string $action = 'native_custom_fields', string $query_arg = 'nonce'): void
    {
        if (! check_ajax_referer($action, $query_arg, false)) {
            wp_send_json_error(['message' => __('Invalid nonce', 'native-custom-fields')]);
        }
    }

    /**
     * Upload files to the media library
     *
     * Note: Nonce verification must be done by the calling method before invoking this function.
     * This method is a utility function and should only be called from properly secured contexts.
     *
     * @return array|false
     * @since 1.0.0
     */
    public static function uploadFiles()
    {

        $file_to_upload = [];

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by the calling method before invoking this function.
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Files are sanitized in the loop below.
        if (isset($_FILES['files'])) {
            $files = $_FILES['files'];

            if (!function_exists('wp_handle_upload')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            foreach ($files['name'] as $key => $name) {

                $upload_overrides = ['test_form' => false];

                if (!empty($files['name'][$key])) {

                    $file = [
                        'name' => sanitize_file_name($files['name'][$key]),
                        'type' => sanitize_mime_type($files['type'][$key]),
                        'tmp_name' => sanitize_text_field($files['tmp_name'][$key]),
                        'error' => sanitize_text_field($files['error'][$key]),
                        'size' => (int)$files['size'][$key],
                    ];

                    $upload = wp_handle_upload($file, $upload_overrides);

                    $filename = $upload['file'];
                    $filetype = wp_check_filetype(basename($filename), null);
                    $wp_upload_dir = wp_upload_dir();
                    $post_title = preg_replace('/\.[^.]+$/', '', basename($filename));
                    $attachment = [
                        'guid' => $wp_upload_dir['url'] . '/' . basename($filename),
                        'post_mime_type' => $filetype['type'],
                        'post_title' => $post_title,
                        'post_content' => '',
                        'post_status' => 'inherit',
                    ];
                    $attach_id = wp_insert_attachment($attachment, $filename);

                    if (is_wp_error($attach_id)) {
                        return false;
                    }

                    $file_url = wp_get_attachment_url($attach_id);
                    $file_to_upload[] = [
                        'file_to_upload' => esc_url($file_url),
                        'file_name' => $post_title,
                    ];
                }
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        return $file_to_upload;
    }

    /**
     * Get JSON data from uploaded file
     * Convert uploaded JSON file to array
     *
     * @param array $file Uploaded file array
     *
     * @return array
     * @since 1.0.0
     */
    public static function getJsonFromFileUpload(array $file): array
    {

        if (isset($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
            $content = file_get_contents($file['tmp_name']);
            if (self::isJson($content)) {
                return json_decode($content, true);
            }
        }

        return [];
    }

    /**
     * Normalize meta type to allowed values.
     * Allowed types: string, boolean, integer, number, array, object
     * Used for register_post_meta, register_term_meta etc.
     *
     * @param string $type
     *
     * @return string
     * @since 1.0.0
     */
    public static function normalizeMetaType(string $type): string
    {
        $allowed_types = ['string', 'boolean', 'integer', 'number', 'array', 'object'];

        return in_array($type, $allowed_types, true) ? $type : 'string';
    }

    /**
     * Get sections and fields from configuration
     *
     * @param FieldsConfigModel $config Fields configuration
     *
     * @return array
     */
    public static function getSectionsAndFieldsFromConfig(FieldsConfigModel $config): array
    {
        if (!empty($config->sections)) {
            return $config->sections;
        }

        return [];
    }

    /**
     * Get sections from configuration
     * It can also be used for meta boxes.
     * @param array $config Fields configuration
     *
     * @return array
     */
    public static function getSectionsFromConfig(array $config): array
    {
        if (!empty($config['sections'])) {
            return $config['sections'];
        }

        return [];
    }

    /**
     * Check if the menu slug is a builder page
     * @param string $menu_slug
     * @return bool
     * @since 1.0.0
     */
    public static function isBuilderPage(string $menu_slug): bool
    {
        return in_array($menu_slug, Constants::BUILDER_MENU_SLUGS, true);
    }

    /**
     * Fields that are already have input tag
     * @return array
     * @since 1.0.0
     */
    public static function fieldsAlreadyHaveInput(): array
    {
        return ['text', 'textarea', 'number', 'input', 'range', 'combobox'];
    }

    /**
     * Check if a string is valid JSON
     *
     * @param string $string String to check
     *
     * @return boolean
     */
    public static function isJson(string $string): bool
    {

        json_decode($string);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Get allowed hidden input html
     *
     * @return array
     */
    public static function getAllowedHiddenInputHtml(): array
    {
        return [
            'input' => [
                'id' => [],
                'type' => [],
                'name' => [],
                'value' => [],
            ],
        ];
    }

    /**
     * Convert snake_case to camelCase
     *
     * @param array $data Data to convert
     *
     * @return array
     */
    public static function snakeToCamelArray(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $camelKey = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
            if (is_array($value)) {
                $value = self::snakeToCamelArray($value);
            }
            $result[$camelKey] = $value;
        }

        return $result;
    }

    /**
     * Renders a builder page wrapper div
     *
     * @param string $wrapperClass The CSS class for the wrapper div
     *
     * @return void
     * @since 1.0.0
     */
    public static function renderBuilderPage(string $wrapperClass): void
    {
        $screen = get_current_screen();
        $id = str_replace('toplevel_page_', '', $screen->id);

        $html = sprintf(
            '<div class="%s" id="%s"></div>',
            esc_attr($wrapperClass),
            esc_attr($id)
        );

        echo wp_kses_post($html);
    }
}
