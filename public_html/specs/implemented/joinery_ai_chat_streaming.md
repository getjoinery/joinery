# Joinery AI — Chat streaming (poll-based partial text) + recipe/chat unification

## Goal

Show the assistant's reply as it is written instead of a static "Thinking…"
until the whole reply lands. Do it without holding the HTTP connection open and
without touching the Apache/Cloudflare/php-fpm buffering layer — extend the
detach-and-poll flow we already have: the background turn writes partial text to
the row as tokens arrive, and the poll endpoint returns the growing text.

While we're in this code, collapse the duplicate machinery between RecipeRunner
and ChatRunner onto one path (see Unification). Both surfaces already share
AgentLoop, the providers, the ToolContext interface, and AiPromptBuilder; this
work makes the provider call a single streaming path and folds the remaining
copy-paste into shared helpers.

## Non-goals

- True token-by-token SSE to the browser (held-open connection). Granularity
  here is the poll interval, not per token. Separate, larger effort.
- Streaming tool-call activity markers. Text only for this pass.
- Changes to AgentLoop loop semantics, confirmation cards, token accounting,
  CostGuard, or the monthly cap.

## 1. Provider: one streaming method, used by both surfaces

`AgentLoop.php:95` is the **only** caller of `createMessage` in the codebase, so
we collapse to a single provider method rather than adding a parallel one:

```php
// LlmProviderInterface — replaces createMessage
public function createMessageStreamed(array $params, callable $onTextDelta): array;
```

- Same canonical contract as `createMessage` (returns `{stop_reason, content:[
  blocks], usage}`), and invokes `$onTextDelta(string $delta)` per text fragment
  as it arrives. A no-op callback === today's blocking behavior to the caller.
- Single HTTP path: the request always sends `stream: true`; the provider reads
  the SSE body incrementally (Guzzle `stream => true`, PSR-7 body read line by
  line) and assembles the canonical response. Both recipes and chat use this one
  method — recipes simply pass a no-op sink (see ToolContext below).
- `AnthropicProvider`: emit text from `content_block_delta`/`text_delta`;
  accumulate `input_json_delta` into tool_use input; usage from `message_start` +
  `message_delta`; finalize on `message_stop`.
- `OpenAiCompatibleProvider` (Ollama et al.): emit `choices[].delta.content`;
  accumulate `choices[].delta.tool_calls[]`; require `stream_options:
  {include_usage: true}` for the final usage chunk; stop on `data: [DONE]`.
- Failure handling unchanged — both still throw `LlmProviderException`.

Update the docs/overview and interface comments (which currently name
`createMessage`) to the streamed method.

## 2. ToolContext is the delta sink (no callback threading)

`ToolContext` is an interface implemented by `ChatTurnContext` and
`RecipeRunContext`. Add one method to the interface — the context is already
threaded through the whole loop, so nothing else changes shape:

```php
// ToolContext
public function emitText(string $delta): void;
```

- `RecipeRunContext::emitText()` — no-op (recipes don't stream partials).
- `ChatTurnContext` — holds a settable sink: `setStreamSink(callable $sink)`;
  `emitText()` forwards to it (no-op until set).
- `AgentLoop.php:95` becomes unconditional and signature-stable:
  `$response = $provider->createMessageStreamed($params, [$context, 'emitText']);`
  No new `run()` parameter, no branch.
- The context is created inside `ChatRunner`, so the endpoint can't set the sink
  on it directly. `ChatRunner::runTurn/resumeTurn` therefore take an optional
  trailing `?callable $onTextDelta = null` and call `setStreamSink()` on the
  context they build. This is a chat-only signature addition — it does not touch
  AgentLoop or the recipe path.

## 3. Background worker writes partials

In `chat_run_and_finalize()` (chat_send.php) and `chat_resume_and_finalize()`
(chat_confirm.php), pass a throttled sink closure (built by
`ChatAsync::streamSink($msg, $seed = '')`) into `ChatRunner::runTurn/resumeTurn`;
the runner installs it on the turn's context. Resume seeds the sink with the
pending lead text so partials read continuously.

- Append deltas to an in-memory buffer; flush to `aim_content` when the buffer
  grew by >= ~80 chars OR >= ~400ms since the last flush.
- Row stays `aim_status = running`; only `aim_content` updates.
- Finalize is unchanged: overwrite `aim_content` with the resolved final text,
  set tool_calls/tokens/pending, flip status to complete (resumeTurn still folds
  lead_text + resumed text).
- No new column — partial text reuses `aim_content`, which finalize overwrites.

## 4. chat_poll returns the partial

While `aim_status === running`, also return the partial text:

```json
{ "success": true, "status": "running", "partial_text": "...so far..." }
```

`complete` (assistant_html) and `failed` (error) responses unchanged.

## 5. Frontend (chat.php)

- During polling, when `status === running` and `partial_text` is present,
  render it into a live bubble as escaped plain text (preserve newlines). Do NOT
  markdown-render partials — incomplete markdown renders badly. Update the same
  bubble each tick.
- On `complete`, replace the live bubble with server `assistant_html` (full
  markdown). On `failed`, show the error as today.
- Drop the poll interval to ~600ms while a turn is active (currently 2000ms);
  keep the existing give-up backstop.
- Applies to `send()` (new live bubble) and the confirm handler (`replaceBubble`
  already targets the row by message id).

## 6. Unification (fold in while we're here)

Per the one-path directive, consolidate the RecipeRunner/ChatRunner duplication
this work sits next to. Each becomes one shared implementation:

- **Provider call** — one method (section 1). Done by construction.
- **Untrusted-input block** — `RecipeRunner::buildUntrustedInputBlock` and
  `ChatRunner::buildUntrustedInputBlock` are near-identical (same nonce-wrapper
  contract). Move into `AiPromptBuilder::untrustedInputBlock(array $allowed,
  string $nonce, array $extraSources = [])`. Recipe passes its workspace source
  via `$extraSources`; chat passes none. Both callers delete their copies.
- **System-prompt skeleton** — both assemble: cached text block (preamble +
  model catalog + surface tail) then the untrusted block after the cache
  breakpoint. Extract `AiPromptBuilder::systemBlocks(string $cachedText, string
  $untrustedBlock): array`. Preamble/tail text stays surface-specific (recipe =
  one-shot report writer; chat = interactive assistant) — only the assembly is
  shared.
- **Provider-error classification** — move `RecipeRunner::classifyProviderError`
  to a shared helper (e.g. `LlmProviderException::classify()` or an
  AiPromptBuilder sibling) and have the chat endpoints use it for specific
  failure messages instead of the current generic text.
- **Leave separate** (genuinely different inputs, not duplication): tool
  resolution (recipe allowlist vs chat capability flags) and token bookkeeping
  (rcr_* vs aic_* rows). The shared "imply query_model/describe_models from the
  model allowlist" rule can be a small shared helper if it falls out cleanly;
  don't force it.

## Edge cases

- **Non-fpm fallback:** synchronous path; the chat context simply never sets a
  sink, so `emitText` is a no-op and the full reply rides back in the response.
- **Confirmation pause:** partial text is the lead text; finalize sets the
  pending action + status complete; poll returns the confirmation card HTML.
- **Mid-stream failure:** partial already written; finalize marks failed; poll
  returns the error; frontend discards the partial and shows the error.
- **Stale reap:** unchanged — `ChatAsync::sweepMessage` still reaps a row left
  running past the ceiling.

## Test plan

- Local slow model (qwen3): long reply grows across polls; matches final
  markdown on completion.
- Anthropic model: streamed text + correct final usage/tokens recorded.
- Turn with a tool call then text: text after the tool still streams.
- Mutating proposal: confirmation card still appears, no partial weirdness.
- Provider error mid-turn: row goes failed, page shows it.
- **Recipe regression:** a recipe run still succeeds end-to-end through the new
  single streamed provider path (no-op sink), with unchanged report + tokens.

## Docs (land at implementation, current-state voice)

`plugins/joinery_ai/docs/overview.md`: update the provider-interface description
to `createMessageStreamed`; note in "Asynchronous turns" that the background turn
streams partial text into the assistant row and the poll endpoint returns it;
note that the system-prompt and untrusted-block builders now live in
AiPromptBuilder and are shared by both surfaces.
