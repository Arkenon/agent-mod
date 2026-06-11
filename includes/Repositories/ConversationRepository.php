<?php

/**
 * Conversation repository.
 *
 * Provides CRUD operations for agentmod_chatmemo posts. Message history is
 * stored as a JSON-encoded string in post meta so no custom tables are needed.
 *
 * @package AgentMod
 * @subpackage Repositories
 * @since 1.0.0
 */

namespace AgentMod\Repositories;

defined('ABSPATH') || exit;

class ConversationRepository
{
	/**
	 * Creates a new conversation post and returns its ID.
	 *
	 * @param int    $agentId Agent post ID (0 when no specific agent is selected).
	 * @param string $source  Origin of the conversation.
	 *
	 * @return int Post ID, or 0 on failure.
	 * @since 1.0.0
	 */
	public function create(int $agentId = 0, string $source = 'admin_chat'): int
	{
		$now    = current_time('c', true);
		$postId = wp_insert_post(
			[
				'post_type'   => 'agentmod_chatmemo',
				'post_status' => 'publish',
				'post_title'  => __('Conversation', 'agent-mod') . ' ' . $now,
			]
		);

		if (is_wp_error($postId) || 0 === $postId) {
			return 0;
		}

		$id = (int) $postId;

		update_post_meta($id, 'agent_id', $agentId);
		update_post_meta($id, 'session_id', wp_generate_uuid4());
		update_post_meta($id, 'source', sanitize_key($source) ?: 'admin_chat');
		update_post_meta($id, 'messages_history', '[]');
		update_post_meta($id, 'started_at', $now);
		update_post_meta($id, 'last_message_at', $now);

		return $id;
	}

	/**
	 * Loads the stored message history for a conversation.
	 *
	 * @param int $conversationId Conversation post ID.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.0.0
	 */
	public function loadHistory(int $conversationId): array
	{
		$raw     = (string) get_post_meta($conversationId, 'messages_history', true);
		$decoded = json_decode('' !== $raw ? $raw : '[]', true);

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Appends new messages to the stored history.
	 *
	 * @param int                              $conversationId Conversation post ID.
	 * @param array<int, array<string, mixed>> $messages       New turns to append.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function appendMessages(int $conversationId, array $messages): void
	{
		if (empty($messages)) {
			return;
		}

		$existing = $this->loadHistory($conversationId);
		$merged   = array_merge($existing, $messages);

		update_post_meta($conversationId, 'messages_history', wp_json_encode($merged));
		$this->touch($conversationId);
	}

	/**
	 * Updates the last_message_at timestamp.
	 *
	 * @param int $conversationId Conversation post ID.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function touch(int $conversationId): void
	{
		update_post_meta($conversationId, 'last_message_at', current_time('c', true));
	}
}
