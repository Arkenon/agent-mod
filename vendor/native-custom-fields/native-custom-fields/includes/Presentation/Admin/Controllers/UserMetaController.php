<?php

/**
 * UserMetaController class
 * Responsible for handling user meta fields and related REST API endpoints.
 *
 * @package NativeCustomFields
 * @subpackage Presentation\Admin\Controllers
 * @since 1.0.0
 */

namespace NativeCustomFields\Presentation\Admin\Controllers;

defined('ABSPATH') || exit;

use Exception;
use NativeCustomFields\Common\Helper;
use NativeCustomFields\Services\UserMetaService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class UserMetaController
{

	/**
	 * Inject UserMetaService
	 *
	 * @var UserMetaService
	 * @since 1.0.0
	 */
	private UserMetaService $userMetaService;

	public function __construct(UserMetaService $user_meta_service)
	{

		$this->userMetaService = $user_meta_service;

		// Register rest api routes
		add_action('rest_api_init', [$this, 'registerRestRoutes']);

		// Add form field hooks for the user meta fields
		add_action("show_user_profile", [$this->userMetaService, 'renderFields']);
		add_action("edit_user_profile", [$this->userMetaService, 'renderFields']);

		// Add save hooks
		add_action("personal_options_update", [$this->userMetaService, 'saveUserMeta']);
		add_action("edit_user_profile_update", [$this->userMetaService, 'saveUserMeta']);
	}

	/**
	 * Register REST API routes
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function registerRestRoutes(): void
	{
		//Rest API route for save user meta configuration
		register_rest_route('native-custom-fields/v1', 'user-meta/save-user-meta-fields-config', [
			'methods'             => 'POST',
			'callback'            => [$this, 'saveUserMetaFieldsConfig'],
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
	}

	/**
	 * Handle save user meta fields configuration request
	 *
	 * @param WP_REST_Request $request Request object
	 *
	 * @return WP_REST_Response|WP_Error Response object
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function saveUserMetaFieldsConfig(WP_REST_Request $request)
	{

		$menu_slug = sanitize_text_field($request->get_param('menu_slug'));
		$values    = Helper::sanitizeArray($request->get_param('values'));

		$result = $this->userMetaService->saveUserMetaFieldsConfig($menu_slug, $values);

		return rest_ensure_response($result);
	}
}
