<?php
/**
 * User Meta Service Interface
 * Contains method signatures for user meta-related operations.
 * @package NativeCustomFields
 * @subpackage Services/Interfaces
 * @since 1.0.0
 */

namespace NativeCustomFields\Services\Interfaces;

use NativeCustomFields\Models\Common\ResponseModel;
use WP_User;

interface UserMetaServiceInterface {
	public function saveUserMetaFieldsConfig( string $menu_slug, array $values ): ResponseModel;
	public function getUserMetaFieldsConfigurations(): array;
	public function getUserMetaFieldsConfigurationsFiltered(): array;
	public function renderFields( WP_User $user ): void;
	public function saveUserMeta( int $user_id ): void;
}

