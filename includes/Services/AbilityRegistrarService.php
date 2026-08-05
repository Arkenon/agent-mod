<?php

/**
 * Ability registrar service.
 *
 * Registers ability categories plus all abilities provided by the AgentMod plugin.
 * Includes demo abilities exercising the orchestrator's tool-calling loop, and a
 * full suite of block-design abilities for managing templates, posts, patterns and
 * global styles — migrated from the deprecated block-design-abilities plugin.
 *
 * @package AgentMod
 * @subpackage Services
 * @since 1.0.0
 */

namespace AgentMod\Services;

use WP_Error;
use WP_Query;
use WP_Block_Patterns_Registry;
use WP_Theme_JSON;
use WP_Theme_JSON_Resolver;

defined('ABSPATH') || exit;

class AbilityRegistrarService
{
	/**
	 * Agent-Mod ability category slug.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private const CATEGORY = 'agent-mod';

	/**
	 * Global Styles preset paths mapped to the key carrying each preset's value.
	 *
	 * Mirrors WP_Theme_JSON::PRESETS_METADATA. In the user's wp_global_styles post
	 * every one of these paths must hold a *flat list* of preset objects. Anything
	 * else — a scalar, or a map keyed by origin (default/theme/custom) — makes
	 * WP_Theme_JSON iterate a non-array while building the stylesheet and emit
	 * "foreach() argument must be of type array|object" warnings on every page.
	 *
	 * @var array<string, string>
	 * @since 1.1.5
	 */
	private const PRESET_PATHS = [
		'color.palette'           => 'color',
		'color.gradients'         => 'gradient',
		'color.duotone'           => 'colors',
		'typography.fontSizes'    => 'size',
		'typography.fontFamilies' => 'fontFamily',
		'spacing.spacingSizes'    => 'size',
		'shadow.presets'          => 'shadow',
		'dimensions.aspectRatios' => 'ratio',
	];

	/**
	 * Origins WP_Theme_JSON keys presets by internally, in precedence order.
	 *
	 * @var string[]
	 * @since 1.1.5
	 */
	private const PRESET_ORIGINS = ['default', 'theme', 'custom'];

	/**
	 * Inject Setting Service
	 * @since 1.0.5
	 */
	private SettingsService $settings_service;

	/**
	 * Shared block-markup validator (advisory linter + write-path enforcement).
	 *
	 * @var BlockMarkupValidator
	 * @since 1.1.0
	 */
	private BlockMarkupValidator $blockMarkupValidator;

	/**
	 * Constructor. Binds the abilities API hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct(SettingsService $settingsService, BlockMarkupValidator $blockMarkupValidator)
	{
		$this->settings_service     = $settingsService;
		$this->blockMarkupValidator = $blockMarkupValidator;

		add_action('wp_abilities_api_categories_init', [$this, 'registerCategories']);
		add_action('wp_abilities_api_init', [$this, 'registerAbilities']);
	}

	/**
	 * Registers the ability categories.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function registerCategories(): void
	{
		if (! function_exists('wp_register_ability_category')) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __('AgentMod', 'agent-mod'),
				'description' => __('Abilities provided by the AgentMod plugin for AI agents.', 'agent-mod'),
			]
		);
	}

	/**
	 * Registers all abilities.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function registerAbilities(): void
	{
		if (! function_exists('wp_register_ability')) {
			return;
		}

		// -------------------------------------------------------------------------
		// AgentMod core abilities
		// -------------------------------------------------------------------------

		wp_register_ability(
			'agent-mod/list-recent-posts',
			[
				'label'               => __('List Recent Posts', 'agent-mod'),
				'description'         => __('Returns the most recent published posts with their titles, links and dates.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeListRecentPosts'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_posts');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'count' => [
							'type'        => 'integer',
							'description' => __('How many posts to return (1-20).', 'agent-mod'),
							'minimum'     => 1,
							'maximum'     =>$this->settings_service->getMaxSearchResults(),
						],
					],
				],
				'output_schema'       => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'    => ['type' => 'integer'],
							'title' => ['type' => 'string'],
							'link'  => ['type' => 'string'],
							'date'  => ['type' => 'string'],
						],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true],
					'show_in_rest' => true,
				],
			]
		);

		// -------------------------------------------------------------------------
		// Block Design: Template abilities
		// -------------------------------------------------------------------------

		wp_register_ability(
			'agent-mod/list-templates',
			[
				'label'               => __('List Templates', 'agent-mod'),
				'description'         => __('Returns all available templates for the active theme.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeListTemplates'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_theme_options');
				},
				// No input_schema: executeListTemplates() never read the "count"
				// property, and WP_Ability::validate_input() rejects the null
				// input a provider sends for an argument-less tool call whenever
				// a schema is present.
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'theme'     => ['type' => 'string'],
						'templates' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'slug'    => ['type' => 'string'],
									'title'   => ['type' => 'string'],
									'post_id' => ['type' => 'integer'],
								],
							],
						],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/get-template',
			[
				'label'               => __('Get Template', 'agent-mod'),
				'description'         => __('Returns a template\'s raw block markup as html by slug. Use list-templates first to get slugs. The returned html can be modified and passed straight back to add-or-update-template as the html parameter. For header/footer/sidebar template parts use get-template-part instead.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeGetTemplate'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_theme_options');
				},
				'input_schema'        => [
					'type'       => 'object',
					'required'   => ['slug'],
					'properties' => [
						'slug' => [
							'type'        => 'string',
							'description' => __('Template slug from list-templates.', 'agent-mod'),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success' => ['type' => 'boolean'],
						'slug'    => ['type' => 'string'],
						'title'   => ['type' => 'string'],
						'post_id' => ['type' => 'integer'],
						'html'    => ['type' => 'string'],
						'source'  => ['type' => 'string'],
						'error'   => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/add-or-update-template',
			[
				'label'               => __('Add or Update Template', 'agent-mod'),
				'description'         => __('Saves content to a template. Provide html and either post_id or slug (not both). Theme-file templates are read-only on disk: saving with the SAME slug creates a database override that WordPress uses instead — this is the correct way to customize them. A slug the theme ships no file for (e.g. front-page) is also allowed and creates a new template. For header/footer parts use add-or-update-template-part.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeAddOrUpdateTemplate'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_theme_options');
				},
				'input_schema'        => [
					'type'       => 'object',
					'required'   => ['html'],
					'properties' => [
						'post_id' => [
							'type'        => 'integer',
							'description' => __('DB post ID of the template. Use this to update an existing template.', 'agent-mod'),
						],
						'slug'    => [
							'type'        => 'string',
							'description' => __('Template slug (e.g. front-page, single, page). Updates the existing DB override for this slug when one exists, otherwise creates one — including slugs the theme ships no file for.', 'agent-mod'),
						],
						'title'   => [
							'type'        => 'string',
							'description' => __('Optional human-readable title. Defaults to the theme template\'s title or one derived from the slug.', 'agent-mod'),
						],
						'html'    => [
							'type'        => 'string',
							'description' => __('Serialized block markup (WordPress block comment format). Use the output of get-template for round-trip editing. Replaces template content entirely.', 'agent-mod'),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success' => ['type' => 'boolean'],
						'post_id' => ['type' => 'integer'],
						'action'  => ['type' => 'string'],
						'slug'    => ['type' => 'string'],
						'error'   => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false],
					'show_in_rest' => true,
				],
			]
		);

		// -------------------------------------------------------------------------
		// Block Design: Template Part abilities
		// -------------------------------------------------------------------------

		wp_register_ability(
			'agent-mod/list-template-parts',
			[
				'label'               => __('List Template Parts', 'agent-mod'),
				'description'         => __('Returns all template parts (header, footer, sidebar, …) available for the active theme, with their area and, when a database override exists, its post_id.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeListTemplateParts'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_theme_options');
				},
				// No input_schema on purpose: WP_Ability rejects a null input when
				// a schema is present, and providers routinely call zero-argument
				// tools with no arguments at all.
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'theme'          => ['type' => 'string'],
						'template_parts' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'slug'    => ['type' => 'string'],
									'title'   => ['type' => 'string'],
									'area'    => ['type' => 'string'],
									'post_id' => ['type' => 'integer'],
								],
							],
						],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/get-template-part',
			[
				'label'               => __('Get Template Part', 'agent-mod'),
				'description'         => __('Returns a template part\'s raw block markup as html by slug (e.g. header, footer). Use list-template-parts first to get slugs. The returned html can be modified and passed straight back to add-or-update-template-part.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeGetTemplatePart'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_theme_options');
				},
				'input_schema'        => [
					'type'       => 'object',
					'required'   => ['slug'],
					'properties' => [
						'slug' => [
							'type'        => 'string',
							'description' => __('Template part slug from list-template-parts.', 'agent-mod'),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success' => ['type' => 'boolean'],
						'slug'    => ['type' => 'string'],
						'title'   => ['type' => 'string'],
						'area'    => ['type' => 'string'],
						'post_id' => ['type' => 'integer'],
						'html'    => ['type' => 'string'],
						'source'  => ['type' => 'string'],
						'error'   => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/add-or-update-template-part',
			[
				'label'               => __('Add or Update Template Part', 'agent-mod'),
				'description'         => __('Saves content to a template part (header, footer, sidebar, …). Provide html and either post_id or slug (not both). Theme-file parts are read-only on disk: saving with the SAME slug creates a database override that WordPress uses instead — the correct way to customize the site header or footer.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeAddOrUpdateTemplatePart'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_theme_options');
				},
				'input_schema'        => [
					'type'       => 'object',
					'required'   => ['html'],
					'properties' => [
						'post_id' => [
							'type'        => 'integer',
							'description' => __('DB post ID of the template part. Use this to update an existing database record.', 'agent-mod'),
						],
						'slug'    => [
							'type'        => 'string',
							'description' => __('Template part slug (e.g. header, footer). Updates the existing DB override for this slug when one exists, otherwise creates one.', 'agent-mod'),
						],
						'title'   => [
							'type'        => 'string',
							'description' => __('Optional human-readable title. Defaults to the theme part\'s title or one derived from the slug.', 'agent-mod'),
						],
						'area'    => [
							'type'        => 'string',
							'description' => __('Template part area: header, footer, sidebar or uncategorized. Defaults by slug convention on create.', 'agent-mod'),
						],
						'html'    => [
							'type'        => 'string',
							'description' => __('Serialized block markup (WordPress block comment format). Use the output of get-template-part for round-trip editing. Replaces part content entirely.', 'agent-mod'),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success' => ['type' => 'boolean'],
						'post_id' => ['type' => 'integer'],
						'action'  => ['type' => 'string'],
						'slug'    => ['type' => 'string'],
						'error'   => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false],
					'show_in_rest' => true,
				],
			]
		);

		// -------------------------------------------------------------------------
		// Block Design: Post/Page abilities
		// -------------------------------------------------------------------------

		wp_register_ability(
			'agent-mod/list-posts',
			[
				'label'               => __('List Posts and Pages', 'agent-mod'),
				'description'         => __('Returns a paginated list of posts/pages. Use "s" to search by title instead of browsing. Returns post_id for use with get-post.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeListPosts'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_posts');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_type'      => [
							'type'        => 'string',
							'description' => __('Any registered post type slug (e.g. "post", "page", or a custom post type), or "any" for all public post types. Default "post".', 'agent-mod'),
						],
						'posts_per_page' => [
							'type'        => 'integer',
							'description' => __('Results per page. Default 10, capped at the Max Search Results setting.', 'agent-mod'),
						],
						'paged'          => [
							'type'        => 'integer',
							'description' => __('Page number. Default 1.', 'agent-mod'),
						],
						's'              => [
							'type'        => 'string',
							'description' => __('Keyword search in title and content.', 'agent-mod'),
						],
						'orderby'        => [
							'type'        => 'string',
							'enum'        => ['title', 'date', 'modified', 'ID'],
							'description' => __('Sort field. Default "title".', 'agent-mod'),
						],
						'order'          => [
							'type'        => 'string',
							'enum'        => ['ASC', 'DESC'],
							'description' => __('Sort direction. Default "ASC".', 'agent-mod'),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'    => ['type' => 'boolean'],
						'posts'      => ['type' => 'array'],
						'pagination' => ['type' => 'object'],
						'error'      => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/get-post',
			[
				'label'               => __('Get Post or Page', 'agent-mod'),
				'description'         => __('Returns a post raw block markup as html by post_id. Use list-posts to find post_id first. The returned html can be modified and passed straight back to update-post as the html parameter.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeGetPost'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_posts');
				},
				'input_schema'        => [
					'type'       => 'object',
					'required'   => ['post_id'],
					'properties' => [
						'post_id'   => [
							'type'        => 'integer',
							'description' => __('Post/page ID from list-posts.', 'agent-mod'),
						],
						'post_type' => [
							'type'        => 'string',
							'description' => __('The expected post type of post_id (e.g. "post", "page", or a custom post type). Default "post".', 'agent-mod'),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'   => ['type' => 'boolean'],
						'post_id'   => ['type' => 'integer'],
						'post_name' => ['type' => 'string'],
						'title'     => ['type' => 'string'],
						'post_type' => ['type' => 'string'],
						'url'       => ['type' => 'string'],
						'html'      => ['type' => 'string'],
						'error'     => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/create-post',
			[
				'label'               => __('Create Post or Page', 'agent-mod'),
				'description'         => __('Creates a new post/page. Provide title and optionally html. The html parameter must be complete, valid serialized block markup; it is parsed with parse_blocks() and re-serialized as-is — attribute/innerHTML consistency is NOT validated server-side. Returns the new post_id for use with get-post/update-post.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeCreatePost'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_posts');
				},
				'input_schema'        => [
					'type'       => 'object',
					'required'   => ['title'],
					'properties' => [
						'title'       => [
							'type'        => 'string',
							'description' => __('The title of the new post/page.', 'agent-mod'),
						],
						'html'        => [
							'type'        => 'string',
							'description' => __('Optional. Serialized block markup (WordPress block comment format) for the initial content.', 'agent-mod'),
						],
						'post_type'   => [
							'type'        => 'string',
							'description' => __('"post" (default) or "page".', 'agent-mod'),
						],
						'post_status' => [
							'type'        => 'string',
							'enum'        => ['draft', 'publish', 'pending', 'private'],
							'description' => __('Publication status. Default "draft".', 'agent-mod'),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'   => ['type' => 'boolean'],
						'post_id'   => ['type' => 'integer'],
						'post_type' => ['type' => 'string'],
						'status'    => ['type' => 'string'],
						'url'       => ['type' => 'string'],
						'error'     => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/update-post',
			[
				'label'               => __('Update Post or Page', 'agent-mod'),
				'description'         => __('Saves content to a post. Provide post_id and html. The html parameter must be complete, valid serialized block markup; it is parsed with parse_blocks() and re-serialized as-is — attribute/innerHTML consistency is NOT validated server-side.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeUpdatePost'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_posts');
				},
				'input_schema'        => [
					'type'       => 'object',
					'required'   => ['post_id', 'html'],
					'properties' => [
						'post_id'   => [
							'type'        => 'integer',
							'description' => __('Post/page ID from get-post.', 'agent-mod'),
						],
						'post_type' => [
							'type'        => 'string',
							'description' => __('The expected post type of post_id (e.g. "post", "page", or a custom post type). Default "post".', 'agent-mod'),
						],
						'html'      => [
							'type'        => 'string',
							'description' => __('Serialized block markup (WordPress block comment format). Use the output of get-post for round-trip editing. Replaces existing content entirely.', 'agent-mod'),
						],
						'title'     => [
							'type'        => 'string',
							'description' => __('Optional. Updates the post/page title if provided.', 'agent-mod'),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'   => ['type' => 'boolean'],
						'post_id'   => ['type' => 'integer'],
						'post_type' => ['type' => 'string'],
						'url'       => ['type' => 'string'],
						'error'     => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false],
					'show_in_rest' => true,
				],
			]
		);

		// -------------------------------------------------------------------------
		// Block Design: Pattern abilities
		// -------------------------------------------------------------------------

		wp_register_ability(
			'agent-mod/list-patterns',
			[
				'label'               => __('List Patterns', 'agent-mod'),
				'description'         => __('Returns block patterns from two sources: registry (theme/plugin, read-only, identified by slug) and database (wp_block posts, editable, identified by post_id). Use get-pattern to retrieve full content.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeListPatterns'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_posts');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'source'   => [
							'type'        => 'string',
							'enum'        => ['all', 'registry', 'database'],
							'description' => __('"all" (default), "registry", or "database".', 'agent-mod'),
						],
						'category' => [
							'type'        => 'string',
							'description' => __('Filter registry patterns by category slug.', 'agent-mod'),
						],
						'search'   => [
							'type'        => 'string',
							'description' => __('Filter by keyword in title.', 'agent-mod'),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'           => ['type' => 'boolean'],
						'registry_patterns' => ['type' => 'array'],
						'database_patterns' => ['type' => 'array'],
						'totals'            => ['type' => 'object'],
						'error'             => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/get-pattern',
			[
				'label'               => __('Get Pattern', 'agent-mod'),
				'description'         => __('Returns a pattern\'s raw block markup as html. source="registry": fetch by slug (read-only). source="database": fetch by post_id (editable via update-pattern). The returned html can be modified and passed straight back to update-pattern as the html parameter.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeGetPattern'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_posts');
				},
				'input_schema'        => [
					'type'       => 'object',
					'required'   => ['source'],
					'properties' => [
						'source'  => [
							'type'        => 'string',
							'enum'        => ['registry', 'database'],
							'description' => __('"registry" (fetch by slug) or "database" (fetch by post_id).', 'agent-mod'),
						],
						'slug'    => [
							'type'        => 'string',
							'description' => __('Pattern slug from list-patterns. Required when source="registry".', 'agent-mod'),
						],
						'post_id' => [
							'type'        => 'integer',
							'description' => __('wp_block post ID from list-patterns. Required when source="database".', 'agent-mod'),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'     => ['type' => 'boolean'],
						'source'      => ['type' => 'string'],
						'post_id'     => ['type' => 'integer'],
						'slug'        => ['type' => 'string'],
						'title'       => ['type' => 'string'],
						'sync_status' => ['type' => 'string'],
						'is_editable' => ['type' => 'boolean'],
						'html'        => ['type' => 'string'],
						'error'       => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/update-pattern',
			[
				'label'               => __('Update Pattern', 'agent-mod'),
				'description'         => __('Updates a database pattern (wp_block). Provide post_id and html. The html parameter must be complete, valid serialized block markup; it is parsed with parse_blocks() and re-serialized as-is — attribute/innerHTML consistency is NOT validated server-side.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeUpdatePattern'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_posts');
				},
				'input_schema'        => [
					'type'       => 'object',
					'required'   => ['post_id', 'html'],
					'properties' => [
						'post_id' => [
							'type'        => 'integer',
							'description' => __('wp_block post ID from list-patterns or get-pattern.', 'agent-mod'),
						],
						'html'    => [
							'type'        => 'string',
							'description' => __('Serialized block markup (WordPress block comment format). Use the output of get-pattern for round-trip editing. Replaces existing content entirely.', 'agent-mod'),
						],
						'title'   => [
							'type'        => 'string',
							'description' => __('Optional. Updates the pattern title if provided.', 'agent-mod'),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'     => ['type' => 'boolean'],
						'post_id'     => ['type' => 'integer'],
						'title'       => ['type' => 'string'],
						'sync_status' => ['type' => 'string'],
						'error'       => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/duplicate-pattern',
			[
				'label'               => __('Duplicate Pattern', 'agent-mod'),
				'description'         => __('Copies a read-only registry pattern into a database wp_block post, making it editable. Workflow: list-patterns → duplicate-pattern → update-pattern.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeDuplicatePattern'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_posts');
				},
				'input_schema'        => [
					'type'       => 'object',
					'required'   => ['slug'],
					'properties' => [
						'slug'        => [
							'type'        => 'string',
							'description' => __('Registry pattern slug from list-patterns.', 'agent-mod'),
						],
						'title'       => [
							'type'        => 'string',
							'description' => __('Optional. Custom title. Defaults to original title + " (Copy)".', 'agent-mod'),
						],
						'sync_status' => [
							'type'        => 'string',
							'enum'        => ['synced', 'unsynced'],
							'description' => __('Default "unsynced". "synced" = shared component updated everywhere when changed.', 'agent-mod'),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'       => ['type' => 'boolean'],
						'post_id'       => ['type' => 'integer'],
						'title'         => ['type' => 'string'],
						'slug'          => ['type' => 'string'],
						'sync_status'   => ['type' => 'string'],
						'original_slug' => ['type' => 'string'],
						'html'          => ['type' => 'string'],
						'error'         => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/create-pattern',
			[
				'label'               => __('Create Pattern', 'agent-mod'),
				'description'         => __('Creates a new wp_block pattern from scratch. Provide title and html. The pattern appears in Site Editor under "My Patterns".', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeCreatePattern'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_posts');
				},
				'input_schema'        => [
					'type'       => 'object',
					'required'   => ['title', 'html'],
					'properties' => [
						'title'       => [
							'type'        => 'string',
							'description' => __('Pattern title (e.g. "Pricing Table").', 'agent-mod'),
						],
						'description' => [
							'type'        => 'string',
							'description' => __('Optional. Short description.', 'agent-mod'),
						],
						'html'        => [
							'type'        => 'string',
							'description' => __('Serialized block markup (WordPress block comment format).', 'agent-mod'),
						],
						'categories'  => [
							'type'        => 'array',
							'items'       => ['type' => 'string'],
							'description' => __('Optional. wp_pattern_category slugs. Non-existent slugs are created automatically.', 'agent-mod'),
						],
						'sync_status' => [
							'type'        => 'string',
							'enum'        => ['synced', 'unsynced'],
							'description' => __('Default "unsynced". "synced" = shared component updated everywhere when changed.', 'agent-mod'),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'     => ['type' => 'boolean'],
						'post_id'     => ['type' => 'integer'],
						'title'       => ['type' => 'string'],
						'slug'        => ['type' => 'string'],
						'sync_status' => ['type' => 'string'],
						'categories'  => ['type' => 'array'],
						'html'        => ['type' => 'string'],
						'error'       => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false],
					'show_in_rest' => true,
				],
			]
		);

		// -------------------------------------------------------------------------
		// Block Design: Global Styles abilities
		// -------------------------------------------------------------------------

		wp_register_ability(
			'agent-mod/get-global-styles',
			[
				'label'               => __('Get Global Styles', 'agent-mod'),
				'description'         => __('Returns the active theme\'s design tokens from theme.json (colors, typography, spacing). Use this before editing templates or patterns to know available preset slugs for block attributes. Presets are returned as flat lists — exactly the shape agent-mod/update-global-styles expects back.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeGetGlobalStyles'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_theme_options');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'origin'   => [
							'type'        => 'string',
							'enum'        => ['all', 'base'],
							'description' => __('"all" (default): theme + user customizations merged. "base": theme file only.', 'agent-mod'),
						],
						'sections' => [
							'type'        => 'array',
							'items'       => [
								'type' => 'string',
								'enum' => ['settings', 'styles', 'user_overrides', 'theme_info'],
							],
							'description' => __('Sections to return. Omit for all. "settings": color/font/spacing tokens. "styles": global CSS. "user_overrides": Site Editor changes. "theme_info": theme metadata.', 'agent-mod'),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'        => ['type' => 'boolean'],
						'theme_info'     => ['type' => 'object'],
						'settings'       => ['type' => 'object'],
						'styles'         => ['type' => 'object'],
						'user_overrides' => ['type' => ['object', 'null']],
						'error'          => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/update-global-styles',
			[
				'label'               => __('Update Global Styles', 'agent-mod'),
				'description'         => __('Merges the provided settings and/or styles into the active theme\'s user overrides (wp_global_styles post). Only supplied keys are changed; everything else is preserved. Creates the post if it does not yet exist. Presets (palette, gradients, fontSizes, fontFamilies, spacingSizes, shadow presets) must be FLAT lists of objects, never numbers and never keyed by origin (default/theme/custom); passing a preset list replaces that list in full, so send every preset you want to keep.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeUpdateGlobalStyles'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_theme_options');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'settings' => [
							'type'        => 'object',
							'description' => __('Partial settings object to deep-merge into global settings, e.g. {"color":{"palette":[{"slug":"primary","name":"Primary","color":"#3d35e8"}]}}.', 'agent-mod'),
							'properties'  => [
								'color'      => [
									'type'       => 'object',
									'properties' => [
										'palette'   => self::presetListSchema('color', __('CSS color value, e.g. #3d35e8.', 'agent-mod')),
										'gradients' => self::presetListSchema('gradient', __('CSS gradient value, e.g. linear-gradient(135deg,#3d35e8 0%,#5b4de8 100%).', 'agent-mod')),
										'duotone'   => [
											'type'  => 'array',
											'items' => [
												'type'       => 'object',
												'required'   => ['slug', 'colors'],
												'properties' => [
													'slug'   => ['type' => 'string'],
													'name'   => ['type' => 'string'],
													'colors' => ['type' => 'array', 'items' => ['type' => 'string']],
												],
											],
										],
									],
								],
								'typography' => [
									'type'       => 'object',
									'properties' => [
										'fontSizes'    => self::presetListSchema('size', __('CSS length, e.g. 1.25rem.', 'agent-mod'), ['string', 'number']),
										'fontFamilies' => self::presetListSchema('fontFamily', __('CSS font stack, e.g. "DM Sans", sans-serif. Add a fontFace array only for fonts already installed on the site.', 'agent-mod')),
									],
								],
								'spacing'    => [
									'type'       => 'object',
									'properties' => [
										'spacingSizes' => self::presetListSchema('size', __('CSS length, e.g. 1.5rem.', 'agent-mod'), ['string', 'number']),
									],
								],
								'shadow'     => [
									'type'       => 'object',
									'properties' => [
										'presets' => self::presetListSchema('shadow', __('CSS box-shadow value.', 'agent-mod')),
									],
								],
							],
						],
						'styles'   => [
							'type'        => 'object',
							'description' => __('Partial styles object to deep-merge into global styles (e.g. {"typography":{"fontSize":"1rem"}}).', 'agent-mod'),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success' => ['type' => 'boolean'],
						'post_id' => ['type' => 'integer'],
						'created' => ['type' => 'boolean'],
						'error'   => ['type' => 'string'],
						'issues'  => ['type' => 'array', 'items' => ['type' => 'string']],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/validate-block-markup',
			[
				'label'               => __('Validate Block Markup', 'agent-mod'),
				'description'         => __('Lints serialized Gutenberg block markup before writing: parses it and reports malformed delimiters, invalid JSON attributes, unregistered block names and content outside block delimiters. Always call this on the full markup before create-pattern, update-post, add-or-update-template or any other write ability, and fix every issue before writing.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'validateBlockMarkup'],
				'permission_callback' => function () {
					return current_user_can('edit_posts');
				},
				'input_schema'        => [
					'type' => 'object',
					'properties' => [
						'markup' => [
							'type' => 'string',
							'description' => __('The serialized block markup to validate.', 'agent-mod')
						]
					],
					'required' => ['markup']
				],
				'output_schema'       => [
					'type' => 'object',
					'properties' => [
						'valid' => ['type' => 'boolean'],
						'block_count' => ['type' => 'integer'],
						'issues' => ['type' => 'array', 'items' => ['type' => 'string']],
					]
				],
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => ['readonly' => true],
				],
			]
		);
	}

	/**
	 * Execute callback for agent-mod/get-site-info.
	 *
	 * @return array<string, string>
	 * @since 1.0.0
	 */
	public function executeGetSiteInfo(): array
	{
		return [
			'name'        => (string) get_bloginfo('name'),
			'description' => (string) get_bloginfo('description'),
			'url'         => (string) home_url(),
			'wp_version'  => (string) get_bloginfo('version'),
		];
	}

	/**
	 * Execute callback for agent-mod/list-recent-posts.
	 *
	 * @param mixed $input Input data (expects an optional 'count').
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.0.0
	 */
	public function executeListRecentPosts($input = null): array
	{
		$count = 5;
		if (is_array($input) && isset($input['count'])) {
			$count = (int) $input['count'];
		}
		$count = max(1, min($this->settings_service->getMaxSearchResults(), $count));

		$posts = get_posts(
			[
				'numberposts' => $count,
				'post_status' => 'publish',
				'orderby'     => 'date',
				'order'       => 'DESC',
			]
		);

		$result = [];
		foreach ($posts as $post) {
			$result[] = [
				'id'    => $post->ID,
				'title' => get_the_title($post),
				'link'  => (string) get_permalink($post),
				'date'  => get_the_date('c', $post),
			];
		}

		return $result;
	}

	// =========================================================================
	// Block Design: Template execute callbacks
	// =========================================================================

	/**
	 * Execute callback for agent-mod/list-templates.
	 *
	 * @param mixed $input Input data (no parameters required).
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function executeListTemplates($input = null): array
	{
		if (! function_exists('get_block_templates')) {
			return ['templates' => []];
		}

		$blockTemplates = get_block_templates([], 'wp_template');

		if (empty($blockTemplates)) {
			return [
				'theme'     => get_stylesheet(),
				'templates' => [],
			];
		}

		$templates = array_map(static function ($tpl) {
			$item = [
				'slug'  => $tpl->slug,
				'title' => $tpl->title,
			];

			if (! empty($tpl->wp_id)) {
				$item['post_id'] = (int) $tpl->wp_id;
			}

			return $item;
		}, $blockTemplates);

		usort($templates, static fn ($a, $b) => strcmp($a['slug'], $b['slug']));

		return [
			'theme'     => get_stylesheet(),
			'templates' => $templates,
		];
	}

	/**
	 * Execute callback for agent-mod/get-template.
	 *
	 * @param mixed $input Input data (expects 'slug').
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function executeGetTemplate($input = null): array
	{
		return $this->readTemplateObject($input, 'wp_template');
	}

	/**
	 * Execute callback for agent-mod/add-or-update-template.
	 *
	 * @param mixed $input Input data (expects 'html' and either 'post_id' or 'slug').
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function executeAddOrUpdateTemplate($input = null): array
	{
		return $this->writeTemplateObject($input, 'wp_template');
	}

	/**
	 * Execute callback for agent-mod/list-template-parts.
	 *
	 * @param mixed $input Input data (no parameters required).
	 *
	 * @return array<string, mixed>
	 * @since 1.1.5
	 */
	public function executeListTemplateParts($input = null): array
	{
		if (! function_exists('get_block_templates')) {
			return ['theme' => get_stylesheet(), 'template_parts' => []];
		}

		$blockTemplates = get_block_templates([], 'wp_template_part');

		$parts = array_map(static function ($tpl) {
			$item = [
				'slug'  => $tpl->slug,
				'title' => $tpl->title,
				'area'  => (string) ($tpl->area ?? ''),
			];

			if (! empty($tpl->wp_id)) {
				$item['post_id'] = (int) $tpl->wp_id;
			}

			return $item;
		}, $blockTemplates);

		usort($parts, static fn ($a, $b) => strcmp($a['slug'], $b['slug']));

		return [
			'theme'          => get_stylesheet(),
			'template_parts' => $parts,
		];
	}

	/**
	 * Execute callback for agent-mod/get-template-part.
	 *
	 * @param mixed $input Input data (expects 'slug').
	 *
	 * @return array<string, mixed>
	 * @since 1.1.5
	 */
	public function executeGetTemplatePart($input = null): array
	{
		return $this->readTemplateObject($input, 'wp_template_part');
	}

	/**
	 * Execute callback for agent-mod/add-or-update-template-part.
	 *
	 * @param mixed $input Input data (expects 'html' and either 'post_id' or 'slug').
	 *
	 * @return array<string, mixed>
	 * @since 1.1.5
	 */
	public function executeAddOrUpdateTemplatePart($input = null): array
	{
		return $this->writeTemplateObject($input, 'wp_template_part');
	}

	/**
	 * Reads a template or template part by slug for the active theme.
	 *
	 * Not-found is an explicit error (never an empty-html success shape): the
	 * model must be able to distinguish "wrong slug / wrong object type" from
	 * "template exists but is empty", and be told how to recover.
	 *
	 * @param mixed  $input    Input data (expects 'slug').
	 * @param string $postType 'wp_template' or 'wp_template_part'.
	 *
	 * @return array<string, mixed>
	 * @since 1.1.5
	 */
	private function readTemplateObject($input, string $postType): array
	{
		$isPart = 'wp_template_part' === $postType;
		$label  = $isPart ? __('Template part', 'agent-mod') : __('Template', 'agent-mod');

		if (! is_array($input) || empty($input['slug'])) {
			return [
				'success' => false,
				/* translators: %s: object label (Template / Template part). */
				'error'   => sprintf(__('%s slug is required.', 'agent-mod'), $label),
			];
		}

		$slug       = sanitize_title((string) $input['slug']);
		$templateId = get_stylesheet() . '//' . $slug;
		$template   = get_block_template($templateId, $postType);

		if (! $template) {
			return [
				'success' => false,
				'error'   => $isPart
					? sprintf(
						/* translators: %1$s: template part slug, %2$s: theme slug. */
						__('Template part "%1$s" not found for theme "%2$s". Call agent-mod/list-template-parts for valid slugs, or create it with agent-mod/add-or-update-template-part. If you meant a full template (front-page, single, …), use the template abilities instead.', 'agent-mod'),
						$slug,
						get_stylesheet()
					)
					: sprintf(
						/* translators: %1$s: template slug, %2$s: theme slug. */
						__('Template "%1$s" not found for theme "%2$s". Call agent-mod/list-templates for valid slugs, or create it with agent-mod/add-or-update-template. If you meant a header/footer/sidebar template part, use the template-part abilities instead.', 'agent-mod'),
						$slug,
						get_stylesheet()
					),
			];
		}

		$result = [
			'success' => true,
			'slug'    => $template->slug,
			'title'   => $template->title,
			'html'    => $template->content,
			// 'theme' = shipped by the theme files (read-only on disk, editable
			// via a DB override with the same slug); 'custom' = DB record.
			'source'  => (string) ($template->source ?? ''),
		];

		if ($isPart) {
			$result['area'] = (string) ($template->area ?? '');
		}

		if (! empty($template->wp_id)) {
			$result['post_id'] = (int) $template->wp_id;
		}

		return $result;
	}

	/**
	 * Creates or updates a template / template part database record.
	 *
	 * Override-by-slug model: themes ship read-only template files; saving a
	 * wp_template / wp_template_part post with the same slug (and the active
	 * theme's wp_theme term) makes WordPress use the database version instead.
	 * The slug branch therefore:
	 *  1. updates the existing DB override when one exists (never inserts a
	 *     duplicate that would get renamed to "header-2" and silently stop
	 *     overriding), and
	 *  2. inserts a new record otherwise — including slugs the theme does not
	 *     ship a file for (e.g. creating a front-page template from scratch).
	 *
	 * @param mixed  $input    Input data (expects 'html' and either 'post_id' or 'slug';
	 *                         optional 'title'; optional 'area' for template parts).
	 * @param string $postType 'wp_template' or 'wp_template_part'.
	 *
	 * @return array<string, mixed>
	 * @since 1.1.5
	 */
	private function writeTemplateObject($input, string $postType): array
	{
		$isPart = 'wp_template_part' === $postType;
		$label  = $isPart ? __('Template part', 'agent-mod') : __('Template', 'agent-mod');

		if (! is_array($input)) {
			return ['success' => false, 'error' => __('Invalid input.', 'agent-mod')];
		}

		if (! empty($input['post_id']) && ! empty($input['slug'])) {
			return [
				'success' => false,
				'error'   => __('Provide either post_id or slug, not both. Post_id for update, slug for create-or-override.', 'agent-mod'),
			];
		}

		$postId = null;
		$slug   = '';
		$title  = sanitize_text_field((string) ($input['title'] ?? ''));
		$action = 'updated';

		if (! empty($input['post_id'])) {
			$postId = absint($input['post_id']);
			$post   = get_post($postId);

			if (! $post || $post->post_type !== $postType) {
				return [
					'success' => false,
					'error'   => sprintf(
						/* translators: %1$s: object label (Template / Template part), %2$d: post ID. */
						__('%1$s with post_id %2$d not found.', 'agent-mod'),
						$label,
						$postId
					),
				];
			}

			$slug = (string) $post->post_name;
		} elseif (! empty($input['slug'])) {
			$slug = sanitize_title((string) $input['slug']);

			if ('' === $slug) {
				return ['success' => false, 'error' => __('Invalid slug.', 'agent-mod')];
			}

			// An existing DB override for this slug+theme must be updated in
			// place — a blind insert would get a "-2" suffixed slug and never
			// take effect.
			$existingId = $this->findTemplateObjectPost($slug, $postType);

			if ($existingId > 0) {
				$postId = $existingId;
			} elseif ('' === $title) {
				// Derive the title from the theme-provided file when there is
				// one; otherwise from the slug (creating a template the theme
				// ships no file for is a supported case).
				$themeTemplate = get_block_template(get_stylesheet() . '//' . $slug, $postType);
				$title         = $themeTemplate
					? (string) $themeTemplate->title
					: ucwords(str_replace(['-', '_'], ' ', $slug));
			}
		} else {
			return ['success' => false, 'error' => __('Either post_id or slug must be provided.', 'agent-mod')];
		}

		$blocks = $this->resolveBlocks($input);
		if (is_wp_error($blocks)) {
			return ['success' => false, 'error' => $blocks->get_error_message()];
		}

		$serializedContent = '';
		foreach ($blocks as $block) {
			$serializedContent .= serialize_block($block);
		}

		if (empty(trim($serializedContent))) {
			return ['success' => false, 'error' => __('Block serialization failed.', 'agent-mod')];
		}

		if ($postId) {
			$update = ['ID' => $postId, 'post_content' => $serializedContent];

			if ('' !== $title) {
				$update['post_title'] = $title;
			}

			$result = wp_update_post($update, true);

			if (is_wp_error($result)) {
				return ['success' => false, 'error' => $result->get_error_message()];
			}
		} else {
			$action = 'created';
			$postId = wp_insert_post(
				[
					'post_type'    => $postType,
					'post_status'  => 'publish',
					'post_name'    => $slug,
					'post_title'   => '' !== $title ? $title : $slug,
					'post_content' => $serializedContent,
				],
				true
			);

			if (is_wp_error($postId)) {
				return ['success' => false, 'error' => $postId->get_error_message()];
			}

			// Explicit term assignment: tax_input silently skips terms when the
			// current user lacks the taxonomy caps in some contexts.
			wp_set_object_terms($postId, get_stylesheet(), 'wp_theme');
		}

		if ($isPart) {
			$area = $this->resolveTemplatePartArea((string) ($input['area'] ?? ''), (int) $postId, 'created' === $action);

			if ('' !== $area) {
				wp_set_object_terms((int) $postId, $area, 'wp_template_part_area');
			}
		}

		return [
			'success' => true,
			'post_id' => (int) $postId,
			'action'  => $action,
			'slug'    => $slug,
		];
	}

	/**
	 * Finds the existing database record for a template/template-part slug in
	 * the active theme.
	 *
	 * @param string $slug     Object slug.
	 * @param string $postType 'wp_template' or 'wp_template_part'.
	 *
	 * @return int Post ID, or 0 when no DB record exists.
	 * @since 1.1.5
	 */
	private function findTemplateObjectPost(string $slug, string $postType): int
	{
		$query = new WP_Query([
			'post_type'      => $postType,
			'post_status'    => 'publish',
			'name'           => $slug,
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => 'wp_theme',
					'field'    => 'name',
					'terms'    => get_stylesheet(),
				],
			],
		]);

		return empty($query->posts) ? 0 : (int) $query->posts[0]->ID;
	}

	/**
	 * Resolves the wp_template_part_area term to assign.
	 *
	 * An explicitly provided area is validated against the allowed areas. On
	 * create without an explicit area the slug-matching default (header/footer)
	 * or the uncategorized fallback applies; on update the stored area is kept.
	 *
	 * @param string $requested Requested area slug ('' for none).
	 * @param int    $postId    Template part post ID.
	 * @param bool   $isCreate  Whether the record was just created.
	 *
	 * @return string Area slug to assign, or '' to leave the terms untouched.
	 * @since 1.1.5
	 */
	private function resolveTemplatePartArea(string $requested, int $postId, bool $isCreate): string
	{
		$allowed = [];

		if (function_exists('get_allowed_block_template_part_areas')) {
			$allowed = array_column((array) get_allowed_block_template_part_areas(), 'area');
		}

		$requested = sanitize_title($requested);

		if ('' !== $requested && (empty($allowed) || in_array($requested, $allowed, true))) {
			return $requested;
		}

		if (! $isCreate) {
			return '';
		}

		// Default for new parts: match by slug convention, else uncategorized.
		$slug = (string) get_post_field('post_name', $postId);

		foreach (['header', 'footer'] as $wellKnown) {
			if ($slug === $wellKnown || 0 === strpos($slug, $wellKnown . '-')) {
				return $wellKnown;
			}
		}

		return defined('WP_TEMPLATE_PART_AREA_UNCATEGORIZED') ? WP_TEMPLATE_PART_AREA_UNCATEGORIZED : 'uncategorized';
	}

	// =========================================================================
	// Block Design: Post/Page execute callbacks
	// =========================================================================

	/**
	 * Execute callback for agent-mod/list-posts.
	 *
	 * @param mixed $input Input data (optional filtering/pagination parameters).
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function executeListPosts($input = null): array
	{
		$input        = is_array($input) ? $input : [];
		$postType     = ! empty($input['post_type']) ? sanitize_key((string) $input['post_type']) : 'post';
		$maxResults   = $this->settings_service->getMaxSearchResults();
		$postsPerPage = isset($input['posts_per_page']) ? min(absint($input['posts_per_page']), $maxResults) : min(10, $maxResults);
		$paged        = isset($input['paged']) ? max(absint($input['paged']), 1) : 1;
		$search       = isset($input['s']) ? sanitize_text_field((string) $input['s']) : '';
		$orderby      = isset($input['orderby']) ? $input['orderby'] : 'title';
		$order        = isset($input['order']) ? strtoupper((string) $input['order']) : 'ASC';

		$queryArgs = [
			'post_type'      => $postType,
			'post_status'    => 'publish',
			'posts_per_page' => $postsPerPage,
			'paged'          => $paged,
			'orderby'        => $orderby,
			'order'          => $order,
		];

		if (! empty($search)) {
			$queryArgs['s'] = $search;
		}

		$query = new WP_Query($queryArgs);

		if (! $query->have_posts()) {
			return [
				'success'    => true,
				'posts'      => [],
				'pagination' => [
					'total_posts'    => 0,
					'total_pages'    => 0,
					'current_page'   => $paged,
					'posts_per_page' => $postsPerPage,
					'has_more'       => false,
				],
			];
		}

		$posts = array_map(static function ($post) {
			return [
				'post_id'   => $post->ID,
				'post_name' => $post->post_name,
				'title'     => $post->post_title,
				'post_type' => $post->post_type,
				'status'    => $post->post_status,
				'modified'  => $post->post_modified,
				'url'       => get_permalink($post->ID),
			];
		}, $query->posts);

		return [
			'success'    => true,
			'posts'      => $posts,
			'pagination' => [
				'total_posts'    => (int) $query->found_posts,
				'total_pages'    => (int) $query->max_num_pages,
				'current_page'   => $paged,
				'posts_per_page' => $postsPerPage,
				'has_more'       => $paged < $query->max_num_pages,
			],
		];
	}

	/**
	 * Execute callback for agent-mod/get-post.
	 *
	 * @param mixed $input Input data (expects 'post_id').
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function executeGetPost($input = null): array
	{
		// Full-content reads are capped per request to control latency and token cost.
		static $fullContentReads = 0;

		if (! is_array($input) || empty($input['post_id'])) {
			return ['success' => false, 'error' => __('post_id is required.', 'agent-mod')];
		}

		$maxReads = $this->settings_service->getMaxFullContentPosts();
		if ($fullContentReads >= $maxReads) {
			return [
				'success' => false,
				'error'   => sprintf(
					/* translators: %d: maximum number of full-content post reads per message. */
					__('Full-content read limit reached: at most %d posts can be read in full per message. Summarize what you already have, or ask the user to narrow the request.', 'agent-mod'),
					$maxReads
				),
			];
		}

		$postId   = absint($input['post_id']);
		$postType = ! empty($input['post_type']) ? sanitize_key((string) $input['post_type']) : 'post';
		$post     = get_post($postId);

		if (! $post || $post->post_type !== $postType) {
			return [
				'success' => false,
				'error'   => sprintf(
					/* translators: 1: post ID, 2: expected post type */
					__('Post with ID %1$d not found or is not of type "%2$s". For templates use get-template instead.', 'agent-mod'),
					$postId,
					$postType
				),
			];
		}

		$fullContentReads++;

		return [
			'success'   => true,
			'post_id'   => $post->ID,
			'post_name' => $post->post_name,
			'title'     => $post->post_title,
			'post_type' => $post->post_type,
			'url'       => (string) get_permalink($post->ID),
			'html'      => $post->post_content,
		];
	}

	/**
	 * Execute callback for agent-mod/create-post.
	 *
	 * @param mixed $input Input data (expects 'title', optional 'html', 'post_type', 'post_status').
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function executeCreatePost($input = null): array
	{
		if (! is_array($input) || empty($input['title'])) {
			return ['success' => false, 'error' => __('A non-empty title is required to create a post/page.', 'agent-mod')];
		}

		$title      = sanitize_text_field((string) $input['title']);
		$postType   = (isset($input['post_type']))
			? sanitize_text_field($input['post_type'])
			: 'post';
		$postStatus = (isset($input['post_status']))
			? sanitize_text_field($input['post_status'])
			: 'draft';

		$serializedContent = '';

		if (! empty($input['html'])) {
			$blocks = $this->resolveBlocks($input);
			if (is_wp_error($blocks)) {
				return ['success' => false, 'error' => $blocks->get_error_message()];
			}

			foreach ($blocks as $block) {
				$serializedContent .= serialize_block($block);
			}

			if (empty(trim($serializedContent))) {
				return ['success' => false, 'error' => __('Block serialization failed. Check your block structure.', 'agent-mod')];
			}
		}

		$result = wp_insert_post(
			[
				'post_title'   => $title,
				'post_content' => $serializedContent,
				'post_type'    => $postType,
				'post_status'  => $postStatus,
			],
			true
		);

		if (is_wp_error($result)) {
			return ['success' => false, 'error' => $result->get_error_message()];
		}

		return [
			'success'   => true,
			'post_id'   => (int) $result,
			'post_type' => $postType,
			'status'    => $postStatus,
			'url'       => (string) get_permalink($result),
		];
	}

	/**
	 * Execute callback for agent-mod/update-post.
	 *
	 * @param mixed $input Input data (expects 'post_id', 'html', optional 'title').
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function executeUpdatePost($input = null): array
	{
		if (! is_array($input) || empty($input['post_id']) || empty($input['html'])) {
			return ['success' => false, 'error' => __('post_id and html are required.', 'agent-mod')];
		}

		$postId   = absint($input['post_id']);
		$postType = ! empty($input['post_type']) ? sanitize_key((string) $input['post_type']) : 'post';
		$post     = get_post($postId);

		if (! $post || $post->post_type !== $postType) {
			return [
				'success' => false,
				'error'   => sprintf(
					/* translators: 1: post ID, 2: expected post type */
					__('Post with ID %1$d not found or is not of type "%2$s". For templates use add-or-update-template instead.', 'agent-mod'),
					$postId,
					$postType
				),
			];
		}

		$blocks = $this->resolveBlocks($input);
		if (is_wp_error($blocks)) {
			return ['success' => false, 'error' => $blocks->get_error_message()];
		}

		$serializedContent = '';
		foreach ($blocks as $block) {
			$serializedContent .= serialize_block($block);
		}

		if (empty(trim($serializedContent))) {
			return ['success' => false, 'error' => __('Block serialization failed. Check your updated block structure.', 'agent-mod')];
		}

		$updateArgs = [
			'ID'           => $postId,
			'post_content' => $serializedContent,
		];

		if (! empty($input['title'])) {
			$updateArgs['post_title'] = sanitize_text_field((string) $input['title']);
		}

		$result = wp_update_post($updateArgs);

		if (is_wp_error($result)) {
			return ['success' => false, 'error' => $result->get_error_message()];
		}

		return [
			'success'   => true,
			'post_id'   => $postId,
			'post_type' => $post->post_type,
			'url'       => (string) get_permalink($postId),
		];
	}

	// =========================================================================
	// Block Design: Pattern execute callbacks
	// =========================================================================

	/**
	 * Execute callback for agent-mod/list-patterns.
	 *
	 * @param mixed $input Input data (optional 'source', 'category', 'search').
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function executeListPatterns($input = null): array
	{
		$input    = is_array($input) ? $input : [];
		$source   = isset($input['source']) ? $input['source'] : 'all';
		$category = isset($input['category']) ? sanitize_text_field((string) $input['category']) : '';
		$search   = isset($input['search']) ? strtolower(sanitize_text_field((string) $input['search'])) : '';

		$registryPatterns = [];
		$databasePatterns = [];

		if (in_array($source, ['all', 'registry'], true)) {
			$allRegistered = WP_Block_Patterns_Registry::get_instance()->get_all_registered();

			foreach ($allRegistered as $pattern) {
				if (strpos($pattern['name'], 'core/') === 0) {
					continue;
				}

				if ($category && (empty($pattern['categories']) || ! in_array($category, $pattern['categories'], true))) {
					continue;
				}

				if ($search && strpos(strtolower($pattern['title']), $search) === false) {
					continue;
				}

				$registryPatterns[] = [
					'name'        => $pattern['name'],
					'title'       => $pattern['title'],
					'description' => $pattern['description'] ?? '',
					'source'      => $pattern['source'] ?? 'theme',
					'categories'  => $pattern['categories'] ?? [],
				];
			}
		}

		if (in_array($source, ['all', 'database'], true)) {
			$queryArgs = [
				'post_type'      => 'wp_block',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			];

			if ($search) {
				$queryArgs['s'] = $search;
			}

			$dbPosts = get_posts($queryArgs);

			foreach ($dbPosts as $post) {
				$terms      = get_the_terms($post->ID, 'wp_pattern_category');
				$categories = ($terms && ! is_wp_error($terms)) ? wp_list_pluck($terms, 'slug') : [];
				$syncMeta   = get_post_meta($post->ID, 'wp_pattern_sync_status', true);
				$syncStatus = ($syncMeta === 'unsynced') ? 'unsynced' : 'synced';

				$databasePatterns[] = [
					'post_id'     => $post->ID,
					'title'       => $post->post_title,
					'post_name'   => $post->post_name,
					'sync_status' => $syncStatus,
					'categories'  => $categories,
					'modified'    => $post->post_modified,
				];
			}
		}

		return [
			'success'           => true,
			'registry_patterns' => $registryPatterns,
			'database_patterns' => $databasePatterns,
			'totals'            => [
				'registry' => count($registryPatterns),
				'database' => count($databasePatterns),
			],
		];
	}

	/**
	 * Execute callback for agent-mod/get-pattern.
	 *
	 * @param mixed $input Input data (expects 'source' and either 'slug' or 'post_id').
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function executeGetPattern($input = null): array
	{
		if (! is_array($input) || empty($input['source'])) {
			return ['success' => false, 'error' => __('source is required.', 'agent-mod')];
		}

		$source = $input['source'];

		if ($source === 'registry') {
			if (empty($input['slug'])) {
				return ['success' => false, 'error' => __('slug is required when source is "registry".', 'agent-mod')];
			}

			$slug    = sanitize_text_field((string) $input['slug']);
			$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered($slug);

			if (! $pattern) {
				return [
					'success' => false,
					'error'   => sprintf(
						/* translators: %s: pattern slug */
						__('Registry pattern "%s" not found. Use list-patterns to see available patterns.', 'agent-mod'),
						$slug
					),
				];
			}

			return [
				'success'     => true,
				'source'      => 'registry',
				'slug'        => $pattern['name'],
				'title'       => $pattern['title'],
				'is_editable' => false,
				'html'        => $pattern['content'],
			];
		}

		if ($source === 'database') {
			if (empty($input['post_id'])) {
				return ['success' => false, 'error' => __('post_id is required when source is "database".', 'agent-mod')];
			}

			$postId = absint($input['post_id']);
			$post   = get_post($postId);

			if (! $post || $post->post_type !== 'wp_block') {
				return [
					'success' => false,
					'error'   => sprintf(
						/* translators: %d: pattern post ID */
						__('Database pattern with post_id %d not found.', 'agent-mod'),
						$postId
					),
				];
			}

			$syncMeta   = get_post_meta($post->ID, 'wp_pattern_sync_status', true);
			$syncStatus = ($syncMeta === 'unsynced') ? 'unsynced' : 'synced';

			return [
				'success'     => true,
				'source'      => 'database',
				'post_id'     => $post->ID,
				'slug'        => $post->post_name,
				'title'       => $post->post_title,
				'sync_status' => $syncStatus,
				'is_editable' => true,
				'html'        => $post->post_content,
			];
		}

		return ['success' => false, 'error' => __('Invalid source. Must be "registry" or "database".', 'agent-mod')];
	}

	/**
	 * Execute callback for agent-mod/update-pattern.
	 *
	 * @param mixed $input Input data (expects 'post_id', 'html', optional 'title').
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function executeUpdatePattern($input = null): array
	{
		if (! is_array($input) || empty($input['post_id']) || empty($input['html'])) {
			return ['success' => false, 'error' => __('post_id and html are required.', 'agent-mod')];
		}

		$postId = absint($input['post_id']);
		$post   = get_post($postId);

		if (! $post || $post->post_type !== 'wp_block') {
			return [
				'success' => false,
				'error'   => sprintf(
					/* translators: %d: pattern post ID */
					__('Database pattern with post_id %d not found. Only wp_block posts can be updated. Registry patterns are read-only.', 'agent-mod'),
					$postId
				),
			];
		}

		$blocks = $this->resolveBlocks($input);
		if (is_wp_error($blocks)) {
			return ['success' => false, 'error' => $blocks->get_error_message()];
		}

		$serializedContent = '';
		foreach ($blocks as $block) {
			$serializedContent .= serialize_block($block);
		}

		if (empty(trim($serializedContent))) {
			return ['success' => false, 'error' => __('Block serialization failed.', 'agent-mod')];
		}

		$updateArgs = [
			'ID'           => $postId,
			'post_content' => $serializedContent,
		];

		if (! empty($input['title'])) {
			$updateArgs['post_title'] = sanitize_text_field((string) $input['title']);
		}

		$result = wp_update_post($updateArgs);

		if (is_wp_error($result)) {
			return ['success' => false, 'error' => $result->get_error_message()];
		}

		$syncMeta   = get_post_meta($postId, 'wp_pattern_sync_status', true);
		$syncStatus = ($syncMeta === 'unsynced') ? 'unsynced' : 'synced';

		return [
			'success'     => true,
			'post_id'     => $postId,
			'title'       => get_post($postId)->post_title,
			'sync_status' => $syncStatus,
		];
	}

	/**
	 * Execute callback for agent-mod/duplicate-pattern.
	 *
	 * @param mixed $input Input data (expects 'slug', optional 'title', 'sync_status').
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function executeDuplicatePattern($input = null): array
	{
		if (! is_array($input) || empty($input['slug'])) {
			return ['success' => false, 'error' => __('slug is required.', 'agent-mod')];
		}

		$slug    = sanitize_text_field((string) $input['slug']);
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered($slug);

		if (! $pattern) {
			return [
				'success' => false,
				'error'   => sprintf(
					/* translators: %s: pattern slug */
					__('Registry pattern "%s" not found. Use list-patterns (source: "registry") to see available patterns.', 'agent-mod'),
					$slug
				),
			];
		}

		$title      = ! empty($input['title'])
			? sanitize_text_field((string) $input['title'])
			: $pattern['title'] . __(' (Copy)', 'agent-mod');
		$syncStatus = (isset($input['sync_status']) && $input['sync_status'] === 'synced') ? 'synced' : 'unsynced';
		$content    = $pattern['content'];

		$postId = wp_insert_post([
			'post_type'    => 'wp_block',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $content,
		]);

		if (is_wp_error($postId)) {
			return ['success' => false, 'error' => $postId->get_error_message()];
		}

		if ($syncStatus === 'unsynced') {
			update_post_meta($postId, 'wp_pattern_sync_status', 'unsynced');
		}

		if (! empty($pattern['categories'])) {
			wp_set_object_terms($postId, $pattern['categories'], 'wp_pattern_category');
		}

		return [
			'success'       => true,
			'post_id'       => $postId,
			'title'         => $title,
			'slug'          => get_post($postId)->post_name,
			'sync_status'   => $syncStatus,
			'original_slug' => $slug,
			'html'          => $content,
		];
	}

	/**
	 * Execute callback for agent-mod/create-pattern.
	 *
	 * @param mixed $input Input data (expects 'title', 'html', optional 'description', 'categories', 'sync_status').
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function executeCreatePattern($input = null): array
	{
		if (! is_array($input) || empty($input['title']) || empty($input['html'])) {
			return ['success' => false, 'error' => __('title and html are required.', 'agent-mod')];
		}

		$title       = sanitize_text_field((string) $input['title']);
		$description = isset($input['description']) ? sanitize_text_field((string) $input['description']) : '';
		$categories  = isset($input['categories']) ? array_map('sanitize_text_field', (array) $input['categories']) : [];
		$syncStatus  = (isset($input['sync_status']) && $input['sync_status'] === 'synced') ? 'synced' : 'unsynced';

		$blocks = $this->resolveBlocks($input);
		if (is_wp_error($blocks)) {
			return ['success' => false, 'error' => $blocks->get_error_message()];
		}

		$serializedContent = '';
		foreach ($blocks as $block) {
			$serializedContent .= serialize_block($block);
		}

		if (empty(trim($serializedContent))) {
			return ['success' => false, 'error' => __('Block serialization failed. Check your block structure.', 'agent-mod')];
		}

		$postId = wp_insert_post([
			'post_type'    => 'wp_block',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $serializedContent,
			'post_excerpt' => $description,
		]);

		if (is_wp_error($postId)) {
			return ['success' => false, 'error' => $postId->get_error_message()];
		}

		if ($syncStatus === 'unsynced') {
			update_post_meta($postId, 'wp_pattern_sync_status', 'unsynced');
		}

		if (! empty($categories)) {
			$termIds = [];
			foreach ($categories as $catSlug) {
				$term = get_term_by('slug', $catSlug, 'wp_pattern_category');
				if (! $term) {
					$newTerm = wp_insert_term(
						ucwords(str_replace('-', ' ', $catSlug)),
						'wp_pattern_category',
						['slug' => $catSlug]
					);
					if (! is_wp_error($newTerm)) {
						$termIds[] = $newTerm['term_id'];
					}
				} else {
					$termIds[] = $term->term_id;
				}
			}

			if (! empty($termIds)) {
				wp_set_object_terms($postId, $termIds, 'wp_pattern_category');
			}
		}

		return [
			'success'     => true,
			'post_id'     => $postId,
			'title'       => $title,
			'slug'        => get_post($postId)->post_name,
			'sync_status' => $syncStatus,
			'categories'  => $categories,
			'html'        => $serializedContent,
		];
	}

	// =========================================================================
	// Block Design: Global Styles execute callbacks
	// =========================================================================

	/**
	 * Execute callback for agent-mod/get-global-styles.
	 *
	 * @param mixed $input Input data (optional 'origin', 'sections').
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function executeGetGlobalStyles($input = null): array
	{
		$input    = is_array($input) ? $input : [];
		$origin   = (isset($input['origin']) && $input['origin'] === 'base') ? 'base' : 'all';
		$sections = isset($input['sections']) ? $input['sections'] : ['settings', 'styles', 'user_overrides', 'theme_info'];

		$theme  = wp_get_theme();
		$result = ['success' => true];

		if (in_array('theme_info', $sections, true)) {
			$hasUserOverrides = false;
			$userCpt          = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles($theme);

			if (! empty($userCpt) && ! empty($userCpt['post_content'])) {
				$decoded          = json_decode($userCpt['post_content'], true);
				$hasUserOverrides = ! empty($decoded['settings']) || ! empty($decoded['styles']);
			}

			$result['theme_info'] = [
				'name'               => $theme->get('Name'),
				'stylesheet'         => $theme->get_stylesheet(),
				'version'            => $theme->get('Version'),
				'is_block_theme'     => wp_is_block_theme(),
				'has_theme_json'     => wp_theme_has_theme_json(),
				'has_user_overrides' => $hasUserOverrides,
			];
		}

		if (in_array('settings', $sections, true)) {
			$context = $origin === 'base' ? ['origin' => 'base'] : [];

			// wp_get_global_settings() hands back WP_Theme_JSON's *internal*
			// representation, where presets sit under an origin key. Writing that
			// shape back is what corrupts the user CPT, so the read side flattens
			// it: what the model sees is what update-global-styles accepts.
			$result['settings'] = $this->flattenSettingsPresets(wp_get_global_settings([], $context));
		}

		if (in_array('styles', $sections, true)) {
			$context          = $origin === 'base' ? ['origin' => 'base'] : [];
			$result['styles'] = wp_get_global_styles([], $context);
		}

		if (in_array('user_overrides', $sections, true)) {
			$userCpt = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles($theme);

			if (empty($userCpt) || empty($userCpt['post_content'])) {
				$result['user_overrides'] = null;
			} else {
				$decoded  = json_decode($userCpt['post_content'], true);
				$settings = $decoded['settings'] ?? [];
				$styles   = $decoded['styles']   ?? [];

				$result['user_overrides'] = (empty($settings) && empty($styles))
					? null
					: [
						'post_id'  => (int) $userCpt['ID'],
						'settings' => $settings,
						'styles'   => $styles,
					];
			}
		}

		return $result;
	}

	/**
	 * Execute callback for agent-mod/update-global-styles.
	 *
	 * @param mixed $input Input data (expects at least one of 'settings' or 'styles').
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function executeUpdateGlobalStyles($input = null): array
	{
		if (! is_array($input) || (empty($input['settings']) && empty($input['styles']))) {
			return ['success' => false, 'error' => __('At least one of "settings" or "styles" must be provided.', 'agent-mod')];
		}

		if (isset($input['settings']) && ! is_array($input['settings'])) {
			return ['success' => false, 'error' => __('"settings" must be an object.', 'agent-mod')];
		}

		if (isset($input['styles']) && ! is_array($input['styles'])) {
			return ['success' => false, 'error' => __('"styles" must be an object.', 'agent-mod')];
		}

		// Presets that are not flat lists of preset objects break WP_Theme_JSON on
		// every front-end request, so they never reach the database: the payload is
		// rejected and the offending paths are named back to the caller.
		$issues           = [];
		$incomingSettings = isset($input['settings'])
			? $this->normalizeGlobalStylesSettings($input['settings'], $issues)
			: [];

		if (! empty($issues)) {
			return [
				'success' => false,
				'error'   => __('Nothing was saved — the payload contains invalid preset data.', 'agent-mod') . ' ' . implode(' ', $issues),
				'issues'  => $issues,
			];
		}

		$theme   = wp_get_theme();
		$userCpt = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles($theme);

		$current = [];
		if (! empty($userCpt) && ! empty($userCpt['post_content'])) {
			$decoded = json_decode($userCpt['post_content'], true);
			$current = is_array($decoded) ? $decoded : [];
		}

		// Repair whatever an earlier write left behind before merging on top of it,
		// so one good call is enough to heal an already-corrupted post.
		if (isset($current['settings']) && is_array($current['settings'])) {
			$discarded           = [];
			$current['settings'] = $this->normalizeGlobalStylesSettings($current['settings'], $discarded);
		}

		if (isset($input['settings'])) {
			$base                = isset($current['settings']) && is_array($current['settings']) ? $current['settings'] : [];
			$current['settings'] = $this->deepMerge($base, $incomingSettings);
		}

		if (isset($input['styles'])) {
			$base              = isset($current['styles']) && is_array($current['styles']) ? $current['styles'] : [];
			$current['styles'] = $this->deepMerge($base, $input['styles']);
		}

		// Without a version WP_Theme_JSON_Schema::migrate() throws the whole payload
		// away, and without the flag WP_Theme_JSON_Resolver ignores the post as
		// unescaped content — either way the changes would silently do nothing.
		if (! isset($current['version']) || ! is_int($current['version'])) {
			$current['version'] = class_exists('WP_Theme_JSON') ? WP_Theme_JSON::LATEST_SCHEMA : 3;
		}

		$current['isGlobalStylesUserThemeJSON'] = true;

		$json = wp_json_encode($current, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		if (false === $json) {
			return ['success' => false, 'error' => __('The merged global styles could not be encoded as JSON.', 'agent-mod')];
		}

		// wp_insert_post()/wp_update_post() expect slashed data and unslash before
		// writing: an unslashed font stack such as "\"DM Sans\", sans-serif" would
		// lose its backslashes and leave invalid JSON in post_content.
		$json    = wp_slash($json);
		$created = false;

		if (! empty($userCpt['ID'])) {
			$postId = wp_update_post([
				'ID'           => (int) $userCpt['ID'],
				'post_content' => $json,
			], true);
		} else {
			$created = true;
			$postId  = wp_insert_post([
				'post_name'    => sprintf('wp-global-styles-%s', urlencode($theme->get_stylesheet())),
				'post_title'   => 'Custom Styles', // Not translatable, matches core.
				'post_type'    => 'wp_global_styles',
				'post_status'  => 'publish',
				'post_content' => $json,
			], true);
		}

		if (is_wp_error($postId) || ! $postId) {
			$message = is_wp_error($postId) ? $postId->get_error_message() : __('Failed to save global styles.', 'agent-mod');
			return ['success' => false, 'error' => $message];
		}

		// The resolver finds the post through the wp_theme taxonomy; a new post
		// without that term is invisible to it and a fresh one is created on every
		// call until the lookup breaks on multiple matches.
		if ($created) {
			wp_set_object_terms((int) $postId, $theme->get_stylesheet(), 'wp_theme');
		}

		if (function_exists('wp_clean_theme_json_cache')) {
			wp_clean_theme_json_cache();
		} else {
			WP_Theme_JSON_Resolver::clean_cached_data();
		}

		return [
			'success' => true,
			'post_id' => (int) $postId,
			'created' => $created,
		];
	}

	// =========================================================================
	// Private helpers
	// =========================================================================

	/**
	 * Resolves a block array from ability input containing 'html' or 'blocks'.
	 *
	 * @param array<string, mixed> $input Ability input parameters.
	 *
	 * @return array<int, mixed>|WP_Error
	 * @since 1.0.0
	 */
	private function resolveBlocks(array $input)
	{
		if (! empty($input['html'])) {
			$html = (string) $input['html'];

			/**
			 * Filters the serialized block markup before it is parsed and saved.
			 *
			 * Extensions (e.g. AgentMod Pro) may inspect the markup and either
			 * return a corrected string or a WP_Error to reject the write — the
			 * error surfaces to the calling agent exactly like the built-in
			 * invalid_attributes / stray_content errors below.
			 *
			 * @since 1.1.0
			 *
			 * @param string               $html  Serialized block markup.
			 * @param array<string, mixed> $input The write ability input.
			 */
			$html = apply_filters('agent_mod_resolve_blocks_html', $html, $input);

			if (is_wp_error($html)) {
				return $html;
			}

			$html = (string) $html;

			// Malformed attribute JSON survives parse_blocks() (attributes are
			// silently nulled) and only fails later in the editor — reject it up
			// front. Detection lives in the shared validator so the linter and
			// this gate stay in lock-step; the write path returns on the first
			// offender with its own error code.
			$invalidAttrs = $this->blockMarkupValidator->invalidJsonAttributes($html);

			if (! empty($invalidAttrs)) {
				return new WP_Error(
					'invalid_attributes',
					sprintf(
						/* translators: %s: excerpt of the invalid attribute object. */
						__('Invalid JSON attribute object: "%s" — attributes must be strict JSON: double-quoted keys and strings, no trailing commas, no single quotes.', 'agent-mod'),
						mb_substr($invalidAttrs[0], 0, 80)
					)
				);
			}

			$blocks = parse_blocks($html);

			$strayContent = $this->blockMarkupValidator->strayContentExcerpts($blocks);

			if (! empty($strayContent)) {
				return new WP_Error(
					'stray_content',
					sprintf(
						/* translators: %s: excerpt of the stray content. */
						__('Content exists outside block delimiters and would be dropped: "%s". Wrap it in proper block markup and retry.', 'agent-mod'),
						mb_substr($strayContent[0], 0, 80)
					)
				);
			}

			$blocks = array_values(array_filter($blocks, static function ($block) {
				return ! empty($block['blockName']);
			}));

			if (empty($blocks)) {
				return new WP_Error('parse_failed', __('No valid blocks found in the provided block markup.', 'agent-mod'));
			}

			// parse_blocks() tolerates a missing closing tag and round-trips it
			// verbatim into the database, breaking the whole parent block chain in
			// the editor. Hard-block unbalanced markup here — the same detection the
			// read-only agent-mod/validate-block-markup ability exposes, now
			// enforced. Only tag balance is blocked (the reported failure, low
			// false-positive risk); softer diagnostics stay advisory in the ability.
			$tagIssues = $this->blockMarkupValidator->tagBalanceIssues($html);

			if (! empty($tagIssues)) {
				return new WP_Error('unbalanced_block_markup', implode(' ', $tagIssues));
			}

			return $blocks;
		}

		if (! empty($input['blocks'])) {
			return $input['blocks'];
		}

		return new WP_Error('missing_input', __('Either html or blocks must be provided.', 'agent-mod'));
	}

	/**
	 * Recursively deep-merges two arrays; override values win on scalar conflicts.
	 *
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $override
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	private function deepMerge(array $base, array $override): array
	{
		foreach ($override as $key => $value) {
			// Sequential arrays are leaf values here (preset lists, spacing.units):
			// merging them by index would keep stale trailing entries, so a palette
			// could never shrink. They replace the base value wholesale, which is
			// also how WP_Theme_JSON::merge() treats them.
			if (is_array($value) && ! $this->isList($value) && isset($base[$key]) && is_array($base[$key])) {
				$base[$key] = $this->deepMerge($base[$key], $value);
				continue;
			}

			$base[$key] = $value;
		}

		return $base;
	}

	/**
	 * Tells a sequential (list) array from an associative one.
	 *
	 * @param array<mixed> $value Array to test.
	 *
	 * @return bool True when the keys are 0..n-1, including for an empty array.
	 * @since 1.1.5
	 */
	private function isList(array $value): bool
	{
		if ([] === $value) {
			return true;
		}

		return array_keys($value) === range(0, count($value) - 1);
	}

	/**
	 * Builds the input schema for one preset list.
	 *
	 * @param string          $valueKey         Key carrying the preset value ('color', 'gradient', ...).
	 * @param string          $valueDescription Human description of that value.
	 * @param string|string[] $valueType        JSON schema type(s) accepted for the value.
	 *
	 * @return array<string, mixed>
	 * @since 1.1.5
	 */
	private static function presetListSchema(string $valueKey, string $valueDescription, $valueType = 'string'): array
	{
		return [
			'type'        => 'array',
			'description' => __('Flat list of presets. Replaces the existing list in full — include every preset you want to keep.', 'agent-mod'),
			'items'       => [
				'type'       => 'object',
				'required'   => ['slug', $valueKey],
				'properties' => [
					'slug'    => [
						'type'        => 'string',
						'description' => __('Stable identifier. It becomes has-{slug}-* class names and var:preset|...|{slug} references in block markup, so renaming one breaks every block using it.', 'agent-mod'),
					],
					'name'    => ['type' => 'string', 'description' => __('Label shown in the editor.', 'agent-mod')],
					$valueKey => ['type' => $valueType, 'description' => $valueDescription],
				],
			],
		];
	}

	/**
	 * Flattens origin-keyed presets so a settings tree can be handed to the model.
	 *
	 * Purely structural: values that are not arrays are left untouched, so a
	 * corrupted node stays visible in the output instead of being hidden.
	 *
	 * @param array<string, mixed> $settings Settings tree from wp_get_global_settings().
	 *
	 * @return array<string, mixed>
	 * @since 1.1.5
	 */
	private function flattenSettingsPresets(array $settings): array
	{
		$settings = $this->flattenPresetNode($settings);

		if (isset($settings['blocks']) && is_array($settings['blocks'])) {
			foreach ($settings['blocks'] as $blockName => $blockNode) {
				if (is_array($blockNode)) {
					$settings['blocks'][$blockName] = $this->flattenPresetNode($blockNode);
				}
			}
		}

		return $settings;
	}

	/**
	 * Flattens the origin-keyed presets of a single settings node.
	 *
	 * @param array<string, mixed> $node Root settings node or one settings.blocks.* node.
	 *
	 * @return array<string, mixed>
	 * @since 1.1.5
	 */
	private function flattenPresetNode(array $node): array
	{
		foreach (self::PRESET_PATHS as $dotted => $valueKey) {
			list($group, $leaf) = explode('.', $dotted);

			if (! isset($node[$group][$leaf]) || ! is_array($node[$group][$leaf])) {
				continue;
			}

			$node[$group][$leaf] = $this->flattenPresetOrigins($node[$group][$leaf]);
		}

		// spacingScale generates spacing presets and is keyed by origin too.
		if (isset($node['spacing']['spacingScale']) && is_array($node['spacing']['spacingScale'])) {
			$scale = $node['spacing']['spacingScale'];

			if (! empty(array_intersect(array_keys($scale), self::PRESET_ORIGINS))) {
				$flat = [];

				foreach (self::PRESET_ORIGINS as $origin) {
					if (isset($scale[$origin]) && is_array($scale[$origin])) {
						$flat = array_replace($flat, $scale[$origin]);
					}
				}

				$node['spacing']['spacingScale'] = $flat;
			}
		}

		return $node;
	}

	/**
	 * Collapses a preset value keyed by origin into a single flat list.
	 *
	 * Later origins win over earlier ones for the same slug, matching how
	 * WP_Theme_JSON resolves the layers. Values that are already flat lists, and
	 * associative arrays that are not keyed by origin, are returned untouched so
	 * the caller can decide what to do with them.
	 *
	 * @param array<mixed> $presets Preset value taken from a settings node.
	 *
	 * @return array<mixed>
	 * @since 1.1.5
	 */
	private function flattenPresetOrigins(array $presets): array
	{
		if ($this->isList($presets)) {
			return $presets;
		}

		if (empty(array_intersect(array_keys($presets), self::PRESET_ORIGINS))) {
			return $presets;
		}

		$bySlug = [];

		foreach (self::PRESET_ORIGINS as $origin) {
			if (! isset($presets[$origin]) || ! is_array($presets[$origin])) {
				continue;
			}

			foreach ($presets[$origin] as $preset) {
				if (! is_array($preset) || ! isset($preset['slug']) || ! is_scalar($preset['slug'])) {
					continue;
				}

				$bySlug[(string) $preset['slug']] = $preset;
			}
		}

		return array_values($bySlug);
	}

	/**
	 * Validates and normalizes a settings tree destined for the user's global styles.
	 *
	 * Every preset path is forced into the flat list of preset objects that
	 * WP_Theme_JSON expects from the 'custom' origin. Invalid entries are dropped
	 * and described in $issues; origin-keyed presets are flattened silently, since
	 * writing a 'theme' or 'default' origin from the user layer would overwrite the
	 * active theme's own presets.
	 *
	 * @param array<string, mixed> $settings Settings tree to normalize.
	 * @param array<int, string>   $issues   Collects human-readable problem descriptions.
	 *
	 * @return array<string, mixed>
	 * @since 1.1.5
	 */
	private function normalizeGlobalStylesSettings(array $settings, array &$issues): array
	{
		$settings = $this->normalizePresetNode($settings, 'settings', $issues);

		if (isset($settings['blocks']) && is_array($settings['blocks'])) {
			foreach ($settings['blocks'] as $blockName => $blockNode) {
				if (is_array($blockNode)) {
					$settings['blocks'][$blockName] = $this->normalizePresetNode(
						$blockNode,
						'settings.blocks.' . $blockName,
						$issues
					);
				}
			}
		}

		return $settings;
	}

	/**
	 * Normalizes the presets of a single settings node.
	 *
	 * @param array<string, mixed> $node   Root settings node or one settings.blocks.* node.
	 * @param string               $prefix Path prefix used in issue messages.
	 * @param array<int, string>   $issues Collects human-readable problem descriptions.
	 *
	 * @return array<string, mixed>
	 * @since 1.1.5
	 */
	private function normalizePresetNode(array $node, string $prefix, array &$issues): array
	{
		$emptied = [];

		foreach (self::PRESET_PATHS as $dotted => $valueKey) {
			list($group, $leaf) = explode('.', $dotted);

			if (! isset($node[$group]) || ! is_array($node[$group]) || ! array_key_exists($leaf, $node[$group])) {
				continue;
			}

			$label = $prefix . '.' . $dotted;
			$value = $node[$group][$leaf];

			if (! is_array($value)) {
				$issues[] = sprintf(
					/* translators: 1: settings path, 2: PHP type of the supplied value, 3: the supplied value. */
					__('%1$s must be a list of preset objects but %2$s was given (%3$s).', 'agent-mod'),
					$label,
					gettype($value),
					wp_json_encode($value)
				);
				unset($node[$group][$leaf]);
				$emptied[$group] = true;
				continue;
			}

			$value = $this->flattenPresetOrigins($value);

			if (! $this->isList($value)) {
				$issues[] = sprintf(
					/* translators: 1: settings path, 2: comma separated list of the keys found. */
					__('%1$s must be a list of preset objects, not an object keyed by %2$s.', 'agent-mod'),
					$label,
					implode(', ', array_slice(array_keys($value), 0, 5))
				);
				unset($node[$group][$leaf]);
				$emptied[$group] = true;
				continue;
			}

			$node[$group][$leaf] = $this->normalizePresetList($value, $valueKey, $label, $issues);
		}

		if (isset($node['spacing']) && is_array($node['spacing']) && array_key_exists('spacingScale', $node['spacing'])) {
			$scale = $node['spacing']['spacingScale'];

			if (! is_array($scale)) {
				$issues[] = sprintf(
					/* translators: 1: settings path, 2: PHP type of the supplied value. */
					__('%1$s must be an object such as {"steps":7,"mediumStep":1.5,"unit":"rem","operator":"*","increment":1.5} but %2$s was given.', 'agent-mod'),
					$prefix . '.spacing.spacingScale',
					gettype($scale)
				);
				unset($node['spacing']['spacingScale']);
				$emptied['spacing'] = true;
			} elseif (! empty(array_intersect(array_keys($scale), self::PRESET_ORIGINS))) {
				$flat = [];

				foreach (self::PRESET_ORIGINS as $origin) {
					if (isset($scale[$origin]) && is_array($scale[$origin])) {
						$flat = array_replace($flat, $scale[$origin]);
					}
				}

				$node['spacing']['spacingScale'] = $flat;
			}
		}

		// A group left empty by the checks above would serialize as a JSON list
		// where theme.json expects an object — the very confusion being removed.
		foreach (array_keys($emptied) as $group) {
			if (isset($node[$group]) && [] === $node[$group]) {
				unset($node[$group]);
			}
		}

		return $node;
	}

	/**
	 * Validates the individual presets of one already-flattened preset list.
	 *
	 * @param array<int, mixed>  $presets  Flat list of candidate presets.
	 * @param string             $valueKey Key carrying the preset value.
	 * @param string             $label    Full settings path, used in issue messages.
	 * @param array<int, string> $issues   Collects human-readable problem descriptions.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.1.5
	 */
	private function normalizePresetList(array $presets, string $valueKey, string $label, array &$issues): array
	{
		$clean = [];

		foreach ($presets as $index => $preset) {
			$itemLabel = $label . '[' . $index . ']';

			if (! is_array($preset)) {
				$issues[] = sprintf(
					/* translators: 1: settings path of the preset, 2: PHP type of the supplied value. */
					__('%1$s must be an object with a slug but %2$s was given.', 'agent-mod'),
					$itemLabel,
					gettype($preset)
				);
				continue;
			}

			$slug = isset($preset['slug']) && is_scalar($preset['slug']) ? trim((string) $preset['slug']) : '';

			if ('' === $slug) {
				$issues[] = sprintf(
					/* translators: %s: settings path of the preset. */
					__('%s is missing a non-empty "slug".', 'agent-mod'),
					$itemLabel
				);
				continue;
			}

			$preset['slug'] = $slug;

			// Duotone is the one preset whose value is a list of colors rather than
			// a single CSS value.
			if ('colors' === $valueKey) {
				$colors = [];

				if (isset($preset['colors']) && is_array($preset['colors'])) {
					foreach ($preset['colors'] as $color) {
						if (is_scalar($color) && '' !== trim((string) $color)) {
							$colors[] = (string) $color;
						}
					}
				}

				if (empty($colors)) {
					$issues[] = sprintf(
						/* translators: %s: settings path of the preset. */
						__('%s needs a "colors" array holding at least one CSS color.', 'agent-mod'),
						$itemLabel
					);
					continue;
				}

				$preset['colors'] = $colors;
				$clean[]          = $preset;
				continue;
			}

			$value = isset($preset[$valueKey]) && is_scalar($preset[$valueKey]) ? trim((string) $preset[$valueKey]) : '';

			if ('' === $value) {
				$issues[] = sprintf(
					/* translators: 1: settings path of the preset, 2: expected key name. */
					__('%1$s is missing a non-empty "%2$s" value; a preset without it is silently dropped by WordPress.', 'agent-mod'),
					$itemLabel,
					$valueKey
				);
				continue;
			}

			$preset[$valueKey] = $value;
			$clean[]           = $preset;
		}

		return $clean;
	}

	/**
	 * Ability callback for agent-mod/validate-block-markup.
	 *
	 * Thin boundary over the shared BlockMarkupValidator: unwraps the ability
	 * input and delegates the linting itself, so the advisory checks and the
	 * write-path enforcement can never drift apart.
	 *
	 * @param array<string, mixed> $args Ability input ({ markup: string }).
	 *
	 * @return array<string, mixed> { valid: bool, block_count: int, issues: string[] }
	 * @since 1.1.0
	 */
	public function validateBlockMarkup(array $args): array
	{
		return $this->blockMarkupValidator->validate((string) ($args['markup'] ?? ''));
	}
}
