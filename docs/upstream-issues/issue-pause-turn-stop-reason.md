https://github.com/WordPress/ai-provider-for-anthropic/issues/29

# pause_turn stop reason is not handled — server-side tool (web search) responses are silently truncated

**Plugin version:** AI Provider for Anthropic 1.0.3
**Related:** php-ai-client SDK web search (`WebSearch`) + server-side tools

## Summary

When a request enables Anthropic's server-side web search tool, the Messages API runs an internal loop (search → evaluate → maybe search again → answer). That loop has an iteration budget. If the model reaches the budget **before finishing**, the API returns the turn with `stop_reason: "pause_turn"` instead of `"end_turn"`. Per Anthropic's docs, `pause_turn` means *"I paused, resend the conversation with the same tools to let me continue"* — it is **not** a terminal stop.

`AnthropicTextGenerationModel` does not implement this continuation. It maps `pause_turn` to the same terminal `FinishReasonEnum::stop()` as `end_turn`, and then strips the raw `stop_reason` from the result metadata. As a result:

- The caller receives what looks like a **complete** response (`finishReason = stop`), but the assistant content is actually **partial** — often just a heading or the first sentence.
- Token usage is still high (e.g. 25,000+ tokens) because the model really did run several server-side searches — but the user never sees the finished answer, and there is no signal that a continuation was expected.

Because both the finish-reason mapping and the metadata are provider-internal, a downstream consumer of the SDK has **no way** to detect the pause and resume the turn.

## Steps to reproduce

1. Build a prompt with web search enabled:
   ```php
   $builder
       ->using_provider('anthropic')
       ->using_web_search(new \WordPress\AiClient\Tools\DTO\WebSearch());
   ```
2. Ask a question that requires several searches to answer well (e.g. "Research X and write a structured overview with sections").
3. The HTTP request succeeds (200). The model performs multiple server-side searches, hits the internal tool-iteration budget, and returns `stop_reason: "pause_turn"`.
4. `generateTextResult()` returns a `GenerativeAiResult` whose only candidate has `finishReason = stop` and truncated text (e.g. just the "Overview" heading). No further request is made, so the answer is never completed.

## Root cause

`src/Models/AnthropicTextGenerationModel.php`, `parseResponseToGenerativeAiResult()`:

1. The stop-reason switch treats `pause_turn` as terminal, identical to `end_turn`:
   ```php
   switch ($responseData['stop_reason']) {
       case 'pause_turn':
       case 'end_turn':
       case 'stop_sequence':
           $finishReason = FinishReasonEnum::stop();
           break;
       // ...
   }
   ```
2. The raw `stop_reason` is then removed from the metadata that reaches the caller, so the `pause_turn` signal is lost even for consumers willing to handle it themselves:
   ```php
   $additionalData = $responseData;
   unset(
       $additionalData['id'],
       $additionalData['role'],
       $additionalData['content'],
       $additionalData['stop_reason'], // <-- pause_turn signal discarded here
       $additionalData['usage']
   );
   ```

There is no `FinishReasonEnum` value that represents a non-terminal "paused / must continue" state, so the SDK abstraction currently cannot express `pause_turn` at all.

## Suggested fix

`pause_turn` is a transport-level detail of how Anthropic streams long server-side-tool turns. The cleanest place to handle it is **inside the provider**, transparently, so every SDK consumer benefits and the `FinishReasonEnum` abstraction stays clean.

### Preferred: continue transparently in `generateTextResult()`

When the parsed result is `pause_turn`, append the returned assistant `content` **verbatim** to the messages and re-POST the *same* params (crucially, the same `tools` array) until a terminal stop reason is reached, then return the consolidated result. Bound the loop with a small guard to avoid pathological infinite continuation.

Sketch:

```php
final public function generateTextResult(array $prompt): GenerativeAiResult
{
    $params = $this->prepareGenerateTextParams($prompt);

    $maxContinuations = 10; // guard against runaway loops
    for ($i = 0; ; $i++) {
        $response = $this->getHttpTransporter()->send($this->buildRequest($params));
        ResponseUtil::throwIfNotSuccessful($response);

        $data = json_decode((string) $response->getBody(), true);

        if (($data['stop_reason'] ?? null) !== 'pause_turn' || $i >= $maxContinuations) {
            return $this->parseResponseToGenerativeAiResult($response);
        }

        // Resend with the paused assistant turn appended and the SAME tools.
        $params['messages'][] = [
            'role'    => $data['role'] ?? 'assistant',
            'content' => $data['content'] ?? [],
        ];
    }
}
```

(Adapt to the actual request/response helpers; the key points are: append the assistant `content` unchanged, keep the identical `tools`/params, loop until `stop_reason !== 'pause_turn'`, and cap the iterations.)

If continuations are consolidated into a single returned result, the accumulated `content` blocks (text + `web_search_tool_result`) and summed `usage` across the paused turns should be merged so token accounting and the final text are complete.

### Alternative / minimal: expose the signal

If transparent continuation is out of scope, at minimum **preserve** `stop_reason` in `additionalData` (i.e. don't `unset` it), or introduce a dedicated `FinishReasonEnum` value (e.g. `PAUSE` / `INCOMPLETE`) for `pause_turn`, so downstream code can implement the continuation loop itself. As shipped, neither is possible — the signal is discarded in both the enum mapping and the metadata.

## Notes / open questions

- This is Anthropic-specific: OpenAI (`web_search` on the Responses API) and Google (`googleSearch` grounding) complete their server-side search within a single response, so they don't surface a pause/continue state. A shared SDK abstraction would still benefit from a first-class "paused / incomplete" finish reason.
- On continuation the `tools` array **must be identical** to the original request; otherwise Anthropic errors or restarts the reasoning.
- A streaming code path, if present, needs the same handling (`pause_turn` can arrive at the end of a stream).
- Reference: Anthropic Messages API docs on `stop_reason: "pause_turn"` and web search / server-side tools.

## Environment

- WordPress 7.0 (bundled php-ai-client SDK v1.3.1)
- AI Provider for Anthropic 1.0.3
- PHP 8.x
- Model: Claude (with `web_search` server-side tool enabled)
