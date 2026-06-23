<?php

/**
 * Base Meta Service Interface
 *
 * @package NativeCustomFields
 * @subpackage Services/Interfaces
 * @since 1.0.0
 */

namespace NativeCustomFields\Services\Interfaces;

defined('ABSPATH') || exit;

interface BaseMetaServiceInterface
{
    public function getFieldValues(array $fields, int $id = 0): array;

    public function prepareFieldList(array $fields): array;
}
