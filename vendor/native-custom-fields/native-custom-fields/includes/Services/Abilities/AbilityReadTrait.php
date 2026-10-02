<?php

/**
 * Ability Read Trait
 * Shared helpers for the read-only (list / get) abilities.
 *
 * @package NativeCustomFields
 * @subpackage Services\Abilities
 * @since 1.4.1
 */

namespace NativeCustomFields\Services\Abilities;

defined('ABSPATH') || exit;

trait AbilityReadTrait
{
    /**
     * Meta shared by every read-only ability.
     *
     * @return array
     * @since 1.4.1
     */
    private function getReadAbilityMeta(): array
    {
        return [
            'show_in_rest' => true,
            'mcp'          => ['public' => true],
            'annotations'  => [
                'readonly'    => true,
                'destructive' => false,
                'idempotent'  => true,
            ],
        ];
    }

    /**
     * Whether the builder UI state option (what the Edit / Fields screens load) exists.
     *
     * @param string $builder_option Builder option name
     *
     * @return bool
     * @since 1.4.1
     */
    private function hasBuilderState(string $builder_option): bool
    {
        return ! empty(get_option($builder_option, []));
    }
}
