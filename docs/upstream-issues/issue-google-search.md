https://github.com/WordPress/ai-provider-for-google/issues/32

# Response parser throws "Part has an unexpected type" on server-side tool invocation parts (toolCall / toolResponse) when combining googleSearch with function calling

**Plugin version:** AI Provider for Google 1.1.0
**Related:** php-ai-client SDK web search (`WebSearch`) + function declarations

## Summary

When a request combines the built-in `googleSearch` tool (via `WebSearch`) with `functionDeclarations` (function calling), the Google Gemini API requires `toolConfig.includeServerSideToolInvocations = true`. With that flag enabled, Gemini echoes the server-side search invocation back into the response `candidates[].content.parts[]` as two extra parts keyed `toolCall` and `toolResponse`.

`GoogleTextGenerationModel::parseResponseCandidateMessagePart()` does not recognize these keys, so it falls through to `throw new InvalidArgumentException('Part has an unexpected type.')`, which surfaces to the caller as:

```
Unexpected Google API response: Invalid "candidates[0].content.parts[0]" key: Part has an unexpected type.
```

The whole generation fails even though the model also returned a valid text answer in a sibling part.

## Steps to reproduce

1. Build a prompt that enables **both** web search and function calling, e.g.:
   ```php
   $builder
       ->using_web_search(new \WordPress\AiClient\Tools\DTO\WebSearch())
       ->using_abilities(/* one or more abilities → functionDeclarations */);
   ```
2. Because Gemini rejects built-in tools + function calling without it, set `toolConfig.includeServerSideToolInvocations` (otherwise you get the 400: *"Please enable tool_config.include_server_side_tool_invocations to use Built-in tools with Function calling."*). I do this via `ModelConfig::setCustomOption('toolConfig', ['includeServerSideToolInvocations' => true])` + `using_model_config()`.
3. Send a prompt that triggers a web search (e.g. "What's the latest WordPress version?").
4. The request to Gemini succeeds (HTTP 200), but parsing the response throws `Part has an unexpected type`.

## Actual response parts

The failing parts look like this (`thoughtSignature` values truncated):

```json
{
  "thoughtSignature": "…",
  "toolCall": {
    "toolType": "GOOGLE_SEARCH_WEB",
    "args": { "queries": ["…"] },
    "id": "utWEB6L1"
  }
}
```

```json
{
  "thoughtSignature": "…",
  "toolResponse": {
    "toolType": "GOOGLE_SEARCH_WEB",
    "response": { "search_suggestions": "<style>…</style><div class=\"container\">…Google Search chips…</div>" },
    "id": "TnTtVb21"
  }
}
```

The actual model answer arrives in a separate `text` part after these two.

## Root cause

`parseResponseCandidateMessagePart()` handles `text`, `inlineData`, `fileData`, `functionCall`, `functionResponse` and then throws on anything else. Server-side built-in tool invocations (`toolCall` / `toolResponse`) are not among the handled types, and a part may also contain only a `thoughtSignature`.

## Suggested fix

Skip parts that carry no client-actionable content instead of throwing. Since the search already ran on Google's side, the client has nothing to execute; the grounded answer is in the sibling `text` part(s).

Change `parseResponseCandidateMessagePart()` to return `?MessagePart`:

```php
// Server-side built-in tool invocations (e.g. googleSearch) are echoed back as
// `toolCall` / `toolResponse` parts when toolConfig.includeServerSideToolInvocations
// is enabled to combine built-in tools with function calling. The tool has already
// run on Google's side, so there is nothing for the client to act on; skip these
// parts (and any standalone `thoughtSignature`) rather than failing the whole
// response. The grounded answer arrives in the sibling text parts.
if (isset($partData['toolCall']) || isset($partData['toolResponse'])) {
    return null;
}
if (isset($partData['thoughtSignature']) && count($partData) === 1) {
    return null;
}
throw new InvalidArgumentException('Part has an unexpected type.');
```

And filter out the skipped parts in the caller (`parseResponseCandidateMessage()`):

```php
$part = $this->parseResponseCandidateMessagePart($messagePartData);
if ($part !== null) {
    $parts[] = $part;
}
```

## Notes / open questions

- The `toolResponse.response.search_suggestions` payload contains the Google Search suggestion chips (HTML + Google logo). Google's grounding terms usually require these to be displayed for attribution, so rather than silently dropping them, the maintainers may prefer to eventually surface this content as grounding metadata on the result. The minimal fix above just prevents the crash.
- A `TextStream` / streaming code path, if present, likely needs the same handling.

## Environment

- WordPress 7.0 (bundled php-ai-client SDK v1.3.1)
- AI Provider for Google 1.1.0
- PHP 8.x
- Model: Gemini (with `googleSearch` + function declarations)
