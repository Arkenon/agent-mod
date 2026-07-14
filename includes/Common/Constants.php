<?php

/**
 * Additional Plugin Constants
 * (Base constant defined in the main plugin php file)
 * @package AgentMod
 * @subpackage Common
 * @since 1.0.0
 */

namespace AgentMod\Common;

defined('ABSPATH') || exit;

class Constants
{
	public const NAME = 'agent_mod';
	public const INCLUDES_PATH = AGENT_MOD_PATH . 'includes/';
	public const INCLUDES_URL = AGENT_MOD_URL . '/includes/';
	public const AUTHOR = 'Kadim Gültekin';
	public const AUTHOR_URL = 'https://kadimgultekin.com/';
	public const PLUGIN_URL = 'https://kadimgultekin.com/';
	public const EMAIL = 'info@kadimgultekin.com';
	public const PHP_VERSION = '7.4';
	public const WP_VERSION = '7.0';

	/**
	 * Default AI provider id.
	 *
	 * An empty string means "auto": the orchestrator lets the WordPress AI Client
	 * pick whichever configured provider (e.g. Google, OpenAI, Anthropic) can
	 * handle the request, instead of forcing a single provider.
	 *
	 * @since 1.0.0
	 */
	public const AI_PROVIDER_DEFAULT = '';

	/**
	 * REST namespace for the plugin.
	 *
	 * @since 1.0.0
	 */
	public const REST_NAMESPACE = 'agent-mod/v1';

	/**
	 * Default maximum number of tool-calling iterations.
	 *
	 * @since 1.0.0
	 */
	public const AI_MAX_TOOL_CALLS = 10;

	/**
	 * Maximum results per ability call that returns a list of posts or items.
	 *
	 * @since 1.0.0
	 */
	public const AI_MAX_SEARCH_RESULTS = 20;

	/**
	 * Maximum posts whose full body content an agent may read in a single turn.
	 *
	 * @since 1.0.0
	 */
	public const AI_MAX_FULL_CONTENT_POSTS = 5;

	/**
	 * Maximum decoded size (in bytes) allowed for a single chat attachment.
	 *
	 * @since 1.0.0
	 */
	public const AI_ATTACHMENT_MAX_BYTES = 5242880; // 5 MB.

	/**
	 * Maximum number of attachments allowed per chat turn.
	 *
	 * @since 1.0.0
	 */
	public const AI_ATTACHMENT_MAX_COUNT = 5;

	/**
	 * MIME types accepted as chat attachments.
	 *
	 * Provider-agnostic allow-list. Whether a given provider can actually consume
	 * a type (e.g. vision for images) is the provider's concern; this only guards
	 * what AgentMod is willing to forward.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	public const AI_ATTACHMENT_MIME_TYPES = [
		'image/png',
		'image/jpeg',
		'image/gif',
		'image/webp',
		'application/pdf',
		'text/plain',
		'text/markdown',
		'text/csv',
	];

	// -------------------------------------------------------------------------
	// Filtered getters — use these instead of the raw constants so that
	// agent-mod-pro (or any third party) can override values via hooks.
	// -------------------------------------------------------------------------

	/**
	 * Returns the canonical default base system prompt.
	 *
	 * These core behaviour directives ship as the default value of the
	 * "Base System Prompt" setting so site owners can fully manage them.
	 *
	 * @return string
	 * @since 1.1.0
	 */
	public static function aiDefaultSystemPrompt(): string
	{
		$directives = [
			__(
				'Never directly execute destructive operations (delete, trash, erase, or remove content or users). Instead, list the affected items and ask the user for explicit confirmation before any such action is taken. If no relevant tool exists to list them, describe what would be affected and request confirmation.',
				'agent-mod'
			),
			__(
				'When a user request is ambiguous or missing required details, ask one short clarifying question before calling any tools. Do not assume intent.',
				'agent-mod'
			),
			__(
				'When a tool can answer the user accurately, call it before responding. Base your answers on tool results rather than guessing.',
				'agent-mod'
			),
		];

		return implode("\n\n", $directives);
	}

	/**
	 * Returns the effective base system prompt, after applying the
	 * `agent_mod_base_system_prompt` filter.
	 *
	 * The settings page bridges the saved "Base System Prompt" value through
	 * this filter, so the user-managed text replaces the default entirely.
	 *
	 * @return string
	 * @since 1.1.0
	 */
	public static function aiBaseSystemPrompt(): string
	{
		return (string) apply_filters('agent_mod_base_system_prompt', self::aiDefaultSystemPrompt());
	}

	/**
	 * Returns the effective maximum tool-call iterations, after applying the
	 * `agent_mod_max_tool_calls` filter.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	public static function aiMaxToolCalls(): int
	{
		return (int) apply_filters('agent_mod_max_tool_calls', self::AI_MAX_TOOL_CALLS);
	}

	/**
	 * Returns the effective maximum search results, after applying the
	 * `agent_mod_max_search_results` filter.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	public static function aiMaxSearchResults(): int
	{
		return (int) apply_filters('agent_mod_max_search_results', self::AI_MAX_SEARCH_RESULTS);
	}

	/**
	 * Returns the effective maximum full-content posts, after applying the
	 * `agent_mod_max_full_content_posts` filter.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	public static function aiMaxFullContentPosts(): int
	{
		return (int) apply_filters('agent_mod_max_full_content_posts', self::AI_MAX_FULL_CONTENT_POSTS);
	}

	/**
	 * Returns the effective maximum attachment size in bytes, after applying the
	 * `agent_mod_attachment_max_bytes` filter.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	public static function aiAttachmentMaxBytes(): int
	{
		return (int) apply_filters('agent_mod_attachment_max_bytes', self::AI_ATTACHMENT_MAX_BYTES);
	}

	/**
	 * Returns the effective maximum attachment count per turn, after applying the
	 * `agent_mod_attachment_max_count` filter.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	public static function aiAttachmentMaxCount(): int
	{
		return (int) apply_filters('agent_mod_attachment_max_count', self::AI_ATTACHMENT_MAX_COUNT);
	}

	/**
	 * Returns the effective list of allowed attachment MIME types, after applying
	 * the `agent_mod_attachment_mime_types` filter.
	 *
	 * @return string[]
	 * @since 1.0.0
	 */
	public static function aiAttachmentMimeTypes(): array
	{
		return (array) apply_filters('agent_mod_attachment_mime_types', self::AI_ATTACHMENT_MIME_TYPES);
	}
}
