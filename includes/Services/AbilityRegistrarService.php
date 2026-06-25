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

use AgentMod\Common\Constants;
use WP_Error;
use WP_Query;
use WP_Block_Patterns_Registry;
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
	 * Constructor. Binds the abilities API hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct()
	{
		add_action('wp_abilities_api_categories_init', [$this, 'registerCategories']);
		add_action('wp_abilities_api_init', [$this, 'registerAbilities']);

		// Mark the demo write ability as requiring confirmation before execution.
		add_filter('agent_mod_ability_requires_confirmation', [$this, 'requiresConfirmation'], 10, 2);
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
			'agent-mod/get-site-info',
			[
				'label'               => __('Get Site Info', 'agent-mod'),
				'description'         => __('Returns basic information about the current WordPress site: name, tagline, URL and WordPress version.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeGetSiteInfo'],
				'permission_callback' => static function (): bool {
					return current_user_can('read');
				},
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'name'        => ['type' => 'string'],
						'description' => ['type' => 'string'],
						'url'         => ['type' => 'string'],
						'wp_version'  => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/create-draft-post',
			[
				'label'               => __('Create Draft Post', 'agent-mod'),
				'description'         => __('Creates a new draft post with the given title and optional content. Requires user confirmation before execution.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeCreateDraftPost'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_posts');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'title'   => [
							'type'        => 'string',
							'description' => __('The post title.', 'agent-mod'),
						],
						'content' => [
							'type'        => 'string',
							'description' => __('The post content (optional).', 'agent-mod'),
						],
					],
					'required'   => ['title'],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'       => ['type' => 'integer'],
						'title'    => ['type' => 'string'],
						'edit_url' => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false],
					'show_in_rest' => true,
				],
			]
		);

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
							'maximum'     => Constants::aiMaxSearchResults(),
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
				'input_schema'        => [
					'type' => 'object',
				],
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
				'description'         => __('Returns a template\'s raw block markup as html by slug. Use list-templates first to get slugs. The returned html can be modified and passed straight back to add-or-update-template as the html parameter.', 'agent-mod'),
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
						'slug'    => ['type' => 'string'],
						'title'   => ['type' => 'string'],
						'post_id' => ['type' => 'integer'],
						'html'    => ['type' => 'string'],
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
				'description'         => __('Saves content to a template. Provide html and either post_id or slug (not both).', 'agent-mod'),
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
							'description' => __('Template slug. Use this to create (duplicate) a new template from a theme file.', 'agent-mod'),
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
							'enum'        => ['post', 'page', 'any'],
							'description' => __('"post", "page", or "any" (default).', 'agent-mod'),
						],
						'posts_per_page' => [
							'type'        => 'integer',
							'description' => __('Results per page. Default 10, max 50.', 'agent-mod'),
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
				'description'         => __('Returns a post/page raw block markup as html by post_id. Use list-posts to find post_id first. The returned html can be modified and passed straight back to update-post as the html parameter.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeGetPost'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_posts');
				},
				'input_schema'        => [
					'type'       => 'object',
					'required'   => ['post_id'],
					'properties' => [
						'post_id' => [
							'type'        => 'integer',
							'description' => __('Post/page ID from list-posts.', 'agent-mod'),
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
				'description'         => __('Creates a new post/page. Provide title and optionally html. The html is converted to blocks server-side, avoiding innerHTML/attributes validation errors. Returns the new post_id for use with get-post/update-post.', 'agent-mod'),
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
							'enum'        => ['post', 'page'],
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
				'description'         => __('Saves content to a post/page. Provide post_id and html. The html is converted to blocks server-side, avoiding innerHTML/attributes validation errors.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeUpdatePost'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_posts');
				},
				'input_schema'        => [
					'type'       => 'object',
					'required'   => ['post_id', 'html'],
					'properties' => [
						'post_id' => [
							'type'        => 'integer',
							'description' => __('Post/page ID from get-post.', 'agent-mod'),
						],
						'html'    => [
							'type'        => 'string',
							'description' => __('Serialized block markup (WordPress block comment format). Use the output of get-post for round-trip editing. Replaces existing content entirely.', 'agent-mod'),
						],
						'title'   => [
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
				'description'         => __('Updates a database pattern (wp_block). Provide post_id and html. The html is converted to blocks server-side, avoiding innerHTML/attributes validation errors.', 'agent-mod'),
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
				'description'         => __('Returns the active theme\'s design tokens from theme.json (colors, typography, spacing). Use this before editing templates or patterns to know available preset slugs for block attributes.', 'agent-mod'),
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
				'description'         => __('Merges the provided settings and/or styles into the active theme\'s user overrides (wp_global_styles post). Only supplied keys are changed; everything else is preserved. Creates the post if it does not yet exist.', 'agent-mod'),
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
							'description' => __('Partial settings object to deep-merge into global settings (e.g. {"color":{"palette":[...]}}).', 'agent-mod'),
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
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false],
					'show_in_rest' => true,
				],
			]
		);
	}

	// =========================================================================
	// Confirmation filter
	// =========================================================================

	/**
	 * Returns true for abilities that must be confirmed before execution.
	 *
	 * @param bool   $requires Current value.
	 * @param string $name     Ability slug.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function requiresConfirmation(bool $requires, string $name): bool
	{
		if ('agent-mod/create-draft-post' === $name) {
			return true;
		}

		return $requires;
	}

	// =========================================================================
	// AgentMod core execute callbacks
	// =========================================================================

	/**
	 * Execute callback for agent-mod/create-draft-post.
	 *
	 * @param mixed $input Input data (expects 'title' and optional 'content').
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.0.0
	 */
	public function executeCreateDraftPost($input = null)
	{
		if (! is_array($input) || empty($input['title'])) {
			return new WP_Error('missing_title', __('A post title is required.', 'agent-mod'));
		}

		$title   = sanitize_text_field((string) $input['title']);
		$content = isset($input['content']) ? wp_kses_post((string) $input['content']) : '';

		$postId = wp_insert_post(
			[
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => 'draft',
				'post_type'    => 'post',
			],
			true
		);

		if (is_wp_error($postId)) {
			return $postId;
		}

		return [
			'id'       => (int) $postId,
			'title'    => $title,
			'edit_url' => (string) get_edit_post_link($postId, 'raw'),
		];
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
		$count = max(1, min(Constants::aiMaxSearchResults(), $count));

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
		if (! is_array($input) || empty($input['slug'])) {
			return ['html' => ''];
		}

		$slug       = sanitize_title((string) $input['slug']);
		$templateId = get_stylesheet() . '//' . $slug;
		$template   = get_block_template($templateId, 'wp_template');

		if (! $template) {
			return ['html' => ''];
		}

		$result = [
			'slug'  => $template->slug,
			'title' => $template->title,
			'html'  => $template->content,
		];

		if (! empty($template->wp_id)) {
			$result['post_id'] = (int) $template->wp_id;
		}

		return $result;
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
		if (! is_array($input)) {
			return ['success' => false, 'error' => __('Invalid input.', 'agent-mod')];
		}

		if (! empty($input['post_id']) && ! empty($input['slug'])) {
			return [
				'success' => false,
				'error'   => __('Provide either post_id or slug, not both. Post_id for update, slug for create.', 'agent-mod'),
			];
		}

		$postId   = null;
		$template = null;

		if (! empty($input['post_id'])) {
			$postId = absint($input['post_id']);
			$post   = get_post($postId);

			if (! $post || $post->post_type !== 'wp_template') {
				return [
					'success' => false,
					'error'   => sprintf(
						/* translators: %d: template post ID */
						__('Template with post_id %d not found.', 'agent-mod'),
						$postId
					),
				];
			}
		} elseif (! empty($input['slug'])) {
			$templateId = get_stylesheet() . '//' . sanitize_title((string) $input['slug']);
			$template   = get_block_template($templateId, 'wp_template');

			if (! $template) {
				return [
					'success' => false,
					'error'   => sprintf(
						/* translators: %s: template slug */
						__('Template "%s" not found.', 'agent-mod'),
						sanitize_text_field((string) $input['slug'])
					),
				];
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
			$result = wp_update_post(['ID' => $postId, 'post_content' => $serializedContent]);

			if (is_wp_error($result)) {
				return ['success' => false, 'error' => $result->get_error_message()];
			}
		} else {
			$slug   = sanitize_title((string) $input['slug']);
			$postId = wp_insert_post([
				'post_type'    => 'wp_template',
				'post_status'  => 'publish',
				'post_name'    => $slug,
				'post_title'   => $template->title,
				'post_content' => $serializedContent,
				'tax_input'    => ['wp_theme' => [get_stylesheet()]],
			]);

			if (is_wp_error($postId)) {
				return ['success' => false, 'error' => $postId->get_error_message()];
			}
		}

		return ['success' => true, 'post_id' => (int) $postId];
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
		$postType     = isset($input['post_type']) ? $input['post_type'] : 'any';
		$postsPerPage = isset($input['posts_per_page']) ? min(absint($input['posts_per_page']), 50) : 10;
		$paged        = isset($input['paged']) ? max(absint($input['paged']), 1) : 1;
		$search       = isset($input['s']) ? sanitize_text_field((string) $input['s']) : '';
		$orderby      = isset($input['orderby']) ? $input['orderby'] : 'title';
		$order        = isset($input['order']) ? strtoupper((string) $input['order']) : 'ASC';

		$resolvedPostType = ($postType === 'any') ? ['post', 'page'] : $postType;

		$queryArgs = [
			'post_type'      => $resolvedPostType,
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
		if (! is_array($input) || empty($input['post_id'])) {
			return ['success' => false, 'error' => __('post_id is required.', 'agent-mod')];
		}

		$postId = absint($input['post_id']);
		$post   = get_post($postId);

		if (! $post || ! in_array($post->post_type, ['post', 'page'], true)) {
			return [
				'success' => false,
				'error'   => sprintf(
					/* translators: %d: post ID */
					__('Post/page with ID %d not found or is not a post/page type. For templates use get-template instead.', 'agent-mod'),
					$postId
				),
			];
		}

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
		$postType   = (isset($input['post_type']) && in_array($input['post_type'], ['post', 'page'], true))
			? $input['post_type']
			: 'post';
		$postStatus = (isset($input['post_status']) && in_array($input['post_status'], ['draft', 'publish', 'pending', 'private'], true))
			? $input['post_status']
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

		$postId = absint($input['post_id']);
		$post   = get_post($postId);

		if (! $post || ! in_array($post->post_type, ['post', 'page'], true)) {
			return [
				'success' => false,
				'error'   => sprintf(
					/* translators: %d: post ID */
					__('Post/page with ID %d not found or is not a post/page type. For templates use add-or-update-template instead.', 'agent-mod'),
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
			$context            = $origin === 'base' ? ['origin' => 'base'] : [];
			$result['settings'] = wp_get_global_settings([], $context);
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

		$theme   = wp_get_theme();
		$userCpt = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles($theme);

		$current = [];
		if (! empty($userCpt) && ! empty($userCpt['post_content'])) {
			$current = json_decode($userCpt['post_content'], true) ?: [];
		}

		if (isset($input['settings'])) {
			$current['settings'] = $this->deepMerge($current['settings'] ?? [], $input['settings']);
		}

		if (isset($input['styles'])) {
			$current['styles'] = $this->deepMerge($current['styles'] ?? [], $input['styles']);
		}

		$created = false;

		if (! empty($userCpt['ID'])) {
			$postId = wp_update_post([
				'ID'           => (int) $userCpt['ID'],
				'post_content' => wp_json_encode($current),
			]);
		} else {
			$created = true;
			$postId  = wp_insert_post([
				'post_name'    => 'wp-global-styles-' . $theme->get_stylesheet(),
				'post_title'   => 'Custom Styles',
				'post_type'    => 'wp_global_styles',
				'post_status'  => 'publish',
				'post_content' => wp_json_encode($current),
			]);
		}

		if (is_wp_error($postId) || ! $postId) {
			$message = is_wp_error($postId) ? $postId->get_error_message() : __('Failed to save global styles.', 'agent-mod');
			return ['success' => false, 'error' => $message];
		}

		WP_Theme_JSON_Resolver::clean_cached_data();

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
			$blocks = parse_blocks($input['html']);
			$blocks = array_values(array_filter($blocks, static function ($block) {
				return ! empty($block['blockName']);
			}));

			if (empty($blocks)) {
				return new WP_Error('parse_failed', __('No valid blocks found in the provided block markup.', 'agent-mod'));
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
			if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
				$base[$key] = $this->deepMerge($base[$key], $value);
			} else {
				$base[$key] = $value;
			}
		}

		return $base;
	}
}
