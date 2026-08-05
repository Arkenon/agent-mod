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
     * Returns the JSON schema for a single field used in ability input_schema definitions.
     * Centralised here so all four field-bearing services share one definition.
     *
     * @return array
     * @since 1.0.5
     */
    protected function getFieldSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['fieldType', 'name', 'fieldLabel'],
            'properties' => [
                'fieldType'  => [
                    'type'        => 'string',
                    'description' => __('Field type. Common values: text, textarea, input, select, radio, checkbox, toggle, number, range, combobox, token_field, toggle_group, date_picker, date_time_picker, time_picker, file_upload, media_library, color_picker, color_palette, font_size, unit, repeater, group, section', 'native-custom-fields'),
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
                    'description' => __('Type-specific options. select/radio/combobox/token_field/toggle_group: {options: "Label:val, Label2:val2", multiple: bool}. input: {type: "text|email|url|number|date|datetime-local|password", placeholder: "...", min: N, max: N, step: N}. textarea: {placeholder: "...", rows: N}. number/range: {min: N, max: N, step: N}. text: {placeholder: "..."}. Nested fields inside repeater/group are not supported via ability.', 'native-custom-fields'),
                ],
            ],
        ];
    }

    /**
     * Wraps simplified ability field items in the structure expected by prepareFieldList.
     * Maps field_custom_info → field_custom_info_{type} and top-level required/disabled → field_base_info.
     *
     * @param array $fields Simplified field definitions from ability input.
     * @return array Fields ready to pass as the 'fields' key inside a section/meta-box.
     * @since 1.0.5
     */
    private function prepareAbilityFields(array $fields): array
    {
        $prepared = [];
        foreach ($fields as $field) {
            $type = sanitize_text_field($field['fieldType'] ?? 'text');

            $field_base_info = [];
            if (isset($field['required'])) {
                $field_base_info['required'] = (bool) $field['required'];
            }
            if (isset($field['disabled'])) {
                $field_base_info['disabled'] = (bool) $field['disabled'];
            }

            $prepared[] = [
                'fieldType'                  => $type,
                'name'                       => sanitize_key($field['name'] ?? ''),
                'fieldLabel'                 => sanitize_text_field($field['fieldLabel'] ?? ''),
                // Repeater/group defaults are arrays; sanitize_text_field() would flatten them to ''.
                'default'                    => Helper::sanitizeFieldValue($field['default'] ?? '', $type, $field['fields'] ?? [], $field['name'] ?? ''),
                'field_base_info'            => $field_base_info,
                'field_custom_info_' . $type => $field['field_custom_info'] ?? [],
                'field_dependency_info'      => [],
            ];
        }
        return $prepared;
    }
}
