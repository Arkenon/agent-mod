<?php

/**
 * Settings service.
 *
 * Registers the AgentMod settings page via Native Custom Fields and bridges
 * saved values to the plugin's filterable constants.
 *
 * @package AgentMod
 * @subpackage Services
 * @since 1.0.0
 */

namespace AgentMod\Services;

defined('ABSPATH') || exit;

final class SettingsService
{
	/**
	 * WordPress option key where NCF stores all field values for this page.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public const OPTION_KEY = 'agent_mod_settings';

	/**
	 * Constructor (PHP-DI autowired). Registers all hooks immediately.
	 *
	 * @since 1.0.0
	 */
	public function __construct()
	{
		// NCF page and field registration.
		add_filter('native_custom_fields_options_pages',       [$this, 'registerSettingsPage']);
		add_filter('native_custom_fields_options_page_fields', [$this, 'registerSettingsFields'], 10, 2);

		// Bridge saved values into the plugin's filterable constants.
		add_filter('agent_mod_max_tool_calls',         [$this, 'filterMaxToolCalls']);
		add_filter('agent_mod_max_search_results',     [$this, 'filterMaxSearchResults']);
		add_filter('agent_mod_max_full_content_posts', [$this, 'filterMaxFullContentPosts']);
		add_filter('agent_mod_attachment_max_bytes',   [$this, 'filterAttachmentMaxBytes']);
		add_filter('agent_mod_attachment_max_count',   [$this, 'filterAttachmentMaxCount']);
		add_filter('agent_mod_attachment_mime_types',  [$this, 'filterAttachmentMimeTypes']);

		// Prepend global system prompt at priority 5 so Pro overrides (priority 10) run after.
		add_filter('agent_mod_system_prompt', [$this, 'prependGlobalSystemPrompt'], 5, 2);
	}

	/**
	 * Registers the AgentMod settings page as a submenu of the main AgentMod menu.
	 *
	 * @param array<string, mixed> $options_pages Existing NCF page configurations.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function registerSettingsPage(array $options_pages): array
	{
		$options_pages[self::OPTION_KEY] = [
			'page_title'  => __('AgentMod Settings', 'agent-mod'),
			'menu_title'  => __('Settings', 'agent-mod'),
			'menu_slug'   => self::OPTION_KEY,
			'capability'  => 'manage_options',
			'icon_url'    => 'dashicons-admin-generic',
			'layout'      => 'stacked',
			'position'    => 10,
			'created_by'  => 'external_plugin',
			'parent_slug' => 'agent-mod',
		];

		return $options_pages;
	}

	/**
	 * Registers field sections and fields for the AgentMod settings page.
	 *
	 * @param array<int, mixed> $sections  Existing sections (from other pages or filters).
	 * @param string            $menu_slug The menu slug of the page being built.
	 *
	 * @return array<int, mixed>
	 * @since 1.0.0
	 */
	public function registerSettingsFields(array $sections, string $menu_slug): array
	{
		if (self::OPTION_KEY !== $menu_slug) {
			return $sections;
		}

		return [
			[
				'section_name'  => 'agent_mod_chat_behaviour',
				'section_title' => __('Chat Behaviour', 'agent-mod'),
				'section_icon'  => 'dashicons-format-chat',
				'fields'        => [
					[
						'fieldType'  => 'toggle',
						'name'       => 'site_context_enabled',
						'fieldLabel' => __('Enable Site Context (RAG) by Default', 'agent-mod'),
						'help'       => __('When enabled, the agent automatically includes site metadata (name, URL, WordPress version, etc.) in its system prompt.', 'agent-mod'),
						'default'    => true,
					],
					[
						'fieldType'   => 'textarea',
						'name'        => 'global_system_prompt',
						'fieldLabel'  => __('Global System Prompt', 'agent-mod'),
						'help'        => __('Prepended to every agent\'s system prompt. Leave empty to skip.', 'agent-mod'),
						'placeholder' => __('You are a helpful WordPress assistant.', 'agent-mod'),
						'default'     => '',
					],
				],
			],
			[
				'section_name'  => 'agent_mod_ai_limits',
				'section_title' => __('AI Limits', 'agent-mod'),
				'section_icon'  => 'dashicons-performance',
				'fields'        => [
					[
						'fieldType'  => 'number',
						'name'       => 'max_tool_calls',
						'fieldLabel' => __('Max Tool Calls per Turn', 'agent-mod'),
						'help'       => __('Maximum agentic tool-calling iterations allowed per chat message. Default: 10.', 'agent-mod'),
						'default'    => 10,
					],
					[
						'fieldType'  => 'number',
						'name'       => 'max_search_results',
						'fieldLabel' => __('Max Search Results', 'agent-mod'),
						'help'       => __('Maximum items returned per search ability call. Default: 20.', 'agent-mod'),
						'default'    => 20,
					],
					[
						'fieldType'  => 'number',
						'name'       => 'max_full_content_posts',
						'fieldLabel' => __('Max Full-Content Posts', 'agent-mod'),
						'help'       => __('Maximum posts whose full body the agent may read in a single turn. Default: 5.', 'agent-mod'),
						'default'    => 5,
					],
				],
			],
			[
				'section_name'  => 'agent_mod_attachments',
				'section_title' => __('File Attachments', 'agent-mod'),
				'section_icon'  => 'dashicons-paperclip',
				'fields'        => [
					[
						'fieldType'  => 'number',
						'name'       => 'attachment_max_count',
						'fieldLabel' => __('Max Files per Message', 'agent-mod'),
						'help'       => __('Maximum number of files a user may attach per chat turn. Default: 5.', 'agent-mod'),
						'default'    => 5,
					],
					[
						'fieldType'  => 'number',
						'name'       => 'attachment_max_bytes',
						'fieldLabel' => __('Max File Size (bytes)', 'agent-mod'),
						'help'       => __('Maximum decoded size in bytes for a single attachment. Default: 5242880 (5 MB).', 'agent-mod'),
						'default'    => 5242880,
					],
					[
						'fieldType'   => 'textarea',
						'name'        => 'attachment_mime_types',
						'fieldLabel'  => __('Allowed MIME Types', 'agent-mod'),
						'help'        => __('One MIME type per line. Leave empty to use the built-in defaults (PNG, JPEG, GIF, WebP, PDF, TXT, Markdown, CSV).', 'agent-mod'),
						'default'     => "image/jpeg\nimage/gif\nimage/webp\napplication/pdf\ntext/plain\ntext/markdown\ntext/csv",
					],
				],
			],
		];
	}

	// -------------------------------------------------------------------------
	// Filter callbacks — each reads the saved setting and returns it when set,
	// or falls back to whatever value the caller passed in ($default).
	// -------------------------------------------------------------------------

	/**
	 * @param int $default Caller-supplied default (typically the hardcoded constant).
	 * @return int
	 * @since 1.0.0
	 */
	public function filterMaxToolCalls(int $default): int
	{
		$saved = (int) ($this->getSettings()['max_tool_calls'] ?? 0);
		return $saved > 0 ? $saved : $default;
	}

	/**
	 * @param int $default Caller-supplied default.
	 * @return int
	 * @since 1.0.0
	 */
	public function filterMaxSearchResults(int $default): int
	{
		$saved = (int) ($this->getSettings()['max_search_results'] ?? 0);
		return $saved > 0 ? $saved : $default;
	}

	/**
	 * @param int $default Caller-supplied default.
	 * @return int
	 * @since 1.0.0
	 */
	public function filterMaxFullContentPosts(int $default): int
	{
		$saved = (int) ($this->getSettings()['max_full_content_posts'] ?? 0);
		return $saved > 0 ? $saved : $default;
	}

	/**
	 * @param int $default Caller-supplied default (bytes).
	 * @return int
	 * @since 1.0.0
	 */
	public function filterAttachmentMaxBytes(int $default): int
	{
		$saved = (int) ($this->getSettings()['attachment_max_bytes'] ?? 0);
		return $saved > 0 ? $saved : $default;
	}

	/**
	 * @param int $default Caller-supplied default.
	 * @return int
	 * @since 1.0.0
	 */
	public function filterAttachmentMaxCount(int $default): int
	{
		$saved = (int) ($this->getSettings()['attachment_max_count'] ?? 0);
		return $saved > 0 ? $saved : $default;
	}

	/**
	 * @param string[] $default Caller-supplied default MIME type list.
	 * @return string[]
	 * @since 1.0.0
	 */
	public function filterAttachmentMimeTypes(array $default): array
	{
		$raw = trim((string) ($this->getSettings()['attachment_mime_types'] ?? ''));
		if ('' === $raw) {
			return $default;
		}

		$types = array_values(array_filter(array_map('trim', explode("\n", $raw))));
		return ! empty($types) ? $types : $default;
	}

	/**
	 * Prepends the saved global system prompt (if any) to the assembled instruction.
	 *
	 * Runs at priority 5 so Pro-level overrides at priority 10 still apply after.
	 *
	 * @param string $instruction Assembled system instruction.
	 * @param mixed  $agent       AgentConfig DTO (unused here).
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function prependGlobalSystemPrompt(string $instruction, $agent): string
	{
		$prefix = trim((string) ($this->getSettings()['global_system_prompt'] ?? ''));
		return '' !== $prefix ? $prefix . "\n\n" . $instruction : $instruction;
	}

	/**
	 * Returns the saved settings array, or an empty array when no settings are saved yet.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	private function getSettings(): array
	{
		return (array) get_option(self::OPTION_KEY, []);
	}
}
