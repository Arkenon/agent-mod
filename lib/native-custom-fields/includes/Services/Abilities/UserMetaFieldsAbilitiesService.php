<?php

/**
 * User Meta Fields Abilities Service
 * Registers WP Abilities API abilities for creating, updating, and deleting user meta field configurations.
 *
 * @package NativeCustomFields
 * @subpackage Services\Abilities
 * @since 1.0.1
 */

namespace NativeCustomFields\Services\Abilities;

use Exception;
use NativeCustomFields\Services\OptionService;
use NativeCustomFields\Services\UserMetaService;

defined('ABSPATH') || exit;

class UserMetaFieldsAbilitiesService
{
    use AbilityFieldAdapterTrait;
    private UserMetaService $userMetaService;
    private OptionService $optionService;
    public function __construct(UserMetaService $userMetaService, OptionService $optionService)
    {
        $this->userMetaService = $userMetaService;
        $this->optionService   = $optionService;
    }

    /**
     * Register Abilities
     *
     * @return void
     * @since 1.0.1
     */
    public function registerAbilities(): void
    {
        $field_schema = $this->getFieldSchema();

        $section_schema = [
            'type'       => 'object',
            'required'   => ['section_name', 'section_title'],
            'properties' => [
                'section_name'  => ['type' => 'string', 'description' => __('Section slug (unique ID)', 'native-custom-fields')],
                'section_title' => ['type' => 'string', 'description' => __('Section title displayed on the user profile page', 'native-custom-fields')],
                'section_icon'  => ['type' => 'string', 'description' => __('Dashicon name without prefix', 'native-custom-fields')],
                'fields'        => ['type' => 'array', 'items' => $field_schema],
            ],
        ];

        $save_schema = [
            'type'       => 'object',
            'required'   => ['sections'],
            'properties' => [
                'sections' => [
                    'type'        => 'array',
                    'items'       => $section_schema,
                    'description' => __('Field sections to display on the user profile/edit page (applies to all users)', 'native-custom-fields'),
                ],
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

        wp_register_ability('native-custom-fields/save-user-meta-fields', [
            'label'               => __('Save User Meta Fields', 'native-custom-fields'),
            'description'         => __('Creates or updates the custom field configuration shown on user profile pages (applies to all users).', 'native-custom-fields'),
            'category'            => 'native-custom-fields',
            'execute_callback'    => [$this, 'saveUserMetaFields'],
            'input_schema'        => $save_schema,
            'output_schema'       => $response_schema,
            'permission_callback' => $permission,
            'meta'                => ['show_in_rest' => true, 'mcp' => ['public' => true]],
        ]);
    }

    /**
     * Save User Meta Fields Ability
     *
     * @param array $input Input data
     * @return array Response data
     * @since 1.0.1
     */
    public function saveUserMetaFields(array $input): array
    {
        try {
            $sections = $input['sections'] ?? [];

            if (empty($sections)) {
                return ['status' => false, 'message' => __('sections is required.', 'native-custom-fields')];
            }

            $menu_slug = 'native_custom_fields_user_meta_fields_builder_all_users';

            $sections_or_meta_boxes = [];
            foreach ($sections as $section) {
                $sections_or_meta_boxes[] = [
                    'fieldType'                 => 'section',
                    'name'                      => sanitize_key($section['section_name'] ?? ''),
                    'fieldLabel'                => sanitize_text_field($section['section_title'] ?? ''),
                    'field_custom_info_section' => ['section_icon' => sanitize_text_field($section['section_icon'] ?? '')],
                    'fields'                    => $this->prepareAbilityFields($section['fields'] ?? []),
                ];
            }

            $values = [
                'sections_or_meta_boxes' => $sections_or_meta_boxes,
            ];

            $response = $this->userMetaService->saveUserMetaFieldsConfig($menu_slug, $values);

            if ($response->status) {
                $this->optionService->saveOptions($menu_slug, $values);
            }

            return ['status' => $response->status, 'message' => $response->message];
        } catch (Exception $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }
}
