<?php

/**
 * REST controller for testing the AI orchestrator.
 *
 * Exposes a temporary, capability-protected endpoint that runs the orchestrator
 * against a live provider. Persistence and the real chat widget are added later.
 *
 * @package AgentMod
 * @subpackage Presentation\Admin\Controllers
 * @since 1.0.0
 */

namespace AgentMod\Presentation\Admin\Controllers;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use AgentMod\Common\Constants;
use AgentMod\Common\Helper;
use AgentMod\Services\AI\AIOrchestratorService;
use AgentMod\Services\AI\ConfirmationStore;
use AgentMod\Services\AI\ProgressStore;
use AgentMod\Services\AI\ProviderInfoService;
use AgentMod\Services\AI\DTO\AgentConfig;
use AgentMod\Services\SettingsService;

defined('ABSPATH') || exit;

final class AIChatRestController
{
	/**
	 * AI orchestrator service.
	 *
	 * @var AIOrchestratorService
	 * @since 1.0.0
	 */
	private AIOrchestratorService $orchestrator;

	/**
	 * Confirmation state store.
	 *
	 * @var ConfirmationStore
	 * @since 1.0.0
	 */
	private ConfirmationStore $confirmationStore;

	/**
	 * Connected-provider info service.
	 *
	 * @var ProviderInfoService
	 * @since 1.0.0
	 */
	private ProviderInfoService $providerInfo;

	/**
	 * Live tool-call progress store.
	 *
	 * @var ProgressStore
	 * @since 1.1.0
	 */
	private ProgressStore $progressStore;

	/**
	 * Inject Setting Service
	 * @since 1.0.5
	 */
	private SettingsService $settings_service;

	/**
	 * Constructor (PHP-DI autowired). Binds the REST route registration.
	 *
	 * @param AIOrchestratorService  $orchestrator           AI orchestrator service.
	 * @param ConfirmationStore      $confirmationStore      Pending write-action store.
	 * @param ProviderInfoService    $providerInfo           Connected-provider info service.
	 * @param ProgressStore          $progressStore          Live tool-call progress store.
	 *
	 * @since 1.0.0
	 */
	public function __construct(
		AIOrchestratorService $orchestrator,
		ConfirmationStore $confirmationStore,
		ProviderInfoService $providerInfo,
		ProgressStore $progressStore,
		SettingsService $settingsService
	) {
		$this->orchestrator           = $orchestrator;
		$this->confirmationStore      = $confirmationStore;
		$this->providerInfo           = $providerInfo;
		$this->progressStore          = $progressStore;
		$this->settings_service       = $settingsService;
		add_action('rest_api_init', [$this, 'registerRoutes']);
	}

	/**
	 * Registers the REST routes.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function registerRoutes(): void
	{
		$chatArgs = [
			'methods'             => 'POST',
			'callback'            => [$this, 'handleChat'],
			'permission_callback' => [$this, 'checkPermission'],
		];

		// Temporary testing route (kept for backwards compatibility).
		register_rest_route(Constants::REST_NAMESPACE, '/test-chat', $chatArgs);

		// Permanent chat route used by the admin chat widget.
		register_rest_route(Constants::REST_NAMESPACE, '/chat', $chatArgs);

		// Returns the list of published agents for the agent selector.
		register_rest_route(
			Constants::REST_NAMESPACE,
			'/agents',
			[
				'methods'             => 'GET',
				'callback'            => [$this, 'handleAgents'],
				'permission_callback' => [$this, 'checkPermission'],
			]
		);

		// Returns the list of connected AI providers.
		register_rest_route(
			Constants::REST_NAMESPACE,
			'/providers',
			[
				'methods'             => 'GET',
				'callback'            => [$this, 'handleProviders'],
				'permission_callback' => [$this, 'checkPermission'],
			]
		);

		// Returns the text-generation models for a connected provider.
		register_rest_route(
			Constants::REST_NAMESPACE,
			'/provider-models',
			[
				'methods'             => 'GET',
				'callback'            => [$this, 'handleProviderModels'],
				'permission_callback' => [$this, 'checkPermission'],
				'args'                => [
					'provider' => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);

		// Resumes a pending write operation after user confirmation.
		register_rest_route(
			Constants::REST_NAMESPACE,
			'/confirm-action',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'handleConfirmAction'],
				'permission_callback' => [$this, 'checkPermission'],
			]
		);

		// Requests cancellation of an in-flight chat request. The orchestration
		// loop checks the flag between iterations and bails out early.
		register_rest_route(
			Constants::REST_NAMESPACE,
			'/chat-stop',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'handleChatStop'],
				'permission_callback' => [$this, 'checkPermission'],
				'args'                => [
					'requestId' => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);

		// Live tool-call progress for an in-flight chat request (polled by the widget).
		register_rest_route(
			Constants::REST_NAMESPACE,
			'/chat-progress',
			[
				'methods'             => 'GET',
				'callback'            => [$this, 'handleChatProgress'],
				'permission_callback' => [$this, 'checkPermission'],
				'args'                => [
					'requestId' => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);
	}

	/**
	 * Permission check for the endpoint.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function checkPermission(): bool
	{
		return current_user_can('manage_options');
	}

	/**
	 * Handles a chat request.
	 *
	 * @param WP_REST_Request $request The REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 * @since 1.0.0
	 */
	public function handleChat(WP_REST_Request $request)
	{
		$message     = sanitize_textarea_field((string) $request->get_param('message'));
		$attachments = $this->sanitizeAttachments($request->get_param('attachments'));

		if ('' === trim($message) && empty($attachments)) {
			return new WP_Error(
				'missing_message',
				__('A "message" or an attachment is required.', 'agent-mod'),
				['status' => 400]
			);
		}

		$agentData = $request->get_param('agent');
		$agentData = is_array($agentData) ? Helper::sanitizeArray($agentData) : [];

		/**
		 * Filters the agent configuration data before the AgentConfig DTO is built.
		 *
		 * Lets extensions (e.g. Pro) resolve a full agent configuration
		 * server-side from the submitted agent id. Callbacks must preserve the
		 * request-level keys: id, mode, provider, model and emphasizedAbilities.
		 *
		 * @param array $agentData Sanitized agent data from the request.
		 * @since 1.2.0
		 */
		$agentData = (array) apply_filters('agent_mod_agent_config_data', $agentData);

		$agent = AgentConfig::fromArray($agentData);

		$conversationId = (int) $request->get_param('conversationId');

		// Load history from DB when a conversationId is provided (pro); otherwise
		// use the client-supplied history for the first turn of a new conversation.
		if ($conversationId > 0) {
			$history = (array) apply_filters('agent_mod_load_conversation_history', [], $conversationId);
		} else {
			$history = $request->get_param('history');
			$history = is_array($history) ? $this->sanitizeHistory($history) : [];
		}

		do_action('agent_mod_before_chat', $agent, $message, $history);

		$response = $this->orchestrator->chat($agent, $message, $history, $attachments, $this->sanitizeRequestId($request));

		do_action('agent_mod_after_chat', $agent, $response, $conversationId);

		if ($response->isError()) {
			$error  = $response->error;
			$data   = $error->get_error_data();
			$status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;

			return new WP_REST_Response($response->toArray(), $status);
		}

		// Persist the new turns when the response is a normal answer.
		// Pending-confirmation responses are not persisted yet — only saved to
		// the transient store by AIOrchestratorService until confirmed. The
		// conversation itself is created lazily here so failed or abandoned
		// requests never leave empty conversation posts behind.
		if (! $response->isPendingConfirmation) {
			if ($conversationId <= 0) {
				/**
				 * Filters the conversation ID for a new chat session.
				 *
				 * Lets extensions (e.g. Pro) persist a new conversation and return
				 * its ID. Returning 0 keeps the conversation ephemeral.
				 *
				 * @param int    $conversationId Default 0 (no persistence).
				 * @param int    $agentId        Stored agent post ID, 0 for the default agent.
				 * @param string $source         Origin of the conversation.
				 * @param string $message        First user message, e.g. for deriving a title.
				 *
				 * @since 1.2.0
				 */
				$conversationId = (int) apply_filters('agent_mod_create_conversation', 0, (int) ($agentData['id'] ?? 0), 'admin_chat', $message);
			}

			if ($conversationId > 0) {
				$newTurns = [
					['role' => 'user', 'text' => $message, 'attachments' => $attachments],
					[
						'role'        => 'assistant',
						'text'        => $response->text,
						'attachments' => [],
						'toolCalls'   => $response->toolCalls,
						'tokenUsage'  => $response->tokenUsage,
					],
				];
				do_action('agent_mod_append_messages', $conversationId, $newTurns);
			}
		}

		$payload                 = $response->toArray();
		$payload['conversationId'] = $conversationId ?: null;

		return rest_ensure_response($payload);
	}

	/**
	 * Returns the list of published agents.
	 *
	 * @return WP_REST_Response
	 * @since 1.0.0
	 */
	public function handleAgents(): WP_REST_Response
	{
		return rest_ensure_response((array) apply_filters('agent_mod_get_agents', []));
	}

	/**
	 * Returns the list of connected AI providers.
	 *
	 * @return WP_REST_Response
	 * @since 1.0.0
	 */
	public function handleProviders(): WP_REST_Response
	{
		return rest_ensure_response($this->providerInfo->getConnectedProviders());
	}

	/**
	 * Returns the text-generation models for a connected provider.
	 *
	 * @param WP_REST_Request $request The REST request.
	 *
	 * @return WP_REST_Response
	 * @since 1.0.0
	 */
	public function handleProviderModels(WP_REST_Request $request): WP_REST_Response
	{
		$provider = sanitize_key((string) $request->get_param('provider'));

		return rest_ensure_response($this->providerInfo->getTextModels($provider));
	}

	/**
	 * Resumes a write operation after the user has confirmed it.
	 *
	 * @param WP_REST_Request $request The REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 * @since 1.0.0
	 */
	public function handleConfirmAction(WP_REST_Request $request)
	{
		$token          = sanitize_text_field((string) $request->get_param('token'));
		$conversationId = (int) $request->get_param('conversationId');

		if ('' === $token) {
			return new WP_Error('missing_token', __('A confirmation token is required.', 'agent-mod'), ['status' => 400]);
		}

		$state = $this->confirmationStore->consume($token);

		if (null === $state) {
			return new WP_Error('invalid_token', __('The confirmation token is invalid or has expired.', 'agent-mod'), ['status' => 404]);
		}

		$agent       = $state['agent'];
		$history     = $state['history'] ?? [];
		$message     = $state['message'] ?? '';
		$attachments = $state['attachments'] ?? [];
		$pendingCall = $state['pendingCalls'][0] ?? [];

		if (! $agent instanceof AgentConfig || empty($pendingCall)) {
			return new WP_Error('corrupt_state', __('The pending confirmation state is invalid.', 'agent-mod'), ['status' => 500]);
		}

		// Inject a confirmation message so the AI re-requests and executes the
		// approved tool on the next loop iteration.
		$confirmInstruction = sprintf(
			/* translators: 1: ability name, 2: JSON-encoded arguments. */
			__('The user has confirmed this action. Please execute it now: %1$s with arguments: %2$s', 'agent-mod'),
			esc_html($pendingCall['name'] ?? ''),
			wp_json_encode($pendingCall['args'] ?? [])
		);

		$response = $this->orchestrator->chat($agent, $confirmInstruction, $history, $attachments, $this->sanitizeRequestId($request), [$pendingCall]);

		if ($response->isError()) {
			$error  = $response->error;
			$data   = $error->get_error_data();
			$status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;

			return new WP_REST_Response($response->toArray(), $status);
		}

		if (! $response->isPendingConfirmation) {
			// The confirmation may belong to the very first turn of a session, in
			// which case no conversation exists yet — create it lazily, exactly
			// like handleChat() does. This filter is documented in handleChat().
			if ($conversationId <= 0) {
				$conversationId = (int) apply_filters('agent_mod_create_conversation', 0, (int) ($agent->id ?? 0), 'admin_chat', $message);
			}

			if ($conversationId > 0) {
				$newTurns = [
					['role' => 'user', 'text' => $message, 'attachments' => $attachments],
					[
						'role'        => 'assistant',
						'text'        => $response->text,
						'attachments' => [],
						'toolCalls'   => $response->toolCalls,
						'tokenUsage'  => $response->tokenUsage,
					],
				];
				do_action('agent_mod_append_messages', $conversationId, $newTurns);
			}
		}

		$payload                 = $response->toArray();
		$payload['conversationId'] = $conversationId ?: null;

		return rest_ensure_response($payload);
	}

	/**
	 * Flags an in-flight chat request as stop-requested.
	 *
	 * The frontend additionally aborts its own fetch; this flag makes the
	 * server-side tool-calling loop bail out at the next iteration boundary so
	 * no further provider calls or ability executions happen for the request.
	 *
	 * @param WP_REST_Request $request The REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 * @since 1.2.0
	 */
	public function handleChatStop(WP_REST_Request $request)
	{
		$requestId = $this->sanitizeRequestId($request);

		if ('' === $requestId) {
			return new WP_Error('invalid_request_id', __('Invalid request id.', 'agent-mod'), ['status' => 400]);
		}

		$this->progressStore->requestStop($requestId);

		return rest_ensure_response(['success' => true]);
	}

	/**
	 * Returns the live tool-call progress for an in-flight chat request.
	 *
	 * @param WP_REST_Request $request The REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 * @since 1.1.0
	 */
	public function handleChatProgress(WP_REST_Request $request)
	{
		$requestId = $this->sanitizeRequestId($request);

		if ('' === $requestId) {
			return new WP_Error('invalid_request_id', __('Invalid request id.', 'agent-mod'), ['status' => 400]);
		}

		$state    = $this->progressStore->load($requestId);
		$response = rest_ensure_response($state ?? ['status' => 'unknown']);
		$response->header('Cache-Control', 'no-store');

		return $response;
	}

	/**
	 * Extracts and validates the client-generated request id (UUID) from a request.
	 *
	 * @param WP_REST_Request $request The REST request.
	 *
	 * @return string The UUID, or '' when missing/invalid.
	 * @since 1.1.0
	 */
	private function sanitizeRequestId(WP_REST_Request $request): string
	{
		$requestId = sanitize_text_field((string) $request->get_param('requestId'));

		return wp_is_uuid($requestId) ? $requestId : '';
	}

	/**
	 * Sanitizes the history payload into ['role' => ..., 'text' => ...] turns.
	 *
	 * @param array<int, mixed> $history Raw history.
	 *
	 * @return array<int, array<string, string>>
	 * @since 1.0.0
	 */
	private function sanitizeHistory(array $history): array
	{
		$turns = [];

		foreach ($history as $turn) {
			if (! is_array($turn)) {
				continue;
			}

			$text        = isset($turn['text']) ? sanitize_textarea_field((string) $turn['text']) : '';
			$attachments = $this->sanitizeAttachments($turn['attachments'] ?? null);

			if ('' === trim($text) && empty($attachments)) {
				continue;
			}

			$sanitizedTurn = [
				'role'        => sanitize_key($turn['role'] ?? 'user'),
				'text'        => $text,
				'attachments' => $attachments,
			];

			/**
			 * Allows Pro to merge extra sanitized fields (e.g. structured_data) into
			 * a history turn before it enters the orchestration pipeline.
			 *
			 * @param array $sanitizedTurn Sanitized turn with role/text/attachments.
			 * @param array $turn          Raw turn from the request payload.
			 *
			 * @since 1.0.0
			 */
			$turns[] = apply_filters('agent_mod_sanitize_history_turn', $sanitizedTurn, $turn);
		}

		return $turns;
	}

	/**
	 * Sanitizes the attachments payload.
	 *
	 * Each attachment must be a base64 data URI whose MIME type is on the
	 * allow-list and whose decoded size is within the per-file limit. Anything
	 * malformed, oversized, or of a disallowed type is dropped, and the number of
	 * attachments is capped. The returned data URI is rebuilt from the validated
	 * MIME type and base64 payload so nothing untrusted is forwarded verbatim.
	 *
	 * @param mixed $raw Raw attachments value from the request.
	 *
	 * @return array<int, array<string, string>>
	 * @since 1.0.0
	 */
	private function sanitizeAttachments($raw): array
	{
		if (! is_array($raw)) {
			return [];
		}

		$clean = [];

		foreach ($raw as $item) {
			if (count($clean) >= $this->settings_service->getAttachmentMaxCount()) {
				break;
			}

			if (! is_array($item)) {
				continue;
			}

			$parsed = $this->parseDataUri(isset($item['data']) ? (string) $item['data'] : '');

			if (null === $parsed) {
				continue;
			}

			[$mimeType, $base64] = $parsed;

			if (! in_array($mimeType, $this->settings_service->getAttachmentMimeTypes(), true)) {
				continue;
			}

			// Approximate the decoded size from the base64 length (4 chars -> 3 bytes).
			$decodedSize = (int) (strlen($base64) * 3 / 4);

			if ($decodedSize >$this->settings_service->getAttachmentMaxBytes()) {
				continue;
			}

			$clean[] = [
				'name'     => isset($item['name']) ? sanitize_file_name((string) $item['name']) : '',
				'mimeType' => $mimeType,
				'data'     => 'data:' . $mimeType . ';base64,' . $base64,
			];
		}

		return $clean;
	}

	/**
	 * Parses and validates a base64 data URI.
	 *
	 * @param string $data The candidate data URI.
	 *
	 * @return array{0: string, 1: string}|null [mimeType, base64Data] or null when invalid.
	 * @since 1.0.0
	 */
	private function parseDataUri(string $data): ?array
	{
		if ('' === $data) {
			return null;
		}

		$pattern = '#^data:([a-z0-9][a-z0-9!\#$&\-\^_+./]*);base64,([A-Za-z0-9+/]+={0,2})$#i';

		if (! preg_match($pattern, $data, $matches)) {
			return null;
		}

		$base64 = $matches[2];

		// Reject payloads that are not valid base64.
		if (false === base64_decode($base64, true)) {
			return null;
		}

		return [strtolower($matches[1]), $base64];
	}
}
