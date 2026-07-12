# Joinery AI Chat — Error Resilience

## Why

When the local model host is unreachable or slow, the chat fails badly on two axes:

1. **The failure is a browser popup, not part of the conversation.** A turn that dies
   ends at a single `alert()`, which is jarring, out of place, and loses the message —
   the user has to dismiss a modal and there is nothing in the transcript to retry from.
2. **The failure takes far too long to surface.** A turn goes straight into a full
   streaming generation call with a 10s TCP-connect cap and a 300s read cap. When the
   host is asleep or Tailscale has dropped, the user waits; when the host is reachable
   but the model is cold or the box is under memory pressure, the generation stalls and
   the user waited ~85s in the observed case before the "could not reach" message.

We are seeing intermittent connectivity drops to the model host, so this is happening in
practice. The fix is three independent pieces, each shippable on its own.

## Current behavior (grounded)

- **Turn failures end at `alert()`.** The live-bubble streamer's failure callback is
  `plugins/joinery_ai/includes/chat_view_body.php:483` —
  `function (err) { setBusy(false); alert(err || 'The turn could not be completed.'); }`.
  The poller reports the failure by calling that callback with `data.error`
  (`chat_view_body.php:424`).
- **The inline pattern already exists for send rejections.** `send()` surfaces a rejected
  *send* as an inline amber note via `rejectSend()` / `showSendNotice()`
  (`chat_view_body.php:816-827`) — no popup. Turn failures simply never adopted it.
- **The server text is already correct and classified.** `ChatTurn::runAndFinalize`
  catches `LlmProviderException`, classifies it, and writes the friendly string into
  `aim_error` (`plugins/joinery_ai/includes/ChatTurn.php:32-36`, `markFailed` at 128-136).
  For an unreachable local host the classify code is `api_network_error`
  (`LlmProviderException::friendlyMessage`). The poller returns that string as
  `data.error` (`logic/chat_poll_logic.php:63`).
- **No reachability pre-check exists anywhere.** The turn goes straight into
  `ChatRunner::runTurn` → `AgentLoop` → `$provider->createMessageStreamed()`
  (`AgentLoop.php:160`). The provider is built by
  `LlmProviderFactory::forConversation($conversation)` (`ChatRunner.php:168`).
- **Timeouts on the local provider** (`llm/OpenAiCompatibleProvider.php`): client
  `connect_timeout` = 10s (constructor, line 61); per-call total `timeout` = 0
  (disabled, line 141); per-call `read_timeout` = the `joinery_ai_local_timeout_seconds`
  setting = 300 (line 142). A `ConnectException` — connection refused / DNS / cURL
  operation-timeout — is rethrown with a "not reachable" message so `classify()` reads it
  as `api_network_error` (lines 145-149).
- **Live config (dev):** provider `local`, base `http://100.69.133.69:11434/v1`
  (Tailscale), model default `qwen3.6:35b-a3b-nvfp4`, timeout `300`. The host is a
  Tailscale peer; when it sleeps or disconnects the connect stalls.

## Goals

- Chat turn failures render **inline in the transcript** as a failed message with the
  server's error text and a one-tap **Retry**, never a popup.
- A genuinely-unreachable host fails **within ~2-3s**, not 10s+ of dead connect wait.
- A reachable-but-stalled generation (no first token) fails **legibly and fast**, not
  after 300s.

## Non-goals

- Not changing the classify/friendly-message layer — the strings are already right.
- Not adding a background health monitor or status indicator in the UI. A reachability
  probe runs per-turn, on demand; there is no polling health widget.
- Not touching the cloud providers' own retry/timeout logic (Anthropic already retries).
  Reachability probing is a no-op for cloud providers (see Piece B).
- No new required install/config: any new setting ships with a factory default in
  `plugin.json`, consistent with zero-config install.

---

## Piece A — Inline turn-failure rendering (frontend only)

**Independent. No backend change. Ship first — it removes the popup on its own.**

Replace the `alert()` failure path with a failed message bubble rendered into the
transcript, reusing the live bubble that was already streaming for that message id.

- Add a `renderFailedBubble(messageId, errorText)` helper next to `replaceBubble`
  (`chat_view_body.php:1324`). It finds the `.joai-chat-msg[data-message-id=...]` bubble
  (the live streaming one) and rewrites it to a failed bubble:
  - class `joai-chat-msg joai-chat-assistant joai-chat-failed`
  - the error text (from `data.error`) as the body — **set via `textContent`**, never
    innerHTML (provider error strings are not trusted markup)
  - a **Retry** button. If no bubble with that id exists (edge case), append a new one.
- Point the two failure callbacks at it:
  - `streamInto`'s `onFailed` (line 483) → `setBusy(false); renderFailedBubble(messageId, err);`
  - the poll `onFailed` cases already flow through that same callback (lines 413, 419,
    424, 427, 433), so they all become inline automatically.
- **Retry:** re-runs the same turn. The simplest correct behavior: remove the failed
  bubble and re-issue the *last user message* through the existing `send()` path. Capture
  the last user message text/attachments at send time (a `lastSent` reference) so Retry
  can replay it. If replaying attachments is awkward (File objects may be gone), Retry may
  fall back to re-sending text only, with the attachment strip repopulated from the
  composer if still present — keep this behavior explicit in the bubble ("Retry" replays
  your last message).
- **CSS:** add `.joai-chat-failed` styling to the plugin stylesheet
  (`plugins/joinery_ai/assets/css/joinery_ai.css`) — a muted error treatment consistent
  with the existing amber send-notice (`showSendNotice`), vanilla CSS only, theme-neutral.
  No Bootstrap.
- **Scope:** turn-lifecycle failures only. The capability-toggle and control-save
  `alert()`s (`chat_view_body.php:498`, control wiring) are out of scope here; they are
  not turn failures and can migrate opportunistically later.

**Acceptance:** with the host unplugged, sending a message shows a failed bubble in the
transcript carrying "Could not reach the AI provider…", no popup, and Retry re-sends.

---

## Piece B — Pre-flight reachability probe (backend)

**Independent of A. Makes the offline case fail in ~2-3s instead of 10s+.**

Add a cheap, short-timeout reachability check the turn runs *before* committing to a full
generation call. Keep the runner provider-agnostic: the check lives on the provider and
returns a uniform result.

- **Interface:** add to `LlmProviderInterface`:
  ```php
  /**
   * Fast pre-turn reachability check. Returns null when the provider is reachable
   * (or when no cheap probe applies — cloud providers), or a short user-facing
   * error string when the host is definitively unreachable. Must be cheap and
   * short-timeout; it runs on the turn's critical path.
   */
  public function reachabilityProbe(): ?string;
  ```
- **`OpenAiCompatibleProvider::reachabilityProbe()`** — a `GET {base_url}/models` with a
  short bound (connect + total ≈ 2-3s; a dedicated short-timeout Guzzle call, not the
  300s client). On `ConnectException` return `unreachableMessage()`; on any HTTP response
  (even non-200) or other error return `null` — we only care about transport reachability,
  not that the models endpoint is perfectly healthy. `/v1/models` is confirmed to return
  200 on the live host.
- **`AnthropicProvider` / `FireworksProvider`:** return `null` (no probe — cloud
  reachability is not the failure mode, and adding a synchronous round-trip to every cloud
  turn is not worth it).
- **Call site:** at the top of `ChatTurn::runAndFinalize` **and** `resumeAndFinalize`,
  before `ChatRunner::runTurn` / `resumeTurn`. Build the provider the same way the runner
  does (`LlmProviderFactory::forConversation($conversation)` — construction is cheap) or
  expose a small `ChatRunner::reachabilityError(AiConversation): ?string` helper so the
  factory/pin logic (Fortress local-only) stays in one place. If the probe returns a
  string, `markFailed($msg, LlmProviderException::friendlyMessage('api_network_error'))`
  and return immediately — the poller surfaces it inline (Piece A) within one poll
  interval (~600ms).
- Keep the existing post-generation catch as the backstop: the probe reduces the common
  offline latency, it does not replace error handling for mid-turn failures.

**Acceptance:** with the host unplugged, the turn is marked FAILED within ~2-3s (measure
the placeholder→FAILED interval), versus the current 10s+ connect wait, and the inline
bubble (Piece A) shows the network message.

---

## Piece C — First-token timeout (backend)

**Independent. Handles the reachable-but-stalled case (the observed ~85s).** A probe (B)
says "reachable ✓" here because the box answered the connect; the generation then hangs
while a cold 35B model loads or the box thrashes. Today the only bound is the 300s
inactivity read timeout, so the user waits far too long.

- Add a **time-to-first-token** bound distinct from the between-token inactivity bound.
  New plugin setting `joinery_ai_local_first_token_timeout_seconds`, factory default ~60,
  declared in `plugin.json` (seeded automatically, no migration).
- In `OpenAiCompatibleProvider::createMessageStreamed`, apply the shorter first-token
  bound to the read until the first byte/token arrives, then relax to the existing
  `read_timeout` (300s) for the rest of the stream so a legitimately long generation is
  never cut mid-answer. Implementation options to weigh at build time:
  - set `read_timeout` to the first-token value on the initial `post(...)`, and once
    `consumeStream` sees the first delta, the remaining reads are governed by the stream
    body's own timeout — or re-read with the relaxed bound; or
  - wrap the first read in a short-timeout guard and stream the remainder normally.
  A first-token timeout must classify as `api_network_error` (surface as "the model
  didn't start responding") so it renders inline like any other turn failure.
- Document the two knobs together in the plugin docs: `first_token_timeout_seconds`
  (how long to wait for the model to *start*) vs `timeout_seconds` (max quiet gap
  *between* tokens once it has started).

**Acceptance:** point the host at a deliberately stalled/cold generation; the turn fails
within ~first-token-timeout seconds with an inline "didn't start responding" message,
while a normal long answer that streams tokens steadily still completes.

---

## Build order & phasing

The pieces are independent and land in this order (each shippable and testable alone):

1. **Piece A** — inline rendering. Highest UX value, frontend-only, zero backend risk.
2. **Piece B** — reachability probe. Fast-fails the offline/Tailscale-drop case.
3. **Piece C** — first-token timeout. Fast-fails the reachable-but-stalled case.

Taking connectivity drops in pieces: A + B together already convert the common drop into
a fast, inline, retryable failure. C closes the slow-generation gap.

## Testing

- **Piece A:** a functional/browser check that a failed turn produces a
  `.joai-chat-failed` bubble (not a dialog) and Retry re-issues the last message. Add to
  the joinery_ai test area; drive with the live host disabled.
- **Piece B:** unit-level test of `OpenAiCompatibleProvider::reachabilityProbe()` against
  a closed port (returns the unreachable string fast) and against a live/stub 200
  (returns null); assert cloud providers return null. Assert `ChatTurn` marks FAILED fast
  when the probe fails (measure the interval).
- **Piece C:** test that first-token exceeding the bound throws a network-classified
  error, and that a slow-but-steady stream past the first token is not cut.
- Gate everything through `php tests/run.php safe` before shipping each piece.

## Docs to update

- `plugins/joinery_ai/docs/overview.md` — describe the per-turn reachability probe, the
  two local timeout settings (`first_token_timeout_seconds` vs `timeout_seconds`), and
  the inline failed-bubble behavior. Write the docs to the end state only (no "previously"
  narration).
- Move this spec to `specs/implemented/` when all three pieces land.
