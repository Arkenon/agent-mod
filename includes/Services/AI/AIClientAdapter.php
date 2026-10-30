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
use AgentMod\Common\Constants;
use WP_AI_Client_Ability_Function_Resolver;
use WP_Ability;
use AgentMod\Services\AI\DTO\AgentResponse;
use AgentMod\Services\AI\Http\HttpTimeoutManager;
use AgentMod\Services\AI\Http\ToolCallRepairManager;
use AgentMod\Services\AskUserAbilityService;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use WordPress\AiClient\Tools\DTO\WebSearch;
use WP_AI_Client_Prompt_Builder;

defined('ABSPATH') || exit;

class AIClientAdapter
{
	/**
	 * How many times one read-only call may run with identical arguments in a
	 * single run before further repeats are answered with an error.
	 *
	 * @var int
	 * @since 1.3.3
	 */
	private const MAX_IDENTICAL_READS = 2;

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
	 * AI provider HTTP timeout manager.
	 *
	 * @var HttpTimeoutManager
	 * @since 1.1.5
	 */
	private HttpTimeoutManager $httpTimeouts;

	/**
	 * In-loop history compactor.
	 *
	 * @var HistoryCompactor
	 * @since 1.1.5
	 */
	private HistoryCompactor $historyCompactor;

	/**
	 * Ask-user ability service (question normalization).
	 *
	 * @var AskUserAbilityService
	 * @since 1.3.1
	 */
	private AskUserAbilityService $askUser;

	/**
	 * Constructor (PHP-DI autowired).
	 *
	 * @param ToolCallRepairManager $toolCallRepairs  Provider tool-call repair manager.
	 * @param ProgressStore         $progressStore    Live tool-call progress store.
	 * @param HttpTimeoutManager    $httpTimeouts     AI provider HTTP timeout manager.
	 * @param HistoryCompactor      $historyCompactor In-loop history compactor.
	 * @param AskUserAbilityService $askUser          Ask-user ability service.
	 *
	 * @since 1.0.0
	 */
	public function __construct(
		ToolCallRepairManager $toolCallRepairs,
		ProgressStore $progressStore,
		HttpTimeoutManager $httpTimeouts,
		HistoryCompactor $historyCompactor,
		AskUserAbilityService $askUser
	) {
		$this->toolCallRepairs  = $toolCallRepairs;
		$this->progressStore    = $progressStore;
		$this->httpTimeouts     = $httpTimeouts;
		$this->historyCompactor = $historyCompactor;
		$this->askUser          = $askUser;
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
	 * @param string[]    $autoApprovedAbilities Ability names the user has approved for the whole
	 *                                           session ('*' approves every write); these skip the
	 *                                           confirmation gate entirely.
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
		array $autoApprovedAbilities = [],
		bool $webSearchEnabled = false
	): AgentResponse {
		if (! function_exists('wp_ai_client_prompt')) {
			return AgentResponse::fromError(
				new \WP_Error('ai_client_unavailable', __('The WordPress AI Client is not available.', 'agent-mod'))
			);
		}

		// Compensate for provider connectors that drop tool-call metadata across turns.
		$this->toolCallRepairs->register();
		$this->httpTimeouts->register();

		try {
			return $this->runGenerationLoop(
				$systemInstruction,
				$messages,
				$abilities,
				$provider,
				$model,
				$maxToolCalls,
				$requestId,
				$autoApprovedAbilities,
				$webSearchEnabled
			);
		} finally {
			$this->toolCallRepairs->unregister();
			$this->httpTimeouts->unregister();

			// Progress and stop flags are only meaningful while the request is
			// in flight.
			if ('' !== $requestId) {
				$this->progressStore->delete($requestId);
				$this->progressStore->clearStop($requestId);
			}
		}
	}

	/**
	 * Resumes a run that paused for user confirmation.
	 *
	 * The history must end with the paused model message — the one whose
	 * function calls the user saw in the confirmation dialog. On approval those
	 * exact calls are executed (no re-prompting, so the model cannot come back
	 * with different arguments) and their real function responses are appended
	 * to the history; the generation loop then continues the model's own plan.
	 * On decline, each call receives a synthetic "declined" function response so
	 * the model can acknowledge gracefully instead of the request being dropped.
	 * On answer (the run paused on an ask-user call), that call receives the
	 * user's answers as its response; any other call batched next to it is
	 * returned unexecuted so the model calls it again with the answers in hand.
	 *
	 * @param string      $systemInstruction     Full system instruction.
	 * @param Message[]   $messages              History ending with the paused model message.
	 * @param WP_Ability[] $abilities             Abilities the model is allowed to call.
	 * @param string      $provider              Provider id.
	 * @param string|null $model                 Optional model id.
	 * @param int         $maxToolCalls          Max tool-calling iterations.
	 * @param string      $requestId             Optional client-generated UUID for live progress reporting.
	 * @param string[]    $autoApprovedAbilities Ability names approved for the whole session.
	 * @param bool        $webSearchEnabled      Whether to enable the provider's native web search tool.
	 * @param array<int, array<string, mixed>>  $executedCalls Calls already executed in earlier legs of this chain.
	 * @param string      $decision              'approve', 'decline' or 'answer'.
	 * @param array<string, mixed> $answerPayload Function response for the ask-user call ('answer' only).
	 *
	 * @return AgentResponse
	 * @since 1.1.0
	 */
	public function resume(
		string $systemInstruction,
		array $messages,
		array $abilities,
		string $provider,
		?string $model,
		int $maxToolCalls,
		string $requestId,
		array $autoApprovedAbilities,
		bool $webSearchEnabled,
		array $executedCalls,
		string $decision,
		array $answerPayload = []
	): AgentResponse {
		if (! function_exists('wp_ai_client_prompt')) {
			return AgentResponse::fromError(
				new \WP_Error('ai_client_unavailable', __('The WordPress AI Client is not available.', 'agent-mod'))
			);
		}

		$lastMessage = end($messages);

		$pendingCalls = $lastMessage instanceof Message ? $this->extractToolCalls($lastMessage) : [];

		if (empty($pendingCalls)) {
			return AgentResponse::fromError(
				new \WP_Error(
					'corrupt_state',
					__('The pending confirmation state is invalid.', 'agent-mod'),
					['status' => 500]
				)
			);
		}

		// Compensate for provider connectors that drop tool-call metadata across turns.
		$this->toolCallRepairs->register();
		$this->httpTimeouts->register();

		try {
			if ('answer' === $decision) {
				// The run paused on the first pausing call (ask-user or
				// request-approval), exactly as the generation loop picked it.
				$pausedName = (string) ($this->findAskUserCall($pendingCalls)['name'] ?? AskUserAbilityService::NAME);
				$responses  = $this->buildAnswerResponses($lastMessage, $answerPayload, $pausedName);
				$messages[] = $responses;

				// Only the answered call goes into the chain's executed list; the
				// calls returned unexecuted will be requested again.
				$askCalls = array_values(array_filter($pendingCalls, static function (array $call) use ($pausedName): bool {
					return $pausedName === (string) ($call['name'] ?? '');
				}));
				$executedCalls = array_merge(
					$executedCalls,
					$this->attachResultDigests($askCalls, $this->onlyAskUserResponses($responses, $pausedName))
				);
			} elseif ('approve' === $decision) {
				$this->reportProgress(
					$requestId,
					'running_tool',
					implode(', ', array_column($pendingCalls, 'name')),
					$executedCalls,
					$pendingCalls
				);

				$resolver      = new WP_AI_Client_Ability_Function_Resolver(...$abilities);
				$resultMessage = $this->executeAbilities($resolver, $lastMessage);
				$messages[]    = $resultMessage;
				$executedCalls = array_merge($executedCalls, $this->attachResultDigests($pendingCalls, $resultMessage));
			} else {
				$messages[] = $this->buildDeclinedResponses($lastMessage);
			}

			return $this->runGenerationLoop(
				$systemInstruction,
				$messages,
				$abilities,
				$provider,
				$model,
				$maxToolCalls,
				$requestId,
				$autoApprovedAbilities,
				$webSearchEnabled,
				$executedCalls
			);
		} finally {
			$this->toolCallRepairs->unregister();
			$this->httpTimeouts->unregister();

			if ('' !== $requestId) {
				$this->progressStore->delete($requestId);
				$this->progressStore->clearStop($requestId);
			}
		}
	}

	/**
	 * Builds the function-response message for declined tool calls.
	 *
	 * Every function call of the paused model message gets a response marking it
	 * as declined; providers require a response for each outstanding call before
	 * the conversation can continue.
	 *
	 * @param Message $message The paused model message.
	 *
	 * @return Message
	 * @since 1.1.0
	 */
	private function buildDeclinedResponses(Message $message): Message
	{
		return $this->buildSyntheticResponses(
			$message,
			static function (): array {
				return [
					'declined' => true,
					'error'    => __('The user declined this action. Do not retry it; acknowledge and ask how to proceed.', 'agent-mod'),
				];
			}
		);
	}

	/**
	 * Builds the function-response message for a paused ask-user or
	 * request-approval call.
	 *
	 * The paused call receives the user's answers. Any other call the model
	 * batched into the same message is answered as "not executed", because it
	 * was planned before the answers were known.
	 *
	 * @param Message              $message       The paused model message.
	 * @param array<string, mixed> $answerPayload The paused call's function response.
	 * @param string               $pausedName    Ability name of the paused call.
	 *
	 * @return Message
	 * @since 1.3.1
	 */
	private function buildAnswerResponses(Message $message, array $answerPayload, string $pausedName = AskUserAbilityService::NAME): Message
	{
		return $this->buildSyntheticResponses(
			$message,
			static function (string $abilityName) use ($answerPayload, $pausedName): array {
				if ($pausedName === $abilityName) {
					return $answerPayload;
				}

				return [
					'not_executed' => true,
					'error'        => __('Not executed: it was requested together with questions to the user. Call it again if it is still needed after the answers.', 'agent-mod'),
				];
			}
		);
	}

	/**
	 * Answers every function call of a model message without executing it.
	 *
	 * Providers require a response for each outstanding call before the
	 * conversation can continue; $payloadFor decides each call's response from
	 * its ability name.
	 *
	 * @param Message  $message    The paused model message.
	 * @param callable $payloadFor Receives the ability name, returns the response payload.
	 *
	 * @return Message
	 * @since 1.3.1
	 */
	private function buildSyntheticResponses(Message $message, callable $payloadFor): Message
	{
		$parts = [];

		foreach ($message->getParts() as $part) {
			if (! $part->getType()->isFunctionCall()) {
				continue;
			}

			$functionCall = $part->getFunctionCall();
			if (! $functionCall instanceof FunctionCall) {
				continue;
			}

			$abilityName = WP_AI_Client_Ability_Function_Resolver::function_name_to_ability_name((string) $functionCall->getName());

			$parts[] = new MessagePart(
				new FunctionResponse(
					$functionCall->getId(),
					$functionCall->getName(),
					$payloadFor($abilityName)
				)
			);
		}

		return new UserMessage($parts);
	}

	/**
	 * Keeps only the answered call's responses of a function-response message.
	 *
	 * Lets attachResultDigests() pair them by position with the answered calls.
	 *
	 * @param Message $responses  The function-response message.
	 * @param string  $pausedName Ability name of the paused call.
	 *
	 * @return Message
	 * @since 1.3.1
	 */
	private function onlyAskUserResponses(Message $responses, string $pausedName = AskUserAbilityService::NAME): Message
	{
		$parts = array_values(array_filter(
			$responses->getParts(),
			static function (MessagePart $part) use ($pausedName): bool {
				$response = $part->getFunctionResponse();

				return $response instanceof FunctionResponse
					&& $pausedName === WP_AI_Client_Ability_Function_Resolver::function_name_to_ability_name((string) $response->getName());
			}
		));

		return new UserMessage($parts);
	}

	/**
	 * Returns the first call that pauses the run for the user, if any.
	 *
	 * Those are ask-user and request-approval (AskUserAbilityService::PAUSING_TOOLS);
	 * the first one in the model's order is the one shown.
	 *
	 * @param array<int, array<string, mixed>> $calls Extracted tool calls.
	 *
	 * @return array<string, mixed>|null
	 * @since 1.3.1
	 */
	private function findAskUserCall(array $calls): ?array
	{
		foreach ($calls as $call) {
			if (in_array((string) ($call['name'] ?? ''), AskUserAbilityService::PAUSING_TOOLS, true)) {
				return $call;
			}
		}

		return null;
	}

	/**
	 * Returns the first requested call to the given ability, if any.
	 *
	 * @param array<int, array<string, mixed>> $calls       Extracted tool calls.
	 * @param string                           $abilityName Ability name.
	 *
	 * @return array<string, mixed>|null
	 * @since 1.3.1
	 */
	private function findCall(array $calls, string $abilityName): ?array
	{
		foreach ($calls as $call) {
			if ($abilityName === (string) ($call['name'] ?? '')) {
				return $call;
			}
		}

		return null;
	}

	/**
	 * Builds the final response for a plan delivered through present-plan.
	 *
	 * The plan becomes the turn's text, so it is shown and persisted like any
	 * answer. Every call of the message is answered in the history (providers
	 * require it for the next turn); the recorded present-plan call drops the
	 * plan itself, which already is the message text.
	 *
	 * @param string                           $plan       The delivered plan (Markdown).
	 * @param array<string, mixed>             $planCall   The extracted present-plan call.
	 * @param Message                          $message    The model message holding the call.
	 * @param Message[]                        $history    History ending with that message.
	 * @param array<int, array<string, mixed>> $toolCalls  Calls executed so far in this run.
	 * @param array<string, int>               $tokenUsage Token usage of the last call.
	 * @param GenerativeAiResult               $result     The model result.
	 *
	 * @return AgentResponse
	 * @since 1.3.1
	 */
	private function presentedPlanResponse(
		string $plan,
		array $planCall,
		Message $message,
		array $history,
		array $toolCalls,
		array $tokenUsage,
		GenerativeAiResult $result
	): AgentResponse {
		$summary = mb_substr(sanitize_text_field((string) ($planCall['args']['summary'] ?? '')), 0, 300);

		$history[] = $this->buildSyntheticResponses(
			$message,
			static function (string $abilityName): array {
				return AskUserAbilityService::PRESENT_PLAN === $abilityName
					? ['presented' => true]
					: ['not_executed' => true, 'error' => __('Not executed: it was requested together with the plan.', 'agent-mod')];
			}
		);

		$toolCalls[] = [
			'name'   => AskUserAbilityService::PRESENT_PLAN,
			'args'   => '' !== $summary ? ['summary' => $summary] : [],
			'result' => '{"presented":true}',
		];

		$response = new AgentResponse(
			$plan,
			$toolCalls,
			$history,
			$tokenUsage,
			null,
			$this->extractStopReason($result)
		);

		$response->planReview = ['summary' => $summary];

		return $response;
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
	 * @param string[]    $autoApprovedAbilities Ability names approved for the whole session;
	 *                                           calls to them skip the confirmation gate.
	 * @param bool        $webSearchEnabled  Whether to enable the provider's native web search tool.
	 * @param array<int, array<string, mixed>> $initialToolCalls Calls executed in earlier legs of a
	 *                                                           confirmation chain; seeds the executed
	 *                                                           list so responses report the whole chain.
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
		array $autoApprovedAbilities = [],
		bool $webSearchEnabled = false,
		array $initialToolCalls = []
	): AgentResponse {
		$resolver   = new WP_AI_Client_Ability_Function_Resolver(...$abilities);
		$history    = $messages;
		$toolCalls  = $initialToolCalls;
		$tokenUsage = [];
		$callCounts = [];
		// History index of the latest result of each read call signature, and
		// the index below which the compactor has elided results in this run.
		$readIndexes = [];
		$watermark   = 0;

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

			// The provider gets a compacted copy; $history itself stays intact
			// for the AgentResponse / confirmation-resume flow.
			$compacted = $this->historyCompactor->compact($history, $watermark);

			// A read whose result was elided may run again: blocking it would
			// leave the model with neither the data nor a way to get it back.
			$this->forgetElidedReads($callCounts, $readIndexes, $watermark);

			$builder = wp_ai_client_prompt()
				->with_history(...$compacted)
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
				$this->applyWebSearch($builder);
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
					$tokenUsage,
					null,
					$this->extractStopReason($result)
				);
			}

			$requestedCalls = $this->extractToolCalls($message);

			// Questions and go-ahead requests pause the run before anything
			// else, write confirmations included: the answers may change what
			// gets written. Session auto-approval does not apply — neither is a
			// write.
			$askCall = $this->findAskUserCall($requestedCalls);

			if (null !== $askCall) {
				$askName    = (string) $askCall['name'];
				$isApproval = AskUserAbilityService::REQUEST_APPROVAL === $askName;
				$questions  = $isApproval
					? $this->askUser->normalizeApproval((array) ($askCall['args'] ?? []))
					: $this->askUser->normalizeQuestions((array) ($askCall['args'] ?? []));

				if (! empty($questions['questions'])) {
					return AgentResponse::pendingQuestions(
						wp_generate_uuid4(),
						$questions,
						$this->extractText($result),
						$history,
						$tokenUsage,
						$toolCalls
					);
				}

				// Nothing showable came through: tell the model, run nothing
				// else from this message, and let it try again.
				$history[] = $this->buildSyntheticResponses(
					$message,
					static function (string $abilityName) use ($askName, $isApproval): array {
						if ($askName !== $abilityName) {
							return ['not_executed' => true, 'error' => __('Not executed: it was requested together with an invalid question or approval request.', 'agent-mod')];
						}

						return $isApproval
							? ['error' => __('No summary was provided. Pass "summary" with exactly what you will do once approved.', 'agent-mod')]
							: ['error' => __('No valid question was provided. Each question needs an id, the question text and a type.', 'agent-mod')];
					}
				);

				continue;
			}

			// A delivered plan ends the turn without another model call: the
			// plan is the answer, and the chat offers approve / review / change.
			$planCall = $this->findCall($requestedCalls, AskUserAbilityService::PRESENT_PLAN);

			if (null !== $planCall) {
				$plan = trim((string) ($planCall['args']['plan'] ?? ''));

				if ('' !== $plan) {
					return $this->presentedPlanResponse($plan, $planCall, $message, $history, $toolCalls, $tokenUsage, $result);
				}

				$history[] = $this->buildSyntheticResponses(
					$message,
					static function (string $abilityName): array {
						return AskUserAbilityService::PRESENT_PLAN === $abilityName
							? ['error' => __('The "plan" argument was empty. Pass the complete plan in Markdown.', 'agent-mod')]
							: ['not_executed' => true, 'error' => __('Not executed: it was requested together with an invalid present-plan call.', 'agent-mod')];
					}
				);

				continue;
			}

			// Intercept write operations before execution to allow user confirmation.
			// Abilities the user session-approved are exempted by name.
			$writeCalls = $this->filterWriteToolCalls($requestedCalls, $autoApprovedAbilities);

			if (! empty($writeCalls)) {
				// $toolCalls carries what already ran in this iteration set; the
				// resume path needs it so the model is told what is already done.
				return AgentResponse::pendingConfirmation(
					wp_generate_uuid4(),
					$writeCalls,
					$history,
					$tokenUsage,
					$toolCalls
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

			$resultMessage = $this->executeAbilities($resolver, $message, $callCounts);

			foreach ($requestedCalls as $call) {
				if (! $this->isWriteAbility((string) $call['name'])) {
					$readIndexes[$this->readSignature((string) $call['name'], $call['args'])] = count($history);
				}
			}

			$history[] = $resultMessage;

			$this->logToolFailures($resultMessage);

			// Carry a truncated result digest on each executed call so the
			// conversation store can persist it and later turns can reuse the
			// data instead of re-calling the same tools.
			$toolCalls = array_merge($toolCalls, $this->attachResultDigests($requestedCalls, $resultMessage));

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
			->with_history(...$this->historyCompactor->compact($history, $watermark))
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
			$this->applyWebSearch($finalBuilder);
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
			$this->extractTokenUsage($finalResult),
			null,
			$this->extractStopReason($finalResult)
		);
	}

	/**
	 * Enables the provider's native web search on the given prompt builder.
	 *
	 * The single WebSearch config is translated by each connector into its own
	 * server tool (OpenAI web_search, Google googleSearch, …), so no
	 * provider-specific payload is built here. The Gemini requirement to enable
	 * `toolConfig.includeServerSideToolInvocations` when googleSearch is combined
	 * with function calling is handled by GoogleConnectorRepairer at the HTTP
	 * layer, keeping this adapter provider-agnostic.
	 *
	 * @param WP_AI_Client_Prompt_Builder $builder WP AI Client prompt builder.
	 *
	 * @return void
	 * @since 1.0.6
	 */
	private function applyWebSearch(WP_AI_Client_Prompt_Builder $builder): void
	{
		$builder->using_web_search(new WebSearch());
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
	 * Safely extracts the answer text from a result.
	 *
	 * GenerativeAiResult::toText() returns only the FIRST content-channel text
	 * part and throws when there is none, so a candidate whose answer is split
	 * across several text parts silently loses everything after the first one.
	 * Every content-channel part is merged here instead — provider-agnostic, and
	 * the counterpart of the connector-level block merging done in Http/.
	 *
	 * Thought/reasoning channels are skipped: they are not the answer.
	 *
	 * @param GenerativeAiResult $result The result.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function extractText(GenerativeAiResult $result): string
	{
		try {
			$candidates = $result->getCandidates();

			if (empty($candidates)) {
				return '';
			}

			$blocks = [];

			foreach ($candidates[0]->getMessage()->getParts() as $part) {
				$text = $part->getText();

				if (null === $text || '' === trim($text) || ! $part->getChannel()->isContent()) {
					continue;
				}

				$blocks[] = $this->stripCitationMarkers($text);
			}

			return implode("\n\n", $blocks);
		} catch (Throwable $e) {
			return '';
		}
	}

	/**
	 * Removes inline citation markers that some providers leave in answer text.
	 *
	 * OpenAI web search embeds references such as "citeturn1view1" wrapped
	 * in private-use characters (U+E200 opens, U+E202 separates, U+E201
	 * closes). They are meant for the provider's own UI and render as noise
	 * here. The bare form (private-use characters already lost) is removed too;
	 * "cite" directly followed by "turnNkindN" does not occur in normal prose.
	 *
	 * @param string $text Answer text.
	 *
	 * @return string
	 * @since 1.3.1
	 */
	private function stripCitationMarkers(string $text): string
	{
		$cleaned = preg_replace(
			[
				'/[ \t]*\x{E200}[^\x{E200}\x{E201}]*\x{E201}/u',
				'/[ \t]*\bcite(?:turn\d+[a-z_]+\d+)+/u',
				'/[\x{E200}-\x{E202}]/u',
			],
			'',
			$text
		);

		return null === $cleaned ? $text : $cleaned;
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
	 * Attaches a truncated result digest to each executed tool call.
	 *
	 * The function responses of $resultMessage are paired with $calls in order
	 * (the resolver executes and answers calls in request order). The digest is
	 * persisted with the conversation history so later turns can be told what
	 * each tool already returned, preventing the model from re-calling the same
	 * read tools on every user message.
	 *
	 * @param array<int, array<string, mixed>> $calls         Extracted tool calls (name/args).
	 * @param Message                          $resultMessage The function-response message.
	 *
	 * @return array<int, array<string, mixed>> Calls with a 'result' digest added where available.
	 * @since 1.1.5
	 */
	private function attachResultDigests(array $calls, Message $resultMessage): array
	{
		/**
		 * Filters the maximum length (characters) of the persisted tool-result digest.
		 *
		 * @param int $length Digest length.
		 *
		 * @since 1.1.5
		 */
		$digestLength = max(0, (int) apply_filters('agent_mod_tool_result_digest_length', Constants::AI_TOOL_RESULT_DIGEST_LENGTH));

		$responses = [];

		foreach ($resultMessage->getParts() as $part) {
			$response = $part->getFunctionResponse();

			if ($response instanceof FunctionResponse) {
				$responses[] = $response;
			}
		}

		foreach ($calls as $index => $call) {
			// The answers to a pausing tool are what the user typed, not tool
			// output: later turns need all of it, so it is kept whole (even when
			// digests are turned off).
			$isAnswer = in_array($call['name'] ?? '', AskUserAbilityService::PAUSING_TOOLS, true);

			if (! isset($responses[$index]) || (0 === $digestLength && ! $isAnswer)) {
				continue;
			}

			// A list keeps every ID and title instead of being cut after a few
			// items, so a "continue" in the next message needs no re-listing.
			$listSummary = $isAnswer ? null : $this->historyCompactor->summarizeListResult($responses[$index]->getResponse());

			if (null !== $listSummary) {
				$calls[$index]['result'] = $listSummary;
				continue;
			}

			$digest = (string) wp_json_encode($responses[$index]->getResponse(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

			if ($isAnswer) {
				$calls[$index]['result'] = $digest;
				continue;
			}

			// A configuration the agent writes code against (e.g. a field
			// configuration) keeps the larger summary budget, so a follow-up
			// message can still use it instead of asking the user to paste it.
			if ($this->historyCompactor->isStickyTool((string) ($call['name'] ?? ''))) {
				$calls[$index]['result'] = mb_substr($digest, 0, max($digestLength, Constants::AI_LIST_RESULT_SUMMARY_LENGTH));
				continue;
			}

			// A nested result too long for the digest keeps its skeleton
			// rather than its first few hundred characters.
			$structureSummary = mb_strlen($digest) > $digestLength
				? $this->historyCompactor->summarizeStructure($responses[$index]->getResponse())
				: null;

			$calls[$index]['result'] = $structureSummary ?? mb_substr($digest, 0, $digestLength);
		}

		return $calls;
	}

	/**
	 * Executes the abilities a model message requested.
	 *
	 * Wrapped in a hook pair so a caller can open an authorisation window around
	 * ability execution ALONE. Every ability is gated on current_user_can(), and
	 * a caller that has no logged-in user of its own — an unattended run, a
	 * public front-end widget — needs one for exactly this call and nothing else.
	 * Keeping the window this narrow is the point: prompt building, knowledge
	 * lookups and persistence stay on the caller's real identity.
	 *
	 * A listener MUST restore whatever it changed on the `after` action; it fires
	 * in a finally block, so an exception inside an ability cannot leave the
	 * request authenticated as somebody else.
	 *
	 * With $callCounts, read-only calls repeated with identical arguments in the
	 * same run are counted: a repeat still runs but carries a notice, and from
	 * the MAX_IDENTICAL_READS + 1-th time on it is answered with an error
	 * instead, so a model stuck re-listing the same posts cannot burn the whole
	 * tool-call budget. Write calls are never short-circuited.
	 *
	 * @param WP_AI_Client_Ability_Function_Resolver $resolver   The ability resolver.
	 * @param Message                                $message    The model message holding the calls.
	 * @param array<string, int>|null                $callCounts Per-run counts of read-only call signatures; null disables the repeat guard.
	 *
	 * @return Message The function-response message.
	 * @since 1.1.8
	 */
	private function executeAbilities(WP_AI_Client_Ability_Function_Resolver $resolver, Message $message, ?array &$callCounts = null): Message
	{
		/**
		 * Fires immediately before requested abilities are executed.
		 *
		 * @since 1.1.8
		 */
		do_action('agent_mod_before_ability_execution');

		try {
			if (null === $callCounts) {
				return $resolver->execute_abilities($message);
			}

			$parts = [];

			// Same order and filtering as the resolver's execute_abilities().
			foreach ($message->getParts() as $part) {
				$functionCall = $part->getType()->isFunctionCall() ? $part->getFunctionCall() : null;

				if (! $functionCall instanceof FunctionCall || ! $resolver->is_ability_call($functionCall)) {
					continue;
				}

				$parts[] = new MessagePart($this->executeGuarded($resolver, $functionCall, $callCounts));
			}

			return new UserMessage($parts);
		} finally {
			/**
			 * Fires after ability execution, including when one threw.
			 *
			 * @since 1.1.8
			 */
			do_action('agent_mod_after_ability_execution');
		}
	}

	/**
	 * Executes one ability call under the identical-read repeat guard.
	 *
	 * @param WP_AI_Client_Ability_Function_Resolver $resolver     The ability resolver.
	 * @param FunctionCall                           $functionCall The call.
	 * @param array<string, int>                     $callCounts   Per-run counts of read-only call signatures.
	 *
	 * @return FunctionResponse
	 * @since 1.3.3
	 */
	private function executeGuarded(WP_AI_Client_Ability_Function_Resolver $resolver, FunctionCall $functionCall, array &$callCounts): FunctionResponse
	{
		$abilityName = WP_AI_Client_Ability_Function_Resolver::function_name_to_ability_name((string) $functionCall->getName());

		if ($this->isWriteAbility($abilityName)) {
			return $resolver->execute_ability($functionCall);
		}

		$signature = $this->readSignature($abilityName, $functionCall->getArgs());
		$seen      = $callCounts[$signature] ?? 0;

		$callCounts[$signature] = $seen + 1;

		if ($seen >= self::MAX_IDENTICAL_READS) {
			return new FunctionResponse(
				$functionCall->getId(),
				$functionCall->getName(),
				[
					'not_executed' => true,
					'error'        => sprintf(
						/* translators: 1: ability name, 2: number of earlier identical calls. */
						__('Not executed: %1$s was already called %2$d times with exactly these arguments in this run. Use the IDs and data you already have (older results are kept as ID / title summaries), change the arguments (e.g. the next page), or answer the user with what you have.', 'agent-mod'),
						$abilityName,
						$seen
					),
				]
			);
		}

		$response = $resolver->execute_ability($functionCall);
		$payload  = $response->getResponse();

		if ($seen > 0 && is_array($payload)) {
			$payload['repeat_notice'] = __('You already made this exact call earlier in this run. Keep and reuse its result instead of calling it again.', 'agent-mod');

			return new FunctionResponse($response->getId(), $response->getName(), $payload);
		}

		return $response;
	}

	/**
	 * Builds the repeat-guard signature of a read call.
	 *
	 * @param string $abilityName Ability name.
	 * @param mixed  $args        Call arguments.
	 *
	 * @return string
	 * @since x.x.x
	 */
	private function readSignature(string $abilityName, $args): string
	{
		return $abilityName . '|' . md5((string) wp_json_encode($this->normalizeArgs($args ?? [])));
	}

	/**
	 * Resets the repeat-guard count of reads whose latest result was elided.
	 *
	 * The guard exists to stop a model re-fetching data it still has. Once the
	 * compactor has elided that data, a repeat is the only way to get it back,
	 * so the signature starts counting from zero again.
	 *
	 * @param array<string, int> $callCounts  Per-run counts of read call signatures.
	 * @param array<string, int> $readIndexes History index of the latest result per signature.
	 * @param int                $watermark   History index below which results are elided.
	 *
	 * @return void
	 * @since x.x.x
	 */
	private function forgetElidedReads(array &$callCounts, array &$readIndexes, int $watermark): void
	{
		foreach ($readIndexes as $signature => $index) {
			if ($index < $watermark) {
				unset($callCounts[$signature], $readIndexes[$signature]);
			}
		}
	}

	/**
	 * Normalizes call arguments for signature comparison (recursively sorted keys).
	 *
	 * @param mixed $args Call arguments.
	 *
	 * @return mixed
	 * @since 1.3.3
	 */
	private function normalizeArgs($args)
	{
		if (! is_array($args)) {
			return $args;
		}

		$args = array_map([$this, 'normalizeArgs'], $args);

		if (array_keys($args) !== range(0, count($args) - 1)) {
			ksort($args);
		}

		return $args;
	}

	/**
	 * Logs ability executions that came back as an error.
	 *
	 * A failing ability is not an exception: the resolver wraps the WP_Error into
	 * a FunctionResponse and hands it to the model, which then either apologises
	 * or — worse — retries until the tool-call budget is gone and the answer ends
	 * up empty. Without this the whole class of failures (`ability_invalid_permissions`,
	 * `ability_not_allowed`, `ability_not_found`) is completely invisible.
	 *
	 * @param Message $resultMessage The function-response message.
	 *
	 * @return void
	 * @since 1.1.8
	 */
	private function logToolFailures(Message $resultMessage): void
	{
		foreach ($resultMessage->getParts() as $part) {
			$response = $part->getFunctionResponse();

			if (! $response instanceof FunctionResponse) {
				continue;
			}

			$payload = $response->getResponse();

			if (! is_array($payload) || ! isset($payload['error'])) {
				continue;
			}

			$name = WP_AI_Client_Ability_Function_Resolver::function_name_to_ability_name((string) $response->getName());

			/**
			 * Fires when an ability execution returned an error to the model.
			 *
			 * @since 1.1.8
			 *
			 * @param string               $name    Ability name.
			 * @param array<string, mixed> $payload The error payload fed back to the model.
			 */
			do_action('agent_mod_tool_call_failed', $name, $payload);

			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostics behind WP_DEBUG.
					sprintf(
						'AgentMod: ability "%s" failed [%s] %s',
						$name,
						(string) ($payload['code'] ?? 'unknown'),
						(string) ($payload['error'] ?? '')
					)
				);
			}
		}
	}

	/**
	 * Filters tool calls that require user confirmation before execution.
	 *
	 * The default comes from the ability's own MCP-style annotations: only an
	 * explicit `readonly => true` bypasses the gate (see isWriteAbility()).
	 * Plugins refine that verdict through the agent_mod_ability_requires_confirmation
	 * filter. Abilities the user has approved for the session are exempted by
	 * name — that exemption deliberately wins over the filter, because it is an
	 * explicit per-name consent given in the confirmation dialog.
	 *
	 * Argument binding is guaranteed by the resume path, not by matching here:
	 * confirmation executes the exact stored calls the user saw (see
	 * AIClientAdapter::resume()), so the model never gets a chance to swap in
	 * different arguments after approval.
	 *
	 * @param array<int, array<string, mixed>> $toolCalls             Extracted tool calls.
	 * @param string[]                         $autoApprovedAbilities Session-approved ability names.
	 *
	 * @return array<int, array<string, mixed>> Only the calls that still need confirmation.
	 * @since 1.0.0
	 */
	private function filterWriteToolCalls(array $toolCalls, array $autoApprovedAbilities): array
	{
		return array_values(
			array_filter(
				$toolCalls,
				function (array $call) use ($autoApprovedAbilities): bool {
					$requires = $this->isWriteAbility((string) $call['name']);

					/**
					 * Filters whether an ability must be confirmed by the user before it runs.
					 *
					 * @param bool   $requires Default verdict, derived from the ability's
					 *                         `readonly` annotation.
					 * @param string $name     Ability name.
					 *
					 * @since 1.0.0
					 */
					if (! (bool) apply_filters('agent_mod_ability_requires_confirmation', $requires, $call['name'])) {
						return false;
					}

					return ! $this->isAutoApproved((string) $call['name'], $autoApprovedAbilities);
				}
			)
		);
	}

	/**
	 * Whether the user has session-approved an ability by name.
	 *
	 * The literal '*' entry approves every write; the chat UI sends it when the
	 * user picks the "Auto" approval mode in Execute mode.
	 *
	 * @param string   $name      Ability name.
	 * @param string[] $allowlist Session-approved ability names.
	 *
	 * @return bool
	 * @since 1.1.0
	 */
	private function isAutoApproved(string $name, array $allowlist): bool
	{
		return in_array('*', $allowlist, true) || in_array($name, $allowlist, true);
	}

	/**
	 * Whether an ability requires confirmation before execution.
	 *
	 * Fail-closed: only an explicit `readonly => true` annotation bypasses the
	 * confirmation gate. `false`, `null` (WP core injects `readonly => null`
	 * into every ability that ships no annotations) and malformed annotation
	 * shapes all count as writes — an unknown ability must never run silently.
	 * Read-only abilities from other plugins opt out by annotating themselves,
	 * or via the agent_mod_ability_requires_confirmation filter.
	 *
	 * @param string $name Ability name.
	 *
	 * @return bool True when the ability must be confirmed.
	 * @since 1.1.0
	 */
	private function isWriteAbility(string $name): bool
	{
		if ('' === $name || ! function_exists('wp_get_ability')) {
			return false;
		}

		$ability = wp_get_ability($name);

		// An unregistered name cannot be executed either, so there is nothing to
		// confirm — the resolver rejects it on its own.
		if (! $ability instanceof WP_Ability) {
			return false;
		}

		$annotations = $ability->get_meta_item('annotations', []);

		return ! (is_array($annotations) && true === ($annotations['readonly'] ?? null));
	}

	/**
	 * Extracts the finish/stop reason of the first candidate as a plain string.
	 *
	 * Returns one of the WP AI Client FinishReasonEnum values ('stop', 'length',
	 * 'content_filter', 'tool_calls', 'error'), or '' when it cannot be resolved.
	 *
	 * @param GenerativeAiResult $result The result.
	 *
	 * @return string
	 * @since 1.2.0
	 */
	private function extractStopReason(GenerativeAiResult $result): string
	{
		try {
			$candidates = $result->getCandidates();
			if (empty($candidates)) {
				return '';
			}

			return (string) $candidates[0]->getFinishReason()->value;
		} catch (Throwable $e) {
			return '';
		}
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
