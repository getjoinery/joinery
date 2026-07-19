# AI Chat Thinking Visibility — Live Reasoning Progress + No Empty Replies

**Status:** Draft

**Follow-on to:** `specs/implemented/ai_chat_turn_activity.md` (the live activity
line this spec extends).

## The problem

Thinking models (the local qwen3.6 models, Claude with extended thinking) can
reason for minutes before emitting a single answer token. During that stretch
the chat UI shows only `Waiting for {model}… · 5m 40s` — because every
reasoning delta is deliberately stripped inside the provider before it reaches
the stream sink, the turn produces no partial text, no `Writing…` transition,
nothing. A healthy model reasoning hard is indistinguishable from a hung one.

Worse: when the reasoning chain consumes the entire per-call output cap
(`AgentLoop::LOCAL_PER_CALL_MAX_TOKENS`, 16 000), the generation is cut with
zero answer text. The turn finalizes as **complete** with an empty message —
the user watches a timer run six minutes and then gets literally nothing.
(Observed live 2026-07-18 against the Mac Studio Ollama host.)

Two facts make the fix small:

1. The provider layer already streams from the LLM and already *parses* the
   reasoning content — the `<think>` filter in
   `OpenAiCompatibleProvider::thinkPush()` walks every reasoning byte and
   throws it away. The information exists at exactly one seam; it is just
   never reported.
2. The browser needs no new transport. The poll loop already renders whatever
   label the runner stamps into `aim_activity`. A progress label is one more
   stamp through an existing channel.

## What the user gets

While the model reasons, the activity line under the live bubble counts up:

```
◦ ◦ ◦
Thinking… ~3,400 tokens · 2m 40s
```

then hands off to the existing `Writing…` the moment answer text starts. A
stuck provider still reads as `Waiting for {model}…` with no count — so the
three states (not started / reasoning / answering) are finally distinguishable
at a glance.

And a turn can no longer end as an empty bubble: a generation that spent its
whole output budget reasoning finalizes as **failed** with a plain
explanation, e.g.

> The model used its entire output budget on reasoning and produced no answer.
> Try a lower thinking level, or ask again more narrowly.

## Design

### 1. Providers report reasoning deltas — new optional callback

`LlmProviderInterface::createMessageStreamed()` gains a fourth optional
parameter:

```php
public function createMessageStreamed(array $params, callable $onTextDelta,
        ?callable $shouldAbort = null, ?callable $onThinkingDelta = null): array;
```

`$onThinkingDelta(string $piece)` receives raw reasoning fragments as they
arrive. Reasoning text still never appears in the returned canonical content
or in `$onTextDelta` — the existing contract ("reasoning output is never
emitted") holds for the answer channel; this is a separate reporting channel.
`null` preserves current behavior exactly.

Per provider:

- **OpenAiCompatibleProvider** — `thinkPush()` currently discards the spans
  inside `<think>…</think>`. Capture those spans and feed them to the
  callback instead of dropping them (the state machine already isolates them;
  this is returning what it deletes, not new parsing). Additionally, if a
  chunk carries `delta.reasoning_content` or `delta.reasoning` (servers that
  separate reasoning from content instead of inlining `<think>` tags), feed
  that through the same callback.
- **AnthropicProvider** — `consumeStream()` feeds `thinking_delta` event text
  to the callback.
- **FireworksProvider** — inherits the OpenAI-compatible behavior.

### 2. The loop forwards it — one ToolContext seam

`ToolContext` gains `emitThinking(string $piece): void` with a no-op default
(recipes stay autonomous and silent, same posture as `emitText`).
`AgentLoop::run()` passes `[$context, 'emitThinking']` as the fourth argument
at its single provider call site. No other loop change.

### 3. ChatTurnContext turns deltas into a progress label

`ChatTurnContext::emitThinking()` accumulates a character count and stamps the
activity line through the existing `noteActivity()` →
`ChatAsync::activityStamper()` path:

```
Thinking… ~{n} tokens
```

where `n` is `chars / 4`, rounded to a friendly figure (2 significant digits —
`~340`, `~3,400`). Stamps are throttled to at most one every 2 seconds; the
poll cadence (600 ms) makes anything finer invisible anyway.

Sealed-conversation posture: the label carries a **count only, never
reasoning content** — the same cleartext-stage-label discipline
`aim_activity` already has on sealed rows. The reasoning text itself is not
persisted anywhere, in any mode.

Label hand-off order within a turn step:
`Waiting for {model}…` → `Thinking… ~n tokens` → `Writing…` (first answer
delta, stamped by the existing stream sink) → (tool labels / next step as
today).

### 4. Empty-answer guard at finalize

In `AgentLoop::run()`, the `end_turn` branch currently accepts an empty
`$iter_text` as a normal completion. New rule: if the loop ends with
`stop_reason = end_turn`, **no pending action**, and empty trimmed assistant
text, the turn result carries `stop_reason = 'empty_answer'` with a detail
that distinguishes the two causes:

- provider finish was `length` (the output cap cut the generation — by far
  the common case with a runaway reasoning chain): detail names the output
  budget and suggests a lower thinking level;
- genuine empty `end_turn` (rare): generic "the model returned no text".

`ChatTurn::runAndFinalize()` maps `empty_answer` to `aim_status = failed`
with the user-facing message above (sealed rows via the existing
`ChatSeal::errorColumns()` path). The front-end already renders failed turns
(`renderFailedBubble`) — no JS change.

To make cause (a) detectable, `OpenAiCompatibleProvider::mapStopReason()`
must preserve the `length` finish as `max_tokens` on a text-less response
rather than collapsing it into `end_turn`; the loop reads it from the
response's `stop_reason` before applying the guard.

## Integration points (complete inventory)

| Seam | Change |
|---|---|
| `includes/llm/LlmProviderInterface.php` | 4th optional param on `createMessageStreamed()` |
| `includes/llm/OpenAiCompatibleProvider.php` | `thinkPush()` capture + `reasoning_content`/`reasoning` fields; `length` stop-reason preserved |
| `includes/llm/AnthropicProvider.php` | `thinking_delta` → callback |
| `includes/llm/FireworksProvider.php` | inherits; verify no override swallows it |
| `includes/ToolContext.php` | `emitThinking()` no-op default |
| `includes/AgentLoop.php` | forward callback; `empty_answer` guard |
| `includes/ChatTurnContext.php` | `emitThinking()` counter + throttled stamp |
| `includes/ChatTurn.php` | `empty_answer` → failed finalize |
| `RecipeRunContext`, `PipelineRunner` | none (no-op default) — confirm only |
| Front-end (`includes/chat_view_body.php`) | none — existing activity line renders the label |
| Poll endpoints / serializer / DB schema | none — `aim_activity` unchanged |
| `plugins/joinery_ai/plugin.json` | version bump |

No new settings (zero-config: the token estimate is `chars/4`, the throttle is
a constant). No schema change. No new endpoints.

## Non-goals

- **Streaming the reasoning text itself into the UI.** A visible (collapsed)
  thinking pane would need a second content channel with the full
  sealed-conversation scratch discipline (`/dev/shm`, wipe-on-lock) plus
  render UX. The count restores legibility at a fraction of that surface; if
  the count proves insufficient, spec the pane separately.
- **SSE / holding the browser connection open.** The detach-and-poll
  transport (`ChatAsync`) exists so turns survive proxy idle limits; nothing
  here requires replacing it.
- **Automatic retry on `empty_answer`.** Silent re-spends of a 16k-token
  generation hide cost; the failed bubble tells the user what to change.

## Tests

New file `plugins/joinery_ai/tests/thinking_visibility_test.php`
(`@joinery-test`, tier `safe` unless a check needs the DB, then `db`):

1. **Think-filter capture** — feed the provider's filter synthetic chunk
   sequences (tag split across chunk boundaries, multiple think blocks,
   `reasoning_content` field variant): answer channel unchanged from today,
   thinking callback receives exactly the discarded spans.
2. **Label shaping/throttle** — `emitThinking` produces `Thinking… ~n tokens`
   with rounded counts; consecutive deltas within the throttle window stamp
   once.
3. **Empty-answer guard** — a loop result with empty text + `end_turn` +
   no pending action finalizes failed with the length-aware message; a normal
   answered turn and a pending-action halt are untouched.
4. **Recipe surface unaffected** — `RecipeRunContext::emitThinking()` no-ops.

Live sanity (manual, dev): a chat turn against the Studio with thinking on
shows the counting label, then `Writing…`; a `num_predict`-starved prompt
surfaces the failed bubble instead of an empty one.

## Docs task

Update `plugins/joinery_ai/docs/overview.md` → **Chat › Asynchronous turns**:
add `Thinking… ~n tokens` to the activity-label list, and document the
empty-answer failure semantics (current-state phrasing only). No other doc
touches.
