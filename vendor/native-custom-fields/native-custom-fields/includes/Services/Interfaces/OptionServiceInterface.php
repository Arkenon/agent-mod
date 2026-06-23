<?php
/**
 * Option Service Interface
 * Contains method signatures for option-related operations.
 * @package NativeCustomFields
 * @subpackage Services/Interfaces
 * @since 1.0.0
 */

namespace NativeCustomFields\Services\Interfaces;

use NativeCustomFields\Models\Common\ResponseModel;
use NativeCustomFields\Models\Options\OptionMenuConfigResponseModel;
use NativeCustomFields\Models\Options\OptionsMenuListResponseModel;

interface OptionServiceInterface {


	// Options Pages
	public function getOptionsPages(): OptionsMenuListResponseModel;
	public function getOptionsPagesConfigurations( string $menu_slug = 'native_custom_fields_options_pages_config' ): array;
	public function getOptionsPagesConfigurationsFiltered( string $menu_slug = 'native_custom_fields_options_pages_config' ): array;
	public function getOptionsPageConfigByMenuSlug( string $menu_slug ): OptionMenuConfigResponseModel;
	public function saveOptions( string $menu_slug, array $values, bool $reset = false ): ResponseModel;
	public function saveOptionsPageConfig( string $menu_slug, array $values ): ResponseModel;
	public function deleteOptionsPageConfigurationsByMenuSlug( string $menu_slug ): ResponseModel;


	// Options Page Fields
	public function getOptionsPagesFieldsConfigurations(): array;
	public function saveOptionPageFieldsConfig( string $menu_slug, array $values ): ResponseModel;

}
