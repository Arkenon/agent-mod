<?php

/**
 * Option Menu default model class
 * Contains default parameters for add_menu_page() function
 * @package NativeCustomFields
 * @subpackage Models\Options
 * @since 1.0.0
 */

namespace NativeCustomFields\Models\Options;

defined('ABSPATH') || exit;

class OptionMenuModel
{
	public string $page_title;
	public string $menu_title = '';
	public string $capability = 'manage_options';
	public string $menu_slug;
	public string $callback = '';
	public string $icon_url = 'dashicons-admin-generic';
	public string $layout = '';
	/**@var ?int|?float $position */
	public $position = 60;
	public string $created_by = 'external_plugin';
	public array $sections = [];
	public array $values = [];

	/**
	 * Create an OptionMenuModel instance from an array
	 *
	 * @param array $data Array data to map to model properties
	 * @param string $menu_slug Menu slug for this menu item
	 *
	 * @return self
	 * @since 1.0.0
	 */
	public static function fromArray(array $data, string $menu_slug = ''): self
	{
		$model               = new self();
		$model->page_title   = $data['page_title'] ?? '';
		$model->menu_title   = $data['menu_title'] ?? '';
		$model->capability   = $data['capability'] ?? 'manage_options';
		$model->menu_slug    = $menu_slug ?: ($data['menu_slug'] ?? '');
		$model->callback     = $data['callback'] ?? '';
		$model->icon_url     = $data['icon_url'] ?? 'dashicons-admin-generic';
		$model->layout       = $data['layout'] ?? '';
		$model->position     = $data['position'] ?? 60;
		$model->created_by   = $data['created_by'] ?? 'external_plugin';
		$model->sections     = $data['sections'] ?? [];
		$model->values       = $data['values'] ?? [];

		return $model;
	}
}
