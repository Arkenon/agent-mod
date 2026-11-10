<?php

/**
 * OpenAI-compatible streaming response repairer.
 *
 * Some OpenAI-compatible gateways (Cloudflare AI Gateway, LiteLLM, Groq-style
 * proxies, self-hosted routers) answer a `/chat/completions` call with a
 * Server-Sent Events body — `data: {...}` chunks ending in `data: [DONE]` —
 * even though the request never asked for streaming, or append a trailing
 * `data: [DONE]` line to an otherwise complete JSON body. The connector reads
 * the body with a plain json_decode() and fails with a JSON parse error, so the
 * whole agent run dies on what is a perfectly good answer.
 *
 * This repairer works at the WordPress HTTP layer, like the Google and
 * Anthropic repairers, and is equally scoped:
 *
 *  - request side: a `/chat/completions` JSON body that does not mention
 *    `stream` at all gets `"stream": false`, so a gateway that streams by
 *    default is told not to. An explicit value is never touched.
 *  - response side: only a `/chat/completions` body that is NOT valid JSON and
 *    contains SSE `data:` lines is rewritten — either cut at the trailing
 *    `[DONE]` (a complete JSON object followed by it) or reassembled from its
 *    delta chunks into the standard non-streaming `chat.completion` shape
 *    (content, tool calls, finish reason, usage). A normal JSON body is never
 *    modified, so every well-behaved provider sees a no-op.
 *
 * It keys on the response shape, not on a host name: the gateways that do this
 * live on arbitrary custom domains.
 *
 * @package AgentMod
 * @subpackage Services\AI\Http
 * @since 1.3.8
 */

namespace AgentMod\Services\AI\Http;

use stdClass;

defined('ABSPATH') || exit;

class OpenAiCompatibleStreamRepairer implements ProviderToolCallRepairerInterface
{
    /**
     * Switch for this repairer alone (see ToolCallRepairManager for the master
     * switch).
     *
     * @var bool
     * @since 1.3.8
     */
    private const ENABLED = true;

    /**
     * Whether the filters are currently registered.
     *
     * @var bool
     * @since 1.3.8
     */
    private bool $active = false;

    /**
     * {@inheritDoc}
     *
     * @since 1.3.8
     */
    public function register(): void
    {
        if (! self::ENABLED || $this->active) {
            return;
        }

        $this->active = true;

        add_filter('http_request_args', [$this, 'repairRequest'], 10, 2);
        add_filter('http_response', [$this, 'repairResponse'], 10, 3);
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.3.8
     */
    public function unregister(): void
    {
        if (! $this->active) {
            return;
        }

        remove_filter('http_request_args', [$this, 'repairRequest'], 10);
        remove_filter('http_response', [$this, 'repairResponse'], 10);

        $this->active = false;
    }

    /**
     * Asks for a non-streaming answer when the request does not say.
     *
     * @param mixed  $args The WordPress HTTP request arguments.
     * @param string $url  The request URL.
     *
     * @return mixed The (possibly modified) request arguments.
     * @since 1.3.8
     */
    public function repairRequest($args, $url)
    {
        if (! is_array($args) || ! $this->isChatCompletionsUrl($url)) {
            return $args;
        }

        if (empty($args['body']) || ! is_string($args['body'])) {
            return $args;
        }

        // Object-preserving decode so empty objects ({}) in tool schemas are
        // not flattened to arrays ([]) on re-encode.
        $payload = json_decode($args['body']);

        if (! $payload instanceof stdClass || property_exists($payload, 'stream')) {
            return $args;
        }

        $payload->stream = false;

        $encoded = wp_json_encode($payload);

        if (false !== $encoded) {
            $args['body'] = $encoded;
        }

        return $args;
    }

    /**
     * Turns an SSE body back into the JSON the connector expects.
     *
     * @param mixed  $response The HTTP response array or WP_Error.
     * @param array  $args     The request arguments.
     * @param string $url      The request URL.
     *
     * @return mixed The (possibly modified) response.
     * @since 1.3.8
     */
    public function repairResponse($response, $args, $url)
    {
        if (! is_array($response) || ! $this->isChatCompletionsUrl($url)) {
            return $response;
        }

        $body = (string) wp_remote_retrieve_body($response);

        if ('' === trim($body) || false === strpos($body, 'data:')) {
            return $response;
        }

        // A body that already decodes is none of our business, even if some
        // string inside it happens to contain "data:".
        json_decode($body);

        if (JSON_ERROR_NONE === json_last_error()) {
            return $response;
        }

        $repaired = $this->repairBody($body);

        if (null === $repaired) {
            return $response;
        }

        $this->log('rewrote a streamed /chat/completions body into a single JSON response');

        $response['body'] = $repaired;

        if (isset($response['headers']) && ($response['headers'] instanceof \ArrayAccess || is_array($response['headers']))) {
            $response['headers']['content-type'] = 'application/json';
        }

        if (isset($response['http_response']) && is_object($response['http_response'])) {
            // Keep the Requests-level response object in sync for consumers
            // that read the body from it instead of the array key.
            $requestsResponse = $response['http_response'];

            if (method_exists($requestsResponse, 'get_response_object')) {
                $raw       = $requestsResponse->get_response_object();
                $raw->body = $repaired;

                if (isset($raw->headers) && $raw->headers instanceof \ArrayAccess) {
                    $raw->headers['content-type'] = 'application/json';
                }
            }
        }

        return $response;
    }

    /**
     * Repairs a body, or returns null when it cannot be understood.
     *
     * Public for testability; holds no state.
     *
     * @param string $body Raw response body.
     *
     * @return string|null JSON body.
     * @since 1.3.8
     */
    public function repairBody(string $body): ?string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        // Case A: a complete JSON object with SSE noise after it.
        $trimmed = ltrim($body);

        if ('{' === substr($trimmed, 0, 1)) {
            $cut  = preg_split('/\n\s*data:/', $trimmed, 2);
            $head = trim(is_array($cut) ? (string) $cut[0] : $trimmed);
            $data = json_decode($head);

            if ($data instanceof stdClass) {
                $encoded = wp_json_encode($data);

                return false === $encoded ? null : $encoded;
            }
        }

        // Case B: a real event stream.
        $chunks = [];

        foreach (explode("\n", $body) as $line) {
            $line = trim($line);

            if (0 !== strpos($line, 'data:')) {
                continue;
            }

            $payload = trim(substr($line, 5));

            if ('' === $payload || '[DONE]' === $payload) {
                continue;
            }

            $chunk = json_decode($payload, true);

            if (is_array($chunk)) {
                $chunks[] = $chunk;
            }
        }

        if (empty($chunks)) {
            return null;
        }

        $assembled = $this->assemble($chunks);

        if (null === $assembled) {
            return null;
        }

        $encoded = wp_json_encode($assembled);

        return false === $encoded ? null : $encoded;
    }

    /**
     * Folds streamed chunks into one `chat.completion` object.
     *
     * @param array<int, array<string, mixed>> $chunks Decoded `data:` payloads.
     *
     * @return array<string, mixed>|null
     * @since 1.3.8
     */
    private function assemble(array $chunks): ?array
    {
        $result = [
            'id'      => '',
            'object'  => 'chat.completion',
            'created' => time(),
            'model'   => '',
            'choices' => [],
        ];

        $choices = [];
        $usage   = null;

        foreach ($chunks as $chunk) {
            // An error event: hand the connector an error body it understands
            // instead of an empty answer.
            if (isset($chunk['error']) && empty($chunk['choices'])) {
                return ['error' => $chunk['error']];
            }

            foreach (['id', 'model'] as $key) {
                if ('' === $result[$key] && ! empty($chunk[$key]) && is_string($chunk[$key])) {
                    $result[$key] = $chunk[$key];
                }
            }

            if (! empty($chunk['created']) && is_int($chunk['created'])) {
                $result['created'] = $chunk['created'];
            }

            if (! empty($chunk['usage']) && is_array($chunk['usage'])) {
                $usage = $chunk['usage'];
            }

            foreach ((array) ($chunk['choices'] ?? []) as $choice) {
                if (! is_array($choice)) {
                    continue;
                }

                $index = (int) ($choice['index'] ?? 0);

                $choices[$index] = $choices[$index] ?? [
                    'content'       => '',
                    'reasoning'     => '',
                    'tool_calls'    => [],
                    'finish_reason' => null,
                    'role'          => 'assistant',
                ];

                // A chunk that already carries a full message (a gateway that
                // wrapped a non-streamed answer in one event).
                $part = $choice['delta'] ?? $choice['message'] ?? [];

                if (is_array($part)) {
                    $this->mergePart($choices[$index], $part);
                }

                if (! empty($choice['finish_reason'])) {
                    $choices[$index]['finish_reason'] = (string) $choice['finish_reason'];
                }
            }
        }

        if (empty($choices)) {
            return null;
        }

        ksort($choices);

        foreach ($choices as $index => $choice) {
            $message = [
                'role'    => $choice['role'],
                'content' => '' !== $choice['content'] ? $choice['content'] : null,
            ];

            if ('' !== $choice['reasoning']) {
                $message['reasoning_content'] = $choice['reasoning'];
            }

            if (! empty($choice['tool_calls'])) {
                ksort($choice['tool_calls']);
                $message['tool_calls'] = array_values($choice['tool_calls']);
            }

            $result['choices'][] = [
                'index'         => $index,
                'message'       => $message,
                'finish_reason' => $choice['finish_reason']
                    ?? (! empty($choice['tool_calls']) ? 'tool_calls' : 'stop'),
            ];
        }

        if (null !== $usage) {
            $result['usage'] = $usage;
        }

        return $result;
    }

    /**
     * Merges one delta (or message) into the choice being assembled.
     *
     * @param array<string, mixed> $choice Choice accumulator (by reference).
     * @param array<string, mixed> $part   The delta or message.
     *
     * @return void
     * @since 1.3.8
     */
    private function mergePart(array &$choice, array $part): void
    {
        if (! empty($part['role']) && is_string($part['role'])) {
            $choice['role'] = $part['role'];
        }

        if (isset($part['content']) && is_string($part['content'])) {
            $choice['content'] .= $part['content'];
        }

        foreach (['reasoning_content', 'reasoning'] as $key) {
            if (isset($part[$key]) && is_string($part[$key])) {
                $choice['reasoning'] .= $part[$key];
            }
        }

        foreach ((array) ($part['tool_calls'] ?? []) as $position => $call) {
            if (! is_array($call)) {
                continue;
            }

            $index = isset($call['index']) ? (int) $call['index'] : (int) $position;

            $choice['tool_calls'][$index] = $choice['tool_calls'][$index] ?? [
                'id'       => '',
                'type'     => 'function',
                'function' => ['name' => '', 'arguments' => ''],
            ];

            $target = &$choice['tool_calls'][$index];

            if (! empty($call['id']) && is_string($call['id'])) {
                $target['id'] = $call['id'];
            }

            if (! empty($call['type']) && is_string($call['type'])) {
                $target['type'] = $call['type'];
            }

            $function = is_array($call['function'] ?? null) ? $call['function'] : [];

            if (isset($function['name']) && is_string($function['name'])) {
                // Names arrive whole in practice; appending also covers the
                // rare gateway that splits them.
                $target['function']['name'] .= $function['name'];
            }

            if (isset($function['arguments'])) {
                $target['function']['arguments'] .= is_string($function['arguments'])
                    ? $function['arguments']
                    : (string) wp_json_encode($function['arguments']);
            }

            unset($target);
        }
    }

    /**
     * Whether a URL is an OpenAI-style chat completions endpoint.
     *
     * @param mixed $url The URL to check.
     *
     * @return bool
     * @since 1.3.8
     */
    private function isChatCompletionsUrl($url): bool
    {
        if (! is_string($url) || '' === $url) {
            return false;
        }

        $path = (string) wp_parse_url($url, PHP_URL_PATH);

        return '/chat/completions' === substr(rtrim($path, '/'), -strlen('/chat/completions'));
    }

    /**
     * Logs a repair while debugging.
     *
     * @param string $note What happened.
     *
     * @return void
     * @since 1.3.8
     */
    private function log(string $note): void
    {
        if (! defined('WP_DEBUG') || ! WP_DEBUG) {
            return;
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('AgentMod: ' . $note);
    }
}
