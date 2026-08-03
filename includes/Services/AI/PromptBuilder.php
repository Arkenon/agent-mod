<?php

/**
 * System instruction builder.
 *
 * Combines the agent definition (role, goal, personality, optional explicit
 * system prompt) with optional site context into a single system instruction
 * string passed to the AI provider.
 *
 * Personality traits are printed as a comma-separated line; any trait that
 * arrived with a description (see AgentConfig::$personalityDetails) also gets a
 * bullet spelling out what it means.
 *
 * @package AgentMod
 * @subpackage Services\AI
 * @since 1.0.0
 */

namespace AgentMod\Services\AI;

use AgentMod\Services\AI\DTO\AgentConfig;

defined('ABSPATH') || exit;

class PromptBuilder
{
	/**
	 * Most per-trait definitions spelled out in the personality section.
	 *
	 * @var int
	 * @since 1.1.0
	 */
	private const MAX_PERSONALITY_BRIEFS = 10;

	/**
	 * Builds the full system instruction for an agent.
	 *
	 * @param AgentConfig            $agent       The agent configuration.
	 * @param array<string, string>  $siteContext Site context (may be empty).
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function buildSystemInstruction(AgentConfig $agent, array $siteContext = []): string
	{
		$sections = [];

		// Explicit override takes precedence and is prepended verbatim.
		$identity = [];
		if ('' !== trim($agent->name)) {
			/* translators: %s: agent name. */
			$identity[] = sprintf(__('You are %s.', 'agent-mod'), $agent->name);
		}
		if ('' !== trim($agent->role)) {
			/* translators: %s: agent role. */
			$identity[] = sprintf(__('Your role: %s.', 'agent-mod'), $agent->role);
		}
		if ('' !== trim($agent->goal)) {
			/* translators: %s: agent goal. */
			$identity[] = sprintf(__('Your goal: %s.', 'agent-mod'), $agent->goal);
		}
		if (! empty($identity)) {
			$sections[] = implode(' ', $identity);
		}
		if (! empty($agent->personality)) {
			$traits = implode(', ', array_map('trim', $agent->personality));
			/* translators: %s: comma-separated personality traits. */
			$personality = sprintf(__('Personality and tone: %s.', 'agent-mod'), $traits);

			// Traits that came with a definition get spelled out underneath, so
			// the model works from what the trait was meant to mean rather than
			// from its own reading of the adjective. Traits without one still
			// appear in the line above, exactly as before.
			$briefs = $this->buildPersonalityBriefs($agent);
			if ('' !== $briefs) {
				$personality .= "\n" . $briefs;
			}

			$sections[] = $personality;
		}

		if (! empty($siteContext)) {
			$sections[] = $this->formatSiteContext($siteContext);
		}

		// User-managed base prompt (settings), defaulting to the built-in directives.
		$base = trim($agent->baseSystemPrompt);
		if ('' !== $base) {
			$sections[] = $base;
		}

		$modeDirective = $this->buildModeDirective($agent);
		if ('' !== $modeDirective) {
			$sections[] = $modeDirective;
		}

		// Earlier turns replay their tool activity as compact text digests (see
		// AIOrchestratorService::mapHistoryToMessages()); without this directive
		// the model tends to re-fetch data it already has.
		$sections[] = __('Earlier tool results are summarized in the conversation history as "[Tools used this turn]" blocks. Trust those summaries: do not re-call the same read tool with the same arguments to retrieve data you already have.', 'agent-mod');

		$emphasized = $this->resolveEmphasized($agent);
		if (! empty($emphasized)) {
			$sections[] = sprintf(
				/* translators: %s: comma-separated ability (tool) names. */
				__('The user has highlighted these tools for this request. Prefer using them when they can fulfil the task: %s. All other tools remain available.', 'agent-mod'),
				implode(', ', $emphasized)
			);
		}

		$instruction = implode("\n\n", array_filter($sections));

		return (string) apply_filters('agent_mod_system_prompt', $instruction, $agent);
	}

	/**
	 * Builds the per-trait definition lines for the personality section.
	 *
	 * Returns an empty string when no trait carries a description, which is the
	 * case for the settings-configured defaults — the personality section then
	 * looks exactly as it always has.
	 *
	 * Order follows $agent->personality rather than the details array so the
	 * bullets match the comma-separated line above them.
	 *
	 * @param AgentConfig $agent The agent configuration.
	 *
	 * @return string
	 * @since 1.1.0
	 */
	private function buildPersonalityBriefs(AgentConfig $agent): string
	{
		if (empty($agent->personalityDetails)) {
			return '';
		}

		$lines = [];

		foreach ($agent->personality as $label) {
			$label       = trim($label);
			$description = $agent->personalityDetails[$label] ?? '';

			if ('' === $description) {
				continue;
			}

			$lines[] = sprintf('- %s: %s', $label, $description);

			// A tone brief that runs past this is no longer a tone brief, and it
			// is re-sent on every request. The labels are all still listed above.
			if (count($lines) >= self::MAX_PERSONALITY_BRIEFS) {
				break;
			}
		}

		return implode("\n", $lines);
	}

	/**
	 * Builds the directive for the active interaction mode.
	 *
	 * Execute mode adds nothing; ask/plan modes steer the agent away from
	 * making changes (write abilities are also removed by the AbilityResolver).
	 *
	 * @param AgentConfig $agent The agent configuration.
	 *
	 * @return string
	 * @since 1.1.0
	 */
	private function buildModeDirective(AgentConfig $agent): string
	{
		$directive = '';

		if ('ask' === $agent->mode) {
			$directive = __(
				'You are in Ask mode: answer questions and explain. You may read site data with the available tools, but you must not create, modify, or delete anything. If the user asks for a change, explain what you would do and suggest switching to Execute mode.',
				'agent-mod'
			);
		} elseif ('plan' === $agent->mode) {
			$directive = __(
				'You are in Plan mode: produce a clear, step-by-step plan of what you WOULD do, including which tools you would call and with what arguments. You may read site data with the available tools, but do not execute any changes. Suggest switching to Execute mode to carry the plan out.',
				'agent-mod'
			);
		}

		return (string) apply_filters('agent_mod_mode_directive', $directive, $agent->mode, $agent);
	}

	/**
	 * Validates the user-mentioned ability names against the registry.
	 *
	 * Unregistered names are dropped so nothing user-controlled is injected
	 * into the system instruction; the list is deduped and capped at 10. In
	 * ask/plan modes write abilities are dropped too, mirroring the tool list
	 * resolved for those modes. The registry is indexed once via
	 * wp_get_abilities() instead of calling wp_get_ability() per name, so
	 * unregistered names (e.g. a select field's placeholder value) are
	 * silently dropped instead of raising a _doing_it_wrong notice.
	 *
	 * @param AgentConfig $agent The agent configuration.
	 *
	 * @return string[] Valid ability names.
	 * @since 1.1.0
	 */
	private function resolveEmphasized(AgentConfig $agent): array
	{
		if (empty($agent->emphasizedAbilities) || ! function_exists('wp_get_abilities')) {
			return [];
		}

		$registry = [];
		foreach (wp_get_abilities() as $ability) {
			$registry[$ability->get_name()] = $ability;
		}

		$readonlyOnly = 'execute' !== $agent->mode;
		$names        = [];

		foreach (array_unique($agent->emphasizedAbilities) as $name) {
			$name = sanitize_text_field((string) $name);

			if ('' === $name) {
				continue;
			}

			$ability = $registry[$name] ?? null;

			if (null === $ability) {
				continue;
			}

			if ($readonlyOnly) {
				$meta = (array) $ability->get_meta();

				if (true !== ($meta['annotations']['readonly'] ?? false)) {
					continue;
				}
			}

			$names[] = $name;

			if (count($names) >= 10) {
				break;
			}
		}

		return $names;
	}

	/**
	 * Formats the site context into a readable block.
	 *
	 * @param array<string, string> $siteContext Site context.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function formatSiteContext(array $siteContext): string
	{
		$lines = [__('Current site context:', 'agent-mod')];

		$labels = [
			'site_name'        => __('Site name', 'agent-mod'),
			'site_description' => __('Tagline', 'agent-mod'),
			'site_url'         => __('URL', 'agent-mod'),
			'admin_email'      => __('Admin email', 'agent-mod'),
			'wp_version'       => __('WordPress version', 'agent-mod'),
			'language'         => __('Language', 'agent-mod'),
		];

		foreach ($siteContext as $key => $value) {
			if ('' === (string) $value) {
				continue;
			}
			$label   = $labels[$key] ?? $key;
			$lines[] = sprintf('- %s: %s', $label, $value);
		}

		return implode("\n", $lines);
	}
}
