<?php

/**
 * Options Page Abilities Service
 * Registers WP Abilities API abilities for creating, updating, and deleting options pages and their fields.
 *
 * @package NativeCustomFields
 * @subpackage Services\Abilities
 * @since 1.0.1
 */

namespace NativeCustomFields\Services\Abilities;

use Exception;
use NativeCustomFields\Services\OptionService;

defined('ABSPATH') || exit;

class OptionsPageAbilitiesService
{
    use AbilityFieldAdapterTrait;

    private OptionService $optionService;

    public function __construct(OptionService $optionService)
    {
        $this->optionService = $optionService;
    }

    /**
     * Register Abilities
     * 
     * @return void
     * @since 1.0.1
     */
    public function registerAbilities(): void
    {
        $options_page_schema = [
            'type'       => 'object',
            'required'   => ['menu_slug', 'page_title'],
            'properties' => [
                'menu_slug'  => ['type' => 'string', 'description' => __('Unique menu slug for the options page', 'native-custom-fields')],
                'page_title' => ['type' => 'string', 'description' => __('Browser/page title', 'native-custom-fields')],
                'menu_title' => ['type' => 'string', 'description' => __('Menu label shown in the sidebar (defaults to page_title if not set)', 'native-custom-fields')],
                'layout'     => ['type' => 'string', 'enum' => ['stacked', 'navigation', 'tab_panel'], 'description' => __('Layout for the options page', 'native-custom-fields')],
                'icon_url'   => ['type' => 'string', 'description' => __('Dashicons class or URL', 'native-custom-fields')],
                'position'   => ['type' => 'integer', 'description' => __('Menu position order', 'native-custom-fields')],
            ],
        ];

        $field_schema = $this->getFieldSchema();
        $field_schema['properties']['name']['description'] = __('Option key (unique slug)', 'native-custom-fields');

        $section_schema = [
            'type'       => 'object',
            'required'   => ['section_name', 'section_title'],
            'properties' => [
                'section_name'  => ['type' => 'string', 'description' => __('Section slug (unique ID)', 'native-custom-fields')],
                'section_title' => ['type' => 'string', 'description' => __('Section title displayed in admin', 'native-custom-fields')],
                'section_icon'  => ['type' => 'string', 'description' => __('Dashicon name without prefix (default: admin-generic)', 'native-custom-fields')],
                'fields'        => ['type' => 'array', 'items' => $field_schema],
            ],
        ];

        $save_fields_schema = [
            'type'       => 'object',
            'required'   => ['menu_slug', 'sections'],
            'properties' => [
                'menu_slug' => ['type' => 'string', 'description' => __('The options page menu slug', 'native-custom-fields')],
                'sections'  => ['type' => 'array', 'items' => $section_schema],
            ],
        ];

        $response_schema = [
            'type'       => 'object',
            'properties' => [
                'status'  => ['type' => 'boolean'],
                'message' => ['type' => 'string'],
            ],
        ];

        $permission = fn() => current_user_can('manage_options');

        wp_register_ability('native-custom-fields/create-options-page', [
            'label'               => __('Create Options Page', 'native-custom-fields'),
            'description'         => __('Creates a new admin options page.', 'native-custom-fields'),
            'category'            => 'native-custom-fields',
            'execute_callback'    => [$this, 'saveOptionsPage'],
            'input_schema'        => $options_page_schema,
            'output_schema'       => $response_schema,
            'permission_callback' => $permission,
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => [
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ]);

        wp_register_ability('native-custom-fields/update-options-page', [
            'label'               => __('Update Options Page', 'native-custom-fields'),
            'description'         => __('Updates the configuration of an existing admin options page.', 'native-custom-fields'),
            'category'            => 'native-custom-fields',
            'execute_callback'    => [$this, 'saveOptionsPage'],
            'input_schema'        => $options_page_schema,
            'output_schema'       => $response_schema,
            'permission_callback' => $permission,
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => [
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ]);

        wp_register_ability('native-custom-fields/save-options-page-fields', [
            'label'               => __('Save Options Page Fields', 'native-custom-fields'),
            'description'         => __('Creates or updates the custom field configuration for an options page.', 'native-custom-fields'),
            'category'            => 'native-custom-fields',
            'execute_callback'    => [$this, 'saveOptionsPageFields'],
            'input_schema'        => $save_fields_schema,
            'output_schema'       => $response_schema,
            'permission_callback' => $permission,
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => [
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ]);
    }

    /**
     * Save Options Page Ability
     *
     * @param array $input Input data
     * @return array Response data
     * @since 1.0.1
     */
    public function saveOptionsPage(array $input): array
    {
        try {
            $menu_slug = sanitize_key($input['menu_slug'] ?? '');

            if (empty($menu_slug)) {
                return ['status' => false, 'message' => __('menu_slug is required.', 'native-custom-fields')];
            }

            $page_title = sanitize_text_field($input['page_title'] ?? '');

            if (empty($page_title)) {
                return ['status' => false, 'message' => __('page_title is required.', 'native-custom-fields')];
            }

            $menu_title = sanitize_text_field($input['menu_title'] ?? '') ?: $page_title;

            $builder_slug = 'native_custom_fields_options_page_builder_' . $menu_slug;

            $values = [
                'native_custom_fields_create_options_page' => [
                    'menu_slug'  => $menu_slug,
                    'page_title' => $page_title,
                    'menu_title' => $menu_title,
                    'layout'     => sanitize_text_field($input['layout'] ?? 'stacked'),
                    'icon_url'   => sanitize_text_field($input['icon_url'] ?? 'dashicons-admin-generic'),
                    'position'   => isset($input['position']) ? (int) $input['position'] : 60,
                ],
            ];

            $response = $this->optionService->saveOptionsPageConfig($builder_slug, $values);

            if ($response->status) {
                $this->optionService->saveOptions($menu_slug, $values);
            }

            return ['status' => $response->status, 'message' => $response->message];
        } catch (Exception $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Save Options Page Fields Ability
     *
     * @param array $input Input data
     * @return array Response data
     * @since 1.0.1
     */
    public function saveOptionsPageFields(array $input): array
    {
        try {
            $menu_slug = sanitize_key($input['menu_slug'] ?? '');

            if (empty($menu_slug)) {
                return ['status' => false, 'message' => __('menu_slug is required.', 'native-custom-fields')];
            }

            $sections = $input['sections'] ?? [];

            if (empty($sections)) {
                return ['status' => false, 'message' => __('sections is required.', 'native-custom-fields')];
            }

            $builder_slug = 'native_custom_fields_options_page_fields_builder_' . $menu_slug;

            $sections_or_meta_boxes = [];
            foreach ($sections as $section) {
                $sections_or_meta_boxes[] = [
                    'name'                      => sanitize_key($section['section_name'] ?? ''),
                    'fieldLabel'                => sanitize_text_field($section['section_title'] ?? ''),
                    'field_custom_info_section' => ['section_icon' => sanitize_text_field($section['section_icon'] ?? 'admin-generic')],
                    'fields'                    => $this->prepareAbilityFields($section['fields'] ?? []),
                ];
            }

            $values = [
                'menu_slug'              => $menu_slug,
                'sections_or_meta_boxes' => $sections_or_meta_boxes,
            ];

            $response = $this->optionService->saveOptionPageFieldsConfig($builder_slug, $values);

            return ['status' => $response->status, 'message' => $response->message];
        } catch (Exception $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }
}
