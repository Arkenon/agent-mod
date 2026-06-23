<?php

/**
 * Custom fields model for meta boxes of post types
 *
 * @package NativeCustomFields
 * @subpackage Models/PostMeta
 * @since 1.0.0
 */

namespace NativeCustomFields\Models\PostMeta;


use NativeCustomFields\Models\Common\FieldsConfigModel;

defined('ABSPATH') || exit;

class PostMetaFieldsConfigModel extends FieldsConfigModel
{
	public string $post_type;
	public array $values = [];
}
