<?php

/**
 * In-loop history compactor.
 *
 * The agentic tool-calling loop re-sends the whole conversation history to the
 * provider on every iteration. Without compaction that is O(n²) token growth:
 * a large tool result (skill body, template HTML) fetched at iteration 2 is
 * re-uploaded verbatim on every later iteration, and screenshot attachments on
 * the user message are re-uploaded too. This service compacts the copy of the
 * history that goes to the provider: binary file parts are kept only on the
 * most recent user message, and once the history grows past a token threshold
 * old tool results are elided to short summaries (their call id and name are
 * preserved — providers require a response for every outstanding call). The
 * canonical history kept for persistence and the confirmation/resume flow is
 * never modified.
 *
 * Elision is threshold-based and sticky on purpose. Below the trigger nothing
 * is elided, so the model keeps every result it fetched. When the trigger is
 * crossed, results are elided oldest first down to a lower target and a
 * watermark records how far it went; later iterations elide exactly the same
 * messages until the next crossing. The sent prefix therefore stays byte-
 * identical between compactions, which keeps provider prompt caches warm —
 * a window sliding on every iteration would invalidate them each time.
 *
 * @package AgentMod
 * @subpackage Services\AI
 * @since 1.1.5
 */

namespace AgentMod\Services\AI;

defined('ABSPATH') || exit;

use AgentMod\Common\Constants;
use AgentMod\Services\AskUserAbilityService;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use WP_AI_Client_Ability_Function_Resolver;

class HistoryCompactor
{
    /**
     * Item keys that mark a tool result value as a list of addressable items.
     *
     * @var string[]
     * @since 1.3.3
     */
    private const LIST_ID_KEYS = ['post_id', 'post_ids', 'id', 'ID', 'term_id', 'user_id', 'navigation_id'];

    /**
     * Item keys kept in the compact summary of a list result.
     *
     * @var string[]
     * @since 1.3.3
     */
    private const LIST_ITEM_KEYS = ['post_id', 'post_ids', 'id', 'ID', 'term_id', 'user_id', 'navigation_id', 'title', 'name', 'slug', 'status', 'post_type', 'matches'];

    /**
     * Keys kept at every depth in the structure summary of a nested result
     * (e.g. a field configuration tree), so its skeleton survives elision.
     *
     * @var string[]
     * @since 1.3.4
     */
    private const STRUCTURE_KEYS = [
        'id', 'ID', 'post_id', 'term_id', 'user_id', 'name', 'slug', 'title', 'label', 'status', 'post_type', 'taxonomy',
        'type', 'fieldType', 'fieldLabel', 'meta_box_id', 'meta_box_title', 'section_name', 'section_title', 'menu_slug',
        'options', 'multiple', 'required', 'default',
    ];

    /**
     * Maximum length of a top-level string kept in a structure summary.
     *
     * @var int
     * @since 1.3.4
     */
    private const STRUCTURE_STRING_LENGTH = 200;

    /**
     * Rough characters-per-token ratio used to estimate history size.
     *
     * @var int
     * @since 1.3.4
     */
    private const CHARS_PER_TOKEN = 4;

    /**
     * Estimated token cost of one file part (image / PDF attachment).
     *
     * @var int
     * @since 1.3.4
     */
    private const FILE_PART_TOKENS = 1500;

    /**
     * Compacts a history for sending to the provider.
     *
     * Pass the same $watermark variable on every iteration of one run: it
     * records up to which history index tool results have been elided, so
     * later calls elide the same messages and only move it on the next
     * threshold crossing.
     *
     * @param Message[] $history   Conversation history (never mutated).
     * @param int       $watermark History index below which tool results are elided; advanced in place.
     *
     * @return Message[] Compacted copy.
     * @since 1.1.5
     */
    public function compact(array $history, int &$watermark = 0): array
    {
        /**
         * Filters how many of the most recent tool-result messages are always
         * re-sent verbatim, even once the history has been compacted.
         *
         * @param int $keep Number of tool-result messages kept verbatim.
         *
         * @since 1.1.5
         */
        $keep = max(0, (int) apply_filters('agent_mod_history_keep_tool_results', Constants::AI_HISTORY_KEEP_TOOL_RESULTS));

        /**
         * Filters the estimated history size (tokens) at which old tool
         * results start being elided.
         *
         * @param int $trigger Token threshold.
         *
         * @since 1.3.4
         */
        $trigger = max(0, (int) apply_filters('agent_mod_history_compact_trigger_tokens', Constants::AI_HISTORY_COMPACT_TRIGGER_TOKENS));

        /**
         * Filters the estimated history size (tokens) a compaction elides
         * down to. Kept below the trigger so compactions stay rare.
         *
         * @param int $target Token target.
         *
         * @since 1.3.4
         */
        $target = min($trigger, max(0, (int) apply_filters('agent_mod_history_compact_target_tokens', Constants::AI_HISTORY_COMPACT_TARGET_TOKENS)));

        $toolResultIndexes = [];
        $fileMessageIndexes = [];

        foreach ($history as $index => $message) {
            if (! $message instanceof Message) {
                continue;
            }

            if ($this->hasFunctionResponse($message)) {
                $toolResultIndexes[] = $index;
            } elseif ($message->getRole()->isUser() && $this->hasFile($message)) {
                $fileMessageIndexes[] = $index;
            }
        }

        // The first tool-result message recent enough to always stay verbatim.
        // (array_slice with -0 would return the whole array, so 0 is handled
        // explicitly.)
        $recent      = $keep > 0 ? array_slice($toolResultIndexes, -$keep) : [];
        $firstRecent = empty($recent) ? PHP_INT_MAX : (int) reset($recent);

        // Only the most recent attachment-bearing user message keeps its files.
        $lastFileIndex = empty($fileMessageIndexes) ? -1 : (int) end($fileMessageIndexes);

        $sticky = $this->resolveStickyParts($history, $toolResultIndexes);

        $compacted = [];

        foreach ($history as $index => $message) {
            if (! $message instanceof Message) {
                $compacted[$index] = $message;
                continue;
            }

            // Everything below the watermark was elided by an earlier
            // compaction of this run and stays elided, so the prefix the
            // provider sees does not change between compactions.
            if ($index < $watermark && in_array($index, $toolResultIndexes, true)) {
                $compacted[$index] = $this->elideToolResults($message, $sticky[$index] ?? []);
                continue;
            }

            if (
                in_array($index, $fileMessageIndexes, true)
                && $index !== $lastFileIndex
            ) {
                $compacted[$index] = $this->stripFiles($message);
                continue;
            }

            $compacted[$index] = $message;
        }

        $tokens = $this->estimateTokens($compacted);

        if ($tokens < $trigger) {
            return array_values($compacted);
        }

        // Over the threshold: elide the oldest results down to the target and
        // move the watermark past the last one elided.
        foreach ($toolResultIndexes as $index) {
            if ($tokens <= $target || $index >= $firstRecent) {
                break;
            }

            if ($index < $watermark) {
                continue;
            }

            $before            = $this->estimateTokens([$compacted[$index]]);
            $compacted[$index] = $this->elideToolResults($history[$index], $sticky[$index] ?? []);
            $tokens           -= $before - $this->estimateTokens([$compacted[$index]]);
            $watermark         = $index + 1;
        }

        return array_values($compacted);
    }

    /**
     * Estimates the token size of a history.
     *
     * A characters / 4 heuristic over text and JSON-encoded function call and
     * response parts; each file part counts as a fixed amount. Only used to
     * decide when to compact, so a rough figure is enough.
     *
     * @param Message[] $history Conversation history.
     *
     * @return int Estimated tokens.
     * @since 1.3.4
     */
    public function estimateTokens(array $history): int
    {
        $chars = 0;
        $files = 0;

        foreach ($history as $message) {
            if (! $message instanceof Message) {
                continue;
            }

            foreach ($message->getParts() as $part) {
                $type = $part->getType();

                if ($type->isFile()) {
                    $files++;
                } elseif ($type->isFunctionCall()) {
                    $call   = $part->getFunctionCall();
                    $chars += null === $call ? 0 : strlen((string) wp_json_encode($call->getArgs())) + strlen((string) $call->getName());
                } elseif ($type->isFunctionResponse()) {
                    $response = $part->getFunctionResponse();
                    $chars   += null === $response ? 0 : strlen((string) wp_json_encode($response->getResponse()));
                } else {
                    $chars += strlen((string) $part->getText());
                }
            }
        }

        return (int) ceil($chars / self::CHARS_PER_TOKEN) + $files * self::FILE_PART_TOKENS;
    }

    /**
     * Whether an ability's results are exempt from elision.
     *
     * By default the Pro skill fetcher, the ask-user / request-approval
     * answers and the Native Custom Fields configuration reads: a field
     * configuration is what the agent writes code against, and losing it
     * mid-task sends the agent back to re-read it or to guess meta keys.
     *
     * @param string $abilityName Ability name.
     *
     * @return bool
     * @since 1.3.4
     */
    public function isStickyTool(string $abilityName): bool
    {
        return in_array($abilityName, $this->stickyTools(), true);
    }

    /**
     * Returns the ability names whose results are never elided.
     *
     * @return string[]
     * @since 1.3.4
     */
    private function stickyTools(): array
    {
        /**
         * Filters the ability names whose most recent result per argument
         * identity is never elided from the in-loop history.
         *
         * @param string[] $stickyTools Ability names.
         *
         * @since 1.1.5
         */
        // The user's answers to ask-user / request-approval are their own input,
        // so they are never elided either.
        return (array) apply_filters(
            'agent_mod_history_sticky_tools',
            array_merge(
                [
                    'agent-mod-pro/get-skill-by-slug',
                    'native-custom-fields/get-post-meta-fields',
                    'native-custom-fields/get-term-meta-fields',
                    'native-custom-fields/get-user-meta-fields',
                    'native-custom-fields/get-options-page',
                ],
                AskUserAbilityService::PAUSING_TOOLS
            )
        );
    }

    /**
     * Determines which function-response parts of old tool-result messages are
     * exempt from elision ("sticky").
     *
     * For each sticky tool (by default the Pro skill fetcher and the
     * ask-user / request-approval answers) only the most
     * recent response per distinct argument identity is kept in full, so a
     * fetched skill body survives compaction while duplicate fetches of the
     * same skill are still elided.
     *
     * @param Message[] $history           Conversation history.
     * @param int[]     $toolResultIndexes Indexes of tool-result messages.
     *
     * @return array<int, array<int, true>> Map of message index => part index => sticky.
     * @since 1.1.5
     */
    private function resolveStickyParts(array $history, array $toolResultIndexes): array
    {
        $stickyTools = $this->stickyTools();

        if (empty($stickyTools)) {
            return [];
        }

        // Walk chronologically; the last write per identity key wins.
        $latest = [];

        foreach ($toolResultIndexes as $index) {
            $message = $history[$index];

            foreach ($message->getParts() as $partIndex => $part) {
                $response = $part->getFunctionResponse();

                if (! $response instanceof FunctionResponse) {
                    continue;
                }

                $abilityName = $this->toAbilityName((string) $response->getName());

                if (! in_array($abilityName, $stickyTools, true)) {
                    continue;
                }

                $latest[$abilityName . '|' . $this->responseIdentity($response)] = [$index, $partIndex];
            }
        }

        $sticky = [];

        foreach ($latest as $location) {
            [$index, $partIndex] = $location;
            $sticky[$index][$partIndex] = true;
        }

        return $sticky;
    }

    /**
     * Derives a stable identity for a sticky tool response.
     *
     * Skill responses carry their slug in the payload; anything else falls
     * back to a hash of the whole payload so distinct results never collide.
     *
     * @param FunctionResponse $response The function response.
     *
     * @return string
     * @since 1.1.5
     */
    private function responseIdentity(FunctionResponse $response): string
    {
        $payload = $response->getResponse();

        if (is_array($payload) && isset($payload['slug']) && is_string($payload['slug'])) {
            return $payload['slug'];
        }

        return md5((string) wp_json_encode($payload));
    }

    /**
     * Replaces non-sticky function responses of a message with short stubs.
     *
     * @param Message               $message     Tool-result message.
     * @param array<int, true>      $stickyParts Part indexes exempt from elision.
     *
     * @return Message
     * @since 1.1.5
     */
    private function elideToolResults(Message $message, array $stickyParts): Message
    {
        $parts = [];

        foreach ($message->getParts() as $partIndex => $part) {
            $response = $part->getFunctionResponse();

            if (! $response instanceof FunctionResponse || isset($stickyParts[$partIndex])) {
                $parts[] = $part;
                continue;
            }

            // A list keeps its IDs and titles: losing them is what sent the
            // agent back to list the same posts again and again. A nested
            // result (e.g. a field configuration) keeps its skeleton.
            $listSummary      = $this->summarizeListResult($response->getResponse());
            $structureSummary = null === $listSummary ? $this->summarizeStructure($response->getResponse()) : null;

            if (null !== $listSummary) {
                $payload = [
                    'elided'       => true,
                    'list_summary' => $listSummary,
                    'note'         => __('Older list result reduced to IDs and titles to save context. Reuse these IDs; do not list the same items again.', 'agent-mod'),
                ];
            } elseif (null !== $structureSummary) {
                $payload = [
                    'elided'            => true,
                    'structure_summary' => $structureSummary,
                    'note'              => __('Older result reduced to its structure (names, types, labels) to save context. If you need the omitted details, call the same tool again.', 'agent-mod'),
                ];
            } else {
                $payload = [
                    'elided'  => true,
                    'summary' => mb_substr(
                        (string) wp_json_encode($response->getResponse(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        0,
                        Constants::AI_TOOL_RESULT_STUB_LENGTH
                    ) . '… ' . __('(older tool result elided to save context; if you need the full data, call the same tool again)', 'agent-mod'),
                ];
            }

            $parts[] = new MessagePart(
                new FunctionResponse(
                    (string) $response->getId(),
                    (string) $response->getName(),
                    $payload
                ),
                $part->getChannel()
            );
        }

        return new Message($message->getRole(), $parts);
    }

    /**
     * Reduces a list-shaped tool result to the IDs and titles of its items.
     *
     * A result counts as a list when one of its top-level values is a list of
     * items carrying an ID (post_id, term_id …), e.g. list-posts' "posts" or
     * bulk-replace's "results". Scalars and small bookkeeping values
     * (pagination, summary, unmatched titles) are kept; everything else per
     * item (URLs, dates, previews) is dropped.
     *
     * @param mixed $payload Tool result.
     *
     * @return string|null JSON summary capped at AI_LIST_RESULT_SUMMARY_LENGTH, or null when the result is not a list.
     * @since 1.3.3
     */
    public function summarizeListResult($payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        $summary = [];
        $isList  = false;
        $keep    = array_flip(self::LIST_ITEM_KEYS);

        foreach ($payload as $key => $value) {
            if ($this->isIdList($value)) {
                $summary[$key] = array_map(static function ($item) use ($keep) {
                    return is_array($item) ? array_intersect_key($item, $keep) : $item;
                }, $value);
                $isList = true;
            } elseif (is_scalar($value) || in_array($key, ['pagination', 'summary', 'unmatched_titles'], true)) {
                $summary[$key] = $value;
            }
        }

        if (! $isList) {
            return null;
        }

        /**
         * Filters the compact summary kept for an elided or persisted list result.
         *
         * @param array<string, mixed> $summary Compact summary.
         * @param array<string, mixed> $payload Full tool result.
         *
         * @since 1.3.3
         */
        $summary = (array) apply_filters('agent_mod_compact_list_result', $summary, $payload);

        return mb_substr(
            (string) wp_json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            0,
            Constants::AI_LIST_RESULT_SUMMARY_LENGTH
        );
    }

    /**
     * Reduces a nested tool result to its structural skeleton.
     *
     * Applies to results that hold nested arrays but are not ID lists, such
     * as a Native Custom Fields configuration (sections → fields → sub-fields).
     * Top-level scalars are kept (long strings cut short); below the top level
     * only identifying keys (name, type, fieldType, label, options …) are kept,
     * at any depth.
     *
     * @param mixed $payload Tool result.
     *
     * @return string|null JSON summary capped at AI_LIST_RESULT_SUMMARY_LENGTH, or null when the result has no nested structure.
     * @since 1.3.4
     */
    public function summarizeStructure($payload): ?string
    {
        if (! is_array($payload) || empty(array_filter($payload, 'is_array'))) {
            return null;
        }

        $skeleton = $this->skeleton($payload, 0);

        if (empty($skeleton)) {
            return null;
        }

        return mb_substr(
            (string) wp_json_encode($skeleton, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            0,
            Constants::AI_LIST_RESULT_SUMMARY_LENGTH
        );
    }

    /**
     * Recursively keeps the structural keys of a nested array.
     *
     * @param array<mixed> $value Array to reduce.
     * @param int          $depth Current depth (0 = top level).
     *
     * @return array<mixed>
     * @since 1.3.4
     */
    private function skeleton(array $value, int $depth): array
    {
        $keep = array_flip(self::STRUCTURE_KEYS);
        $out  = [];

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $child = $this->skeleton($item, $depth + 1);

                if (! empty($child)) {
                    $out[$key] = $child;
                }
            } elseif (0 === $depth) {
                $out[$key] = is_string($item) ? mb_substr($item, 0, self::STRUCTURE_STRING_LENGTH) : $item;
            } elseif (is_int($key) || isset($keep[$key])) {
                $out[$key] = $item;
            }
        }

        return $out;
    }

    /**
     * Whether a value is a non-empty list of items that carry an ID.
     *
     * @param mixed $value Candidate value.
     *
     * @return bool
     * @since 1.3.3
     */
    private function isIdList($value): bool
    {
        if (! is_array($value) || empty($value) || array_keys($value) !== range(0, count($value) - 1)) {
            return false;
        }

        $first = reset($value);

        return is_array($first) && ! empty(array_intersect_key($first, array_flip(self::LIST_ID_KEYS)));
    }

    /**
     * Removes file parts from a user message, leaving a text placeholder.
     *
     * @param Message $message User message with file parts.
     *
     * @return Message
     * @since 1.1.5
     */
    private function stripFiles(Message $message): Message
    {
        $parts   = [];
        $removed = 0;

        foreach ($message->getParts() as $part) {
            if ($part->getType()->isFile()) {
                $removed++;
                continue;
            }

            $parts[] = $part;
        }

        if ($removed > 0) {
            $parts[] = new MessagePart(
                sprintf(
                    /* translators: %d: number of attachments omitted. */
                    __('[%d earlier attachment(s) omitted to save context]', 'agent-mod'),
                    $removed
                )
            );
        }

        return new Message($message->getRole(), $parts);
    }

    /**
     * Maps a provider function name back to its ability name when possible.
     *
     * @param string $functionName Function name from the response part.
     *
     * @return string
     * @since 1.1.5
     */
    private function toAbilityName(string $functionName): string
    {
        if (class_exists(WP_AI_Client_Ability_Function_Resolver::class)) {
            return (string) WP_AI_Client_Ability_Function_Resolver::function_name_to_ability_name($functionName);
        }

        return $functionName;
    }

    /**
     * Whether a message contains a function-response part.
     *
     * @param Message $message The message.
     *
     * @return bool
     * @since 1.1.5
     */
    private function hasFunctionResponse(Message $message): bool
    {
        foreach ($message->getParts() as $part) {
            if ($part->getType()->isFunctionResponse()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a message contains a file part.
     *
     * @param Message $message The message.
     *
     * @return bool
     * @since 1.1.5
     */
    private function hasFile(Message $message): bool
    {
        foreach ($message->getParts() as $part) {
            if ($part->getType()->isFile()) {
                return true;
            }
        }

        return false;
    }
}
