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
	 * Stop-flag transient key prefix.
	 *
	 * @var string
	 * @since 1.2.0
	 */
	private const STOP_PREFIX = 'agent_mod_stop_';

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

	/**
	 * Flags an in-flight request as stop-requested.
	 *
	 * Written by the chat-stop REST endpoint from a separate PHP request; the
	 * blocking chat request polls the flag between loop iterations. Uses the
	 * same shared persistence caveats as the progress state above.
	 *
	 * @param string $requestId The client-generated UUID.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function requestStop(string $requestId): void
	{
		set_transient(self::STOP_PREFIX . $requestId, time(), self::TTL);
	}

	/**
	 * Checks whether a stop has been requested for a request.
	 *
	 * @param string $requestId The client-generated UUID.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	public function isStopRequested(string $requestId): bool
	{
		if ('' === $requestId) {
			return false;
		}

		// Transients can be served from a per-request runtime cache; the flag
		// is written by a different PHP request while this one is running, so
		// every cached trace must be dropped before each check. Without an
		// external object cache a miss also lands in the "notoptions" cache,
		// which would hide the flag for the rest of this request.
		$key = self::STOP_PREFIX . $requestId;
		wp_cache_delete($key, 'transient');
		wp_cache_delete($key, 'transient_timeout');
		wp_cache_delete('_transient_' . $key, 'options');
		wp_cache_delete('_transient_timeout_' . $key, 'options');
		wp_cache_delete('notoptions', 'options');
		wp_cache_delete('alloptions', 'options');

		return false !== get_transient(self::STOP_PREFIX . $requestId);
	}

	/**
	 * Clears the stop flag for a request.
	 *
	 * @param string $requestId The client-generated UUID.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function clearStop(string $requestId): void
	{
		delete_transient(self::STOP_PREFIX . $requestId);
	}
}
