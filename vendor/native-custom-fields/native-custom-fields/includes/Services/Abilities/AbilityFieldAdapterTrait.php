<?php

/**
 * Ability Field Adapter Trait
 * Shared helper for adapting simplified ability field input into the structure expected by prepareFieldList.
 *
 * @package NativeCustomFields
 * @subpackage Services\Abilities
 * @since 1.0.5
 */

namespace NativeCustomFields\Services\Abilities;

use NativeCustomFields\Common\Helper;

defined('ABSPATH') || exit;

trait AbilityFieldAdapterTrait
{
    /**
     * Field types that accept sub-fields.
     *
     * @var string[]
     * @since 1.3.9
     */
    private static $container_field_types = ['repeater', 'group'];

    /**
     * How many levels of sub-fields the ability input schema advertises.
     * The Abilities API validates input with rest_validate_value_from_schema(), which has no
     * JSON Schema `$ref` support, so the field schema has to be expanded to a fixed depth.
     * 2 allows repeater -> group -> field, which covers realistic field layouts without
     * repeating the whole field schema for every extra level.
     *
     * @var int
     * @since 1.3.9
     */
    private static $max_field_depth = 2;

    /**
     * Returns the JSON schema for a single field used in ability input_schema definitions.
     * Centralised here so all four field-bearing services share one definition.
     *
     * @param int|null $depth How many further levels of sub-fields to expand. 0 stops the recursion.
     *                        Defaults to self::$max_field_depth.
     *
     * @return array
     * @since 1.0.5
     */
    protected function getFieldSchema(?int $depth = null): array
    {
        $depth = null === $depth ? self::$max_field_depth : $depth;

        // Only the outermost level carries the long descriptions; repeating them at every
        // nesting level would bloat the schema without telling the client anything new.
        $is_root = $depth === self::$max_field_depth;

        $schema = [
            'type'       => 'object',
            'required'   => ['fieldType', 'name', 'fieldLabel'],
            'properties' => [
                'fieldType'  => [
                    'type'        => 'string',
                    'description' => $is_root
                        ? __('Field type. Common values: text, textarea, input, select, radio, checkbox, toggle, number, range, combobox, token_field, toggle_group, date_picker, date_time_picker, time_picker, file_upload, media_library, color_picker, color_palette, font_size, unit, repeater, group, section, notice, heading, text_highlight. For notice, heading and text_highlight the displayed content goes in field_custom_info (see there), not in fieldLabel', 'native-custom-fields')
                        : __('Field type. Accepts the same values as the parent field.', 'native-custom-fields'),
                ],
                'name'       => ['type' => 'string', 'description' => __('Unique meta key slug', 'native-custom-fields')],
                'fieldLabel' => ['type' => 'string'],
                'default'    => [
                    'type'        => ['string', 'array'],
                    'description' => __('Default value. Repeater and group fields take an array: a repeater default is a list of row objects keyed by sub-field name.', 'native-custom-fields'),
                ],
                'required'   => ['type' => 'boolean', 'default' => false],
                'disabled'   => ['type' => 'boolean', 'default' => false],
                'field_custom_info' => [
                    'type'        => 'object',
                    'description' => $is_root
                        ? __('Type-specific options. select/radio/combobox/token_field/toggle_group: {options: "Label:val, Label2:val2", multiple: bool}. input: {type: "text|email|url|number|date|datetime-local|password", placeholder: "...", min: N, max: N, step: N}. textarea: {placeholder: "...", rows: N}. number/range: {min: N, max: N, step: N}. text: {placeholder: "..."}. repeater: {layout: "table|panel", addButtonText: "...", min: N, max: N, initialOpen: bool}. group: {layout: "flex|grid", columns: N, direction: "row|columnRow|column", justify: "flex-start|center|flex-end|space-between|space-around|space-evenly"}. notice: {children: "The message text shown to the user", status: "info|success|warning|error"} (fieldLabel is only the admin label, the visible message is children). heading: {text: "Heading text", level: 1-6}. text_highlight: {text: "Full text", highlight: "Part of the text to highlight"}.', 'native-custom-fields')
                        : __('Type-specific options. Same shape as the parent field.', 'native-custom-fields'),
                ],
            ],
        ];

        if ($depth > 0) {
            $schema['properties']['fields'] = [
                'type'        => 'array',
                'description' => $is_root
                    ? sprintf(
                        /* translators: %d: maximum supported sub-field nesting depth. */
                        __('Sub-fields, for the repeater and group field types only. Each item uses this same field structure, so a repeater row can hold any combination of fields. Ignored for every other field type. Up to %d levels of nesting are supported.', 'native-custom-fields'),
                        self::$max_field_depth
                    )
                    : __('Sub-fields of a nested repeater or group field.', 'native-custom-fields'),
                'items'       => $this->getFieldSchema($depth - 1),
            ];
        }

        return $schema;
    }

    /**
     * Base info defaults as the builder UI stores them, so the builder forms can load
     * ability-created configurations the same way as UI-created ones.
     *
     * @return array
     * @since 1.4.1
     */
    private function getDefaultFieldBaseInfo(): array
    {
        return [
            'fieldHelpText'           => '',
            'className'               => '',
            'fieldLabelPosition'      => 'top',
            'fieldLabelTextTransform' => 'uppercase',
            'required'                => false,
            'disabled'                => false,
            'hideLabelFromVision'     => false,
        ];
    }

    /**
     * Empty dependency structure as the builder UI stores it.
     *
     * @return array
     * @since 1.4.1
     */
    private function getDefaultDependencyInfo(): array
    {
        return ['relation' => 'and', 'conditions' => []];
    }

    /**
     * Makes sure the display-only field types (notice, heading, text_highlight) get their content
     * in the property the renderer reads. Clients often put the message in fieldLabel (or call it
     * `message`), which leaves the field empty on the page, so those are mapped to the right property.
     *
     * @param string $type Field type
     * @param mixed $custom_info The field_custom_info value from ability input
     * @param string $label The field label from ability input
     *
     * @return array
     * @since 1.4.1
     */
    private function normalizeDisplayFieldCustomInfo(string $type, $custom_info, string $label): array
    {
        $custom_info = is_array($custom_info) ? $custom_info : [];

        // Display property each type renders, and the alias clients tend to use for it.
        $content_keys = [
            'notice'         => ['children', 'message'],
            'heading'        => ['text', 'content'],
            'text_highlight' => ['text', 'content'],
        ];

        if (! isset($content_keys[$type])) {
            return $custom_info;
        }

        list($property, $alias) = $content_keys[$type];

        if (! isset($custom_info[$property]) || '' === $custom_info[$property]) {
            $custom_info[$property] = $custom_info[$alias] ?? $label;
        }
        unset($custom_info[$alias]);

        return $custom_info;
    }

    /**
     * Reverse of prepareAbilityFields(): converts stored (runtime) field configs back into the
     * simplified ability shape (fieldType, name, fieldLabel, default, required, disabled,
     * field_custom_info, fields) so a read result can be edited and saved back.
     *
     * @param array $fields Stored field configs as produced by prepareFieldList().
     * @param int $depth Current nesting level, bounded like prepareAbilityFields().
     *
     * @return array
     * @since 1.4.1
     */
    private function extractAbilityFields(array $fields, int $depth = 0): array
    {
        $core_keys = ['fieldType', 'name', 'fieldLabel', 'default', 'dependencies', 'fields'];
        $base_keys = array_keys($this->getDefaultFieldBaseInfo());
        // Added by prepareFieldList() for table repeaters, not user input.
        $derived_keys = ['hideRepeaterItemTag', 'hideLabel'];

        $result = [];
        foreach ($fields as $field) {
            $custom = array_diff_key($field, array_flip(array_merge($core_keys, $base_keys, $derived_keys)));

            $item = [
                'fieldType'  => $field['fieldType'] ?? '',
                'name'       => $field['name'] ?? '',
                'fieldLabel' => $field['fieldLabel'] ?? '',
                'default'    => $field['default'] ?? '',
                'required'   => ! empty($field['required']),
                'disabled'   => ! empty($field['disabled']),
            ];

            if (in_array($field['fieldType'] ?? '', self::$container_field_types, true)) {
                $sub_fields = $field['fields'] ?? [];
                if (! empty($sub_fields) && is_array($sub_fields) && $depth < self::$max_field_depth) {
                    $item['fields'] = $this->extractAbilityFields($sub_fields, $depth + 1);
                }
            }

            if (! empty($custom)) {
                $item['field_custom_info'] = $custom;
            }

            $result[] = $item;
        }

        return $result;
    }

    /**
     * Wraps simplified ability field items in the structure expected by prepareFieldList.
     * Maps field_custom_info → field_custom_info_{type} and top-level required/disabled → field_base_info.
     * Sub-fields of repeater/group fields are converted recursively into the sibling 'fields' key
     * that prepareFieldList() reads.
     *
     * @param array $fields Simplified field definitions from ability input.
     * @param int $depth Current nesting level. Guards against runaway recursion when the ability
     *                   callback is invoked directly in PHP instead of through schema validation.
     *
     * @return array Fields ready to pass as the 'fields' key inside a section/meta-box.
     * @since 1.0.5
     */
    private function prepareAbilityFields(array $fields, int $depth = 0): array
    {
        $prepared = [];
        foreach ($fields as $field) {
            $type = sanitize_text_field($field['fieldType'] ?? 'text');

            $field_base_info = $this->getDefaultFieldBaseInfo();
            if (isset($field['required'])) {
                $field_base_info['required'] = (bool) $field['required'];
            }
            if (isset($field['disabled'])) {
                $field_base_info['disabled'] = (bool) $field['disabled'];
            }

            $sub_fields = [];
            if (
                in_array($type, self::$container_field_types, true)
                && ! empty($field['fields'])
                && is_array($field['fields'])
                && $depth < self::$max_field_depth
            ) {
                $sub_fields = $this->prepareAbilityFields($field['fields'], $depth + 1);
            }

            $custom_info = $this->normalizeDisplayFieldCustomInfo($type, $field['field_custom_info'] ?? [], $field['fieldLabel'] ?? '');

            $prepared_field = [
                'fieldType'                  => $type,
                'name'                       => sanitize_key($field['name'] ?? ''),
                'fieldLabel'                 => sanitize_text_field($field['fieldLabel'] ?? ''),
                // Repeater/group defaults are arrays; sanitize_text_field() would flatten them to ''.
                'default'                    => Helper::sanitizeFieldValue($field['default'] ?? '', $type, $sub_fields, $field['name'] ?? ''),
                'field_base_info'            => $field_base_info,
                'field_custom_info_' . $type => $custom_info,
                'field_dependency_info'      => $this->getDefaultDependencyInfo(),
            ];

            // Only repeater/group carry sub-fields; prepareFieldList() ignores the key for every
            // other type, so setting it there would only bloat the stored configuration.
            if (! empty($sub_fields)) {
                $prepared_field['fields'] = $sub_fields;
            }

            $prepared[] = $prepared_field;
        }
        return $prepared;
    }
}
