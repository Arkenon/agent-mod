<?php

/**
 * Agent CPT registration service.
 *
 * Registers the agentmod_agent and agentmod_chatmemo custom post types plus
 * their associated meta fields. Neither CPT is publicly accessible; both are
 * internal data stores for the plugin.
 *
 * @package AgentMod
 * @subpackage Services
 * @since 1.0.0
 */

namespace AgentMod\Services;

defined('ABSPATH') || exit;

class AgentCPTService
{
	/**
	 * Constructor. Binds the CPT registration to the init hook.
	 *
	 * @since 1.0.0
	 */
	public function __construct()
	{
		add_action('init', [$this, 'registerPostTypes']);
	}

	/**
	 * Registers both custom post types and their meta fields.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function registerPostTypes(): void
	{
		$this->registerAgentCPT();
		$this->registerConversationCPT();
	}

	/**
	 * Registers the agentmod_agent CPT.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function registerAgentCPT(): void
	{
		register_post_type(
			'agentmod_agent',
			[
				'labels'       => [
					'name'               => __('Agents', 'agent-mod'),
					'singular_name'      => __('Agent', 'agent-mod'),
					'add_new_item'       => __('Add New Agent', 'agent-mod'),
					'edit_item'          => __('Edit Agent', 'agent-mod'),
					'not_found'          => __('No agents found.', 'agent-mod'),
				],
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => false,
				'show_in_rest'       => true,
				'supports'           => ['title'],
				'menu_icon'          => 'dashicons-format-chat',
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
			]
		);

		$string_fields = ['description', 'agent_type', 'system_prompt', 'role', 'goal', 'ability_source'];

		foreach ($string_fields as $key) {
			register_post_meta(
				'agentmod_agent',
				$key,
				[
					'type'         => 'string',
					'single'       => true,
					'default'      => '',
					'show_in_rest' => true,
				]
			);
		}

		foreach (['personality_traits', 'allowed_abilities'] as $key) {
			register_post_meta(
				'agentmod_agent',
				$key,
				[
					'type'         => 'string',
					'single'       => true,
					'default'      => '[]',
					'show_in_rest' => true,
				]
			);
		}

		register_post_meta(
			'agentmod_agent',
			'max_tool_calls',
			[
				'type'         => 'integer',
				'single'       => true,
				'default'      => 10,
				'show_in_rest' => true,
			]
		);
	}

	/**
	 * Registers the agentmod_chatmemo CPT.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function registerConversationCPT(): void
	{
		register_post_type(
			'agentmod_chatmemo',
			[
				'labels'      => [
					'name'          => __('Conversations', 'agent-mod'),
					'singular_name' => __('Conversation', 'agent-mod'),
				],
				'public'      => false,
				'show_ui'     => false,
				'show_in_rest'=> false,
				'supports'    => ['title'],
			]
		);

		$meta_map = [
			'agent_id'         => 'integer',
			'session_id'       => 'string',
			'source'           => 'string',
			'messages_history' => 'string',
			'started_at'       => 'string',
			'last_message_at'  => 'string',
		];

		foreach ($meta_map as $key => $type) {
			register_post_meta(
				'agentmod_chatmemo',
				$key,
				[
					'type'         => $type,
					'single'       => true,
					'default'      => 'integer' === $type ? 0 : '',
					'show_in_rest' => false,
				]
			);
		}
	}
}
