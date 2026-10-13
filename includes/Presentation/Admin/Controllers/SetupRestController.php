<?php

/**
 * REST endpoints used by the setup wizard.
 *
 * @package    AgentMod
 * @subpackage Presentation\Admin\Controllers
 * @since      1.3.0
 */

namespace AgentMod\Presentation\Admin\Controllers;

use AgentMod\Common\Constants;
use AgentMod\Services\AI\ProviderInfoService;
use AgentMod\Services\SettingsService;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

final class SetupRestController
{
	private SettingsService $settingsService;
	private ProviderInfoService $providerInfo;

	/**
	 * @since 1.3.0
	 */
	public function __construct(SettingsService $settingsService, ProviderInfoService $providerInfo)
	{
		$this->settingsService = $settingsService;
		$this->providerInfo    = $providerInfo;

		add_action('rest_api_init', [$this, 'registerRoutes']);
	}

	/**
	 * @since 1.3.0
	 */
	public function registerRoutes(): void
	{
		$permission = static fn(): bool => current_user_can('manage_options');

		register_rest_route(Constants::REST_NAMESPACE, '/setup/settings', [
			'methods'             => 'POST',
			'callback'            => [$this, 'saveSettings'],
			'permission_callback' => $permission,
		]);

		register_rest_route(Constants::REST_NAMESPACE, '/setup/complete', [
			'methods'             => 'POST',
			'callback'            => [$this, 'complete'],
			'permission_callback' => $permission,
		]);

		register_rest_route(Constants::REST_NAMESPACE, '/setup/models', [
			'methods'             => 'GET',
			'callback'            => [$this, 'models'],
			'permission_callback' => $permission,
		]);
	}

	/**
	 * Model options ("<provider>:<model>") for the default-model select.
	 *
	 * @since 1.3.0
	 */
	public function models(): WP_REST_Response
	{
		return rest_ensure_response($this->providerInfo->getModelOptions());
	}

	/**
	 * Merges the submitted fields into the settings option. Only keys present
	 * in the request are touched, so "Skip" simply sends nothing.
	 *
	 * @since 1.3.0
	 */
	public function saveSettings(WP_REST_Request $request): WP_REST_Response
	{
		$params = (array) $request->get_json_params();
		$key    = $this->settingsService->optionKey;
		$saved  = get_option($key, []);
		$saved  = is_array($saved) ? $saved : [];

		$behaviour = isset($saved['agent_mod_chat_behaviour']) && is_array($saved['agent_mod_chat_behaviour'])
			? $saved['agent_mod_chat_behaviour']
			: [];

		$textFields = ['role' => 'role', 'goal' => 'goal', 'systemPrompt' => 'global_system_prompt'];
		foreach ($textFields as $param => $name) {
			if (array_key_exists($param, $params)) {
				$behaviour[$name] = wp_kses_post((string) $params[$param]);
			}
		}

		if (array_key_exists('personalityTraits', $params)) {
			$traits = is_array($params['personalityTraits']) ? $params['personalityTraits'] : [];
			$behaviour['personality_traits'] = array_values(array_filter(array_map('sanitize_text_field', $traits)));
		}

		if (array_key_exists('siteContextEnabled', $params)) {
			$behaviour['site_context_enabled'] = rest_sanitize_boolean($params['siteContextEnabled']);
		}

		if (array_key_exists('webSearchEnabled', $params)) {
			$behaviour['web_search_enabled'] = rest_sanitize_boolean($params['webSearchEnabled']);
		}

		$saved['agent_mod_chat_behaviour'] = $behaviour;

		if (array_key_exists('defaultModel', $params)) {
			$saved['agent_mod_ai_model'] = array_merge(
				(array) ($saved['agent_mod_ai_model'] ?? []),
				['default_model' => sanitize_text_field((string) $params['defaultModel'])]
			);
		}

		if (array_key_exists('presetPrompts', $params)) {
			$rows = [];
			foreach ((array) $params['presetPrompts'] as $row) {
				$prompt = sanitize_textarea_field((string) ($row['prompt'] ?? ''));
				if ('' === trim($prompt)) {
					continue;
				}
				$rows[] = [
					'label'  => sanitize_text_field((string) ($row['label'] ?? '')),
					'prompt' => $prompt,
				];
			}
			// NCF repeater format: JSON string, max 10 rows.
			$saved['agent_mod_chat_panel'] = array_merge(
				(array) ($saved['agent_mod_chat_panel'] ?? []),
				['preset_prompts' => wp_json_encode(array_slice($rows, 0, 10))]
			);
		}

		update_option($key, $saved);

		return rest_ensure_response(['success' => true]);
	}

	/**
	 * @since 1.3.0
	 */
	public function complete(): WP_REST_Response
	{
		update_option(SetupController::COMPLETED_OPTION, 1);

		return rest_ensure_response(['success' => true]);
	}
}
