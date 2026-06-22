<?php

/**
 * Post type list item model class
 * Responsible for handling list item data for post types
 * @package NativeCustomFields
 * @subpackage Models\PostMeta
 * @since 1.0.0
 */

namespace NativeCustomFields\Models\PostMeta;

defined('ABSPATH') || exit;

class PostTypeListItemModel
{

	public int $no = 1;
	public string $post_type = '';
	public string $post_type_slug = '';
	public string $created_by = '';
}
