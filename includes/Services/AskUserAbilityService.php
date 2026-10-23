<?php

/**
 * Ask-user ability service.
 *
 * Gives the agent a structured way to ask the user questions. The ability is
 * never executed for real: the generation loop intercepts the call, pauses the
 * run and the chat UI shows the questions in a modal (radio buttons for a
 * single choice, checkboxes for several, a free-text field for anything
 * else). The answers come back as the call's function response, so the model
 * continues its own run instead of the user having to reply in the composer.
 *
 * The ability is only offered on channels with a person present to answer
 * (see AgentConfig::$interactive and AbilityResolver).
 *
 * This class also owns the two normalizations both ends of that exchange
 * rely on: the questions the model sent, and the answers the user returned.
 *
 * @package AgentMod
 * @subpackage Services
 * @since x.x.x
 */

namespace AgentMod\Services;

use WP_Error;

defined('ABSPATH') || exit;

class AskUserAbilityService
{
	/**
	 * Ability name.
	 *
	 * @var string
	 * @since x.x.x
	 */
	public const NAME = 'agent-mod/ask-user';

	/**
	 * Plan-delivery ability name (Plan mode only).
	 *
	 * @var string
	 * @since x.x.x
	 */
	public const PRESENT_PLAN = 'agent-mod/present-plan';

	/**
	 * Go-ahead ability name (Execute mode with manual approval only).
	 *
	 * @var string
	 * @since x.x.x
	 */
	public const REQUEST_APPROVAL = 'agent-mod/request-approval';

	/**
	 * Abilities that pause the run until the user answers in a modal.
	 *
	 * @var string[]
	 * @since x.x.x
	 */
	public const PAUSING_TOOLS = [self::NAME, self::REQUEST_APPROVAL];

	/**
	 * Answer values of a request-approval call.
	 *
	 * @var string[]
	 * @since x.x.x
	 */
	public const APPROVAL_OPTIONS = ['continue', 'cancel'];

	/**
	 * Ability category slug.
	 *
	 * @var string
	 * @since x.x.x
	 */
	private const CATEGORY = 'agent-mod';

	/**
	 * Supported question types.
	 *
	 * @var string[]
	 * @since x.x.x
	 */
	private const TYPES = ['single', 'multiple', 'text'];

	/**
	 * Most questions shown in one modal.
	 *
	 * @var int
	 * @since x.x.x
	 */
	private const MAX_QUESTIONS = 5;

	/**
	 * Most options offered for one question.
	 *
	 * @var int
	 * @since x.x.x
	 */
	private const MAX_OPTIONS = 8;

	/**
	 * Settings service; supplies the longest free-text answer kept.
	 *
	 * @var SettingsService
	 * @since x.x.x
	 */
	private SettingsService $settings;

	/**
	 * Constructor. Binds the abilities API hook.
	 *
	 * @param SettingsService $settings Settings service.
	 *
	 * @since x.x.x
	 */
	public function __construct(SettingsService $settings)
	{
		$this->settings = $settings;

		add_action('wp_abilities_api_init', [$this, 'registerAbilities']);
	}

	/**
	 * Registers the interactive chat abilities: ask-user, present-plan and
	 * request-approval.
	 *
	 * Annotated read-only: it changes nothing on the site, so it is available
	 * in Ask and Plan modes too and never reaches the write-confirmation gate.
	 * The schema sticks to the JSON Schema subset every provider connector
	 * accepts (no oneOf, additionalProperties or pattern); limits are stated in
	 * the descriptions and enforced by normalizeQuestions().
	 *
	 * @return void
	 * @since x.x.x
	 */
	public function registerAbilities(): void
	{
		if (! function_exists('wp_register_ability')) {
			return;
		}

		wp_register_ability(
			self::NAME,
			[
				'label'               => __('Ask the User', 'agent-mod'),
								// Deliberately says nothing about how often to ask: that bar differs
				// per mode (low in Plan, high in Execute) and is set by the mode
				// directive in PromptBuilder.
				'description'         => __('Asks the user one or more questions in an interactive form and waits for the answers; your run continues as soon as they are answered. When to ask is set by the instructions of the current mode. Ask everything you need in this single call (at most 5 questions) and offer concrete options where you can, with your own proposal marked as recommended: type "single" for one choice, "multiple" for several, "text" for a free-text answer. The user can always add their own answer instead of the options. Do not call other tools in the same turn. To ask for the go-ahead on an action, use agent-mod/request-approval instead when it is available.', 'agent-mod'),
				'category'            => self::CATEGORY,
				// Reached only through the chat loop, which intercepts the call
				// before anything executes.
				'execute_callback'    => static function () {
					return new WP_Error(
						'ask_user_not_interactive',
						__('Questions can only be asked in the interactive chat. Choose a sensible default instead and state it.', 'agent-mod')
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can('read');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'intro'     => [
							'type'        => 'string',
							'description' => __('Optional one or two sentences shown above the questions, e.g. why you are asking.', 'agent-mod'),
						],
						'questions' => [
							'type'        => 'array',
							'description' => __('One to five questions.', 'agent-mod'),
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'id'          => [
										'type'        => 'string',
										'description' => __('Short unique key for the question, e.g. "page_layout".', 'agent-mod'),
									],
									'question'    => [
										'type'        => 'string',
										'description' => __('The question as the user should read it.', 'agent-mod'),
									],
									'type'        => [
										'type'        => 'string',
										'enum'        => self::TYPES,
										'description' => __('"single": pick one option. "multiple": pick any number of options. "text": free-text answer, no options.', 'agent-mod'),
									],
									'options'     => [
										'type'        => 'array',
										'items'       => ['type' => 'string'],
										'description' => __('Two to eight short options for "single" and "multiple". Leave empty for "text".', 'agent-mod'),
									],
									'recommended' => [
										'type'        => 'string',
										'description' => __('Optional: the option you recommend, copied exactly from options. It is preselected.', 'agent-mod'),
									],
									'allow_other' => [
										'type'        => 'boolean',
										'description' => __('Whether the user may type an answer of their own besides the options. Defaults to true.', 'agent-mod'),
									],
								],
								'required'   => ['id', 'question', 'type'],
							],
						],
					],
					'required'   => ['questions'],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
					'show_in_rest' => false,
				],
			]
		);

		// Like ask-user, intercepted by the chat loop and never executed: the
		// plan becomes the turn's answer and the chat opens the review modal
		// (approve and execute, ask for a review, request changes).
		wp_register_ability(
			self::PRESENT_PLAN,
			[
				'label'               => __('Present the Plan', 'agent-mod'),
				'description'         => __('Delivers your finished plan to the user for review. Call it once the plan is complete (after any questions are answered) with the complete plan in Markdown — this replaces writing the plan as a reply. The user then approves it for execution, asks you to review it, or requests changes. Do not call other tools in the same turn.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => static function () {
					return new WP_Error(
						'present_plan_not_interactive',
						__('Plans can only be presented in the interactive chat. Write the plan as your reply instead.', 'agent-mod')
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can('read');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'plan'    => [
							'type'        => 'string',
							'description' => __('The complete plan in Markdown, exactly as the user should read it.', 'agent-mod'),
						],
						'summary' => [
							'type'        => 'string',
							'description' => __('Optional one-sentence summary shown above the plan.', 'agent-mod'),
						],
					],
					'required'   => ['plan'],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
					'show_in_rest' => false,
				],
			]
		);

		// Like ask-user, intercepted by the chat loop and never executed: the
		// chat shows Continue / Cancel / Request changes and the choice comes
		// back as the call's function response.
		wp_register_ability(
			self::REQUEST_APPROVAL,
			[
				'label'               => __('Request Approval', 'agent-mod'),
				'description'         => __('Asks the user for the go-ahead before you carry out an action and waits for the decision; your run continues as soon as they answer. Use it whenever you would otherwise stop and tell the user what you are going to do — for example when you chose a different approach than the user literally asked for, the scope grew, or a step cannot be undone. State exactly what you will do in "summary". The user picks Continue, Cancel or requests changes; on Continue carry the action out with the tools right away. Do not call other tools in the same turn.', 'agent-mod'),
				'category'            => self::CATEGORY,
				'execute_callback'    => static function () {
					return new WP_Error(
						'request_approval_not_interactive',
						__('Approval can only be requested in the interactive chat. Proceed as the user asked instead.', 'agent-mod')
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can('read');
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'summary' => [
							'type'        => 'string',
							'description' => __('What you will do once approved, in one or two sentences, as the user should read it.', 'agent-mod'),
						],
						'details' => [
							'type'        => 'string',
							'description' => __('Optional Markdown: the steps, files or items that will be affected.', 'agent-mod'),
						],
						'reason'  => [
							'type'        => 'string',
							'description' => __('Optional: why you are asking, e.g. that you chose a different approach than requested.', 'agent-mod'),
						],
					],
					'required'   => ['summary'],
				],
				'meta'                => [
					'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
					'show_in_rest' => false,
				],
			]
		);
	}

	/**
	 * Normalizes a request-approval call into the pending-questions shape.
	 *
	 * Reusing that shape lets the approval travel the same pause / answer /
	 * resume path as ask-user; 'kind' tells the chat to show the approval
	 * buttons instead of a question form. Returns no questions when the call
	 * has no summary, so the caller can send the model an error instead.
	 *
	 * @param array<string, mixed> $args The request-approval call arguments.
	 *
	 * @return array{kind: string, intro: string, details: string, reason: string, questions: array<int, array<string, mixed>>}
	 * @since x.x.x
	 */
	public function normalizeApproval(array $args): array
	{
		$summary = mb_substr(sanitize_textarea_field((string) ($args['summary'] ?? '')), 0, 1000);

		return [
			'kind'      => 'approval',
			'intro'     => $summary,
			'details'   => mb_substr(sanitize_textarea_field((string) ($args['details'] ?? '')), 0, 4000),
			'reason'    => mb_substr(sanitize_text_field((string) ($args['reason'] ?? '')), 0, 300),
			'questions' => '' === trim($summary) ? [] : [
				[
					'id'          => 'approval',
					'question'    => $summary,
					'type'        => 'single',
					'options'     => self::APPROVAL_OPTIONS,
					'recommended' => 'continue',
					'allowOther'  => true,
				],
			],
		];
	}

	/**
	 * Normalizes the questions the model sent into the shape the modal renders.
	 *
	 * Providers fill optional fields inconsistently (empty strings, missing
	 * keys, options on a text question), so every field is defaulted here
	 * rather than trusted: a choice question with fewer than two options
	 * becomes a text question, ids are made unique, a recommendation that is
	 * not one of the options is dropped.
	 *
	 * @param array<string, mixed> $args The ask-user call arguments.
	 *
	 * @return array{intro: string, questions: array<int, array<string, mixed>>}
	 * @since x.x.x
	 */
	public function normalizeQuestions(array $args): array
	{
		$questions = [];
		$usedIds   = [];

		foreach (array_slice((array) ($args['questions'] ?? []), 0, self::MAX_QUESTIONS) as $index => $raw) {
			if (! is_array($raw)) {
				continue;
			}

			$text = sanitize_text_field((string) ($raw['question'] ?? ''));

			if ('' === $text) {
				continue;
			}

			$id = sanitize_key((string) ($raw['id'] ?? ''));
			if ('' === $id || isset($usedIds[$id])) {
				$id = 'q' . ($index + 1);
			}
			$usedIds[$id] = true;

			$options = [];
			foreach ((array) ($raw['options'] ?? []) as $option) {
				$option = is_scalar($option) ? sanitize_text_field((string) $option) : '';

				if ('' !== $option && ! in_array($option, $options, true)) {
					$options[] = $option;
				}
			}
			$options = array_slice($options, 0, self::MAX_OPTIONS);

			$type = in_array($raw['type'] ?? '', self::TYPES, true) ? (string) $raw['type'] : 'single';
			if ('text' !== $type && count($options) < 2) {
				$type = 'text';
			}
			if ('text' === $type) {
				$options = [];
			}

			$recommended = sanitize_text_field((string) ($raw['recommended'] ?? ''));

			$questions[] = [
				'id'          => $id,
				'question'    => $text,
				'type'        => $type,
				'options'     => $options,
				'recommended' => in_array($recommended, $options, true) ? $recommended : '',
				// A text question is nothing but "other"; for choice questions
				// only an explicit false turns it off.
				'allowOther'  => 'text' === $type || false !== ($raw['allow_other'] ?? true),
			];
		}

		return [
			'intro'     => mb_substr(sanitize_textarea_field((string) ($args['intro'] ?? '')), 0, 500),
			'questions' => $questions,
		];
	}

	/**
	 * Builds the function response the model receives for its ask-user call.
	 *
	 * Answers are matched to the questions that were actually shown: a
	 * selected value must be one of that question's options and a single
	 * choice keeps one value, so the client cannot feed the model arbitrary
	 * choices. Free text is kept only where the question allowed it.
	 *
	 * @param array{intro?: string, questions?: array<int, array<string, mixed>>} $asked   Normalized questions.
	 * @param mixed                                                                $answers Raw answers keyed by question id,
	 *                                                                                      each {selected: string[], other: string}.
	 * @param bool                                                                 $skipped Whether the user skipped the questions.
	 *
	 * @return array<string, mixed>
	 * @since x.x.x
	 */
	public function buildAnswerPayload(array $asked, $answers, bool $skipped): array
	{
		// Dismissing the approval dialog is a Cancel, never a silent go-ahead.
		if ($skipped && 'approval' === ($asked['kind'] ?? '')) {
			return $this->buildApprovalPayload(['approval' => ['selected' => ['cancel']]]);
		}

		if ($skipped) {
			return [
				'skipped' => true,
				'note'    => __('The user skipped these questions. Continue with sensible defaults and state which ones you chose.', 'agent-mod'),
			];
		}

		$answers = is_array($answers) ? $answers : [];

		if ('approval' === ($asked['kind'] ?? '')) {
			return $this->buildApprovalPayload($answers);
		}

		$result    = [];
		$maxLength = $this->settings->getMaxAnswerLength();

		foreach ((array) ($asked['questions'] ?? []) as $question) {
			$raw = isset($answers[$question['id']]) && is_array($answers[$question['id']])
				? $answers[$question['id']]
				: [];

			// Kept in the order the user picked them; anything that is not one
			// of the question's options is dropped.
			$selected = [];
			foreach ((array) ($raw['selected'] ?? []) as $value) {
				$value = is_scalar($value) ? sanitize_text_field((string) $value) : '';

				if (in_array($value, (array) $question['options'], true) && ! in_array($value, $selected, true)) {
					$selected[] = $value;
				}
			}

			if ('single' === $question['type']) {
				$selected = array_slice($selected, 0, 1);
			}

			$other     = $question['allowOther'] && is_scalar($raw['other'] ?? null)
				? sanitize_textarea_field((string) $raw['other'])
				: '';
			$truncated = mb_strlen($other) > $maxLength;
			$other     = mb_substr($other, 0, $maxLength);

			$entry = [
				'id'       => $question['id'],
				'question' => $question['question'],
				'selected' => $selected,
			];

			if ('' !== $other) {
				$entry['other'] = $other;
			}

			// Tells the model it is not seeing the whole answer, so it does
			// not answer from a partial list as if it were complete.
			if ($truncated) {
				$entry['truncated'] = true;
			}

			if (empty($selected) && '' === $other) {
				$entry['unanswered'] = true;
			}

			$result[] = $entry;
		}

		return ['answers' => $result];
	}

	/**
	 * Builds the function response for a request-approval call.
	 *
	 * Requested changes win over a selected option: the user typed them on
	 * purpose. Only an explicit "continue" approves; anything else, including
	 * an empty answer, is treated as not approved.
	 *
	 * @param array<string, mixed> $answers Raw answers; reads answers['approval'] as {selected: string[], other: string}.
	 *
	 * @return array<string, mixed>
	 * @since x.x.x
	 */
	private function buildApprovalPayload(array $answers): array
	{
		$raw      = isset($answers['approval']) && is_array($answers['approval']) ? $answers['approval'] : [];
		$selected = is_array($raw['selected'] ?? null) ? $raw['selected'] : [];
		$changes  = is_scalar($raw['other'] ?? null) ? trim(sanitize_textarea_field((string) $raw['other'])) : '';

		if ('' !== $changes) {
			$maxLength = $this->settings->getMaxAnswerLength();
			$payload   = [
				'approved'          => false,
				'changes_requested' => mb_substr($changes, 0, $maxLength),
				'note'              => __('The user did not approve as proposed and asked for changes. Rework the approach accordingly; request approval again before acting if it still needs a go-ahead.', 'agent-mod'),
			];

			if (mb_strlen($changes) > $maxLength) {
				$payload['truncated'] = true;
			}

			return $payload;
		}

		if (in_array('continue', $selected, true)) {
			return [
				'approved' => true,
				'note'     => __('The user approved. Carry the action out with the tools now, in this turn.', 'agent-mod'),
			];
		}

		return [
			'approved' => false,
			'note'     => __('The user cancelled. Do not carry the action out; end the turn with a short note.', 'agent-mod'),
		];
	}
}
