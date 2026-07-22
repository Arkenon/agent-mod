<?php

/**
 * User Meta Service for handling user meta fields
 *
 * @package NativeCustomFields
 * @subpackage Services
 * @since 1.0.0
 **/

namespace NativeCustomFields\Services;

use Exception;
use NativeCustomFields\Common\Helper;
use NativeCustomFields\Models\Common\ResponseModel;
use NativeCustomFields\Repositories\UserMetaRepository;
use NativeCustomFields\Services\Interfaces\BaseMetaServiceInterface;
use NativeCustomFields\Services\Interfaces\UserMetaServiceInterface;
use WP_User;

defined('ABSPATH') || exit;

class UserMetaService implements BaseMetaServiceInterface, UserMetaServiceInterface
{

	/**
	 * Term meta repository
	 *
	 * @var UserMetaRepository
	 * @since 1.0.0
	 */
	private UserMetaRepository $userMetaRepository;

	public function __construct(UserMetaRepository $user_meta_repository)
	{
		$this->userMetaRepository = $user_meta_repository;
	}

	/**
	 * Add fields into user pages from builder form
	 *
	 * @param string $menu_slug
	 * @param array $values
	 *
	 * @return ResponseModel
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function saveUserMetaFieldsConfig(string $menu_slug, array $values): ResponseModel
	{
		$result = new ResponseModel();

		//Check if menu slug starts with 'builder'
		if (strpos($menu_slug, 'native_custom_fields_user_meta_fields_builder') !== 0) {
			$result->status  = false;
			$result->message = __('It is not a user meta fields builder.', 'native-custom-fields');

			return $result;
		}

		// Sanitize fields in the values (uses sanitize_text_fields)
		$values = Helper::sanitizeArray($values);

		// Get sections (sections_or_meta_boxes) from values
		$sections = $values['sections_or_meta_boxes'] ?? [];

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
		// User role is fixed as 'all_users' for now
		$config_array = [
			'user_role' => 'all_users',
			'sections'  => $prepared_sections
		];

		$get_config = $this->getUserMetaFieldsConfigurations();

		//Set user role configurations data as array (only all_users for now)
		$get_config[$config_array['user_role']] = $config_array;

		//Save user meta fields configurations (add or update)
		$save_user_meta_config = $this->userMetaRepository->saveConfigurations($get_config, 'native_custom_fields_user_meta_fields_config');

		if ($save_user_meta_config) {
			$result->message = __('User meta fields data saved successfully.', 'native-custom-fields');
		} else {
			$result->message = __('No changes detected. User meta fields data already up to date.', 'native-custom-fields');
		}

		return $result;
	}

	/**
	 * Get term meta fields configurations
	 * @return array
	 * @since 1.0.0
	 */
	public function getUserMetaFieldsConfigurations(): array
	{
		//Get saved configurations from database
		return $this->userMetaRepository->getConfigurations('native_custom_fields_user_meta_fields_config');
	}

	/**
	 * Get user meta fields configurations with filters applied
	 * Filter applies php based modifications to the user meta fields configurations
	 * @return array
	 * @since 1.0.0
	 */
	public function getUserMetaFieldsConfigurationsFiltered(): array
	{
		//Get saved configurations from database
		$get_user_meta_fields = $this->userMetaRepository->getConfigurations('native_custom_fields_user_meta_fields_config');

		// Apply filter (matches the documented hook name, same naming pattern as
		// native_custom_fields_post_meta_fields / native_custom_fields_term_meta_fields)
		$filtered = apply_filters('native_custom_fields_user_meta_fields', $get_user_meta_fields);

		// Normalize: Convert model objects to arrays (backward compatibility)
		if (is_array($filtered)) {
			foreach ($filtered as $key => $config) {
				// If config is a model object, cast it to array
				if (is_object($config)) {
					$filtered[$key] = (array) $config;
				}
			}
		}

		return $filtered;
	}

	/**
	 * Get field values for a user
	 *
	 * @param array $fields Fields configuration
	 * @param int $id User ID
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
				$get_value = $this->userMetaRepository->getUserMeta($field['name'], $id);
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
	 * Add user meta fields to edit form
	 *
	 * @param WP_User $user User object
	 *
	 * @return void
	 * @throws Exception
	 */
	public function renderFields(WP_User $user): void
	{

		$user_meta_fields_config = $this->getUserMetaFieldsConfigurationsFiltered();

		// Only supports 'all_users' role for now
		if (empty($user_meta_fields_config) || ! array_key_exists('all_users', $user_meta_fields_config)) {
			return;
		}

		$config = $user_meta_fields_config['all_users'];

		// Get sections from config (already in array format)
		$sections = Helper::getSectionsFromConfig($config);

		if (empty($sections)) {
			return;
		}

		wp_nonce_field('native_custom_fields_user_meta_nonce', 'native_custom_fields_user_meta_nonce');

		$allowed_html = Helper::getAllowedHiddenInputHtml();

		echo '<table class="form-table">';

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

				// Get the value of the field
				$value = $this->userMetaRepository->getUserMeta($field['name'], $user->ID);

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
				$section_header = '<span class="dashicons dashicons-' . esc_attr($section_icon) . '"></span> ' . '<span class="native-custom-fields-meta-th-header">' . $section_header . '</span>';
			}

			$html = '<tr class="form-field user-meta-wrap user-meta-section-wrap">';
			$html .= '<th scope="row">%s</th>';
			$html .= '<td>';
			$html .= '<div class="native-custom-fields-user-meta-wrapper" data-fields="%s" data-values="%s"></div>';
			$html .= '%s';
			$html .= '</td>';
			$html .= '</tr>';

			echo sprintf(
				wp_kses_post($html),
				$section_header, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $section_header is already escaped via esc_html() and esc_attr() above.
				esc_attr(wp_json_encode($fields)),
				esc_attr(wp_json_encode($this->getFieldValues($fields, $user->ID))),
				wp_kses($hidden_fields_html, $allowed_html)
			);
		}

		echo '</table>';
	}

	/**
	 * Save user meta
	 *
	 * @param int $user_id User ID
	 *
	 * @return void
	 * @throws Exception
	 */
	public function saveUserMeta(int $user_id): void
	{
		// Check nonce
		if (
			! isset($_POST['native_custom_fields_user_meta_nonce']) ||
			! wp_verify_nonce(sanitize_key(wp_unslash($_POST['native_custom_fields_user_meta_nonce'])), 'native_custom_fields_user_meta_nonce')
		) {
			return;
		}

		// Check permissions
		if (! current_user_can('edit_user', $user_id)) {
			return;
		}


		$user_meta_fields_config = $this->getUserMetaFieldsConfigurationsFiltered();
		$config = $user_meta_fields_config['all_users'];
		if (empty($config)) {
			return;
		}

		// Get sections from config (already in array format)
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

			// Always save the value, even if it's empty (important for checkboxes and radios)
			$value = Helper::getRawValue($meta_key, 'post') ?? '';

			// Try to decode JSON if the value is a JSON string (group/repeater fields are posted as JSON)
			if (is_string($value) && Helper::isJson($value)) {
				$value = json_decode($value, true);
			}

			// Sanitize according to the field's fieldType (preserves line breaks for textarea, etc.)
			$value = Helper::sanitizeFieldValue($value, $field['fieldType'] ?? 'text', $field['fields'] ?? []);

			// Save the value to the database
			$this->userMetaRepository->saveUserMeta($user_id, $meta_key, $value);
		}
	}

	/**
	 * Prepare a field list with recursive handling for group and repeater fields
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
}
