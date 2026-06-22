<?php

/**
 * Taxonomy list model class
 * Responsible for handling taxonomies data for Create Taxonomy page
 * @package NativeCustomFields
 * @subpackage Models\TermMeta
 * @since 1.0.0
 */

namespace NativeCustomFields\Models\TermMeta;

use NativeCustomFields\Models\Common\ResponseModel;

defined('ABSPATH') || exit;

class TaxonomyListResponseModel extends ResponseModel
{
	public array $taxonomy_list = [];
}
