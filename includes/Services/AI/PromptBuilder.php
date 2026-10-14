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
use AgentMod\Services\AskUserAbilityService;

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
	 * Execute mode tells the agent to finish the work in the same turn instead
	 * of announcing it — the loop ends on any reply without a tool call, so a
	 * "here is what I will do" message stops the run. Ask/plan modes steer the
	 * agent away from making changes (write abilities are also removed by the
	 * AbilityResolver).
	 *
	 * Questions are never ruled out. On an interactive channel they go through
	 * the ask-user tool (a form the user answers, after which the run carries
	 * on); elsewhere nobody would answer, so the agent defaults instead.
	 *
	 * @param AgentConfig $agent The agent configuration.
	 *
	 * @return string
	 * @since 1.1.0
	 */
	private function buildModeDirective(AgentConfig $agent): string
	{
		$askUser = $agent->interactive
			? sprintf(
				/* translators: %s: ask-user ability name. */
				__('If a question really is needed, ask it with the %s tool (all questions in one call) instead of writing it as plain text, then continue once it is answered.', 'agent-mod'),
				AskUserAbilityService::NAME
			)
			: '';

		if ('ask' === $agent->mode) {
			$directive = __(
				'You are in Ask mode: answer questions and explain. You may read site data with the available tools, but you must not create, modify, or delete anything. If the user asks for a change, explain what you would do and suggest switching to Execute mode.',
				'agent-mod'
			);
		} elseif ('plan' === $agent->mode) {
			$directive = __(
				'You are in Plan mode: produce a clear, step-by-step plan of what you WOULD do, including which tools you would call and with what arguments. You may read site data with the available tools, but do not execute any changes.',
				'agent-mod'
			);

			// On an interactive channel the plan is handed over through
			// present-plan, which opens the review modal; elsewhere the user
			// has to be told how to carry it out.
			$directive .= ' ' . ($agent->interactive
				? sprintf(
					/* translators: %s: present-plan ability name. */
					__('When the plan is complete, deliver it by calling the %s tool with the full plan in Markdown instead of writing it as a reply. The user then gets options to approve it, have it reviewed or request changes, so do not tell them to switch modes or ask whether to proceed. If the user only asked a question, answer it normally without that tool.', 'agent-mod'),
					AskUserAbilityService::PRESENT_PLAN
				)
				: __('Suggest switching to Execute mode to carry the plan out.', 'agent-mod'));

			// Plan mode is where open choices get settled, so the bar for asking
			// is deliberately lower here than in Execute mode.
			if ($agent->interactive) {
				$askUser = sprintf(
					/* translators: %s: ask-user ability name. */
					__('Plan mode is where decisions are settled together with the user. Before you write the plan, if the request leaves open choices that shape the result — for example which fields, options or sections to include, names and slugs, scope or structure — ask about them with the %s tool, even when the user left the choice to you. Read the site data you need first so your options fit the site, offer your own proposals as the options with your recommendation marked, and ask everything in one call. Then write the plan from the answers. Skip the questions only when the request leaves nothing open.', 'agent-mod'),
					AskUserAbilityService::NAME
				);
			}
		} else {
			$directive = __(
				'You are in Execute mode: carry the request out with the tools in this same turn. Do not describe what you are about to do and then stop — call the tools. Write actions pass through the system\'s own approval step, so call them directly instead of asking for permission in chat; only a tool description that explicitly says to confirm first overrides this.',
				'agent-mod'
			);

			$askUser = $agent->interactive
				? __('Ask the user only when a missing detail would materially change the outcome and cannot be found with the tools or reasonably defaulted.', 'agent-mod') . ' ' . $askUser
				: __('When a detail is missing, choose a sensible default and state it in your answer.', 'agent-mod');
		}

		if ('' !== $askUser) {
			$directive .= ' ' . $askUser;
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
