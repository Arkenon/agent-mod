<?php
/**
 * Post Meta Service Interface
 * Contains method signatures for post meta-related operations.
 * @package NativeCustomFields
 * @subpackage Services/Interfaces
 * @since 1.0.0
 */

namespace NativeCustomFields\Services\Interfaces;

use NativeCustomFields\Models\Common\ResponseModel;
use NativeCustomFields\Models\PostMeta\PostMetaFieldsConfigResponseModel;
use NativeCustomFields\Models\PostMeta\PostTypeListResponseModel;
use WP_Post;
use WP_Post_Type;

interface PostMetaServiceInterface {
	public function registerPostTypes(): void;
	public function getPostTypes(): PostTypeListResponseModel;
	public function getPostTypesConfigurations(): array;
	public function getPostTypesConfigurationsFiltered(): array;
	public function savePostTypeConfig( string $menu_slug, array $values ): ResponseModel;
	public function deletePostTypeConfigBySlug( string $post_type_slug ): ResponseModel;
	public function getPostMetaFieldsConfigurations(): array;
	public function getPostMetaFieldsConfigurationsFiltered(): array;
	public function addMetaBoxes(): void;
	public function renderMetaBox( WP_Post $post, array $meta_box ): void;
	public function savePostMetaFieldsConfig( string $menu_slug, array $values ): ResponseModel;
	public function savePostMeta( int $post_id ): void;
	public function registerAllPostMeta( string $post_type, WP_Post_Type $pt_object ): void;
	public function getPostMetaConfigByPostType( string $post_type ): PostMetaFieldsConfigResponseModel;
}

