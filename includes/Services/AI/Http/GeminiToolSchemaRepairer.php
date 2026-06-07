<?php

/**
 * Gemini tool-schema repairer.
 *
 * Works around a strictness difference in the Gemini generateContent endpoint:
 * a function declaration's `parameters.properties` must be a JSON object (map),
 * even when the ability takes no input. WordPress abilities that declare an empty
 * input schema (`'properties' => array()`) are serialised by PHP/`json_encode` as
 * an empty JSON array (`[]`) rather than an object (`{}`), so Gemini rejects the
 * whole request with: "Cannot bind a list to map for field 'properties'". Other
 * providers (OpenAI, Anthropic) accept the array form, so this is Gemini-specific.
 *
 * Rather than patching the third-party abilities or the AI Client connector (both
 * forbidden by the project rules and lost on update), this repairer hooks the
 * WordPress HTTP API — the AI Client routes every Gemini call through
 * wp_safe_remote_request() — and rewrites any empty `properties` array in the
 * outgoing tool schemas to an empty object. It is strictly scoped to the Gemini
 * generateContent endpoint, so it is a no-op for every other provider.
 *
 * The body is decoded WITHOUT associative mode so genuine empty objects elsewhere
 * in the payload keep their `{}` form on re-encode; only the broken empty
 * `properties` arrays (decoded as PHP arrays) are converted to objects.
 *
 * @package AgentMod
 * @subpackage Services\AI\Http
 * @since 1.0.0
 *
 * @see https://ai.google.dev/api/caching#Schema
 */

namespace AgentMod\Services\AI\Http;

use stdClass;

defined('ABSPATH') || exit;

class GeminiToolSchemaRepairer implements ProviderToolCallRepairerInterface
{
    /**
     * Whether the HTTP filter is currently registered.
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

        $this->active = true;

        add_filter('http_request_args', [$this, 'repairRequest'], 10, 2);
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

        remove_filter('http_request_args', [$this, 'repairRequest'], 10);

        $this->active = false;
    }

    /**
     * Rewrites empty `properties` arrays in outgoing tool schemas to objects.
     *
     * @param mixed  $args The WordPress HTTP request arguments.
     * @param string $url  The request URL.
     *
     * @return mixed The (possibly modified) request arguments.
     * @since 1.0.0
     */
    public function repairRequest($args, $url)
    {
        if (! is_array($args) || ! $this->isGeminiGenerateUrl($url)) {
            return $args;
        }

        if (empty($args['body']) || ! is_string($args['body'])) {
            return $args;
        }

        // Decode preserving the object/array distinction so untouched empty
        // objects ({}) are not flattened to arrays ([]) on re-encode.
        $payload = json_decode($args['body']);
        if (! $payload instanceof stdClass || empty($payload->tools) || ! is_array($payload->tools)) {
            return $args;
        }

        $changed = false;

        foreach ($payload->tools as $tool) {
            if (! $tool instanceof stdClass) {
                continue;
            }

            // REST JSON uses camelCase; accept the snake_case form defensively.
            foreach (['functionDeclarations', 'function_declarations'] as $declKey) {
                if (empty($tool->{$declKey}) || ! is_array($tool->{$declKey})) {
                    continue;
                }

                foreach ($tool->{$declKey} as $declaration) {
                    if (! $declaration instanceof stdClass || ! isset($declaration->parameters)) {
                        continue;
                    }

                    if ($this->normalizeSchema($declaration->parameters)) {
                        $changed = true;
                    }
                }
            }
        }

        if ($changed) {
            $args['body'] = wp_json_encode($payload);
        }

        return $args;
    }

    /**
     * Recursively converts empty `properties` arrays in a JSON Schema node to objects.
     *
     * Schema nodes are stdClass (objects survive json_decode); their nested objects
     * are mutated in place via PHP's object handles. Arrays (e.g. `anyOf`) are walked
     * so nested object schemas are reached too.
     *
     * @param mixed $node The schema node to normalise (modified in place).
     *
     * @return bool True if any change was made.
     * @since 1.0.0
     */
    private function normalizeSchema($node): bool
    {
        $changed = false;

        if ($node instanceof stdClass) {
            foreach ($node as $key => $value) {
                if ('properties' === $key && is_array($value) && 0 === count($value)) {
                    $node->properties = new stdClass();
                    $changed          = true;
                    continue;
                }

                if ($this->normalizeSchema($value)) {
                    $changed = true;
                }
            }
        } elseif (is_array($node)) {
            foreach ($node as $value) {
                if ($this->normalizeSchema($value)) {
                    $changed = true;
                }
            }
        }

        return $changed;
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
}
