<?php

/**
 * Options class for handling admin pages and fields
 *
 * @package NativeCustomFields
 * @subpackage Services
 */

namespace NativeCustomFields\Services;

use Exception;
use NativeCustomFields\Common\Helper;
use NativeCustomFields\Models\Common\ResponseModel;
use NativeCustomFields\Models\Options\OptionMenuConfigModel;
use NativeCustomFields\Models\Options\OptionMenuConfigResponseModel;
use NativeCustomFields\Models\Options\OptionsMenuListItemModel;
use NativeCustomFields\Models\Options\OptionsMenuListResponseModel;
use NativeCustomFields\Repositories\OptionRepository;
use NativeCustomFields\Services\Interfaces\OptionServiceInterface;


defined('ABSPATH') || exit;

/**
 * Class Options
 *
 * @package NativeCustomFields\Options
 * @since 1.0.0
 */
class OptionService implements OptionServiceInterface
{

	/**
	 * Option repository
	 *
	 * @var OptionRepository
	 * @since 1.0.0
	 */
	private OptionRepository $optionRepository;

	public function __construct(OptionRepository $optionRepository)
	{
		//Inject dependencies
		$this->optionRepository = $optionRepository;
	}


	#region Options Pages Configurations

	/**
	 * Get the option pages list
	 * @return OptionsMenuListResponseModel
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function getOptionsPages(): OptionsMenuListResponseModel
	{
		//Get options pages configurations
		$options_pages = $this->getOptionsPagesConfigurationsFiltered();

		// Set response model
		$result = new OptionsMenuListResponseModel();

		if (! empty($options_pages)) {
			//Add menu items to the response model
			$i = 1;
			foreach ($options_pages as $menu_slug => $menu) {
				// Create item from array data
				$item             = new OptionsMenuListItemModel();
				$item->no         = $i;
				$item->menu_slug  = $menu_slug;
				$item->menu_name  = $menu['menu_title'] ?? '';
				$item->layout     = $menu['layout'] ?? 'stacked';
				$item->created_by = $menu['created_by'] ?? '';

				$result->options_menu_list[] = $item;

				$i++;
			}
		}

		return $result;
	}

	/**+
	 * Get options pages configurations
	 *
	 * @param string $menu_slug
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function getOptionsPagesConfigurations(string $menu_slug = 'native_custom_fields_options_pages_config'): array
	{
		return $this->optionRepository->getConfigurations($menu_slug);
	}

	/**+
	 * Get options pages configurations with applied filters
	 * Filter applies PHP-based modifications to the options pages list
	 *
	 * @param string $menu_slug
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function getOptionsPagesConfigurationsFiltered(string $menu_slug = 'native_custom_fields_options_pages_config'): array
	{
		$options_pages = $this->optionRepository->getConfigurations($menu_slug);

		// Apply filter to allow modification of options pages list
		return apply_filters('native_custom_fields_options_pages', $options_pages);
	}

	/***
	 * Get options pages configurations by menu slug
	 *
	 * @param string $menu_slug
	 *
	 * @return OptionMenuConfigResponseModel
	 * @throws Exception
	 *
	 */
	public function getOptionsPageConfigByMenuSlug(string $menu_slug): OptionMenuConfigResponseModel
	{

		//Set response model
		$result = new OptionMenuConfigResponseModel();

		if (empty($menu_slug)) {
			$result->status  = false;
			$result->message = __('Invalid menu slug provided.', 'native-custom-fields');

			return $result;
		}

		// Check if it is a page builder page - there is no config, return the base model
		if (Helper::isBuilderPage($menu_slug)) {
			//Return base response with empty config model
			$result->config_model = new OptionMenuConfigModel();
		} else {
			try {
				// Build config model from arrays
				$result->config_model = $this->buildConfigModel($menu_slug);
			} catch (Exception $e) {
				$result->status  = false;
				$result->message = $e->getMessage();

				return $result;
			}
		}

		return $result;
	}

	/**
	 * Save options page configurations
	 *
	 * @param string $menu_slug
	 * @param array $values
	 * @param bool $reset If reset form data action is performed
	 *
	 * @return ResponseModel
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function saveOptions(string $menu_slug, array $values, bool $reset = false): ResponseModel
	{

		//Set response model
		$response = new ResponseModel();

		if (empty($menu_slug)) {
			$response->status  = false;
			$response->message = __('Invalid parameters provided.', 'native-custom-fields');

			return $response;
		}

		//If is not a form reset action, fire hooks before saving options
		if (! $reset) {
			// Fire action before saving options
			do_action('native_custom_fields_save_options_before', $menu_slug, $values);
		}

		//Get saved values from the database
		$saved = $this->optionRepository->saveOptions($values, $menu_slug);

		if (! $saved) {
			$response->status  = false;
			$response->message = __('Failed to save options.', 'native-custom-fields');
		} else {
			$response->status  = true;
			$response->message = __('Options saved successfully.', 'native-custom-fields');
		}

		//If is not a form reset action, fire hooks after saving options
		if (! $reset) {
			// Fire action after saving options
			do_action('native_custom_fields_save_options_after', $response);
		}

		return $response;
	}

	/**
	 * Save options page configurations from the options page builder
	 * This method can only be used for the options page builder
	 *
	 * @param string $menu_slug
	 * @param array $values
	 *
	 * @return ResponseModel
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function saveOptionsPageConfig(string $menu_slug, array $values): ResponseModel
	{

		//Set response model
		$response = new ResponseModel();

		//Check if menu slug starts with 'native_custom_fields_options_page_builder' and if not, return false.
		//Because this method is only for the options page builder
		if (strpos($menu_slug, 'native_custom_fields_options_page_builder') !== 0) {
			$response->status  = false;
			$response->message = __('It is not a builder form.', 'native-custom-fields');

			return $response;
		}

		//Get values from create options page form
		$menu_data = Helper::sanitizeArray($values['native_custom_fields_create_options_page']);

		//Check if required values from the create options page form exist
		if (! isset($menu_data['menu_slug']) && ! isset($menu_data['page_title'])) {
			$response->status  = false;
			$response->message = __('Menu slug and page title are required.', 'native-custom-fields');

			return $response;
		}

		//If menu title is not set, use page title as menu title
		$menu_data['menu_title'] = $menu_data['menu_title'] ?: $menu_data['page_title'];

		// Mark as created by options page builder to separate from external plugins
		$menu_data['created_by'] = 'native_custom_fields';

		//Get current options pages configurations
		$get_config = $this->getOptionsPagesConfigurations();

		//Add new options page configurations data into the existing configurations
		$get_config[$menu_data['menu_slug']] = $menu_data;

		//Save options page configurations (add or update)
		$save_config = $this->optionRepository->saveConfigurations($get_config, 'native_custom_fields_options_pages_config');

		if ($save_config) {
			$response->message = __('Options saved successfully.', 'native-custom-fields');
		} else {
			$response->message = __('No changes detected. Options already up to date.', 'native-custom-fields');
		}

		return $response;
	}

	/**
	 * Delete options pages configurations
	 *
	 * @param string $menu_slug
	 *
	 * @return ResponseModel
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function deleteOptionsPageConfigurationsByMenuSlug(string $menu_slug): ResponseModel
	{
		//Set response model
		$result = new ResponseModel();

		//Delete options page configurations
		$result->status  = $this->optionRepository->deleteConfigurations('native_custom_fields_options_pages_config', $menu_slug);
		$result->message = $result->status ? __('Options page deleted successfully.', 'native-custom-fields') : __('Options page can not be deleted.', 'native-custom-fields');

		return $result;
	}
	#endregion

	#region Options Pages Fields

	/**
	 * Get options page fields configurations
	 * @return array
	 * @since 1.0.0
	 */
	public function getOptionsPagesFieldsConfigurations(): array
	{
		//Get saved configurations from the database
		return $this->optionRepository->getConfigurations('native_custom_fields_options_pages_fields_config');
	}

	/**
	 * Create option page fields from the builder
	 *
	 * @param string $menu_slug
	 * @param array $values
	 *
	 * @return ResponseModel
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function saveOptionPageFieldsConfig(string $menu_slug, array $values): ResponseModel
	{

		//Set response model
		$response = new ResponseModel();

		//Check if menu slug starts with 'builder'. If not, return.
		if (strpos(sanitize_text_field($menu_slug), 'native_custom_fields_options_page_fields_builder') !== 0) {
			$response->status  = false;
			$response->message = __('It is not a builder form.', 'native-custom-fields');

			return $response;
		}

		//Get menu slug value to set fields_config data
		$config_menu_slug = $values['menu_slug'];

		// Get page sections value to set fields_config data
		$page_sections = $values['sections_or_meta_boxes'];

		//Prepare fields configuration
		$menu_sections = [];
		foreach ($page_sections as $page_section) {

			$field_list = $this->prepareFieldList($page_section['fields']);

			$menu_sections[] = [
				'section_name'  => $page_section['name'] ?? '',
				'section_title' => $page_section['fieldLabel'] ?? '',
				'section_icon'  => $page_section['field_custom_info_section']['section_icon'] ?? '',
				'fields'        => $field_list
			];
		}

		//Prepare fields config as array (for database storage)
		$fields_config_array = [
			'menu_slug' => $config_menu_slug,
			'sections'  => $menu_sections
		];

		//Get options pages configurations
		$get_config = $this->getOptionsPagesConfigurations();

		//Set options page configurations data
		$get_config[$config_menu_slug] = $fields_config_array;

		//Save options page configurations (add or update)
		$save_config = $this->optionRepository->saveConfigurations($get_config, 'native_custom_fields_options_pages_fields_config');

		if ($save_config) {
			$response->message = __('Option page fields saved successfully.', 'native-custom-fields');
		}

		return $response;
	}


	/**
	 * Build config model from array data
	 * Converts internal array structures to OptionMenuConfigModel
	 *
	 * @param string $menu_slug
	 *
	 * @return OptionMenuConfigModel
	 * @throws Exception
	 * @since 1.0.0
	 */
	private function buildConfigModel(string $menu_slug): OptionMenuConfigModel
	{
		//Get all options pages configurations stored in the database
		$options_pages_config = $this->getOptionsPagesConfigurationsFiltered();

		//Get fields configuration by menu slug
		$fields_config = $this->getOptionsPagesFieldsConfigurations();

		//Create config model
		$config_model = new OptionMenuConfigModel();
		$config_model->menu_slug = $menu_slug;
		$config_model->layout = $options_pages_config[$menu_slug]['layout'] ?? 'stacked';

		//Get sections from fields configuration
		if (! empty($fields_config[$menu_slug]['sections'])) {
			$config_model->sections = $fields_config[$menu_slug]['sections'];
		}

		//Get saved values from the database
		$config_model->values = $this->optionRepository->getOptions($menu_slug);

		//Apply filter to allow modification of sections before returning
		$config_model->sections = apply_filters('native_custom_fields_options_page_fields', $config_model->sections, $menu_slug);

		return $config_model;
	}

	/**
	 * Handle field list to prepare field data
	 *
	 * @param array $fields
	 *
	 * @return array
	 * @since 1.0.0
	 */
	private function prepareFieldList(array $fields): array
	{
		$field_list = [];


		foreach ($fields as $field) {

			// Get field data from option groups
			$field_base_info = $field['field_base_info'];

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
