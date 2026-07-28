<?php

/**
 * Google (Gemini) connector repairer.
 *
 * Single, self-contained workaround layer for every known quirk of the bundled
 * "AI Provider for Google" connector. It is the HTTP-layer mirror of the three
 * upstream fix branches submitted as PRs; each numbered repair below maps 1:1 to
 * one of them and becomes a harmless no-op once the fixed connector ships:
 *
 * 1. Schema type-union / empty-properties (fix/schema-type-union, google #33):
 *    Gemini's function-declaration schema is a proto-based OpenAPI subset where
 *    `type` is a non-repeating scalar field and `properties` must be a JSON map.
 *    A JSON Schema `type` union (`['string', 'null']`) or an empty `properties`
 *    serialised as `[]` makes Gemini reject the ENTIRE request — including plain
 *    chat — with "Proto field is not repeating, cannot start list" / "Cannot bind
 *    a list to map for field 'properties'". Outgoing tool schemas are normalised:
 *    the union collapses to its first non-"null" member (plus `nullable: true`
 *    when "null" was allowed) and empty `properties` become objects.
 *
 * 2. Thought-signature round-trip (fix/thought-signature-round-trip):
 *    Thinking models attach a `thoughtSignature` to every `functionCall` part
 *    that must be echoed back unchanged on all following turns, otherwise the
 *    API rejects the request with "Function call is missing a thought_signature
 *    in functionCall parts". The connector drops it in both directions, so it is
 *    captured here from the raw response and re-injected into the matching
 *    outgoing call (matched by a hash of name + args). Captured signatures are
 *    additionally persisted in short-lived transients because the agent run can
 *    span multiple PHP requests: a write ability pauses for user confirmation
 *    (see ConfirmationStore) and resumes in a NEW request, where an in-memory
 *    map alone would be empty and the resumed call would 400. The fixed
 *    connector survives this naturally because it stores the signature inside
 *    the MessagePart DTO, which travels with the persisted history.
 *
 * 3. Web search + function calling (fix/google-search-part-parsing, google #32):
 *    (a) Combining the built-in `googleSearch` tool with function declarations
 *    requires `toolConfig.includeServerSideToolInvocations = true`, which the
 *    connector does not set; it is injected here when both tools are present and
 *    no `toolConfig` was provided (a caller-supplied one takes precedence, same
 *    as upstream). (b) With that flag on, Gemini echoes the server-side search
 *    invocation back as `toolCall` / `toolResponse` response parts (and may emit
 *    signature-only parts), which the connector's parser rejects with "Part has
 *    an unexpected type", failing the whole response. Those non-actionable parts
 *    are stripped from the raw response before the connector parses it; the
 *    grounded answer arrives in the sibling text parts.
 *
 * Rather than patching the third-party connector (forbidden by project rules and
 * lost on update), all repairs hook the WordPress HTTP API — the AI Client routes
 * every Gemini call through wp_safe_remote_request() — and are strictly scoped to
 * the Gemini generateContent endpoint, so they are a no-op for every other
 * provider. Bodies are decoded WITHOUT associative mode so genuine empty objects
 * keep their `{}` form on re-encode.
 *
 * Plug-and-play: flip the ENABLED constant to false (or drop this class from
 * ToolCallRepairManager) to run against the connector's native behaviour, e.g.
 * once the upstream PRs are merged and the fixed connector is installed. All
 * repairs are also idempotent against an already-fixed connector: a normalised
 * schema, a present thoughtSignature, and an existing toolConfig are left alone.
 *
 * @package AgentMod
 * @subpackage Services\AI\Http
 * @since 1.1.0
 *
 * @see https://github.com/WordPress/ai-provider-for-google/issues/32
 * @see https://github.com/WordPress/ai-provider-for-google/issues/33
 * @see https://ai.google.dev/gemini-api/docs/thought-signatures
 */

namespace AgentMod\Services\AI\Http;

use stdClass;

defined('ABSPATH') || exit;

class GoogleConnectorRepairer implements ProviderToolCallRepairerInterface
{
    /**
     * Master switch for every Google repair in this class.
     *
     * Set to false to disable the whole class (register() becomes a no-op) once
     * the upstream connector ships the fixes this class mirrors.
     *
     * @var bool
     * @since 1.1.0
     */
    private const ENABLED = true;

    /**
     * Transient key prefix for persisted thought signatures.
     *
     * The current user id is appended so signatures never leak between the
     * conversations of different users.
     *
     * @var string
     * @since 1.1.0
     */
    private const SIGNATURE_TRANSIENT_PREFIX = 'agent_mod_google_tsig_';

    /**
     * Transient time-to-live for persisted thought signatures, in seconds.
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
     * Captured thought signatures, keyed by a hash of the function call name + args.
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
        $this->signatures = [];
    }

    /**
     * Applies every request-side repair to an outgoing Gemini call.
     *
     * Normalises tool schemas (repair 1), re-injects captured thought signatures
     * into function-call parts (repair 2) and enables server-side tool
     * invocations when googleSearch is combined with function calling (repair 3a).
     *
     * @param mixed  $args The WordPress HTTP request arguments.
     * @param string $url  The request URL.
     *
     * @return mixed The (possibly modified) request arguments.
     * @since 1.1.0
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
        if (! $payload instanceof stdClass) {
            return $args;
        }

        $changed = $this->normalizeToolSchemas($payload);

        if ($this->injectServerSideToolInvocations($payload)) {
            $changed = true;
        }

        if ($this->injectThoughtSignatures($payload)) {
            $changed = true;
        }

        if ($changed) {
            $args['body'] = wp_json_encode($payload);
        }

        return $args;
    }

    /**
     * Applies every response-side repair to a raw Gemini response.
     *
     * Captures thought signatures from function-call parts (repair 2) and strips
     * the server-side `toolCall` / `toolResponse` and signature-only parts the
     * connector's parser cannot handle (repair 3b).
     *
     * @param mixed  $response The WordPress HTTP response array.
     * @param mixed  $args     The request arguments (unused).
     * @param string $url      The request URL.
     *
     * @return mixed The (possibly modified) response.
     * @since 1.1.0
     */
    public function repairResponse($response, $args, $url)
    {
        if (! is_array($response) || ! $this->isGeminiGenerateUrl($url)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        if ('' === $body) {
            return $response;
        }

        // Object-preserving decode, same reason as on the request side.
        $data = json_decode($body);
        if (! $data instanceof stdClass || empty($data->candidates) || ! is_array($data->candidates)) {
            return $response;
        }

        $changed = false;

        foreach ($data->candidates as $candidate) {
            if (
                ! $candidate instanceof stdClass
                || ! isset($candidate->content)
                || ! $candidate->content instanceof stdClass
                || empty($candidate->content->parts)
                || ! is_array($candidate->content->parts)
            ) {
                continue;
            }

            $keptParts = [];

            foreach ($candidate->content->parts as $part) {
                if (! $part instanceof stdClass) {
                    $keptParts[] = $part;
                    continue;
                }

                $this->captureThoughtSignature($part);

                /*
                 * Server-side built-in tool invocations (googleSearch) echoed back
                 * as `toolCall` / `toolResponse` parts, and parts consisting of
                 * nothing but a thought signature, carry no client-actionable
                 * content — dropping them mirrors the upstream parser fix that
                 * skips them instead of throwing "Part has an unexpected type".
                 */
                if (isset($part->toolCall) || isset($part->toolResponse)) {
                    $changed = true;
                    continue;
                }

                if (isset($part->thoughtSignature) && 1 === count((array) $part)) {
                    $changed = true;
                    continue;
                }

                $keptParts[] = $part;
            }

            if ($changed) {
                $candidate->content->parts = $keptParts;
            }
        }

        if ($changed) {
            $response['body'] = wp_json_encode($data);

            if (isset($response['http_response']) && is_object($response['http_response'])) {
                // Keep the Requests-level response object in sync for consumers
                // that read the body from it instead of the array key.
                $requestsResponse = $response['http_response'];
                if (method_exists($requestsResponse, 'get_response_object')) {
                    $requestsResponse->get_response_object()->body = $response['body'];
                }
            }
        }

        return $response;
    }

    /**
     * Normalises outgoing function-declaration schemas for Gemini (repair 1).
     *
     * @param stdClass $payload The decoded request payload (modified in place).
     *
     * @return bool True if any change was made.
     * @since 1.1.0
     */
    private function normalizeToolSchemas(stdClass $payload): bool
    {
        if (empty($payload->tools) || ! is_array($payload->tools)) {
            return false;
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

        return $changed;
    }

    /**
     * Recursively normalises a JSON Schema node for Gemini.
     *
     * Empty `properties` arrays become objects and `type` unions collapse to a
     * single scalar, exactly like the upstream sanitizer pass. Schema nodes are
     * stdClass (objects survive json_decode); their nested objects are mutated in
     * place via PHP's object handles. Arrays (e.g. `anyOf`, `items` lists) are
     * walked so nested object schemas are reached too.
     *
     * @param mixed $node The schema node to normalise (modified in place).
     *
     * @return bool True if any change was made.
     * @since 1.1.0
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
     * The first non-"null" member is chosen and `nullable` is set on the node
     * when the union allowed null; a union of only "null" falls back to
     * "string" — identical to the upstream fix. The value the model then
     * supplies is still validated and coerced against the ability's real schema
     * server-side, so narrowing the wire type here does not lose correctness.
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
     * Enables server-side tool invocations for googleSearch + function calling (repair 3a).
     *
     * Mirrors the upstream condition exactly: only when the request carries BOTH
     * the built-in googleSearch tool and function declarations, and no
     * `toolConfig` is already present (one supplied via custom options reaches
     * the wire before this filter and therefore takes precedence).
     *
     * @param stdClass $payload The decoded request payload (modified in place).
     *
     * @return bool True if the flag was injected.
     * @since 1.1.0
     */
    private function injectServerSideToolInvocations(stdClass $payload): bool
    {
        if (isset($payload->toolConfig) || empty($payload->tools) || ! is_array($payload->tools)) {
            return false;
        }

        $hasWebSearch            = false;
        $hasFunctionDeclarations = false;

        foreach ($payload->tools as $tool) {
            if (! $tool instanceof stdClass) {
                continue;
            }

            if (isset($tool->googleSearch)) {
                $hasWebSearch = true;
            }

            foreach (['functionDeclarations', 'function_declarations'] as $declKey) {
                if (! empty($tool->{$declKey})) {
                    $hasFunctionDeclarations = true;
                }
            }
        }

        if (! $hasWebSearch || ! $hasFunctionDeclarations) {
            return false;
        }

        $payload->toolConfig = (object) ['includeServerSideToolInvocations' => true];

        return true;
    }

    /**
     * Re-injects captured thought signatures into outgoing function-call parts (repair 2).
     *
     * Parts that already carry a signature are left alone, so this is a no-op
     * once the fixed connector round-trips the signature itself.
     *
     * @param stdClass $payload The decoded request payload (modified in place).
     *
     * @return bool True if any signature was injected.
     * @since 1.1.0
     */
    private function injectThoughtSignatures(stdClass $payload): bool
    {
        if (empty($payload->contents) || ! is_array($payload->contents)) {
            return false;
        }

        $changed = false;

        foreach ($payload->contents as $content) {
            if (! $content instanceof stdClass || empty($content->parts) || ! is_array($content->parts)) {
                continue;
            }

            foreach ($content->parts as $part) {
                if (
                    ! $part instanceof stdClass
                    || ! isset($part->functionCall)
                    || isset($part->thoughtSignature)
                ) {
                    continue;
                }

                $hash = $this->hashFunctionCall($part->functionCall);
                if (null === $hash) {
                    continue;
                }

                $signature = $this->lookupSignature($hash);
                if (null !== $signature) {
                    $part->thoughtSignature = $signature;
                    $changed                = true;
                }
            }
        }

        return $changed;
    }

    /**
     * Resolves a captured signature by hash, falling back to the transient store.
     *
     * The in-memory map only survives a single PHP request; signatures captured
     * before a confirmation pause live in the transient written by
     * captureThoughtSignature() and are re-cached on first use.
     *
     * @param string $hash The function call hash.
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
     * Captures the thought signature of a response function-call part (repair 2).
     *
     * @param stdClass $part A decoded response message part.
     *
     * @return void
     * @since 1.1.0
     */
    private function captureThoughtSignature(stdClass $part): void
    {
        if (
            ! isset($part->functionCall)
            || empty($part->thoughtSignature)
            || ! is_string($part->thoughtSignature)
        ) {
            return;
        }

        $hash = $this->hashFunctionCall($part->functionCall);
        if (null === $hash) {
            return;
        }

        $this->signatures[$hash] = $part->thoughtSignature;

        // Persist across PHP requests: a write ability pauses the run for user
        // confirmation and resumes in a new request, which must still be able to
        // re-inject this signature into the re-serialised call.
        set_transient(
            $this->signatureTransientKey($hash),
            $part->thoughtSignature,
            self::SIGNATURE_TRANSIENT_TTL
        );
    }

    /**
     * Builds the transient key for a captured signature hash.
     *
     * @param string $hash The function call hash.
     *
     * @return string
     * @since 1.1.0
     */
    private function signatureTransientKey(string $hash): string
    {
        return self::SIGNATURE_TRANSIENT_PREFIX . get_current_user_id() . '_' . $hash;
    }

    /**
     * Builds a stable hash for a function call from its name and arguments.
     *
     * Empty args (null, missing, or `{}`) are normalised to a single form so that
     * a no-argument call captured from the response matches the same call when the
     * connector re-serialises it (the connector omits empty args).
     *
     * @param mixed $functionCall The decoded function call object.
     *
     * @return string|null The hash, or null when the call has no usable name.
     * @since 1.1.0
     */
    private function hashFunctionCall($functionCall): ?string
    {
        if (
            ! $functionCall instanceof stdClass
            || empty($functionCall->name)
            || ! is_string($functionCall->name)
        ) {
            return null;
        }

        $callArgs = $functionCall->args ?? null;
        if (($callArgs instanceof stdClass || is_array($callArgs)) && 0 === count((array) $callArgs)) {
            $callArgs = null;
        }

        $argsKey = null === $callArgs ? 'null' : (string) wp_json_encode($callArgs);

        return md5($functionCall->name . '|' . $argsKey);
    }

    /**
     * Determines whether a URL targets the Gemini generateContent endpoint.
     *
     * @param mixed $url The URL to check.
     *
     * @return bool
     * @since 1.1.0
     */
    private function isGeminiGenerateUrl($url): bool
    {
        return is_string($url)
            && false !== strpos($url, 'generativelanguage.googleapis.com')
            && false !== strpos($url, ':generateContent');
    }
}
