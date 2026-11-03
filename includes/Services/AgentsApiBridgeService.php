<?php

/**
 * Agents API bridge (FREE side).
 *
 * Optional integration with the Agents API substrate (wp_register_agent()).
 * When the Agents API is loaded — as a plugin, a Composer package, or one day
 * WordPress core — every agent another plugin registers there shows up in the
 * chat panel's Agent Tray and runs on AgentMod's own engine: AgentMod's tool
 * loading, Ask/Plan/Execute modes and, above all, its write confirmation gate
 * apply to it exactly as they do to AgentMod's own agents.
 *
 * Nothing here requires the Agents API: every entry point feature-detects it
 * and is a no-op when it is missing.
 *
 * Tray records of these agents carry a string id (`agents-api:<slug>`). The
 * agent configuration is resolved server-side from the registered agent, never
 * from the request, in resolveAgentConfig().
 *
 * @package AgentMod
 * @subpackage Services
 * @since 1.3.7
 */

namespace AgentMod\Services;

use WP_Ability;
use WP_Agent;
use AgentMod\Services\AI\AbilityResolver;

defined('ABSPATH') || exit;

class AgentsApiBridgeService
{
	/**
	 * Prefix of the tray id of an Agents API agent.
	 *
	 * @var string
	 * @since 1.3.7
	 */
	public const ID_PREFIX = 'agents-api:';

	/**
	 * Allowed-ability placeholder for an agent that declares no tools.
	 *
	 * AgentConfig falls back to the settings-page ability list when the list is
	 * empty, which would hand a tool-less agent every ability. An unregistered
	 * name resolves to nothing, so the agent gets no tools.
	 *
	 * @var string
	 * @since 1.3.7
	 */
	private const NO_TOOLS = 'agent-mod/__no-tools__';

	/**
	 * Most abilities read from one agent's declared tool list.
	 *
	 * @var int
	 * @since 1.3.7
	 */
	private const MAX_TOOLS = 200;

	/**
	 * Settings service; supplies the base system prompt.
	 *
	 * @var SettingsService
	 * @since 1.3.7
	 */
	private SettingsService $settings;

	/**
	 * Constructor (PHP-DI autowired). Binds the FREE extension points.
	 *
	 * Both run after PRO's (priority 10), so PRO's own agents and its config
	 * resolution are untouched.
	 *
	 * @param SettingsService $settings Settings service.
	 *
	 * @since 1.3.7
	 */
	public function __construct(SettingsService $settings)
	{
		$this->settings = $settings;

		add_filter('agent_mod_get_agents', [$this, 'appendAgents'], 20);
		add_filter('agent_mod_agent_config_data', [$this, 'resolveAgentConfig'], 20);
	}

	/**
	 * Whether the Agents API registry is available.
	 *
	 * @return bool
	 * @since 1.3.7
	 */
	public static function isAvailable(): bool
	{
		return function_exists('wp_get_agents') && function_exists('wp_get_agent') && class_exists('WP_Agent');
	}

	/**
	 * Adds the agents registered with the Agents API to the Agent Tray list.
	 *
	 * Agents AgentMod itself published there (PRO's agents) are skipped: they
	 * are already in the list under their own ids.
	 *
	 * @param array<int, array<string, mixed>> $agents Agent tray records.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.3.7
	 */
	public function appendAgents($agents): array
	{
		$agents = is_array($agents) ? $agents : [];

		if (! self::isAvailable()) {
			return $agents;
		}

		foreach (wp_get_agents() as $agent) {
			if (! $agent instanceof WP_Agent || self::isOwnAgent($agent)) {
				continue;
			}

			$agents[] = $this->trayRecord($agent);
		}

		return $agents;
	}

	/**
	 * Resolves the configuration of an Agents API agent picked in the tray.
	 *
	 * Maps the registered agent's default config onto AgentConfig keys:
	 *
	 *  - its label is the agent name; role and goal are left empty, and its
	 *    system prompt is appended to the settings page's base system prompt
	 *    (AgentMod's safety directives), which therefore always stays;
	 *  - its declared tools become a 'selected' ability list (none declared:
	 *    no tools);
	 *  - its provider/model apply unless the request picked a model.
	 *
	 * Request-level keys (mode, emphasizedAbilities, provider, model) are kept.
	 * The id is cleared: the run is not tied to a stored AgentMod agent.
	 *
	 * @param array<string, mixed> $agentData Sanitized agent data from the request.
	 *
	 * @return array<string, mixed>
	 * @since 1.3.7
	 */
	public function resolveAgentConfig($agentData): array
	{
		$agentData = is_array($agentData) ? $agentData : [];
		$slug      = self::slugFromId($agentData['id'] ?? null);

		if ('' === $slug) {
			return $agentData;
		}

		// Whatever happens below, the string id must not reach AgentConfig.
		$agentData['id'] = null;

		$agent = self::isAvailable() && wp_has_agent($slug) ? wp_get_agent($slug) : null;

		if (! $agent instanceof WP_Agent) {
			// Unregistered meanwhile (plugin deactivated): default agent.
			return $agentData;
		}

		$config = $agent->get_default_config();

		$agentData['name'] = $agent->get_label();
		$agentData['role'] = '';
		$agentData['goal'] = '';

		$prompt = $this->systemPrompt($config);

		if ('' !== $prompt) {
			// The settings page's base prompt carries AgentMod's safety directives;
			// the agent's own instructions follow it.
			$agentData['baseSystemPrompt'] = trim($this->settings->getSystemPrompt() . "\n\n" . $prompt);
		}

		$tools = $this->toolNames($config);

		unset($agentData['abilitySource'], $agentData['allowedAbilities']);
		$agentData['ability_source']    = 'selected';
		$agentData['allowed_abilities'] = empty($tools) ? [self::NO_TOOLS] : $tools;

		$requestProvider = (string) ($agentData['provider'] ?? '');
		$model           = $this->modelPair($config);

		if ('' === $requestProvider && '' !== $model['provider']) {
			$agentData['provider'] = $model['provider'];
			$agentData['model']    = $model['model'];
		}

		return $agentData;
	}

	/**
	 * Agents API slug from a tray id, or '' when the id is not one.
	 *
	 * @param mixed $id Agent id from the request.
	 *
	 * @return string
	 * @since 1.3.7
	 */
	public static function slugFromId($id): string
	{
		if (! is_string($id) || 0 !== strpos($id, self::ID_PREFIX)) {
			return '';
		}

		return sanitize_title(substr($id, strlen(self::ID_PREFIX)));
	}

	/**
	 * Whether AgentMod itself registered the agent with the Agents API.
	 *
	 * @param WP_Agent $agent The agent.
	 *
	 * @return bool
	 * @since 1.3.7
	 */
	public static function isOwnAgent(WP_Agent $agent): bool
	{
		$meta   = $agent->get_meta();
		$source = is_string($meta['source_plugin'] ?? null) ? $meta['source_plugin'] : '';

		return 0 === strpos($source, 'agent-mod');
	}

	/**
	 * Builds the Agent Tray record of an Agents API agent.
	 *
	 * Same shape as PRO's records: display data, plus the ability visibility and
	 * model the server will resolve, so the tray and the model picker match it.
	 *
	 * @param WP_Agent $agent The agent.
	 *
	 * @return array<string, mixed>
	 * @since 1.3.7
	 */
	private function trayRecord(WP_Agent $agent): array
	{
		$config = $agent->get_default_config();

		$record = [
			'id'            => self::ID_PREFIX . $agent->get_slug(),
			'name'          => sanitize_text_field($agent->get_label()),
			'description'   => sanitize_text_field($agent->get_description()),
			'icon'          => '',
			'source'        => 'agents-api',
			'abilitySource' => 'selected',
		];

		$details = $this->abilityDetails($this->toolNames($config));

		if (! empty($details)) {
			$record['selectedAbilities'] = $details;
		}

		$model = $this->modelPair($config);

		if ('' !== $model['provider']) {
			$record['defaultProvider'] = $model['provider'];
			$record['defaultModel']    = $model['model'];
		}

		return $record;
	}

	/**
	 * The ability names an agent's default config declares as its tools.
	 *
	 * Same keys the Agents API's default chat runtime reads: `enabled_tools`,
	 * or else `tools`, `abilities` and `tool_names`.
	 *
	 * @param array<string, mixed> $config Agent default config.
	 *
	 * @return string[]
	 * @since 1.3.7
	 */
	private function toolNames(array $config): array
	{
		$names = is_array($config['enabled_tools'] ?? null) ? $config['enabled_tools'] : [];

		if (empty($names)) {
			foreach (['tools', 'abilities', 'tool_names'] as $key) {
				if (is_array($config[$key] ?? null)) {
					$names = array_merge($names, $config[$key]);
				}
			}
		}

		$clean = [];

		foreach ($names as $name) {
			$name = is_string($name) ? sanitize_text_field(trim($name)) : '';

			if ('' !== $name) {
				$clean[] = $name;
			}
		}

		return array_slice(array_values(array_unique($clean)), 0, self::MAX_TOOLS);
	}

	/**
	 * Tray details of the selectable abilities among the given names.
	 *
	 * @param string[] $names Ability names.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.3.7
	 */
	private function abilityDetails(array $names): array
	{
		if (empty($names) || ! function_exists('wp_get_ability')) {
			return [];
		}

		$details = [];

		foreach ($names as $name) {
			$ability = wp_get_ability($name);

			if (! $ability instanceof WP_Ability || ! AbilityResolver::isSelectable($ability)) {
				continue;
			}

			$details[] = [
				'name'  => $ability->get_name(),
				'label' => $ability->get_label(),
				'meta'  => [
					'annotations' => [
						'readonly' => AbilityResolver::isReadonly($ability),
					],
				],
			];
		}

		return $details;
	}

	/**
	 * The system prompt of an agent's default config.
	 *
	 * Same keys, in the same order, as the Agents API's default chat runtime.
	 *
	 * @param array<string, mixed> $config Agent default config.
	 *
	 * @return string
	 * @since 1.3.7
	 */
	private function systemPrompt(array $config): string
	{
		foreach (['system_prompt', 'instructions', 'system', 'prompt'] as $key) {
			if (is_string($config[$key] ?? null) && '' !== trim($config[$key])) {
				return sanitize_textarea_field($config[$key]);
			}
		}

		return '';
	}

	/**
	 * The provider/model pair of an agent's default config.
	 *
	 * Both or neither: a model without its provider is dropped.
	 *
	 * @param array<string, mixed> $config Agent default config.
	 *
	 * @return array{provider: string, model: string}
	 * @since 1.3.7
	 */
	private function modelPair(array $config): array
	{
		$provider = '';
		$model    = '';

		foreach (['provider', 'provider_id'] as $key) {
			if (is_string($config[$key] ?? null) && '' !== $config[$key]) {
				$provider = sanitize_text_field($config[$key]);
				break;
			}
		}

		foreach (['model', 'model_id'] as $key) {
			if (is_string($config[$key] ?? null) && '' !== $config[$key]) {
				$model = sanitize_text_field($config[$key]);
				break;
			}
		}

		return '' === $provider ? ['provider' => '', 'model' => ''] : ['provider' => $provider, 'model' => $model];
	}
}
