<?php

/**
 * Post Meta Fields Abilities Service
 * Registers WP Abilities API abilities for creating, updating, and deleting post meta field configurations.
 *
 * @package NativeCustomFields
 * @subpackage Services\Abilities
 * @since 1.0.1
 */

namespace NativeCustomFields\Services\Abilities;

use Exception;
use NativeCustomFields\Services\OptionService;
use NativeCustomFields\Services\PostMetaService;

defined('ABSPATH') || exit;

class PostMetaFieldsAbilitiesService
{
    use AbilityFieldAdapterTrait;
    use AbilityReadTrait;
    private PostMetaService $postMetaService;
    private OptionService $optionService;
    public function __construct(PostMetaService $postMetaService, OptionService $optionService)
    {
        $this->postMetaService = $postMetaService;
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
            'required'   => ['meta_box_id', 'meta_box_title'],
            'properties' => [
                'meta_box_id'       => ['type' => 'string', 'description' => __('Meta box ID (unique slug)', 'native-custom-fields')],
                'meta_box_title'    => ['type' => 'string', 'description' => __('Meta box title displayed in admin', 'native-custom-fields')],
                'meta_box_context'  => ['type' => 'string', 'enum' => ['normal', 'side', 'advanced'], 'description' => __('Where the meta box appears in the edit screen (default: advanced)', 'native-custom-fields')],
                'meta_box_priority' => ['type' => 'string', 'enum' => ['default', 'high', 'core', 'low'], 'description' => __('Priority within the context (default: default)', 'native-custom-fields')],
                'fields'            => ['type' => 'array', 'items' => $field_schema],
            ],
        ];

        $save_schema = [
            'type'       => 'object',
            'required'   => ['post_type', 'sections'],
            'properties' => [
                'post_type' => ['type' => 'string', 'description' => __('The post type slug to attach fields to', 'native-custom-fields')],
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

        $this->registerReadAbilities($permission);

        wp_register_ability('native-custom-fields/save-post-meta-fields', [
            'label'               => __('Save Post Meta Fields', 'native-custom-fields'),
            'description'         => __('Creates or updates the custom field configuration for a post type.', 'native-custom-fields'),
            'category'            => 'native-custom-fields',
            'execute_callback'    => [$this, 'savePostMetaFields'],
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
     * Register the read-only abilities (list / get) for post meta field configurations.
     *
     * @param callable $permission Permission callback
     * @return void
     * @since 1.4.1
     */
    private function registerReadAbilities(callable $permission): void
    {
        $read_meta = $this->getReadAbilityMeta();

        wp_register_ability('native-custom-fields/list-post-meta-fields', [
            'label'               => __('List Post Meta Field Configurations', 'native-custom-fields'),
            'description'         => __('Lists the post types that have a custom field configuration, with their meta box and field counts.', 'native-custom-fields'),
            'category'            => 'native-custom-fields',
            'execute_callback'    => [$this, 'listPostMetaFields'],
            'permission_callback' => $permission,
            'meta'                => $read_meta,
        ]);

        wp_register_ability('native-custom-fields/get-post-meta-fields', [
            'label'               => __('Get Post Meta Fields', 'native-custom-fields'),
            'description'         => __('Reads the custom field configuration of a post type in the same shape that save-post-meta-fields accepts, so the result can be edited and saved back. Also reports whether the builder UI state (Edit Fields screen) exists.', 'native-custom-fields'),
            'category'            => 'native-custom-fields',
            'execute_callback'    => [$this, 'getPostMetaFields'],
            'input_schema'        => [
                'type'       => 'object',
                'required'   => ['post_type'],
                'properties' => [
                    'post_type' => ['type' => 'string', 'description' => __('The post type slug', 'native-custom-fields')],
                ],
            ],
            'permission_callback' => $permission,
            'meta'                => $read_meta,
        ]);
    }

    /**
     * List Post Meta Fields Ability
     *
     * @return array Response data
     * @since 1.4.1
     */
    public function listPostMetaFields(): array
    {
        $items = [];

        foreach ($this->postMetaService->getPostMetaFieldsConfigurations() as $post_type => $config) {
            $sections    = $config['sections'] ?? [];
            $field_count = 0;
            foreach ($sections as $section) {
                $field_count += count($section['fields'] ?? []);
            }

            $items[] = [
                'post_type'      => $config['post_type'] ?? $post_type,
                'section_count'  => count($sections),
                'field_count'    => $field_count,
            ];
        }

        return ['status' => true, 'configurations' => $items];
    }

    /**
     * Get Post Meta Fields Ability
     *
     * @param array $input Input data
     * @return array Response data
     * @since 1.4.1
     */
    public function getPostMetaFields(array $input): array
    {
        $post_type = sanitize_key($input['post_type'] ?? '');

        if (empty($post_type)) {
            return ['status' => false, 'message' => __('post_type is required.', 'native-custom-fields')];
        }

        $configs = $this->postMetaService->getPostMetaFieldsConfigurations();

        if (! isset($configs[$post_type])) {
            return ['status' => false, 'message' => __('No field configuration found for this post type.', 'native-custom-fields')];
        }

        $sections = [];
        foreach ($configs[$post_type]['sections'] ?? [] as $section) {
            $sections[] = [
                'meta_box_id'       => $section['meta_box_id'] ?? '',
                'meta_box_title'    => $section['meta_box_title'] ?? '',
                'meta_box_context'  => $section['meta_box_context'] ?? 'advanced',
                'meta_box_priority' => $section['meta_box_priority'] ?? 'default',
                'fields'            => $this->extractAbilityFields($section['fields'] ?? []),
            ];
        }

        return [
            'status'        => true,
            'post_type'     => $post_type,
            'sections'      => $sections,
            'builder_state' => [
                'fields_form_present' => $this->hasBuilderState('native_custom_fields_post_meta_fields_builder_' . $post_type),
            ],
        ];
    }

    /**
     * Save Post Meta Fields Ability
     *
     * @param array $input Input data
     * @return array Response data
     * @since 1.0.1
     */
    public function savePostMetaFields(array $input): array
    {
        try {
            $post_type = sanitize_key($input['post_type'] ?? '');

            if (empty($post_type)) {
                return ['status' => false, 'message' => __('post_type is required.', 'native-custom-fields')];
            }

            $sections = $input['sections'] ?? [];

            if (empty($sections)) {
                return ['status' => false, 'message' => __('sections is required.', 'native-custom-fields')];
            }

            $menu_slug = 'native_custom_fields_post_meta_fields_builder_' . $post_type;

            $sections_or_meta_boxes = [];
            foreach ($sections as $section) {
                $sections_or_meta_boxes[] = [
                    'fieldType'                  => 'meta_box',
                    'name'                       => sanitize_key($section['meta_box_id'] ?? ''),
                    'fieldLabel'                 => sanitize_text_field($section['meta_box_title'] ?? ''),
                    'field_custom_info_meta_box' => [
                        'meta_box_context'  => sanitize_text_field($section['meta_box_context'] ?? 'advanced'),
                        'meta_box_priority' => sanitize_text_field($section['meta_box_priority'] ?? 'default'),
                    ],
                    'field_base_info'            => $this->getDefaultFieldBaseInfo(),
                    'field_dependency_info'      => $this->getDefaultDependencyInfo(),
                    'fields'                     => $this->prepareAbilityFields($section['fields'] ?? []),
                ];
            }

            $values = [
                'post_type'              => $post_type,
                'sections_or_meta_boxes' => $sections_or_meta_boxes,
            ];

            $response = $this->postMetaService->savePostMetaFieldsConfig($menu_slug, $values);

            if ($response->status) {
                $this->optionService->saveOptions($menu_slug, $values);
            }

            return ['status' => $response->status, 'message' => $response->message];
        } catch (Exception $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }
}
