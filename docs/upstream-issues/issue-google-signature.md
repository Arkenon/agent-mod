# Function call thought signatures are dropped, breaking multi-turn tool calling with thinking models

**Repo:** WordPress/ai-provider-for-google
**Plugin version:** 1.1.0
**Endpoint:** `POST https://generativelanguage.googleapis.com/.../:generateContent`

## Summary

Gemini thinking models attach a `thoughtSignature` to every `functionCall` part they return. The Google AI API requires that signature to be sent back **unchanged** with the conversation history on every following turn. The connector neither reads it when parsing a response nor writes it when serializing the history, so the signature is lost after the first turn and any agentic (multi-turn) tool call fails with:

```
Bad Request (400) - Function call is missing a thought_signature in functionCall parts.
```

This makes tool calling unusable with thinking models: the model asks for a tool, the client executes it and feeds the result back, and that second request is rejected.

## Steps to reproduce

1. Use a thinking Gemini model (e.g. `gemini-3-pro-preview`).
2. Send a prompt with one or more `functionDeclarations` that the model will want to call.
3. The model responds with a `functionCall` part carrying a `thoughtSignature`.
4. Execute the function, append the model message plus the `functionResponse` to the history, and send the next request.
5. The request is rejected — the re-serialized `functionCall` part has no `thoughtSignature`.

**Expected:** the signature round-trips through the message history and the second turn succeeds.
**Actual:** the signature is dropped and the API rejects the request.

## Root cause

`src/Models/GoogleTextGenerationModel.php`

- `parseResponseCandidateMessagePart()` builds the `MessagePart` from `functionCall` only and ignores the sibling `thoughtSignature` key.
- `getMessagePartData()` serializes a function call part back to `['functionCall' => [...]]` with no `thoughtSignature`.

The AI Client `MessagePart` DTO has carried thought signatures since 1.3.0 (`MessagePart::__construct()` third argument, `MessagePart::getThoughtSignature()`, and the `thoughtSignature` key in `toArray()`/`fromArray()`), so nothing needs to be stored outside the existing message history — the connector simply is not using it.

## Suggested fix

Read the signature when parsing:

```php
$functionCall = new FunctionCall(null, $partData['functionCall']['name'], $args);

$thoughtSignature = isset($partData['thoughtSignature']) && is_string($partData['thoughtSignature'])
    ? $partData['thoughtSignature']
    : null;
if ($thoughtSignature !== null && self::supportsThoughtSignatures()) {
    return new MessagePart($functionCall, null, $thoughtSignature);
}
return new MessagePart($functionCall);
```

and write it back when serializing:

```php
$partData = ['functionCall' => $functionCallData];

$thoughtSignature = $this->getMessagePartThoughtSignature($part);
if ($thoughtSignature !== null) {
    $partData['thoughtSignature'] = $thoughtSignature;
}
return $partData;
```

with the AI Client version gated the same way the connector already gates `ProviderMetadata` features in `GoogleProvider::providerMetadata()`:

```php
protected static function supportsThoughtSignatures(): bool
{
    return version_compare(AiClient::VERSION, '1.3.0', '>=');
}
```

Because the value travels inside `MessagePart`, it also survives `Message::toArray()` / `Message::fromArray()`, so it is preserved by clients that persist the conversation between HTTP requests.

## Workaround (for reference)

We currently capture each signature from the raw Gemini response and re-inject it into the matching outgoing `functionCall` part at the WordPress HTTP layer (`http_request_args` / `http_response`), scoped to the Gemini `:generateContent` endpoint, matching calls by a hash of name + args. It works but is a client-side patch that becomes an unnecessary no-op once the connector round-trips the signature itself.

## Environment

- AI Provider for Google 1.1.0
- WordPress AI Client 1.3.1 (bundled with WordPress 7.0)
- PHP 8.x
- Model: thinking Gemini model with function declarations

## Related

- Combining `googleSearch` with function calling requires `toolConfig.includeServerSideToolInvocations`, which makes the API echo server-side invocations back as `toolCall` / `toolResponse` parts — see the separate google search issue.