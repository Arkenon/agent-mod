<?php

/**
 * Agent response Data Transfer Object.
 *
 * Carries the result of an orchestration run back to the caller: the final text,
 * the executed tool calls, the updated message history (for future persistence),
 * token usage and an optional error.
 *
 * @package AgentMod
 * @subpackage Services\AI\DTO
 * @since 1.0.0
 */

namespace AgentMod\Services\AI\DTO;

use WP_Error;
use WordPress\AiClient\Messages\DTO\Message;

defined('ABSPATH') || exit;

final class AgentResponse
{
	/**
	 * Final assistant text.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public string $text;

	/**
	 * Executed tool calls as ['name' => string, 'args' => array] entries.
	 *
	 * @var array<int, array<string, mixed>>
	 * @since 1.0.0
	 */
	public array $toolCalls;

	/**
	 * Updated message history (Message[]) — for the next step's persistence.
	 *
	 * @var Message[]
	 * @since 1.0.0
	 */
	public array $messages;

	/**
	 * Token usage statistics as a plain array.
	 *
	 * @var array<string, int>
	 * @since 1.0.0
	 */
	public array $tokenUsage;

	/**
	 * Error, if the run failed.
	 *
	 * @var WP_Error|null
	 * @since 1.0.0
	 */
	public ?WP_Error $error;

	/**
	 * Constructor.
	 *
	 * @param string                            $text       Final assistant text.
	 * @param array<int, array<string, mixed>>  $toolCalls  Executed tool calls.
	 * @param Message[]                         $messages   Updated message history.
	 * @param array<string, int>                $tokenUsage Token usage.
	 * @param WP_Error|null                     $error      Error, if any.
	 *
	 * @since 1.0.0
	 */
	public function __construct(
		string $text = '',
		array $toolCalls = [],
		array $messages = [],
		array $tokenUsage = [],
		?WP_Error $error = null
	) {
		$this->text       = $text;
		$this->toolCalls  = $toolCalls;
		$this->messages   = $messages;
		$this->tokenUsage = $tokenUsage;
		$this->error      = $error;
	}

	/**
	 * Creates an error response.
	 *
	 * @param WP_Error $error The error.
	 *
	 * @return self
	 * @since 1.0.0
	 */
	public static function fromError(WP_Error $error): self
	{
		return new self('', [], [], [], $error);
	}

	/**
	 * Whether this response represents an error.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function isError(): bool
	{
		return $this->error instanceof WP_Error;
	}

	/**
	 * Converts the response to an array suitable for a REST response.
	 *
	 * Note: the raw Message[] history is intentionally excluded; it is kept only
	 * in memory for the (future) persistence step.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function toArray(): array
	{
		if ($this->isError()) {
			return [
				'success' => false,
				'error'   => [
					'code'    => $this->error->get_error_code(),
					'message' => $this->error->get_error_message(),
				],
			];
		}

		return [
			'success'    => true,
			'text'       => $this->text,
			'toolCalls'  => $this->toolCalls,
			'tokenUsage' => $this->tokenUsage,
		];
	}
}
