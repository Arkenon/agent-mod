<?php

/**
 * TermMetaController class
 * Responsible for handling custom taxonomies, term meta fields, and related REST API endpoints.
 *
 * @package NativeCustomFields
 * @subpackage Presentation\Admin\Controllers
 * @since 1.0.0
 */

namespace NativeCustomFields\Presentation\Admin\Controllers;

use Exception;
use NativeCustomFields\Common\Helper;
use NativeCustomFields\Services\TermMetaService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

class TermMetaController
{

	/**
	 * Inject PostMetaRepository
	 *
	 * @var TermMetaService
	 * @since 1.0.0
	 */
	private TermMetaService $termMetaService;

	public function __construct(TermMetaService $term_meta_service)
	{

		$this->termMetaService = $term_meta_service;

		// Register rest api routes
		add_action('rest_api_init', [$this, 'registerRestRoutes']);

		// Register taxonomies
		add_action('init', [$this->termMetaService, 'registerTaxonomies']);

		// Register all term meta fields when a taxonomy is registered
		add_action('registered_taxonomy', [$this->termMetaService, 'registerAllTermMeta'], 10, 2);

		// Add form fields to taxonomy add and edit forms
		add_action('registered_taxonomy', [$this->termMetaService, 'addFormFieldsToTaxonomy'], 10, 2);

		// Add edit and save hooks for taxonomy
		add_action('registered_taxonomy', [$this->termMetaService, 'editOrSaveHooksForTaxonomy'], 10, 2);
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

		//Rest API route for get post taxonomy list
		register_rest_route('native-custom-fields/v1', 'term-meta/get-taxonomies', [
			'methods'             => 'GET',
			'callback'            => [$this, 'getTaxonomies'],
			'permission_callback' => function () {
				return current_user_can('edit_posts');
			}
		]);

		//Rest API route for save custom taxonomy configuration
		register_rest_route('native-custom-fields/v1', 'term-meta/save-custom-taxonomy-config', [
			'methods'             => 'POST',
			'callback'            => [$this, 'saveCustomTaxonomyConfig'],
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

		//Rest API route for term meta configuration
		register_rest_route('native-custom-fields/v1', 'term-meta/save-term-meta-fields-config', [
			'methods'             => 'POST',
			'callback'            => [$this, 'saveTermMetaFieldsConfig'],
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

		//Rest API route for to delete taxonomy by slug
		register_rest_route('native-custom-fields/v1', '/term-meta/delete-taxonomy', [
			[
				'methods'             => 'DELETE',
				'callback'            => [$this, 'deleteTaxonomyConfigBySlug'],
				'permission_callback' => function () {
					return current_user_can('manage_categories');
				},
				'args'                => [
					'taxonomy_slug' => [
						'required' => true,
						'type'     => 'string',
					],
				],
			],
		]);
	}


	/**
	 * Get taxonomy list registered in the site
	 *
	 * @return WP_REST_Response WP_REST_Response or WP_Error
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function getTaxonomies(): WP_REST_Response
	{

		$result = $this->termMetaService->getTaxonomies();

		return rest_ensure_response($result);
	}

	/**
	 * Delete taxonomy configuration by slug
	 *
	 * @param WP_REST_Request $request Request object
	 *
	 * @return WP_REST_Response WP_REST_Response or WP_Error
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function deleteTaxonomyConfigBySlug(WP_REST_Request $request): WP_REST_Response
	{

		$taxonomy_slug = sanitize_text_field($request->get_param('taxonomy_slug'));

		$result = $this->termMetaService->deleteTaxonomyConfigBySlug($taxonomy_slug);

		return rest_ensure_response($result);
	}

	/**
	 * Handle save custom taxonomy configuration request
	 *
	 * @param WP_REST_Request $request Request object
	 *
	 * @return WP_REST_Response|WP_Error Response object
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function saveCustomTaxonomyConfig(WP_REST_Request $request)
	{

		$menu_slug = sanitize_text_field($request->get_param('menu_slug'));
		$values    = Helper::sanitizeArray($request->get_param('values'));

		$result = $this->termMetaService->saveCustomTaxonomyConfig($menu_slug, $values);

		return rest_ensure_response($result);
	}

	/**
	 * Handle save term meta fields configuration request
	 *
	 * @param WP_REST_Request $request Request object
	 *
	 * @return WP_REST_Response|WP_Error Response object
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function saveTermMetaFieldsConfig(WP_REST_Request $request)
	{

		$menu_slug = sanitize_text_field($request->get_param('menu_slug'));
		$values    = Helper::sanitizeArray($request->get_param('values'));

		$result = $this->termMetaService->saveTermMetaFieldsConfig($menu_slug, $values);

		return rest_ensure_response($result);
	}
	#endregion
}
