<?php
/**
 * Main plugin class
 * Runs on plugins_loaded action
 * @package NativeCustomFields
 * @since 1.0.0
 */

namespace NativeCustomFields;

use DI\DependencyException;
use DI\NotFoundException;
use Exception;
use NativeCustomFields\Common\DI;
use NativeCustomFields\Presentation\Admin\Controllers\AbilityContoller;
use NativeCustomFields\Presentation\ControllerInit;
use NativeCustomFields\Services\AjaxService;
use NativeCustomFields\Services\ImportExportService;
use NativeCustomFields\Services\OptionService;
use NativeCustomFields\Services\PostMetaService;
use NativeCustomFields\Services\TermMetaService;
use NativeCustomFields\Services\UserMetaService;

defined( 'ABSPATH' ) || exit;

final class App {

    /**
     * List of services to be initialized
     * @var array
     * @since 1.0.0
     */
    private array $services = [
        AjaxService::class,
        OptionService::class,
        PostMetaService::class,
		TermMetaService::class,
        UserMetaService::class,
        ImportExportService::class
    ];

    /**
     * Include Presentation layer base class - ControllerInit.php
     * Contains all controllers
     * @var array
     * @since 1.0.0
     */
    private array $controllers = [
        ControllerInit::class
    ];

    /**
     * Flag to prevent multiple initializations
     * @var bool
     * @since 1.2.6
     */
    private static bool $booted = false;

    public function __construct()
    {
        // Add filters to allow plugins to add their own services
        $this->services = apply_filters(
            'native_custom_fields_services',
            $this->services
        );
    }

	/**
	 * Boot the plugin
     * Would be called when plugin using with composer or externally
	 * @return void
	 * @since 1.2.6
	 */
	public static function boot(array $config = []): void
	{
        if ( self::$booted ) {
            return;
        }

        $url = isset( $config['url'] )
            ? sanitize_text_field( $config['url'] )
            : ( defined( 'NATIVE_CUSTOM_FIELDS_URL' ) ? NATIVE_CUSTOM_FIELDS_URL : '' );
        $path = isset( $config['path'] )
            ? sanitize_text_field( $config['path'] )
            : ( defined( 'NATIVE_CUSTOM_FIELDS_PATH' ) ? NATIVE_CUSTOM_FIELDS_PATH : '' );
       

        if ( ! defined( 'NATIVE_CUSTOM_FIELDS_URL' ) ) {
            define( 'NATIVE_CUSTOM_FIELDS_URL', $url );
        }

        if ( ! defined( 'NATIVE_CUSTOM_FIELDS_PATH' ) ) {
            define( 'NATIVE_CUSTOM_FIELDS_PATH', $path );
        }

        if ( ! defined( 'NATIVE_CUSTOM_FIELDS_INCLUDES_PATH' ) ) {
            define(
                'NATIVE_CUSTOM_FIELDS_INCLUDES_PATH',
                $path . 'includes/'
            );
        }

        // Remove AbilityContoller from the controllers list because it will
        // already be registered/loaded automatically when booted via Composer.
        add_filter( 'native_custom_fields_controllers', static function ( array $controllers ): array {
            return array_diff( $controllers, [ AbilityContoller::class ] );
        } );

        ( new self() )->run();
	}

    /**
	 * Run all services and controllers
	 * @return void
	 * @since 1.0.0
	 */
	public function run(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;

        //Define a hook run before initializing the plugin
        do_action( 'native_custom_fields_before_init' );

        // Load all services
        add_action( 'plugins_loaded', [ $this, 'initPluginServices' ] );

        //Load all controllers
        add_action( 'init', [ $this, 'initPluginControllers' ], 5 );

        //Define a hook run after initializing the plugin
        do_action( 'native_custom_fields_after_init' );
	}


    /**
     * Initialize all services
     * @throws DependencyException
     * @throws NotFoundException
     * @throws Exception
     * @since 1.0.0
     */
    public function initPluginServices(): void
    {
        //Initialize all services
        foreach ( $this->services as $service ) {
            DI::container()->get( $service );
        }
    }

    /**
     * Initialize all controllers
     * @throws Exception
     * @throws DependencyException
     * @throws NotFoundException
     * @since 1.0.0
     */
    public function initPluginControllers(): void
    {
        //Initialize all controllers
        foreach ( $this->controllers as $controller ) {
            DI::container()->get( $controller );
        }
    }
}
