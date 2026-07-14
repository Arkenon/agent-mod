<?php

/**
 * Progress store.
 *
 * Persists live tool-call progress for an in-flight chat request so the
 * frontend can poll it from a separate REST request while the blocking chat
 * request is still running. State is stored in a short-lived WordPress
 * transient keyed by a client-generated UUID.
 *
 * Requires a shared persistence layer (database or shared object cache) so a
 * write from the chat request is visible to the polling request. Per-process
 * caches that are not shared across PHP workers will not propagate progress;
 * in that case polling degrades gracefully to "unknown". The progress REST
 * endpoint sends no-store cache headers so page caches never serve stale state.
 *
 * @package AgentMod
 * @subpackage Services\AI
 * @since 1.1.0
 */

namespace AgentMod\Services\AI;

defined('ABSPATH') || exit;

class ProgressStore
{
	/**
	 * Transient key prefix.
	 *
	 * @var string
	 * @since 1.1.0
	 */
	private const PREFIX = 'agent_mod_progress_';

	/**
	 * Time-to-live in seconds (5 minutes).
	 *
	 * @var int
	 * @since 1.1.0
	 */
	private const TTL = 300;

	/**
	 * Saves the progress state for a request.
	 *
	 * @param string               $requestId The client-generated UUID.
	 * @param array<string, mixed> $state     Serializable state array.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function save(string $requestId, array $state): void
	{
		$state['updatedAt'] = time();
		set_transient(self::PREFIX . $requestId, $state, self::TTL);
	}

	/**
	 * Retrieves the progress state for a request.
	 *
	 * @param string $requestId The client-generated UUID.
	 *
	 * @return array<string, mixed>|null Null when not found or expired.
	 * @since 1.1.0
	 */
	public function load(string $requestId): ?array
	{
		$data = get_transient(self::PREFIX . $requestId);

		return is_array($data) ? $data : null;
	}

	/**
	 * Deletes the progress state for a request.
	 *
	 * @param string $requestId The client-generated UUID.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function delete(string $requestId): void
	{
		delete_transient(self::PREFIX . $requestId);
	}
}
