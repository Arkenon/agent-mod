<?php

/**
 * Option menu config response model
 *
 * @package NativeCustomFields
 * @subpackage Models/Options
 * @since 1.0.0
 */

namespace NativeCustomFields\Models\Options;

use NativeCustomFields\Models\Common\ResponseModel;

defined('ABSPATH') || exit;

class OptionMenuConfigResponseModel extends ResponseModel
{
	public OptionMenuConfigModel $config_model;
}
