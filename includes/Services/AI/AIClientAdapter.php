<?php

/**
 * WP AI Client adapter.
 *
 * Single layer wrapping the native WordPress AI Client. It isolates the rest of
 * the plugin from the WP AI Client API and runs the multi-step (agentic)
 * tool-calling loop: send the prompt, execute any requested abilities, feed the
 * responses back, and repeat until the model returns a plain text answer or the
 * tool-call limit is reached.
 *
 * @package AgentMod
 * @subpackage Services\AI
 * @since 1.0.0
 */

namespace AgentMod\Services\AI;

use Throwable;
use WP_AI_Client_Ability_Function_Resolver;
use WP_Ability;
use AgentMod\Services\AI\DTO\AgentResponse;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Tools\DTO\FunctionCall;

defined('ABSPATH') || exit;

class AIClientAdapter
{
	/**
	 * Generates an agent response, running the agentic tool-calling loop.
	 *
	 * @param string      $systemInstruction Full system instruction.
	 * @param Message[]   $messages          Initial conversation history (incl. the latest user message).
	 * @param WP_Ability[] $abilities         Abilities the model is allowed to call.
	 * @param string      $provider          Provider id.
	 * @param string|null $model             Optional model id.
	 * @param int         $maxToolCalls      Max tool-calling iterations.
	 *
	 * @return AgentResponse
	 * @since 1.0.0
	 */
	public function generate(
		string $systemInstruction,
		array $messages,
		array $abilities,
		string $provider,
		?string $model,
		int $maxToolCalls
	): AgentResponse {
		if (! function_exists('wp_ai_client_prompt')) {
			return AgentResponse::fromError(
				new \WP_Error('ai_client_unavailable', __('The WordPress AI Client is not available.', 'agent-mod'))
			);
		}

		$resolver   = new WP_AI_Client_Ability_Function_Resolver(...$abilities);
		$history    = $messages;
		$toolCalls  = [];
		$tokenUsage = [];

		for ($i = 0; $i < $maxToolCalls; $i++) {
			$builder = wp_ai_client_prompt()
				->with_history(...$history)
				->using_system_instruction($systemInstruction);

			if ('' !== $provider) {
				$builder->using_provider($provider);

				if ($model) {
					$builder->using_model_preference([$provider, $model]);
				}
			}

			if (! empty($abilities)) {
				$builder->using_abilities(...$abilities);
			}

			$result = $builder->generate_result();

			if (is_wp_error($result)) {
				return AgentResponse::fromError($result);
			}

			$candidates = $result->getCandidates();
			if (empty($candidates)) {
				return AgentResponse::fromError(
					new \WP_Error('ai_no_candidates', __('The AI provider returned no candidates.', 'agent-mod'))
				);
			}

			$message    = $candidates[0]->getMessage();
			$history[]  = $message;
			$tokenUsage = $this->extractTokenUsage($result);

			// No tool calls -> the model produced a final answer.
			if (! $resolver->has_ability_calls($message)) {
				return new AgentResponse(
					$this->extractText($result),
					$toolCalls,
					$history,
					$tokenUsage
				);
			}

			// Record the requested tool calls, then execute them and feed responses back.
			$toolCalls = array_merge($toolCalls, $this->extractToolCalls($message));
			$history[] = $resolver->execute_abilities($message);
		}

		// Tool-call limit reached: do a final pass without abilities to force a text answer.
		$finalBuilder = wp_ai_client_prompt()
			->with_history(...$history)
			->using_system_instruction($systemInstruction);

		if ('' !== $provider) {
			$finalBuilder->using_provider($provider);

			if ($model) {
				$finalBuilder->using_model_preference([$provider, $model]);
			}
		}

		$finalResult = $finalBuilder->generate_result();

		if (is_wp_error($finalResult)) {
			return AgentResponse::fromError($finalResult);
		}

		$finalCandidates = $finalResult->getCandidates();
		if (! empty($finalCandidates)) {
			$history[] = $finalCandidates[0]->getMessage();
		}

		return new AgentResponse(
			$this->extractText($finalResult),
			$toolCalls,
			$history,
			$this->extractTokenUsage($finalResult)
		);
	}

	/**
	 * Safely extracts text from a result (toText() throws when there is none).
	 *
	 * @param GenerativeAiResult $result The result.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function extractText(GenerativeAiResult $result): string
	{
		try {
			return $result->toText();
		} catch (Throwable $e) {
			return '';
		}
	}

	/**
	 * Extracts requested tool calls from a model message.
	 *
	 * @param Message $message The model message.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.0.0
	 */
	private function extractToolCalls(Message $message): array
	{
		$calls = [];

		foreach ($message->getParts() as $part) {
			if (! $part->getType()->isFunctionCall()) {
				continue;
			}

			$functionCall = $part->getFunctionCall();
			if (! $functionCall instanceof FunctionCall) {
				continue;
			}

			$functionName = (string) $functionCall->getName();

			$calls[] = [
				'name' => WP_AI_Client_Ability_Function_Resolver::function_name_to_ability_name($functionName),
				'args' => $functionCall->getArgs() ?? [],
			];
		}

		return $calls;
	}

	/**
	 * Extracts token usage as a plain array.
	 *
	 * @param GenerativeAiResult $result The result.
	 *
	 * @return array<string, int>
	 * @since 1.0.0
	 */
	private function extractTokenUsage(GenerativeAiResult $result): array
	{
		$usage = $result->getTokenUsage();

		return [
			'promptTokens'     => $usage->getPromptTokens(),
			'completionTokens' => $usage->getCompletionTokens(),
			'totalTokens'      => $usage->getTotalTokens(),
		];
	}
}
