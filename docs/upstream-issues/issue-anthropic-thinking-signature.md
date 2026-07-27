https://github.com/WordPress/ai-provider-for-anthropic/issues/30

# Extended thinking `signature` is dropped on parse and never re-serialized, breaking multi-turn tool use ("thinking.signature: Field required")

**Repo:** WordPress/ai-provider-for-anthropic
**Plugin version:** 1.0.3
**Endpoint:** `POST https://api.anthropic.com/v1/messages`

## Summary

When extended thinking is enabled and the model reasons *before* calling a tool, any follow-up request in the same conversation is rejected by the Anthropic API with:

```
Bad Request (400) - messages.1.content.0.thinking.signature: Field required
```

The Anthropic API requires that every `thinking` content block returned by the model be echoed back **unchanged** on subsequent turns, including its opaque `signature`. The connector captures only the thinking *text* when parsing a response and drops the `signature`; it then re-serializes the block without a `signature` on the next request, so the API rejects it.

This makes single-shot tool calls appear to work while any flow where the model thinks first and then calls a tool (e.g. "create a post", "search then decide, then write") fails on the second request.

## Steps to reproduce

1. Use a Claude model with extended thinking enabled.
2. Register any function/tool and send a prompt that causes the model to produce a `thinking` block and then a `tool_use` block (e.g. "Create a post about X").
3. Return the tool result and let the loop continue (the connector re-serializes the prior assistant turn, including the thinking block).
4. The next request fails with `messages.N.content.0.thinking.signature: Field required`.

**Expected:** the conversation continues; the thinking block is round-tripped with its `signature`.
**Actual:** 400 error because the `signature` was lost.

## Root cause

`src/Models/AnthropicTextGenerationModel.php`

**Parsing a response** — the `thinking` case builds a `MessagePart` from the text only; the `signature` present in `$partData` is discarded:

```php
// parseResponseContentMessagePart() — around line 546
case 'thinking':
    if (!isset($partData['thinking']) || !is_string($partData['thinking'])) {
        throw new InvalidArgumentException('Part has an invalid thinking shape.');
    }
    return new MessagePart($partData['thinking'], MessagePartChannelEnum::thought());
    // $partData['signature'] is never read
```

**Serializing a request** — the thought part is emitted without a `signature`:

```php
// getMessagePartData() — around line 246
if ($part->getChannel()->isThought()) {
    return [
        'type' => 'thinking',
        'thinking' => $part->getText(),
        // no 'signature'
    ];
}
```

Because the `signature` is never carried on the `MessagePart` and never re-emitted, the round-trip is impossible at the connector level.

## Suggested fix

Carry the `signature` through the round-trip:

1. When parsing (`parseResponseContentMessagePart`), read `$partData['signature']` and store it on the resulting `MessagePart` (e.g. on part metadata / additional data, wherever the DTO can hold provider-specific fields).
2. When serializing (`getMessagePartData`), if a thought part has a stored `signature`, include it in the `thinking` block:

```php
if ($part->getChannel()->isThought()) {
    $block = [
        'type' => 'thinking',
        'thinking' => $part->getText(),
    ];
    $signature = /* stored signature from the part, if any */;
    if (is_string($signature) && $signature !== '') {
        $block['signature'] = $signature;
    }
    return $block;
}
```

If the `MessagePart` DTO in the underlying AI Client cannot currently carry provider-specific metadata like a thinking signature, that DTO may need a place to hold it (this is the same class of problem as Google's `thoughtSignature`).

## Workaround (for reference)

We currently round-trip the signature at the WordPress HTTP layer (`http_request_args` / `http_response`), keyed by a hash of the thinking text, scoped to the Anthropic messages endpoint. It works but is a client-side patch that becomes unnecessary once the connector carries the `signature`.

## Environment

- Anthropic provider connector: 1.0.3
- WordPress AI Client (bundled)
- Reproduced with extended thinking enabled + user-defined tools
- OpenAI and (with unrelated fixes) other providers do not exhibit this because they have no equivalent signed-reasoning round-trip requirement.
