<?php

/**
 * Custom Taxonomy Service for handling custom taxonomies and their meta fields
 *
 * @package NativeCustomFields
 * @subpackage Services
 */

namespace NativeCustomFields\Services;

use Exception;
use NativeCustomFields\Common\Helper;
use NativeCustomFields\Models\Common\ResponseModel;
use NativeCustomFields\Models\TermMeta\TaxonomyListItemModel;
use NativeCustomFields\Models\TermMeta\TaxonomyListResponseModel;
use NativeCustomFields\Models\TermMeta\TermMetaFieldsConfigModel;
use NativeCustomFields\Repositories\TermMetaRepository;
use NativeCustomFields\Services\Interfaces\BaseMetaServiceInterface;
use NativeCustomFields\Services\Interfaces\TermMetaServiceInterface;
use WP_Term;

defined('ABSPATH') || exit;

class TermMetaService implements BaseMetaServiceInterface, TermMetaServiceInterface
{

	/**
	 * Term meta repository
	 *
	 * @var TermMetaRepository
	 * @since 1.0.0
	 */
	private TermMetaRepository $termMetaRepository;

	public function __construct(TermMetaRepository $termMetaRepository)
	{
		//Inject dependencies
		$this->termMetaRepository = $termMetaRepository;
	}

	#region Custom Taxonomies

	/**
	 * Register taxonomies
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function registerTaxonomies(): void
	{
		$taxonomies = $this->getTaxonomyConfigurationsFiltered();

		foreach ($taxonomies as $taxonomy => $config) {
			if (taxonomy_exists($taxonomy)) {
				continue;
			}

			try {
				if (! isset($config['args']) || empty($config['object_type'])) {
					continue;
				}

				//Remove null values from args
				$arguments = array_filter($config['args'], function ($value) {
					return $value !== null && $value !== '';
				});

				// Allowed EP_MASK values
				$allowed_masks = Helper::getAllowedEpMaskList();

				if (in_array($arguments['rewrite']['ep_mask'], $allowed_masks, true) && defined($arguments['rewrite']['ep_mask'])) {
					$arguments['rewrite']['ep_mask'] = constant($arguments['rewrite']['ep_mask']);
				} else {
					$arguments['rewrite']['ep_mask'] = defined('EP_NONE') ? EP_NONE : 0;
				}

				$registered = register_taxonomy($taxonomy, $config['object_type'], $arguments);

				if (is_wp_error($registered)) {
					continue;
				}
			} catch (Exception $e) {
				// Silent failure
			}
		}
	}


	/**
	 * Get taxonomy list
	 *
	 * @return TaxonomyListResponseModel
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function getTaxonomies(): TaxonomyListResponseModel
	{

		// Get registered taxonomies
		$registered_taxonomies = get_taxonomies(['public' => true, '_builtin' => false], 'objects');

		// Taxonomies from configurations
		$configured_taxonomies = $this->getTaxonomyConfigurationsFiltered();

		// Set response model
		$result = new TaxonomyListResponseModel();

		if (! empty($registered_taxonomies)) {
			//Add taxonomy items to response model
			$i = 1;
			foreach ($registered_taxonomies as $taxonomy_slug => $taxonomy) {

				$created_by = $configured_taxonomies[$taxonomy_slug]['created_by'] ?? "external_plugin";

				$item                = new TaxonomyListItemModel();
				$item->no            = $i;
				$item->taxonomy_slug = $taxonomy_slug;
				$item->taxonomy      = $taxonomy->label;
				$item->created_by    = $created_by;

				$result->taxonomy_list[] = $item;

				$i++;
			}
		}

		return $result;
	}

	/**
	 * Get custom taxonomy configurations
	 * @return array
	 * @since 1.0.0
	 */
	public function getTaxonomyConfigurations(): array
	{
		return $this->termMetaRepository->getConfigurations('native_custom_fields_taxonomies_config');
	}

	/**
	 * Get custom taxonomy configurations with filters applied
	 * Filter applies php-based modifications to the taxonomy configurations
	 * @return array
	 * @since 1.0.0
	 */
	public function getTaxonomyConfigurationsFiltered(): array
	{
		$get_taxonomies = $this->termMetaRepository->getConfigurations('native_custom_fields_taxonomies_config');

		return apply_filters('native_custom_fields_taxonomies', $get_taxonomies);
	}

	/**
	 * Save custom taxonomy configurations comes from admin taxonomy builder form
	 *
	 * @param string $menu_slug
	 * @param array $values
	 *
	 * @return ResponseModel
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function saveCustomTaxonomyConfig(string $menu_slug, array $values): ResponseModel
	{

		$response = new ResponseModel();

		//Check if menu slug starts with 'builder'
		if (strpos($menu_slug, 'native_custom_fields_taxonomy_builder') !== 0) {
			$response->status  = false;
			$response->message = __('It is not a builder options.', 'native-custom-fields');

			return $response;
		}

		//Get values from create options page form and sanitize fields (uses sanitize_text_field for all)
		$general = Helper::sanitizeArray($values['native_custom_fields_create_taxonomy_general']);

		// Validate required fields
		if (empty($general['taxonomy']) && empty($general['label'])) {
			$response->status  = false;
			$response->message = __('Taxonomy and label are required.', 'native-custom-fields');

			return $response;
		}

		$labels       = Helper::sanitizeArray($values['native_custom_fields_create_taxonomy_labels']);
		$visibility   = Helper::sanitizeArray($values['native_custom_fields_create_taxonomy_visibility']);
		$capabilities = Helper::sanitizeArray($values['native_custom_fields_create_taxonomy_capabilities']);
		$rest_api     = Helper::sanitizeArray($values['native_custom_fields_create_taxonomy_rest_api']);
		$permalinks   = Helper::sanitizeArray($values['native_custom_fields_create_taxonomy_permalinks']);

		//Prepare capabilities settings
		$get_capabilities      = ! empty($capabilities['capabilities']) ? $capabilities['capabilities'] : [];
		$selected_capabilities = [];

		$capability_prefixes = [
			'manage_terms' => 'manage_',
			'edit_terms'   => 'edit_',
			'delete_terms' => 'delete_',
			'assign_terms' => 'assign_',
		];

		foreach ($capability_prefixes as $cap => $prefix) {
			if (in_array($cap, $get_capabilities)) {
				$selected_capabilities[$cap] = $prefix . $general['taxonomy'];
			}
		}

		//Prepare query var settings
		$query_var = rest_sanitize_boolean($general['query_var']);
		if ($query_var) {
			//Set custom slug if it has archive is true
			$query_var = (isset($general['query_var_custom_slug'])) ? $general['query_var_custom_slug'] : $general['taxonomy'];
		}

		// Prepare rewrite settings
		$rewrite = rest_sanitize_boolean($permalinks['rewrite']) === true ? [] : false;
		if (is_array($rewrite) && isset($permalinks['rewrite_settings'])) {

			// Prepare EP_MASK
			$ep_mask = isset($permalinks['rewrite_settings']['ep_mask']) ? strtoupper(trim($permalinks['rewrite_settings']['ep_mask'])) : '';

			$rewrite = [
				'slug'         => sanitize_key($permalinks['rewrite_settings']['slug']),
				'with_front'   => rest_sanitize_boolean($permalinks['rewrite_settings']['with_front']),
				'hierarchical' => rest_sanitize_boolean($permalinks['rewrite_settings']['hierarchical']),
				'ep_mask'      => $ep_mask,
			];
		}


		//Prepare default term settings
		$default_term = [];
		if (isset($general['default_term']) && $general['default_term']) {
			$default_term = [
				'name'        => $general['default_term_settings']['default_term_name'],
				'slug'        => $general['default_term_settings']['default_term_slug'],
				'description' => $general['default_term_settings']['default_term_description'],
			];
		}

		//Set taxonomy arguments
		$taxonomy_args                          = [];
		$taxonomy_args['labels']                = $labels;
		$taxonomy_args['description']           = $general['description'] ?? null;
		$taxonomy_args['query_var']             = $query_var;
		$taxonomy_args['default_term']          = $default_term;
		$taxonomy_args['public']                = rest_sanitize_boolean($visibility['public']);
		$taxonomy_args['hierarchical']          = rest_sanitize_boolean($visibility['hierarchical']);
		$taxonomy_args['publicly_queryable']    = rest_sanitize_boolean($visibility['publicly_queryable']);
		$taxonomy_args['show_ui']               = rest_sanitize_boolean($visibility['show_ui']);
		$taxonomy_args['show_in_menu']          = rest_sanitize_boolean($visibility['show_in_menu']);
		$taxonomy_args['show_tagcloud']         = rest_sanitize_boolean($visibility['show_tagcloud']);
		$taxonomy_args['show_in_quick_edit']    = rest_sanitize_boolean($visibility['show_in_quick_edit']);
		$taxonomy_args['show_in_nav_menus']     = rest_sanitize_boolean($visibility['show_in_nav_menus']);
		$taxonomy_args['show_admin_column']     = rest_sanitize_boolean($visibility['show_admin_column']);
		$taxonomy_args['capabilities']          = $selected_capabilities;
		$taxonomy_args['sort']                  = rest_sanitize_boolean($capabilities['sort']);
		$taxonomy_args['show_in_rest']          = rest_sanitize_boolean($rest_api['show_in_rest']);
		$taxonomy_args['rest_base']             = $rest_api['rest_base'] ?? '';
		$taxonomy_args['rest_controller_class'] = $rest_api['rest_controller_class'];
		$taxonomy_args['rest_namespace']        = $rest_api['rest_namespace'];
		$taxonomy_args['rewrite']               = $rewrite;


		//Set taxonomy model
		$taxonomy['taxonomy']    = $general['taxonomy'];
		$taxonomy['object_type'] = $general['object_type'];
		$taxonomy['args']        = $taxonomy_args;
		$taxonomy['created_by']  = 'native_custom_fields';

		//Get taxonomy configurations
		$get_config = $this->getTaxonomyConfigurations();

		//Set taxonomy configurations data
		$get_config[$general['taxonomy']] = $taxonomy;

		//Save taxonomy configurations (add or update)
		$save_taxonomy_config = $this->termMetaRepository->saveConfigurations($get_config, 'native_custom_fields_taxonomies_config');

		if ($save_taxonomy_config) {
			$response->message = __('Custom taxonomy data saved successfully.', 'native-custom-fields');
		} else {
			$response->message = __('No changes detected. Custom taxonomy data already up to date.', 'native-custom-fields');
		}

		return $response;
	}

	/**
	 * Delete taxonomy configuration by taxonomy slug
	 *
	 * @param string $taxonomy_slug
	 *
	 * @return ResponseModel
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function deleteTaxonomyConfigBySlug(string $taxonomy_slug): ResponseModel
	{
		//Set response model
		$result = new ResponseModel();

		//Delete taxonomy configurations
		$result->status  = $this->termMetaRepository->deleteConfigurations('native_custom_fields_taxonomies_config', $taxonomy_slug);
		$result->message = $result->status ? __('Taxonomy deleted successfully.', 'native-custom-fields') : __('Taxonomy can not be deleted.', 'native-custom-fields');

		return $result;
	}
	#endregion

	#region Taxonomy Fields
	/**
	 * Add fields into a taxonomy from builder form
	 *
	 * @param string $menu_slug
	 * @param array $values
	 *
	 * @return ResponseModel
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function saveTermMetaFieldsConfig(string $menu_slug, array $values): ResponseModel
	{

		$result = new ResponseModel();

		//Check if menu slug starts with 'builder'
		if (strpos($menu_slug, 'native_custom_fields_term_meta_fields_builder') !== 0) {
			$result->status  = false;
			$result->message = __('It is not a term meta fields builder.', 'native-custom-fields');

			return $result;
		}

		// Sanitize fields in the values (uses sanitize_text_fields)
		$values = Helper::sanitizeArray($values);

		// Get taxonomy from values
		$taxonomy_slug = $values['taxonomy'] ?? '';

		// Get sections (sections_or_meta_boxes) from values
		$sections = $values['sections_or_meta_boxes'] ?? [];

		if (empty($taxonomy_slug)) {
			$result->status  = false;
			$result->message = __('Taxonomy is required.', 'native-custom-fields');

			return $result;
		}

		$taxonomy_key = sanitize_key($taxonomy_slug);

		//Prepare fields configuration from sections
		$prepared_sections = [];
		foreach ($sections as $section) {

			$field_list = $this->prepareFieldList($section['fields'] ?? []);

			$prepared_sections[] = [
				'section_name'  => $section['name'] ?? '',
				'section_title' => $section['fieldLabel'] ?? '',
				'section_icon'  => $section['field_custom_info_section']['section_icon'] ?? '',
				'fields'        => $field_list
			];
		}

		// Prepare config as array (for database storage)
		$config_array = [
			'taxonomy' => $taxonomy_key,
			'sections' => $prepared_sections
		];

		//Get term meta fields configurations
		$get_config = $this->getTermMetaFieldsConfigurations();

		//Set taxonomy configurations data as array
		$get_config[$taxonomy_key] = $config_array;

		//Save term meta fields configurations (add or update)
		$save_term_meta_config = $this->termMetaRepository->saveConfigurations($get_config, 'native_custom_fields_term_meta_fields_config');

		if ($save_term_meta_config) {
			$result->message = __('Term meta fields data saved successfully.', 'native-custom-fields');
		} else {
			$result->message = __('No changes detected. Term meta fields data already up to date.', 'native-custom-fields');
		}

		return $result;
	}

	/**
	 * Get term meta fields configurations
	 * @return array
	 * @since 1.0.0
	 */
	public function getTermMetaFieldsConfigurations(): array
	{
		//Get saved configurations from the database
		return $this->termMetaRepository->getConfigurations('native_custom_fields_term_meta_fields_config');
	}

	/**
	 * Get term meta fields configurations with filters applied
	 * Filter applies php-based modifications to the term meta fields configurations
	 * @return array
	 * @since 1.0.0
	 */
	public function getTermMetaFieldsConfigurationsFiltered(): array
	{
		//Get saved configurations from the database
		$get_term_meta_fields = $this->termMetaRepository->getConfigurations('native_custom_fields_term_meta_fields_config');

		return apply_filters('native_custom_fields_term_meta_fields', $get_term_meta_fields);
	}

	/**
	 * Register all post meta fields
	 *
	 * @param string $taxonomy
	 * @param array|string $object_type
	 *
	 * @return void
	 * @throws Exception
	 */
	public function registerAllTermMeta(string $taxonomy, $object_type): void
	{

		if (! taxonomy_exists($taxonomy) || $taxonomy === '') {
			return;
		}

		$term_meta_fields_config = $this->getTermMetaFieldsConfigurationsFiltered();
		if (empty($term_meta_fields_config[$taxonomy])) {
			return;
		}

		$config = $term_meta_fields_config[$taxonomy];

		$sections = Helper::getSectionsFromConfig($config);

		// Collect all fields from all sections
		$fields = [];
		if (! empty($sections)) {
			foreach ($sections as $section) {
				if (isset($section['fields']) && is_array($section['fields'])) {
					$fields = array_merge($fields, $section['fields']);
				}
			}
		}

		if (empty($fields)) {
			return;
		}

		$this->registerFieldsForTaxonomy($taxonomy, $fields);
	}

	/**
	 * Add and edit form fields into a custom taxonomy
	 *
	 * @param string $taxonomy
	 * @param array|string $object_type
	 *
	 * @return void
	 * @throws Exception
	 */
	public function addFormFieldsToTaxonomy(string $taxonomy, $object_type): void
	{
		add_action("{$taxonomy}_add_form_fields", [$this, 'addTermMetaFormFields']);
		add_action("{$taxonomy}_edit_form_fields", [$this, 'addTermMetaEditFormFields'], 10, 2);
	}

	/**
	 * Add, edit and save hooks for a custom taxonomy
	 *
	 * @param string $taxonomy
	 * @param array|string $object_type
	 *
	 * @return void
	 * @throws Exception
	 */
	public function editOrSaveHooksForTaxonomy(string $taxonomy, $object_type): void
	{
		add_action("created_{$taxonomy}", [$this, 'saveTermMeta']);
		add_action("edited_{$taxonomy}", [$this, 'saveTermMeta']);
	}


	/**
	 * Register fields for a custom taxonomy
	 *
	 * @param string $taxonomy
	 * @param array $fields
	 *
	 * @return void
	 * @since 1.0.0
	 *
	 */
	private function registerFieldsForTaxonomy(string $taxonomy, array $fields): void
	{
		foreach ($fields as $field) {

			$meta_key = $field['name'];
			$field_type = $field['fieldType'];

			if (empty($meta_key) || ! is_string($meta_key)) {
				continue;
			}

			if ($field_type === 'section' || $field_type === 'meta_box') {
				return;
			}

			// Modify term meta type (for example, convert string to number)
			$get_type = apply_filters('native_custom_fields_register_term_meta_type', $meta_key, $taxonomy);

			$get_type = Helper::normalizeMetaType($get_type);

			$args = [
				'type'         => $get_type,
				'single'       => true,
				'show_in_rest' => true,
			];

			// Modify term meta args
			$args = apply_filters('native_custom_fields_register_term_meta_args', $args, $meta_key, $taxonomy);

			register_term_meta($taxonomy, $meta_key, $args);
		}
	}

	/**
	 * Add term meta fields into the "Add New Term" form
	 *
	 * @param string $taxonomy Taxonomy name
	 *
	 * @return void
	 * @throws Exception
	 */
	public function addTermMetaFormFields(string $taxonomy): void
	{

		$term_meta_fields_config = $this->getTermMetaFieldsConfigurationsFiltered();

		if (empty($term_meta_fields_config) || ! array_key_exists($taxonomy, $term_meta_fields_config)) {
			return;
		}

		$config = $term_meta_fields_config[$taxonomy];

		$sections = Helper::getSectionsFromConfig($config);

		if (empty($sections)) {
			return;
		}

		wp_nonce_field('native_custom_fields_term_meta_nonce', 'native_custom_fields_term_meta_nonce');

		$allowed_html = Helper::getAllowedHiddenInputHtml();

		// Render each section separately
		foreach ($sections as $section) {
			$section_title = $section['section_title'] ?? __('Custom Fields', 'native-custom-fields');
			$section_icon  = $section['section_icon'] ?? '';
			$fields        = $section['fields'] ?? [];

			if (empty($fields)) {
				continue;
			}

			// Section header
			echo '<div class="form-field term-meta-section-wrap">';

			if (! empty($section_icon)) {
				echo '<h3><span class="dashicons dashicons-' . esc_attr($section_icon) . '"></span> <span class="native-custom-fields-meta-th-header">' . esc_html($section_title) . '</span></h3>';
			} else {
				echo '<h3>' . esc_html($section_title) . '</h3>';
			}

			// Hidden fields
			$hidden_fields_html = '';
			foreach ($fields as $field) {
				if (! isset($field['name'])) {
					continue;
				}

				//Skip fields that already have input tag
				if (in_array($field['fieldType'] ?? '', Helper::fieldsAlreadyHaveInput(), true)) {
					continue;
				}

				$hidden_fields_html .= '<input type="hidden" name="' . esc_attr($field['name']) . '" value="">';
			}

			// React wrapper for this section
			echo '<div class="native-custom-fields-term-meta-wrapper" data-fields="' . esc_attr(wp_json_encode($fields)) . '" data-values="' . esc_attr(wp_json_encode($this->getFieldValues($fields))) . '"></div>';
			echo wp_kses($hidden_fields_html, $allowed_html);
			echo '</div>';
		}
	}

	/**
	 * Get field values for a taxonomy
	 *
	 * @param array $fields Fields configuration
	 * @param int $id Term ID
	 *
	 * @return array Field values
	 */
	public function getFieldValues(array $fields, int $id = 0): array
	{
		$values = [];
		foreach ($fields as $field) {
			if (! isset($field['name'])) {
				continue;
			}

			$get_default_value = $field['default'] ?? '';

			if ($id === 0) {
				$values[$field['name']] = $get_default_value;
			} else {
				$get_value = $this->termMetaRepository->getTermMeta($field['name'], $id);
				if (empty($get_value)) {
					$values[$field['name']] = $get_default_value;
				} else {
					$values[$field['name']] = $get_value;
				}
			}
		}

		return $values;
	}

	/**
	 * Add term meta fields into edit term form
	 *
	 * @param WP_Term $term Term object
	 * @param string $taxonomy Taxonomy name
	 *
	 * @return void
	 * @throws Exception
	 */
	public function addTermMetaEditFormFields(WP_Term $term, string $taxonomy): void
	{
		$term_meta_fields_config = $this->getTermMetaFieldsConfigurationsFiltered();
		if (empty($term_meta_fields_config[$taxonomy])) {
			return;
		}

		$config = $term_meta_fields_config[$taxonomy];

		// Get sections from config (already in array format)
		$sections = Helper::getSectionsFromConfig($config);

		if (empty($sections)) {
			return;
		}

		wp_nonce_field('native_custom_fields_term_meta_nonce', 'native_custom_fields_term_meta_nonce');

		$allowed_html = Helper::getAllowedHiddenInputHtml();

		// Render each section separately
		foreach ($sections as $section) {
			$section_title = $section['section_title'] ?? __('Custom Fields', 'native-custom-fields');
			$section_icon  = $section['section_icon'] ?? '';
			$fields        = $section['fields'] ?? [];

			if (empty($fields)) {
				continue;
			}

			// Hidden fields
			$hidden_fields_html = '';
			foreach ($fields as $field) {
				if (empty($field['name'])) {
					continue;
				}

				$value = $this->termMetaRepository->getTermMeta($field['name'], $term->term_id);

				// Convert array values to JSON string
				if (is_array($value)) {
					$value = wp_json_encode($value);
				}

				//Skip fields that already have input tag
				if (in_array($field['fieldType'] ?? '', Helper::fieldsAlreadyHaveInput(), true)) {
					continue;
				}

				$hidden_fields_html .= '<input type="hidden" name="' . esc_attr($field['name']) . '" value="' . esc_attr((string) $value) . '">';
			}

			// Section title with icon
			$section_header = esc_html($section_title);
			if (! empty($section_icon)) {
				$section_header = '<span class="dashicons dashicons-' . esc_attr($section_icon) . '"></span> <span class="native-custom-fields-meta-th-header">' . esc_html($section_title) . '</span>';
			}

			$html = '<tr class="form-field term-meta-wrap term-meta-section-wrap">';
			$html .= '<th scope="row">%s</th>';
			$html .= '<td>';
			$html .= '<div class="native-custom-fields-term-meta-wrapper" data-fields="%s" data-values="%s"></div>';
			$html .= '%s';
			$html .= '</td>';
			$html .= '</tr>';

			echo sprintf(
				wp_kses_post($html),
				$section_header, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $section_header is already escaped via esc_html() and esc_attr() above.
				esc_attr(wp_json_encode($fields)),
				esc_attr(wp_json_encode($this->getFieldValues($fields, $term->term_id))),
				wp_kses($hidden_fields_html, $allowed_html)
			);
		}
	}

	/**
	 * Save term meta
	 *
	 * @param int $term_id Term ID
	 *
	 * @return void
	 * @throws Exception
	 */
	public function saveTermMeta(int $term_id): void
	{
		if (
			! isset($_POST['native_custom_fields_term_meta_nonce']) ||
			! wp_verify_nonce(sanitize_key(wp_unslash($_POST['native_custom_fields_term_meta_nonce'])), 'native_custom_fields_term_meta_nonce')
		) {
			return;
		}

		if (! current_user_can('manage_categories')) {
			return;
		}

		$term = $this->termMetaRepository->getTerm($term_id);

		if (! $term || is_wp_error($term)) {
			return;
		}

		$taxonomy = $term->taxonomy;

		$term_meta_fields_config = $this->getTermMetaFieldsConfigurationsFiltered();
		if (empty($term_meta_fields_config[$taxonomy])) {
			return;
		}

		$config = $term_meta_fields_config[$taxonomy];

		$sections = Helper::getSectionsFromConfig($config);

		// Collect all fields from all sections
		$fields = [];
		if (! empty($sections)) {
			foreach ($sections as $section) {
				if (isset($section['fields']) && is_array($section['fields'])) {
					$fields = array_merge($fields, $section['fields']);
				}
			}
		}

		if (empty($fields)) {
			return;
		}

		foreach ($fields as $field) {

			$meta_key   = $field['name'];

			if (! isset($meta_key)) {
				continue;
			}

			$meta_value = Helper::sanitize($meta_key, 'post');

			// Always save the value, even if it's empty (important for checkboxes and radios)
			$value = wp_unslash($meta_value);

			// Try to decode JSON if the value is a JSON string
			if (Helper::isJson($value)) {
				$value = json_decode($value, true);
			}

			// Check if the value is an array and sanitize it
			if (is_array($value)) {
				$value = Helper::sanitizeArray($value);
			} else {
				$value = sanitize_text_field($value);
			}

			// Save the value to the database
			$this->termMetaRepository->saveTermMeta($term_id, $meta_key, $value);
		}
	}

	/**
	 * Prepare field list with recursive handling for group and repeater fields
	 *
	 * @param array $fields
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function prepareFieldList(array $fields): array
	{
		$field_list = [];

		foreach ($fields as $field) {

			// Get field data from option groups
			$field_base_info = $field['field_base_info'] ?? [];

			//Get custom field data by field type
			$field_custom_info = [];
			if (! empty($field['fieldType'])) {
				$custom_key = 'field_custom_info_' . $field['fieldType'];
				if (isset($field[$custom_key])) {
					$field_custom_info = $field[$custom_key];
				}

				// Recursive handling for group and repeater fields
				// Sub-fields are in $field['fields'], not in $field_custom_info['fields']
				if (($field['fieldType'] === 'group' || $field['fieldType'] === 'repeater') && ! empty($field['fields'])) {
					$field_custom_info['fields'] = $this->prepareFieldList($field['fields']);

					// For repeater fields with table layout, hide labels and tags of inner fields
					if ($field['fieldType'] === 'repeater' && isset($field_custom_info['layout']) && $field_custom_info['layout'] === 'table') {
						$field_custom_info['hideRepeaterItemTag'] = true;
						foreach ($field_custom_info['fields'] as &$item) {
							$item['hideLabel'] = true;
						}
						unset($item);
					}
				}
			}

			// Condition to set default value for date and date time picker fields
			if (($field['fieldType'] === 'date_picker' || $field['fieldType'] === 'date_time_picker')) {
				$field['default'] = $field_custom_info['currentDate'] ?? null;
			}

			//Merge and set field data
			$field_info = array_merge(
				[
					"fieldType"  => $field['fieldType'],
					"name"       => $field['name'],
					"fieldLabel" => $field['fieldLabel'],
					"default"    => $field['default'] ?? null,
				],
				$field_base_info,
				$field_custom_info,
				['dependencies' => $field['field_dependency_info'] ?? []]
			);

			$field_list[] = $field_info;
		}

		return $field_list;
	}

	#endregion

}
