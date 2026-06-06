<?php

/**
 * AI Orchestrator service.
 *
 * Stateless engine that turns an agent definition plus a user message into a
 * provider call. It assembles the system instruction (identity + optional site
 * context), resolves the allowed abilities, maps the conversation history to AI
 * Client messages and delegates the tool-calling loop to the adapter.
 *
 * This service does not bind any hooks; it is invoked on-demand and injected via
 * PHP-DI into the REST controller.
 *
 * @package AgentMod
 * @subpackage Services\AI
 * @since 1.0.0
 */

namespace AgentMod\Services\AI;

use WP_Error;
use AgentMod\Services\AI\DTO\AgentConfig;
use AgentMod\Services\AI\DTO\AgentResponse;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;

defined('ABSPATH') || exit;

class AIOrchestratorService
{
	/**
	 * System instruction builder.
	 *
	 * @var PromptBuilder
	 * @since 1.0.0
	 */
	private PromptBuilder $promptBuilder;

	/**
	 * Ability resolver.
	 *
	 * @var AbilityResolver
	 * @since 1.0.0
	 */
	private AbilityResolver $abilityResolver;

	/**
	 * Knowledge resolver.
	 *
	 * @var KnowledgeResolver
	 * @since 1.0.0
	 */
	private KnowledgeResolver $knowledgeResolver;

	/**
	 * WP AI Client adapter.
	 *
	 * @var AIClientAdapter
	 * @since 1.0.0
	 */
	private AIClientAdapter $clientAdapter;

	/**
	 * Constructor (PHP-DI autowired).
	 *
	 * @param PromptBuilder     $promptBuilder     System instruction builder.
	 * @param AbilityResolver   $abilityResolver   Ability resolver.
	 * @param KnowledgeResolver $knowledgeResolver Knowledge resolver.
	 * @param AIClientAdapter   $clientAdapter     WP AI Client adapter.
	 *
	 * @since 1.0.0
	 */
	public function __construct(
		PromptBuilder $promptBuilder,
		AbilityResolver $abilityResolver,
		KnowledgeResolver $knowledgeResolver,
		AIClientAdapter $clientAdapter
	) {
		$this->promptBuilder     = $promptBuilder;
		$this->abilityResolver   = $abilityResolver;
		$this->knowledgeResolver = $knowledgeResolver;
		$this->clientAdapter     = $clientAdapter;
	}

	/**
	 * Runs an agent against a user message.
	 *
	 * @param AgentConfig                       $agent   The agent configuration.
	 * @param string                            $message The user message.
	 * @param array<int, array<string, string>> $history Prior turns as ['role' => ..., 'text' => ...].
	 *
	 * @return AgentResponse
	 * @since 1.0.0
	 */
	public function chat(AgentConfig $agent, string $message, array $history = []): AgentResponse
	{
		$guard = $this->guard($agent->provider);
		if ($guard instanceof WP_Error) {
			return AgentResponse::fromError($guard);
		}

		if ('' === trim($message)) {
			return AgentResponse::fromError(
				new WP_Error('empty_message', __('The user message cannot be empty.', 'agent-mod'))
			);
		}

		$siteContext = $agent->autoIncludeSiteContext ? $this->knowledgeResolver->getSiteContext() : [];
		$systemInstruction = $this->promptBuilder->buildSystemInstruction($agent, $siteContext);
		$abilities = $this->abilityResolver->resolve($agent);

		$messages   = $this->mapHistoryToMessages($history);
		$messages[] = new Message(MessageRoleEnum::user(), [new MessagePart($message)]);

		return $this->clientAdapter->generate(
			$systemInstruction,
			$messages,
			$abilities,
			$agent->provider,
			$agent->model,
			$agent->maxToolCalls
		);
	}

	/**
	 * Ensures AI support and the requested provider connector are available.
	 *
	 * @param string $provider Provider id.
	 *
	 * @return WP_Error|null Null when everything is available.
	 * @since 1.0.0
	 */
	private function guard(string $provider): ?WP_Error
	{
		if (function_exists('wp_supports_ai') && ! wp_supports_ai()) {
			return new WP_Error(
				'ai_not_supported',
				__('AI features are not supported in this environment.', 'agent-mod'),
				['status' => 503]
			);
		}

		if (function_exists('wp_is_connector_registered') && ! wp_is_connector_registered($provider)) {
			return new WP_Error(
				'connector_not_registered',
				/* translators: %s: provider id. */
				sprintf(__('The "%s" AI connector is not registered or configured.', 'agent-mod'), $provider),
				['status' => 503]
			);
		}

		return null;
	}

	/**
	 * Maps a plain history array to AI Client Message objects.
	 *
	 * @param array<int, array<string, string>> $history Prior turns.
	 *
	 * @return Message[]
	 * @since 1.0.0
	 */
	private function mapHistoryToMessages(array $history): array
	{
		$messages = [];

		foreach ($history as $turn) {
			$text = isset($turn['text']) ? (string) $turn['text'] : '';
			if ('' === trim($text)) {
				continue;
			}

			$role  = isset($turn['role']) ? (string) $turn['role'] : 'user';
			$isUser = ! in_array($role, ['model', 'assistant'], true);

			$messages[] = new Message(
				$isUser ? MessageRoleEnum::user() : MessageRoleEnum::model(),
				[new MessagePart($text)]
			);
		}

		return $messages;
	}
}
