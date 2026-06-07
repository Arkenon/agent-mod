<?php

/**
 * Gemini thought-signature repairer.
 *
 * Works around a limitation of the bundled Google AI provider: Gemini "thinking"
 * models attach a `thoughtSignature` to function-call parts that must be echoed
 * back unchanged on subsequent turns, otherwise the API rejects the request with
 * "Function call is missing a thought_signature in functionCall parts". The
 * provider neither captures the signature when parsing a response nor re-emits it
 * when serialising the history, so the agentic tool-calling loop breaks.
 *
 * Rather than patching the third-party provider (which would be lost on update and
 * is forbidden by the project rules), this repairer hooks the WordPress HTTP API —
 * the AI Client routes every Gemini call through wp_safe_remote_request() — to
 * capture each signature from the raw response and re-inject it into the matching
 * function call on the next request. It is strictly scoped to the Gemini
 * generateContent endpoint, so it is a no-op for every other provider.
 *
 * Provider-specific by nature: the round-trip data lives in the wire format, which
 * differs per provider, so it cannot be handled in AgentMod's provider-agnostic
 * Message layer. The correct long-term fix is for the Google provider connector to
 * use the MessagePart DTO's existing thoughtSignature support; once that ships,
 * this repairer becomes a harmless no-op and can be removed.
 *
 * @package AgentMod
 * @subpackage Services\AI\Http
 * @since 1.0.0
 *
 * @see https://ai.google.dev/gemini-api/docs/thought-signatures
 */

namespace AgentMod\Services\AI\Http;

defined('ABSPATH') || exit;

class GeminiThoughtSignatureRepairer implements ProviderToolCallRepairerInterface
{
    /**
     * Captured signatures, keyed by a hash of the function call name + args.
     *
     * @var array<string, string>
     * @since 1.0.0
     */
    private array $signatures = [];

    /**
     * Whether the HTTP filters are currently registered.
     *
     * @var bool
     * @since 1.0.0
     */
    private bool $active = false;

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
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
     * @since 1.0.0
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
     * Re-injects captured thought signatures into outgoing function-call parts.
     *
     * @param mixed  $args The WordPress HTTP request arguments.
     * @param string $url  The request URL.
     *
     * @return mixed The (possibly modified) request arguments.
     * @since 1.0.0
     */
    public function injectIntoRequest($args, $url)
    {
        if (! is_array($args) || ! $this->isGeminiGenerateUrl($url)) {
            return $args;
        }

        if (empty($args['body']) || ! is_string($args['body'])) {
            return $args;
        }

        $payload = json_decode($args['body'], true);
        if (! is_array($payload) || empty($payload['contents']) || ! is_array($payload['contents'])) {
            return $args;
        }

        $changed = false;

        foreach ($payload['contents'] as &$content) {
            if (! is_array($content) || empty($content['parts']) || ! is_array($content['parts'])) {
                continue;
            }

            foreach ($content['parts'] as &$part) {
                if (
                    ! is_array($part)
                    || ! isset($part['functionCall'])
                    || isset($part['thoughtSignature'])
                ) {
                    continue;
                }

                $hash = $this->hashFunctionCall($part['functionCall']);
                if (null !== $hash && isset($this->signatures[$hash])) {
                    $part['thoughtSignature'] = $this->signatures[$hash];
                    $changed                  = true;
                }
            }
            unset($part);
        }
        unset($content);

        if ($changed) {
            $args['body'] = wp_json_encode($payload);
        }

        return $args;
    }

    /**
     * Captures thought signatures from a Gemini response.
     *
     * @param mixed  $response The WordPress HTTP response array.
     * @param mixed  $args     The request arguments (unused).
     * @param string $url      The request URL.
     *
     * @return mixed The unmodified response.
     * @since 1.0.0
     */
    public function captureFromResponse($response, $args, $url)
    {
        if (! $this->isGeminiGenerateUrl($url)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        if ('' === $body) {
            return $response;
        }

        $data = json_decode($body, true);
        if (! is_array($data) || empty($data['candidates']) || ! is_array($data['candidates'])) {
            return $response;
        }

        foreach ($data['candidates'] as $candidate) {
            if (
                ! is_array($candidate)
                || empty($candidate['content']['parts'])
                || ! is_array($candidate['content']['parts'])
            ) {
                continue;
            }

            foreach ($candidate['content']['parts'] as $part) {
                if (
                    ! is_array($part)
                    || ! isset($part['functionCall'])
                    || empty($part['thoughtSignature'])
                    || ! is_string($part['thoughtSignature'])
                ) {
                    continue;
                }

                $hash = $this->hashFunctionCall($part['functionCall']);
                if (null !== $hash) {
                    $this->signatures[$hash] = $part['thoughtSignature'];
                }
            }
        }

        return $response;
    }

    /**
     * Determines whether a URL targets the Gemini generateContent endpoint.
     *
     * @param mixed $url The URL to check.
     *
     * @return bool
     * @since 1.0.0
     */
    private function isGeminiGenerateUrl($url): bool
    {
        return is_string($url)
            && false !== strpos($url, 'generativelanguage.googleapis.com')
            && false !== strpos($url, ':generateContent');
    }

    /**
     * Builds a stable hash for a function call from its name and arguments.
     *
     * Empty args (null, missing, or `{}`) are normalised to a single form so that
     * a no-argument call captured from the response matches the same call when the
     * provider re-serialises it (the provider omits empty args).
     *
     * @param mixed $functionCall The function call array.
     *
     * @return string|null The hash, or null when the call has no usable name.
     * @since 1.0.0
     */
    private function hashFunctionCall($functionCall): ?string
    {
        if (! is_array($functionCall) || empty($functionCall['name']) || ! is_string($functionCall['name'])) {
            return null;
        }

        $callArgs = $functionCall['args'] ?? null;
        if (is_array($callArgs) && 0 === count($callArgs)) {
            $callArgs = null;
        }

        $argsKey = null === $callArgs ? 'null' : (string) wp_json_encode($callArgs);

        return md5($functionCall['name'] . '|' . $argsKey);
    }
}
