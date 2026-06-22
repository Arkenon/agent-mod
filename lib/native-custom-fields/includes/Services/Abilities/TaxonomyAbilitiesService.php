<?php

/**
 * Taxonomy Abilities Service
 * Registers WP Abilities API abilities for creating, updating, and deleting custom taxonomies.
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

class TaxonomyAbilitiesService
{
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
        $taxonomy_schema = [
            'type'       => 'object',
            'required'   => ['taxonomy', 'label', 'object_type'],
            'properties' => [
                'taxonomy'         => ['type' => 'string', 'description' => __('Taxonomy slug (lowercase, max 32 chars)', 'native-custom-fields')],
                'label'            => ['type' => 'string', 'description' => __('Plural label', 'native-custom-fields')],
                'singular_name'    => ['type' => 'string', 'description' => __('Singular label (defaults to label if not set)', 'native-custom-fields')],
                'object_type'      => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => __('Post types to attach this taxonomy to, e.g. ["post","page"]', 'native-custom-fields')],
                'description'      => ['type' => 'string'],
                'public'           => ['type' => 'boolean', 'default' => true],
                'hierarchical'     => ['type' => 'boolean', 'default' => true],
                'show_admin_column' => ['type' => 'boolean', 'default' => false, 'description' => __('Whether to display a column for the taxonomy on its post type listing screens', 'native-custom-fields')],
                'show_in_rest'     => ['type' => 'boolean', 'default' => true],
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

        wp_register_ability('native-custom-fields/create-taxonomy', [
            'label'               => __('Create Taxonomy', 'native-custom-fields'),
            'description'         => __('Creates a new custom taxonomy and saves its configuration.', 'native-custom-fields'),
            'category'            => 'native-custom-fields',
            'execute_callback'    => [$this, 'saveTaxonomy'],
            'input_schema'        => $taxonomy_schema,
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

        wp_register_ability('native-custom-fields/update-taxonomy', [
            'label'               => __('Update Taxonomy', 'native-custom-fields'),
            'description'         => __('Updates the configuration of an existing custom taxonomy.', 'native-custom-fields'),
            'category'            => 'native-custom-fields',
            'execute_callback'    => [$this, 'saveTaxonomy'],
            'input_schema'        => $taxonomy_schema,
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
     * Save Taxonomy Ability
     *
     * @param array $input Input data
     * @return array Response data
     * @since 1.0.1
     */
    public function saveTaxonomy(array $input): array
    {
        try {
            $taxonomy = sanitize_key($input['taxonomy'] ?? '');

            if (empty($taxonomy)) {
                return ['status' => false, 'message' => __('taxonomy is required.', 'native-custom-fields')];
            }

            $label = sanitize_text_field($input['label'] ?? '');

            if (empty($label)) {
                return ['status' => false, 'message' => __('label is required.', 'native-custom-fields')];
            }

            $object_type = $input['object_type'] ?? [];
            if (empty($object_type)) {
                return ['status' => false, 'message' => __('object_type is required.', 'native-custom-fields')];
            }

            $menu_slug = 'native_custom_fields_taxonomy_builder_' . $taxonomy;

            // Prepare labels array
            $plural         = $label;
            $singular       = $input['singular_name'] ?? $label;
            $plural_lower   = strtolower($plural);
            $singular_lower = strtolower($singular);

            $labels_data = [
                'menu_name'                  => $plural,
                'name_admin_bar'             => $singular,
                /* translators: %s: singular taxonomy name */
                'add_new_item'               => sprintf(__('Add New %s', 'native-custom-fields'), $singular),
                /* translators: %s: singular taxonomy name */
                'new_item_name'              => sprintf(__('New %s Name', 'native-custom-fields'), $singular),
                /* translators: %s: singular taxonomy name */
                'template_name'              => sprintf(__('%s Template Name', 'native-custom-fields'), $singular),
                /* translators: %s: plural taxonomy name (lowercase) */
                'separate_items_with_commas' => sprintf(__('Separate %s with commas', 'native-custom-fields'), $plural_lower),
                /* translators: %s: plural taxonomy name (lowercase) */
                'add_or_remove_items'        => sprintf(__('Add or remove %s', 'native-custom-fields'), $plural_lower),
                'most_used'                  => __('Most Used', 'native-custom-fields'),
                /* translators: %s: plural taxonomy name (lowercase) */
                'choose_from_most_used'      => sprintf(__('Choose from the most used %s', 'native-custom-fields'), $plural_lower),
                /* translators: %s: plural taxonomy name */
                'back_to_items'              => sprintf(__('← Back to %s', 'native-custom-fields'), $plural),
                /* translators: %s: singular taxonomy name */
                'item_link'                  => sprintf(__('%s Link', 'native-custom-fields'), $singular),
                /* translators: %s: singular taxonomy name (lowercase) */
                'item_link_description'      => sprintf(__('A link to a %s', 'native-custom-fields'), $singular_lower),
                /* translators: %s: plural taxonomy name */
                'all_items'                  => sprintf(__('All %s', 'native-custom-fields'), $plural),
                /* translators: %s: singular taxonomy name */
                'view_item'                  => sprintf(__('View %s', 'native-custom-fields'), $singular),
                /* translators: %s: singular taxonomy name */
                'update_item'                => sprintf(__('Update %s', 'native-custom-fields'), $singular),
                /* translators: %s: plural taxonomy name */
                'search_items'               => sprintf(__('Search %s', 'native-custom-fields'), $plural),
                /* translators: %s: plural taxonomy name */
                'popular_items'              => sprintf(__('Popular %s', 'native-custom-fields'), $plural),
                /* translators: %s: singular taxonomy name */
                'parent_item'                => sprintf(__('Parent %s', 'native-custom-fields'), $singular),
                /* translators: %s: singular taxonomy name */
                'parent_item_colon'          => sprintf(__('Parent %s:', 'native-custom-fields'), $singular),
                /* translators: %s: plural taxonomy name (lowercase) */
                'not_found'                  => sprintf(__('No %s found', 'native-custom-fields'), $plural_lower),
                /* translators: %s: plural taxonomy name (lowercase) */
                'no_terms'                   => sprintf(__('No %s', 'native-custom-fields'), $plural_lower),
                /* translators: %s: singular taxonomy name (lowercase) */
                'filter_by_item'             => sprintf(__('Filter by %s', 'native-custom-fields'), $singular_lower),
                /* translators: %s: plural taxonomy name */
                'items_list_navigation'      => sprintf(__('%s list navigation', 'native-custom-fields'), $plural),
                /* translators: %s: plural taxonomy name */
                'items_list'                 => sprintf(__('%s list', 'native-custom-fields'), $plural),
            ];

            $values = [
                'native_custom_fields_create_taxonomy_general' => [
                    'taxonomy'               => $taxonomy,
                    'label'                  => $label,
                    'singular_name'          => sanitize_text_field($input['singular_name'] ?? $label),
                    'description'            => sanitize_text_field($input['description'] ?? ''),
                    'object_type'            => array_map('sanitize_key', (array) $object_type),
                    'query_var'              => true,
                    'query_var_custom_slug'  => $taxonomy,
                    'default_term'           => false,
                    'default_term_settings'  => [
                        'default_term_name'        => '',
                        'default_term_slug'        => '',
                        'default_term_description' => '',
                    ],
                ],
                'native_custom_fields_create_taxonomy_labels'       => $labels_data,
                'native_custom_fields_create_taxonomy_visibility'   => [
                    'public'              => $input['public'] ?? true,
                    'hierarchical'        => $input['hierarchical'] ?? true,
                    'publicly_queryable'  => true,
                    'show_ui'             => true,
                    'show_in_menu'        => true,
                    'show_tagcloud'       => true,
                    'show_in_quick_edit'  => true,
                    'show_in_nav_menus'   => true,
                    'show_admin_column'   => $input['show_admin_column'] ?? false,
                ],
                'native_custom_fields_create_taxonomy_capabilities' => [
                    'capabilities' => [],
                    'sort'         => false,
                ],
                'native_custom_fields_create_taxonomy_rest_api' => [
                    'show_in_rest'            => $input['show_in_rest'] ?? true,
                    'rest_base'               => '',
                    'rest_controller_class'   => 'WP_REST_Terms_Controller',
                    'rest_namespace'          => 'wp/v2',
                ],
                'native_custom_fields_create_taxonomy_permalinks' => [
                    'rewrite'          => true,
                    'rewrite_settings' => [
                        'slug'         => $taxonomy,
                        'with_front'   => true,
                        'hierarchical' => $input['hierarchical'] ?? true,
                        'ep_mask'      => 'EP_NONE',
                    ],
                ],
            ];

            $response = $this->termMetaService->saveCustomTaxonomyConfig($menu_slug, $values);

            if ($response->status) {
                $this->optionService->saveOptions($menu_slug, $values);
            }

            return ['status' => $response->status, 'message' => $response->message];
        } catch (Exception $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }
}
