<?php

/**
 * Option Menu configurations model class
 * Responsible for handling option menu data
 * @package NativeCustomFields
 * @subpackage Models\Options
 * @since 1.0.0
 */

namespace NativeCustomFields\Models\Options;

use NativeCustomFields\Models\Common\FieldsConfigModel;

defined('ABSPATH') || exit;

class OptionMenuConfigModel
{
	public string $menu_slug = '';
	public string $layout = 'stacked';
	public array $values = [];
	public array $sections = [];
}
