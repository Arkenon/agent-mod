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
use AgentMod\Services\SettingsService;

defined('ABSPATH') || exit;

final class AgentConfig
{
	/**
	 * Characters kept from a single personality trait description.
	 *
	 * @var int
	 * @since 1.1.0
	 */
	public const MAX_PERSONALITY_DESCRIPTION_LENGTH = 500;

	/**
	 * Stored agent post ID, or null when the default (settings-based) agent runs.
	 *
	 * Carried on the DTO so consumers that only receive the config (e.g. the
	 * confirm-action flow reading it back from the ConfirmationStore) can still
	 * relate the request to a stored agent.
	 *
	 * @var int|null
	 * @since 1.0.5
	 */
	public ?int $id;

	/**
	 * Human-readable agent name.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public string $name;

	/**
	 * Role description.
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
	 * Always a flat list of labels, whether the caller supplied bare strings or
	 * labelled traits — anything reading this property keeps working unchanged.
	 * Descriptions, when supplied, land in $personalityDetails instead.
	 *
	 * @var string[]
	 * @since 1.0.0
	 */
	public array $personality;

	/**
	 * Descriptions explaining what each personality trait means, keyed by label.
	 *
	 * A bare adjective leaves the model to invent its own reading of "corporate"
	 * or "analytical". A caller that can say what it means — an extension whose
	 * traits are terms with descriptions, for instance — passes them here and
	 * PromptBuilder spells them out in the system prompt.
	 *
	 * Only traits that actually carry a description appear; the array is empty
	 * for the settings-configured defaults, which are plain words.
	 *
	 * @var array<string, string>
	 * @since 1.1.0
	 */
	public array $personalityDetails = [];

	/**
	 * AI provider id (e.g. "gemini", empty string means "auto").
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
	 * Whether the agent may use the provider's native web search tool.
	 *
	 * @var bool
	 * @since 1.0.6
	 */
	public bool $webSearchEnabled;

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
	 * User-managed base system prompt, or built-in defaults.
	 *
	 * @var string
	 * @since 1.1.0
	 */
	public string $baseSystemPrompt;

	/**
	 * Constructor.
	 *
	 * @param string|null $name                   Agent name.
	 * @param string|null $role                   Agent role.
	 * @param string|null $goal                   Agent goal.
	 * @param array       $personality            Personality traits. Each entry is either a
	 *                                            plain label, or an array shaped
	 *                                            {label: string, description?: string}.
	 * @param string|null $provider               Provider id, or null to use the settings-configured default model's provider.
	 * @param string|null $model                  Model id belonging to $provider, or null for that provider's own default.
	 * @param string      $abilitySource          'all' or 'selected'.
	 * @param string[]    $allowedAbilities       Allowed ability names for 'selected'.
	 * @param int|null    $maxToolCalls           Max tool-calling iterations.
	 * @param bool|null   $autoIncludeSiteContext Include site context flag.
	 * @param bool|null   $webSearchEnabled       Enable native web search flag.
	 * @param string      $mode                   'ask', 'plan' or 'execute'.
	 * @param string[]    $emphasizedAbilities    Ability names mentioned by the user.
	 * @param string|null $baseSystemPrompt       Base system prompt.
	 * @param int|null    $id                     Stored agent post ID, or null for the default agent.
	 *
	 * @since 1.0.0
	 */
	public function __construct(
		?string $name = null,
		?string $role = null,
		?string $goal = null,
		array $personality = [],
		?string $provider = null,
		?string $model = null,
		?string $abilitySource = 'all',
		array $allowedAbilities = [],
		?int $maxToolCalls = null,
		?bool $autoIncludeSiteContext = true,
		?bool $webSearchEnabled = null,
		string $mode = 'execute',
		array $emphasizedAbilities = [],
		?string $baseSystemPrompt = null,
		?int $id = null
	) {
		$settingsService = new SettingsService();

		$this->id                     = ($id !== null && $id > 0) ? $id : null;
		$this->name                   = $name ?? Constants::AI_AGENT_DEFAULT_NAME;
		$this->role                   = $role ?? $settingsService->getRole();
		$this->goal                   = $goal ?? $settingsService->getGoal();

		// Normalised here rather than in fromArray() so a caller that builds the
		// DTO directly gets the same treatment.
		[$this->personality, $this->personalityDetails] = self::splitPersonality(
			empty($personality) ? $settingsService->getPersonalityTraits() : $personality
		);

		// Provider and model always travel together: a model only makes sense
		// for the provider that offers it. Either the caller supplies its own
		// pair (chat toolbar pick, or a Pro agent's configured model), or the
		// settings-configured default pair applies wholesale. They are never
		// mixed, which would pair a model with a provider that has never heard
		// of it.
		if (null !== $provider && '' !== $provider) {
			$this->provider = $provider;
			$this->model    = $model;
		} else {
			$defaultModel   = $settingsService->getDefaultModel();
			$this->provider = $defaultModel['provider'];
			$this->model    = $defaultModel['model'];
		}

		$resolvedAbilitySource        = $abilitySource ?? $settingsService->getAbilitySource();
		$this->abilitySource          = in_array($resolvedAbilitySource, ['all', 'selected'], true) ? $resolvedAbilitySource : 'all';
		$this->allowedAbilities       = empty($allowedAbilities) ? $settingsService->getAllowedAbilities() : $allowedAbilities;
		$this->maxToolCalls           = ($maxToolCalls !== null && $maxToolCalls > 0) ? $maxToolCalls : $settingsService->getMaxToolCalls();
		$this->autoIncludeSiteContext = $autoIncludeSiteContext ?? $settingsService->isSiteContextEnabled();
		$this->webSearchEnabled       = $webSearchEnabled ?? $settingsService->isWebSearchEnabled();
		$this->mode                   = in_array($mode, ['ask', 'plan', 'execute'], true) ? $mode : 'execute';
		$this->emphasizedAbilities    = $emphasizedAbilities;
		$this->baseSystemPrompt       = $baseSystemPrompt ?? $settingsService->getSystemPrompt();
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
		if (is_string($personality) && !empty($personality)) {
			$personality = array_filter(array_map('trim', explode(',', $personality)));
		}

		$allowed = $data['allowedAbilities'] ?? ($data['allowed_abilities'] ?? []);
		if (is_string($allowed) && !empty($allowed)) {
			$allowed = array_filter(array_map('trim', explode(',', $allowed)));
		}

		$emphasized = $data['emphasizedAbilities'] ?? ($data['emphasized_abilities'] ?? []);
		if (is_string($emphasized) && !empty($emphasized)) {
			$emphasized = array_filter(array_map('trim', explode(',', $emphasized)));
		}

		return new self(
			isset($data['name']) ? trim((string) $data['name']) : null,
			isset($data['role']) ? trim((string) $data['role']) : null,
			isset($data['goal']) ? trim((string) $data['goal']) : null,
			array_values((array) $personality),
			isset($data['provider']) && '' !== $data['provider'] ? (string) $data['provider'] : null,
			isset($data['model']) && '' !== $data['model'] ? (string) $data['model'] : null,
			isset($data['abilitySource']) ? (string) $data['abilitySource'] : (isset($data['ability_source']) ? (string) $data['ability_source'] : null),
			array_values((array) $allowed),
			isset($data['maxToolCalls']) ? (int) $data['maxToolCalls'] : (isset($data['max_tool_calls']) ? (int) $data['max_tool_calls'] : null),
			isset($data['autoIncludeSiteContext']) ? (bool) $data['autoIncludeSiteContext'] : null,
			isset($data['webSearchEnabled']) ? (bool) $data['webSearchEnabled'] : (isset($data['web_search_enabled']) ? (bool) $data['web_search_enabled'] : null),
			isset($data['mode']) ? (string) $data['mode'] : 'execute',
			array_values((array) $emphasized),
			isset($data['baseSystemPrompt']) ? (string) $data['baseSystemPrompt'] : null,
			isset($data['id']) ? (int) $data['id'] : null
		);
	}

	/**
	 * Splits a mixed personality list into labels and their descriptions.
	 *
	 * Accepts both shapes so the two producers can differ without either having
	 * to know about the other: the settings page stores plain words, while an
	 * extension backing traits with real definitions passes
	 * {label, description} entries. Labels always come out as a flat list, which
	 * is what $personality has always been.
	 *
	 * @param array $traits Mixed list of labels and/or labelled traits.
	 *
	 * @return array{0: string[], 1: array<string, string>} Labels, then descriptions keyed by label.
	 * @since 1.1.0
	 */
	private static function splitPersonality(array $traits): array
	{
		$labels  = [];
		$details = [];

		foreach ($traits as $trait) {
			if (is_array($trait)) {
				$label       = trim((string) ($trait['label'] ?? ''));
				$description = trim((string) ($trait['description'] ?? ''));
			} else {
				$label       = trim((string) $trait);
				$description = '';
			}

			if ('' === $label) {
				continue;
			}

			$labels[] = $label;

			if ('' !== $description) {
				// Capped because this text is written by whoever defines the
				// trait and is reused on every single request. Long enough for a
				// couple of sentences, which is all a tone brief needs to be.
				$details[$label] = mb_substr(wp_strip_all_tags($description), 0, self::MAX_PERSONALITY_DESCRIPTION_LENGTH);
			}
		}

		return [array_values(array_unique($labels)), $details];
	}
}
