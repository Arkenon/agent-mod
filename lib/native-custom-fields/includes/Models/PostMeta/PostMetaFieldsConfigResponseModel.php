<?php

/**
 * Post meta fields config response model
 *
 * @package NativeCustomFields
 * @subpackage Models/PostMeta
 * @since 1.0.0
 */

namespace NativeCustomFields\Models\PostMeta;

use NativeCustomFields\Models\Common\ResponseModel;

defined('ABSPATH') || exit;

class PostMetaFieldsConfigResponseModel extends ResponseModel
{
	public PostMetaFieldsConfigModel $config_model;
}
