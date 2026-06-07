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
use WordPress\AiClient\Files\DTO\File;
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
	 * @param AgentConfig                       $agent       The agent configuration.
	 * @param string                            $message     The user message.
	 * @param array<int, array<string, mixed>>  $history     Prior turns as ['role' => ..., 'text' => ..., 'attachments' => [...]].
	 * @param array<int, array<string, string>> $attachments Attachments for the current turn (each ['data' => dataUri, 'mimeType' => ..., 'name' => ...]).
	 *
	 * @return AgentResponse
	 * @since 1.0.0
	 */
	public function chat(AgentConfig $agent, string $message, array $history = [], array $attachments = []): AgentResponse
	{
		$guard = $this->guard($agent->provider);
		if ($guard instanceof WP_Error) {
			return AgentResponse::fromError($guard);
		}

		if ('' === trim($message) && empty($attachments)) {
			return AgentResponse::fromError(
				new WP_Error('empty_message', __('The user message cannot be empty.', 'agent-mod'))
			);
		}

		$siteContext = $agent->autoIncludeSiteContext ? $this->knowledgeResolver->getSiteContext() : [];
		$systemInstruction = $this->promptBuilder->buildSystemInstruction($agent, $siteContext);
		$abilities = $this->abilityResolver->resolve($agent);

		$messages   = $this->mapHistoryToMessages($history);
		$messages[] = new Message(MessageRoleEnum::user(), $this->buildMessageParts($message, $attachments));

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
	 * Ensures AI text generation is available for the request.
	 *
	 * Provider-agnostic: instead of probing a single hard-coded connector, this
	 * asks the WordPress AI Client whether text generation is supported. When a
	 * specific provider is requested it scopes the check to that provider; when
	 * the provider is empty ("auto") it passes if any configured provider
	 * (Google, OpenAI, Anthropic, …) can handle text generation.
	 *
	 * @param string $provider Provider id, or '' for auto-select.
	 *
	 * @return WP_Error|null Null when text generation is available.
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

		if (! function_exists('wp_ai_client_prompt')) {
			return new WP_Error(
				'ai_client_unavailable',
				__('The WordPress AI Client is not available.', 'agent-mod'),
				['status' => 503]
			);
		}

		if (! $this->supportsTextGeneration($provider)) {
			$message = '' === $provider
				? __('No configured AI provider can handle this request. Connect a provider (e.g. Google, OpenAI, or Anthropic) in the AI settings.', 'agent-mod')
				/* translators: %s: provider id. */
				: sprintf(__('The "%s" AI provider is not configured for text generation.', 'agent-mod'), $provider);

			return new WP_Error('ai_not_configured', $message, ['status' => 503]);
		}

		return null;
	}

	/**
	 * Checks whether text generation is supported for the given provider.
	 *
	 * Delegates the capability check to the AI Client builder, which considers
	 * the configured connectors and their credentials. An empty provider lets
	 * the client evaluate every configured provider.
	 *
	 * @param string $provider Provider id, or '' for auto-select.
	 *
	 * @return bool True when text generation is available.
	 * @since 1.0.0
	 */
	private function supportsTextGeneration(string $provider): bool
	{
		try {
			$builder = wp_ai_client_prompt('ping');

			if ('' !== $provider) {
				$builder->using_provider($provider);
			}

			return (bool) $builder->is_supported_for_text_generation();
		} catch (\Throwable $e) {
			return false;
		}
	}

	/**
	 * Maps a plain history array to AI Client Message objects.
	 *
	 * @param array<int, array<string, mixed>> $history Prior turns.
	 *
	 * @return Message[]
	 * @since 1.0.0
	 */
	private function mapHistoryToMessages(array $history): array
	{
		$messages = [];

		foreach ($history as $turn) {
			$text        = isset($turn['text']) ? (string) $turn['text'] : '';
			$attachments = isset($turn['attachments']) && is_array($turn['attachments']) ? $turn['attachments'] : [];

			if ('' === trim($text) && empty($attachments)) {
				continue;
			}

			$role  = isset($turn['role']) ? (string) $turn['role'] : 'user';
			$isUser = ! in_array($role, ['model', 'assistant'], true);

			$messages[] = new Message(
				$isUser ? MessageRoleEnum::user() : MessageRoleEnum::model(),
				$this->buildMessageParts($text, $attachments)
			);
		}

		return $messages;
	}

	/**
	 * Builds the message parts for a turn: a text part plus one file part per
	 * attachment. Invalid attachments are skipped so a malformed upload never
	 * breaks the whole request.
	 *
	 * @param string                            $text        The turn text.
	 * @param array<int, array<string, string>> $attachments Attachments (each ['data' => dataUri, 'mimeType' => ..., 'name' => ...]).
	 *
	 * @return MessagePart[]
	 * @since 1.0.0
	 */
	private function buildMessageParts(string $text, array $attachments): array
	{
		$parts = [];
		$text  = trim($text);

		if ('' !== $text) {
			$parts[] = new MessagePart($text);
		}

		foreach ($attachments as $attachment) {
			if (! is_array($attachment)) {
				continue;
			}

			$file = $this->attachmentToFile($attachment);

			if ($file instanceof File) {
				$parts[] = new MessagePart($file);
			}
		}

		// A message must carry at least one part; fall back to an (empty) text part.
		if (empty($parts)) {
			$parts[] = new MessagePart($text);
		}

		return $parts;
	}

	/**
	 * Converts a sanitized attachment array to a WP AI Client File, or null when
	 * the data cannot be turned into a valid file.
	 *
	 * @param array<string, string> $attachment Attachment data (['data' => dataUri, 'mimeType' => ...]).
	 *
	 * @return File|null
	 * @since 1.0.0
	 */
	private function attachmentToFile(array $attachment): ?File
	{
		$data = isset($attachment['data']) ? (string) $attachment['data'] : '';

		if ('' === $data) {
			return null;
		}

		$mimeType = isset($attachment['mimeType']) && '' !== $attachment['mimeType']
			? (string) $attachment['mimeType']
			: null;

		try {
			return new File($data, $mimeType);
		} catch (\Throwable $e) {
			return null;
		}
	}
}
