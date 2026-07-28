<?php

/**
 * Anthropic (Claude) connector repairer.
 *
 * Single, self-contained workaround layer for every known quirk of the bundled
 * "AI Provider for Anthropic" connector. It is the HTTP-layer mirror of the two
 * upstream fix branches submitted as PRs; each numbered repair below maps 1:1 to
 * one of them and becomes a harmless no-op once the fixed connector ships:
 *
 * 1. Thinking-signature round-trip (fix/anthropic-thinking-signature,
 *    anthropic #30): with extended thinking active, Claude returns each
 *    `thinking` content block with an opaque `signature` that must be echoed
 *    back unchanged on every subsequent turn, otherwise the API rejects the
 *    request with "messages.N.content.0.thinking.signature: Field required".
 *    The connector discards the signature when parsing and re-emits the block
 *    without it, so any multi-step tool-calling turn that began with a thinking
 *    block breaks on the next request. The signature is captured here from the
 *    raw response (matched by a hash of the thinking text) and re-injected into
 *    the outgoing block; blocks whose signature could not be recovered are
 *    dropped — the API rejects an unsigned block outright, and thinking blocks
 *    only have to be echoed for the turn they belong to — unless that would
 *    leave the message empty (mirror of removeUnsignedThinkingBlocks()).
 *    Captured signatures are additionally persisted in short-lived transients
 *    because the agent run can span multiple PHP requests: a write ability
 *    pauses for user confirmation (see ConfirmationStore) and resumes in a NEW
 *    request, where an in-memory map alone would be empty.
 *
 * 2. Paused / sliced server-tool turns (fix/pause-turn-stop-reason,
 *    anthropic #29): when a server-side tool (web search) makes a turn run
 *    long, the API returns `stop_reason: "pause_turn"` and expects the same
 *    conversation to be re-sent with the paused assistant turn appended so the
 *    model can continue. The connector treats the paused turn as finished, so
 *    the caller receives only the first fragment of the answer. The turn is
 *    continued here: the paused response is replayed against the API (same
 *    request, assistant leg appended) up to a bounded number of legs, and
 *    content plus token usage are accumulated into one complete response.
 *    Additionally — pause or no pause — a turn that uses a server-side tool
 *    carries its answer as several text blocks interleaved with
 *    `server_tool_use` / `web_search_tool_result` blocks, while
 *    GenerativeAiResult::toText() reads only the FIRST content text part; the
 *    text slices are therefore merged unconditionally into a single text block
 *    (mirror of mergeTextBlocks()).
 *
 * Rather than patching the third-party connector (forbidden by project rules and
 * lost on update), all repairs hook the WordPress HTTP API — the AI Client routes
 * every Anthropic call through wp_safe_remote_request() — and are strictly scoped
 * to the Anthropic messages endpoint, so they are a no-op for every other
 * provider. Bodies are decoded WITHOUT associative mode so genuine empty objects
 * keep their `{}` form on re-encode.
 *
 * Plug-and-play: flip the ENABLED constant to false (or drop this class from
 * ToolCallRepairManager) to run against the connector's native behaviour, e.g.
 * once the upstream PRs are merged and the fixed connector is installed. All
 * repairs are also idempotent against an already-fixed connector: a thinking
 * block that already carries its signature is left alone, and a turn the
 * connector already continued arrives here as a single finished response.
 *
 * @package AgentMod
 * @subpackage Services\AI\Http
 * @since 1.1.0
 *
 * @see https://github.com/WordPress/ai-provider-for-anthropic/issues/29
 * @see https://github.com/WordPress/ai-provider-for-anthropic/issues/30
 * @see https://docs.anthropic.com/en/docs/build-with-claude/extended-thinking
 */

namespace AgentMod\Services\AI\Http;

use stdClass;

defined('ABSPATH') || exit;

class AnthropicConnectorRepairer implements ProviderToolCallRepairerInterface
{
    /**
     * Master switch for every Anthropic repair in this class.
     *
     * Set to false to disable the whole class (register() becomes a no-op) once
     * the upstream connector ships the fixes this class mirrors.
     *
     * @var bool
     * @since 1.1.0
     */
    private const ENABLED = true;

    /**
     * Maximum number of pause_turn continuations for a single turn.
     *
     * Mirrors the upstream bound; prevents an endless loop should the API keep
     * pausing. When the limit is exhausted the last (still paused) leg is
     * returned with everything accumulated so far.
     *
     * @var int
     * @since 1.1.0
     */
    private const MAX_CONTINUATIONS = 5;

    /**
     * Transient key prefix for persisted thinking signatures.
     *
     * The current user id is appended so signatures never leak between the
     * conversations of different users.
     *
     * @var string
     * @since 1.1.0
     */
    private const SIGNATURE_TRANSIENT_PREFIX = 'agent_mod_anthropic_tsig_';

    /**
     * Transient time-to-live for persisted thinking signatures, in seconds.
     *
     * Must comfortably outlive the confirmation-store TTL (15 minutes), which is
     * the longest gap between the request that captured a signature and the
     * resumed request that needs it back.
     *
     * @var int
     * @since 1.1.0
     */
    private const SIGNATURE_TRANSIENT_TTL = 6 * 3600;

    /**
     * Captured thinking signatures, keyed by a hash of the thinking block's text.
     *
     * In-memory cache in front of the signature transients; covers the common
     * case of loop iterations inside a single PHP request.
     *
     * @var array<string, string>
     * @since 1.1.0
     */
    private array $signatures = [];

    /**
     * Whether the HTTP filters are currently registered.
     *
     * @var bool
     * @since 1.1.0
     */
    private bool $active = false;

    /**
     * Re-entrancy guard for the continuation loop.
     *
     * The continuation legs are sent through the WordPress HTTP API and would
     * re-enter repairResponse(); while this flag is set the filter passes inner
     * responses through untouched and the outer loop processes them itself.
     *
     * @var bool
     * @since 1.1.0
     */
    private bool $continuing = false;

    /**
     * {@inheritDoc}
     *
     * @since 1.1.0
     */
    public function register(): void
    {
        if (! self::ENABLED || $this->active) {
            return;
        }

        $this->signatures = [];
        $this->active     = true;

        add_filter('http_request_args', [$this, 'repairRequest'], 10, 2);
        add_filter('http_response', [$this, 'repairResponse'], 10, 3);
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.1.0
     */
    public function unregister(): void
    {
        if (! $this->active) {
            return;
        }

        remove_filter('http_request_args', [$this, 'repairRequest'], 10);
        remove_filter('http_response', [$this, 'repairResponse'], 10);

        $this->active     = false;
        $this->continuing = false;
        $this->signatures = [];
    }

    /**
     * Applies every request-side repair to an outgoing Anthropic call.
     *
     * Re-injects captured thinking signatures into outgoing thinking blocks and
     * drops the blocks that would still be unsigned (repair 1).
     *
     * @param mixed  $args The WordPress HTTP request arguments.
     * @param string $url  The request URL.
     *
     * @return mixed The (possibly modified) request arguments.
     * @since 1.1.0
     */
    public function repairRequest($args, $url)
    {
        if (! is_array($args) || ! $this->isAnthropicMessagesUrl($url)) {
            return $args;
        }

        if (empty($args['body']) || ! is_string($args['body'])) {
            return $args;
        }

        // Decode preserving the object/array distinction so untouched empty
        // objects ({}) are not flattened to arrays ([]) on re-encode.
        $payload = json_decode($args['body']);
        if (! $payload instanceof stdClass || empty($payload->messages) || ! is_array($payload->messages)) {
            return $args;
        }

        $changed = false;

        foreach ($payload->messages as $message) {
            if (! $message instanceof stdClass || empty($message->content) || ! is_array($message->content)) {
                continue;
            }

            foreach ($message->content as $block) {
                if (
                    ! $block instanceof stdClass
                    || 'thinking' !== ($block->type ?? null)
                    || ! isset($block->thinking) || ! is_string($block->thinking)
                    || ! empty($block->signature)
                ) {
                    continue;
                }

                $hash = $this->hashThinking($block->thinking);
                if (null === $hash) {
                    continue;
                }

                $signature = $this->lookupSignature($hash);
                if (null !== $signature) {
                    $block->signature = $signature;
                    $changed          = true;
                }
            }

            if ($this->removeUnsignedThinkingBlocks($message)) {
                $changed = true;
            }
        }

        if ($changed) {
            $args['body'] = wp_json_encode($payload);
        }

        return $args;
    }

    /**
     * Applies every response-side repair to a raw Anthropic response.
     *
     * Captures thinking signatures (repair 1), continues turns the API paused
     * with `pause_turn` and merges the sliced answer text into a single text
     * block (repair 2).
     *
     * @param mixed  $response The WordPress HTTP response array.
     * @param mixed  $args     The request arguments.
     * @param string $url      The request URL.
     *
     * @return mixed The (possibly modified) response.
     * @since 1.1.0
     */
    public function repairResponse($response, $args, $url)
    {
        if ($this->continuing || ! is_array($response) || ! $this->isAnthropicMessagesUrl($url)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        if ('' === $body) {
            return $response;
        }

        // Object-preserving decode, same reason as on the request side.
        $data = json_decode($body);
        if (! $data instanceof stdClass || empty($data->content) || ! is_array($data->content)) {
            return $response;
        }

        $this->captureThinkingSignatures($data->content);

        $accumulatedContent = $data->content;
        $accumulatedUsage   = $this->extractUsage($data);
        $continued          = $this->continuePausedTurn($data, $args, $url, $accumulatedContent, $accumulatedUsage);

        // Merge unconditionally: a server-tool turn slices its answer across
        // several text blocks even when it never paused, and toText() reads
        // only the first one. Single-text turns pass through unchanged.
        $mergedContent = $this->mergeTextBlocks($accumulatedContent);

        if (! $continued && $mergedContent === $accumulatedContent) {
            return $response;
        }

        $data->content = $mergedContent;
        $data->usage   = (object) $accumulatedUsage;

        $response['body'] = wp_json_encode($data);

        if (isset($response['http_response']) && is_object($response['http_response'])) {
            // Keep the Requests-level response object in sync for consumers
            // that read the body from it instead of the array key.
            $requestsResponse = $response['http_response'];
            if (method_exists($requestsResponse, 'get_response_object')) {
                $requestsResponse->get_response_object()->body = $response['body'];
            }
        }

        return $response;
    }

    /**
     * Continues a turn the API paused with `pause_turn` (repair 2).
     *
     * Mirrors the upstream continuation loop: the paused assistant leg is
     * appended to the request messages and the identical request is replayed
     * until the turn finishes or the bound is hit. Content and usage of every
     * leg are accumulated into the caller's references; `$data` is updated to
     * the last leg so the final body carries its raw stop_reason. A failed leg
     * ends the loop with everything gathered so far — at the HTTP layer a
     * partial answer is strictly better than replacing a 200 with an error.
     *
     * @param stdClass             $data               The decoded response data (updated to the last leg).
     * @param mixed                $args               The original request arguments.
     * @param string               $url                The request URL.
     * @param array<int, mixed>    $accumulatedContent Accumulated content blocks (by reference).
     * @param array<string, int>   $accumulatedUsage   Accumulated token usage (by reference).
     *
     * @return bool True when at least one continuation leg was executed.
     * @since 1.1.0
     */
    private function continuePausedTurn(
        stdClass $data,
        $args,
        string $url,
        array &$accumulatedContent,
        array &$accumulatedUsage
    ): bool {
        if ('pause_turn' !== ($data->stop_reason ?? null)) {
            return false;
        }

        if (! is_array($args) || empty($args['body']) || ! is_string($args['body'])) {
            return false;
        }

        $payload = json_decode($args['body']);
        if (! $payload instanceof stdClass || ! isset($payload->messages) || ! is_array($payload->messages)) {
            return false;
        }

        $continued = false;
        $leg       = $data;

        for ($i = 0; $i < self::MAX_CONTINUATIONS; $i++) {
            // Append the paused assistant leg so the model continues where it
            // left off, exactly as the API expects.
            $payload->messages[] = (object) [
                'role'    => is_string($leg->role ?? null) ? $leg->role : 'assistant',
                'content' => is_array($leg->content ?? null) ? $leg->content : [],
            ];

            $legArgs         = $args;
            $legArgs['body'] = wp_json_encode($payload);

            $this->continuing = true;
            $legResponse      = wp_remote_request($url, $legArgs);
            $this->continuing = false;

            if (is_wp_error($legResponse) || 200 !== (int) wp_remote_retrieve_response_code($legResponse)) {
                break;
            }

            $legData = json_decode(wp_remote_retrieve_body($legResponse));
            if (! $legData instanceof stdClass || empty($legData->content) || ! is_array($legData->content)) {
                break;
            }

            $this->captureThinkingSignatures($legData->content);

            foreach ($legData->content as $block) {
                $accumulatedContent[] = $block;
            }

            $legUsage = $this->extractUsage($legData);
            foreach ($legUsage as $key => $value) {
                $accumulatedUsage[$key] += $value;
            }

            // Adopt the leg's top-level fields (id, stop_reason, …) so the final
            // body reflects the last leg, mirroring the upstream loop.
            foreach (get_object_vars($legData) as $key => $value) {
                $data->{$key} = $value;
            }

            $continued = true;
            $leg       = $legData;

            if ('pause_turn' !== ($legData->stop_reason ?? null)) {
                break;
            }
        }

        return $continued;
    }

    /**
     * Merges the text slices of an assistant turn into a single text block.
     *
     * Mirror of the upstream mergeTextBlocks(): the slices are joined into the
     * first text block, non-text blocks keep their position, and a turn with a
     * single text block passes through unchanged (the identical array is
     * returned so the caller can detect that nothing changed).
     *
     * @param array<int, mixed> $content The accumulated content blocks.
     *
     * @return array<int, mixed> The content blocks with the text slices joined.
     * @since 1.1.0
     */
    private function mergeTextBlocks(array $content): array
    {
        $merged    = [];
        $textIndex = null;
        $changed   = false;

        foreach ($content as $block) {
            if (
                $block instanceof stdClass
                && 'text' === ($block->type ?? null)
                && isset($block->text) && is_string($block->text)
            ) {
                if (null !== $textIndex) {
                    $merged[$textIndex]->text .= $block->text;
                    $changed                   = true;
                    continue;
                }

                $textIndex = count($merged);
            }

            $merged[] = $block;
        }

        return $changed ? $merged : $content;
    }

    /**
     * Drops thinking blocks that would go out without a signature (repair 1).
     *
     * Mirror of the upstream removeUnsignedThinkingBlocks(): the API rejects any
     * thinking block sent without its signature, and thinking blocks only have
     * to be echoed back for the turn they belong to, so dropping an unsigned
     * block is preferable to failing the whole request. The content is left
     * untouched when filtering would empty it, so a message never ends up empty.
     *
     * @param stdClass $message A decoded request message (modified in place).
     *
     * @return bool True if any block was removed.
     * @since 1.1.0
     */
    private function removeUnsignedThinkingBlocks(stdClass $message): bool
    {
        $filtered = array_values(array_filter(
            $message->content,
            static function ($block): bool {
                return ! $block instanceof stdClass
                    || 'thinking' !== ($block->type ?? null)
                    || ! empty($block->signature);
            }
        ));

        if ([] === $filtered || count($filtered) === count($message->content)) {
            return false;
        }

        $message->content = $filtered;

        return true;
    }

    /**
     * Captures the signatures of the thinking blocks in a response content list (repair 1).
     *
     * @param array<int, mixed> $content Decoded response content blocks.
     *
     * @return void
     * @since 1.1.0
     */
    private function captureThinkingSignatures(array $content): void
    {
        foreach ($content as $block) {
            if (
                ! $block instanceof stdClass
                || 'thinking' !== ($block->type ?? null)
                || ! isset($block->thinking) || ! is_string($block->thinking)
                || empty($block->signature) || ! is_string($block->signature)
            ) {
                continue;
            }

            $hash = $this->hashThinking($block->thinking);
            if (null === $hash) {
                continue;
            }

            $this->signatures[$hash] = $block->signature;

            // Persist across PHP requests: a write ability pauses the run for
            // user confirmation and resumes in a new request, which must still
            // be able to re-inject this signature into the re-serialised block.
            set_transient(
                $this->signatureTransientKey($hash),
                $block->signature,
                self::SIGNATURE_TRANSIENT_TTL
            );
        }
    }

    /**
     * Resolves a captured signature by hash, falling back to the transient store.
     *
     * The in-memory map only survives a single PHP request; signatures captured
     * before a confirmation pause live in the transient written by
     * captureThinkingSignatures() and are re-cached on first use.
     *
     * @param string $hash The thinking-text hash.
     *
     * @return string|null The signature, or null when none was captured.
     * @since 1.1.0
     */
    private function lookupSignature(string $hash): ?string
    {
        if (isset($this->signatures[$hash])) {
            return $this->signatures[$hash];
        }

        $stored = get_transient($this->signatureTransientKey($hash));
        if (is_string($stored) && '' !== $stored) {
            $this->signatures[$hash] = $stored;

            return $stored;
        }

        return null;
    }

    /**
     * Builds the transient key for a captured signature hash.
     *
     * @param string $hash The thinking-text hash.
     *
     * @return string
     * @since 1.1.0
     */
    private function signatureTransientKey(string $hash): string
    {
        return self::SIGNATURE_TRANSIENT_PREFIX . get_current_user_id() . '_' . $hash;
    }

    /**
     * Extracts the token usage of a response leg as a plain sum-ready array.
     *
     * @param stdClass $data Decoded response data.
     *
     * @return array<string, int>
     * @since 1.1.0
     */
    private function extractUsage(stdClass $data): array
    {
        $usage = ($data->usage ?? null) instanceof stdClass ? $data->usage : new stdClass();

        return [
            'input_tokens'                => (int) ($usage->input_tokens ?? 0),
            'output_tokens'               => (int) ($usage->output_tokens ?? 0),
            'cache_creation_input_tokens' => (int) ($usage->cache_creation_input_tokens ?? 0),
            'cache_read_input_tokens'     => (int) ($usage->cache_read_input_tokens ?? 0),
        ];
    }

    /**
     * Builds a stable hash for a thinking block from its text.
     *
     * The signature is bound to the exact reasoning content, and the connector
     * re-emits that text verbatim, so hashing it matches a captured signature to
     * the block that carries it on the next turn.
     *
     * @param string $thinking The thinking block text.
     *
     * @return string|null The hash, or null when the text is empty.
     * @since 1.1.0
     */
    private function hashThinking(string $thinking): ?string
    {
        if ('' === $thinking) {
            return null;
        }

        return md5($thinking);
    }

    /**
     * Determines whether a URL targets the Anthropic messages endpoint.
     *
     * @param mixed $url The URL to check.
     *
     * @return bool
     * @since 1.1.0
     */
    private function isAnthropicMessagesUrl($url): bool
    {
        return is_string($url)
            && false !== strpos($url, 'api.anthropic.com')
            && false !== strpos($url, '/v1/messages');
    }
}
