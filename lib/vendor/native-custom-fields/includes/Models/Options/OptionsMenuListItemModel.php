<?php

/**
 * Option Menu list item model class
 * Responsible for handling list item data for options menus
 * @package NativeCustomFields
 * @subpackage Models\Options
 * @since 1.0.0
 */

namespace NativeCustomFields\Models\Options;

defined('ABSPATH') || exit;

class OptionsMenuListItemModel
{

	public int $no = 1;
	public string $menu_name = '';
	public string $menu_slug = '';
	public string $layout = '';
	public string $created_by = 'external_plugin';
}
