<?php

/**
 * Field Service
 * Provides available field types and container types.
 * Both lists can be extended via filter hooks.
 *
 * @package NativeCustomFields
 * @subpackage Services
 * @since 1.0.0
 */

namespace NativeCustomFields\Services;

defined('ABSPATH') || exit;

class FieldService
{

    /**
     * Returns available container types.
     * Can be extended via the 'native_custom_fields_container_types' filter.
     *
     * @return array
     * @since 1.0.0
     */
    public function getContainerTypes(): array
    {
        $types = [
            ['value' => 'section', 'label' => __('Section', 'native-custom-fields')],
            ['value' => 'meta_box', 'label' => __('Meta Box', 'native-custom-fields')],
        ];

        return apply_filters('native_custom_fields_container_types', $types);
    }

    /**
     * Returns available field types.
     * Can be extended via the 'native_custom_fields_field_types' filter.
     *
     * @return array
     * @since 1.0.0
     */
    public function getFieldTypes(): array
    {
        $types = [
            ['value' => 'group', 'label' => __('Group', 'native-custom-fields')],
            ['value' => 'repeater', 'label' => __('Repeater', 'native-custom-fields')],
            ['value' => 'input', 'label' => __('Input Control', 'native-custom-fields')],
            ['value' => 'text', 'label' => __('Text Control', 'native-custom-fields')],
            ['value' => 'number', 'label' => __('Number Control', 'native-custom-fields')],
            ['value' => 'select', 'label' => __('Select Control', 'native-custom-fields')],
            ['value' => 'checkbox', 'label' => __('Checkbox Control', 'native-custom-fields')],
            ['value' => 'radio', 'label' => __('Radio Control', 'native-custom-fields')],
            ['value' => 'textarea', 'label' => __('Textarea Control', 'native-custom-fields')],
            ['value' => 'range', 'label' => __('Range Control', 'native-custom-fields')],
            ['value' => 'toggle', 'label' => __('Toggle Control', 'native-custom-fields')],
            ['value' => 'color_picker', 'label' => __('Color Picker', 'native-custom-fields')],
            ['value' => 'color_palette', 'label' => __('Color Palette', 'native-custom-fields')],
            ['value' => 'date_picker', 'label' => __('Date Picker', 'native-custom-fields')],
            ['value' => 'date_time_picker', 'label' => __('DateTime Picker', 'native-custom-fields')],
            ['value' => 'time_picker', 'label' => __('Time Picker', 'native-custom-fields')],
            ['value' => 'unit', 'label' => __('Unit Control', 'native-custom-fields')],
            ['value' => 'angle_picker', 'label' => __('Angle Picker Control', 'native-custom-fields')],
            ['value' => 'alignment_matrix', 'label' => __('Alignment Matrix Control', 'native-custom-fields')],
            ['value' => 'border_box', 'label' => __('Border Box Control', 'native-custom-fields')],
            ['value' => 'border', 'label' => __('Border Control', 'native-custom-fields')],
            ['value' => 'box', 'label' => __('Box Control', 'native-custom-fields')],
            ['value' => 'toggle_group', 'label' => __('Toggle Group Control', 'native-custom-fields')],
            ['value' => 'combobox', 'label' => __('Combobox Field', 'native-custom-fields')],
            ['value' => 'font_size', 'label' => __('Font Size Picker', 'native-custom-fields')],
            ['value' => 'file_upload', 'label' => __('File Upload', 'native-custom-fields')],
            ['value' => 'media_library', 'label' => __('Media Library', 'native-custom-fields')],
            ['value' => 'token_field', 'label' => __('Form Token', 'native-custom-fields')],
            ['value' => 'external_link', 'label' => __('ExternalLink', 'native-custom-fields')],
            ['value' => 'heading', 'label' => __('Heading', 'native-custom-fields')],
            ['value' => 'notice', 'label' => __('Notice', 'native-custom-fields')],
            ['value' => 'text_highlight', 'label' => __('Text Highlight', 'native-custom-fields')],
        ];

        return apply_filters('native_custom_fields_field_types', $types);
    }


    /**
     * Get dashboard items to display on the dashboard
     * @return array
     * @since 1.0.0
     *
     */
    public function getDashboardItems(): array
    {
        $items = [
            [
                'label' => __('Post Types & Post Meta Fields', 'native-custom-fields'),
                'description' => __('Create custom post types with custom fields', 'native-custom-fields'),
                'icon' => 'postList',
                'page' => 'native-custom-fields-post-type-builder'
            ],
            [
                'label' => __('Custom Taxonomies & Term Meta Fields', 'native-custom-fields'),
                'description' => __('Create custom taxonomies with custom fields', 'native-custom-fields'),
                'icon' => 'category',
                'page' => 'native-custom-fields-taxonomy-builder'
            ],
            [
                'label' => __('User Meta Fields', 'native-custom-fields'),
                'description' => __('Add custom fields to user profiles', 'native-custom-fields'),
                'icon' => 'people',
                'page' => 'native-custom-fields-user-meta-builder'
            ],

            // Added 1.0.5
            [
                'label' => __('Create Option Pages', 'native-custom-fields'),
                'description' => __('Create custom option pages for your site settings', 'native-custom-fields'),
                'icon' => 'settings',
                'page' => 'native-custom-fields-options-page-builder',
            ],

            [
                'label' => __('Import/Export Data', 'native-custom-fields'),
                'description' => __('Import or export data with JSON or PHP format.', 'native-custom-fields'),
                'icon' => 'cloudDownload',
                'page' => 'native-custom-fields-import-export',
            ],
        ];
        return apply_filters('native_custom_fields_dashboard_items', $items);
    }
}
