<?php

/**
 * Term meta fields model for taxonomies
 *
 * @package NativeCustomFields
 * @subpackage Models/TermMeta
 * @since 1.0.0
 */

namespace NativeCustomFields\Models\TermMeta;


use NativeCustomFields\Models\Common\FieldsConfigModel;

defined('ABSPATH') || exit;

class TermMetaFieldsConfigModel extends FieldsConfigModel
{
	public string $taxonomy;
	public array $values = [];
}
