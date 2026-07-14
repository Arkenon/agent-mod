<?php

/**
 * Agent configuration Data Transfer Object.
 *
 * Represents a single agent definition passed into the orchestrator. While there is
 * no Agent CPT yet, the orchestrator is stateless and receives its configuration
 * from the outside (e.g. a REST request) through this DTO.
 *
 * @package AgentMod
 * @subpackage Services\AI\DTO
 * @since 1.0.0
 */

namespace AgentMod\Services\AI\DTO;

use AgentMod\Common\Constants;

defined('ABSPATH') || exit;

final class AgentConfig
{
	/**
	 * Human-readable agent name.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public string $name;

	/**
	 * Short role description (e.g. "Support Assistant").
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public string $role;

	/**
	 * Primary goal of the agent.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public string $goal;

	/**
	 * Personality traits used to shape the tone.
	 *
	 * @var string[]
	 * @since 1.0.0
	 */
	public array $personality;

	/**
	 * Optional explicit system prompt override. When set, it is prepended verbatim.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public string $systemPrompt;

	/**
	 * AI provider id (e.g. "gemini").
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public string $provider;

	/**
	 * Optional specific model id. Null lets the provider pick a default.
	 *
	 * @var string|null
	 * @since 1.0.0
	 */
	public ?string $model;

	/**
	 * Ability source strategy: 'all' or 'selected'.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public string $abilitySource;

	/**
	 * Ability names allowed when abilitySource is 'selected'.
	 *
	 * @var string[]
	 * @since 1.0.0
	 */
	public array $allowedAbilities;

	/**
	 * Maximum number of tool-calling iterations.
	 *
	 * @var int
	 * @since 1.0.0
	 */
	public int $maxToolCalls;

	/**
	 * Whether to automatically include site context in the system instruction.
	 *
	 * @var bool
	 * @since 1.0.0
	 */
	public bool $autoIncludeSiteContext;

	/**
	 * Interaction mode: 'ask', 'plan' or 'execute'.
	 *
	 * In 'ask' and 'plan' modes only read-only abilities are resolved and the
	 * system instruction steers the agent away from making changes.
	 *
	 * @var string
	 * @since 1.1.0
	 */
	public string $mode;

	/**
	 * Ability names the user @-mentioned for this request.
	 *
	 * These are emphasized in the system instruction (the full tool list stays
	 * available); names are validated against the registry by the PromptBuilder.
	 *
	 * @var string[]
	 * @since 1.1.0
	 */
	public array $emphasizedAbilities;

	/**
	 * Constructor.
	 *
	 * @param string      $name                   Agent name.
	 * @param string      $role                   Agent role.
	 * @param string      $goal                   Agent goal.
	 * @param string[]    $personality            Personality traits.
	 * @param string      $systemPrompt           Explicit system prompt override.
	 * @param string      $provider               Provider id.
	 * @param string|null $model                  Model id, or null for provider default.
	 * @param string      $abilitySource          'all' or 'selected'.
	 * @param string[]    $allowedAbilities       Allowed ability names for 'selected'.
	 * @param int         $maxToolCalls           Max tool-calling iterations.
	 * @param bool        $autoIncludeSiteContext Include site context flag.
	 * @param string      $mode                   'ask', 'plan' or 'execute'.
	 * @param string[]    $emphasizedAbilities    Ability names mentioned by the user.
	 *
	 * @since 1.0.0
	 */
	public function __construct(
		string $name = 'AgentMod Assistant',
		string $role = '',
		string $goal = '',
		array $personality = [],
		string $systemPrompt = '',
		string $provider = Constants::AI_PROVIDER_DEFAULT,
		?string $model = null,
		string $abilitySource = 'all',
		array $allowedAbilities = [],
		int $maxToolCalls = Constants::AI_MAX_TOOL_CALLS,
		bool $autoIncludeSiteContext = true,
		string $mode = 'execute',
		array $emphasizedAbilities = []
	) {
		$this->name                   = $name;
		$this->role                   = $role;
		$this->goal                   = $goal;
		$this->personality            = $personality;
		$this->systemPrompt           = $systemPrompt;
		$this->provider               = $provider;
		$this->model                  = $model;
		$this->abilitySource          = in_array($abilitySource, ['all', 'selected'], true) ? $abilitySource : 'all';
		$this->allowedAbilities       = $allowedAbilities;
		$this->maxToolCalls           = $maxToolCalls > 0 ? $maxToolCalls : Constants::aiMaxToolCalls();
		$this->autoIncludeSiteContext = $autoIncludeSiteContext;
		$this->mode                   = in_array($mode, ['ask', 'plan', 'execute'], true) ? $mode : 'execute';
		$this->emphasizedAbilities    = $emphasizedAbilities;
	}

	/**
	 * Builds an AgentConfig from a (sanitized) associative array.
	 *
	 * Intended to be fed a sanitized array coming from a REST request body.
	 *
	 * @param array $data Raw/sanitized agent definition.
	 *
	 * @return self
	 * @since 1.0.0
	 */
	public static function fromArray(array $data): self
	{
		$personality = $data['personality'] ?? [];
		if (is_string($personality)) {
			$personality = array_filter(array_map('trim', explode(',', $personality)));
		}

		$allowed = $data['allowedAbilities'] ?? ($data['allowed_abilities'] ?? []);
		if (is_string($allowed)) {
			$allowed = array_filter(array_map('trim', explode(',', $allowed)));
		}

		$emphasized = $data['emphasizedAbilities'] ?? ($data['emphasized_abilities'] ?? []);
		if (is_string($emphasized)) {
			$emphasized = array_filter(array_map('trim', explode(',', $emphasized)));
		}

		return new self(
			(string) ($data['name'] ?? 'AgentMod Assistant'),
			(string) ($data['role'] ?? ''),
			(string) ($data['goal'] ?? ''),
			array_values((array) $personality),
			(string) ($data['systemPrompt'] ?? ($data['system_prompt'] ?? '')),
			(string) ($data['provider'] ?? Constants::AI_PROVIDER_DEFAULT),
			isset($data['model']) && '' !== $data['model'] ? (string) $data['model'] : null,
			(string) ($data['abilitySource'] ?? ($data['ability_source'] ?? 'all')),
			array_values((array) $allowed),
			(int) ($data['maxToolCalls'] ?? ($data['max_tool_calls'] ?? Constants::aiMaxToolCalls())),
			(bool) ($data['autoIncludeSiteContext'] ?? ($data['auto_include_site_context'] ?? true)),
			(string) ($data['mode'] ?? 'execute'),
			array_values((array) $emphasized)
		);
	}
}
