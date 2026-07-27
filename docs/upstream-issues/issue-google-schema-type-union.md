https://github.com/WordPress/ai-provider-for-google/issues/33

# Function-declaration schemas with a JSON Schema `type` union break Gemini ("Proto field is not repeating, cannot start list")

**Repo:** WordPress/ai-provider-for-google
**Plugin version:** 1.1.0
**Endpoint:** `POST https://generativelanguage.googleapis.com/.../:generateContent`

## Summary

Gemini's `generateContent` function-declaration schema is a proto-based OpenAPI subset in which a property's `type` is a **non-repeating scalar field**. Valid JSON Schema, however, allows a `type` union (e.g. `"type": ["string", "number", "null"]`). When any tool declares such a union, Gemini rejects the **entire** request with:

```
Bad Request (400) - Invalid JSON payload received.
Unknown name "type" at 'tools[0].function_declarations[34].parameters.properties[1].value':
Proto field is not repeating, cannot start list.
```

Because one malformed declaration fails the whole request, **every** call breaks — including plain chat and vision requests that don't use tools at all — as long as the affected tool is present in the `tools` array. In our plugin this makes Gemini completely unusable while OpenAI and Anthropic accept the identical tool set.

## Steps to reproduce

1. Register a function/tool whose input schema contains a property with a `type` union, e.g.:

```php
'value' => [
    'type' => ['string', 'number', 'integer', 'boolean', 'array', 'object', 'null'],
    'description' => 'The new value.',
],
```

(This is a legitimate JSON Schema shape for a free-form value; OpenAI and Anthropic accept it as-is.)

2. Send any request to Gemini with that tool present in `tools` — even a tool-less chat prompt.
3. The request fails with `Proto field is not repeating, cannot start list` at the `type` field.

**Expected:** the union is normalized to a Gemini-compatible single scalar `type` (with `nullable` when the union included `"null"`), and the request succeeds.
**Actual:** the whole request is rejected.

## Root cause

`src/Models/GoogleTextGenerationModel.php`

The connector already performs Gemini-specific schema massaging when preparing function declarations — it recursively strips `additionalProperties`, which the Google API also disallows:

```php
// prepareFunctionDeclarationsParam() — around line 489
foreach ($functionDeclarations as $functionDeclaration) {
    $data = $functionDeclaration->toArray();
    if (isset($data['parameters'])) {
        // The Google AI API does not allow the `additionalProperties` key for function parameters.
        $data['parameters'] = $this->removeAdditionalPropertiesKey($data['parameters']);
    }
    $preparedFunctionDeclarations[] = $data;
}
```

`removeAdditionalPropertiesKey()` (around line 512) already walks `properties`, `items`, and nested schemas — but it does not normalize `type` unions, so array-valued `type` reaches the wire and Gemini rejects it.

## Suggested fix

In the same schema-normalization pass (or a sibling of `removeAdditionalPropertiesKey`), collapse a `type` array to a single scalar the Gemini proto accepts:

- Choose the first non-`"null"` member as the scalar `type`.
- If the union contained `"null"`, set `nullable => true` on that node.
- If the union somehow contained only `"null"`, fall back to `"string"`.

```php
if (isset($schema['type']) && is_array($schema['type'])) {
    $hasNull = in_array('null', $schema['type'], true);
    $primary = null;
    foreach ($schema['type'] as $t) {
        if (is_string($t) && $t !== 'null') { $primary = $t; break; }
    }
    $schema['type'] = $primary ?? 'string';
    if ($hasNull) {
        $schema['nullable'] = true;
    }
}
```

This preserves nullability, keeps a single request-level malformed schema from taking down unrelated tools and tool-less requests, and mirrors what the connector already does for `additionalProperties`.

## Workaround (for reference)

We currently normalize outgoing tool schemas at the WordPress HTTP layer (`http_request_args`), scoped to the Gemini `:generateContent` endpoint: empty `properties` arrays → objects, and `type` unions → single scalar + `nullable`. It works but is a client-side patch that becomes unnecessary once the connector normalizes `type` unions like it already normalizes `additionalProperties`.

## Environment

- Google provider connector: 1.1.0
- WordPress AI Client (bundled)
- Same tool set is accepted by OpenAI and Anthropic; only Gemini rejects the `type` union.
- Note: a related but distinct Gemini issue is that an empty `parameters.properties` is serialized by PHP as `[]` instead of `{}` and rejected with "Cannot bind a list to map for field 'properties'". We handle both in the same normalization pass; happy to file that separately if preferred.
