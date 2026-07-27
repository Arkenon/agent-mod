<?php

/**
 * Site management ability registrar.
 *
 * Registers the abilities that let an agent manage the WordPress installation
 * itself: plugins, themes, settings/options, system status and users. These are
 * native equivalents of the corresponding WP-CLI command families, built on the
 * same core APIs WP-CLI delegates to — no shell access is involved.
 *
 * This class only declares schemas and wires callbacks; all behaviour lives in
 * the Services\SiteManagement managers.
 *
 * @package AgentMod
 * @subpackage Services
 * @since 1.1.0
 */

namespace AgentMod\Services;

use AgentMod\Services\SiteManagement\OptionManager;
use AgentMod\Services\SiteManagement\PluginManager;
use AgentMod\Services\SiteManagement\SystemManager;
use AgentMod\Services\SiteManagement\ThemeManager;
use AgentMod\Services\SiteManagement\UserManager;

defined('ABSPATH') || exit;

class SiteManagementAbilityService
{
	/**
	 * Ability category slug for site management abilities.
	 *
	 * Kept separate from the main 'agent-mod' category so site owners can spot
	 * and allow-list these higher-impact abilities as a group.
	 *
	 * @var string
	 * @since 1.1.0
	 */
	private const CATEGORY = 'agent-mod-site';

	/**
	 * Abilities that must be confirmed by the user before they run.
	 *
	 * Read-only abilities and reversible maintenance tasks are absent by design.
	 *
	 * @var string[]
	 * @since 1.1.0
	 */
	private const CONFIRM_REQUIRED = [
		'agent-mod/install-plugin',
		'agent-mod/upload-plugin',
		'agent-mod/manage-plugin',
		'agent-mod/remove-plugin',
		'agent-mod/install-theme',
		'agent-mod/upload-theme',
		'agent-mod/manage-theme',
		'agent-mod/remove-theme',
		'agent-mod/update-setting',
		'agent-mod/update-option',
		'agent-mod/manage-user',
		'agent-mod/remove-user',
	];

	/**
	 * Plugin manager.
	 *
	 * @var PluginManager
	 * @since 1.1.0
	 */
	private PluginManager $plugins;

	/**
	 * Theme manager.
	 *
	 * @var ThemeManager
	 * @since 1.1.0
	 */
	private ThemeManager $themes;

	/**
	 * Option manager.
	 *
	 * @var OptionManager
	 * @since 1.1.0
	 */
	private OptionManager $options;

	/**
	 * System manager.
	 *
	 * @var SystemManager
	 * @since 1.1.0
	 */
	private SystemManager $system;

	/**
	 * User manager.
	 *
	 * @var UserManager
	 * @since 1.1.0
	 */
	private UserManager $users;

	/**
	 * Constructor. Binds the abilities API hooks.
	 *
	 * @param PluginManager $plugins Plugin manager.
	 * @param ThemeManager  $themes  Theme manager.
	 * @param OptionManager $options Option manager.
	 * @param SystemManager $system  System manager.
	 * @param UserManager   $users   User manager.
	 *
	 * @since 1.1.0
	 */
	public function __construct(
		PluginManager $plugins,
		ThemeManager $themes,
		OptionManager $options,
		SystemManager $system,
		UserManager $users
	) {
		$this->plugins = $plugins;
		$this->themes  = $themes;
		$this->options = $options;
		$this->system  = $system;
		$this->users   = $users;

		add_action('wp_abilities_api_categories_init', [$this, 'registerCategories']);
		add_action('wp_abilities_api_init', [$this, 'registerAbilities']);

		add_filter('agent_mod_ability_requires_confirmation', [$this, 'requiresConfirmation'], 10, 2);
	}

	/**
	 * Registers the site management ability category.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function registerCategories(): void
	{
		if (! function_exists('wp_register_ability_category')) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __('AgentMod — Site Management', 'agent-mod'),
				'description' => __('Abilities for managing the WordPress installation: plugins, themes, settings, system status and users.', 'agent-mod'),
			]
		);
	}

	/**
	 * Registers every site management ability.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function registerAbilities(): void
	{
		if (! function_exists('wp_register_ability')) {
			return;
		}

		$this->registerPluginAbilities();
		$this->registerThemeAbilities();
		$this->registerOptionAbilities();
		$this->registerSystemAbilities();
		$this->registerUserAbilities();
	}

	/**
	 * Marks the write abilities in this group as requiring confirmation.
	 *
	 * @param bool   $requires Current value.
	 * @param string $name     Ability name.
	 *
	 * @return bool
	 * @since 1.1.0
	 */
	public function requiresConfirmation(bool $requires, string $name): bool
	{
		return in_array($name, self::CONFIRM_REQUIRED, true) ? true : $requires;
	}

	// =========================================================================
	// Plugins — equivalents of the `wp plugin` command family
	// =========================================================================

	/**
	 * Registers the plugin management abilities.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	private function registerPluginAbilities(): void
	{
		wp_register_ability(
			'agent-mod/get-plugins',
			[
				'label'               => __('Get Plugins', 'agent-mod'),
				'description'         => __('Lists the plugins installed on this site with their version, activation status and whether an update is available. Use this before activating, updating or removing a plugin to find its exact slug.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => function ($input = null) {
					return $this->plugins->listPlugins($this->toArray($input));
				},
				'permission_callback' => static function (): bool {
					return current_user_can('activate_plugins');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'search' => [
							'type'        => 'string',
							'description' => __('Optional. Filters results by plugin name or slug.', 'agent-mod'),
						],
						'status' => [
							'type'        => 'string',
							'enum'        => ['all', 'active', 'inactive', 'network-active', 'update-available'],
							'default'     => 'all',
							'description' => __('Optional. Filters results by activation or update status.', 'agent-mod'),
						],
					],
					// Every property is optional, so a call with no arguments is
					// valid. WP_Ability only maps a null input onto this default.
					'default'    => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'plugins'   => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'slug'             => ['type' => 'string'],
									'plugin_file'      => ['type' => 'string'],
									'name'             => ['type' => 'string'],
									'version'          => ['type' => 'string'],
									'status'           => ['type' => 'string'],
									'update_available' => ['type' => 'boolean'],
									'new_version'      => ['type' => 'string'],
								],
							],
						],
						'total'     => ['type' => 'integer'],
						'truncated' => ['type' => 'boolean'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/get-plugin-by-slug',
			[
				'label'               => __('Get Plugin By Slug', 'agent-mod'),
				'description'         => __('Returns detailed information about a single plugin: its locally installed state (if any) and its WordPress.org directory listing, including version, requirements and rating. Works for plugins that are not installed yet.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => function ($input = null) {
					$input = $this->toArray($input);

					return $this->plugins->getPlugin((string) ($input['slug'] ?? ''));
				},
				'permission_callback' => static function (): bool {
					return current_user_can('activate_plugins');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'slug' => [
							'type'        => 'string',
							'description' => __('The plugin slug, e.g. "akismet". The plugin folder name is also accepted.', 'agent-mod'),
						],
					],
					'required'   => ['slug'],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'slug'      => ['type' => 'string'],
						'installed' => ['type' => 'boolean'],
						'local'     => ['type' => ['object', 'null']],
						'directory' => ['type' => ['object', 'null']],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/search-plugin-directory',
			[
				'label'               => __('Search Plugin Directory', 'agent-mod'),
				'description'         => __('Searches the public WordPress.org plugin directory. Use this to find a plugin to install when the user describes what they need rather than naming a slug.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => function ($input = null) {
					return $this->plugins->searchDirectory($this->toArray($input));
				},
				'permission_callback' => static function (): bool {
					return current_user_can('install_plugins');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'search' => [
							'type'        => 'string',
							'description' => __('Search term, e.g. "contact form" or "seo".', 'agent-mod'),
						],
					],
					'required'   => ['search'],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'query'   => ['type' => 'string'],
						'results' => ['type' => 'array'],
						'total'   => ['type' => 'integer'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/install-plugin',
			[
				'label'               => __('Install Plugin', 'agent-mod'),
				'description'         => __('Downloads and installs a plugin from the WordPress.org directory, optionally activating it. Requires user confirmation. Fails if the plugin is already installed — use manage-plugin to activate or update an installed plugin instead.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => function ($input = null) {
					$input = $this->toArray($input);

					return $this->plugins->install(
						(string) ($input['slug'] ?? ''),
						! empty($input['activate'])
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can('install_plugins');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'slug'     => [
							'type'        => 'string',
							'description' => __('The WordPress.org plugin slug, e.g. "wordpress-seo".', 'agent-mod'),
						],
						'activate' => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => __('Whether to activate the plugin immediately after installing it.', 'agent-mod'),
						],
					],
					'required'   => ['slug'],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'     => ['type' => 'boolean'],
						'plugin_file' => ['type' => 'string'],
						'name'        => ['type' => 'string'],
						'version'     => ['type' => 'string'],
						'activated'   => ['type' => 'boolean'],
						'message'     => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/upload-plugin',
			[
				'label'               => __('Upload Plugin', 'agent-mod'),
				'description'         => __('Installs a plugin from a .zip archive that is not in the WordPress.org directory, either from an https:// URL or from a .zip already uploaded to the media library. Requires user confirmation.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => function ($input = null) {
					return $this->plugins->upload($this->toArray($input));
				},
				'permission_callback' => static function (): bool {
					return current_user_can('upload_plugins');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'url'           => [
							'type'        => 'string',
							'description' => __('An https:// URL pointing at the plugin .zip archive.', 'agent-mod'),
						],
						'attachment_id' => [
							'type'        => 'integer',
							'description' => __('The media library attachment ID of an already uploaded .zip archive. Takes precedence over url.', 'agent-mod'),
						],
						'activate'      => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => __('Whether to activate the plugin immediately after installing it.', 'agent-mod'),
						],
					],
					// url and attachment_id are alternatives rather than both
					// required, so the choice is validated in PluginManager.
					'default'    => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'     => ['type' => 'boolean'],
						'plugin_file' => ['type' => 'string'],
						'name'        => ['type' => 'string'],
						'version'     => ['type' => 'string'],
						'activated'   => ['type' => 'boolean'],
						'message'     => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/manage-plugin',
			[
				'label'               => __('Manage Plugin', 'agent-mod'),
				'description'         => __('Activates, deactivates or updates a plugin that is already installed. Requires user confirmation. Note that activating a plugin loads its code immediately.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => function ($input = null) {
					$input = $this->toArray($input);

					return $this->plugins->manage(
						(string) ($input['slug'] ?? ''),
						(string) ($input['action'] ?? '')
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can('activate_plugins');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'slug'   => [
							'type'        => 'string',
							'description' => __('The slug of an installed plugin, e.g. "akismet".', 'agent-mod'),
						],
						'action' => [
							'type'        => 'string',
							'enum'        => ['activate', 'deactivate', 'update'],
							'description' => __('The operation to perform on the plugin.', 'agent-mod'),
						],
					],
					'required'   => ['slug', 'action'],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'     => ['type' => 'boolean'],
						'plugin_file' => ['type' => 'string'],
						'name'        => ['type' => 'string'],
						'version'     => ['type' => 'string'],
						'message'     => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/remove-plugin',
			[
				'label'               => __('Remove Plugin', 'agent-mod'),
				'description'         => __('Deactivates and permanently deletes an installed plugin, running its uninstall routine — this usually deletes the plugin\'s stored data as well. This cannot be undone and requires user confirmation.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => function ($input = null) {
					$input = $this->toArray($input);

					return $this->plugins->remove((string) ($input['slug'] ?? ''));
				},
				'permission_callback' => static function (): bool {
					return current_user_can('delete_plugins');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'slug' => [
							'type'        => 'string',
							'description' => __('The slug of the installed plugin to delete, e.g. "hello-dolly".', 'agent-mod'),
						],
					],
					'required'   => ['slug'],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'     => ['type' => 'boolean'],
						'plugin_file' => ['type' => 'string'],
						'name'        => ['type' => 'string'],
						'message'     => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
					'show_in_rest' => true,
				],
			]
		);
	}

	// =========================================================================
	// Themes — equivalents of the `wp theme` command family
	// =========================================================================

	/**
	 * Registers the theme management abilities.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	private function registerThemeAbilities(): void
	{
		wp_register_ability(
			'agent-mod/get-themes',
			[
				'label'               => __('Get Themes', 'agent-mod'),
				'description'         => __('Lists the themes installed on this site with their version, whether each is the active theme, its parent theme and whether an update is available. Use this to find a theme\'s exact slug before activating, updating or removing it.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => function ($input = null) {
					return $this->themes->listThemes($this->toArray($input));
				},
				'permission_callback' => static function (): bool {
					return current_user_can('switch_themes');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'search' => [
							'type'        => 'string',
							'description' => __('Optional. Filters results by theme name or slug.', 'agent-mod'),
						],
						'status' => [
							'type'        => 'string',
							'enum'        => ['all', 'active', 'inactive', 'update-available'],
							'default'     => 'all',
							'description' => __('Optional. Filters results by activation or update status.', 'agent-mod'),
						],
					],
					'default'    => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'themes'    => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'slug'             => ['type' => 'string'],
									'name'             => ['type' => 'string'],
									'version'          => ['type' => 'string'],
									'status'           => ['type' => 'string'],
									'parent'           => ['type' => 'string'],
									'update_available' => ['type' => 'boolean'],
									'new_version'      => ['type' => 'string'],
								],
							],
						],
						'total'     => ['type' => 'integer'],
						'truncated' => ['type' => 'boolean'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/get-theme-by-slug',
			[
				'label'               => __('Get Theme By Slug', 'agent-mod'),
				'description'         => __('Returns detailed information about a single theme: its locally installed state (if any), whether it is a block theme, and its WordPress.org directory listing. Works for themes that are not installed yet.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => function ($input = null) {
					$input = $this->toArray($input);

					return $this->themes->getTheme((string) ($input['slug'] ?? ''));
				},
				'permission_callback' => static function (): bool {
					return current_user_can('switch_themes');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'slug' => [
							'type'        => 'string',
							'description' => __('The theme directory slug, e.g. "twentytwentyfour". The theme display name is also accepted.', 'agent-mod'),
						],
					],
					'required'   => ['slug'],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'slug'      => ['type' => 'string'],
						'installed' => ['type' => 'boolean'],
						'local'     => ['type' => ['object', 'null']],
						'directory' => ['type' => ['object', 'null']],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/search-theme-directory',
			[
				'label'               => __('Search Theme Directory', 'agent-mod'),
				'description'         => __('Searches the public WordPress.org theme directory. Use this to find a theme to install when the user describes the look they want rather than naming a slug.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => function ($input = null) {
					return $this->themes->searchDirectory($this->toArray($input));
				},
				'permission_callback' => static function (): bool {
					return current_user_can('install_themes');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'search' => [
							'type'        => 'string',
							'description' => __('Search term, e.g. "portfolio" or "magazine".', 'agent-mod'),
						],
					],
					'required'   => ['search'],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'query'   => ['type' => 'string'],
						'results' => ['type' => 'array'],
						'total'   => ['type' => 'integer'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/install-theme',
			[
				'label'               => __('Install Theme', 'agent-mod'),
				'description'         => __('Downloads and installs a theme from the WordPress.org directory, optionally switching the site to it. Requires user confirmation. Fails if the theme is already installed — use manage-theme to activate or update an installed theme instead.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => function ($input = null) {
					$input = $this->toArray($input);

					return $this->themes->install(
						(string) ($input['slug'] ?? ''),
						! empty($input['activate'])
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can('install_themes');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'slug'     => [
							'type'        => 'string',
							'description' => __('The WordPress.org theme slug, e.g. "twentytwentyfive".', 'agent-mod'),
						],
						'activate' => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => __('Whether to switch the site to this theme immediately after installing it. This changes the public appearance of the site.', 'agent-mod'),
						],
					],
					'required'   => ['slug'],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'   => ['type' => 'boolean'],
						'slug'      => ['type' => 'string'],
						'name'      => ['type' => 'string'],
						'version'   => ['type' => 'string'],
						'activated' => ['type' => 'boolean'],
						'message'   => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/upload-theme',
			[
				'label'               => __('Upload Theme', 'agent-mod'),
				'description'         => __('Installs a theme from a .zip archive that is not in the WordPress.org directory, either from an https:// URL or from a .zip already uploaded to the media library. Requires user confirmation.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => function ($input = null) {
					return $this->themes->upload($this->toArray($input));
				},
				'permission_callback' => static function (): bool {
					return current_user_can('upload_themes');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'url'           => [
							'type'        => 'string',
							'description' => __('An https:// URL pointing at the theme .zip archive.', 'agent-mod'),
						],
						'attachment_id' => [
							'type'        => 'integer',
							'description' => __('The media library attachment ID of an already uploaded .zip archive. Takes precedence over url.', 'agent-mod'),
						],
						'activate'      => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => __('Whether to switch the site to this theme immediately after installing it.', 'agent-mod'),
						],
					],
					'default'    => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'   => ['type' => 'boolean'],
						'slug'      => ['type' => 'string'],
						'name'      => ['type' => 'string'],
						'version'   => ['type' => 'string'],
						'activated' => ['type' => 'boolean'],
						'message'   => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/manage-theme',
			[
				'label'               => __('Manage Theme', 'agent-mod'),
				'description'         => __('Activates or updates a theme that is already installed. Activating a theme changes the public appearance of the whole site. Requires user confirmation. Themes cannot be deactivated — a site always has exactly one active theme.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => function ($input = null) {
					$input = $this->toArray($input);

					return $this->themes->manage(
						(string) ($input['slug'] ?? ''),
						(string) ($input['action'] ?? '')
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can('switch_themes');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'slug'   => [
							'type'        => 'string',
							'description' => __('The slug of an installed theme, e.g. "twentytwentyfour".', 'agent-mod'),
						],
						'action' => [
							'type'        => 'string',
							'enum'        => ['activate', 'update'],
							'description' => __('The operation to perform on the theme.', 'agent-mod'),
						],
					],
					'required'   => ['slug', 'action'],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success' => ['type' => 'boolean'],
						'slug'    => ['type' => 'string'],
						'name'    => ['type' => 'string'],
						'version' => ['type' => 'string'],
						'message' => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/remove-theme',
			[
				'label'               => __('Remove Theme', 'agent-mod'),
				'description'         => __('Permanently deletes an installed theme and its files. The active theme, the parent of an active child theme and the default fallback theme cannot be deleted. This cannot be undone and requires user confirmation.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => function ($input = null) {
					$input = $this->toArray($input);

					return $this->themes->remove((string) ($input['slug'] ?? ''));
				},
				'permission_callback' => static function (): bool {
					return current_user_can('delete_themes');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'slug' => [
							'type'        => 'string',
							'description' => __('The slug of the installed theme to delete.', 'agent-mod'),
						],
					],
					'required'   => ['slug'],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success' => ['type' => 'boolean'],
						'slug'    => ['type' => 'string'],
						'name'    => ['type' => 'string'],
						'message' => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
					'show_in_rest' => true,
				],
			]
		);
	}

	// =========================================================================
	// Settings and options — equivalents of the `wp option` command family
	// =========================================================================

	/**
	 * Registers the settings and option abilities.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	private function registerOptionAbilities(): void
	{
		wp_register_ability(
			'agent-mod/get-settings',
			[
				'label'               => __('Get Settings', 'agent-mod'),
				'description'         => __('Lists WordPress\' registered settings — everything behind Settings → General, Writing, Reading and Discussion, plus settings registered by plugins — with each one\'s current value, declared type, description and whether it can be changed. Use this before update-setting to learn the exact setting name and the value type it expects.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => function ($input = null) {
					return $this->options->getSettings($this->toArray($input));
				},
				'permission_callback' => static function (): bool {
					return current_user_can('manage_options');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'group'  => [
							'type'        => 'string',
							'description' => __('Optional. Restricts results to one settings group, e.g. "general", "writing", "reading" or "discussion".', 'agent-mod'),
						],
						'search' => [
							'type'        => 'string',
							'description' => __('Optional. Filters results by setting name or label.', 'agent-mod'),
						],
					],
					'default'    => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'settings' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'name'        => ['type' => 'string'],
									'group'       => ['type' => 'string'],
									'label'       => ['type' => 'string'],
									'description' => ['type' => 'string'],
									'type'        => ['type' => 'string'],
									'writable'    => ['type' => 'boolean'],
								],
							],
						],
						'groups'   => ['type' => 'array', 'items' => ['type' => 'string']],
						'total'    => ['type' => 'integer'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/update-setting',
			[
				'label'               => __('Update Setting', 'agent-mod'),
				'description'         => __('Changes one of WordPress\' registered settings, for example the site title, tagline, date format, posts per page or comment defaults. The new value is validated against the setting\'s declared type before it is saved. Requires user confirmation. Call get-settings first to confirm the exact name and expected type.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => function ($input = null) {
					$input = $this->toArray($input);

					return $this->options->updateSetting(
						(string) ($input['name'] ?? ''),
						$input['value'] ?? null
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can('manage_options');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'name'  => [
							'type'        => 'string',
							'description' => __('The registered setting name, e.g. "blogname", "blogdescription" or "posts_per_page".', 'agent-mod'),
						],
						'value' => [
							'type'        => ['string', 'number', 'integer', 'boolean', 'array', 'object', 'null'],
							'description' => __('The new value. It must match the type the setting declares.', 'agent-mod'),
						],
					],
					'required'   => ['name', 'value'],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success' => ['type' => 'boolean'],
						'name'    => ['type' => 'string'],
						'changed' => ['type' => 'boolean'],
						'message' => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/get-option',
			[
				'label'               => __('Get Option', 'agent-mod'),
				'description'         => __('Reads a single row from the WordPress options table by name. Use this for plugin options that are not exposed through get-settings. Options holding credentials, cached transients or AgentMod\'s own configuration cannot be read.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => function ($input = null) {
					$input = $this->toArray($input);

					return $this->options->getOption((string) ($input['name'] ?? ''));
				},
				'permission_callback' => static function (): bool {
					return current_user_can('manage_options');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'name' => [
							'type'        => 'string',
							'description' => __('The exact option name, e.g. "permalink_structure".', 'agent-mod'),
						],
					],
					'required'   => ['name'],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'name'       => ['type' => 'string'],
						'exists'     => ['type' => 'boolean'],
						'value_type' => ['type' => 'string'],
						'registered' => ['type' => 'boolean'],
						'writable'   => ['type' => 'boolean'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/update-option',
			[
				'label'               => __('Update Option', 'agent-mod'),
				'description'         => __('Writes a single row in the WordPress options table. Prefer update-setting for anything that appears in get-settings, because that path validates the value against a declared schema. Options controlling site addressing, active plugins and themes, role definitions or credentials are refused. Requires user confirmation.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => function ($input = null) {
					return $this->options->updateOption($this->toArray($input));
				},
				'permission_callback' => static function (): bool {
					return current_user_can('manage_options');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'name'   => [
							'type'        => 'string',
							'description' => __('The exact option name.', 'agent-mod'),
						],
						'value'  => [
							'type'        => ['string', 'number', 'integer', 'boolean', 'array', 'null'],
							'description' => __('The new value.', 'agent-mod'),
						],
						'create' => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => __('Set to true to create the option when it does not exist yet. Without it, a missing option is treated as a mistake.', 'agent-mod'),
						],
					],
					'required'   => ['name', 'value'],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success' => ['type' => 'boolean'],
						'name'    => ['type' => 'string'],
						'created' => ['type' => 'boolean'],
						'message' => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
					'show_in_rest' => true,
				],
			]
		);
	}

	// =========================================================================
	// System — equivalents of `wp core`, `wp cron` and the flush commands
	// =========================================================================

	/**
	 * Registers the system status and maintenance abilities.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	private function registerSystemAbilities(): void
	{
		wp_register_ability(
			'agent-mod/get-core-status',
			[
				'label'               => __('Get Core Status', 'agent-mod'),
				'description'         => __('Reports the WordPress version and whether an update is waiting, the PHP and MySQL versions, the active theme, how many plugin and theme updates are pending, and whether this site permits file modifications at all. Call this first when an install or update fails, or when diagnosing the environment.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => function () {
					return $this->system->getCoreStatus();
				},
				'permission_callback' => static function (): bool {
					return current_user_can('manage_options');
				},
				// No input_schema on purpose: WP_Ability rejects a null input when
				// a schema is present, and providers routinely call zero-argument
				// tools with no arguments at all.
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'wordpress'          => ['type' => 'object'],
						'environment'        => ['type' => 'object'],
						'active_theme'       => ['type' => 'object'],
						'updates'            => ['type' => 'object'],
						'file_modifications' => ['type' => 'object'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/get-cron-events',
			[
				'label'               => __('Get Cron Events', 'agent-mod'),
				'description'         => __('Lists the tasks WordPress has scheduled, when each next runs, whether any are overdue, and whether the built-in cron trigger is disabled. Useful for diagnosing background work that is not happening.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => function ($input = null) {
					return $this->system->getCronEvents($this->toArray($input));
				},
				'permission_callback' => static function (): bool {
					return current_user_can('manage_options');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'search' => [
							'type'        => 'string',
							'description' => __('Optional. Filters events by hook name.', 'agent-mod'),
						],
					],
					'default'    => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'events'        => ['type' => 'array'],
						'total'         => ['type' => 'integer'],
						'truncated'     => ['type' => 'boolean'],
						'cron_disabled' => ['type' => 'boolean'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/flush-caches',
			[
				'label'               => __('Flush Caches', 'agent-mod'),
				'description'         => __('Clears the WordPress object cache and regenerates the URL rewrite rules. Use this when changes are not showing up on the front end, or after changing permalinks. Both operations are safe and reversible, so this runs without asking for confirmation.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => function ($input = null) {
					return $this->system->flushCaches($this->toArray($input));
				},
				'permission_callback' => static function (): bool {
					return current_user_can('manage_options');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'object_cache'  => [
							'type'        => 'boolean',
							'default'     => true,
							'description' => __('Whether to flush the object cache. Defaults to true.', 'agent-mod'),
						],
						'rewrite_rules' => [
							'type'        => 'boolean',
							'default'     => true,
							'description' => __('Whether to regenerate the rewrite rules. Defaults to true.', 'agent-mod'),
						],
					],
					'default'    => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'       => ['type' => 'boolean'],
						'object_cache'  => ['type' => 'boolean'],
						'rewrite_rules' => ['type' => 'boolean'],
						'message'       => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
					'show_in_rest' => true,
				],
			]
		);
	}

	// =========================================================================
	// Users — equivalents of the `wp user` command family
	// =========================================================================

	/**
	 * Registers the user management abilities.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	private function registerUserAbilities(): void
	{
		wp_register_ability(
			'agent-mod/get-users',
			[
				'label'               => __('Get Users', 'agent-mod'),
				'description'         => __('Lists user accounts with their ID, username, display name, email address, roles and registration date, and reports which roles exist on this site. Use this to find a user\'s ID before changing or deleting their account.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => function ($input = null) {
					return $this->users->listUsers($this->toArray($input));
				},
				'permission_callback' => static function (): bool {
					return current_user_can('list_users');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'search' => [
							'type'        => 'string',
							'description' => __('Optional. Matches against username, email, nicename and display name.', 'agent-mod'),
						],
						'role'   => [
							'type'        => 'string',
							'description' => __('Optional. Restricts results to one role slug, e.g. "administrator" or "subscriber".', 'agent-mod'),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'users'     => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'           => ['type' => 'integer'],
									'login'        => ['type' => 'string'],
									'display_name' => ['type' => 'string'],
									'email'        => ['type' => 'string'],
									'roles'        => ['type' => 'array', 'items' => ['type' => 'string']],
									'registered'   => ['type' => 'string'],
								],
							],
						],
						'total'     => ['type' => 'integer'],
						'truncated' => ['type' => 'boolean'],
						'roles'     => ['type' => 'array', 'items' => ['type' => 'string']],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/manage-user',
			[
				'label'               => __('Manage User', 'agent-mod'),
				'description'         => __('Creates a user account, edits an existing user\'s profile fields, changes their role, or emails them a password reset link. Requires user confirmation. Passwords are never chosen or shown here: new accounts and resets go out as standard WordPress emails. Usernames cannot be changed after creation.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => function ($input = null) {
					return $this->users->manage($this->toArray($input));
				},
				'permission_callback' => static function (): bool {
					return current_user_can('edit_users') || current_user_can('create_users');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'action'       => [
							'type'        => 'string',
							'enum'        => ['create', 'update', 'set-role', 'send-password-reset'],
							'description' => __('The operation to perform.', 'agent-mod'),
						],
						'id'           => [
							'type'        => 'integer',
							'description' => __('The target user ID. Required for update, set-role and send-password-reset.', 'agent-mod'),
						],
						'login'        => [
							'type'        => 'string',
							'description' => __('Username for the new account. Required for create, ignored otherwise.', 'agent-mod'),
						],
						'email'        => [
							'type'        => 'string',
							'description' => __('Email address. Required for create, optional for update.', 'agent-mod'),
						],
						'role'         => [
							'type'        => 'string',
							'description' => __('Role slug, e.g. "editor". Required for set-role, optional for create.', 'agent-mod'),
						],
						'display_name' => [
							'type'        => 'string',
							'description' => __('Public display name.', 'agent-mod'),
						],
						'first_name'   => ['type' => 'string'],
						'last_name'    => ['type' => 'string'],
						'nickname'     => ['type' => 'string'],
						'description'  => [
							'type'        => 'string',
							'description' => __('Biographical info shown on the profile.', 'agent-mod'),
						],
					],
					'required'   => ['action'],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success' => ['type' => 'boolean'],
						'id'      => ['type' => 'integer'],
						'login'   => ['type' => 'string'],
						'role'    => ['type' => 'string'],
						'message' => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/remove-user',
			[
				'label'               => __('Remove User', 'agent-mod'),
				'description'         => __('Permanently deletes a user account and reassigns everything they authored to another user, which reassign_to must name. The account you are signed in as and the site\'s only administrator cannot be deleted. This cannot be undone and requires user confirmation.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => function ($input = null) {
					return $this->users->remove($this->toArray($input));
				},
				'permission_callback' => static function (): bool {
					return current_user_can('delete_users');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'id'          => [
							'type'        => 'integer',
							'description' => __('The ID of the user account to delete.', 'agent-mod'),
						],
						'reassign_to' => [
							'type'        => 'integer',
							'description' => __('The ID of the user who should inherit the deleted user\'s posts and pages.', 'agent-mod'),
						],
					],
					'required'   => ['id', 'reassign_to'],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'       => ['type' => 'boolean'],
						'id'            => ['type' => 'integer'],
						'display_name'  => ['type' => 'string'],
						'reassigned_to' => ['type' => 'integer'],
						'message'       => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
					'show_in_rest' => true,
				],
			]
		);
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Normalises ability input to an array.
	 *
	 * Abilities receive `mixed` input; providers occasionally hand over an
	 * object or null instead of the declared object schema.
	 *
	 * @param mixed $input Raw ability input.
	 *
	 * @return array<string, mixed>
	 * @since 1.1.0
	 */
	private function toArray($input): array
	{
		if (is_array($input)) {
			return $input;
		}

		if (is_object($input)) {
			return get_object_vars($input);
		}

		return [];
	}
}
