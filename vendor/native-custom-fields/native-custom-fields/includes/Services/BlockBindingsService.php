<?php
/**
 * BlockBindingsService class
 * Registers the "native-custom-fields/post-meta" block bindings source and formats
 * post meta values (including array and object values) as strings for bound block attributes.
 *
 * @package NativeCustomFields
 * @subpackage Services
 * @since 1.4.3
 */

namespace NativeCustomFields\Services;

use DateTimeImmutable;
use Exception;
use WP_Block;

defined('ABSPATH') || exit;

class BlockBindingsService
{
	/**
	 * Block bindings source name
	 *
	 * @since 1.4.3
	 */
	public const SOURCE_NAME = 'native-custom-fields/post-meta';

	/**
	 * Field types that hold other fields and never store a value themselves
	 *
	 * @since 1.4.3
	 */
	private const CONTAINER_FIELD_TYPES = ['section', 'meta_box'];

	/**
	 * Date types of the text (inputType) and input (type) field input types
	 *
	 * @since 1.4.5
	 */
	private const DATE_INPUT_TYPES = [
		'date'           => 'date',
		'datetime-local' => 'datetime',
		'time'           => 'time',
	];

	/**
	 * Inject PostMetaService
	 *
	 * @var PostMetaService
	 * @since 1.4.3
	 */
	private PostMetaService $postMetaService;

	/**
	 * Fields per post type, cached for the current request
	 *
	 * @var array
	 * @since 1.4.3
	 */
	private array $fieldsCache = [];

	public function __construct(PostMetaService $post_meta_service)
	{
		$this->postMetaService = $post_meta_service;
	}

	/**
	 * Register the block bindings source
	 *
	 * @return void
	 * @since 1.4.3
	 */
	public function registerSource(): void
	{
		if (! function_exists('register_block_bindings_source')) {
			return;
		}

		register_block_bindings_source(
			self::SOURCE_NAME,
			[
				'label'              => __('Native Custom Fields', 'native-custom-fields'),
				'get_value_callback' => [$this, 'getValue'],
				'uses_context'       => ['postId', 'postType'],
			]
		);
	}

	/**
	 * Get the value of a bound attribute
	 * Runs the same access checks as the core/post-meta source, then formats the stored value.
	 *
	 * @param array $source_args Source arguments, expects 'key'
	 * @param WP_Block $block_instance Block instance
	 * @param string $attribute_name Bound attribute name
	 *
	 * @return string|null
	 * @since 1.4.3
	 */
	public function getValue(array $source_args, $block_instance, string $attribute_name = '')
	{
		$meta_key = isset($source_args['key']) && is_string($source_args['key']) ? $source_args['key'] : '';

		if ($meta_key === '' || empty($block_instance->context['postId'])) {
			return null;
		}

		$post_id = (int) $block_instance->context['postId'];
		$post    = get_post($post_id);

		if (! $post) {
			return null;
		}

		// If a post isn't public, prevent unauthorized users from accessing the post meta.
		if ((! is_post_publicly_viewable($post) && ! current_user_can('read_post', $post_id)) || post_password_required($post)) {
			return null;
		}

		if (is_protected_meta($meta_key, 'post')) {
			return null;
		}

		// Only fields built with this plugin can be read through this source.
		$fields = $this->getFieldsForPostType($post->post_type);

		if (! isset($fields[$meta_key])) {
			return null;
		}

		// Respect show_in_rest, as core/post-meta does (it can be turned off through a filter).
		$meta_keys = get_registered_meta_keys('post', $post->post_type);
		if (empty($meta_keys[$meta_key]['show_in_rest'])) {
			return null;
		}

		$field     = $fields[$meta_key];
		$raw_value = get_post_meta($post_id, $meta_key, true);
		$rich_text = $this->isRichTextAttribute($block_instance, $attribute_name);

		$value = $this->formatValue($raw_value, $field, $rich_text, $attribute_name);

		/**
		 * Filter the string a Native Custom Fields value is rendered as in a bound block attribute.
		 *
		 * @param string $value Formatted value
		 * @param mixed $raw_value Value stored in post meta
		 * @param array $field Field configuration
		 * @param int $post_id Post ID
		 * @param string $attribute_name Bound attribute name (content, url, alt, ...)
		 *
		 * @since 1.4.3
		 */
		return apply_filters('native_custom_fields_block_bindings_value', $value, $raw_value, $field, $post_id, $attribute_name);
	}

	/**
	 * Get the bindable fields of a post type, keyed by meta key
	 *
	 * @param string $post_type Post type slug
	 *
	 * @return array
	 * @since 1.4.3
	 */
	public function getFieldsForPostType(string $post_type): array
	{
		if (isset($this->fieldsCache[$post_type])) {
			return $this->fieldsCache[$post_type];
		}

		$config = $this->postMetaService->getPostMetaFieldsConfigurationsFiltered();
		$fields = [];

		foreach ($config[$post_type]['sections'] ?? [] as $section) {
			foreach ($section['fields'] ?? [] as $field) {
				$name = $field['name'] ?? '';

				if (! is_string($name) || $name === '' || $name[0] === '_') {
					continue;
				}

				if (in_array($field['fieldType'] ?? '', self::CONTAINER_FIELD_TYPES, true)) {
					continue;
				}

				$fields[$name] = $field;
			}
		}

		$this->fieldsCache[$post_type] = $fields;

		return $fields;
	}

	/**
	 * Get the field configurations the editor needs, for every post type with fields
	 * Only the keys used for listing and formatting are kept.
	 *
	 * @return array Post type => list of fields
	 * @since 1.4.3
	 */
	public function getEditorFieldsConfig(): array
	{
		$result = [];

		foreach (array_keys($this->postMetaService->getPostMetaFieldsConfigurationsFiltered()) as $post_type) {
			$fields = array_values(array_map([$this, 'getEditorField'], $this->getFieldsForPostType((string) $post_type)));

			if (! empty($fields)) {
				$result[$post_type] = $fields;
			}
		}

		return $result;
	}

	/**
	 * Reduce a field configuration to the keys used by the editor
	 *
	 * @param array $field Field configuration
	 *
	 * @return array
	 * @since 1.4.3
	 */
	private function getEditorField(array $field): array
	{
		$editor_field = [
			'name'      => $field['name'] ?? '',
			'label'     => $field['fieldLabel'] ?? ($field['name'] ?? ''),
			'fieldType' => $field['fieldType'] ?? 'text',
			'options'   => is_string($field['options'] ?? null) ? $field['options'] : '',
			'multiple'  => ! empty($field['multiple']),
			'dateType'  => $this->getDateType($field),
		];

		if (! empty($field['fields']) && is_array($field['fields'])) {
			$editor_field['fields'] = array_values(array_map([$this, 'getEditorField'], $field['fields']));
		}

		return $editor_field;
	}

	/**
	 * Check whether a block attribute holds rich text (HTML)
	 *
	 * @param WP_Block $block_instance Block instance
	 * @param string $attribute_name Attribute name
	 *
	 * @return bool
	 * @since 1.4.3
	 */
	private function isRichTextAttribute($block_instance, string $attribute_name): bool
	{
		$attribute = $block_instance->block_type->attributes[$attribute_name] ?? [];

		return ($attribute['source'] ?? '') === 'rich-text' || ($attribute['type'] ?? '') === 'rich-text';
	}

	/**
	 * Format a stored value as a string
	 * Keep in sync with formatValue() in src/editor/block-bindings.js.
	 *
	 * @param mixed $value Stored value
	 * @param array $field Field configuration
	 * @param bool $rich_text Whether the result goes into an HTML (rich text) attribute
	 * @param string $attribute_name Bound attribute name
	 *
	 * @return string
	 * @since 1.4.3
	 */
	public function formatValue($value, array $field, bool $rich_text, string $attribute_name = ''): string
	{
		$field_type = $field['fieldType'] ?? 'text';
		$separator  = $rich_text ? '<br>' : ', ';

		// File values may still be stored as a JSON string.
		if (is_string($value) && in_array($field_type, ['repeater', 'group', 'file_upload', 'media_library'], true)) {
			$decoded = json_decode($value, true);
			$value   = is_array($decoded) ? $decoded : $value;
		}

		switch ($field_type) {
			case 'toggle':
			case 'checkbox':
				return rest_sanitize_boolean($value)
					? __('Yes', 'native-custom-fields')
					: __('No', 'native-custom-fields');

			case 'file_upload':
			case 'media_library':
				$urls = $this->getFileUrls($value);

				// A URL attribute (image url, button url) can only take one link.
				if ($attribute_name === 'url') {
					return $urls[0] ?? '';
				}

				return $this->escapeText(implode(', ', $urls), $rich_text);

			case 'repeater':
				if (! is_array($value)) {
					return '';
				}

				$rows = [];
				foreach ($value as $row) {
					if (! is_array($row)) {
						continue;
					}

					$cells = [];
					foreach ($field['fields'] ?? [] as $sub_field) {
						$cell = $this->formatValue($row[$sub_field['name'] ?? ''] ?? '', $sub_field, $rich_text);
						if ($cell !== '') {
							$cells[] = $cell;
						}
					}

					if (! empty($cells)) {
						$rows[] = implode(' — ', $cells);
					}
				}

				return implode($separator, $rows);

			case 'group':
				if (! is_array($value)) {
					return '';
				}

				$lines = [];
				foreach ($field['fields'] ?? [] as $sub_field) {
					$line = $this->formatValue($value[$sub_field['name'] ?? ''] ?? '', $sub_field, $rich_text);
					if ($line !== '') {
						$label   = $this->escapeText((string) ($sub_field['fieldLabel'] ?? $sub_field['name'] ?? ''), $rich_text);
						$lines[] = $label !== '' ? $label . ': ' . $line : $line;
					}
				}

				return implode($separator, $lines);
		}

		// Dates and times in the date and time formats of the site settings
		$date_type = $this->getDateType($field);
		if ($date_type !== '') {
			return $this->escapeText($this->formatDate($value, $date_type), $rich_text);
		}

		// Select labels instead of stored values
		$labels = in_array($field_type, ['select', 'radio', 'toggle_group', 'combobox'], true)
			? $this->parseOptions($field['options'] ?? '')
			: [];

		$items = is_array($value) ? $value : [$value];
		$texts = [];

		foreach ($items as $item) {
			if (is_array($item) || is_object($item) || $item === null || $item === '') {
				continue;
			}

			$item    = (string) $item;
			$texts[] = $labels[$item] ?? $item;
		}

		$text = $this->escapeText(implode(', ', $texts), $rich_text);

		// Keep the line breaks of multiline fields
		return $rich_text ? (string) preg_replace('/\r?\n/', '<br>', $text) : $text;
	}

	/**
	 * Get the date type of a field: date, datetime, time or an empty string when it holds no date
	 *
	 * @param array $field Field configuration
	 *
	 * @return string
	 * @since 1.4.5
	 */
	public function getDateType(array $field): string
	{
		switch ($field['fieldType'] ?? '') {
			case 'date_picker':
				return 'date';
			case 'date_time_picker':
				return 'datetime';
			case 'time_picker':
				return 'time';
			case 'text':
				$input_type = $field['inputType'] ?? '';
				break;
			case 'input':
				$input_type = $field['type'] ?? '';
				break;
			default:
				return '';
		}

		return is_string($input_type) ? (self::DATE_INPUT_TYPES[$input_type] ?? '') : '';
	}

	/**
	 * Get the formats dates and times are rendered with, from the Date Format and Time Format site settings
	 *
	 * @return array Date type (date, datetime, time) => PHP date format
	 * @since 1.4.5
	 */
	public function getDateFormats(): array
	{
		$date_format = (string) get_option('date_format', 'F j, Y');
		$time_format = (string) get_option('time_format', 'g:i a');

		/**
		 * Filter the formats dates and times are rendered with in bound block attributes.
		 * Used by the editor preview as well.
		 *
		 * @param array $formats Date type (date, datetime, time) => PHP date format
		 *
		 * @since 1.4.5
		 */
		return apply_filters('native_custom_fields_block_bindings_date_formats', [
			'date'     => $date_format,
			'datetime' => trim($date_format . ' ' . $time_format),
			'time'     => $time_format,
		]);
	}

	/**
	 * Format a stored date, date time or time value with the site settings
	 * Stored values are the wall-clock time of the site (2026-10-22, 2026-10-22T12:00, 12:00).
	 * Keep in sync with formatDate() in src/editor/block-bindings.js.
	 *
	 * @param mixed $value Stored value
	 * @param string $date_type date, datetime or time
	 *
	 * @return string Formatted value, or the stored value when it can not be read as a date
	 * @since 1.4.5
	 */
	private function formatDate($value, string $date_type): string
	{
		// A time picker value can be an hours/minutes object
		if (is_array($value) && isset($value['hours'])) {
			$value = sprintf('%02d:%02d', (int) $value['hours'], (int) ($value['minutes'] ?? 0));
		}

		if (! is_scalar($value)) {
			return '';
		}

		$value = trim((string) $value);

		if ($value === '') {
			return '';
		}

		$is_time = (bool) preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value);
		$is_date = (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', $value);

		if (! $is_time && ! $is_date) {
			return $value;
		}

		$timezone = wp_timezone();
		$formats  = $this->getDateFormats();
		$format   = $formats[$is_time ? 'time' : $date_type] ?? '';

		if ($format === '') {
			return $value;
		}

		try {
			$date = $is_time
				? new DateTimeImmutable(wp_date('Y-m-d') . ' ' . $value, $timezone)
				: new DateTimeImmutable($value, $timezone);
		} catch (Exception $e) {
			return $value;
		}

		$formatted = wp_date($format, $date->getTimestamp(), $timezone);

		return is_string($formatted) && $formatted !== '' ? $formatted : $value;
	}

	/**
	 * Escape text that goes into a rich text attribute
	 *
	 * @param string $text Text
	 * @param bool $rich_text Whether the target is rich text
	 *
	 * @return string
	 * @since 1.4.3
	 */
	private function escapeText(string $text, bool $rich_text): string
	{
		return $rich_text ? esc_html($text) : $text;
	}

	/**
	 * Get the URLs of a file field value
	 *
	 * @param mixed $value Stored value: one file item or a list of file items
	 *
	 * @return array
	 * @since 1.4.3
	 */
	private function getFileUrls($value): array
	{
		if (! is_array($value)) {
			return is_string($value) && $value !== '' ? [$value] : [];
		}

		// A single file item
		if (isset($value['file_to_upload']) || isset($value['url'])) {
			$value = [$value];
		}

		$urls = [];
		foreach ($value as $item) {
			$url = is_array($item) ? ($item['file_to_upload'] ?? $item['url'] ?? '') : $item;

			if (is_string($url) && $url !== '') {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Parse a static options string ("Label 1 : value_1, Label 2 : value_2") into value => label
	 * Mirrors parseStaticOptionsString() in src/common/optionsHelper.js.
	 *
	 * @param mixed $options Options string
	 *
	 * @return array
	 * @since 1.4.3
	 */
	private function parseOptions($options): array
	{
		if (! is_string($options) || $options === '' || preg_match('/{{[^}]+}}/', $options)) {
			return [];
		}

		$labels = [];
		foreach (explode(',', $options) as $pair) {
			$parts = explode(':', trim($pair));
			$label = trim($parts[0] ?? '');
			$value = trim($parts[1] ?? '');

			if ($label === '' && $value === '') {
				continue;
			}

			$labels[$value !== '' ? $value : $label] = $label !== '' ? $label : $value;
		}

		return $labels;
	}
}
