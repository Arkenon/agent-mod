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
use WP_Post;
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
	 * Post columns list-posts lets "s" search.
	 *
	 * @var string[]
	 * @since 1.3.3
	 */
	private const SEARCH_COLUMNS = ['post_title', 'post_excerpt', 'post_content'];

	/**
	 * Comparison operators accepted in a list-posts meta_query condition.
	 *
	 * @var string[]
	 * @since 1.3.3
	 */
	private const META_COMPARE = ['=', '!=', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'EXISTS', 'NOT EXISTS'];

	/**
	 * Value types accepted in a list-posts meta_query condition.
	 *
	 * @var string[]
	 * @since 1.3.3
	 */
	private const META_TYPES = ['CHAR', 'NUMERIC', 'DECIMAL', 'DATE', 'DATETIME'];

	/**
	 * Longest plain-text preview list-posts returns per post (characters).
	 *
	 * @var int
	 * @since 1.3.3
	 */
	private const MAX_CONTENT_PREVIEW = 500;

	/**
	 * Most conditions accepted in one list-posts meta_query or tax_query.
	 *
	 * @var int
	 * @since 1.3.3
	 */
	private const MAX_QUERY_CONDITIONS = 10;

	/**
	 * Unit (bytes) of context around a change in a bulk-replace snippet: twice
	 * this before the change, six times this in the whole snippet.
	 *
	 * @var int
	 * @since 1.3.3
	 */
	private const SNIPPET_CONTEXT = 60;

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
				'description'         => __('Returns a template part\'s raw block markup as html by slug (e.g. header, footer). Use list-template-parts first to get slugs. The returned html can be modified and passed straight back to add-or-update-template-part. A wp:navigation block\'s "ref" attribute is the ID of its menu: edit the menu items with update-navigation, not by changing this markup.', 'agent-mod'),
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
				'description'         => __('Returns a paginated list of posts/pages in any status (drafts included), with flexible filters. When the user names the posts (e.g. a list of titles or names), pass them all at once in "titles" instead of searching one by one or browsing. Narrow with include / exclude IDs, "s" + search_columns, content_contains / content_not_contains, meta_query, tax_query, author and date range. Set content_preview to see the start of each post\'s text without calling get-post. For the latest posts use orderby "date" with order "DESC". Keep the returned post_id list and reuse it; do not list the same posts again. To change the same text in many posts use preview-bulk-replace / bulk-replace-content instead of get-post + update-post per post.', 'agent-mod') . ' ' . $this->settings_service->getSearchLimitHint(),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeListPosts'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_posts');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_type'            => [
							'type'        => 'string',
							'description' => __('Any registered post type slug (e.g. "post", "page", or a custom post type), or "any" for all public post types. Default "post".', 'agent-mod'),
						],
						'post_status'          => [
							'type'        => 'string',
							'enum'        => ['any', 'publish', 'draft', 'pending', 'private', 'future'],
							'description' => __('Filter by status. Default "any" (every status except trash).', 'agent-mod'),
						],
						'posts_per_page'       => [
							'type'        => 'integer',
							'description' => sprintf(
								/* translators: %d: Max Search Results setting value. */
								__('Results per page. Default 10, maximum %d (Max Search Results setting); larger values are reduced. To get more, page with "paged" until pagination.has_more is false.', 'agent-mod'),
								$this->settings_service->getMaxSearchResults()
							),
						],
						'paged'                => [
							'type'        => 'integer',
							'description' => __('Page number. Default 1. A page past pagination.total_pages returns no posts.', 'agent-mod'),
						],
						's'                    => [
							'type'        => 'string',
							'description' => __('Keyword search. Searches title, excerpt and content unless search_columns narrows it.', 'agent-mod'),
						],
						'search_columns'       => [
							'type'        => 'array',
							'items'       => [
								'type' => 'string',
								'enum' => self::SEARCH_COLUMNS,
							],
							'description' => __('Optional. Columns "s" searches, e.g. ["post_title"] to search titles only.', 'agent-mod'),
						],
						'titles'               => [
							'type'        => 'array',
							'items'       => ['type' => 'string'],
							'description' => sprintf(
								/* translators: %d: maximum number of titles. */
								__('Optional. Find posts by title in one call (up to %d titles). Matching ignores case, extra spaces and accents (e.g. Turkish letters); a title with no exact match falls back to posts whose title contains it. The result lists title_matches and unmatched_titles.', 'agent-mod'),
								Constants::AI_MAX_BULK_POSTS
							),
						],
						'include'              => [
							'type'        => 'array',
							'items'       => ['type' => 'integer'],
							'description' => __('Optional. Only these post IDs. Results keep this order unless orderby is given.', 'agent-mod'),
						],
						'exclude'              => [
							'type'        => 'array',
							'items'       => ['type' => 'integer'],
							'description' => __('Optional. Leave these post IDs out.', 'agent-mod'),
						],
						'content_contains'     => [
							'type'        => 'string',
							'description' => __('Optional. Only posts whose raw content (block markup) contains this text.', 'agent-mod'),
						],
						'content_not_contains' => [
							'type'        => 'string',
							'description' => __('Optional. Only posts whose raw content does NOT contain this text.', 'agent-mod'),
						],
						'meta_key'             => [
							'type'        => 'string',
							'description' => __('Optional. Only return posts that have this custom field. For other comparisons use meta_query.', 'agent-mod'),
						],
						'meta_value'           => [
							'type'        => 'string',
							'description' => __('Optional, used with meta_key. Only return posts whose meta_key equals this value.', 'agent-mod'),
						],
						'meta_query'           => [
							'type'        => 'array',
							'description' => __('Optional. Custom field conditions, combined with meta_relation.', 'agent-mod'),
							'items'       => [
								'type'       => 'object',
								'required'   => ['key'],
								'properties' => [
									'key'     => ['type' => 'string'],
									'value'   => [
										'type'        => 'string',
										'description' => __('Value to compare. For IN / NOT IN a comma-separated list. Not used with EXISTS / NOT EXISTS.', 'agent-mod'),
									],
									'compare' => [
										'type'        => 'string',
										'enum'        => self::META_COMPARE,
										'description' => __('Default "=".', 'agent-mod'),
									],
									'type'    => [
										'type'        => 'string',
										'enum'        => self::META_TYPES,
										'description' => __('Value type for the comparison. Default "CHAR"; use "NUMERIC" for numbers.', 'agent-mod'),
									],
								],
							],
						],
						'meta_relation'        => [
							'type'        => 'string',
							'enum'        => ['AND', 'OR'],
							'description' => __('How meta_query conditions combine. Default "AND".', 'agent-mod'),
						],
						'tax_query'            => [
							'type'        => 'array',
							'description' => __('Optional. Taxonomy term conditions (categories, tags, custom taxonomies), all of which must match.', 'agent-mod'),
							'items'       => [
								'type'       => 'object',
								'required'   => ['taxonomy', 'terms'],
								'properties' => [
									'taxonomy' => ['type' => 'string'],
									'terms'    => [
										'type'  => 'array',
										'items' => ['type' => 'string'],
									],
									'field'    => [
										'type'        => 'string',
										'enum'        => ['slug', 'term_id', 'name'],
										'description' => __('What terms holds. Default "slug".', 'agent-mod'),
									],
									'operator' => [
										'type'        => 'string',
										'enum'        => ['IN', 'NOT IN', 'AND'],
										'description' => __('Default "IN".', 'agent-mod'),
									],
								],
							],
						],
						'author'               => [
							'type'        => 'integer',
							'description' => __('Optional. Only posts by this author (user ID).', 'agent-mod'),
						],
						'date_after'           => [
							'type'        => 'string',
							'description' => __('Optional. Only posts published on or after this date (YYYY-MM-DD).', 'agent-mod'),
						],
						'date_before'          => [
							'type'        => 'string',
							'description' => __('Optional. Only posts published on or before this date (YYYY-MM-DD).', 'agent-mod'),
						],
						'content_preview'      => [
							'type'        => 'integer',
							'description' => sprintf(
								/* translators: %d: maximum preview length. */
								__('Optional. Adds the first N characters (max %d) of each post\'s plain text, without markup. Does not count against the get-post full-content limit. Default 0 (no preview).', 'agent-mod'),
								self::MAX_CONTENT_PREVIEW
							),
						],
						'fields'               => [
							'type'        => 'string',
							'enum'        => ['full', 'ids'],
							'description' => __('"ids" returns only post_id and title per post (smallest output). Default "full".', 'agent-mod'),
						],
						'orderby'              => [
							'type'        => 'string',
							'enum'        => ['title', 'date', 'modified', 'ID', 'post__in'],
							'description' => __('Sort field. Default "title", or the include / titles order when those are given.', 'agent-mod'),
						],
						'order'                => [
							'type'        => 'string',
							'enum'        => ['ASC', 'DESC'],
							'description' => __('Sort direction. Default "ASC".', 'agent-mod'),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'          => ['type' => 'boolean'],
						'posts'            => ['type' => 'array'],
						'pagination'       => ['type' => 'object'],
						'title_matches'    => ['type' => 'array'],
						'unmatched_titles' => ['type' => 'array'],
						'limit_notice'     => ['type' => 'string'],
						'error'            => ['type' => 'string'],
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
				'description'         => __('Returns a post raw block markup as html by post_id, plus its status and custom fields (meta). Use list-posts to find post_id first. For the same text change across many posts use preview-bulk-replace / bulk-replace-content instead of reading each post. The returned html can be modified and passed straight back to update-post as the html parameter. Navigation menus (wp_navigation, referenced by {"ref":ID} in wp:navigation blocks) have dedicated abilities: list-navigations / get-navigation / update-navigation.', 'agent-mod'),
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
						'meta_keys' => [
							'type'        => 'array',
							'items'       => ['type' => 'string'],
							'description' => __('Optional. Custom field keys to return. When omitted, every public (non-underscore) custom field is returned; name underscore-prefixed keys (e.g. SEO plugin fields) here to read them.', 'agent-mod'),
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
						'status'    => ['type' => 'string'],
						'url'       => ['type' => 'string'],
						'html'      => ['type' => 'string'],
						'meta'      => ['type' => 'object'],
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
				'description'         => __('Creates a new post/page. Provide title and optionally html and meta. The html parameter must be complete, valid serialized block markup; it is parsed with parse_blocks() and re-serialized as-is — attribute/innerHTML consistency is NOT validated server-side. Returns the new post_id for use with get-post/update-post.', 'agent-mod'),
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
						'meta'        => $this->metaWriteSchema(),
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
				'description'         => __('Updates a post. Provide post_id plus any of html, title, post_status and meta; fields left out stay unchanged. The html parameter must be complete, valid serialized block markup; it is parsed with parse_blocks() and re-serialized as-is — attribute/innerHTML consistency is NOT validated server-side. To edit a navigation menu (wp_navigation) use update-navigation instead.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeUpdatePost'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_posts');
				},
				'input_schema'        => [
					'type'       => 'object',
					'required'   => ['post_id'],
					'properties' => [
						'post_id'     => [
							'type'        => 'integer',
							'description' => __('Post/page ID from get-post.', 'agent-mod'),
						],
						'post_type'   => [
							'type'        => 'string',
							'description' => __('The expected post type of post_id (e.g. "post", "page", or a custom post type). Default "post".', 'agent-mod'),
						],
						'html'        => [
							'type'        => 'string',
							'description' => __('Optional. Serialized block markup (WordPress block comment format). Use the output of get-post for round-trip editing. Replaces existing content entirely; omit it to keep the content unchanged.', 'agent-mod'),
						],
						'title'       => [
							'type'        => 'string',
							'description' => __('Optional. Updates the post/page title if provided.', 'agent-mod'),
						],
						'post_status' => [
							'type'        => 'string',
							'enum'        => ['draft', 'publish', 'pending', 'private'],
							'description' => __('Optional. Changes the publication status (e.g. publish a draft).', 'agent-mod'),
						],
						'meta'        => $this->metaWriteSchema(),
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
			'agent-mod/preview-bulk-replace',
			[
				'label'               => __('Preview Bulk Replace in Posts', 'agent-mod'),
				'description'         => __('Read-only dry run of bulk-replace-content: finds the text in the content of many posts at once and returns, per post, the number of matches and a before / after snippet. Nothing is saved. Use it for any change that repeats across posts (remove a prefix, fix a word, replace a link), then call bulk-replace-content with the same arguments. Get the post IDs once with list-posts (e.g. its titles filter); never read each post with get-post for this.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executePreviewBulkReplace'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_posts');
				},
				'input_schema'        => $this->bulkReplaceInputSchema(),
				'output_schema'       => $this->bulkReplaceOutputSchema(),
				'meta'                => [
					'annotations'  => ['readonly' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/bulk-replace-content',
			[
				'label'               => __('Bulk Replace in Posts', 'agent-mod'),
				'description'         => __('Finds and replaces text in the content of many posts in one call, on the server: the content is not sent back and forth. Run preview-bulk-replace with the same arguments first and check its snippets. Changed posts get a revision when their post type supports revisions; otherwise the change cannot be undone from the editor, so say so before applying it. Posts with no match are left untouched.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeBulkReplaceContent'],
				'permission_callback' => static function (): bool {
					return current_user_can('edit_posts');
				},
				'input_schema'        => $this->bulkReplaceInputSchema(),
				'output_schema'       => $this->bulkReplaceOutputSchema(),
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

			// wp_update_post()/wp_insert_post() unslash their input; without
			// wp_slash() the JSON escapes in block attributes would be stripped.
			$result = wp_update_post(wp_slash($update), true);

			if (is_wp_error($result)) {
				return ['success' => false, 'error' => $result->get_error_message()];
			}
		} else {
			$action = 'created';
			$postId = wp_insert_post(
				wp_slash([
					'post_type'    => $postType,
					'post_status'  => 'publish',
					'post_name'    => $slug,
					'post_title'   => '' !== $title ? $title : $slug,
					'post_content' => $serializedContent,
				]),
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
		$requested    = isset($input['posts_per_page']) ? absint($input['posts_per_page']) : 10;
		$postsPerPage = max(1, min($requested, $maxResults));
		$paged        = isset($input['paged']) ? max(absint($input['paged']), 1) : 1;
		$search       = isset($input['s']) ? sanitize_text_field((string) $input['s']) : '';
		$orderby      = isset($input['orderby']) && in_array($input['orderby'], ['title', 'date', 'modified', 'ID', 'post__in'], true)
			? $input['orderby']
			: 'title';
		$order        = isset($input['order']) && 'DESC' === strtoupper((string) $input['order']) ? 'DESC' : 'ASC';
		$postStatus   = isset($input['post_status']) && in_array($input['post_status'], ['any', 'publish', 'draft', 'pending', 'private', 'future'], true)
			? $input['post_status']
			: 'any';

		$queryArgs = [
			'post_type'      => $postType,
			'post_status'    => $postStatus,
			// Private posts of other authors only when the user may read them.
			'perm'           => 'readable',
			'posts_per_page' => $postsPerPage,
			'paged'          => $paged,
			'orderby'        => $orderby,
			'order'          => $order,
		];

		if (! empty($search)) {
			$queryArgs['s'] = $search;

			$columns = array_values(array_intersect(self::SEARCH_COLUMNS, (array) ($input['search_columns'] ?? [])));
			if (! empty($columns)) {
				$queryArgs['search_columns'] = $columns;
			}
		}

		$include      = $this->sanitizeIdList($input['include'] ?? []);
		$titleMatches = null;

		if (! empty($input['titles']) && is_array($input['titles'])) {
			$titleMatches = $this->matchPostTitles($input['titles'], $postType, $postStatus);
			$titleIds     = array_unique(array_merge([], ...array_column($titleMatches, 'post_ids')));
			$include      = empty($include) ? $titleIds : array_intersect($include, $titleIds);
			$include      = array_slice(array_values($include), 0, Constants::AI_MAX_BULK_POSTS);

			// None of the titles exist: an empty post__in would match every post.
			if (empty($include)) {
				return [
					'success'          => true,
					'posts'            => [],
					'pagination'       => [
						'total_posts'    => 0,
						'total_pages'    => 0,
						'current_page'   => $paged,
						'posts_per_page' => $postsPerPage,
						'has_more'       => false,
					],
					'title_matches'    => $titleMatches,
					'unmatched_titles' => $this->unmatchedTitles($titleMatches),
				];
			}
		}

		if (! empty($include)) {
			$queryArgs['post__in'] = array_values(array_unique($include));

			if (! isset($input['orderby'])) {
				$queryArgs['orderby'] = 'post__in';
			}
		} elseif ('post__in' === $orderby) {
			$queryArgs['orderby'] = 'title';
		}

		$exclude = $this->sanitizeIdList($input['exclude'] ?? []);
		if (! empty($exclude)) {
			$queryArgs['post__not_in'] = $exclude;
		}

		$metaQuery = $this->buildMetaQuery($input);
		if (! empty($metaQuery)) {
			$queryArgs['meta_query'] = $metaQuery;
		}

		$taxQuery = $this->buildTaxQuery($input['tax_query'] ?? []);
		if (! empty($taxQuery)) {
			$queryArgs['tax_query'] = $taxQuery;
		}

		if (! empty($input['author'])) {
			$queryArgs['author'] = absint($input['author']);
		}

		$dateQuery = $this->buildDateQuery($input);
		if (! empty($dateQuery)) {
			$queryArgs['date_query'] = $dateQuery;
		}

		$contentFilters = $this->buildContentFilters($input);
		if (! empty($contentFilters)) {
			// Read by filterContentWhere(), which is hooked only around these queries.
			$queryArgs['agent_mod_content_filters'] = $contentFilters;
			add_filter('posts_where', [$this, 'filterContentWhere'], 10, 2);
		}

		try {
			return $this->runListPostsQuery($queryArgs, $input, $paged, $postsPerPage, $requested, $maxResults, $titleMatches);
		} finally {
			remove_filter('posts_where', [$this, 'filterContentWhere'], 10);
		}
	}

	/**
	 * Runs the list-posts query and shapes its result.
	 *
	 * @param array<string, mixed>                   $queryArgs    WP_Query arguments.
	 * @param array<string, mixed>                   $input        The ability input.
	 * @param int                                    $paged        Requested page.
	 * @param int                                    $postsPerPage Effective page size.
	 * @param int                                    $requested    Page size the agent asked for.
	 * @param int                                    $maxResults   Max Search Results setting.
	 * @param array<int, array<string, mixed>>|null  $titleMatches Title lookup result, when titles were given.
	 *
	 * @return array<string, mixed>
	 * @since 1.3.3
	 */
	private function runListPostsQuery(array $queryArgs, array $input, int $paged, int $postsPerPage, int $requested, int $maxResults, ?array $titleMatches): array
	{
		$query = new WP_Query($queryArgs);

		if (! $query->have_posts()) {
			$pagination = [
				'total_posts'    => 0,
				'total_pages'    => 0,
				'current_page'   => $paged,
				'posts_per_page' => $postsPerPage,
				'has_more'       => false,
			];

			// WP_Query skips found_posts on an empty page, so a page past the
			// last one would read as "no posts at all". Count separately.
			if ($paged > 1) {
				$countQuery = new WP_Query(array_merge($queryArgs, [
					'fields'                 => 'ids',
					'posts_per_page'         => 1,
					'paged'                  => 1,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				]));

				$totalPosts = (int) $countQuery->found_posts;
				$totalPages = (int) ceil($totalPosts / $postsPerPage);

				$pagination['total_posts'] = $totalPosts;
				$pagination['total_pages'] = $totalPages;
				$pagination['notice']      = sprintf(
					/* translators: 1: requested page number, 2: last page number. */
					__('Page %1$d is past the last page (%2$d); there are no more posts.', 'agent-mod'),
					$paged,
					$totalPages
				);
			}

			return $this->withTitleMatches([
				'success'    => true,
				'posts'      => [],
				'pagination' => $pagination,
			], $titleMatches);
		}

		$idsOnly       = 'ids' === ($input['fields'] ?? '');
		$previewLength = min(absint($input['content_preview'] ?? 0), self::MAX_CONTENT_PREVIEW);

		$posts = array_map(function ($post) use ($idsOnly, $previewLength) {
			$item = $idsOnly
				? [
					'post_id' => $post->ID,
					'title'   => $post->post_title,
				]
				: [
					'post_id'   => $post->ID,
					'post_name' => $post->post_name,
					'title'     => $post->post_title,
					'post_type' => $post->post_type,
					'status'    => $post->post_status,
					'date'      => $post->post_date,
					'modified'  => $post->post_modified,
					'url'       => get_permalink($post->ID),
				];

			// The preview shows post content, so it follows get-post's capability.
			if ($previewLength > 0 && current_user_can('edit_post', $post->ID)) {
				$item['content_preview'] = $this->plainTextPreview((string) $post->post_content, $previewLength);
			}

			return $item;
		}, $query->posts);

		$hasMore = $paged < $query->max_num_pages;

		$result = [
			'success'    => true,
			'posts'      => $posts,
			'pagination' => [
				'total_posts'    => (int) $query->found_posts,
				'total_pages'    => (int) $query->max_num_pages,
				'current_page'   => $paged,
				'posts_per_page' => $postsPerPage,
				'has_more'       => $hasMore,
			],
		];

		if ($requested > $maxResults || ($hasMore && $postsPerPage >= $maxResults)) {
			$result['limit_notice'] = $this->settings_service->getSearchLimitNotice($maxResults, true);
		}

		return $this->withTitleMatches($result, $titleMatches);
	}

	/**
	 * Adds the title lookup outcome to a list-posts result.
	 *
	 * @param array<string, mixed>                  $result       The list-posts result.
	 * @param array<int, array<string, mixed>>|null $titleMatches Title lookup result, or null when no titles were given.
	 *
	 * @return array<string, mixed>
	 * @since 1.3.3
	 */
	private function withTitleMatches(array $result, ?array $titleMatches): array
	{
		if (null === $titleMatches) {
			return $result;
		}

		$result['title_matches']    = $titleMatches;
		$result['unmatched_titles'] = $this->unmatchedTitles($titleMatches);

		return $result;
	}

	/**
	 * Finds post IDs for a list of titles in one lookup.
	 *
	 * Titles are compared after normalization (case, whitespace, accents and the
	 * Turkish dotted / dotless i), because the agent usually copies names from a
	 * screenshot or from text typed by the user: "ANIL BAYER" must find "Anıl
	 * Bayer". A title with no exact match falls back to the posts whose title
	 * contains it. Only ID and title are read, so a large post type stays cheap.
	 *
	 * @param array<int, mixed> $titles     Requested titles.
	 * @param string            $postType   Post type slug, or "any".
	 * @param string            $postStatus Status filter from list-posts.
	 *
	 * @return array<int, array{title: string, post_ids: int[], match: string}>
	 * @since 1.3.3
	 */
	private function matchPostTitles(array $titles, string $postType, string $postStatus): array
	{
		global $wpdb;

		$titles = array_slice(
			array_values(array_unique(array_filter(array_map(static function ($title): string {
				return is_scalar($title) ? trim(sanitize_text_field((string) $title)) : '';
			}, $titles), 'strlen'))),
			0,
			Constants::AI_MAX_BULK_POSTS
		);

		if (empty($titles)) {
			return [];
		}

		$types = 'any' === $postType
			? array_values(get_post_types(['exclude_from_search' => false]))
			: [$postType];

		$statuses = 'any' === $postStatus
			? ['publish', 'draft', 'pending', 'private', 'future']
			: [$postStatus];

		$typePlaceholders   = implode(',', array_fill(0, count($types), '%s'));
		$statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are built above; one cheap read per call.
		$rows = (array) $wpdb->get_results($wpdb->prepare("SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type IN ($typePlaceholders) AND post_status IN ($statusPlaceholders)", array_merge($types, $statuses)));

		$normalized = [];
		foreach ($rows as $row) {
			$normalized[(int) $row->ID] = $this->normalizeTitle((string) $row->post_title);
		}

		$matches = [];

		foreach ($titles as $title) {
			$needle = $this->normalizeTitle($title);
			$ids    = array_keys($normalized, $needle, true);
			$match  = 'exact';

			if (empty($ids) && '' !== $needle) {
				$ids   = array_keys(array_filter($normalized, static function (string $candidate) use ($needle): bool {
					return false !== strpos($candidate, $needle);
				}));
				$match = empty($ids) ? 'none' : 'contains';
			}

			$matches[] = [
				'title'    => $title,
				'post_ids' => array_map('intval', $ids),
				'match'    => empty($ids) ? 'none' : $match,
			];
		}

		return $matches;
	}

	/**
	 * Lists the requested titles that matched no post.
	 *
	 * @param array<int, array<string, mixed>> $titleMatches Title lookup result.
	 *
	 * @return string[]
	 * @since 1.3.3
	 */
	private function unmatchedTitles(array $titleMatches): array
	{
		return array_values(array_map(static function (array $match): string {
			return (string) $match['title'];
		}, array_filter($titleMatches, static function (array $match): bool {
			return empty($match['post_ids']);
		})));
	}

	/**
	 * Normalizes a title for matching: lowercase, no accents, single spaces.
	 *
	 * The Turkish İ / I / ı are folded to "i" before lowercasing, as
	 * mb_strtolower('İ') yields "i" plus a combining dot and 'I' would
	 * otherwise never meet "ı".
	 *
	 * @param string $title Raw title.
	 *
	 * @return string
	 * @since 1.3.3
	 */
	private function normalizeTitle(string $title): string
	{
		$title = html_entity_decode(wp_strip_all_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$title = strtr($title, ['İ' => 'i', 'I' => 'i', 'ı' => 'i']);
		$title = remove_accents(mb_strtolower($title, 'UTF-8'));

		return trim((string) preg_replace('/\s+/u', ' ', $title));
	}

	/**
	 * Sanitizes a list of post IDs, capped at the bulk limit.
	 *
	 * @param mixed $ids Raw input.
	 *
	 * @return int[]
	 * @since 1.3.3
	 */
	private function sanitizeIdList($ids): array
	{
		if (! is_array($ids)) {
			return [];
		}

		return array_slice(array_values(array_unique(array_filter(array_map('absint', $ids)))), 0, Constants::AI_MAX_BULK_POSTS);
	}

	/**
	 * Builds the list-posts meta_query from meta_query conditions and the legacy meta_key / meta_value pair.
	 *
	 * @param array<string, mixed> $input The ability input.
	 *
	 * @return array<int|string, mixed> Empty when no condition was given.
	 * @since 1.3.3
	 */
	private function buildMetaQuery(array $input): array
	{
		$clauses = [];

		if (! empty($input['meta_key'])) {
			$clause = ['key' => sanitize_text_field((string) $input['meta_key'])];

			if (isset($input['meta_value']) && '' !== (string) $input['meta_value']) {
				$clause['value'] = sanitize_text_field((string) $input['meta_value']);
			} else {
				$clause['compare'] = 'EXISTS';
			}

			$clauses[] = $clause;
		}

		foreach (array_slice((array) ($input['meta_query'] ?? []), 0, self::MAX_QUERY_CONDITIONS) as $condition) {
			if (! is_array($condition) || empty($condition['key'])) {
				continue;
			}

			$compare = strtoupper(trim((string) ($condition['compare'] ?? '=')));
			$compare = in_array($compare, self::META_COMPARE, true) ? $compare : '=';
			$clause  = [
				'key'     => sanitize_text_field((string) $condition['key']),
				'compare' => $compare,
			];

			if (! in_array($compare, ['EXISTS', 'NOT EXISTS'], true)) {
				$value = $condition['value'] ?? '';
				$value = is_array($value) ? implode(',', array_filter($value, 'is_scalar')) : (is_scalar($value) ? (string) $value : '');

				$clause['value'] = in_array($compare, ['IN', 'NOT IN'], true)
					? array_values(array_filter(array_map('trim', array_map('sanitize_text_field', explode(',', $value))), 'strlen'))
					: sanitize_text_field($value);

				$type = strtoupper((string) ($condition['type'] ?? ''));
				if (in_array($type, self::META_TYPES, true)) {
					$clause['type'] = $type;
				}
			}

			$clauses[] = $clause;
		}

		if (count($clauses) > 1) {
			$clauses['relation'] = 'OR' === strtoupper((string) ($input['meta_relation'] ?? '')) ? 'OR' : 'AND';
		}

		return $clauses;
	}

	/**
	 * Builds the list-posts tax_query; conditions on unknown taxonomies are dropped.
	 *
	 * @param mixed $conditions Raw tax_query input.
	 *
	 * @return array<int|string, mixed> Empty when no valid condition was given.
	 * @since 1.3.3
	 */
	private function buildTaxQuery($conditions): array
	{
		$clauses = [];

		foreach (array_slice(is_array($conditions) ? $conditions : [], 0, self::MAX_QUERY_CONDITIONS) as $condition) {
			if (! is_array($condition) || empty($condition['taxonomy']) || empty($condition['terms'])) {
				continue;
			}

			$taxonomy = sanitize_key((string) $condition['taxonomy']);
			if (! taxonomy_exists($taxonomy)) {
				continue;
			}

			$field    = in_array($condition['field'] ?? '', ['slug', 'term_id', 'name'], true) ? $condition['field'] : 'slug';
			$operator = strtoupper((string) ($condition['operator'] ?? 'IN'));
			$terms    = array_filter((array) $condition['terms'], 'is_scalar');
			$terms    = 'term_id' === $field
				? array_values(array_filter(array_map('absint', $terms)))
				: array_values(array_filter(array_map('sanitize_text_field', $terms), 'strlen'));

			if (empty($terms)) {
				continue;
			}

			$clauses[] = [
				'taxonomy' => $taxonomy,
				'field'    => $field,
				'terms'    => $terms,
				'operator' => in_array($operator, ['IN', 'NOT IN', 'AND'], true) ? $operator : 'IN',
			];
		}

		if (count($clauses) > 1) {
			$clauses['relation'] = 'AND';
		}

		return $clauses;
	}

	/**
	 * Builds the list-posts date_query from date_after / date_before (inclusive).
	 *
	 * @param array<string, mixed> $input The ability input.
	 *
	 * @return array<int, array<string, mixed>> Empty when neither date is valid.
	 * @since 1.3.3
	 */
	private function buildDateQuery(array $input): array
	{
		$range = [];

		foreach (['date_after' => 'after', 'date_before' => 'before'] as $key => $bound) {
			$date = isset($input[$key]) ? trim((string) $input[$key]) : '';

			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && wp_checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4), $date)) {
				$range[$bound] = $date;
			}
		}

		if (empty($range)) {
			return [];
		}

		$range['inclusive'] = true;

		return [$range];
	}

	/**
	 * Collects the content_contains / content_not_contains filters of list-posts.
	 *
	 * @param array<string, mixed> $input The ability input.
	 *
	 * @return array<string, string> Map of "contains" / "not_contains" => text.
	 * @since 1.3.3
	 */
	private function buildContentFilters(array $input): array
	{
		$filters = [];

		foreach (['content_contains' => 'contains', 'content_not_contains' => 'not_contains'] as $key => $mode) {
			// Not sanitize_text_field(): the text may be markup. It only ever
			// reaches SQL through $wpdb->prepare() + esc_like().
			$text = isset($input[$key]) && is_scalar($input[$key]) ? wp_check_invalid_utf8((string) $input[$key]) : '';

			if ('' !== trim($text)) {
				$filters[$mode] = mb_substr($text, 0, 500);
			}
		}

		return $filters;
	}

	/**
	 * posts_where filter applying the list-posts content filters.
	 *
	 * Hooked only while list-posts runs its queries, and acts only on a query
	 * carrying the agent_mod_content_filters var.
	 *
	 * @param string   $where The WHERE clause.
	 * @param WP_Query $query The query.
	 *
	 * @return string
	 * @since 1.3.3
	 */
	public function filterContentWhere(string $where, WP_Query $query): string
	{
		global $wpdb;

		$filters = $query->get('agent_mod_content_filters');

		if (! is_array($filters)) {
			return $where;
		}

		if (isset($filters['contains'])) {
			$where .= $wpdb->prepare(" AND {$wpdb->posts}.post_content LIKE %s", '%' . $wpdb->esc_like($filters['contains']) . '%');
		}

		if (isset($filters['not_contains'])) {
			$where .= $wpdb->prepare(" AND {$wpdb->posts}.post_content NOT LIKE %s", '%' . $wpdb->esc_like($filters['not_contains']) . '%');
		}

		return $where;
	}

	/**
	 * Plain-text start of a post's content: no block comments, tags or entities.
	 *
	 * @param string $content Raw post content.
	 * @param int    $length  Maximum characters.
	 *
	 * @return string
	 * @since 1.3.3
	 */
	private function plainTextPreview(string $content, int $length): string
	{
		$text = (string) preg_replace('/<!--.*?-->/s', ' ', $content);
		$text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = trim((string) preg_replace('/\s+/u', ' ', $text));

		return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '…' : $text;
	}

	/**
	 * Execute callback for agent-mod/get-post.
	 *
	 * @param mixed $input Input data (expects 'post_id', optional 'post_type', 'meta_keys').
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function executeGetPost($input = null): array
	{
		// Full-content reads are capped per request to control latency and token
		// cost. The cap counts distinct posts: re-reading one (e.g. after its
		// result was elided from the history) must not lock the agent out of it.
		static $readPostIds = [];

		if (! is_array($input) || empty($input['post_id'])) {
			return ['success' => false, 'error' => __('post_id is required.', 'agent-mod')];
		}

		$postId   = absint($input['post_id']);
		$postType = ! empty($input['post_type']) ? sanitize_key((string) $input['post_type']) : 'post';
		$post     = get_post($postId);

		if (! $post || $post->post_type !== $postType) {
			return ['success' => false, 'error' => $this->postTypeMismatchError($postId, $postType, $post, false)];
		}

		if (! current_user_can('edit_post', $postId)) {
			return ['success' => false, 'error' => __('You are not allowed to edit this post.', 'agent-mod')];
		}

		$maxReads = $this->settings_service->getMaxFullContentPosts();
		if (! isset($readPostIds[$postId]) && count($readPostIds) >= $maxReads) {
			return [
				'success' => false,
				'error'   => sprintf(
					/* translators: %d: maximum number of full-content post reads per message. */
					__('Full-content read limit reached: at most %d posts can be read in full per message. To change the same text in many posts use preview-bulk-replace / bulk-replace-content, and to look at the start of many posts use list-posts with content_preview; neither counts against this limit. Otherwise summarize what you already have, or ask the user to narrow the request.', 'agent-mod'),
					$maxReads
				),
			];
		}

		$readPostIds[$postId] = true;

		return [
			'success'   => true,
			'post_id'   => $post->ID,
			'post_name' => $post->post_name,
			'title'     => $post->post_title,
			'post_type' => $post->post_type,
			'status'    => $post->post_status,
			'url'       => (string) get_permalink($post->ID),
			'html'      => $post->post_content,
			'meta'      => $this->readPostMeta($post->ID, $input['meta_keys'] ?? null),
		];
	}

	/**
	 * Execute callback for agent-mod/create-post.
	 *
	 * @param mixed $input Input data (expects 'title', optional 'html', 'post_type', 'post_status', 'meta').
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
			? sanitize_key((string) $input['post_type'])
			: 'post';
		$postStatus = (isset($input['post_status']))
			? sanitize_key((string) $input['post_status'])
			: 'draft';

		if (! post_type_exists($postType)) {
			/* translators: %s: post type slug */
			return ['success' => false, 'error' => sprintf(__('Post type "%s" does not exist.', 'agent-mod'), $postType)];
		}

		$statusError = $this->checkPostStatus($postType, $postStatus);
		if (is_wp_error($statusError)) {
			return ['success' => false, 'error' => $statusError->get_error_message()];
		}

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

		// Meta capabilities can only be checked against an existing post, so a
		// post with meta starts as a draft: it can be rolled back without side
		// effects, and publish-time hooks (SEO, sharing) see the final meta.
		$hasMeta = ! empty($input['meta']);

		// wp_insert_post() unslashes its input; without wp_slash() the JSON
		// escapes in block attributes (", \\) would be stripped.
		$result = wp_insert_post(
			wp_slash([
				'post_title'   => $title,
				'post_content' => $serializedContent,
				'post_type'    => $postType,
				'post_status'  => $hasMeta ? 'draft' : $postStatus,
			]),
			true
		);

		if (is_wp_error($result)) {
			return ['success' => false, 'error' => $result->get_error_message()];
		}

		$postId = (int) $result;

		if ($hasMeta) {
			$metaWrites = $this->prepareMetaWrites($postId, $input['meta']);
			if (is_wp_error($metaWrites)) {
				wp_delete_post($postId, true);
				return ['success' => false, 'error' => $metaWrites->get_error_message()];
			}

			$this->applyMetaWrites($postId, $metaWrites);

			if ('draft' !== $postStatus) {
				$statusResult = wp_update_post(['ID' => $postId, 'post_status' => $postStatus], true);
				if (is_wp_error($statusResult)) {
					return ['success' => false, 'error' => $statusResult->get_error_message()];
				}
			}
		}

		return [
			'success'   => true,
			'post_id'   => $postId,
			'post_type' => $postType,
			'status'    => (string) get_post_status($postId),
			'url'       => (string) get_permalink($postId),
		];
	}

	/**
	 * Execute callback for agent-mod/update-post.
	 *
	 * @param mixed $input Input data (expects 'post_id' plus any of 'html', 'title', 'post_status', 'meta').
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function executeUpdatePost($input = null): array
	{
		if (! is_array($input) || empty($input['post_id'])) {
			return ['success' => false, 'error' => __('post_id is required.', 'agent-mod')];
		}

		$hasHtml   = isset($input['html']) && '' !== trim((string) $input['html']);
		$hasTitle  = ! empty($input['title']);
		$hasStatus = ! empty($input['post_status']);
		$hasMeta   = ! empty($input['meta']);

		if (! $hasHtml && ! $hasTitle && ! $hasStatus && ! $hasMeta) {
			return ['success' => false, 'error' => __('Nothing to update: provide at least one of html, title, post_status or meta.', 'agent-mod')];
		}

		$postId   = absint($input['post_id']);
		$postType = ! empty($input['post_type']) ? sanitize_key((string) $input['post_type']) : 'post';
		$post     = get_post($postId);

		if (! $post || $post->post_type !== $postType) {
			return ['success' => false, 'error' => $this->postTypeMismatchError($postId, $postType, $post, true)];
		}

		if (! current_user_can('edit_post', $postId)) {
			return ['success' => false, 'error' => __('You are not allowed to edit this post.', 'agent-mod')];
		}

		$updateArgs = ['ID' => $postId];

		if ($hasStatus) {
			$postStatus  = sanitize_key((string) $input['post_status']);
			$statusError = $this->checkPostStatus($post->post_type, $postStatus);
			if (is_wp_error($statusError)) {
				return ['success' => false, 'error' => $statusError->get_error_message()];
			}

			$updateArgs['post_status'] = $postStatus;
		}

		if ($hasHtml) {
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

			$updateArgs['post_content'] = $serializedContent;
		}

		if ($hasTitle) {
			$updateArgs['post_title'] = sanitize_text_field((string) $input['title']);
		}

		// Every check runs before the first write, so a rejected call changes nothing.
		$metaWrites = $this->prepareMetaWrites($postId, $input['meta'] ?? null);
		if (is_wp_error($metaWrites)) {
			return ['success' => false, 'error' => $metaWrites->get_error_message()];
		}

		// Meta first, so publish-time hooks fired by a status change see it.
		$this->applyMetaWrites($postId, $metaWrites);

		if (count($updateArgs) > 1) {
			// wp_update_post() unslashes its input; keep the attribute JSON escapes.
			$result = wp_update_post(wp_slash($updateArgs), true);

			if (is_wp_error($result)) {
				return ['success' => false, 'error' => $result->get_error_message()];
			}
		}

		return [
			'success'   => true,
			'post_id'   => $postId,
			'post_type' => $post->post_type,
			'status'    => (string) get_post_status($postId),
			'url'       => (string) get_permalink($postId),
		];
	}

	/**
	 * Input schema shared by preview-bulk-replace and bulk-replace-content.
	 *
	 * @return array<string, mixed>
	 * @since 1.3.3
	 */
	private function bulkReplaceInputSchema(): array
	{
		return [
			'type'       => 'object',
			'required'   => ['post_ids', 'find'],
			'properties' => [
				'post_ids'       => [
					'type'        => 'array',
					'items'       => ['type' => 'integer'],
					'description' => sprintf(
						/* translators: %d: maximum number of posts. */
						__('IDs of the posts to change (up to %d), e.g. from list-posts.', 'agent-mod'),
						Constants::AI_MAX_BULK_POSTS
					),
				],
				'post_type'      => [
					'type'        => 'string',
					'description' => __('The expected post type of the posts (e.g. "post", "page", or a custom post type). Default "post".', 'agent-mod'),
				],
				'find'           => [
					'type'        => 'string',
					'description' => __('Text to find, or a PCRE pattern without delimiters when match is "regex" (e.g. "^\\s*Short bio\\s*:\\s*" to remove a leading label). In text_only mode the text is HTML-encoded as stored (e.g. &amp;).', 'agent-mod'),
				],
				'replace'        => [
					'type'        => 'string',
					'description' => __('Replacement text. Default "" (removes the match). In regex mode $1, $2 … insert captured groups.', 'agent-mod'),
				],
				'match'          => [
					'type'        => 'string',
					'enum'        => ['literal', 'regex'],
					'description' => __('"literal" (default) finds the exact text; "regex" treats find as a PCRE pattern (Unicode).', 'agent-mod'),
				],
				'case_sensitive' => [
					'type'        => 'boolean',
					'description' => __('Default false: "BIO", "Bio" and "bio" all match, Turkish letters included.', 'agent-mod'),
				],
				'limit_per_post' => [
					'type'        => 'integer',
					'description' => __('Most replacements per post. Default 0 (all matches); use 1 to change only the first occurrence, e.g. a leading label.', 'agent-mod'),
				],
				'text_only'      => [
					'type'        => 'boolean',
					'description' => __('Default true: only the visible text between tags is searched, never block comments, tags or attributes, so the block markup cannot break. A "^" anchor then matches the start of each text run (e.g. the start of a paragraph). Text split by inline tags (e.g. <strong>Bio</strong>:) is not matched as one piece; set false to search the raw markup instead.', 'agent-mod'),
				],
			],
		];
	}

	/**
	 * Output schema shared by preview-bulk-replace and bulk-replace-content.
	 *
	 * @return array<string, mixed>
	 * @since 1.3.3
	 */
	private function bulkReplaceOutputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'success'   => ['type' => 'boolean'],
				'dry_run'   => ['type' => 'boolean'],
				'summary'   => ['type' => 'object'],
				'results'   => ['type' => 'array'],
				'next_step' => ['type' => 'string'],
				'error'     => ['type' => 'string'],
			],
		];
	}

	/**
	 * Execute callback for agent-mod/preview-bulk-replace.
	 *
	 * @param mixed $input Input data (see bulkReplaceInputSchema()).
	 *
	 * @return array<string, mixed>
	 * @since 1.3.3
	 */
	public function executePreviewBulkReplace($input = null): array
	{
		return $this->runBulkReplace(is_array($input) ? $input : [], true);
	}

	/**
	 * Execute callback for agent-mod/bulk-replace-content.
	 *
	 * @param mixed $input Input data (see bulkReplaceInputSchema()).
	 *
	 * @return array<string, mixed>
	 * @since 1.3.3
	 */
	public function executeBulkReplaceContent($input = null): array
	{
		return $this->runBulkReplace(is_array($input) ? $input : [], false);
	}

	/**
	 * Finds and replaces text in the content of many posts.
	 *
	 * Every post is checked on its own: a missing post, a type mismatch, a
	 * missing capability or a replacement that would change the block
	 * structure only skips that post. Changed posts are saved through
	 * wp_update_post(), so each gets a revision.
	 *
	 * @param array<string, mixed> $input  The ability input.
	 * @param bool                 $dryRun Whether to only report what would change.
	 *
	 * @return array<string, mixed>
	 * @since 1.3.3
	 */
	private function runBulkReplace(array $input, bool $dryRun): array
	{
		$postIds = $this->sanitizeIdList($input['post_ids'] ?? []);
		$find    = isset($input['find']) && is_scalar($input['find']) ? wp_check_invalid_utf8((string) $input['find']) : '';

		if (empty($postIds)) {
			return ['success' => false, 'error' => __('post_ids is required. Get the IDs with list-posts first.', 'agent-mod')];
		}

		if ('' === $find) {
			return ['success' => false, 'error' => __('find is required and must not be empty.', 'agent-mod')];
		}

		$isRegex = 'regex' === ($input['match'] ?? 'literal');
		$pattern = $this->buildReplacePattern($find, $isRegex, ! empty($input['case_sensitive']));

		if (is_wp_error($pattern)) {
			return ['success' => false, 'error' => $pattern->get_error_message()];
		}

		$replace  = isset($input['replace']) && is_scalar($input['replace']) ? wp_check_invalid_utf8((string) $input['replace']) : '';
		$limit    = absint($input['limit_per_post'] ?? 0);
		$textOnly = ! isset($input['text_only']) || false !== $input['text_only'];
		$postType = ! empty($input['post_type']) ? sanitize_key((string) $input['post_type']) : 'post';

		$results = [];
		$summary = [
			'posts_checked' => count($postIds),
			'posts_matched' => 0,
			'total_matches' => 0,
			'posts_updated' => 0,
			'posts_skipped' => 0,
		];

		foreach ($postIds as $postId) {
			$item = $this->bulkReplaceOne($postId, $postType, $pattern, $replace, $isRegex, $limit, $textOnly, $dryRun);

			if ($item['matches'] > 0) {
				$summary['posts_matched']++;
				$summary['total_matches'] += $item['matches'];
			}

			if ('changed' === $item['status'] && ! $dryRun) {
				$summary['posts_updated']++;
			} elseif (in_array($item['status'], ['skipped', 'error'], true)) {
				$summary['posts_skipped']++;
			}

			$results[] = $item;
		}

		$result = [
			'success' => true,
			'dry_run' => $dryRun,
			'summary' => $summary,
			'results' => $results,
		];

		if ($dryRun) {
			$result['next_step'] = 0 === $summary['posts_matched']
				? __('Nothing matched. Check the snippets with list-posts content_preview and adjust find (e.g. regex mode, case_sensitive false, or text_only false when the text is split by inline tags).', 'agent-mod')
				: __('Nothing was saved. If the after snippets are right, call bulk-replace-content with exactly the same arguments to apply the change.', 'agent-mod');
		}

		return $result;
	}

	/**
	 * Builds the PCRE pattern for a bulk replace and checks that it compiles.
	 *
	 * @param string $find          Text or pattern from the input.
	 * @param bool   $isRegex       Whether find is a pattern.
	 * @param bool   $caseSensitive Whether matching is case-sensitive.
	 *
	 * @return string|WP_Error
	 * @since 1.3.3
	 */
	private function buildReplacePattern(string $find, bool $isRegex, bool $caseSensitive)
	{
		$body = $isRegex
			// Escape the delimiter unless the agent already escaped it.
			? (string) preg_replace('/(?<!\\\\)#/', '\\#', $find)
			: preg_quote($find, '#');

		$pattern = '#' . ($caseSensitive ? $body : $this->foldTurkishI($body)) . '#u' . ($caseSensitive ? '' : 'i');

		// preg_last_error_msg() only says "Internal error" for a pattern that
		// does not compile; the warning carries the actual reason.
		$compileError = '';
		set_error_handler(static function (int $errno, string $message) use (&$compileError): bool {
			$compileError = (string) preg_replace('/^preg_match\(\):\s*/', '', $message);
			return true;
		});

		try {
			$compiled = preg_match($pattern, '');
		} finally {
			restore_error_handler();
		}

		if (false === $compiled) {
			return new WP_Error(
				'agent_mod_invalid_pattern',
				sprintf(
					/* translators: %s: PCRE error message. */
					__('The find pattern is not a valid regular expression: %s', 'agent-mod'),
					'' !== $compileError ? $compileError : preg_last_error_msg()
				)
			);
		}

		return $pattern;
	}

	/**
	 * Makes the letters i, ı, I and İ match each other in a pattern.
	 *
	 * PCRE's caseless Unicode matching pairs I with i only, so "KISA" never
	 * matches "Kısa" and "ÖZGEÇMİŞ" never matches "Özgeçmiş". Each of these
	 * letters becomes a class of all four. Escapes (\p{…} included), group
	 * syntax such as (?<name>…) and inline flags are copied unchanged; inside a
	 * character class the letters are added to that class.
	 *
	 * @param string $pattern Pattern body without delimiters.
	 *
	 * @return string
	 * @since 1.3.3
	 */
	private function foldTurkishI(string $pattern): string
	{
		$chars   = mb_str_split($pattern, 1, 'UTF-8');
		$count   = count($chars);
		$letters = ['i', 'ı', 'I', 'İ'];
		$out     = '';
		$inClass = false;

		for ($i = 0; $i < $count; $i++) {
			$char = $chars[$i];

			if ('\\' === $char) {
				$out .= $char . ($chars[$i + 1] ?? '');
				$i++;

				// \p{…}, \x{…}, \N{…}, \g{…}, \k{…}: copy the braces whole.
				if (isset($chars[$i + 1]) && '{' === $chars[$i + 1]) {
					while (++$i < $count) {
						$out .= $chars[$i];

						if ('}' === $chars[$i]) {
							break;
						}
					}
				}

				continue;
			}

			if ($inClass) {
				// POSIX class such as [:digit:]: copy it whole.
				if ('[' === $char && ':' === ($chars[$i + 1] ?? '')) {
					$out .= '[:';
					$i++;

					while (++$i < $count) {
						$out .= $chars[$i];

						if (':' === $chars[$i] && ']' === ($chars[$i + 1] ?? '')) {
							$out .= $chars[++$i];
							break;
						}
					}

					continue;
				}

				$inClass = ']' !== $char;
				$out    .= in_array($char, $letters, true) ? implode('', $letters) : $char;
				continue;
			}

			if ('[' === $char) {
				$inClass = true;
				$out    .= $char;

				// A "]" (after an optional "^") right after "[" is a literal.
				if (isset($chars[$i + 1]) && '^' === $chars[$i + 1]) {
					$out .= $chars[++$i];
				}

				if (isset($chars[$i + 1]) && ']' === $chars[$i + 1]) {
					$out .= $chars[++$i];
				}

				continue;
			}

			if ('(' === $char && '?' === ($chars[$i + 1] ?? '')) {
				$out .= '(?';
				$i++;
				$next = $chars[$i + 1] ?? '';

				if ('<' === $next && in_array($chars[$i + 2] ?? '', ['=', '!'], true)) {
					// Lookbehind: the assertion body is a normal pattern.
					$out .= $next . $chars[$i + 2];
					$i   += 2;
				} elseif ('<' === $next || "'" === $next || 'P' === $next) {
					// Named group or reference: copy the name. (?P<name> ends
					// at ">", (?P=name) and (?P>name) at ")".
					if ('P' === $next) {
						$close = '<' === ($chars[$i + 2] ?? '') ? '>' : ')';
					} else {
						$close = "'" === $next ? "'" : '>';
					}

					$i++;
					$out .= $next;

					while (++$i < $count) {
						$out .= $chars[$i];

						if ($close === $chars[$i]) {
							break;
						}
					}
				} else {
					// Inline flags such as (?i) or (?i-m:…).
					while (isset($chars[$i + 1]) && preg_match('/^[a-zA-Z\-\^]$/', $chars[$i + 1])) {
						$out .= $chars[++$i];
					}
				}

				continue;
			}

			$out .= in_array($char, $letters, true) ? '[' . implode('', $letters) . ']' : $char;
		}

		return $out;
	}

	/**
	 * Applies a bulk replace to one post.
	 *
	 * @param int    $postId   Post ID.
	 * @param string $postType Expected post type.
	 * @param string $pattern  Compiled PCRE pattern.
	 * @param string $replace  Replacement.
	 * @param bool   $isRegex  Whether $replace may use backreferences.
	 * @param int    $limit    Most replacements, 0 for all.
	 * @param bool   $textOnly Whether only text between tags is searched.
	 * @param bool   $dryRun   Whether to skip saving.
	 *
	 * @return array<string, mixed>
	 * @since 1.3.3
	 */
	private function bulkReplaceOne(int $postId, string $postType, string $pattern, string $replace, bool $isRegex, int $limit, bool $textOnly, bool $dryRun): array
	{
		$post = get_post($postId);
		$item = [
			'post_id' => $postId,
			'title'   => $post ? $post->post_title : '',
			'matches' => 0,
		];

		if (! $post || $post->post_type !== $postType) {
			return $item + ['status' => 'error', 'error' => $this->postTypeMismatchError($postId, $postType, $post, true)];
		}

		if (! current_user_can('edit_post', $postId)) {
			return $item + ['status' => 'skipped', 'error' => __('You are not allowed to edit this post.', 'agent-mod')];
		}

		$old     = (string) $post->post_content;
		$matches = 0;
		$new     = $this->replaceInContent($old, $pattern, $replace, $isRegex, $limit, $textOnly, $matches);

		if (null === $new) {
			return $item + ['status' => 'error', 'error' => preg_last_error_msg()];
		}

		$item['matches'] = $matches;

		if (0 === $matches || $new === $old) {
			return $item + ['status' => 'no_match'];
		}

		$item += $this->changeSnippets($old, $new);

		if (has_blocks($old) && $this->blockNames($old) !== $this->blockNames($new)) {
			return $item + ['status' => 'skipped', 'error' => __('The replacement would change the block structure (block comments), so this post was left untouched. Use text_only true or a narrower find.', 'agent-mod')];
		}

		if ($dryRun) {
			return $item + ['status' => 'changed'];
		}

		// wp_update_post() unslashes its input; keep the attribute JSON escapes.
		$saved = wp_update_post(wp_slash(['ID' => $postId, 'post_content' => $new]), true);

		if (is_wp_error($saved)) {
			return $item + ['status' => 'error', 'error' => $saved->get_error_message()];
		}

		return $item + ['status' => 'changed'];
	}

	/**
	 * Replaces matches in post content, optionally in text runs only.
	 *
	 * @param string $content  Raw post content.
	 * @param string $pattern  Compiled PCRE pattern.
	 * @param string $replace  Replacement.
	 * @param bool   $isRegex  Whether $replace may use backreferences.
	 * @param int    $limit    Most replacements, 0 for all.
	 * @param bool   $textOnly Whether block comments and tags are skipped.
	 * @param int    $matches  Receives the number of replacements made.
	 *
	 * @return string|null Null when PCRE failed.
	 * @since 1.3.3
	 */
	private function replaceInContent(string $content, string $pattern, string $replace, bool $isRegex, int $limit, bool $textOnly, int &$matches): ?string
	{
		$matches  = 0;
		$segments = $textOnly
			// Odd indexes hold the captured comments / tags, even ones the text between them.
			? preg_split('/(<!--.*?-->|<[^>]*>)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE)
			: [$content];

		if (false === $segments) {
			return null;
		}

		foreach ($segments as $index => $segment) {
			if (($textOnly && 1 === $index % 2) || '' === $segment) {
				continue;
			}

			$remaining = $limit > 0 ? $limit - $matches : -1;

			if (0 === $remaining) {
				break;
			}

			$count    = 0;
			$replaced = $isRegex
				? preg_replace($pattern, $replace, $segment, $remaining, $count)
				// A literal replacement must not interpret $1 or backslashes.
				: preg_replace_callback($pattern, static function () use ($replace): string {
					return $replace;
				}, $segment, $remaining, $count);

			if (null === $replaced) {
				return null;
			}

			$segments[$index] = $replaced;
			$matches         += $count;
		}

		return implode('', $segments);
	}

	/**
	 * Before / after snippets around the first change.
	 *
	 * @param string $old Original content.
	 * @param string $new Changed content.
	 *
	 * @return array{before_snippet: string, after_snippet: string}
	 * @since 1.3.3
	 */
	private function changeSnippets(string $old, string $new): array
	{
		// Length of the common byte prefix: XOR is "\0" wherever the bytes agree.
		$offset = strspn($old ^ $new, "\0");
		$start  = max(0, $offset - self::SNIPPET_CONTEXT * 2);
		$length = self::SNIPPET_CONTEXT * 6;

		return [
			'before_snippet' => mb_strcut($old, $start, $length, 'UTF-8'),
			'after_snippet'  => mb_strcut($new, $start, $length, 'UTF-8'),
		];
	}

	/**
	 * Flat, ordered list of block names in some content.
	 *
	 * @param string $content Serialized block content.
	 *
	 * @return string[]
	 * @since 1.3.3
	 */
	private function blockNames(string $content): array
	{
		$names = [];
		$walk  = static function (array $blocks) use (&$walk, &$names): void {
			foreach ($blocks as $block) {
				// Unnamed chunks are the free text between blocks; editing that
				// text may legitimately empty one, so only real blocks count.
				if (! empty($block['blockName'])) {
					$names[] = (string) $block['blockName'];
				}

				$walk($block['innerBlocks'] ?? []);
			}
		};

		$walk(parse_blocks($content));

		return $names;
	}

	/**
	 * Input schema shared by the create-post and update-post meta parameter.
	 *
	 * A list of {key, value} items rather than a free-form object: providers
	 * disagree on additionalProperties support, a fixed item shape works everywhere.
	 *
	 * @return array<string, mixed>
	 * @since 1.3.1
	 */
	private function metaWriteSchema(): array
	{
		return [
			'type'        => 'array',
			'description' => __('Optional. Custom fields (post meta) to set or delete. Underscore-prefixed (protected) keys are only accepted when the plugin that owns them allows it.', 'agent-mod'),
			'items'       => [
				'type'       => 'object',
				'required'   => ['key'],
				'properties' => [
					'key'    => [
						'type'        => 'string',
						'description' => __('Meta key.', 'agent-mod'),
					],
					'value'  => [
						'type'        => 'string',
						'description' => __('New value. Replaces the existing value.', 'agent-mod'),
					],
					'delete' => [
						'type'        => 'boolean',
						'description' => __('true removes the field; value is ignored.', 'agent-mod'),
					],
				],
			],
		];
	}

	/**
	 * Reads post meta for get-post.
	 *
	 * Without explicit keys only public (non-underscore) meta is returned, which
	 * keeps editor/plugin internals (_edit_lock, _wp_page_template…) out of the
	 * agent's context. Single values are unwrapped; repeated keys stay a list.
	 *
	 * @param int   $postId Post ID.
	 * @param mixed $keys   Requested meta keys, or null for all public keys.
	 *
	 * @return array<string, mixed>
	 * @since 1.3.1
	 */
	private function readPostMeta(int $postId, $keys): array
	{
		$explicit = is_array($keys) && ! empty($keys);

		if ($explicit) {
			$keys = array_unique(array_filter(array_map(static function ($key) {
				return sanitize_text_field((string) $key);
			}, $keys)));
		} else {
			$keys = array_filter(array_keys((array) get_post_meta($postId)), static function ($key) {
				return ! is_protected_meta($key, 'post');
			});
		}

		$meta = [];
		foreach ($keys as $key) {
			$values = get_post_meta($postId, $key, false);

			if (empty($values)) {
				// Tell the agent a requested key is absent instead of dropping it.
				if ($explicit) {
					$meta[$key] = null;
				}
				continue;
			}

			$meta[$key] = 1 === count($values) ? $values[0] : $values;
		}

		return $meta;
	}

	/**
	 * Validates meta write items and checks the per-key capabilities.
	 *
	 * Nothing is written here, so callers can reject the whole request before
	 * touching the post. Core's edit_post_meta / delete_post_meta caps deny
	 * protected keys unless their owner registered an auth callback.
	 *
	 * @param int   $postId Post ID the meta belongs to.
	 * @param mixed $items  The 'meta' ability input.
	 *
	 * @return array<int, array{key: string, value: string, delete: bool}>|WP_Error
	 * @since 1.3.1
	 */
	private function prepareMetaWrites(int $postId, $items)
	{
		if (empty($items)) {
			return [];
		}

		if (! is_array($items)) {
			return new WP_Error('invalid_meta', __('meta must be a list of {key, value} objects.', 'agent-mod'));
		}

		$writes = [];
		foreach ($items as $item) {
			$key = is_array($item) && isset($item['key']) ? trim(sanitize_text_field((string) $item['key'])) : '';
			if ('' === $key) {
				return new WP_Error('invalid_meta', __('Each meta item needs a non-empty key.', 'agent-mod'));
			}

			$delete = ! empty($item['delete']);
			if (! $delete && ! array_key_exists('value', $item)) {
				/* translators: %s: meta key */
				return new WP_Error('invalid_meta', sprintf(__('Meta item "%s" needs a value, or delete: true to remove it.', 'agent-mod'), $key));
			}

			if (! current_user_can($delete ? 'delete_post_meta' : 'edit_post_meta', $postId, $key)) {
				return new WP_Error(
					'meta_forbidden',
					/* translators: %s: meta key */
					sprintf(__('You are not allowed to change the custom field "%s". Underscore-prefixed (protected) fields can only be changed when the plugin that owns them allows it.', 'agent-mod'), $key)
				);
			}

			$value = $delete || is_array($item['value']) ? '' : (string) $item['value'];
			if (! $delete && ! current_user_can('unfiltered_html')) {
				$value = wp_kses_post($value);
			}

			$writes[] = ['key' => $key, 'value' => $value, 'delete' => $delete];
		}

		return $writes;
	}

	/**
	 * Saves meta writes validated by prepareMetaWrites().
	 *
	 * @param int                                                          $postId Post ID.
	 * @param array<int, array{key: string, value: string, delete: bool}> $writes Validated writes.
	 *
	 * @return void
	 * @since 1.3.1
	 */
	private function applyMetaWrites(int $postId, array $writes): void
	{
		foreach ($writes as $write) {
			if ($write['delete']) {
				delete_post_meta($postId, $write['key']);
				continue;
			}

			// update_post_meta() unslashes its value.
			update_post_meta($postId, $write['key'], wp_slash($write['value']));
		}
	}

	/**
	 * Builds the get-post / update-post error for a missing post or a post_type mismatch.
	 *
	 * Names the post's actual type so the agent can retry with the right
	 * post_type, and points navigation menus and templates to their dedicated
	 * abilities instead of sending every mismatch to the template abilities.
	 *
	 * @param int          $postId   Requested post ID.
	 * @param string       $postType Expected post type from the input.
	 * @param WP_Post|null $post     The loaded post, or null when it does not exist.
	 * @param bool         $write    Whether the caller is a write ability.
	 *
	 * @return string
	 * @since 1.3.2
	 */
	private function postTypeMismatchError(int $postId, string $postType, ?WP_Post $post, bool $write): string
	{
		if (! $post) {
			return sprintf(
				/* translators: %d: post ID */
				__('Post with ID %d not found. Use list-posts to find a valid post_id.', 'agent-mod'),
				$postId
			);
		}

		$actualType = $post->post_type;

		if ('wp_navigation' === $actualType) {
			return sprintf(
				/* translators: %d: post ID */
				__('Post %d is a navigation menu (wp_navigation). Use get-navigation / update-navigation with navigation_id to read or edit it in place — do not create a new menu.', 'agent-mod'),
				$postId
			);
		}

		if (in_array($actualType, ['wp_template', 'wp_template_part'], true)) {
			return sprintf(
				/* translators: 1: post ID, 2: post type, 3: ability names */
				__('Post %1$d is a "%2$s". Use %3$s instead.', 'agent-mod'),
				$postId,
				$actualType,
				'wp_template' === $actualType
					? ($write ? 'add-or-update-template' : 'get-template')
					: ($write ? 'add-or-update-template-part' : 'get-template-part')
			);
		}

		return sprintf(
			/* translators: 1: post ID, 2: actual post type, 3: expected post type */
			__('Post %1$d is of type "%2$s", not "%3$s". Retry with post_type "%2$s".', 'agent-mod'),
			$postId,
			$actualType,
			$postType
		);
	}

	/**
	 * Checks that a status is allowed and that the user may set it.
	 *
	 * wp_insert_post()/wp_update_post() do no capability checks of their own,
	 * so publishing is gated here the way the editor gates it.
	 *
	 * @param string $postType   Post type slug.
	 * @param string $postStatus Requested status.
	 *
	 * @return true|WP_Error
	 * @since 1.3.1
	 */
	private function checkPostStatus(string $postType, string $postStatus)
	{
		if (! in_array($postStatus, ['draft', 'publish', 'pending', 'private'], true)) {
			/* translators: %s: post status */
			return new WP_Error('invalid_status', sprintf(__('Invalid post_status "%s". Use draft, publish, pending or private.', 'agent-mod'), $postStatus));
		}

		if (in_array($postStatus, ['publish', 'private'], true)) {
			$postTypeObject = get_post_type_object($postType);

			if (! $postTypeObject || ! current_user_can($postTypeObject->cap->publish_posts)) {
				return new WP_Error('status_forbidden', __('You are not allowed to publish this post type. Use "draft" or "pending" instead.', 'agent-mod'));
			}
		}

		return true;
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

		// wp_update_post() unslashes its input; keep the attribute JSON escapes.
		$result = wp_update_post(wp_slash($updateArgs));

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

		// wp_insert_post() unslashes its input; keep the attribute JSON escapes.
		$postId = wp_insert_post(wp_slash([
			'post_type'    => 'wp_block',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $content,
		]));

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

		// wp_insert_post() unslashes its input; keep the attribute JSON escapes.
		$postId = wp_insert_post(wp_slash([
			'post_type'    => 'wp_block',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $serializedContent,
			'post_excerpt' => $description,
		]));

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
