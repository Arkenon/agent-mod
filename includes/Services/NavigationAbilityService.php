<?php

/**
 * Navigation menu ability service.
 *
 * Block themes store their menus as wp_navigation posts, referenced from
 * templates and template parts by `<!-- wp:navigation {"ref":ID} /-->`. The
 * generic post abilities could technically read and write them, but an agent
 * had to round-trip the whole serialized markup to add a single link, and a
 * failed attempt pushed it towards creating a brand-new menu instead of
 * editing the existing one.
 *
 * This service exposes menus as structured item trees: listing menus with the
 * templates that use them, reading a menu as items addressed by path ("0",
 * "2.1"), and editing it through add / update / remove / move operations that
 * are validated as a whole before a single write.
 *
 * @package AgentMod
 * @subpackage Services
 * @since x.x.x
 */

namespace AgentMod\Services;

use WP_Error;
use WP_Post;

defined('ABSPATH') || exit;

class NavigationAbilityService
{
	/**
	 * Ability category slug. Shared with the block design abilities.
	 *
	 * @var string
	 * @since x.x.x
	 */
	private const CATEGORY = 'agent-mod';

	/**
	 * Navigation post type.
	 *
	 * @var string
	 * @since x.x.x
	 */
	private const POST_TYPE = 'wp_navigation';

	/**
	 * Blocks whose link attributes can be edited and which can hold children.
	 *
	 * @var string[]
	 * @since x.x.x
	 */
	private const LINK_BLOCKS = ['core/navigation-link', 'core/navigation-submenu'];

	/**
	 * Maximum menus returned by list-navigations.
	 *
	 * @var int
	 * @since x.x.x
	 */
	private const MAX_MENUS = 100;

	/**
	 * Block markup validator, shared with the post/template write abilities.
	 *
	 * @var BlockMarkupValidator
	 * @since x.x.x
	 */
	private BlockMarkupValidator $blockMarkupValidator;

	/**
	 * Constructor. Binds the abilities API hook.
	 *
	 * @param BlockMarkupValidator $blockMarkupValidator Block markup validator.
	 *
	 * @since x.x.x
	 */
	public function __construct(BlockMarkupValidator $blockMarkupValidator)
	{
		$this->blockMarkupValidator = $blockMarkupValidator;

		add_action('wp_abilities_api_init', [$this, 'registerAbilities']);
	}

	/**
	 * Registers the navigation abilities.
	 *
	 * @return void
	 * @since x.x.x
	 */
	public function registerAbilities(): void
	{
		if (! function_exists('wp_register_ability')) {
			return;
		}

		$permission = static function (): bool {
			return current_user_can('edit_theme_options');
		};

		$itemsSchema = [
			'type'        => 'array',
			'description' => __('Menu items as a tree. Each item has a path ("0", "2.1" = second child of the third item) used to address it in update-navigation.', 'agent-mod'),
		];

		wp_register_ability(
			'agent-mod/list-navigations',
			[
				'label'               => __('List Navigation Menus', 'agent-mod'),
				'description'         => __('Lists the navigation menus (wp_navigation posts) of a block theme with their item count and the templates / template parts that display them (e.g. used_in: footer). Use this first when asked to change "the header menu" or "the footer menu", then edit that menu with update-navigation — never create a new menu to change an existing one.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeListNavigations'],
				'permission_callback' => $permission,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						's' => [
							'type'        => 'string',
							'description' => __('Optional. Filter menus by title.', 'agent-mod'),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'     => ['type' => 'boolean'],
						'navigations' => ['type' => 'array'],
						'total'       => ['type' => 'integer'],
						'error'       => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/get-navigation',
			[
				'label'               => __('Get Navigation Menu', 'agent-mod'),
				'description'         => __('Returns a navigation menu (wp_navigation post) as a tree of items with their path, block type, label, url and link target. navigation_id is the "ref" of a wp:navigation block, or comes from list-navigations. Use the paths with update-navigation.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeGetNavigation'],
				'permission_callback' => $permission,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => ['navigation_id'],
					'properties' => [
						'navigation_id' => [
							'type'        => 'integer',
							'description' => __('wp_navigation post ID (the "ref" attribute of a wp:navigation block).', 'agent-mod'),
						],
						'include_html'  => [
							'type'        => 'boolean',
							'description' => __('Optional. Also return the raw block markup. Default false; only needed for blocks the item tree does not describe.', 'agent-mod'),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'       => ['type' => 'boolean'],
						'navigation_id' => ['type' => 'integer'],
						'title'         => ['type' => 'string'],
						'status'        => ['type' => 'string'],
						'items'         => $itemsSchema,
						'html'          => ['type' => 'string'],
						'error'         => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'agent-mod/update-navigation',
			[
				'label'               => __('Update Navigation Menu', 'agent-mod'),
				'description'         => __('Edits an existing navigation menu in place with operations, so every template that shows the menu picks up the change. "add" appends a link (or inserts it at position / under parent_path), "update" changes an item\'s label/url/target, "remove" deletes an item with its children, "move" relocates it. Operations run in order against the result of the previous one and are all validated before anything is saved: one invalid operation saves nothing. Returns the updated item tree. Alternatively pass html to replace the whole menu markup.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => [$this, 'executeUpdateNavigation'],
				'permission_callback' => $permission,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => ['navigation_id'],
					'properties' => [
						'navigation_id' => [
							'type'        => 'integer',
							'description' => __('wp_navigation post ID from list-navigations or a wp:navigation block\'s "ref".', 'agent-mod'),
						],
						'operations'    => [
							'type'        => 'array',
							'description' => __('Ordered edit operations. Paths refer to the menu as it is after the previous operations.', 'agent-mod'),
							'items'       => [
								'type'       => 'object',
								'required'   => ['action'],
								'properties' => [
									'action'           => [
										'type' => 'string',
										'enum' => ['add', 'update', 'remove', 'move'],
									],
									'path'             => [
										'type'        => 'string',
										'description' => __('Item path from get-navigation (required for update, remove, move).', 'agent-mod'),
									],
									'parent_path'      => [
										'type'        => 'string',
										'description' => __('add/move: path of the item to nest under (a link becomes a submenu). Omit for the top level.', 'agent-mod'),
									],
									'position'         => [
										'type'        => 'integer',
										'description' => __('add/move: the item\'s final 0-based position among its siblings. Omit to append at the end.', 'agent-mod'),
									],
									'label'            => [
										'type'        => 'string',
										'description' => __('Link text (required for add).', 'agent-mod'),
									],
									'url'              => [
										'type'        => 'string',
										'description' => __('Link URL. Absolute, relative ("/contact") or an anchor ("#team"). May be omitted when id points to a post/page or term.', 'agent-mod'),
									],
									'kind'             => [
										'type'        => 'string',
										'enum'        => ['custom', 'post-type', 'taxonomy'],
										'description' => __('Link kind. Default "custom", or "post-type" when id is given.', 'agent-mod'),
									],
									'type'             => [
										'type'        => 'string',
										'description' => __('Linked object type, e.g. "page", "post", "category". Default derived from id.', 'agent-mod'),
									],
									'id'               => [
										'type'        => 'integer',
										'description' => __('Optional linked post/page ID (or term ID with kind "taxonomy"); keeps the link valid when the slug changes.', 'agent-mod'),
									],
									'opens_in_new_tab' => [
										'type' => 'boolean',
									],
								],
							],
						],
						'html'          => [
							'type'        => 'string',
							'description' => __('Optional alternative to operations: complete serialized block markup of the menu items (no wrapping wp:navigation block). Replaces the whole menu.', 'agent-mod'),
						],
						'title'         => [
							'type'        => 'string',
							'description' => __('Optional new menu title.', 'agent-mod'),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'       => ['type' => 'boolean'],
						'navigation_id' => ['type' => 'integer'],
						'title'         => ['type' => 'string'],
						'items'         => $itemsSchema,
						'error'         => ['type' => 'string'],
					],
				],
				'meta'                => [
					'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Execute callback for agent-mod/list-navigations.
	 *
	 * @param mixed $input Input data (optional 's').
	 *
	 * @return array<string, mixed>
	 * @since x.x.x
	 */
	public function executeListNavigations($input = null): array
	{
		$input = is_array($input) ? $input : [];

		$queryArgs = [
			'post_type'      => self::POST_TYPE,
			'post_status'    => ['publish', 'draft', 'private'],
			'posts_per_page' => self::MAX_MENUS,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		];

		if (! empty($input['s'])) {
			$queryArgs['s'] = sanitize_text_field((string) $input['s']);
		}

		$usage       = $this->collectNavigationUsage();
		$navigations = [];

		foreach (get_posts($queryArgs) as $post) {
			$navigations[] = [
				'navigation_id' => $post->ID,
				'title'         => $post->post_title,
				'status'        => $post->post_status,
				'item_count'    => count($this->parseItems($post->post_content)),
				'used_in'       => $usage[$post->ID] ?? [],
			];
		}

		return [
			'success'     => true,
			'navigations' => $navigations,
			'total'       => count($navigations),
		];
	}

	/**
	 * Execute callback for agent-mod/get-navigation.
	 *
	 * @param mixed $input Input data (expects 'navigation_id', optional 'include_html').
	 *
	 * @return array<string, mixed>
	 * @since x.x.x
	 */
	public function executeGetNavigation($input = null): array
	{
		$post = $this->resolveNavigation($input);

		if (is_wp_error($post)) {
			return ['success' => false, 'error' => $post->get_error_message()];
		}

		$result = [
			'success'       => true,
			'navigation_id' => $post->ID,
			'title'         => $post->post_title,
			'status'        => $post->post_status,
			'items'         => $this->describeItems($this->parseItems($post->post_content)),
		];

		if (! empty($input['include_html'])) {
			$result['html'] = $post->post_content;
		}

		return $result;
	}

	/**
	 * Execute callback for agent-mod/update-navigation.
	 *
	 * @param mixed $input Input data (expects 'navigation_id' plus 'operations' or 'html', optional 'title').
	 *
	 * @return array<string, mixed>
	 * @since x.x.x
	 */
	public function executeUpdateNavigation($input = null): array
	{
		$post = $this->resolveNavigation($input);

		if (is_wp_error($post)) {
			return ['success' => false, 'error' => $post->get_error_message()];
		}

		$hasOperations = ! empty($input['operations']);
		$hasHtml       = isset($input['html']) && '' !== trim((string) $input['html']);
		$hasTitle      = isset($input['title']) && '' !== trim((string) $input['title']);

		if ($hasOperations && $hasHtml) {
			return ['success' => false, 'error' => __('Pass either operations or html, not both.', 'agent-mod')];
		}

		if (! $hasOperations && ! $hasHtml && ! $hasTitle) {
			return ['success' => false, 'error' => __('Nothing to update: provide operations, html or title.', 'agent-mod')];
		}

		$items = null;

		if ($hasOperations) {
			if (! is_array($input['operations'])) {
				return ['success' => false, 'error' => __('operations must be a list of operation objects.', 'agent-mod')];
			}

			$items = $this->parseItems($post->post_content);

			foreach (array_values($input['operations']) as $index => $operation) {
				$items = $this->applyOperation($items, is_array($operation) ? $operation : []);

				if (is_wp_error($items)) {
					return [
						'success' => false,
						'error'   => sprintf(
							/* translators: 1: 0-based operation index, 2: error message. */
							__('Operation %1$d failed, nothing was saved: %2$s', 'agent-mod'),
							$index,
							$items->get_error_message()
						),
					];
				}
			}
		} elseif ($hasHtml) {
			$items = $this->parseHtmlInput((string) $input['html'], $input);

			if (is_wp_error($items)) {
				return ['success' => false, 'error' => $items->get_error_message()];
			}
		}

		$updateArgs = ['ID' => $post->ID];

		if (null !== $items) {
			$updateArgs['post_content'] = $this->serializeItems($items);
		}

		if ($hasTitle) {
			$updateArgs['post_title'] = sanitize_text_field((string) $input['title']);
		}

		// wp_update_post() runs wp_unslash() on the data; slash it so the JSON
		// escapes inside block attributes survive.
		$result = wp_update_post(wp_slash($updateArgs), true);

		if (is_wp_error($result)) {
			return ['success' => false, 'error' => $result->get_error_message()];
		}

		$updated = get_post($post->ID);

		return [
			'success'       => true,
			'navigation_id' => $post->ID,
			'title'         => $updated instanceof WP_Post ? $updated->post_title : $post->post_title,
			'items'         => $this->describeItems(
				$this->parseItems($updated instanceof WP_Post ? $updated->post_content : '')
			),
		];
	}

	/**
	 * Loads and authorizes the navigation post named by the input.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return WP_Post|WP_Error
	 * @since x.x.x
	 */
	private function resolveNavigation($input)
	{
		if (! is_array($input) || empty($input['navigation_id'])) {
			return new WP_Error('missing_id', __('navigation_id is required.', 'agent-mod'));
		}

		$id   = absint($input['navigation_id']);
		$post = get_post($id);

		if (! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type || 'trash' === $post->post_status) {
			return new WP_Error(
				'not_found',
				sprintf(
					/* translators: %d: post ID. */
					__('Navigation menu %d not found. Call agent-mod/list-navigations for valid IDs.', 'agent-mod'),
					$id
				)
			);
		}

		if (! current_user_can('edit_post', $id)) {
			return new WP_Error('forbidden', __('You are not allowed to edit this navigation menu.', 'agent-mod'));
		}

		return $post;
	}

	/**
	 * Maps navigation IDs to the templates / template parts that reference them.
	 *
	 * @return array<int, string[]> Navigation ID => list of "template-part:footer" style labels.
	 * @since x.x.x
	 */
	private function collectNavigationUsage(): array
	{
		$usage = [];

		if (! function_exists('get_block_templates')) {
			return $usage;
		}

		foreach (['wp_template_part' => 'template-part', 'wp_template' => 'template'] as $templateType => $label) {
			foreach ((array) get_block_templates([], $templateType) as $template) {
				$content = (string) ($template->content ?? '');

				if (false === strpos($content, 'wp:navigation')) {
					continue;
				}

				foreach ($this->findNavigationRefs(parse_blocks($content)) as $ref) {
					$usage[$ref][] = $label . ':' . $template->slug;
				}
			}
		}

		return array_map('array_values', array_map('array_unique', $usage));
	}

	/**
	 * Collects the "ref" IDs of every core/navigation block in a block tree.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 *
	 * @return int[]
	 * @since x.x.x
	 */
	private function findNavigationRefs(array $blocks): array
	{
		$refs = [];

		foreach ($blocks as $block) {
			if ('core/navigation' === ($block['blockName'] ?? null) && ! empty($block['attrs']['ref'])) {
				$refs[] = (int) $block['attrs']['ref'];
			}

			if (! empty($block['innerBlocks'])) {
				$refs = array_merge($refs, $this->findNavigationRefs($block['innerBlocks']));
			}
		}

		return $refs;
	}

	/**
	 * Parses menu markup into named blocks, dropping whitespace-only filler.
	 *
	 * @param string $content Serialized block markup.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since x.x.x
	 */
	private function parseItems(string $content): array
	{
		return array_values(array_filter(parse_blocks($content), static function ($block): bool {
			return ! empty($block['blockName']) || '' !== trim((string) ($block['innerHTML'] ?? ''));
		}));
	}

	/**
	 * Validates and parses the html alternative of update-navigation.
	 *
	 * Applies the same gates as the post/template write abilities.
	 *
	 * @param string               $html  Serialized block markup.
	 * @param array<string, mixed> $input The ability input.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 * @since x.x.x
	 */
	private function parseHtmlInput(string $html, array $input)
	{
		/** This filter is documented in includes/Services/AbilityRegistrarService.php */
		$html = apply_filters('agent_mod_resolve_blocks_html', $html, $input);

		if (is_wp_error($html)) {
			return $html;
		}

		$html = (string) $html;

		if (preg_match('#<!--\s+wp:navigation[\s{/]#', $html) && ! preg_match('#<!--\s+wp:navigation-#', $html)) {
			return new WP_Error('wrapped_markup', __('Pass only the menu items, without a wrapping wp:navigation block.', 'agent-mod'));
		}

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
		$stray  = $this->blockMarkupValidator->strayContentExcerpts($blocks);

		if (! empty($stray)) {
			return new WP_Error(
				'stray_content',
				sprintf(
					/* translators: %s: excerpt of the stray content. */
					__('Content exists outside block delimiters and would be dropped: "%s". Wrap it in proper block markup and retry.', 'agent-mod'),
					mb_substr($stray[0], 0, 80)
				)
			);
		}

		$tagIssues = $this->blockMarkupValidator->tagBalanceIssues($html);

		if (! empty($tagIssues)) {
			return new WP_Error('unbalanced_block_markup', implode(' ', $tagIssues));
		}

		$items = $this->parseItems($html);

		if (empty($items)) {
			return new WP_Error('parse_failed', __('No valid blocks found in the provided block markup.', 'agent-mod'));
		}

		return $items;
	}

	/**
	 * Serializes the top-level item blocks, one per line.
	 *
	 * @param array<int, array<string, mixed>> $items Blocks.
	 *
	 * @return string
	 * @since x.x.x
	 */
	private function serializeItems(array $items): string
	{
		return implode("\n\n", array_map('serialize_block', $items));
	}

	/**
	 * Builds the agent-facing description of a block tree.
	 *
	 * @param array<int, array<string, mixed>> $blocks Blocks.
	 * @param string                           $prefix Path of the parent item.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since x.x.x
	 */
	private function describeItems(array $blocks, string $prefix = ''): array
	{
		$items = [];

		foreach (array_values($blocks) as $index => $block) {
			$path  = '' === $prefix ? (string) $index : $prefix . '.' . $index;
			$name  = (string) ($block['blockName'] ?? '');
			$attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];

			$item = [
				'path'  => $path,
				'block' => '' === $name ? 'html' : preg_replace('#^core/#', '', $name),
			];

			if (in_array($name, self::LINK_BLOCKS, true)) {
				$item['label']            = (string) ($attrs['label'] ?? '');
				$item['url']              = (string) ($attrs['url'] ?? '');
				$item['kind']             = (string) ($attrs['kind'] ?? '');
				$item['type']             = (string) ($attrs['type'] ?? '');
				$item['id']               = isset($attrs['id']) ? (int) $attrs['id'] : null;
				$item['opens_in_new_tab'] = ! empty($attrs['opensInNewTab']);
			} elseif (! empty($attrs)) {
				$item['attrs'] = $attrs;
			}

			if (! empty($block['innerBlocks'])) {
				$item['children'] = $this->describeItems($block['innerBlocks'], $path);
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Applies one edit operation to a copy of the item tree.
	 *
	 * @param array<int, array<string, mixed>> $items     Current top-level blocks.
	 * @param array<string, mixed>             $operation Operation input.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 * @since x.x.x
	 */
	private function applyOperation(array $items, array $operation)
	{
		$action = (string) ($operation['action'] ?? '');

		switch ($action) {
			case 'add':
				$label = $this->sanitizeLabel($operation['label'] ?? '');
				if ('' === $label) {
					return new WP_Error('missing_label', __('"add" needs a non-empty label.', 'agent-mod'));
				}

				$attrs = $this->buildLinkAttributes($operation, []);
				if (is_wp_error($attrs)) {
					return $attrs;
				}

				if (empty($attrs['url'])) {
					return new WP_Error('missing_url', __('"add" needs a url, or an id that resolves to one.', 'agent-mod'));
				}

				$block = [
					'blockName'    => 'core/navigation-link',
					'attrs'        => $attrs,
					'innerBlocks'  => [],
					'innerHTML'    => '',
					'innerContent' => [],
				];

				return $this->insertBlock($items, $this->parsePath($operation['parent_path'] ?? ''), $operation['position'] ?? null, $block);

			case 'update':
				$path = $this->requirePath($items, $operation);
				if (is_wp_error($path)) {
					return $path;
				}

				$block = $this->getBlock($items, $path);
				if (! in_array($block['blockName'] ?? '', self::LINK_BLOCKS, true)) {
					return new WP_Error(
						'not_editable',
						sprintf(
							/* translators: 1: item path, 2: block name. */
							__('Item %1$s is a "%2$s" block; only navigation-link and navigation-submenu items can be updated. Remove it and add a link instead.', 'agent-mod'),
							implode('.', $path),
							(string) ($block['blockName'] ?? 'html')
						)
					);
				}

				$attrs = $this->buildLinkAttributes($operation, is_array($block['attrs'] ?? null) ? $block['attrs'] : []);
				if (is_wp_error($attrs)) {
					return $attrs;
				}

				$block['attrs'] = $attrs;

				return $this->setBlock($items, $path, $block);

			case 'remove':
				$path = $this->requirePath($items, $operation);
				if (is_wp_error($path)) {
					return $path;
				}

				return $this->removeBlock($items, $path);

			case 'move':
				$path = $this->requirePath($items, $operation);
				if (is_wp_error($path)) {
					return $path;
				}

				$parentPath = $this->parsePath($operation['parent_path'] ?? '');
				if (is_wp_error($parentPath)) {
					return $parentPath;
				}

				if (count($parentPath) >= count($path) && array_slice($parentPath, 0, count($path)) === $path) {
					return new WP_Error('invalid_move', __('An item cannot be moved inside itself.', 'agent-mod'));
				}

				$block    = $this->getBlock($items, $path);
				$items    = $this->removeBlock($items, $path);
				// parent_path refers to the tree before the move; removing the
				// source shifts its later siblings (and their subtrees) one slot
				// up. position is the item's final index and needs no adjustment.
				$depth = count($path) - 1;
				if (
					count($parentPath) > $depth
					&& array_slice($parentPath, 0, $depth) === array_slice($path, 0, $depth)
					&& $parentPath[$depth] > $path[$depth]
				) {
					$parentPath[$depth]--;
				}

				return $this->insertBlock($items, $parentPath, $operation['position'] ?? null, $block);

			default:
				return new WP_Error('invalid_action', __('action must be one of add, update, remove, move.', 'agent-mod'));
		}
	}

	/**
	 * Builds navigation-link attributes from an operation, merged over existing ones.
	 *
	 * @param array<string, mixed> $operation Operation input.
	 * @param array<string, mixed> $attrs     Existing attributes.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since x.x.x
	 */
	private function buildLinkAttributes(array $operation, array $attrs)
	{
		if (array_key_exists('label', $operation)) {
			$label = $this->sanitizeLabel($operation['label']);
			if ('' === $label) {
				return new WP_Error('missing_label', __('label cannot be empty.', 'agent-mod'));
			}
			$attrs['label'] = $label;
		}

		$id   = isset($operation['id']) ? absint($operation['id']) : 0;
		$kind = isset($operation['kind']) ? sanitize_key((string) $operation['kind']) : '';

		if ('' !== $kind && ! in_array($kind, ['custom', 'post-type', 'taxonomy'], true)) {
			return new WP_Error('invalid_kind', __('kind must be custom, post-type or taxonomy.', 'agent-mod'));
		}

		if ($id > 0) {
			$kind = '' !== $kind ? $kind : 'post-type';

			if ('taxonomy' === $kind) {
				$term = get_term($id);
				if (! $term || is_wp_error($term)) {
					/* translators: %d: term ID. */
					return new WP_Error('invalid_id', sprintf(__('Term %d not found.', 'agent-mod'), $id));
				}
				$type    = 'post_tag' === $term->taxonomy ? 'tag' : $term->taxonomy;
				$linkUrl = get_term_link($term);
			} elseif ('post-type' === $kind) {
				$linked = get_post($id);
				if (! $linked instanceof WP_Post) {
					/* translators: %d: post ID. */
					return new WP_Error('invalid_id', sprintf(__('Post %d not found.', 'agent-mod'), $id));
				}
				$type    = $linked->post_type;
				$linkUrl = get_permalink($linked);
			} else {
				$type    = 'custom';
				$linkUrl = '';
			}

			$attrs['id']   = $id;
			$attrs['kind'] = $kind;
			$attrs['type'] = isset($operation['type']) ? sanitize_key((string) $operation['type']) : $type;

			if (! isset($operation['url']) && is_string($linkUrl) && '' !== $linkUrl) {
				$attrs['url'] = $linkUrl;
			}
		} elseif ('' !== $kind) {
			$attrs['kind'] = $kind;
			if (isset($operation['type'])) {
				$attrs['type'] = sanitize_key((string) $operation['type']);
			}
		} elseif (isset($operation['url'])) {
			$attrs['kind'] = 'custom';
		}

		if (isset($operation['url'])) {
			$url = esc_url_raw(trim((string) $operation['url']));
			if ('' === $url) {
				return new WP_Error('invalid_url', __('url is not a valid URL.', 'agent-mod'));
			}
			$attrs['url'] = $url;

			// A hand-typed URL no longer points at the previously linked object.
			if (0 === $id && 'custom' === ($attrs['kind'] ?? '')) {
				unset($attrs['id']);
				$attrs['type'] = 'custom';
			}
		}

		if (array_key_exists('opens_in_new_tab', $operation)) {
			if ($operation['opens_in_new_tab']) {
				$attrs['opensInNewTab'] = true;
			} else {
				unset($attrs['opensInNewTab']);
			}
		}

		return $attrs;
	}

	/**
	 * Sanitizes a link label (inline HTML such as <strong> is allowed).
	 *
	 * @param mixed $label Raw label.
	 *
	 * @return string
	 * @since x.x.x
	 */
	private function sanitizeLabel($label): string
	{
		return trim(wp_kses_post((string) $label));
	}

	/**
	 * Parses a dotted item path ("2.1") into indexes.
	 *
	 * @param mixed $path Raw path; '' is the top level.
	 *
	 * @return int[]|WP_Error
	 * @since x.x.x
	 */
	private function parsePath($path)
	{
		$path = trim((string) $path);

		if ('' === $path) {
			return [];
		}

		if (! preg_match('/^\d+(\.\d+)*$/', $path)) {
			/* translators: %s: item path. */
			return new WP_Error('invalid_path', sprintf(__('Invalid path "%s". Use the paths returned by get-navigation, e.g. "0" or "2.1".', 'agent-mod'), $path));
		}

		return array_map('intval', explode('.', $path));
	}

	/**
	 * Parses the operation's required path and checks the item exists.
	 *
	 * @param array<int, array<string, mixed>> $items     Current blocks.
	 * @param array<string, mixed>             $operation Operation input.
	 *
	 * @return int[]|WP_Error
	 * @since x.x.x
	 */
	private function requirePath(array $items, array $operation)
	{
		if (! isset($operation['path']) || '' === trim((string) $operation['path'])) {
			return new WP_Error('missing_path', __('This action needs the item path.', 'agent-mod'));
		}

		$path = $this->parsePath($operation['path']);

		if (is_wp_error($path)) {
			return $path;
		}

		if (null === $this->getBlock($items, $path)) {
			return $this->pathNotFound($path);
		}

		return $path;
	}

	/**
	 * Builds the error for a path that addresses no item.
	 *
	 * @param int[] $path Item path.
	 *
	 * @return WP_Error
	 * @since x.x.x
	 */
	private function pathNotFound(array $path): WP_Error
	{
		/* translators: %s: item path. */
		return new WP_Error('path_not_found', sprintf(__('No menu item at path "%s". Re-read the paths from get-navigation (earlier operations shift them).', 'agent-mod'), implode('.', $path)));
	}

	/**
	 * Returns the block at a path, or null.
	 *
	 * @param array<int, array<string, mixed>> $items Blocks.
	 * @param int[]                            $path  Item path.
	 *
	 * @return array<string, mixed>|null
	 * @since x.x.x
	 */
	private function getBlock(array $items, array $path): ?array
	{
		$index = array_shift($path);

		if (null === $index || ! isset($items[$index])) {
			return null;
		}

		if (empty($path)) {
			return $items[$index];
		}

		return $this->getBlock($items[$index]['innerBlocks'] ?? [], $path);
	}

	/**
	 * Replaces the block at an existing path.
	 *
	 * @param array<int, array<string, mixed>> $items Blocks.
	 * @param int[]                            $path  Item path.
	 * @param array<string, mixed>             $block Replacement block.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since x.x.x
	 */
	private function setBlock(array $items, array $path, array $block): array
	{
		$index = array_shift($path);

		if (empty($path)) {
			$items[$index] = $block;
			return $items;
		}

		$parent                = $items[$index];
		$parent['innerBlocks'] = $this->setBlock($parent['innerBlocks'], $path, $block);
		$items[$index]         = $parent;

		return $items;
	}

	/**
	 * Removes the block at an existing path; an emptied submenu becomes a plain link.
	 *
	 * @param array<int, array<string, mixed>> $items Blocks.
	 * @param int[]                            $path  Item path.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since x.x.x
	 */
	private function removeBlock(array $items, array $path): array
	{
		$index = array_shift($path);

		if (empty($path)) {
			array_splice($items, $index, 1);
			return $items;
		}

		$parent                = $items[$index];
		$parent['innerBlocks'] = $this->removeBlock($parent['innerBlocks'], $path);
		$items[$index]         = $this->normalizeContainer($parent);

		return $items;
	}

	/**
	 * Inserts a block under a parent path at a position (append when null).
	 *
	 * @param array<int, array<string, mixed>> $items      Blocks.
	 * @param int[]|WP_Error                   $parentPath Parent item path ([] = top level).
	 * @param mixed                            $position   0-based position or null.
	 * @param array<string, mixed>             $block      Block to insert.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 * @since x.x.x
	 */
	private function insertBlock(array $items, $parentPath, $position, array $block)
	{
		if (is_wp_error($parentPath)) {
			return $parentPath;
		}

		if (empty($parentPath)) {
			return $this->spliceAt($items, $position, $block);
		}

		$parent = $this->getBlock($items, $parentPath);

		if (null === $parent) {
			return $this->pathNotFound($parentPath);
		}

		if (! in_array($parent['blockName'] ?? '', self::LINK_BLOCKS, true)) {
			return new WP_Error(
				'invalid_parent',
				sprintf(
					/* translators: %s: item path. */
					__('Item %s cannot hold children; only links and submenus can.', 'agent-mod'),
					implode('.', $parentPath)
				)
			);
		}

		$children = $this->spliceAt($parent['innerBlocks'] ?? [], $position, $block);

		if (is_wp_error($children)) {
			return $children;
		}

		$parent['innerBlocks'] = $children;

		return $this->setBlock($items, $parentPath, $this->normalizeContainer($parent));
	}

	/**
	 * Inserts a block into a sibling list.
	 *
	 * @param array<int, array<string, mixed>> $siblings Sibling blocks.
	 * @param mixed                            $position 0-based position or null to append.
	 * @param array<string, mixed>             $block    Block to insert.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 * @since x.x.x
	 */
	private function spliceAt(array $siblings, $position, array $block)
	{
		$siblings = array_values($siblings);
		$count    = count($siblings);

		if (null === $position || '' === $position) {
			$position = $count;
		}

		$position = (int) $position;

		if ($position < 0 || $position > $count) {
			/* translators: 1: requested position, 2: number of siblings. */
			return new WP_Error('invalid_position', sprintf(__('position %1$d is out of range (0–%2$d).', 'agent-mod'), $position, $count));
		}

		array_splice($siblings, $position, 0, [$block]);

		return $siblings;
	}

	/**
	 * Keeps a link/submenu block consistent with its children.
	 *
	 * A link with children must be a navigation-submenu and a submenu without
	 * children reverts to a navigation-link. innerContent gets one null
	 * placeholder per child so serialize_block() emits every inner block.
	 *
	 * @param array<string, mixed> $block Link or submenu block.
	 *
	 * @return array<string, mixed>
	 * @since x.x.x
	 */
	private function normalizeContainer(array $block): array
	{
		if (! in_array($block['blockName'] ?? '', self::LINK_BLOCKS, true)) {
			return $block;
		}

		$children = array_values($block['innerBlocks'] ?? []);

		$block['blockName']    = empty($children) ? 'core/navigation-link' : 'core/navigation-submenu';
		$block['innerBlocks']  = $children;
		$block['innerHTML']    = '';
		$block['innerContent'] = array_fill(0, count($children), null);

		return $block;
	}
}
