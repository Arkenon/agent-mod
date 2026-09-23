<?php

/**
 * Taxonomy term ability service.
 *
 * The Abilities API surface shipped by AgentMod could read and write posts,
 * patterns and templates, but had no way to work with taxonomy terms: an agent
 * could have a custom taxonomy registered for it (by Native Custom Fields or
 * any other plugin) and still be unable to seed a single category into it.
 *
 * This service closes that gap with the `wp term` command family: listing the
 * registered taxonomies, listing and reading terms, and creating, updating and
 * deleting them. Capability checks are taken from each taxonomy's own `cap`
 * object, so a taxonomy that restricts term management to editors is respected
 * here exactly as it is in wp-admin.
 *
 * @package AgentMod
 * @subpackage Services
 * @since 1.2.4
 */

namespace AgentMod\Services;

use WP_Error;
use WP_Taxonomy;
use WP_Term;

defined('ABSPATH') || exit;

class TaxonomyAbilityService
{
	/**
	 * Ability category slug. Shared with the content abilities, because terms
	 * are site content rather than installation management.
	 *
	 * @var string
	 * @since 1.2.4
	 */
	private const CATEGORY = 'agent-mod';

	/**
	 * Abilities that must be confirmed by the user before they run.
	 *
	 * Creating and updating a term is cheap to undo; deleting one detaches it
	 * from every post it was assigned to and cannot be reversed.
	 *
	 * @var string[]
	 * @since 1.2.4
	 */
	private const CONFIRM_REQUIRED = [
		'agent-mod/delete-term',
	];

	/**
	 * Maximum number of terms a single create-terms call may insert.
	 *
	 * Seeding a taxonomy is the main use case, so a batch is allowed, but an
	 * unbounded one would let a single tool call run past the request timeout.
	 *
	 * @var int
	 * @since 1.2.4
	 */
	private const MAX_BATCH_TERMS = 100;

	/**
	 * Settings service, used for result limits.
	 *
	 * @var SettingsService
	 * @since 1.2.4
	 */
	private SettingsService $settings;

	/**
	 * Constructor. Binds the abilities API hooks.
	 *
	 * @param SettingsService $settings Settings service.
	 *
	 * @since 1.2.4
	 */
	public function __construct(SettingsService $settings)
	{
		$this->settings = $settings;

		add_action('wp_abilities_api_init', [$this, 'registerAbilities']);

		add_filter('agent_mod_ability_requires_confirmation', [$this, 'requiresConfirmation'], 10, 2);
	}

	/**
	 * Marks the destructive abilities in this group as requiring confirmation.
	 *
	 * @param bool   $requires Current value.
	 * @param string $name     Ability name.
	 *
	 * @return bool
	 * @since 1.2.4
	 */
	public function requiresConfirmation(bool $requires, string $name): bool
	{
		return in_array($name, self::CONFIRM_REQUIRED, true) ? true : $requires;
	}

	/**
	 * Registers every taxonomy term ability.
	 *
	 * @return void
	 * @since 1.2.4
	 */
	public function registerAbilities(): void
	{
		if (! function_exists('wp_register_ability')) {
			return;
		}

		$termShape = [
			'type'       => 'object',
			'properties' => [
				'id'          => ['type' => 'integer'],
				'name'        => ['type' => 'string'],
				'slug'        => ['type' => 'string'],
				'taxonomy'    => ['type' => 'string'],
				'description' => ['type' => 'string'],
				'parent'      => ['type' => 'integer'],
				'parent_slug' => ['type' => 'string'],
				'count'       => ['type' => 'integer'],
				'link'        => ['type' => 'string'],
			],
		];

		wp_register_ability(
			'agent-mod/list-taxonomies',
			[
				'label'               => __('List Taxonomies', 'agent-mod'),
				'description'         => __('Lists the taxonomies registered on this site with their slug, label, whether they are hierarchical, which post types they apply to and how many terms they hold. Use this first to find the exact taxonomy slug before listing or writing terms; the slug is rarely identical to the label shown in the admin menu.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeListTaxonomies'],
				'permission_callback' => static function ($input = null): bool {
					return current_user_can('edit_posts');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_type'   => [
							'type'        => 'string',
							'description' => __('Optional. Only return taxonomies attached to this post type, e.g. "post" or "service_provider".', 'agent-mod'),
						],
						'public_only' => [
							'type'        => 'boolean',
							'description' => __('Optional. When true, hides internal taxonomies such as wp_theme and wp_pattern_category. Defaults to false.', 'agent-mod'),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'taxonomies' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'slug'         => ['type' => 'string'],
									'label'        => ['type' => 'string'],
									'hierarchical' => ['type' => 'boolean'],
									'public'       => ['type' => 'boolean'],
									'post_types'   => ['type' => 'array', 'items' => ['type' => 'string']],
									'term_count'   => ['type' => 'integer'],
								],
							],
						],
						'total'      => ['type' => 'integer'],
						'error'      => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/list-terms',
			[
				'label'               => __('List Terms', 'agent-mod'),
				'description'         => __('Lists the terms in one taxonomy with their ID, name, slug, description, parent and post count. Empty terms are included by default, so freshly seeded categories show up too. Call this before creating terms to see what already exists, and to collect the parent IDs needed for a hierarchy.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeListTerms'],
				'permission_callback' => static function ($input = null): bool {
					return current_user_can('edit_posts');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'taxonomy'   => [
							'type'        => 'string',
							'description' => __('The taxonomy slug, e.g. "category" or "service_category".', 'agent-mod'),
						],
						'search'     => [
							'type'        => 'string',
							'description' => __('Optional. Matches term names and slugs.', 'agent-mod'),
						],
						'parent'     => [
							'type'        => 'integer',
							'description' => __('Optional. Only return direct children of this term ID. Pass 0 for top-level terms only.', 'agent-mod'),
						],
						'hide_empty' => [
							'type'        => 'boolean',
							'description' => __('Optional. When true, terms with no assigned posts are omitted. Defaults to false.', 'agent-mod'),
						],
					],
					'required'   => ['taxonomy'],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'terms'     => ['type' => 'array', 'items' => $termShape],
						'total'     => ['type' => 'integer'],
						'truncated' => ['type' => 'boolean'],
						'error'     => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/get-term',
			[
				'label'               => __('Get Term', 'agent-mod'),
				'description'         => __('Reads one term by ID or slug and returns its full record, including its parent and how many posts are assigned to it. Use this to confirm a term exists before updating or deleting it.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeGetTerm'],
				'permission_callback' => static function ($input = null): bool {
					return current_user_can('edit_posts');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'taxonomy' => [
							'type'        => 'string',
							'description' => __('The taxonomy slug the term belongs to.', 'agent-mod'),
						],
						'id'       => [
							'type'        => 'integer',
							'description' => __('The term ID. Either id or slug is required.', 'agent-mod'),
						],
						'slug'     => [
							'type'        => 'string',
							'description' => __('The term slug. Used when id is omitted.', 'agent-mod'),
						],
					],
					'required'   => ['taxonomy'],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'term'  => $termShape,
						'error' => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/create-terms',
			[
				'label'               => __('Create Terms', 'agent-mod'),
				'description'         => __('Creates one or more terms in a taxonomy in a single call, which is how a taxonomy is seeded. Each entry needs a name; slug, description and parent are optional. parent takes either a term ID or the slug of a term in the same taxonomy, and only works on hierarchical taxonomies. Terms that already exist are reported as "exists" and left untouched, so the call is safe to repeat. Create parent levels before their children so the parent slugs resolve.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeCreateTerms'],
				'permission_callback' => function ($input = null): bool {
					return $this->userCan($input, 'manage_terms', 'manage_categories');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'taxonomy' => [
							'type'        => 'string',
							'description' => __('The taxonomy slug the terms belong to.', 'agent-mod'),
						],
						'terms'    => [
							'type'        => 'array',
							'description' => __('The terms to create, in order. Maximum 100 per call.', 'agent-mod'),
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'name'        => [
										'type'        => 'string',
										'description' => __('The term name as shown to visitors, e.g. "Plumbing".', 'agent-mod'),
									],
									'slug'        => [
										'type'        => 'string',
										'description' => __('Optional. URL slug. Generated from the name when omitted.', 'agent-mod'),
									],
									'description' => [
										'type'        => 'string',
										'description' => __('Optional. Term description.', 'agent-mod'),
									],
									'parent'      => [
										'type'        => ['string', 'integer'],
										'description' => __('Optional. Parent term ID or slug, for hierarchical taxonomies only.', 'agent-mod'),
									],
								],
								'required'   => ['name'],
							],
						],
					],
					'required'   => ['taxonomy', 'terms'],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success' => ['type' => 'boolean'],
						'created' => ['type' => 'integer'],
						'skipped' => ['type' => 'integer'],
						'failed'  => ['type' => 'integer'],
						'results' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'name'    => ['type' => 'string'],
									'id'      => ['type' => 'integer'],
									'slug'    => ['type' => 'string'],
									'status'  => ['type' => 'string'],
									'message' => ['type' => 'string'],
								],
							],
						],
						'error'   => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/update-term',
			[
				'label'               => __('Update Term', 'agent-mod'),
				'description'         => __('Renames a term or changes its slug, description or parent. Identify the term by id, or by slug together with the taxonomy. Only the fields you send are changed; everything else is preserved. Pass parent as 0 to move a term to the top level.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeUpdateTerm'],
				'permission_callback' => function ($input = null): bool {
					return $this->userCan($input, 'edit_terms', 'manage_categories');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'taxonomy'    => [
							'type'        => 'string',
							'description' => __('The taxonomy slug the term belongs to.', 'agent-mod'),
						],
						'id'          => [
							'type'        => 'integer',
							'description' => __('The term ID. Either id or slug is required.', 'agent-mod'),
						],
						'slug'        => [
							'type'        => 'string',
							'description' => __('The current term slug, used to find the term when id is omitted.', 'agent-mod'),
						],
						'name'        => [
							'type'        => 'string',
							'description' => __('Optional. New term name.', 'agent-mod'),
						],
						'new_slug'    => [
							'type'        => 'string',
							'description' => __('Optional. New URL slug. Changing this breaks existing links to the term archive.', 'agent-mod'),
						],
						'description' => [
							'type'        => 'string',
							'description' => __('Optional. New term description.', 'agent-mod'),
						],
						'parent'      => [
							'type'        => ['string', 'integer'],
							'description' => __('Optional. New parent term ID or slug; 0 moves the term to the top level. Hierarchical taxonomies only.', 'agent-mod'),
						],
					],
					'required'   => ['taxonomy'],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success' => ['type' => 'boolean'],
						'term'    => $termShape,
						'error'   => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/delete-term',
			[
				'label'               => __('Delete Term', 'agent-mod'),
				'description'         => __('Permanently deletes a term and removes it from every post it was assigned to. On a hierarchical taxonomy its children move up to the deleted term\'s parent rather than being deleted. The posts themselves are never deleted. This cannot be undone and requires user confirmation.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeDeleteTerm'],
				'permission_callback' => function ($input = null): bool {
					return $this->userCan($input, 'delete_terms', 'manage_categories');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'taxonomy' => [
							'type'        => 'string',
							'description' => __('The taxonomy slug the term belongs to.', 'agent-mod'),
						],
						'id'       => [
							'type'        => 'integer',
							'description' => __('The term ID. Either id or slug is required.', 'agent-mod'),
						],
						'slug'     => [
							'type'        => 'string',
							'description' => __('The term slug. Used when id is omitted.', 'agent-mod'),
						],
					],
					'required'   => ['taxonomy'],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success' => ['type' => 'boolean'],
						'id'      => ['type' => 'integer'],
						'name'    => ['type' => 'string'],
						'message' => ['type' => 'string'],
						'error'   => ['type' => 'string'],
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
	// Execute callbacks
	// =========================================================================

	/**
	 * Execute callback for agent-mod/list-taxonomies.
	 *
	 * @param mixed $input Optional 'post_type' and 'public_only'.
	 *
	 * @return array<string, mixed>
	 * @since 1.2.4
	 */
	public function executeListTaxonomies($input = null): array
	{
		$input      = $this->toArray($input);
		$postType   = isset($input['post_type']) ? sanitize_key((string) $input['post_type']) : '';
		$publicOnly = ! empty($input['public_only']);

		if ('' !== $postType && ! post_type_exists($postType)) {
			return [
				'taxonomies' => [],
				'total'      => 0,
				'error'      => sprintf(
					/* translators: %s: post type slug. */
					__('The post type "%s" is not registered on this site.', 'agent-mod'),
					$postType
				),
			];
		}

		$taxonomies = '' !== $postType
			? get_object_taxonomies($postType, 'objects')
			: get_taxonomies([], 'objects');

		$items = [];

		foreach ($taxonomies as $taxonomy) {
			if (! $taxonomy instanceof WP_Taxonomy) {
				continue;
			}

			if ($publicOnly && ! $taxonomy->public) {
				continue;
			}

			$items[] = [
				'slug'         => $taxonomy->name,
				'label'        => is_object($taxonomy->labels) ? (string) $taxonomy->labels->name : $taxonomy->name,
				'hierarchical' => (bool) $taxonomy->hierarchical,
				'public'       => (bool) $taxonomy->public,
				'post_types'   => array_values((array) $taxonomy->object_type),
				'term_count'   => (int) wp_count_terms(['taxonomy' => $taxonomy->name, 'hide_empty' => false]),
			];
		}

		return [
			'taxonomies' => $items,
			'total'      => count($items),
		];
	}

	/**
	 * Execute callback for agent-mod/list-terms.
	 *
	 * @param mixed $input Input data.
	 *
	 * @return array<string, mixed>
	 * @since 1.2.4
	 */
	public function executeListTerms($input = null): array
	{
		$input    = $this->toArray($input);
		$taxonomy = $this->resolveTaxonomy($input);

		if ($taxonomy instanceof WP_Error) {
			return ['terms' => [], 'total' => 0, 'truncated' => false, 'error' => $taxonomy->get_error_message()];
		}

		$limit = $this->settings->getMaxSearchResults();

		$args = [
			'taxonomy'   => $taxonomy->name,
			'hide_empty' => ! empty($input['hide_empty']),
			'orderby'    => 'name',
			'order'      => 'ASC',
			'number'     => $limit + 1,
		];

		if (! empty($input['search'])) {
			$args['search'] = sanitize_text_field((string) $input['search']);
		}

		if (isset($input['parent']) && '' !== $input['parent']) {
			$args['parent'] = (int) $input['parent'];
		}

		$terms = get_terms($args);

		if ($terms instanceof WP_Error) {
			return ['terms' => [], 'total' => 0, 'truncated' => false, 'error' => $terms->get_error_message()];
		}

		$truncated = count($terms) > $limit;
		$terms     = array_slice($terms, 0, $limit);

		return [
			'terms'     => array_map([$this, 'formatTerm'], $terms),
			'total'     => count($terms),
			'truncated' => $truncated,
		];
	}

	/**
	 * Execute callback for agent-mod/get-term.
	 *
	 * @param mixed $input Input data.
	 *
	 * @return array<string, mixed>
	 * @since 1.2.4
	 */
	public function executeGetTerm($input = null): array
	{
		$input    = $this->toArray($input);
		$taxonomy = $this->resolveTaxonomy($input);

		if ($taxonomy instanceof WP_Error) {
			return ['error' => $taxonomy->get_error_message()];
		}

		$term = $this->findTerm($input, $taxonomy);

		if ($term instanceof WP_Error) {
			return ['error' => $term->get_error_message()];
		}

		return ['term' => $this->formatTerm($term)];
	}

	/**
	 * Execute callback for agent-mod/create-terms.
	 *
	 * Each entry is inserted on its own: one bad row reports an error in its
	 * own result and the remaining terms are still created, because a partly
	 * seeded taxonomy the agent can inspect is more useful than an
	 * all-or-nothing failure.
	 *
	 * @param mixed $input Input data.
	 *
	 * @return array<string, mixed>
	 * @since 1.2.4
	 */
	public function executeCreateTerms($input = null): array
	{
		$input    = $this->toArray($input);
		$taxonomy = $this->resolveTaxonomy($input);

		if ($taxonomy instanceof WP_Error) {
			return [
				'success' => false,
				'created' => 0,
				'skipped' => 0,
				'failed'  => 0,
				'results' => [],
				'error'   => $taxonomy->get_error_message(),
			];
		}

		$rows = isset($input['terms']) && is_array($input['terms']) ? $input['terms'] : [];

		if (empty($rows)) {
			return [
				'success' => false,
				'created' => 0,
				'skipped' => 0,
				'failed'  => 0,
				'results' => [],
				'error'   => __('No terms were supplied. Send a "terms" array holding at least one object with a "name".', 'agent-mod'),
			];
		}

		if (count($rows) > self::MAX_BATCH_TERMS) {
			return [
				'success' => false,
				'created' => 0,
				'skipped' => 0,
				'failed'  => 0,
				'results' => [],
				'error'   => sprintf(
					/* translators: %d: maximum number of terms per call. */
					__('Too many terms in one call. Send at most %d terms per call and repeat the call for the rest.', 'agent-mod'),
					self::MAX_BATCH_TERMS
				),
			];
		}

		$results = [];
		$created = 0;
		$skipped = 0;
		$failed  = 0;

		foreach ($rows as $row) {
			$row  = $this->toArray($row);
			$name = isset($row['name']) ? sanitize_text_field((string) $row['name']) : '';

			if ('' === $name) {
				$failed++;
				$results[] = [
					'name'    => '',
					'status'  => 'error',
					'message' => __('Each term needs a non-empty "name".', 'agent-mod'),
				];
				continue;
			}

			$parent = $this->resolveParent($row['parent'] ?? null, $taxonomy);

			if ($parent instanceof WP_Error) {
				$failed++;
				$results[] = ['name' => $name, 'status' => 'error', 'message' => $parent->get_error_message()];
				continue;
			}

			$args = [];

			if (null !== $parent) {
				$args['parent'] = $parent;
			}

			if (! empty($row['slug'])) {
				$args['slug'] = sanitize_title((string) $row['slug']);
			}

			if (isset($row['description'])) {
				$args['description'] = wp_kses_post((string) $row['description']);
			}

			$existing = term_exists($args['slug'] ?? $name, $taxonomy->name, $args['parent'] ?? null);

			if ($existing) {
				$skipped++;
				$results[] = [
					'name'    => $name,
					'id'      => (int) (is_array($existing) ? $existing['term_id'] : $existing),
					'status'  => 'exists',
					'message' => __('A term with this name or slug already exists here; it was left unchanged.', 'agent-mod'),
				];
				continue;
			}

			$inserted = wp_insert_term($name, $taxonomy->name, $args);

			if ($inserted instanceof WP_Error) {
				$failed++;
				$results[] = ['name' => $name, 'status' => 'error', 'message' => $inserted->get_error_message()];
				continue;
			}

			$term = get_term((int) $inserted['term_id'], $taxonomy->name);
			$created++;
			$results[] = [
				'name'   => $name,
				'id'     => (int) $inserted['term_id'],
				'slug'   => $term instanceof WP_Term ? $term->slug : '',
				'status' => 'created',
			];
		}

		return [
			'success' => 0 === $failed,
			'created' => $created,
			'skipped' => $skipped,
			'failed'  => $failed,
			'results' => $results,
		];
	}

	/**
	 * Execute callback for agent-mod/update-term.
	 *
	 * @param mixed $input Input data.
	 *
	 * @return array<string, mixed>
	 * @since 1.2.4
	 */
	public function executeUpdateTerm($input = null): array
	{
		$input    = $this->toArray($input);
		$taxonomy = $this->resolveTaxonomy($input);

		if ($taxonomy instanceof WP_Error) {
			return ['success' => false, 'error' => $taxonomy->get_error_message()];
		}

		$term = $this->findTerm($input, $taxonomy);

		if ($term instanceof WP_Error) {
			return ['success' => false, 'error' => $term->get_error_message()];
		}

		$args = [];

		if (isset($input['name']) && '' !== trim((string) $input['name'])) {
			$args['name'] = sanitize_text_field((string) $input['name']);
		}

		if (! empty($input['new_slug'])) {
			$args['slug'] = sanitize_title((string) $input['new_slug']);
		}

		if (isset($input['description'])) {
			$args['description'] = wp_kses_post((string) $input['description']);
		}

		if (array_key_exists('parent', $input) && null !== $input['parent'] && '' !== $input['parent']) {
			$parent = $this->resolveParent($input['parent'], $taxonomy);

			if ($parent instanceof WP_Error) {
				return ['success' => false, 'error' => $parent->get_error_message()];
			}

			if (null !== $parent) {
				if ($parent === (int) $term->term_id) {
					return [
						'success' => false,
						'error'   => __('A term cannot be its own parent.', 'agent-mod'),
					];
				}

				$args['parent'] = $parent;
			}
		}

		if (empty($args)) {
			return [
				'success' => false,
				'error'   => __('Nothing to update. Send at least one of name, new_slug, description or parent.', 'agent-mod'),
			];
		}

		$updated = wp_update_term($term->term_id, $taxonomy->name, $args);

		if ($updated instanceof WP_Error) {
			return ['success' => false, 'error' => $updated->get_error_message()];
		}

		$fresh = get_term((int) $updated['term_id'], $taxonomy->name);

		return [
			'success' => true,
			'term'    => $fresh instanceof WP_Term ? $this->formatTerm($fresh) : [],
		];
	}

	/**
	 * Execute callback for agent-mod/delete-term.
	 *
	 * @param mixed $input Input data.
	 *
	 * @return array<string, mixed>
	 * @since 1.2.4
	 */
	public function executeDeleteTerm($input = null): array
	{
		$input    = $this->toArray($input);
		$taxonomy = $this->resolveTaxonomy($input);

		if ($taxonomy instanceof WP_Error) {
			return ['success' => false, 'error' => $taxonomy->get_error_message()];
		}

		$term = $this->findTerm($input, $taxonomy);

		if ($term instanceof WP_Error) {
			return ['success' => false, 'error' => $term->get_error_message()];
		}

		$protected = $this->assertTermDeletable($term, $taxonomy);

		if ($protected instanceof WP_Error) {
			return ['success' => false, 'error' => $protected->get_error_message()];
		}

		$deleted = wp_delete_term($term->term_id, $taxonomy->name);

		if ($deleted instanceof WP_Error) {
			return ['success' => false, 'error' => $deleted->get_error_message()];
		}

		if (true !== $deleted) {
			return [
				'success' => false,
				'error'   => __('WordPress refused to delete this term. It is either the default term for its taxonomy or no longer exists.', 'agent-mod'),
			];
		}

		return [
			'success' => true,
			'id'      => (int) $term->term_id,
			'name'    => $term->name,
			'message' => sprintf(
				/* translators: 1: term name, 2: taxonomy slug. */
				__('Deleted the term "%1$s" from the "%2$s" taxonomy.', 'agent-mod'),
				$term->name,
				$taxonomy->name
			),
		];
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Resolves and validates the taxonomy named in the input.
	 *
	 * @param array<string, mixed> $input Ability input.
	 *
	 * @return WP_Taxonomy|WP_Error
	 * @since 1.2.4
	 */
	private function resolveTaxonomy(array $input)
	{
		$slug = isset($input['taxonomy']) ? sanitize_key((string) $input['taxonomy']) : '';

		if ('' === $slug) {
			return new WP_Error(
				'agent_mod_missing_taxonomy',
				__('A "taxonomy" slug is required. Call agent-mod/list-taxonomies to see the slugs registered on this site.', 'agent-mod')
			);
		}

		$taxonomy = get_taxonomy($slug);

		if (! $taxonomy instanceof WP_Taxonomy) {
			return new WP_Error(
				'agent_mod_unknown_taxonomy',
				sprintf(
					/* translators: %s: taxonomy slug. */
					__('The taxonomy "%s" is not registered on this site. Call agent-mod/list-taxonomies to see the available slugs.', 'agent-mod'),
					$slug
				)
			);
		}

		return $taxonomy;
	}

	/**
	 * Finds a term from an 'id' or 'slug' input pair.
	 *
	 * @param array<string, mixed> $input    Ability input.
	 * @param WP_Taxonomy          $taxonomy Resolved taxonomy.
	 *
	 * @return WP_Term|WP_Error
	 * @since 1.2.4
	 */
	private function findTerm(array $input, WP_Taxonomy $taxonomy)
	{
		$id = isset($input['id']) ? (int) $input['id'] : 0;

		if ($id > 0) {
			$term = get_term($id, $taxonomy->name);

			if ($term instanceof WP_Term) {
				return $term;
			}

			return new WP_Error(
				'agent_mod_term_not_found',
				sprintf(
					/* translators: 1: term ID, 2: taxonomy slug. */
					__('No term with ID %1$d exists in the "%2$s" taxonomy.', 'agent-mod'),
					$id,
					$taxonomy->name
				)
			);
		}

		$slug = isset($input['slug']) ? sanitize_title((string) $input['slug']) : '';

		if ('' === $slug) {
			return new WP_Error(
				'agent_mod_missing_term_identifier',
				__('Identify the term with either "id" or "slug".', 'agent-mod')
			);
		}

		$term = get_term_by('slug', $slug, $taxonomy->name);

		if (! $term instanceof WP_Term) {
			return new WP_Error(
				'agent_mod_term_not_found',
				sprintf(
					/* translators: 1: term slug, 2: taxonomy slug. */
					__('No term with the slug "%1$s" exists in the "%2$s" taxonomy. Call agent-mod/list-terms to see what is there.', 'agent-mod'),
					$slug,
					$taxonomy->name
				)
			);
		}

		return $term;
	}

	/**
	 * Resolves a parent reference — a term ID or a slug — to a term ID.
	 *
	 * @param mixed       $parent   Raw parent value.
	 * @param WP_Taxonomy $taxonomy Resolved taxonomy.
	 *
	 * @return int|null|WP_Error Null when no parent was requested.
	 * @since 1.2.4
	 */
	private function resolveParent($parent, WP_Taxonomy $taxonomy)
	{
		if (null === $parent || '' === $parent) {
			return null;
		}

		if (is_numeric($parent) && 0 === (int) $parent) {
			return 0;
		}

		if (! $taxonomy->hierarchical) {
			return new WP_Error(
				'agent_mod_flat_taxonomy',
				sprintf(
					/* translators: %s: taxonomy slug. */
					__('The "%s" taxonomy is flat (non-hierarchical), so its terms cannot have a parent. Omit the parent field.', 'agent-mod'),
					$taxonomy->name
				)
			);
		}

		$term = is_numeric($parent)
			? get_term((int) $parent, $taxonomy->name)
			: get_term_by('slug', sanitize_title((string) $parent), $taxonomy->name);

		if (! $term instanceof WP_Term) {
			return new WP_Error(
				'agent_mod_parent_not_found',
				sprintf(
					/* translators: 1: parent reference, 2: taxonomy slug. */
					__('The parent term "%1$s" does not exist in the "%2$s" taxonomy. Create the parent first, then use its ID or slug.', 'agent-mod'),
					(string) $parent,
					$taxonomy->name
				)
			);
		}

		return (int) $term->term_id;
	}

	/**
	 * Refuses deletion of terms the site depends on.
	 *
	 * A taxonomy's default term (Uncategorized for posts) is what every post
	 * falls back to; core silently refuses to delete it, so this turns that
	 * silence into an explanation the agent can pass on.
	 *
	 * @param WP_Term     $term     Term being deleted.
	 * @param WP_Taxonomy $taxonomy Resolved taxonomy.
	 *
	 * @return WP_Error|null Null when allowed.
	 * @since 1.2.4
	 */
	private function assertTermDeletable(WP_Term $term, WP_Taxonomy $taxonomy): ?WP_Error
	{
		$defaults = [
			'category'      => (int) get_option('default_category'),
			'link_category' => (int) get_option('default_link_category'),
		];

		$default = $defaults[$taxonomy->name] ?? (int) get_option('default_term_' . $taxonomy->name);

		if ($default > 0 && $default === (int) $term->term_id) {
			return new WP_Error(
				'agent_mod_default_term',
				sprintf(
					/* translators: 1: term name, 2: taxonomy slug. */
					__('"%1$s" is the default term for the "%2$s" taxonomy and cannot be deleted. Pick a different default first.', 'agent-mod'),
					$term->name,
					$taxonomy->name
				)
			);
		}

		return null;
	}

	/**
	 * Checks a taxonomy-specific capability for the taxonomy named in the input.
	 *
	 * Falls back to a generic capability when the input carries no usable
	 * taxonomy, so the permission check still denies anonymous callers instead
	 * of failing before the execute callback can explain the problem.
	 *
	 * @param mixed  $input    Raw ability input.
	 * @param string $capKey   Key on the taxonomy's cap object, e.g. 'edit_terms'.
	 * @param string $fallback Capability used when the taxonomy is unknown.
	 *
	 * @return bool
	 * @since 1.2.4
	 */
	private function userCan($input, string $capKey, string $fallback): bool
	{
		$input = $this->toArray($input);
		$slug  = isset($input['taxonomy']) ? sanitize_key((string) $input['taxonomy']) : '';
		$tax   = '' !== $slug ? get_taxonomy($slug) : null;

		if (! $tax instanceof WP_Taxonomy) {
			return current_user_can($fallback);
		}

		$capability = $tax->cap->{$capKey} ?? $fallback;

		return current_user_can($capability);
	}

	/**
	 * Shapes a term for ability output.
	 *
	 * @param mixed $term Term object.
	 *
	 * @return array<string, mixed>
	 * @since 1.2.4
	 */
	private function formatTerm($term): array
	{
		if (! $term instanceof WP_Term) {
			return [];
		}

		$parentSlug = '';

		if ($term->parent) {
			$parent = get_term((int) $term->parent, $term->taxonomy);

			if ($parent instanceof WP_Term) {
				$parentSlug = $parent->slug;
			}
		}

		$link = get_term_link($term);

		return [
			'id'          => (int) $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'taxonomy'    => $term->taxonomy,
			'description' => $term->description,
			'parent'      => (int) $term->parent,
			'parent_slug' => $parentSlug,
			'count'       => (int) $term->count,
			'link'        => is_string($link) ? $link : '',
		];
	}

	/**
	 * Normalises ability input to an array.
	 *
	 * Abilities receive `mixed` input; providers occasionally hand over an
	 * object or null instead of the declared object schema.
	 *
	 * @param mixed $input Raw ability input.
	 *
	 * @return array<string, mixed>
	 * @since 1.2.4
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
