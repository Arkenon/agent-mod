<?php

/**
 * Term meta fields model for taxonomies
 *
 * @package NativeCustomFields
 * @subpackage Models/TermMeta
 * @since 1.0.0
 */


namespace NativeCustomFields\Models\UserMeta;

use NativeCustomFields\Models\Common\FieldsConfigModel;

class UserMetaFieldsConfigModel extends FieldsConfigModel
{
	public string $user_role = 'all_users'; // Meta box will be shown for all user roles by default
	public array $values = [];
}
