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
use AgentMod\Services\AI\Http\ToolCallRepairManager;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\WebSearch;

defined('ABSPATH') || exit;

class AIClientAdapter
{
	/**
	 * Provider tool-call repair manager.
	 *
	 * @var ToolCallRepairManager
	 * @since 1.0.0
	 */
	private ToolCallRepairManager $toolCallRepairs;

	/**
	 * Live tool-call progress store.
	 *
	 * @var ProgressStore
	 * @since 1.1.0
	 */
	private ProgressStore $progressStore;

	/**
	 * Constructor (PHP-DI autowired).
	 *
	 * @param ToolCallRepairManager $toolCallRepairs Provider tool-call repair manager.
	 * @param ProgressStore         $progressStore   Live tool-call progress store.
	 *
	 * @since 1.0.0
	 */
	public function __construct(ToolCallRepairManager $toolCallRepairs, ProgressStore $progressStore)
	{
		$this->toolCallRepairs = $toolCallRepairs;
		$this->progressStore   = $progressStore;
	}

	/**
	 * Generates an agent response, running the agentic tool-calling loop.
	 *
	 * @param string      $systemInstruction Full system instruction.
	 * @param Message[]   $messages          Initial conversation history (incl. the latest user message).
	 * @param WP_Ability[] $abilities         Abilities the model is allowed to call.
	 * @param string      $provider          Provider id.
	 * @param string|null $model             Optional model id.
	 * @param int         $maxToolCalls      Max tool-calling iterations.
	 * @param string      $requestId         Optional client-generated UUID for live progress reporting.
	 * @param array<int, array<string, mixed>> $approvedCalls Tool calls the user has already confirmed
	 *                                                          (each ['name' => string, 'args' => array]).
	 *                                                          Bypasses the confirmation gate once per match.
	 * @param bool        $webSearchEnabled  Whether to enable the provider's native web search tool.
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
		int $maxToolCalls,
		string $requestId = '',
		array $approvedCalls = [],
		bool $webSearchEnabled = false
	): AgentResponse {
		if (! function_exists('wp_ai_client_prompt')) {
			return AgentResponse::fromError(
				new \WP_Error('ai_client_unavailable', __('The WordPress AI Client is not available.', 'agent-mod'))
			);
		}

		// Compensate for provider connectors that drop tool-call metadata across turns.
		$this->toolCallRepairs->register();

		try {
			return $this->runGenerationLoop(
				$systemInstruction,
				$messages,
				$abilities,
				$provider,
				$model,
				$maxToolCalls,
				$requestId,
				$approvedCalls,
				$webSearchEnabled
			);
		} finally {
			$this->toolCallRepairs->unregister();

			// Progress and stop flags are only meaningful while the request is
			// in flight.
			if ('' !== $requestId) {
				$this->progressStore->delete($requestId);
				$this->progressStore->clearStop($requestId);
			}
		}
	}

	/**
	 * Builds the canonical "stopped by the user" error response.
	 *
	 * The frontend aborts its own fetch when the user presses Stop, so this
	 * response usually goes nowhere — its purpose is to end the tool-calling
	 * loop early (no further provider calls or ability executions) and to keep
	 * the stopped turn out of conversation persistence (error responses are
	 * never persisted).
	 *
	 * @return AgentResponse
	 * @since 1.2.0
	 */
	private function stoppedResponse(): AgentResponse
	{
		return AgentResponse::fromError(
			new \WP_Error(
				'agent_mod_request_stopped',
				__('The request was stopped by the user.', 'agent-mod'),
				['status' => 400]
			)
		);
	}

	/**
	 * Runs the agentic tool-calling loop.
	 *
	 * @param string      $systemInstruction Full system instruction.
	 * @param Message[]   $messages          Initial conversation history (incl. the latest user message).
	 * @param WP_Ability[] $abilities         Abilities the model is allowed to call.
	 * @param string      $provider          Provider id.
	 * @param string|null $model             Optional model id.
	 * @param int         $maxToolCalls      Max tool-calling iterations.
	 * @param string      $requestId         Optional client-generated UUID for live progress reporting.
	 * @param array<int, array<string, mixed>> $approvedCalls Tool calls the user has already confirmed;
	 *                                                          each is exempted from the confirmation gate once.
	 * @param bool        $webSearchEnabled  Whether to enable the provider's native web search tool.
	 *
	 * @return AgentResponse
	 * @since 1.0.0
	 */
	private function runGenerationLoop(
		string $systemInstruction,
		array $messages,
		array $abilities,
		string $provider,
		?string $model,
		int $maxToolCalls,
		string $requestId = '',
		array $approvedCalls = [],
		bool $webSearchEnabled = false
	): AgentResponse {
		$resolver   = new WP_AI_Client_Ability_Function_Resolver(...$abilities);
		$history    = $messages;
		$toolCalls  = [];
		$tokenUsage = [];

		for ($i = 0; $i < $maxToolCalls; $i++) {
			// The user may press Stop at any time; the flag is written by the
			// chat-stop endpoint from a separate request. Model calls already
			// in flight cannot be interrupted, so the loop bails at the next
			// iteration boundary instead.
			if ('' !== $requestId && $this->progressStore->isStopRequested($requestId)) {
				return $this->stoppedResponse();
			}

			// Only the first pass has no tool activity to show yet, so report
			// the neutral "thinking" state (bare spinner). On later passes the
			// previous "running_tool" status is intentionally left in place
			// while the model reasons over the tool results: the model call
			// (generate_result) dominates each iteration, and tool execution
			// alone is far too brief for the ~1.2s poll interval to ever catch,
			// so resetting to "thinking" here would make the tool status
			// invisible in practice.
			if (0 === $i) {
				$this->reportProgress($requestId, 'thinking', '', $toolCalls);
			}

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

			// Enable the provider's native web search when requested.
			if ($webSearchEnabled) {
				$this->applyWebSearch($builder, $provider);
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

			// Intercept write operations before execution to allow user confirmation.
			// Calls already approved via the confirm-action endpoint are exempted once.
			$requestedCalls = $this->extractToolCalls($message);
			$writeCalls     = $this->filterWriteToolCalls($requestedCalls, $approvedCalls);

			if (! empty($writeCalls)) {
				return AgentResponse::pendingConfirmation(
					wp_generate_uuid4(),
					$writeCalls,
					$history,
					$tokenUsage
				);
			}

			// A stop pressed while the model was generating must also prevent
			// the tools it just requested from executing.
			if ('' !== $requestId && $this->progressStore->isStopRequested($requestId)) {
				return $this->stoppedResponse();
			}

			// Record the requested tool calls, then execute them and feed responses back.
			$this->reportProgress(
				$requestId,
				'running_tool',
				implode(', ', array_column($requestedCalls, 'name')),
				$toolCalls,
				$requestedCalls
			);

			$toolCalls = array_merge($toolCalls, $requestedCalls);
			$history[] = $resolver->execute_abilities($message);

			// Deliberately keep the "running_tool" status set here (no reset to
			// "thinking"): it stays visible while the next generate_result()
			// runs, which is the only window long enough for the client poll to
			// reliably catch the tool-call feedback.
		}

		// Tool-call limit reached: do a final pass without abilities to force a
		// text answer — unless the user stopped the request meanwhile.
		if ('' !== $requestId && $this->progressStore->isStopRequested($requestId)) {
			return $this->stoppedResponse();
		}

		$finalBuilder = wp_ai_client_prompt()
			->with_history(...$history)
			->using_system_instruction($systemInstruction);

		if ('' !== $provider) {
			$finalBuilder->using_provider($provider);

			if ($model) {
				$finalBuilder->using_model_preference([$provider, $model]);
			}
		}

		// Keep web search available on the forced-text final pass so the answer
		// can still ground on live results even after the tool-call limit.
		if ($webSearchEnabled) {
			$this->applyWebSearch($finalBuilder, $provider);
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
	 * Enables the provider's native web search on the given prompt builder.
	 *
	 * The single WebSearch config is translated by each connector into its own
	 * server tool (OpenAI web_search, Google googleSearch, …), so no
	 * provider-specific payload is built here.
	 *
	 * Google/Gemini exception: when a built-in server tool (googleSearch) is
	 * combined with function calling (our abilities), the Gemini API rejects the
	 * request with "enable tool_config.include_server_side_tool_invocations".
	 * The Google connector does not expose that flag, so we pass it through the
	 * SDK's sanctioned customOptions channel, which the connector splices
	 * verbatim into the request body (no third-party code is modified). This is
	 * scoped to Google only — sending the flag to other providers would add an
	 * unknown parameter and break them.
	 *
	 * @param WP_AI_Client_Prompt_Builder $builder  WP AI Client prompt builder.
	 * @param string                      $provider Provider id, or '' for auto-select.
	 *
	 * @return void
	 * @since 1.0.6
	 */
	private function applyWebSearch($builder, string $provider): void
	{
		$builder->using_web_search(new WebSearch());

		if (in_array($provider, ['google', 'gemini'], true)) {
			$config = new ModelConfig();
			$config->setCustomOption('toolConfig', ['includeServerSideToolInvocations' => true]);
			$builder->using_model_config($config);
		}
	}

	/**
	 * Writes a progress snapshot for the polling endpoint. No-op without a request id.
	 *
	 * @param string                            $requestId     Client-generated UUID, or '' to skip.
	 * @param string                            $status        'thinking' or 'running_tool'.
	 * @param string                            $currentTool   Names of the tools currently executing.
	 * @param array<int, array<string, mixed>>  $executedCalls Tool calls completed so far.
	 * @param array<int, array<string, mixed>>  $runningCalls  Tool calls currently executing.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	private function reportProgress(
		string $requestId,
		string $status,
		string $currentTool,
		array $executedCalls,
		array $runningCalls = []
	): void {
		if ('' === $requestId) {
			return;
		}

		$mapCalls = static function (array $calls, string $callStatus): array {
			return array_map(
				static function (array $call) use ($callStatus): array {
					return [
						'name'   => (string) ($call['name'] ?? ''),
						'args'   => $call['args'] ?? [],
						'status' => $callStatus,
					];
				},
				$calls
			);
		};

		$this->progressStore->save($requestId, [
			'status'        => $status,
			'currentTool'   => $currentTool,
			'executedCalls' => array_merge(
				$mapCalls($executedCalls, 'done'),
				$mapCalls($runningCalls, 'running')
			),
		]);
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
	 * Filters tool calls that require user confirmation before execution.
	 *
	 * Other plugins register write abilities via the agent_mod_ability_requires_confirmation
	 * filter, returning true for ability names that must not be executed silently. A call
	 * matching an already-approved entry (by ability name) is exempted once and removed
	 * from $approvedCalls, so re-requesting the same ability later still requires confirmation.
	 *
	 * @param array<int, array<string, mixed>> $toolCalls     Extracted tool calls.
	 * @param array<int, array<string, mixed>> $approvedCalls Tool calls already confirmed by the user.
	 *
	 * @return array<int, array<string, mixed>> Only the calls that still need confirmation.
	 * @since 1.0.0
	 */
	private function filterWriteToolCalls(array $toolCalls, array &$approvedCalls = []): array
	{
		return array_values(
			array_filter(
				$toolCalls,
				static function (array $call) use (&$approvedCalls): bool {
					if (! (bool) apply_filters('agent_mod_ability_requires_confirmation', false, $call['name'])) {
						return false;
					}

					foreach ($approvedCalls as $index => $approved) {
						if (($approved['name'] ?? '') === $call['name']) {
							unset($approvedCalls[$index]);
							return false;
						}
					}

					return true;
				}
			)
		);
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
