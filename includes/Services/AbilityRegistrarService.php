<?php

/**
 * Ability registrar service.
 *
 * Registers the AgentMod ability category plus a minimal set of demo abilities
 * used to exercise the orchestrator's tool-calling loop. These are intentionally
 * read-only; write abilities are introduced in a later step.
 *
 * @package AgentMod
 * @subpackage Services
 * @since 1.0.0
 */

namespace AgentMod\Services;

use AgentMod\Common\Constants;
use WP_Error;

defined('ABSPATH') || exit;

class AbilityRegistrarService
{
	/**
	 * Ability category slug.
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
	 * Registers the AgentMod ability category.
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
	 * Registers the demo abilities.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function registerAbilities(): void
	{
		if (! function_exists('wp_register_ability')) {
			return;
		}

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
						'id'      => ['type' => 'integer'],
						'title'   => ['type' => 'string'],
						'edit_url'=> ['type' => 'string'],
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
							'maximum'     => Constants::AI_MAX_SEARCH_RESULTS,
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
	}

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
	 * @return array<int, array<string, mixed>>|WP_Error
	 * @since 1.0.0
	 */
	public function executeListRecentPosts($input = null)
	{
		$count = 5;
		if (is_array($input) && isset($input['count'])) {
			$count = (int) $input['count'];
		}
		$count = max(1, min(Constants::AI_MAX_SEARCH_RESULTS, $count));

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
}