<?php
/**
 * Base class for Presentation layer
 * Contains all controllers
 * @since 1.0.0
 * @package NativeCustomFields
 * @subpackage Presentation
 */

namespace NativeCustomFields\Presentation;

defined( 'ABSPATH' ) || exit;

use Exception;
use NativeCustomFields\Common\DI;
use NativeCustomFields\Presentation\Admin\Controllers\AbilityContoller;
use NativeCustomFields\Presentation\Admin\Controllers\AdminController;
use NativeCustomFields\Presentation\Admin\Controllers\ImportExportController;
use NativeCustomFields\Presentation\Admin\Controllers\OptionsController;
use NativeCustomFields\Presentation\Admin\Controllers\PostMetaController;
use NativeCustomFields\Presentation\Admin\Controllers\TermMetaController;
use NativeCustomFields\Presentation\Admin\Controllers\UserMetaController;


final class ControllerInit {
	/**
	 * List of controllers to be initialized
	 * @var array
	 * @since 1.0.0
	 */
	private array $controllers = [
		AdminController::class,
		OptionsController::class,
		PostMetaController::class,
		TermMetaController::class,
		UserMetaController::class,
		ImportExportController::class,
		AbilityContoller::class
	];

	/**
	 * Initialize the program
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function __construct() {

        // Add filters to allow plugins to add their own controllers
        $this->controllers = apply_filters(
            'native_custom_fields_controllers',
            $this->controllers
        );

		// Initialize controllers
		$this->initControllers();
	}

	/**
	 * Initialize controllers
	 * @throws Exception
	 * @since 1.0.0
	 */
	public function initControllers() {
		foreach ( $this->controllers as $controller ) {
			DI::container()->get( $controller );
		}
	}
}
