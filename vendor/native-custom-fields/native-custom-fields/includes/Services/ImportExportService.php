<?php
/**
 * ImportExport service class
 * Responsible for handling import and export functionalities
 *
 * @package NativeCustomFields
 * @subpackage Services
 * @since 1.0.5
 */

namespace NativeCustomFields\Services;

use Exception;
use NativeCustomFields\Common\Helper;
use NativeCustomFields\Repositories\OptionRepository;
use NativeCustomFields\Repositories\PostMetaRepository;
use NativeCustomFields\Repositories\TermMetaRepository;
use NativeCustomFields\Repositories\UserMetaRepository;
use NativeCustomFields\Services\OptionService;
use NativeCustomFields\Services\PostMetaService;
use NativeCustomFields\Services\TermMetaService;
use NativeCustomFields\Services\UserMetaService;

defined( 'ABSPATH' ) || exit;

class ImportExportService {

	/**
	 * Builder state option prefixes by export data key.
	 * The builder screens load their form values from these options ({prefix}{slug}), not from the
	 * runtime configurations, so they are exported and imported alongside the configurations.
	 *
	 * @since 1.4.5
	 */
	private const BUILDER_STATE_PREFIXES = [
		'options_pages_config'       => 'native_custom_fields_options_page_builder_',
		'options_page_fields_config' => 'native_custom_fields_options_page_fields_builder_',
		'post_types_config'          => 'native_custom_fields_post_type_builder_',
		'post_meta_fields_config'    => 'native_custom_fields_post_meta_fields_builder_',
		'taxonomies_config'          => 'native_custom_fields_taxonomy_builder_',
		'term_meta_fields_config'    => 'native_custom_fields_term_meta_fields_builder_',
		'user_meta_fields_config'    => 'native_custom_fields_user_meta_fields_builder_',
	];

	/**
	 * @var OptionService
	 * @since 1.0.5
	 */
	private OptionService $optionService;

	/**
	 * @var OptionRepository
	 * @since 1.0.5
	 */
	private OptionRepository $optionRepository;

	/**
	 * @var PostMetaService
	 * @since 1.0.5
	 */
	private PostMetaService $postMetaService;

	/**
	 * @var PostMetaRepository
	 * @since 1.0.5
	 */
	private PostMetaRepository $postMetaRepository;

	/**
	 * @var TermMetaService
	 * @since 1.0.5
	 */
	private TermMetaService $termMetaService;

	/**
	 * @var TermMetaRepository
	 * @since 1.0.5
	 */
	private TermMetaRepository $termMetaRepository;

	/**
	 * @var UserMetaService
	 * @since 1.0.5
	 */
	private UserMetaService $userMetaService;

	/**
	 * @var UserMetaRepository
	 * @since 1.0.5
	 */
	private UserMetaRepository $userMetaRepository;

	public function __construct(
		OptionService $optionService, OptionRepository $optionRepository,
		PostMetaService $postMetaService, PostMetaRepository $postMetaRepository,
		TermMetaService $termMetaService, TermMetaRepository $termMetaRepository,
		UserMetaService $userMetaService, UserMetaRepository $userMetaRepository
	) {
		$this->optionService      = $optionService;
		$this->optionRepository   = $optionRepository;
		$this->postMetaService    = $postMetaService;
		$this->postMetaRepository = $postMetaRepository;
		$this->termMetaService    = $termMetaService;
		$this->termMetaRepository = $termMetaRepository;
		$this->userMetaService    = $userMetaService;
		$this->userMetaRepository = $userMetaRepository;
	}

	/**
	 * Import data from given array
	 *
	 * @param array $data
	 * @return bool
	 * @since 1.0.5
	 */
	public function importData( array $data ): bool {
		try {
			if ( array_key_exists( 'options_pages_config', $data ) ) {
				$this->importOptionsPages( $data['options_pages_config'] );
			}
			if ( array_key_exists( 'options_page_fields_config', $data ) ) {
				// Files exported before deleted pages were left out can still carry their fields. Skip the fields
				// of pages that are neither in the file nor on this site (the file's pages are imported above).
				$data['options_page_fields_config'] = $this->filterFieldConfigs( $data['options_page_fields_config'], $this->getOptionsPageExistsCallback() );
				$this->importOptionsPageFields( $data['options_page_fields_config'] );
			}
			if ( array_key_exists( 'post_types_config', $data ) ) {
				$this->importPostTypes( $data['post_types_config'] );
			}
			if ( array_key_exists( 'post_meta_fields_config', $data ) ) {
				$this->importPostMetaFields( $data['post_meta_fields_config'] );
			}
			if ( array_key_exists( 'taxonomies_config', $data ) ) {
				$this->importTaxonomies( $data['taxonomies_config'] );
			}
			if ( array_key_exists( 'term_meta_fields_config', $data ) ) {
				$this->importTermMetaFields( $data['term_meta_fields_config'] );
			}
			if ( array_key_exists( 'user_meta_fields_config', $data ) ) {
				$this->importUserMetaFields( $data['user_meta_fields_config'] );
			}
			$this->importBuilderStates( $data );
			return true;
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Export data from choices
	 *
	 * @param array $choices
	 * @return array
	 * @since 1.0.5
	 */
	public function exportData( array $choices ): array {

		if ( ! isset( $choices ) && $choices === [] ) {
			return [ 'success' => false, 'data' => [] ];
		}

		$options_pages_config = $options_page_fields_config = null;
		$post_types_config = $post_meta_fields_config = null;
		$taxonomies_config = $taxonomy_fields_config = null;
		$user_meta_fields_config = null;

		if ( in_array( 'options', $choices ) ) {
			$options_pages_config       = $this->optionRepository->getOptions( 'native_custom_fields_options_pages_config' );
			$options_page_fields_config = $this->optionRepository->getOptions( 'native_custom_fields_options_pages_fields_config' );
		}

		if ( in_array( 'post_meta', $choices ) ) {
			$post_types_config       = $this->optionRepository->getOptions( 'native_custom_fields_post_types_config' );
			$post_meta_fields_config = $this->optionRepository->getOptions( 'native_custom_fields_post_meta_fields_config' );
		}

		if ( in_array( 'term_meta', $choices ) ) {
			$taxonomies_config      = $this->optionRepository->getOptions( 'native_custom_fields_taxonomies_config' );
			$taxonomy_fields_config = $this->optionRepository->getOptions( 'native_custom_fields_term_meta_fields_config' );
		}

		if ( in_array( 'user_meta', $choices ) ) {
			$user_meta_fields_config = $this->optionRepository->getOptions( 'native_custom_fields_user_meta_fields_config' );
		}

		// Fields are exported even when no page, post type or taxonomy was created with the plugin,
		// since they can belong to ones registered by other plugins. Fields whose page, post type or
		// taxonomy no longer exists (deleted ones) are left out.
		$options_page_fields_config = $this->filterFieldConfigs( $options_page_fields_config, $this->getOptionsPageExistsCallback() );
		$post_meta_fields_config    = $this->filterFieldConfigs( $post_meta_fields_config, 'post_type_exists' );
		$taxonomy_fields_config     = $this->filterFieldConfigs( $taxonomy_fields_config, 'taxonomy_exists' );

		$data = [];

		if ( ! empty( $options_pages_config ) ) {
			$data['options_pages_config'] = $options_pages_config;
		}
		if ( ! empty( $options_page_fields_config ) ) {
			$data['options_page_fields_config'] = $options_page_fields_config;
		}
		if ( ! empty( $post_types_config ) ) {
			$data['post_types_config'] = $post_types_config;
		}
		if ( ! empty( $post_meta_fields_config ) ) {
			$data['post_meta_fields_config'] = $post_meta_fields_config;
		}
		if ( ! empty( $taxonomies_config ) ) {
			$data['taxonomies_config'] = $taxonomies_config;
		}
		if ( ! empty( $taxonomy_fields_config ) ) {
			$data['term_meta_fields_config'] = $taxonomy_fields_config;
		}
		if ( ! empty( $user_meta_fields_config ) ) {
			$data['user_meta_fields_config'] = $user_meta_fields_config;
		}

		$builder_states = $this->exportBuilderStates( $data );
		if ( ! empty( $builder_states ) ) {
			$data['builder_states'] = $builder_states;
		}

		return [ 'success' => true, 'data' => $data ];
	}

	/**
	 * Create PHP code string from exported data
	 *
	 * @param array $exportedData The "data" array returned by exportData()
	 * @return string PHP code
	 * @since 1.0.5
	 */
	public function createPhpFromExport( array $exportedData ): string {
		$uses   = [];
		$blocks = [];

		$toPhp = function ( $value ): string {
			return var_export( $value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Used intentionally to generate PHP array syntax for export feature, not for debugging.
		};

		$get = function ( $src, string $prop, $default = null ) {
			if ( is_array( $src ) && array_key_exists( $prop, $src ) ) {
				return $src[ $prop ];
			}
			if ( is_object( $src ) && isset( $src->$prop ) ) {
				return $src->$prop;
			}
			return $default;
		};

		$getKey = function ( $key, $cfg, string $fallbackProp ) use ( $get ): string {
			$slug = ( is_string( $key ) && $key !== '' ) ? $key : (string) $get( $cfg, $fallbackProp, '' );
			return sanitize_key( $slug );
		};

		$fnName = function ( string $prefix, string $slug ): string {
			$slug = sanitize_key( $slug );
			$slug = str_replace( '-', '_', $slug );
			return $prefix . '_' . $slug;
		};

		// OPTIONS PAGES
		if ( ! empty( $exportedData['options_pages_config'] ) ) {
			foreach ( $exportedData['options_pages_config'] as $slug => $cfg ) {
				$slug_key    = $getKey( $slug, $cfg, 'menu_slug' );
				$fn          = $fnName( 'native_custom_fields_add_options_page', $slug_key );
				$menu_title  = (string) $get( $cfg, 'menu_title', '' );
				$page_title  = (string) $get( $cfg, 'page_title', '' );
				$layout      = (string) $get( $cfg, 'layout', 'stacked' );
				$position    = $get( $cfg, 'position', null );
				$icon_url    = (string) $get( $cfg, 'icon_url', '' );
				$capability  = (string) $get( $cfg, 'capability', 'manage_options' );
				$parent_slug = (string) $get( $cfg, 'parent_slug', '' );

				$option_data = [ 'menu_title' => $menu_title, 'menu_slug' => $slug_key, 'page_title' => $page_title, 'layout' => $layout ];
				if ( null !== $position )      { $option_data['position']    = (int) $position; }
				if ( ! empty( $icon_url ) )    { $option_data['icon_url']    = $icon_url; }
				if ( ! empty( $capability ) )  { $option_data['capability']  = $capability; }
				if ( ! empty( $parent_slug ) ) { $option_data['parent_slug'] = $parent_slug; }

				$option_data_export = var_export( $option_data, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export

				$blocks[] = implode( "\n", [
					"function {$fn}(\$options_pages) {",
					"\t\$menu_slug = '{$slug_key}';",
					"\tif (\$options_pages && array_key_exists(\$menu_slug, \$options_pages)) { return \$options_pages; }",
					"\t\$options_pages[\$menu_slug] = {$option_data_export};",
					"\treturn \$options_pages;",
					"}",
					"add_filter('native_custom_fields_options_pages', '{$fn}');",
				] );
			}
		}

		// OPTIONS PAGE FIELDS
		if ( ! empty( $exportedData['options_page_fields_config'] ) ) {
			foreach ( $exportedData['options_page_fields_config'] as $slug => $cfg ) {
				$slug_key      = $getKey( $slug, $cfg, 'menu_slug' );
				$fn            = $fnName( 'native_custom_fields_add_options_page_fields_config', $slug_key );
				$sections      = $get( $cfg, 'sections', [] );
				$sections_code = $toPhp( $sections );
				$blocks[]      = implode( "\n", [
					"function {$fn}(array \$fields_config, string \$menu_slug): array {",
					"\tif ('{$slug_key}' !== \$menu_slug) { return \$fields_config; }",
					"\treturn {$sections_code};",
					"}",
					"add_filter('native_custom_fields_options_page_fields', '{$fn}', 10, 2);",
				] );
			}
		}

		// POST TYPES
		if ( ! empty( $exportedData['post_types_config'] ) ) {
			foreach ( $exportedData['post_types_config'] as $slug => $cfg ) {
				$slug_key = $getKey( $slug, $cfg, 'post_type' );
				$fn       = $fnName( 'native_custom_fields_add_post_type', $slug_key );
				$blocks[] = implode( "\n", [
					"function {$fn}(array \$post_types): array {",
					"\t\$post_type_slug = '{$slug_key}';",
					"\tif (\$post_types && array_key_exists(\$post_type_slug, \$post_types)) { return \$post_types; }",
					"\t\$post_types[\$post_type_slug] = " . var_export( $cfg, true ) . ";", // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
					"\treturn \$post_types;",
					"}",
					"add_filter('native_custom_fields_post_types', '{$fn}');",
				] );
			}
		}

		// POST META FIELDS
		if ( ! empty( $exportedData['post_meta_fields_config'] ) ) {
			foreach ( $exportedData['post_meta_fields_config'] as $slug => $cfg ) {
				$slug_key      = $getKey( $slug, $cfg, 'post_type' );
				$fn            = $fnName( 'native_custom_fields_add_post_meta_fields', $slug_key );
				$sections      = $get( $cfg, 'sections', [] );
				$config_export = $toPhp( [ 'post_type' => $slug_key, 'sections' => $sections ] );
				$blocks[]      = implode( "\n", [
					"function {$fn}( array \$post_meta_fields) : array {",
					"\t\$post_type_slug = '{$slug_key}';",
					"\tif(isset(\$post_meta_fields[\$post_type_slug])){ return \$post_meta_fields; }",
					"\t\$post_meta_fields[\$post_type_slug] = {$config_export};",
					"\treturn \$post_meta_fields;",
					"}",
					"add_filter('native_custom_fields_post_meta_fields', '{$fn}');",
				] );
			}
		}

		// TAXONOMIES
		if ( ! empty( $exportedData['taxonomies_config'] ) ) {
			foreach ( $exportedData['taxonomies_config'] as $taxonomy => $cfg ) {
				$taxonomy_key = $getKey( $taxonomy, $cfg, 'taxonomy' );
				$fn           = $fnName( 'native_custom_fields_add_taxonomy', $taxonomy_key );
				$blocks[]     = implode( "\n", [
					"function {$fn}(array \$taxonomies): array {",
					"\t\$taxonomy_slug = '{$taxonomy_key}';",
					"\tif (\$taxonomies && array_key_exists(\$taxonomy_slug, \$taxonomies)) { return \$taxonomies; }",
					"\t\$taxonomies[\$taxonomy_slug] = " . var_export( $cfg, true ) . ";", // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
					"\treturn \$taxonomies;",
					"}",
					"add_filter('native_custom_fields_taxonomies', '{$fn}');",
				] );
			}
		}

		// TERM META FIELDS
		if ( ! empty( $exportedData['term_meta_fields_config'] ) ) {
			foreach ( $exportedData['term_meta_fields_config'] as $taxonomy => $cfg ) {
				$taxonomy_key  = $getKey( $taxonomy, $cfg, 'taxonomy' );
				$fn            = $fnName( 'native_custom_fields_add_term_meta_fields', $taxonomy_key );
				$sections      = $get( $cfg, 'sections', [] );
				$config_export = $toPhp( [ 'taxonomy' => $taxonomy_key, 'sections' => $sections ] );
				$blocks[]      = implode( "\n", [
					"function {$fn}( array \$term_meta_fields) : array {",
					"\t\$taxonomy_slug = '{$taxonomy_key}';",
					"\tif(isset(\$term_meta_fields[\$taxonomy_slug])){ return \$term_meta_fields; }",
					"\t\$term_meta_fields[\$taxonomy_slug] = {$config_export};",
					"\treturn \$term_meta_fields;",
					"}",
					"add_filter('native_custom_fields_term_meta_fields', '{$fn}');",
				] );
			}
		}

		// USER META FIELDS
		if ( ! empty( $exportedData['user_meta_fields_config'] ) ) {
			foreach ( $exportedData['user_meta_fields_config'] as $key => $cfg ) {
				$slug_key      = $getKey( $key, $cfg, 'user_role' );
				$fn            = $fnName( 'native_custom_fields_add_user_meta_fields', $slug_key );
				$sections      = $get( $cfg, 'sections', [] );
				$config_export = $toPhp( [ 'user_role' => $slug_key, 'sections' => $sections ] );
				$blocks[]      = implode( "\n", [
					"function {$fn}( array \$user_meta_fields) : array {",
					"\t\$user_role_slug = '{$slug_key}';",
					"\tif(isset(\$user_meta_fields[\$user_role_slug])){ return \$user_meta_fields; }",
					"\t\$user_meta_fields[\$user_role_slug] = {$config_export};",
					"\treturn \$user_meta_fields;",
					"}",
					"add_filter('native_custom_fields_user_meta_fields', '{$fn}');",
				] );
			}
		}

		$header = [
			"<?php",
			"/**",
			" * Auto-generated by Native Custom Fields Create PHP",
			" * Date: " . current_time( 'mysql' ),
			" */",
		];

		$uses_lines = [];
		foreach ( array_keys( $uses ) as $fqcn ) {
			$uses_lines[] = 'use ' . $fqcn . ';';
		}

		$body = implode( "\n\n", array_filter( $blocks ) );

		return implode( "\n", array_filter( [
			implode( "\n", $header ),
			! empty( $uses_lines ) ? implode( "\n", $uses_lines ) : '',
			$body,
		] ) ) . "\n";
	}

	/**
	 * @param array $options_pages_config
	 * @return void
	 * @since 1.0.5
	 */
	private function importOptionsPages( array $options_pages_config ): void {
		foreach ( $options_pages_config as $key => $item ) {
			$get_config         = $this->optionService->getOptionsPagesConfigurations();
			$get_config[ $key ] = $item;
			$this->optionRepository->saveConfigurations( $get_config, 'native_custom_fields_options_pages_config' );
		}
	}

	/**
	 * @param array $options_page_fields_config
	 * @return void
	 * @throws Exception
	 * @since 1.0.5
	 */
	private function importOptionsPageFields( array $options_page_fields_config ): void {
		foreach ( $options_page_fields_config as $key => $item ) {
			$fields_config_array = [ 'menu_slug' => $key, 'sections' => $item['sections'] ];
			$get_config          = $this->optionService->getOptionsPagesFieldsConfigurations();
			$get_config[ $key ]  = $fields_config_array;
			$this->optionRepository->saveConfigurations( $get_config, 'native_custom_fields_options_pages_fields_config' );
		}
	}

	/**
	 * @param array $post_types_config
	 * @return void
	 * @throws Exception
	 * @since 1.0.5
	 */
	private function importPostTypes( array $post_types_config ): void {
		foreach ( $post_types_config as $key => $item ) {
			$get_config         = $this->postMetaService->getPostTypesConfigurations();
			$get_config[ $key ] = $item;
			$this->postMetaRepository->saveConfigurations( $get_config, 'native_custom_fields_post_types_config' );
		}
	}

	/**
	 * @param array $post_meta_fields_config
	 * @return void
	 * @throws Exception
	 * @since 1.0.5
	 */
	private function importPostMetaFields( array $post_meta_fields_config ): void {
		foreach ( $post_meta_fields_config as $key => $item ) {
			$get_config         = $this->postMetaService->getPostMetaFieldsConfigurations();
			$config_array       = [ 'post_type' => sanitize_key( $key ), 'sections' => $item['sections'] ];
			$get_config[ $key ] = $config_array;
			$this->postMetaRepository->saveConfigurations( $get_config, 'native_custom_fields_post_meta_fields_config' );
		}
	}

	/**
	 * @param array $taxonomies_config
	 * @return void
	 * @throws Exception
	 * @since 1.0.5
	 */
	private function importTaxonomies( array $taxonomies_config ): void {
		foreach ( $taxonomies_config as $key => $item ) {
			$get_config         = $this->termMetaService->getTaxonomyConfigurations();
			$get_config[ $key ] = $item;
			$this->termMetaRepository->saveConfigurations( $get_config, 'native_custom_fields_taxonomies_config' );
		}
	}

	/**
	 * @param array $term_meta_fields_config
	 * @return void
	 * @throws Exception
	 * @since 1.0.5
	 */
	private function importTermMetaFields( array $term_meta_fields_config ): void {
		foreach ( $term_meta_fields_config as $key => $item ) {
			$get_config         = $this->termMetaService->getTermMetaFieldsConfigurations();
			$config_array       = [ 'taxonomy' => $key, 'sections' => $item['sections'] ];
			$get_config[ $key ] = $config_array;
			$this->termMetaRepository->saveConfigurations( $get_config, 'native_custom_fields_term_meta_fields_config' );
		}
	}

	/**
	 * @param array $user_meta_fields_config
	 * @return void
	 * @throws Exception
	 * @since 1.0.5
	 */
	private function importUserMetaFields( array $user_meta_fields_config ): void {
		foreach ( $user_meta_fields_config as $key => $item ) {
			$get_config         = $this->userMetaService->getUserMetaFieldsConfigurations();
			$config_array       = [ 'user_role' => $key, 'sections' => $item['sections'] ];
			$get_config[ $key ] = $config_array;
			$this->userMetaRepository->saveConfigurations( $get_config, 'native_custom_fields_user_meta_fields_config' );
		}
	}

	/**
	 * Keep the field configurations whose page, post type or taxonomy passes the given check
	 *
	 * @param mixed $field_configs Field configurations keyed by slug
	 * @param callable $exists Receives the slug, returns whether it still exists
	 * @return array
	 * @since 1.4.5
	 */
	private function filterFieldConfigs( $field_configs, callable $exists ): array {
		if ( empty( $field_configs ) || ! is_array( $field_configs ) ) {
			return [];
		}

		return array_filter( $field_configs, function ( $slug ) use ( $exists ): bool {
			return (bool) call_user_func( $exists, (string) $slug );
		}, ARRAY_FILTER_USE_KEY );
	}

	/**
	 * Get a check for whether an options page exists, stored or added through the options pages filter
	 *
	 * @return callable
	 * @since 1.4.5
	 */
	private function getOptionsPageExistsCallback(): callable {
		$menu_slugs = array_map( 'strval', array_keys( $this->optionService->getOptionsPagesConfigurationsFiltered() ) );

		return function ( string $menu_slug ) use ( $menu_slugs ): bool {
			return in_array( $menu_slug, $menu_slugs, true );
		};
	}

	/**
	 * Collect the builder states of the exported configurations
	 *
	 * @param array $data Exported configurations keyed by export data key
	 * @return array Builder states keyed by option name
	 * @since 1.4.5
	 */
	private function exportBuilderStates( array $data ): array {
		$builder_states = [];

		foreach ( self::BUILDER_STATE_PREFIXES as $config_key => $prefix ) {
			if ( empty( $data[ $config_key ] ) || ! is_array( $data[ $config_key ] ) ) {
				continue;
			}

			foreach ( array_keys( $data[ $config_key ] ) as $slug ) {
				$option_name = $prefix . $slug;
				$state       = $this->optionRepository->getOptions( $option_name );

				if ( ! empty( $state ) && is_array( $state ) ) {
					$builder_states[ $option_name ] = $state;
				}
			}
		}

		return $builder_states;
	}

	/**
	 * Write the builder state of every imported configuration, so the builder screens can load it.
	 * Uses the exported builder state when the file has one, otherwise rebuilds it from the configuration
	 * (exports made before builder states were exported, or configurations created without the builder).
	 * Only option names derived from the imported configurations are written.
	 *
	 * @param array $data Import data
	 * @return void
	 * @throws Exception
	 * @since 1.4.5
	 */
	private function importBuilderStates( array $data ): void {
		$imported_states = is_array( $data['builder_states'] ?? null ) ? $data['builder_states'] : [];

		foreach ( self::BUILDER_STATE_PREFIXES as $config_key => $prefix ) {
			if ( empty( $data[ $config_key ] ) || ! is_array( $data[ $config_key ] ) ) {
				continue;
			}

			foreach ( $data[ $config_key ] as $slug => $item ) {
				$slug = (string) $slug;

				if ( ! is_array( $item ) || $slug === '' || sanitize_key( $slug ) !== $slug ) {
					continue;
				}

				$option_name = $prefix . $slug;

				if ( ! empty( $imported_states[ $option_name ] ) && is_array( $imported_states[ $option_name ] ) ) {
					$values = Helper::sanitizeBuilderState( $imported_states[ $option_name ] );
				} else {
					$values = $this->buildBuilderState( $config_key, $slug, $item );
				}

				if ( ! empty( $values ) ) {
					$this->optionService->saveOptions( $option_name, $values );
				}
			}
		}
	}

	/**
	 * Rebuild the builder state of a configuration
	 *
	 * @param string $config_key Export data key
	 * @param string $slug Configuration slug (menu slug, post type, taxonomy or user role)
	 * @param array $item Configuration
	 * @return array
	 * @since 1.4.5
	 */
	private function buildBuilderState( string $config_key, string $slug, array $item ): array {
		$sections = is_array( $item['sections'] ?? null ) ? $item['sections'] : [];

		switch ( $config_key ) {
			case 'options_pages_config':
				return $this->optionService->buildOptionsPageBuilderValues( $slug, $item );
			case 'options_page_fields_config':
				return [
					'menu_slug'              => $slug,
					'sections_or_meta_boxes' => Helper::sectionsToBuilderSections( $sections, 'section' ),
				];
			case 'post_types_config':
				return $this->postMetaService->buildPostTypeBuilderValues( array_merge( [ 'post_type' => $slug ], $item ) );
			case 'post_meta_fields_config':
				return [
					'post_type'              => $slug,
					'sections_or_meta_boxes' => Helper::sectionsToBuilderSections( $sections, 'meta_box' ),
				];
			case 'taxonomies_config':
				return $this->termMetaService->buildTaxonomyBuilderValues( array_merge( [ 'taxonomy' => $slug ], $item ) );
			case 'term_meta_fields_config':
				return [
					'taxonomy'               => $slug,
					'sections_or_meta_boxes' => Helper::sectionsToBuilderSections( $sections, 'section' ),
				];
			case 'user_meta_fields_config':
				return [
					'sections_or_meta_boxes' => Helper::sectionsToBuilderSections( $sections, 'section' ),
				];
			default:
				return [];
		}
	}
}

