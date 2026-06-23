<?php

/**
 * Post Meta Repository
 * Responsible for handling post metadata
 * @package NativeCustomFields
 * @subpackage Services
 * @since 1.0.0
 */

namespace NativeCustomFields\Repositories;

defined('ABSPATH') || exit;

class PostMetaRepository extends BaseRepository
{

	/**
	 * Get field values for a post
	 *
	 * @param string $field_name
	 * @param int $post_id
	 *
	 * @return mixed An array of values if $single is false.
	 *               The value of the meta field if $single is true.
	 *               False for an invalid $post_id (non-numeric, zero, or negative value).
	 *               An empty array if a valid but non-existing post ID is passed and $single is false.
	 *               An empty string if a valid but non-existing post ID is passed and $single is true.
	 */
	public function getPostMeta(string $field_name, int $post_id)
	{
		return get_post_meta($post_id, $field_name, true);
	}

	/**
	 * Save post meta
	 *
	 * @param int $post_id Post ID
	 * @param string $meta_key Meta key
	 * @param mixed $value Meta value
	 *
	 * @return bool|int
	 * @since 1.0.0
	 */
	public function savePostMeta(int $post_id, string $meta_key, $value)
	{
		return update_post_meta($post_id, $meta_key, $value);
	}
}
