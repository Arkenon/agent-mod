<?php

/**
 * Term Meta Fields Abilities Service
 * Registers WP Abilities API abilities for creating, updating, and deleting term meta field configurations.
 *
 * @package NativeCustomFields
 * @subpackage Services\Abilities
 * @since 1.0.1
 */

namespace NativeCustomFields\Services\Abilities;

use Exception;
use NativeCustomFields\Services\OptionService;
use NativeCustomFields\Services\TermMetaService;

defined('ABSPATH') || exit;

class TermMetaFieldsAbilitiesService
{
    use AbilityFieldAdapterTrait;
    use AbilityReadTrait;
    private TermMetaService $termMetaService;
    private OptionService $optionService;

    public function __construct(TermMetaService $termMetaService, OptionService $optionService)
    {
        $this->termMetaService = $termMetaService;
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
                'section_title' => ['type' => 'string', 'description' => __('Section title displayed in admin', 'native-custom-fields')],
                'section_icon'  => ['type' => 'string', 'description' => __('Dashicon name without prefix (default: admin-generic)', 'native-custom-fields')],
                'fields'        => ['type' => 'array', 'items' => $field_schema],
            ],
        ];

        $save_schema = [
            'type'       => 'object',
            'required'   => ['taxonomy', 'sections'],
            'properties' => [
                'taxonomy' => ['type' => 'string', 'description' => __('The taxonomy slug to attach fields to', 'native-custom-fields')],
                'sections' => ['type' => 'array', 'items' => $section_schema],
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

        $this->registerReadAbilities($permission);

        wp_register_ability('native-custom-fields/save-term-meta-fields', [
            'label'               => __('Save Term Meta Fields', 'native-custom-fields'),
            'description'         => __('Creates or updates the custom field configuration for a taxonomy.', 'native-custom-fields'),
            'category'            => 'native-custom-fields',
            'execute_callback'    => [$this, 'saveTermMetaFields'],
            'input_schema'        => $save_schema,
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
     * Register the read-only abilities (list / get) for term meta field configurations.
     *
     * @param callable $permission Permission callback
     * @return void
     * @since 1.4.1
     */
    private function registerReadAbilities(callable $permission): void
    {
        $read_meta = $this->getReadAbilityMeta();

        wp_register_ability('native-custom-fields/list-term-meta-fields', [
            'label'               => __('List Term Meta Field Configurations', 'native-custom-fields'),
            'description'         => __('Lists the taxonomies that have a custom field configuration, with their section and field counts.', 'native-custom-fields'),
            'category'            => 'native-custom-fields',
            'execute_callback'    => [$this, 'listTermMetaFields'],
            'permission_callback' => $permission,
            'meta'                => $read_meta,
        ]);

        wp_register_ability('native-custom-fields/get-term-meta-fields', [
            'label'               => __('Get Term Meta Fields', 'native-custom-fields'),
            'description'         => __('Reads the custom field configuration of a taxonomy in the same shape that save-term-meta-fields accepts, so the result can be edited and saved back. Also reports whether the builder UI state (Edit Fields screen) exists.', 'native-custom-fields'),
            'category'            => 'native-custom-fields',
            'execute_callback'    => [$this, 'getTermMetaFields'],
            'input_schema'        => [
                'type'       => 'object',
                'required'   => ['taxonomy'],
                'properties' => [
                    'taxonomy' => ['type' => 'string', 'description' => __('The taxonomy slug', 'native-custom-fields')],
                ],
            ],
            'permission_callback' => $permission,
            'meta'                => $read_meta,
        ]);
    }

    /**
     * List Term Meta Fields Ability
     *
     * @return array Response data
     * @since 1.4.1
     */
    public function listTermMetaFields(): array
    {
        $items = [];

        foreach ($this->termMetaService->getTermMetaFieldsConfigurations() as $taxonomy => $config) {
            $sections    = $config['sections'] ?? [];
            $field_count = 0;
            foreach ($sections as $section) {
                $field_count += count($section['fields'] ?? []);
            }

            $items[] = [
                'taxonomy'      => $config['taxonomy'] ?? $taxonomy,
                'section_count' => count($sections),
                'field_count'   => $field_count,
            ];
        }

        return ['status' => true, 'configurations' => $items];
    }

    /**
     * Get Term Meta Fields Ability
     *
     * @param array $input Input data
     * @return array Response data
     * @since 1.4.1
     */
    public function getTermMetaFields(array $input): array
    {
        $taxonomy = sanitize_key($input['taxonomy'] ?? '');

        if (empty($taxonomy)) {
            return ['status' => false, 'message' => __('taxonomy is required.', 'native-custom-fields')];
        }

        $configs = $this->termMetaService->getTermMetaFieldsConfigurations();

        if (! isset($configs[$taxonomy])) {
            return ['status' => false, 'message' => __('No field configuration found for this taxonomy.', 'native-custom-fields')];
        }

        $sections = [];
        foreach ($configs[$taxonomy]['sections'] ?? [] as $section) {
            $sections[] = [
                'section_name'  => $section['section_name'] ?? '',
                'section_title' => $section['section_title'] ?? '',
                'section_icon'  => $section['section_icon'] ?? '',
                'fields'        => $this->extractAbilityFields($section['fields'] ?? []),
            ];
        }

        return [
            'status'        => true,
            'taxonomy'      => $taxonomy,
            'sections'      => $sections,
            'builder_state' => [
                'fields_form_present' => $this->hasBuilderState('native_custom_fields_term_meta_fields_builder_' . $taxonomy),
            ],
        ];
    }

    /**
     * Save Term Meta Fields Ability
     *
     * @param array $input Input data
     * @return array Response data
     * @since 1.0.1
     */
    public function saveTermMetaFields(array $input): array
    {
        try {
            $taxonomy = sanitize_key($input['taxonomy'] ?? '');

            if (empty($taxonomy)) {
                return ['status' => false, 'message' => __('taxonomy is required.', 'native-custom-fields')];
            }

            $sections = $input['sections'] ?? [];

            if (empty($sections)) {
                return ['status' => false, 'message' => __('sections is required.', 'native-custom-fields')];
            }

            $menu_slug = 'native_custom_fields_term_meta_fields_builder_' . $taxonomy;

            $sections_or_meta_boxes = [];
            foreach ($sections as $section) {
                $sections_or_meta_boxes[] = [
                    'fieldType'                 => 'section',
                    'name'                      => sanitize_key($section['section_name'] ?? ''),
                    'fieldLabel'                => sanitize_text_field($section['section_title'] ?? ''),
                    'field_custom_info_section' => ['section_icon' => sanitize_text_field($section['section_icon'] ?? 'admin-generic')],
                    'field_base_info'           => $this->getDefaultFieldBaseInfo(),
                    'field_dependency_info'     => $this->getDefaultDependencyInfo(),
                    'fields'                    => $this->prepareAbilityFields($section['fields'] ?? []),
                ];
            }

            $values = [
                'taxonomy'               => $taxonomy,
                'sections_or_meta_boxes' => $sections_or_meta_boxes,
            ];

            $response = $this->termMetaService->saveTermMetaFieldsConfig($menu_slug, $values);

            if ($response->status) {
                $this->optionService->saveOptions($menu_slug, $values);
            }

            return ['status' => $response->status, 'message' => $response->message];
        } catch (Exception $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }
}
