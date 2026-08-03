<?php

/**
 * AI provider HTTP timeout manager.
 *
 * WordPress ships a 30 second cURL timeout for outgoing HTTP requests, which
 * long AI generations (large prompts, vision inputs, big completions) regularly
 * exceed — surfacing as "cURL error 28: Operation timed out". This manager
 * raises the timeout, but only for requests to known AI provider hosts and only
 * while a generation is in flight (register()/unregister() around the loop), so
 * unrelated HTTP calls made by abilities in the same window keep their normal
 * timeout. Lives entirely in AgentMod: no third-party connector code is
 * modified.
 *
 * @package AgentMod
 * @subpackage Services\AI\Http
 * @since 1.1.5
 */

namespace AgentMod\Services\AI\Http;

defined('ABSPATH') || exit;

use AgentMod\Common\Constants;

class HttpTimeoutManager
{
    /**
     * Hook priority; late enough to win over generic tweaks.
     *
     * @var int
     * @since 1.1.5
     */
    private const PRIORITY = 20;

    /**
     * Whether the filter is currently registered.
     *
     * @var bool
     * @since 1.1.5
     */
    private bool $registered = false;

    /**
     * Registers the timeout filter.
     *
     * @return void
     * @since 1.1.5
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        add_filter('http_request_args', [$this, 'filterRequestArgs'], self::PRIORITY, 2);
        $this->registered = true;
    }

    /**
     * Unregisters the timeout filter.
     *
     * @return void
     * @since 1.1.5
     */
    public function unregister(): void
    {
        if (! $this->registered) {
            return;
        }

        remove_filter('http_request_args', [$this, 'filterRequestArgs'], self::PRIORITY);
        $this->registered = false;
    }

    /**
     * Raises the timeout for requests to AI provider hosts.
     *
     * Never lowers an already higher timeout.
     *
     * @param array<string, mixed> $args Request arguments.
     * @param string               $url  Request URL.
     *
     * @return array<string, mixed>
     * @since 1.1.5
     */
    public function filterRequestArgs(array $args, string $url): array
    {
        if (! $this->isProviderUrl($url)) {
            return $args;
        }

        /**
         * Filters the HTTP timeout (seconds) applied to AI provider requests.
         *
         * @param int $timeout Timeout in seconds.
         *
         * @since 1.1.5
         */
        $timeout = (float) apply_filters('agent_mod_ai_http_timeout', Constants::AI_HTTP_TIMEOUT);

        $current = isset($args['timeout']) ? (float) $args['timeout'] : 0.0;

        $args['timeout'] = max($current, $timeout);

        return $args;
    }

    /**
     * Whether the URL targets a known AI provider host.
     *
     * @param string $url Request URL.
     *
     * @return bool
     * @since 1.1.5
     */
    private function isProviderUrl(string $url): bool
    {
        $host = wp_parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || '' === $host) {
            return false;
        }

        /**
         * Filters the AI provider hosts whose requests get the raised timeout.
         *
         * @param string[] $hosts Provider hostnames.
         *
         * @since 1.1.5
         */
        $hosts = (array) apply_filters('agent_mod_ai_http_hosts', [
            'api.openai.com',
            'api.anthropic.com',
            'generativelanguage.googleapis.com',
        ]);

        return in_array(strtolower($host), array_map('strtolower', $hosts), true);
    }
}
