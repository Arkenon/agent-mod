<?php

/**
 * Gemini tool-schema repairer.
 *
 * Works around two strictness differences in the Gemini generateContent endpoint,
 * both stemming from Gemini using a proto-based OpenAPI subset for function
 * declarations that other providers (OpenAI, Anthropic) do not enforce:
 *
 * 1. A function declaration's `parameters.properties` must be a JSON object (map),
 *    even when the ability takes no input. WordPress abilities that declare an
 *    empty input schema (`'properties' => array()`) are serialised by PHP/
 *    `json_encode` as an empty JSON array (`[]`) rather than an object (`{}`), so
 *    Gemini rejects the whole request with: "Cannot bind a list to map for field
 *    'properties'".
 *
 * 2. A property's `type` must be a single scalar string. Valid JSON Schema allows
 *    a union of types (`'type' => ['string', 'integer', 'null']`), which abilities
 *    like update-setting and update-option use for their free-form `value` input,
 *    but Gemini's `type` is a non-repeating proto field and rejects the whole
 *    request with: "Proto field is not repeating, cannot start list". Because one
 *    malformed declaration fails the entire request, this breaks every tool in the
 *    call, not just the ones with a union type. The union is collapsed to its first
 *    non-null member (with `nullable: true` when `null` was present); server-side
 *    validation in the manager re-coerces the value against its real schema.
 *
 * Rather than patching the third-party abilities or the AI Client connector (both
 * forbidden by the project rules and lost on update), this repairer hooks the
 * WordPress HTTP API — the AI Client routes every Gemini call through
 * wp_safe_remote_request() — and rewrites the outgoing tool schemas. It is
 * strictly scoped to the Gemini generateContent endpoint, so it is a no-op for
 * every other provider.
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

                if ('type' === $key && is_array($value)) {
                    $node->type = $this->collapseTypeUnion($value, $node);
                    $changed    = true;
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
     * Collapses a JSON Schema `type` union into a single scalar type for Gemini.
     *
     * Gemini's `type` is a non-repeating proto field, so a union array must be
     * reduced to one string. The first non-"null" member is chosen (the most
     * general the ability declared), and `nullable` is set on the node when the
     * union allowed null. When the union somehow contains only "null", "string" is
     * used as a permissive fallback. The value the model then supplies is still
     * validated and coerced against the setting/option's real schema server-side,
     * so narrowing the wire type here does not lose correctness.
     *
     * @param array<int, mixed> $types The declared type union.
     * @param stdClass          $node  The schema node (mutated to set `nullable`).
     *
     * @return string The single type to send to Gemini.
     * @since 1.1.0
     */
    private function collapseTypeUnion(array $types, stdClass $node): string
    {
        $hasNull = false;
        $primary = null;

        foreach ($types as $type) {
            if (! is_string($type)) {
                continue;
            }

            if ('null' === $type) {
                $hasNull = true;
                continue;
            }

            if (null === $primary) {
                $primary = $type;
            }
        }

        if ($hasNull) {
            $node->nullable = true;
        }

        return $primary ?? 'string';
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
