<?php

/**
 * Post Meta Service for handling custom post types and fields
 *
 * @package NativeCustomFields
 * @subpackage Services
 */

namespace NativeCustomFields\Services;

use Exception;
use NativeCustomFields\Common\Helper;
use NativeCustomFields\Models\Common\ResponseModel;
use NativeCustomFields\Models\PostMeta\PostMetaFieldsConfigModel;
use NativeCustomFields\Models\PostMeta\PostMetaFieldsConfigResponseModel;
use NativeCustomFields\Models\PostMeta\PostTypeListItemModel;
use NativeCustomFields\Models\PostMeta\PostTypeListResponseModel;
use NativeCustomFields\Repositories\PostMetaRepository;
use NativeCustomFields\Services\Interfaces\BaseMetaServiceInterface;
use NativeCustomFields\Services\Interfaces\PostMetaServiceInterface;
use WP_Post;
use WP_Post_Type;

defined('ABSPATH') || exit;

class PostMetaService implements BaseMetaServiceInterface, PostMetaServiceInterface
{

	/**
	 * Post meta repository
	 *
	 * @var PostMetaRepository
	 * @since 1.0.0
	 */
	private PostMetaRepository $postMetaRepository;

	public function __construct(PostMetaRepository $postMetaRepository)
	{
		//Inject dependencies
		$this->postMetaRepository = $postMetaRepository;
	}

	#region Post Types

	/**
	 * Register post types
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function registerPostTypes(): void
	{

		// Get post types configurations
		$post_types = $this->getPostTypesConfigurationsFiltered();

		//Check if post types are empty
		if (empty($post_types)) {
			return;
		}

		// Register post types
		foreach ($post_types as $post_type => $config) {
			if (! post_type_exists($post_type)) {
				try {
					if (! isset($config['args'])) {
						continue;
					}

					//Remove null values from args
					$arguments = array_filter($config['args'], function ($value) {
						return $value !== null && $value !== '';
					});

					$allowed_masks = Helper::getAllowedEpMaskList();

					if (in_array($arguments['rewrite']['ep_mask'], $allowed_masks, true) && defined($arguments['rewrite']['ep_mask'])) {
						$arguments['rewrite']['ep_mask'] = constant($arguments['rewrite']['ep_mask']);
					} else {
						$arguments['rewrite']['ep_mask'] = defined('EP_PERMALINK') ? EP_PERMALINK : 0;
					}

					$registered = register_post_type($post_type, $arguments);

					if (is_wp_error($registered)) {
						continue;
					}
				} catch (Exception $e) {
					// Silent failure
				}
			}
		}

		// Flush rewrite rules
		flush_rewrite_rules(false);
	}

	/**
	 * Get post types list
	 * Both set by PHP array and admin create post types form
	 *
	 * @return PostTypeListResponseModel
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function getPostTypes(): PostTypeListResponseModel
	{
		// Get registered post types
		$registered_post_types = get_post_types(['public' => true, '_builtin' => false], 'objects');

		// Post types from configurations
		$configured_post_types = $this->getPostTypesConfigurationsFiltered();

		// Set response model
		$result = new PostTypeListResponseModel();

		if (! empty($registered_post_types)) {
			//Add post type items to response model
			$i = 1;
			foreach ($registered_post_types as $post_type_slug => $post_type) {

				$created_by = $configured_post_types[$post_type_slug]['created_by'] ?? "external_plugin";

				$item                 = new PostTypeListItemModel();
				$item->no             = $i;
				$item->post_type_slug = $post_type_slug;
				$item->post_type      = $post_type->label;
				$item->created_by     = $created_by;

				$result->post_type_list[] = $item;

				$i++;
			}
		}

		return $result;
	}

	/**
	 * Get configurations of post types
	 * @return array
	 * @since 1.0.0
	 */
	public function getPostTypesConfigurations(): array
	{
		return $this->postMetaRepository->getConfigurations('native_custom_fields_post_types_config');
	}

	/**
	 * Get configurations of post types with applied filters
	 * Filter applies php-based modifications to the post type configurations
	 * @return array
	 * @since 1.0.0
	 */
	public function getPostTypesConfigurationsFiltered(): array
	{
		$post_types = $this->postMetaRepository->getConfigurations('native_custom_fields_post_types_config');

		// Apply filter to allow modification of post type list
		return apply_filters('native_custom_fields_post_types', $post_types);
	}

	/**
	 * Register post type via admin post type builder form
	 *
	 * @param string $menu_slug
	 * @param array $values
	 *
	 * @return ResponseModel
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function savePostTypeConfig(string $menu_slug, array $values): ResponseModel
	{

		//Set response model
		$result = new ResponseModel();

		//Check if menu slug starts with 'builder'
		if (strpos($menu_slug, 'native_custom_fields_post_type_builder') !== 0) {
			$result->status  = false;
			$result->message = __('It is not a post type builder.', 'native-custom-fields');

			return $result;
		}


		//Get values from create options page form and sanitize fields (uses sanitize_text_field for all)
		$general = Helper::sanitizeArray($values['native_custom_fields_create_post_type_general']);

		// Validate required fields
		if (empty($general['post_type']) && empty($general['label'])) {
			$result->status  = false;
			$result->message = __('Post type and label are required.', 'native-custom-fields');

			return $result;
		}

		// Sanitize text fields in all arrays
		$labels       = Helper::sanitizeArray($values['native_custom_fields_create_post_type_labels']);
		$visibility   = Helper::sanitizeArray($values['native_custom_fields_create_post_type_visibility']);
		$capabilities = Helper::sanitizeArray($values['native_custom_fields_create_post_type_capabilities']);
		$rest_api     = Helper::sanitizeArray($values['native_custom_fields_create_post_type_rest_api']);
		$permalinks   = Helper::sanitizeArray($values['native_custom_fields_create_post_type_permalinks']);
		$template     = Helper::sanitizeArray($values['native_custom_fields_create_post_type_template']);

		//Prepare has archive settings
		$has_archive = rest_sanitize_boolean($general['has_archive']) ?? false;
		if ($has_archive) {
			//Set custom slug if it has archive is true and custom slug is set
			if ($general['has_archive_custom_slug'] !== null) {
				$has_archive = $general['has_archive_custom_slug'];
			}
		}

		//Prepare query var settings
		$query_var = rest_sanitize_boolean($general['query_var']);
		if ($query_var) {
			//Set custom slug if it has archive is true
			$query_var = (isset($general['query_var_custom_slug'])) ? $general['query_var_custom_slug'] : $general['post_type'];
		}

		// Prepare rewrite settings
		$rewrite            = rest_sanitize_boolean($permalinks['rewrite']) === true ? [] : false;
		$permalink_settings = $permalinks['rewrite_settings'];
		if (is_array($rewrite) && isset($permalink_settings)) {
			$slug       = sanitize_key($permalink_settings['slug']);
			$with_front = rest_sanitize_boolean($permalink_settings['with_front']);
			$feeds      = rest_sanitize_boolean($permalink_settings['feeds']);
			$pages      = rest_sanitize_boolean($permalink_settings['pages']);

			// Prepare EP_MASK
			$ep_mask = isset($permalink_settings['ep_mask']) ? strtoupper(trim($permalink_settings['ep_mask'])) : '';

			$rewrite = [
				'slug'       => $slug ?: (is_string($has_archive) ? $has_archive : $general['post_type']),
				'with_front' => $with_front,
				'feeds'      => $feeds,
				'pages'      => $pages,
				'ep_mask'    => $ep_mask,
			];
		}

		//Prepare capability type data
		$capability_type = 'post';
		if (isset($capabilities['capability_type'])) {
			$raw = $capabilities['capability_type'];

			if (is_array($raw)) {
				$raw             = array_filter($raw);
				$raw             = array_values($raw);
				$capability_type = count($raw) > 1 ? $raw : $raw[0];
			} else {
				$capability_type = $raw;
			}
		}

		// Prepare template configuration
		$template_data = [
			'template_blocks' => [],
			'template_lock'   => false,
		];
		if (isset($template['template']) && $template['template'] && isset($template['template_config'])) {

			$get_template_blocks = $template['template_config']['template_blocks'];

			//Decode JSON template blocks
			if (is_string($get_template_blocks) && Helper::isJson($get_template_blocks)) {
				$get_template_blocks = json_decode($get_template_blocks, true);
			}

			$template_data = [
				'template_blocks' => $get_template_blocks ?? [],
				'template_lock'   => rest_sanitize_boolean($template['template_config']['template_lock']) ?? false,
			];
		}

		$post_type              = [];
		$post_type['post_type'] = $general['post_type'];

		$post_type_args                                    = [];
		$post_type_args['label']                           = $general['label'];
		$post_type_args['description']                     = $general['description'] ?? null;
		$post_type_args['public']                          = rest_sanitize_boolean($visibility['public']);
		$post_type_args['hierarchical']                    = rest_sanitize_boolean($visibility['hierarchical']);
		$post_type_args['exclude_from_search']             = rest_sanitize_boolean($visibility['exclude_from_search']);
		$post_type_args['publicly_queryable']              = rest_sanitize_boolean($visibility['publicly_queryable']);
		$post_type_args['show_ui']                         = rest_sanitize_boolean($visibility['show_ui']);
		$post_type_args['show_in_menu']                    = rest_sanitize_boolean($visibility['show_in_menu']);
		$post_type_args['menu_position']                   = (isset($general['menu_position']) && intval($general['menu_position']) > 0) ? intval($general['menu_position']) : null;
		$post_type_args['menu_icon']                       = $general['menu_icon'] ?? '';
		$post_type_args['show_in_admin_bar']               = rest_sanitize_boolean($visibility['show_in_admin_bar']);
		$post_type_args['show_in_nav_menus']               = rest_sanitize_boolean($visibility['show_in_nav_menus']);
		$post_type_args['show_in_rest']                    = rest_sanitize_boolean($rest_api['show_in_rest']);
		$post_type_args['rest_base']                       = $rest_api['rest_base'] ?? '';
		$post_type_args['rest_controller_class']           = $rest_api['rest_controller_class'];
		$post_type_args['rest_namespace']                  = $rest_api['rest_namespace'];
		$post_type_args['autosave_rest_controller_class']  = $rest_api['autosave_rest_controller_class'];
		$post_type_args['revisions_rest_controller_class'] = $rest_api['revisions_rest_controller_class'];
		$post_type_args['has_archive']                     = $has_archive;
		$post_type_args['supports']                        = is_array($general['supports']) ? $general['supports'] : [];
		$post_type_args['taxonomies']                      = is_array($general['taxonomies']) ? $general['taxonomies'] : [];
		$post_type_args['capability_type']                 = $capability_type;
		$post_type_args['map_meta_cap']                    = rest_sanitize_boolean($general['map_meta_cap']);
		$post_type_args['rewrite']                         = $rewrite;
		$post_type_args['query_var']                       = $query_var;
		$post_type_args['can_export']                      = rest_sanitize_boolean($capabilities['can_export']);
		$post_type_args['delete_with_user']                = rest_sanitize_boolean($capabilities['delete_with_user']);
		$post_type_args['template']                        = $template_data['template_blocks'];
		$post_type_args['template_lock']                   = rest_sanitize_boolean($template_data['template_lock']);
		$post_type_args['labels']                          = $labels;

		$post_type['args']       = $post_type_args;
		$post_type['created_by'] = 'native_custom_fields';

		//Get post types configurations
		$get_config = $this->getPostTypesConfigurations();

		//Set post type configurations data
		$get_config[$general['post_type']] = $post_type;

		//Save post types configurations (add or update)
		$save_post_type_config = $this->postMetaRepository->saveConfigurations($get_config, 'native_custom_fields_post_types_config');

		if ($save_post_type_config) {
			$result->message = __('Post type data saved successfully.', 'native-custom-fields');
		} else {
			$result->message = __('No changes detected. Post type data already up to date.', 'native-custom-fields');
		}

		return $result;
	}

	/**
	 * Delete post type configuration by post type slug,
	 * To delete post type configurations created via admin post type builder form
	 *
	 * @param string $post_type_slug
	 *
	 * @return ResponseModel
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function deletePostTypeConfigBySlug(string $post_type_slug): ResponseModel
	{

		//Set response model
		$result = new ResponseModel();

		//Delete post type configurations
		$result->status  = $this->postMetaRepository->deleteConfigurations('native_custom_fields_post_types_config', $post_type_slug);
		$result->message = $result->status ? __('Post type deleted successfully.', 'native-custom-fields') : __('Post type can not be deleted.', 'native-custom-fields');

		return $result;
	}

	#endregion

	#region Custom Fields and Meta Boxes
	/**
	 * Get post meta fields configurations
	 * @return array
	 * @since 1.0.0
	 */
	public function getPostMetaFieldsConfigurations(): array
	{
		return $this->postMetaRepository->getConfigurations('native_custom_fields_post_meta_fields_config');
	}

	/**
	 * Get post meta fields configurations with applied filters
	 * Filter applies PHP-based modifications to the post meta fields configurations
	 * @return array
	 * @since 1.0.0
	 */
	public function getPostMetaFieldsConfigurationsFiltered(): array
	{
		$post_meta_fields = $this->postMetaRepository->getConfigurations('native_custom_fields_post_meta_fields_config');

		return apply_filters('native_custom_fields_post_meta_fields', $post_meta_fields);
	}

	/**
	 * Add meta boxes for post types
	 *
	 * @return void
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function addMetaBoxes(): void
	{
		$post_meta_fields_config = $this->getPostMetaFieldsConfigurationsFiltered();

		if (empty($post_meta_fields_config)) {
			return;
		}

		foreach ($post_meta_fields_config as $post_type => $config) {

			// Get meta boxes (sections) from configuration
			$meta_boxes = Helper::getSectionsFromConfig($config);

			foreach ($meta_boxes as $meta_box) {

				// Extract meta box data from array
				$meta_box_id       = $meta_box['meta_box_id'] ?? '';
				$meta_box_title    = $meta_box['meta_box_title'] ?? '';
				$meta_box_context  = $meta_box['meta_box_context'] ?? 'advanced';
				$meta_box_priority = $meta_box['meta_box_priority'] ?? 'default';
				$fields            = $meta_box['fields'] ?? [];

				// Add meta box
				add_meta_box(
					$meta_box_id,
					$meta_box_title,
					[$this, 'renderMetaBox'], //Always use this callback
					$post_type,
					$meta_box_context,
					$meta_box_priority,
					[
						'fields' => $fields, // Set fields configurations as a callback argument
					]
				);
			}
		}
	}

	/**
	 * Render meta box
	 *
	 * @param WP_Post $post Post object
	 * @param array $meta_box Meta box arguments
	 *
	 * @return void
	 * @throws Exception
	 */
	public function renderMetaBox(WP_Post $post, array $meta_box): void
	{

		// Get fields from meta box args (already in array format)
		$fields_sections = $meta_box['args']['fields'];

		// Get fields using sections helper
		$fields = Helper::getSectionsFromConfig(['sections' => $fields_sections]);

		// Add nonce for security
		wp_nonce_field('native_custom_fields_post_meta_nonce', 'native_custom_fields_post_meta_nonce');

		$hidden_fields_html = '';
		foreach ($fields as $field) {
			$value = $this->postMetaRepository->getPostMeta($field['name'], $post->ID);

			// Convert array values to JSON string
			if (is_array($value)) {
				$value = wp_json_encode($value);
			}

			//Skip fields that already have input tag
			if (in_array($field['fieldType'] ?? '', Helper::fieldsAlreadyHaveInput(), true)) {
				continue;
			}

			//Add hidden input for fields that not have an input tag
			$hidden_fields_html .= '<input type="hidden" name="' . esc_attr($field['name']) . '" value="' . esc_attr($value) . '">';
		}

		$allowed_html = Helper::getAllowedHiddenInputHtml();

		// Create container for React with all fields data
		// Get fields and value data as JSON with data attributes
		$html = '<div class="native-custom-fields-post-meta-wrapper" id="%s-wrapper" data-fields="%s" data-values="%s"></div>';

		echo sprintf(
			wp_kses_post($html),
			esc_attr($meta_box['id']),
			esc_attr(wp_json_encode($fields)),
			esc_attr(wp_json_encode($this->getFieldValues($fields, $post->ID))),
		);

		echo wp_kses($hidden_fields_html, $allowed_html);
	}

	/**
	 * Add fields into a post type from builder form
	 *
	 * @param string $menu_slug
	 * @param array $values
	 *
	 * @return ResponseModel
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function savePostMetaFieldsConfig(string $menu_slug, array $values): ResponseModel
	{

		$result = new ResponseModel();

		//Check if menu slug starts with 'builder'
		if (strpos($menu_slug, 'native_custom_fields_post_meta_fields_builder') !== 0) {
			$result->status  = false;
			$result->message = __('It is not a post meta fields builder.', 'native-custom-fields');

			return $result;
		}

		// Sanitize fields in the values (uses sanitize_text_fields)
		$values = Helper::sanitizeArray($values);

		// Get post_type from values
		$post_type_slug = $values['post_type'] ?? '';

		// Get meta boxes (sections_or_meta_boxes) from values
		$meta_boxes = $values['sections_or_meta_boxes'] ?? [];

		if (empty($post_type_slug)) {
			$result->status  = false;
			$result->message = __('Post type is required.', 'native-custom-fields');

			return $result;
		}

		//Prepare fields configuration (sections as meta boxes)
		$sections = [];
		foreach ($meta_boxes as $meta_box) {

			$field_list = $this->prepareFieldList($meta_box['fields'] ?? []);

			$sections[] = [
				'meta_box_id'       => $meta_box['name'] ?? '',
				'meta_box_title'    => $meta_box['fieldLabel'] ?? '',
				'meta_box_context'  => $meta_box['field_custom_info_meta_box']['meta_box_context'] ?? 'advanced',
				'meta_box_priority' => $meta_box['field_custom_info_meta_box']['meta_box_priority'] ?? 'default',
				'fields'            => $field_list,
			];
		}

		// Prepare config as array (for database storage)
		$config_array = [
			'post_type' => sanitize_key($post_type_slug),
			'sections'  => $sections
		];

		//Get post meta fields configurations
		$get_config = $this->getPostMetaFieldsConfigurations();

		//Set post type configurations data
		$get_config[$config_array['post_type']] = $config_array;

		//Save post meta fields configurations (add or update)
		$save_post_meta_config = $this->postMetaRepository->saveConfigurations($get_config, 'native_custom_fields_post_meta_fields_config');

		if ($save_post_meta_config) {
			$result->message = __('Post meta fields data saved successfully.', 'native-custom-fields');
		} else {
			$result->message = __('No changes detected. Post meta fields data already up to date.', 'native-custom-fields');
		}

		return $result;
	}

	/**
	 * Get field values for a post
	 *
	 * @param array $fields Fields configuration
	 * @param int $id Post ID
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
				$get_value = $this->postMetaRepository->getPostMeta($field['name'], $id);
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
	 * Save post meta
	 *
	 * @param int $post_id Post ID
	 *
	 * @return void
	 * @throws Exception
	 */
	public function savePostMeta(int $post_id): void
	{
		$nonce = Helper::sanitize('native_custom_fields_post_meta_nonce', 'post');

		if (
			! isset($nonce) || // phpcs:ignore WordPress.Security.NonceVerification.NoNonceVerification
			! wp_verify_nonce($nonce, 'native_custom_fields_post_meta_nonce')
		) {
			return;
		}

		// Check autosave
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		// Check permissions
		if (! current_user_can('edit_post', $post_id)) {
			return;
		}

		// Get fields for this post type
		$get_post_type = get_post_type($post_id);

		$post_meta_fields_config = $this->getPostMetaFieldsConfigurationsFiltered();

		if (empty($post_meta_fields_config)) {
			return;
		}

		foreach ($post_meta_fields_config as $post_type => $config) {

			if ($post_type !== $get_post_type) {
				continue;
			}

			// Get meta boxes (sections) from configuration
			$meta_boxes = Helper::getSectionsFromConfig($config);

			foreach ($meta_boxes as $meta_box) {

				// Get fields directly from array
				$meta_box_fields = $meta_box['fields'] ?? [];

				foreach ($meta_box_fields as $field) {

					$meta_key   = $field['name'];

					if (! isset($meta_key)) {
						continue;
					}

					// Always save the value, even if it's empty (important for checkboxes and radios)
					$value = Helper::getRawValue($meta_key, 'post') ?? '';

					// Try to decode JSON if the value is a JSON string (group/repeater fields are posted as JSON)
					if (is_string($value) && Helper::isJson($value)) {
						$value = json_decode($value, true);
					}

					// Sanitize according to the field's fieldType (preserves line breaks for textarea, etc.)
					$value = Helper::sanitizeFieldValue($value, $field['fieldType'] ?? 'text', $field['fields'] ?? [], $meta_key);

					// Save the value to the database
					$this->postMetaRepository->savePostMeta($post_id, $meta_key, $value);
				}
			}
		}
	}

	/**
	 * Register all post meta fields
	 *
	 * @param string $post_type
	 * @param WP_Post_Type $pt_object
	 *
	 * @return void
	 * @throws Exception
	 */
	public function registerAllPostMeta(string $post_type, WP_Post_Type $pt_object): void
	{
		// Get post meta fields configurations
		$post_meta_fields_config = $this->getPostMetaFieldsConfigurationsFiltered();

		if (empty($post_meta_fields_config[$post_type])) {
			return;
		}

		$config = $post_meta_fields_config[$post_type];

		// Get meta boxes from config (already in array format)
		$meta_boxes = Helper::getSectionsFromConfig($config);

		foreach ($meta_boxes as $meta_box) {
			// Get fields directly from array
			$fields = $meta_box['fields'] ?? [];
			$this->registerFieldsForPostType($post_type, $fields);
		}
	}

	/**
	 * Register fields for a post type
	 *
	 * @param string $post_type
	 * @param array $fields
	 *
	 * @return void
	 * @since 1.0.0
	 *
	 */
	private function registerFieldsForPostType(string $post_type, array $fields): void
	{

		if (empty($fields) && $post_type === '') {
			return;
		}

		foreach ($fields as $field) {

			$meta_key = $field['name'];
			$field_type = $field['fieldType'];

			if ($field_type === 'section' || $field_type === 'meta_box') {
				return;
			}

			if (empty($meta_key) || ! is_string($meta_key)) {
				continue;
			}

			// Modify post meta type (for example, convert string to number)
			$get_type = apply_filters('native_custom_fields_register_post_meta_type', $meta_key, $post_type);

			$get_type = Helper::normalizeMetaType($get_type);

			$args = [
				'type'         => $get_type,
				'single'       => true,
				'show_in_rest' => true,
			];

			// Modify post meta args
			$args = apply_filters('native_custom_fields_register_post_meta_args', $args, $meta_key, $post_type);

			register_post_meta($post_type, $meta_key, $args);
		}
	}

	/**
	 * Prepare a field list with recursive handling for the fields
	 *
	 * @param array $fields
	 *
	 * @return array
	 * @since 1.0.0
	 */
	function prepareFieldList(array $fields): array
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

	//region Config Reader for Post Meta (similar to Options)
	/**
	 * Get post meta fields configuration by post type
	 *
	 * @param string $post_type
	 *
	 * @return PostMetaFieldsConfigResponseModel
	 * @throws Exception
	 */
	public function getPostMetaConfigByPostType(string $post_type): PostMetaFieldsConfigResponseModel
	{
		$post_type = sanitize_key($post_type);

		// Set response model
		$result = new PostMetaFieldsConfigResponseModel();

		if (empty($post_type)) {
			$result->status  = false;
			$result->message = __('Invalid post type provided.', 'native-custom-fields');

			return $result;
		}

		// Create config model
		$config_model            = new PostMetaFieldsConfigModel();
		$config_model->post_type = $post_type;

		//Get post meta fields configurations
		$post_meta_fields_config = $this->getPostMetaFieldsConfigurationsFiltered();

		if (! empty($post_meta_fields_config[$post_type])) {
			// Get sections from config (already in array format)
			$config_model->sections = $post_meta_fields_config[$post_type]['sections'] ?? [];
		} else {
			$config_model->sections = [];
		}

		// Get builder form values for TreeView initialFields
		$builder_menu_slug = 'native_custom_fields_post_meta_fields_builder_' . $post_type;
		try {
			$builder_values = get_option($builder_menu_slug, []);
			if (! empty($builder_values)) {
				$config_model->values = $builder_values;
			}
		} catch (Exception $e) {
			// If builder values don't exist, continue with empty values
		}

		$result->config_model = $config_model;

		return $result;
	}
	//endregion
}
