<?php

/**
 * Taxonomy list item model class
 * Responsible for handling list item data for taxonomies
 * @package NativeCustomFields
 * @subpackage Models\PostMeta
 * @since 1.0.0
 */

namespace NativeCustomFields\Models\TermMeta;

defined('ABSPATH') || exit;

class TaxonomyListItemModel
{
	public int $no = 1;
	public string $taxonomy = '';
	public string $taxonomy_slug = '';
	public string $created_by = '';
}
