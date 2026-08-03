<?php

/**
 * In-loop history compactor.
 *
 * The agentic tool-calling loop re-sends the whole conversation history to the
 * provider on every iteration. Without compaction that is O(n²) token growth:
 * a large tool result (skill body, template HTML) fetched at iteration 2 is
 * re-uploaded verbatim on every later iteration, and screenshot attachments on
 * the user message are re-uploaded too. This service compacts the copy of the
 * history that goes to the provider: old tool results are elided to short
 * stubs (their call id and name are preserved — providers require a response
 * for every outstanding call), and binary file parts are kept only on the most
 * recent user message. The canonical history kept for persistence and the
 * confirmation/resume flow is never modified.
 *
 * @package AgentMod
 * @subpackage Services\AI
 * @since 1.1.5
 */

namespace AgentMod\Services\AI;

defined('ABSPATH') || exit;

use AgentMod\Common\Constants;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use WP_AI_Client_Ability_Function_Resolver;

class HistoryCompactor
{
    /**
     * Compacts a history for sending to the provider.
     *
     * @param Message[] $history Conversation history (never mutated).
     *
     * @return Message[] Compacted copy.
     * @since 1.1.5
     */
    public function compact(array $history): array
    {
        /**
         * Filters how many of the most recent tool-result messages are re-sent
         * verbatim; older ones are elided to stubs.
         *
         * @param int $keep Number of tool-result messages kept verbatim.
         *
         * @since 1.1.5
         */
        $keep = max(0, (int) apply_filters('agent_mod_history_keep_tool_results', Constants::AI_HISTORY_KEEP_TOOL_RESULTS));

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

        // Tool-result messages recent enough to stay verbatim. (array_slice
        // with -0 would return the whole array, so 0 is handled explicitly.)
        $verbatim = $keep > 0 ? array_flip(array_slice($toolResultIndexes, -$keep)) : [];

        // Only the most recent attachment-bearing user message keeps its files.
        $lastFileIndex = empty($fileMessageIndexes) ? -1 : (int) end($fileMessageIndexes);

        $sticky = $this->resolveStickyParts($history, $toolResultIndexes);

        $compacted = [];

        foreach ($history as $index => $message) {
            if (! $message instanceof Message) {
                $compacted[] = $message;
                continue;
            }

            if (in_array($index, $toolResultIndexes, true) && ! isset($verbatim[$index])) {
                $compacted[] = $this->elideToolResults($message, $sticky[$index] ?? []);
                continue;
            }

            if (
                in_array($index, $fileMessageIndexes, true)
                && $index !== $lastFileIndex
            ) {
                $compacted[] = $this->stripFiles($message);
                continue;
            }

            $compacted[] = $message;
        }

        return $compacted;
    }

    /**
     * Determines which function-response parts of old tool-result messages are
     * exempt from elision ("sticky").
     *
     * For each sticky tool (by default the Pro skill fetcher) only the most
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
        /**
         * Filters the ability names whose most recent result per argument
         * identity is never elided from the in-loop history.
         *
         * @param string[] $stickyTools Ability names.
         *
         * @since 1.1.5
         */
        $stickyTools = (array) apply_filters('agent_mod_history_sticky_tools', ['agent-mod-pro/get-skill-by-slug']);

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

            $summary = mb_substr(
                (string) wp_json_encode($response->getResponse()),
                0,
                Constants::AI_TOOL_RESULT_STUB_LENGTH
            );

            $parts[] = new MessagePart(
                new FunctionResponse(
                    (string) $response->getId(),
                    (string) $response->getName(),
                    [
                        'elided'  => true,
                        'summary' => $summary . '… ' . __('(older tool result elided to save context; re-call the tool only if you truly need the full data again)', 'agent-mod'),
                    ]
                ),
                $part->getChannel()
            );
        }

        return new Message($message->getRole(), $parts);
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
