<?php

/**
 * User meta repository for handling user meta fields
 *
 * @package NativeCustomFields
 * @subpackage Repositories
 * @since 1.0.0
 */

namespace NativeCustomFields\Repositories;

use WP_User;

defined('ABSPATH') || exit;

class UserMetaRepository extends BaseRepository
{

	/**
	 * Get field values for a user meta field
	 *
	 * @param string $key
	 * @param int $user_id
	 * @param bool $single
	 *
	 * @return mixed The value of the meta field if found.
	 *               False if the meta key does not exist.
	 */
	public function getUserMeta(string $key, int $user_id, bool $single = true)
	{
		return get_user_meta($user_id, $key, $single);
	}

	/**
	 * Check whether a meta key exists for a user
	 * Unlike getUserMeta(), this tells a stored falsy value (false, 0, '') apart from a missing one.
	 *
	 * @param string $key
	 * @param int $user_id
	 *
	 * @return bool
	 * @since 1.3.3
	 */
	public function userMetaExists(string $key, int $user_id): bool
	{
		return metadata_exists('user', $user_id, $key);
	}

	/**
	 * Save user meta
	 *
	 * @param int $user_id User ID
	 * @param string $meta_key Meta key
	 * @param mixed $value Meta value
	 *
	 * @return bool|int Meta ID if the key didn't exist, true on successful update, false on failure.
	 * @since 1.0.0
	 */
	public function saveUserMeta(int $user_id, string $meta_key, $value)
	{
		return update_user_meta($user_id, $meta_key, $value);
	}

	/**
	 * Get WP_User object by user ID
	 *
	 * @param int $user_id
	 *
	 * @return WP_User|false
	 * @since 1.0.0
	 */
	public function getUser(int $user_id)
	{
		return get_user($user_id);
	}
}
