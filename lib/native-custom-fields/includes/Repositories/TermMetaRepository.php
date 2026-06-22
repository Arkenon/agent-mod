<?php

/**
 * Term meta repository for handling term meta fields
 *
 * @package NativeCustomFields
 * @subpackage Repositories
 * @since 1.0.0
 */

namespace NativeCustomFields\Repositories;

use WP_Error;
use WP_Term;

defined('ABSPATH') || exit;

class TermMetaRepository extends BaseRepository
{

	/**
	 * Get field values for a term
	 *
	 * @param string $field_name
	 * @param int $term_id
	 * @param bool $single
	 *
	 * @return mixed The value of the meta field if found.
	 *               False if the meta key does not exist.
	 */
	public function getTermMeta(string $field_name, int $term_id, bool $single = true)
	{
		return get_term_meta($term_id, $field_name, $single);
	}

	/**
	 * Save term meta
	 *
	 * @param int $term_id Term ID
	 * @param string $meta_key Meta key
	 * @param mixed $value Meta value
	 *
	 * @return bool|int Meta ID if the key didn't exist, true on successful update, false on failure.
	 * @since 1.0.0
	 */
	public function saveTermMeta(int $term_id, string $meta_key, $value)
	{
		return update_term_meta($term_id, $meta_key, $value);
	}

	/**
	 * Delete term meta
	 *
	 * @param int $term_id Term ID
	 * @param string $meta_key Meta key
	 * @param mixed $value Optional. Meta value. If provided, rows will only be removed that match the value.
	 *
	 * @return bool True on success, false on failure.
	 * @since 1.0.0
	 */
	public function deleteTermMeta(int $term_id, string $meta_key, $value = ''): bool
	{
		return delete_term_meta($term_id, $meta_key, $value);
	}

	/**
	 * Get all terms for a taxonomy
	 *
	 * @param string $taxonomy Taxonomy name
	 * @param array $args Optional. Arguments to get terms
	 *
	 * @return int[]|string|string[]|WP_Error|WP_Term[] List of WP_Term objects on success, 0 if no terms found, or WP_Error on error.
	 * @since 1.0.0
	 */
	public function getTerms(string $taxonomy, array $args = [])
	{
		return get_terms(array_merge(['taxonomy' => $taxonomy], $args));
	}

	/**
	 * Get a specific term by ID
	 *
	 * @param int $term_id Term ID
	 * @param string $taxonomy Taxonomy name
	 *
	 * @return array|null|WP_Error|WP_Term WP_Term object if found, WP_Error if not found.
	 * @since 1.0.0
	 */
	public function getTerm(int $term_id, string $taxonomy = '')
	{
		return get_term($term_id, $taxonomy);
	}
}
