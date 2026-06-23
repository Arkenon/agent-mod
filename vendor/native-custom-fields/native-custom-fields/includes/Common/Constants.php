<?php

/**
 * Additional Plugin Constants
 * (Base constant defined in the main plugin php file)
 * @package NativeCustomFields
 * @subpackage Common
 * @since 1.0.0
 */

namespace NativeCustomFields\Common;

defined('ABSPATH') || exit;

class Constants
{
	public const NAME = 'native_custom_fields';
	public const INCLUDES_PATH = NATIVE_CUSTOM_FIELDS_PATH . 'includes/';
	public const INCLUDES_URL = NATIVE_CUSTOM_FIELDS_URL . '/includes/';
	public const AUTHOR = 'Kadim Gültekin';
	public const AUTHOR_URL = 'https://kadimgultekin.com/';
	public const PLUGIN_URL = 'https://kadimgultekin.com/';
	public const EMAIL = 'info@kadimgultekin.com';
	public const PHP_VERSION = '7.4';
	public const WP_VERSION = '5.0';
	public const VERSION = '1.2.6';

	public const BUILDER_MENU_SLUGS = [
		'native_custom_fields_options_page_builder',
		'native_custom_fields_options_page_sections_builder',
		'native_custom_fields_options_page_fields_builder',
		'native_custom_fields_post_type_builder',
		'native_custom_fields_post_meta_builder',
		'native_custom_fields_taxonomy_builder',
		'native_custom_fields_term_meta_fields_builder',
		'native_custom_fields_user_meta_fields_builder',
		'native_custom_fields_comment_meta_fields_builder'
	];
}
