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


    public function __construct()
    {
        // Add filters to allow plugins to add their own services
        $this->services = apply_filters(
            'native_custom_fields_services',
            $this->services
        );
    }

    /**
	 * Run all services and controllers
	 * @return void
	 * @since 1.0.0
	 */
	public function run(): void {
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
