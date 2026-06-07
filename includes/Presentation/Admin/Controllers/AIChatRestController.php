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
use AgentMod\Services\AI\DTO\AgentConfig;

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
	 * Constructor (PHP-DI autowired). Binds the REST route registration.
	 *
	 * @param AIOrchestratorService $orchestrator AI orchestrator service.
	 *
	 * @since 1.0.0
	 */
	public function __construct(AIOrchestratorService $orchestrator)
	{
		$this->orchestrator = $orchestrator;
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
		$args = [
			'methods'             => 'POST',
			'callback'            => [$this, 'handleChat'],
			'permission_callback' => [$this, 'checkPermission'],
		];

		// Temporary testing route (kept for backwards compatibility).
		register_rest_route(Constants::REST_NAMESPACE, '/test-chat', $args);

		// Permanent chat route used by the admin chat widget.
		register_rest_route(Constants::REST_NAMESPACE, '/chat', $args);
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
		$message = sanitize_textarea_field((string) $request->get_param('message'));

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
		$agent     = AgentConfig::fromArray($agentData);

		$history = $request->get_param('history');
		$history = is_array($history) ? $this->sanitizeHistory($history) : [];

		$response = $this->orchestrator->chat($agent, $message, $history, $attachments);

		if ($response->isError()) {
			$error  = $response->error;
			$data   = $error->get_error_data();
			$status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;

			return new WP_REST_Response($response->toArray(), $status);
		}

		return rest_ensure_response($response->toArray());
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

			$turns[] = [
				'role'        => sanitize_key($turn['role'] ?? 'user'),
				'text'        => $text,
				'attachments' => $attachments,
			];
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
			if (count($clean) >= Constants::AI_ATTACHMENT_MAX_COUNT) {
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

			if (! in_array($mimeType, Constants::AI_ATTACHMENT_MIME_TYPES, true)) {
				continue;
			}

			// Approximate the decoded size from the base64 length (4 chars -> 3 bytes).
			$decodedSize = (int) (strlen($base64) * 3 / 4);

			if ($decodedSize > Constants::AI_ATTACHMENT_MAX_BYTES) {
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
