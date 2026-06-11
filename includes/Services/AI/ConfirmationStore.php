<?php

/**
 * Confirmation store.
 *
 * Persists a pending write-action execution state between the initial AI
 * response (which triggers the confirmation modal) and the user's subsequent
 * approval request. State is stored in a short-lived WordPress transient keyed
 * by a UUID token so no database table is required.
 *
 * @package AgentMod
 * @subpackage Services\AI
 * @since 1.0.0
 */

namespace AgentMod\Services\AI;

defined('ABSPATH') || exit;

class ConfirmationStore
{
	/**
	 * Transient key prefix.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private const PREFIX = 'agent_mod_confirm_';

	/**
	 * Time-to-live in seconds (15 minutes).
	 *
	 * @var int
	 * @since 1.0.0
	 */
	private const TTL = 900;

	/**
	 * Saves a pending confirmation state.
	 *
	 * @param string               $token The UUID token.
	 * @param array<string, mixed> $state Serializable state array.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function save(string $token, array $state): void
	{
		set_transient(self::PREFIX . $token, $state, self::TTL);
	}

	/**
	 * Retrieves a pending state without consuming it.
	 *
	 * @param string $token The UUID token.
	 *
	 * @return array<string, mixed>|null Null when not found or expired.
	 * @since 1.0.0
	 */
	public function load(string $token): ?array
	{
		$data = get_transient(self::PREFIX . $token);

		return is_array($data) ? $data : null;
	}

	/**
	 * Retrieves and deletes a pending state in one operation.
	 *
	 * @param string $token The UUID token.
	 *
	 * @return array<string, mixed>|null Null when not found or expired.
	 * @since 1.0.0
	 */
	public function consume(string $token): ?array
	{
		$data = $this->load($token);

		if (null !== $data) {
			delete_transient(self::PREFIX . $token);
		}

		return $data;
	}
}
