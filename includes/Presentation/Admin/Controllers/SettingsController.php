<?php
/**
 * Settings controller class
 * Creates settings page for admin area
 * @package AgentMod
 * @subpackage Presentation\Admin\Controllers
 * @since 1.0.0
 */

namespace AgentMod\Presentation\Admin\Controllers;

use AgentMod\Common\Constants;
use AgentMod\Services\SettingsService;

defined('ABSPATH') || exit;

final class SettingsController
{

    private SettingsService $settingsService;

	public function __construct(SettingsService $settingsService)
	{
        $this->settingsService = $settingsService;

        // NCF page and field registration.
		add_filter('native_custom_fields_options_pages',       [$this, 'registerSettingsPage']);
		add_filter('native_custom_fields_options_page_fields', [$this, 'registerSettingsFields'], 10, 2);
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
		$options_pages[$this->settingsService->optionKey] = [
			'page_title'  => __('AgentMod Settings', 'agent-mod'),
			'menu_title'  => __('Settings', 'agent-mod'),
			'menu_slug'   => $this->settingsService->optionKey,
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
		if ($this->settingsService->optionKey !== $menu_slug) {
			return $sections;
		}

		return [
			[
				'section_name'  => 'agent_mod_chat_behaviour',
				'section_title' => __('Chat Behaviour', 'agent-mod'),
				'section_icon'  => 'format-chat',
				'fields'        => [
					[
						'fieldType'  => 'toggle',
						'name'       => 'site_context_enabled',
						'fieldLabel' => __('Enable Site Context (RAG) by Default', 'agent-mod'),
						'help'       => __('When enabled, the agent automatically includes site metadata (name, URL, WordPress version, etc.) in its system prompt.', 'agent-mod'),
						'default'    => Constants::AI_CONTEXT_ENABLED,
					],
					[
						'fieldType'   => 'text',
						'name'        => 'role',
						'fieldLabel'  => __('Role', 'agent-mod'),
						'default'     => Constants::AI_AGENT_DEFAULT_ROLE
					],
					[
						'fieldType'   => 'text',
						'name'        => 'goal',
						'fieldLabel'  => __('Goal', 'agent-mod'),
						'default'     => Constants::AI_AGENT_DEFAULT_GOAL
					],
					[
						'fieldType'   => 'textarea',
						'name'        => 'global_system_prompt',
						'fieldLabel'  => __('Base System Prompt', 'agent-mod'),
						'help'        => __('The core behaviour instructions sent to the AI with every message. Fully editable: change or remove directives to manage the assistant\'s behaviour. Leave empty to use the built-in defaults.', 'agent-mod'),
						'placeholder' => __('You are a helpful WordPress assistant.', 'agent-mod'),
						'default'     => Constants::aiDefaultSystemPrompt(),
					],
				],
			],
			[
				'section_name'  => 'agent_mod_ai_limits',
				'section_title' => __('AI Limits', 'agent-mod'),
				'section_icon'  => 'performance',
				'fields'        => [
					[
						'fieldType'  => 'number',
						'name'       => 'max_tool_calls',
						'fieldLabel' => __('Max Tool Calls per Turn', 'agent-mod'),
						'help'       => __('Maximum agentic tool-calling iterations allowed per chat message. Default: 10.', 'agent-mod'),
						'default'    => Constants::AI_MAX_TOOL_CALLS,
					],
					[
						'fieldType'  => 'number',
						'name'       => 'max_search_results',
						'fieldLabel' => __('Max Search Results', 'agent-mod'),
						'help'       => __('Maximum items returned per search ability call. Default: 20.', 'agent-mod'),
						'default'    => Constants::AI_MAX_SEARCH_RESULTS,
					],
					[
						'fieldType'  => 'number',
						'name'       => 'max_full_content_posts',
						'fieldLabel' => __('Max Full-Content Posts', 'agent-mod'),
						'help'       => __('Maximum posts whose full body the agent may read in a single turn. Default: 5.', 'agent-mod'),
						'default'    => Constants::AI_MAX_FULL_CONTENT_POSTS,
					],
				],
			],
			[
				'section_name'  => 'agent_mod_attachments',
				'section_title' => __('File Attachments', 'agent-mod'),
				'section_icon'  => 'paperclip',
				'fields'        => [
					[
						'fieldType'  => 'number',
						'name'       => 'attachment_max_count',
						'fieldLabel' => __('Max Files per Message', 'agent-mod'),
						'help'       => __('Maximum number of files a user may attach per chat turn. Default: 5.', 'agent-mod'),
						'default'    => Constants::AI_ATTACHMENT_MAX_COUNT,
					],
					[
						'fieldType'  => 'number',
						'name'       => 'attachment_max_bytes',
						'fieldLabel' => __('Max File Size (bytes)', 'agent-mod'),
						'help'       => __('Maximum decoded size in bytes for a single attachment. Default: 5242880 (5 MB).', 'agent-mod'),
						'default'    => Constants::AI_ATTACHMENT_MAX_BYTES,
					],
					[
						'fieldType'   => 'textarea',
						'name'        => 'attachment_mime_types',
						'fieldLabel'  => __('Allowed MIME Types', 'agent-mod'),
						'help'        => __('One MIME type per line. Leave empty to use the built-in defaults (PNG, JPEG, GIF, WebP, PDF, TXT, Markdown, CSV).', 'agent-mod'),
						'default'     => implode("\n", Constants::AI_ATTACHMENT_MIME_TYPES),
					],
				],
			],
		];
	}
}
