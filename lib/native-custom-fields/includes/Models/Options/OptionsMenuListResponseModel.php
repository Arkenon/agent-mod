<?php

/**
 * Option Menu list model class
 * Responsible for handling option menu data for Create Options page
 * @package NativeCustomFields
 * @subpackage Models\Options
 * @since 1.0.0
 */

namespace NativeCustomFields\Models\Options;

use NativeCustomFields\Models\Common\ResponseModel;

defined('ABSPATH') || exit;

class OptionsMenuListResponseModel extends ResponseModel
{
	public array $options_menu_list = [];
}
