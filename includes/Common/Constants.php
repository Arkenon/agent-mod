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
	public const AI_MAX_TOOL_CALLS = 30;

	/**
	 * Maximum results per ability call that returns a list of posts or items.
	 *
	 * @since 1.0.0
	 */
	public const AI_MAX_SEARCH_RESULTS = 20;

	/**
	 * Category of AgentMod's system abilities: the tools that are part of how
	 * the agent works (ask-user, present-plan, request-approval,
	 * find-abilities) rather than site capabilities. AgentMod alone decides
	 * when they are offered, so they are never part of an ability selection.
	 *
	 * @since 1.3.4
	 */
	public const SYSTEM_ABILITY_CATEGORY = 'agent-mod-system';

	/**
	 * Default tool loading strategy: 'dynamic' sends a small tool set and lets
	 * the agent load the rest through agent-mod/find-abilities; 'all' sends
	 * every allowed ability on every request.
	 *
	 * @since 1.3.4
	 */
	public const AI_TOOL_LOADING = 'dynamic';

	/**
	 * Allowed-ability count up to which dynamic loading still sends every
	 * ability: a small set costs less than the extra find round trip.
	 * Filterable via `agent_mod_dynamic_tools_threshold`.
	 *
	 * @since 1.3.4
	 */
	public const AI_DYNAMIC_TOOLS_THRESHOLD = 15;

	/**
	 * Most abilities one agent-mod/find-abilities call returns (and loads).
	 *
	 * @since 1.3.4
	 */
	public const AI_FIND_ABILITIES_LIMIT = 8;

	/**
	 * HTTP timeout (seconds) for requests to AI provider APIs.
	 *
	 * WordPress defaults to a 30s cURL timeout, which long generations
	 * (large prompts, vision inputs) regularly exceed. Applied only to
	 * provider hosts while a generation is in flight; filterable via
	 * `agent_mod_ai_http_timeout`.
	 *
	 * @since 1.1.5
	 */
	public const AI_HTTP_TIMEOUT = 120;

	/**
	 * How many of the most recent tool-result messages are always re-sent
	 * verbatim, even once the history has been compacted. Filterable via
	 * `agent_mod_history_keep_tool_results`.
	 *
	 * @since 1.1.5
	 */
	public const AI_HISTORY_KEEP_TOOL_RESULTS = 6;

	/**
	 * Estimated history size (tokens) at which the tool-calling loop starts
	 * eliding old tool results. Below it the history is sent whole. Filterable
	 * via `agent_mod_history_compact_trigger_tokens`.
	 *
	 * @since 1.3.4
	 */
	public const AI_HISTORY_COMPACT_TRIGGER_TOKENS = 80000;

	/**
	 * Estimated history size (tokens) a compaction elides down to. The gap to
	 * the trigger keeps compactions rare, so the sent prefix stays stable (and
	 * cacheable) between them. Filterable via
	 * `agent_mod_history_compact_target_tokens`.
	 *
	 * @since 1.3.4
	 */
	public const AI_HISTORY_COMPACT_TARGET_TOKENS = 40000;

	/**
	 * Length (characters) of the stub kept when an old tool result is elided
	 * from the in-loop history.
	 *
	 * @since 1.1.5
	 */
	public const AI_TOOL_RESULT_STUB_LENGTH = 200;

	/**
	 * Length (characters) of the tool-result digest persisted with the
	 * conversation history so later turns can reuse earlier results instead of
	 * re-calling the same tools. Filterable via
	 * `agent_mod_tool_result_digest_length`.
	 *
	 * @since 1.1.5
	 */
	public const AI_TOOL_RESULT_DIGEST_LENGTH = 500;

	/**
	 * Maximum posts whose full body content an agent may read in a single turn.
	 *
	 * @since 1.0.0
	 */
	public const AI_MAX_FULL_CONTENT_POSTS = 5;

	/**
	 * Maximum posts a single bulk call accepts: titles / include in list-posts
	 * and post_ids in preview-bulk-replace / bulk-replace-content.
	 *
	 * @since 1.3.3
	 */
	public const AI_MAX_BULK_POSTS = 100;

	/**
	 * Length (characters) of the compact summary kept for a list result (post
	 * IDs and titles) when it is elided from the in-loop history or persisted
	 * as a digest. Larger than the plain stub / digest so the IDs survive and
	 * the agent does not list the same posts again.
	 *
	 * @since 1.3.3
	 */
	public const AI_LIST_RESULT_SUMMARY_LENGTH = 6000;

	/**
	 * Maximum length (characters) of a free-text answer the user types in an
	 * ask-user or request-approval dialog.
	 *
	 * @since 1.3.2
	 */
	public const AI_MAX_ANSWER_LENGTH = 20000;

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
	public const AI_ATTACHMENT_MAX_COUNT = 15;

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
		'text/html',
		'text/csv',
	];

	/**
	 * Default name of AgentMod Assistant.
	 *
	 * @since 1.0.5
	 * @var string
	 */
	public const AI_AGENT_DEFAULT_NAME = 'AgentMod Assistant';

	/**
	 * Default context enabled of AgentMod Assistant.
	 *
	 * @since 1.0.5
	 * @var bool
	 */
	public const AI_CONTEXT_ENABLED = true;

	/**
	 * Default web search enabled state of AgentMod Assistant.
	 *
	 * Opt-in by default: web search adds latency and cost, and native support
	 * depends on the selected provider/model.
	 *
	 * @since 1.0.6
	 * @var bool
	 */
	public const AI_WEB_SEARCH_ENABLED = false;

	/**
	 * Default role of AgentMod Assistant.
	 *
	 * @since 1.0.5
	 * @var string
	 */
	public const AI_AGENT_DEFAULT_ROLE = 'You are a WordPress expert AI assistant. You can create content, design, and manage the website using abilities.';

	/**
	 * Default goal of AgentMod Assistant.
	 *
	 * @since 1.0.5
	 * @var string
	 */
	public const AI_AGENT_DEFAULT_GOAL = 'Your goal is completing requests with high accuracy and efficiency and making no mistakes.';

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
	 * @since 1.0.0
	 */
	public static function aiDefaultSystemPrompt(): string
	{
		$directives = [
			__(
				'State-changing operations are automatically intercepted by the system, which shows the user an approval dialog before they run. Do not ask for permission in chat — request the needed tool calls directly. When a task requires several write operations, request them in sequence until the whole task is complete; never stop mid-task to ask whether to continue.',
				'agent-mod'
			),
			__(
				'When a tool requires a detail the user has not given and you cannot look it up with the available tools or reasonably default it, ask the user before calling that tool. Do not ask about details you can find yourself.',
				'agent-mod'
			),
			__(
				'When a tool can answer the user accurately, call it before responding, unless the answer is already given in the site context or earlier in the conversation. Base your answers on tool results rather than guessing.',
				'agent-mod'
			),
		];

		return implode("\n\n", $directives);
	}

	/**
	 * Default chat panel preset prompts.
	 *
	 * @since 1.0.7
	 * @return array<array{label: string, prompt: string}>
	 */
	public static function aiDefaultPresetPrompts(): array
	{
		return [
			[
				'label' => __('Create a pattern', 'agent-mod'),
				'prompt' => __('Create a hero section pattern includes ...... via using @agent-mod/create-pattern  tool.', 'agent-mod'),
			],
			[
				'label' => __('Create a post', 'agent-mod'),
				'prompt' => __('Create a post about ..... via using @agent-mod/create-post tool. Details: .....', 'agent-mod'),
			],
			[
				'label' => __('Install a slider plugin', 'agent-mod'),
				'prompt' => __('Install a slider plugin via using @agent-mod/install-plugin tool. The plugin should provide a block to create slides with core blocks.', 'agent-mod'),
			],
			[
				'label' => __('Change time zone of the site', 'agent-mod'),
				'prompt' => __('Change site time zone as "Europe/Istanbul".', 'agent-mod'),
			],
			[
				'label' => __('Get environment info', 'agent-mod'),
				'prompt' => __('What is the environment information of my web site? Use related ability tools to fetch environment info.', 'agent-mod'),
			],
		];
	}
}
