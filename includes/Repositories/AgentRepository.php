<?php

/**
 * Agent repository.
 *
 * Provides read access to agentmod_agent posts and converts them to the wire
 * format consumed by AgentConfig::fromArray().
 *
 * @package AgentMod
 * @subpackage Repositories
 * @since 1.0.0
 */

namespace AgentMod\Repositories;

use WP_Post;
use WP_Query;

defined('ABSPATH') || exit;

class AgentRepository
{
	/**
	 * Returns all published agents as config arrays.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.0.0
	 */
	public function all(): array
	{
		$query = new WP_Query(
			[
				'post_type'      => 'agentmod_agent',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'no_found_rows'  => true,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);

		return array_map([$this, 'postToConfig'], $query->posts);
	}

	/**
	 * Finds a single agent by post ID.
	 *
	 * @param int $id Agent post ID.
	 *
	 * @return array<string, mixed>|null
	 * @since 1.0.0
	 */
	public function find(int $id): ?array
	{
		$post = get_post($id);

		if (! $post instanceof WP_Post || 'agentmod_agent' !== $post->post_type) {
			return null;
		}

		return $this->postToConfig($post);
	}

	/**
	 * Converts a WP_Post to an agent config wire-format array.
	 *
	 * @param WP_Post $post The post object.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	private function postToConfig(WP_Post $post): array
	{
		$meta = get_post_meta($post->ID);

		return [
			'id'                     => $post->ID,
			'name'                   => $post->post_title,
			'description'            => $this->metaString($meta, 'description'),
			'agent_type'             => $this->metaString($meta, 'agent_type', 'generic'),
			'system_prompt'          => $this->metaString($meta, 'system_prompt'),
			'role'                   => $this->metaString($meta, 'role'),
			'goal'                   => $this->metaString($meta, 'goal'),
			'personality'            => $this->metaJson($meta, 'personality_traits'),
			'abilitySource'          => $this->metaString($meta, 'ability_source', 'all'),
			'allowedAbilities'       => $this->metaJson($meta, 'allowed_abilities'),
			'maxToolCalls'           => (int) $this->metaString($meta, 'max_tool_calls', '10'),
			'provider'               => '',
			'model'                  => null,
			'autoIncludeSiteContext' => true,
		];
	}

	/**
	 * Retrieves a single string meta value.
	 *
	 * @param array<string, array<int, mixed>> $meta    All post meta.
	 * @param string                           $key     Meta key.
	 * @param string                           $default Fallback value.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function metaString(array $meta, string $key, string $default = ''): string
	{
		return isset($meta[$key][0]) ? (string) $meta[$key][0] : $default;
	}

	/**
	 * Retrieves a JSON-encoded meta value as a plain array.
	 *
	 * @param array<string, array<int, mixed>> $meta All post meta.
	 * @param string                           $key  Meta key.
	 *
	 * @return array<int|string, mixed>
	 * @since 1.0.0
	 */
	private function metaJson(array $meta, string $key): array
	{
		$raw     = isset($meta[$key][0]) ? (string) $meta[$key][0] : '[]';
		$decoded = json_decode($raw, true);

		return is_array($decoded) ? $decoded : [];
	}
}
