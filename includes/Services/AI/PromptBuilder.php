<?php

/**
 * System instruction builder.
 *
 * Combines the agent definition (role, goal, personality, optional explicit
 * system prompt) with optional site context into a single system instruction
 * string passed to the AI provider.
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
		if ('' !== trim($agent->systemPrompt)) {
			$sections[] = trim($agent->systemPrompt);
		}

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
			$sections[] = sprintf(__('Personality and tone: %s.', 'agent-mod'), $traits);
		}

		if (! empty($siteContext)) {
			$sections[] = $this->formatSiteContext($siteContext);
		}

		$sections[] = __(
			'Never directly execute destructive operations (delete, trash, erase, or remove content or users). Instead, list the affected items and ask the user for explicit confirmation before any such action is taken. If no relevant tool exists to list them, describe what would be affected and request confirmation.',
			'agent-mod'
		);

		$sections[] = __(
			'When a user request is ambiguous or missing required details, ask one short clarifying question before calling any tools. Do not assume intent.',
			'agent-mod'
		);

		$sections[] = __(
			'When a tool can answer the user accurately, call it before responding. Base your answers on tool results rather than guessing.',
			'agent-mod'
		);

		return implode("\n\n", array_filter($sections));
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
