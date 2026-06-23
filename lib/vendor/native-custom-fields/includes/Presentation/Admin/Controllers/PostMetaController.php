<?php

/**
 * PostMetaController class
 * Responsible for handling post meta actions
 *
 * @package NativeCustomFields
 * @subpackage Presentation\Admin\Controllers
 * @since 1.0.0
 */

namespace NativeCustomFields\Presentation\Admin\Controllers;

use Exception;
use NativeCustomFields\Common\DI;
use NativeCustomFields\Common\Helper;
use NativeCustomFields\Models\Common\ResponseModel;
use NativeCustomFields\Services\PostMetaService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

class PostMetaController
{

	/**
	 * Inject PostMetaRepository
	 *
	 * @var PostMetaService
	 * @since 1.0.0
	 */
	private PostMetaService $postMetaService;

	public function __construct(PostMetaService $post_meta_service)
	{

		$this->postMetaService = $post_meta_service;

		// Register rest api routes
		add_action('rest_api_init', [$this, 'registerRestRoutes']);
	}

	#region Rest Routes and Callbacks

	/**
	 * Register REST API routes
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function registerRestRoutes(): void
	{

		//Rest API route for get post type list
		register_rest_route('native-custom-fields/v1', 'post-meta/get-post-types', [
			'methods'             => 'GET',
			'callback'            => [$this, 'getPostTypes'],
			'permission_callback' => function () {
				return current_user_can('edit_posts');
			}
		]);

		//Rest API route for get post meta config by post type
		register_rest_route('native-custom-fields/v1', 'post-meta/get-post-meta-config-by-post-type', [
			'methods'             => 'GET',
			'callback'            => [$this, 'getPostMetaConfigByPostType'],
			'permission_callback' => function () {
				return current_user_can('edit_posts');
			},
			'args'                => [
				'post_type' => [
					'required' => true,
					'type'     => 'string',
				],
			],
		]);

		//Rest API route for save post type configuration
		register_rest_route('native-custom-fields/v1', 'post-meta/save-post-type-config', [
			'methods'             => 'POST',
			'callback'            => [$this, 'savePostTypeConfig'],
			'permission_callback' => function () {
				return current_user_can('manage_options');
			},
			'args'                => [
				'menu_slug' => [
					'required' => true,
					'type'     => 'string',
				],
				'values'    => [
					'required' => true,
					'type'     => 'object',
				],
			],
		]);

		//Rest API route for save post meta field configuration
		register_rest_route('native-custom-fields/v1', 'post-meta/save-post-meta-fields-config', [
			'methods'             => 'POST',
			'callback'            => [$this, 'savePostMetaFieldsConfig'],
			'permission_callback' => function () {
				return current_user_can('manage_options');
			},
			'args'                => [
				'menu_slug' => [
					'required' => true,
					'type'     => 'string',
				],
				'values'    => [
					'required' => true,
					'type'     => 'object',
				],
			],
		]);

		//Rest API route for to delete post type by slug
		register_rest_route('native-custom-fields/v1', '/post-meta/delete-post-type', [
			[
				'methods'             => 'DELETE',
				'callback'            => [$this, 'deletePostTypeConfigBySlug'],
				'permission_callback' => function () {
					return current_user_can('edit_posts');
				},
				'args'                => [
					'post_type_slug' => [
						'required' => true,
						'type'     => 'string',
					],
				],
			],
		]);
	}

	/**
	 * Get post meta config by post type
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 * @throws Exception
	 */
	public function getPostMetaConfigByPostType(WP_REST_Request $request)
	{
		$post_type = sanitize_text_field($request->get_param('post_type'));

		$result = $this->postMetaService->getPostMetaConfigByPostType($post_type);

		return rest_ensure_response($result);
	}

	/**
	 * Get post types list
	 * Both set by PHP array and admin create post types form
	 *
	 * @return WP_REST_Response WP_REST_Response or WP_Error
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function getPostTypes(): WP_REST_Response
	{

		$result = $this->postMetaService->getPostTypes();

		return rest_ensure_response($result);
	}

	/**
	 * Delete post type configuration by post type slug
	 * To delete post type configurations at admin create post types form
	 *
	 * @param WP_REST_Request $request REST API request
	 *
	 * @return WP_Error|WP_REST_Response WP_REST_Response or WP_Error
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function deletePostTypeConfigBySlug(WP_REST_Request $request)
	{
		$post_type_slug = sanitize_text_field($request->get_param('post_type_slug'));

		$result = $this->postMetaService->deletePostTypeConfigBySlug($post_type_slug);

		return rest_ensure_response($result);
	}

	/**
	 * Handle save post type configuration request
	 *
	 * @param WP_REST_Request $request Request object
	 *
	 * @return WP_REST_Response|WP_Error Response object
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function savePostTypeConfig(WP_REST_Request $request)
	{

		$menu_slug = sanitize_text_field($request->get_param('menu_slug'));
		$values    = Helper::sanitizeArray($request->get_param('values'));

		$result = $this->postMetaService->savePostTypeConfig($menu_slug, $values);

		return rest_ensure_response($result);
	}

	/**
	 * Handle save post meta fields configuration request
	 *
	 * @param WP_REST_Request $request Request object
	 *
	 * @return WP_REST_Response|WP_Error Response object
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function savePostMetaFieldsConfig(WP_REST_Request $request)
	{

		$menu_slug = sanitize_text_field($request->get_param('menu_slug'));
		$values    = Helper::sanitizeArray($request->get_param('values'));

		$result = $this->postMetaService->savePostMetaFieldsConfig($menu_slug, $values);

		return rest_ensure_response($result);
	}
	#endregion
}
