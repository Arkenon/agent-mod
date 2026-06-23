<?php

/**
 * Post type list model class
 * Responsible for handling post types data for Create Post Type page
 * @package NativeCustomFields
 * @subpackage Models\Postmeta
 * @since 1.0.0
 */

namespace NativeCustomFields\Models\PostMeta;

use NativeCustomFields\Models\Common\ResponseModel;

defined('ABSPATH') || exit;

class PostTypeListResponseModel extends ResponseModel
{
	public array $post_type_list = [];
}
