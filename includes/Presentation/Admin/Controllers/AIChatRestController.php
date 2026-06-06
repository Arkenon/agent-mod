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

		if ('' === trim($message)) {
			return new WP_Error(
				'missing_message',
				__('A "message" parameter is required.', 'agent-mod'),
				['status' => 400]
			);
		}

		$agentData = $request->get_param('agent');
		$agentData = is_array($agentData) ? Helper::sanitizeArray($agentData) : [];
		$agent     = AgentConfig::fromArray($agentData);

		$history = $request->get_param('history');
		$history = is_array($history) ? $this->sanitizeHistory($history) : [];

		$response = $this->orchestrator->chat($agent, $message, $history);

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
			if (! is_array($turn) || ! isset($turn['text'])) {
				continue;
			}

			$turns[] = [
				'role' => sanitize_key($turn['role'] ?? 'user'),
				'text' => sanitize_textarea_field((string) $turn['text']),
			];
		}

		return $turns;
	}
}
