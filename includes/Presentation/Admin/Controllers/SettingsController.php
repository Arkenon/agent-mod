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
			'layout'      => 'tab_panel',
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
						'fieldType'               => 'token_field',
						'name'                    => 'personality_traits',
						'fieldLabel'              => __('Personality Traits', 'agent-mod'),
						'fieldHelpText'           => __('Add personality traits.', 'agent-mod'),
						'maxSuggestions'          => 10,
						'suggestions'             => 'helpful, friendly, professional, corporate, persuasive, curious, patient, analytical',
						'__experimentalExpandOnFocus' => true,
					],
					[
						'fieldType'  => 'toggle',
						'name'       => 'site_context_enabled',
						'fieldLabel' => __('Enable Site Context (RAG) by Default', 'agent-mod'),
						'fieldHelpText'       => __('When enabled, the agent automatically includes site metadata (name, URL, WordPress version, etc.) in its system prompt.', 'agent-mod'),
						'default'    => Constants::AI_CONTEXT_ENABLED,
					],
					[
						'fieldType'   => 'textarea',
						'name'        => 'role',
						'fieldLabel'  => __('Role', 'agent-mod'),
						'fieldHelpText' => __('The role the agent should play. Describe the main function of the agent.', 'agent-mod'),
						'default'     => Constants::AI_AGENT_DEFAULT_ROLE
					],
					[
						'fieldType'   => 'textarea',
						'name'        => 'goal',
						'fieldLabel'  => __('Goal', 'agent-mod'),
						'fieldHelpText' => __('The goal of the agent. Describe what you want the agent to achieve.', 'agent-mod'),
						'default'     => Constants::AI_AGENT_DEFAULT_GOAL
					],
					[
						'fieldType'   => 'textarea',
						'name'        => 'global_system_prompt',
						'fieldLabel'  => __('Base System Prompt', 'agent-mod'),
						'fieldHelpText'        => __('The core behaviour instructions sent to the AI with every message. Fully editable: change or remove directives to manage the assistant\'s behaviour. Leave empty to use the built-in defaults.', 'agent-mod'),
						'placeholder' => __('You are a helpful WordPress assistant.', 'agent-mod'),
						'default'     => Constants::aiDefaultSystemPrompt(),
					],
				],
			],
			[
				'section_name'  => 'agent_mod_abilities',
				'section_title' => __('Abilities', 'agent-mod'),
				'section_icon'  => 'admin-plugins',
				'fields'        => [
					[
						'fieldType'   => 'select',
						'name'        => 'ability_source',
						'fieldLabel'  => __('Ability Source', 'agent-mod'),
						'fieldHelpText'        => __('Choose whether to allow all abilities or only selected ones.', 'agent-mod'),
						'options'     => 'All Abilities : all, Selected Abilities : selected',
						'default'     => 'all',
					],
					[
						'fieldType'    => 'select',
						'multiple'     => true,
						'name'         => 'allowed_abilities',
						'fieldLabel'   => __('Allowed Abilities', 'agent-mod'),
						'fieldHelpText'         => __('Select the abilities to allow. Used only when Ability Source is set to Selected Abilities.', 'agent-mod'),
						'options'      => $this->getAbilityOptions(),
						'default'      => [],
						'dependencies' => [
							'relation'   => 'and',
							'conditions' => [
								[
									'field'    => 'ability_source',
									'operator' => '==',
									'value'    => 'selected',
								],
							],
						],
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
						'fieldHelpText'       => __('Maximum agentic tool-calling iterations allowed per chat message. Default: 10.', 'agent-mod'),
						'default'    => Constants::AI_MAX_TOOL_CALLS,
					],
					[
						'fieldType'  => 'number',
						'name'       => 'max_search_results',
						'fieldLabel' => __('Max Search Results', 'agent-mod'),
						'fieldHelpText'       => __('Maximum items returned per search ability call. Default: 20.', 'agent-mod'),
						'default'    => Constants::AI_MAX_SEARCH_RESULTS,
					],
					[
						'fieldType'  => 'number',
						'name'       => 'max_full_content_posts',
						'fieldLabel' => __('Max Full-Content Posts', 'agent-mod'),
						'fieldHelpText'       => __('Maximum posts whose full body the agent may read in a single turn. Default: 5.', 'agent-mod'),
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
						'fieldHelpText'       => __('Maximum number of files a user may attach per chat turn. Default: 5.', 'agent-mod'),
						'default'    => Constants::AI_ATTACHMENT_MAX_COUNT,
					],
					[
						'fieldType'  => 'number',
						'name'       => 'attachment_max_bytes',
						'fieldLabel' => __('Max File Size (bytes)', 'agent-mod'),
						'fieldHelpText'       => __('Maximum decoded size in bytes for a single attachment. Default: 5242880 (5 MB).', 'agent-mod'),
						'default'    => Constants::AI_ATTACHMENT_MAX_BYTES,
					],
					[
						'fieldType'   => 'textarea',
						'name'        => 'attachment_mime_types',
						'fieldLabel'  => __('Allowed MIME Types', 'agent-mod'),
						'fieldHelpText'        => __('One MIME type per line. Leave empty to use the built-in defaults (PNG, JPEG, GIF, WebP, PDF, TXT, Markdown, CSV).', 'agent-mod'),
						'default'     => implode("\n", Constants::AI_ATTACHMENT_MIME_TYPES),
					],
				],
			],
		];
	}

	/**
	 * Get allowed abilities options for the settings field.
	 *
	 * @return array<int, array<string, string>>
	 * @since 1.0.0
	 */
	private function getAbilityOptions(): array
	{
		$options = [];

		$abilities = wp_get_abilities();
		foreach ($abilities as $ability) {
			$options[] = [
				'label' => $ability->get_label(),
				'value' => $ability->get_name(),
			];
		}

		return $options;
	}
}
