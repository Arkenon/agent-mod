<?php

/**
 * Anthropic thinking-signature repairer.
 *
 * Works around a limitation of the bundled Anthropic provider: when extended
 * thinking is active, Claude returns each `thinking` content block with an opaque
 * `signature` that must be echoed back unchanged on every subsequent turn,
 * otherwise the API rejects the request with
 * "messages.N.content.0.thinking.signature: Field required". The provider captures
 * only the thinking text when parsing a response (it discards the signature) and
 * re-emits the block without it when serialising the history, so any multi-step
 * tool-calling turn that began with a thinking block breaks on the next request.
 * This is why simple one-shot tool calls appear to work while abilities that make
 * the model reason first (e.g. searching a directory, deciding on a write) fail.
 *
 * This is the exact Anthropic analogue of the Gemini `thoughtSignature` problem
 * (see GeminiThoughtSignatureRepairer). Rather than patching the third-party
 * provider (forbidden by the project rules and lost on update), this repairer
 * hooks the WordPress HTTP API — the AI Client routes every Anthropic call through
 * wp_safe_remote_request() — to capture each signature from the raw response and
 * re-inject it into the matching thinking block on the next request. It is
 * strictly scoped to the Anthropic messages endpoint, so it is a no-op for every
 * other provider.
 *
 * Provider-specific by nature: the round-trip data lives in the wire format, which
 * differs per provider, so it cannot be handled in AgentMod's provider-agnostic
 * Message layer. The correct long-term fix is for the Anthropic provider connector
 * to carry the signature on the MessagePart DTO; once that ships, this repairer
 * becomes a harmless no-op and can be removed.
 *
 * @package AgentMod
 * @subpackage Services\AI\Http
 * @since 1.1.0
 *
 * @see https://docs.anthropic.com/en/docs/build-with-claude/extended-thinking
 */

namespace AgentMod\Services\AI\Http;

defined('ABSPATH') || exit;

class AnthropicThinkingSignatureRepairer implements ProviderToolCallRepairerInterface
{
    /**
     * Captured signatures, keyed by a hash of the thinking block's text.
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
     * {@inheritDoc}
     *
     * @since 1.1.0
     */
    public function register(): void
    {
        if ($this->active) {
            return;
        }

        $this->signatures = [];
        $this->active     = true;

        add_filter('http_request_args', [$this, 'injectIntoRequest'], 10, 2);
        add_filter('http_response', [$this, 'captureFromResponse'], 10, 3);
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

        remove_filter('http_request_args', [$this, 'injectIntoRequest'], 10);
        remove_filter('http_response', [$this, 'captureFromResponse'], 10);

        $this->active     = false;
        $this->signatures = [];
    }

    /**
     * Re-injects captured signatures into outgoing thinking blocks.
     *
     * @param mixed  $args The WordPress HTTP request arguments.
     * @param string $url  The request URL.
     *
     * @return mixed The (possibly modified) request arguments.
     * @since 1.1.0
     */
    public function injectIntoRequest($args, $url)
    {
        if (! is_array($args) || ! $this->isAnthropicMessagesUrl($url)) {
            return $args;
        }

        if (empty($args['body']) || ! is_string($args['body'])) {
            return $args;
        }

        $payload = json_decode($args['body'], true);
        if (! is_array($payload) || empty($payload['messages']) || ! is_array($payload['messages'])) {
            return $args;
        }

        $changed = false;

        foreach ($payload['messages'] as &$message) {
            if (! is_array($message) || empty($message['content']) || ! is_array($message['content'])) {
                continue;
            }

            foreach ($message['content'] as &$block) {
                if (
                    ! is_array($block)
                    || ! isset($block['type']) || 'thinking' !== $block['type']
                    || ! isset($block['thinking']) || ! is_string($block['thinking'])
                    || ! empty($block['signature'])
                ) {
                    continue;
                }

                $hash = $this->hashThinking($block['thinking']);
                if (null !== $hash && isset($this->signatures[$hash])) {
                    $block['signature'] = $this->signatures[$hash];
                    $changed            = true;
                }
            }
            unset($block);
        }
        unset($message);

        if ($changed) {
            $args['body'] = wp_json_encode($payload);
        }

        return $args;
    }

    /**
     * Captures thinking-block signatures from an Anthropic response.
     *
     * @param mixed  $response The WordPress HTTP response array.
     * @param mixed  $args     The request arguments (unused).
     * @param string $url      The request URL.
     *
     * @return mixed The unmodified response.
     * @since 1.1.0
     */
    public function captureFromResponse($response, $args, $url)
    {
        if (! $this->isAnthropicMessagesUrl($url)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        if ('' === $body) {
            return $response;
        }

        $data = json_decode($body, true);
        if (! is_array($data) || empty($data['content']) || ! is_array($data['content'])) {
            return $response;
        }

        foreach ($data['content'] as $block) {
            if (
                ! is_array($block)
                || ! isset($block['type']) || 'thinking' !== $block['type']
                || ! isset($block['thinking']) || ! is_string($block['thinking'])
                || empty($block['signature']) || ! is_string($block['signature'])
            ) {
                continue;
            }

            $hash = $this->hashThinking($block['thinking']);
            if (null !== $hash) {
                $this->signatures[$hash] = $block['signature'];
            }
        }

        return $response;
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

    /**
     * Builds a stable hash for a thinking block from its text.
     *
     * The signature is bound to the exact reasoning content, and the provider
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
}
