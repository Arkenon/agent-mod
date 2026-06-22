<?php

/**
 * OptionsController class
 * Handles the options pages and their configurations
 *
 * @package NativeCustomFields
 * @subpackage Presentation\Admin\Controllers
 * @since 1.0.0
 */

namespace NativeCustomFields\Presentation\Admin\Controllers;

use Exception;
use NativeCustomFields\Common\Helper;
use NativeCustomFields\Models\Options\OptionMenuModel;
use NativeCustomFields\Services\OptionService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

class OptionsController
{

    /**
     * Route namespace for REST API
     * @since 1.0.0
     * @var string
     */
    private string $routeNamespace = 'native-custom-fields/v1';

    /**
     * Inject OptionService
     * @var OptionService
     * @since 1.0.0
     */
    private OptionService $optionService;

    public function __construct(OptionService $optionService)
    {
        $this->optionService = $optionService;

        // Add menu pages
        add_action('admin_menu', [$this, 'addMenuPages']);

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

        #region Options Pages
        //Rest API route for get options pages list
        register_rest_route($this->routeNamespace, 'options/get-options-pages', [
            'methods' => 'GET',
            'callback' => [$this, 'getOptionsPages'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ]);

        //Rest API route for get options page configurations by menu slug
        register_rest_route($this->routeNamespace, 'options/get-options-page-config-by-menu-slug', [
            'methods' => 'GET',
            'callback' => [$this, 'getOptionsPageConfigByMenuSlug'],
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            },
            'args' => [
                'menu_slug' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        //Rest API route for save options pages configuration
        register_rest_route($this->routeNamespace, 'options/save-option-pages-config', [
            'methods' => 'POST',
            'callback' => [$this, 'saveOptionPagesConfig'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'args' => [
                'menu_slug' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'values' => [
                    'required' => true,
                    'type' => 'object',
                ],
            ],
        ]);

        //Rest API route for to delete options page by menu slug
        register_rest_route($this->routeNamespace, '/options/delete-options-page', [
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'deleteOptionsPageConfigByMenuSlug'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
                'args' => [
                    'menu_slug' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                ],
            ],
        ]);

        //Rest API route for save options for general use
        register_rest_route($this->routeNamespace, 'options/save-options', [
            'methods' => 'POST',
            'callback' => [$this, 'saveOptions'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'args' => [
                'menu_slug' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'values' => [
                    'required' => true,
                    'type' => 'object',
                ],
            ],
        ]);
        #endregion

        #region Options Page Fields
        //Rest API route for save option page fields configuration
        register_rest_route($this->routeNamespace, 'options/save-option-page-fields-config', [
            'methods' => 'POST',
            'callback' => [$this, 'saveOptionPageFieldsConfig'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'args' => [
                'menu_slug' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'values' => [
                    'required' => true,
                    'type' => 'object',
                ],
            ],
        ]);
        #endregion
    }

    /**
     * Get options pages configuration by menu slug
     *
     * @param WP_REST_Request $request REST API request
     *
     * @return WP_Error|WP_REST_Response WP_REST_Response or WP_Error
     * @throws Exception
     * @since 1.0.0
     */
    public function getOptionsPageConfigByMenuSlug(WP_REST_Request $request)
    {
        $menu_slug = sanitize_text_field($request->get_param('menu_slug'));

        $result = $this->optionService->getOptionsPageConfigByMenuSlug($menu_slug);

        return rest_ensure_response($result);
    }

    /**
     * Delete options page configuration by menu slug
     *
     * @param WP_REST_Request $request REST API request
     *
     * @return WP_Error|WP_REST_Response WP_REST_Response or WP_Error
     * @throws Exception
     * @since 1.0.0
     */
    public function deleteOptionsPageConfigByMenuSlug(WP_REST_Request $request)
    {
        $menu_slug = sanitize_text_field($request->get_param('menu_slug'));

        $result = $this->optionService->deleteOptionsPageConfigurationsByMenuSlug($menu_slug);

        return rest_ensure_response($result);
    }

    /**
     * Get options pages list
     * @return WP_REST_Response WP_REST_Response or WP_Error
     * @throws Exception
     * @since 1.0.0
     */
    public function getOptionsPages(): WP_REST_Response
    {
        $result = $this->optionService->getOptionsPages();

        return rest_ensure_response($result);
    }

    /**
     * Handle save options request
     *
     * @param WP_REST_Request $request Request object
     *
     * @return WP_REST_Response|WP_Error Response object
     * @throws Exception
     * @since 1.0.0
     */
    public function saveOptions(WP_REST_Request $request)
    {

        $menu_slug = sanitize_text_field($request->get_param('menu_slug'));
        $values = Helper::sanitizeArray($request->get_param('values'));
        $reset = rest_sanitize_boolean($request->get_param('reset'));

        $result = $this->optionService->saveOptions($menu_slug, $values, $reset);

        return rest_ensure_response($result);
    }

    /**
     * Handle save option pages configuration request
     *
     * @param WP_REST_Request $request Request object
     *
     * @return WP_REST_Response|WP_Error Response object
     * @throws Exception
     * @since 1.0.0
     */
    public function saveOptionPagesConfig(WP_REST_Request $request)
    {

        $menu_slug = sanitize_text_field($request->get_param('menu_slug'));
        $values = Helper::sanitizeArray($request->get_param('values'));

        $result = $this->optionService->saveOptionsPageConfig($menu_slug, $values);

        return rest_ensure_response($result);
    }

    /**
     * Handle save option page fields configuration request
     *
     * @param WP_REST_Request $request Request object
     *
     * @return WP_REST_Response|WP_Error Response object
     * @throws Exception
     * @since 1.0.0
     */
    public function saveOptionPageFieldsConfig(WP_REST_Request $request)
    {

        $menu_slug = sanitize_text_field($request->get_param('menu_slug'));
        $values = Helper::sanitizeArray($request->get_param('values'));

        $result = $this->optionService->saveOptionPageFieldsConfig($menu_slug, $values);

        return rest_ensure_response($result);
    }
    #endregion

    #region Menu Pages

    /**
     * Add admin menus pages from options pages configurations
     * @return void
     * @throws Exception
     * @since 1.0.0
     */
    public function addMenuPages(): void
    {
        //Get options pages configurations
        $options_pages = $this->optionService->getOptionsPagesConfigurationsFiltered();

        if (empty($options_pages)) {
            return;
        }

        foreach ($options_pages as $menu_slug => $menu) {
            // Convert array to OptionMenuModel
            $menuModel = OptionMenuModel::fromArray($menu, $menu_slug);

            //Add menu pages into admin menu
            if (!empty($menuModel->parent_slug)) {
                add_submenu_page(
                    $menuModel->parent_slug,
                    $menuModel->page_title,
                    $menuModel->menu_title ?: $menuModel->page_title,
                    $menuModel->capability,
                    $menu_slug,
                    [$this, 'renderPage']
                );
            } else {
                add_menu_page(
                    $menuModel->page_title,
                    $menuModel->menu_title,
                    $menuModel->capability,
                    $menu_slug,
                    [$this, 'renderPage'],
                    $menuModel->icon_url,
                    $menuModel->position
                );
            }
        }
    }

    /**
     * Render method for options pages
     * It provides a wrapper div for React app
     * @return void
     * @since 1.0.0
     */
    public function renderPage()
    {
        $menu_slug = Helper::sanitize('page', 'get');

        $html = '<div class="native-custom-fields-wrapper" data-menu="' . esc_attr($menu_slug) . '" data-page-title="' . esc_attr(get_admin_page_title()) . '"></div>';
        echo wp_kses_post($html);
    }

    #endregion

}
