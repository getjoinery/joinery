# Spec: Cancel an in-flight chat prompt (Joinery AI)

**Status:** Draft (awaiting implementation)
**Version:** 1.0
**Area:** `plugins/joinery_ai` — member + admin chat UI
**Owner surface:** async chat turn lifecycle

---

## 1. What this does, in plain terms

After a user sends a prompt, the assistant can take a while to answer — and sometimes it
runs long, wanders, or the user simply changes their mind. Today there is no way to stop it:
the only controls are Send and, separately, a confirm/decline card for proposed tool actions.
This feature adds a **Cancel** control that appears while the assistant is generating and
stops the current turn cleanly — the half-written answer is kept and marked cancelled, the
composer unlocks, and the background worker stops doing (and paying for) further work.

This is **not** the existing "Cancel" on the tool-action confirmation card, which declines a
proposed mutating action. That one stays exactly as-is; this is a new, unrelated control.

---

## 2. Why it is more than a button

The chat does not generate the reply inside the page request. Send returns immediately, a
**background worker** runs the turn, and the page **polls** for the result. So a Cancel button
cannot just abort a `fetch` — the work is happening in another process. Three things must
cooperate:

1. **Record the request** — a Cancel endpoint marks the running turn as "please stop."
2. **The worker notices and stops** — the running turn re-reads that flag from the database
   at safe boundaries and exits cleanly.
3. **The page reflects it** — the poll surfaces a new `cancelled` terminal state and the UI
   settles.

The platform already does exactly this for admin recipe runs (the "Stop" button). This spec
**mirrors that proven pattern** rather than inventing a new one.

### The existing pattern to mirror (recipe runs)
- `views/admin/stop_run.php` writes a dedicated kill flag directly to the run row
  (`rcr_kill_requested = TRUE`), and flips not-yet-started runs straight to cancelled.
- `RecipeRunContext::isKillRequested()` (`includes/RecipeRunContext.php:107`) re-reads that
  flag with a **fresh `SELECT`** — deliberately bypassing the worker's stale in-memory copy,
  because the flag was written by a different process.
- `RecipeRunContext::shouldContinue()` (`:90`) returns
  `['stop_reason' => 'cancelled', 'detail' => ...]` when the flag is set.
- `AgentLoop::run()` (`includes/AgentLoop.php:118`) calls `shouldContinue()` at the top of
  every iteration and `break`s on a non-null result.

The chat turn already runs through the **same** `AgentLoop`, so the loop-boundary check comes
for free once the chat's context object learns to re-read a chat kill flag.

---

## 3. Current chat turn lifecycle (reference)

1. **Send** — `includes/chat_view_body.php:772` `send()`, POSTs to `chat_send`
   (`:842`), optimistically echoes the user bubble, calls `setBusy(true)` (`:810`).
2. **Send endpoint** — `views/profile/chat_send.php` → `views/admin/chat_send.php`; authorizes
   (login + conversation owner-scope), persists the user message, creates the **assistant
   placeholder row** with `aim_status = STATUS_RUNNING` (`views/admin/chat_send.php:125`).
3. **Detach + run** — if php-fpm can detach (`ChatAsync::canDetach()`), it echoes
   `{status:'running', message_id, conversation_id}`, calls `ChatAsync::detach()`
   (`fastcgi_finish_request` + `session_write_close` + `ignore_user_abort(true)`,
   `includes/ChatAsync.php:35`), then runs `ChatTurn::runAndFinalize()` in the same process.
   The `/api/v1` chat surface uses the CLI worker (`cli/run_chat_turn.php`) instead, which
   calls the **same** `ChatTurn::runAndFinalize()`.
4. **Run** — `ChatTurn::runAndFinalize()` (`includes/ChatTurn.php:23`) → `ChatRunner::runTurn()`
   (`includes/ChatRunner.php:35`) → `ChatRunner::drive()` (`:162`) → `AgentLoop::run()`
   (`includes/AgentLoop.php:86`). On success writes `STATUS_COMPLETE` (`ChatTurn.php:62`).
   The provider streams (SSE): `OpenAiCompatibleProvider::consumeStream()` read loop at
   `includes/llm/OpenAiCompatibleProvider.php:242`, firing a text delta per chunk (`:273`).
5. **Poll** — `pollMessage()` (`includes/chat_view_body.php:407`) fetches `chat_poll` every
   600 ms; endpoint `views/admin/chat_poll.php:60` branches on `aim_status`
   (complete → html, failed → error, else → partial text).
6. **Render** — `onComplete` → `replaceBubble()` (`:1337`, `setBusy(false)`), `onFailed` →
   `renderFailedBubble()` (`:1348`).

---

## 4. Design

### 4.1 Signal mechanism — a dedicated flag column
Add a boolean **`aim_cancel_requested`** to `data/ai_conversation_messages_class.php`
`$field_specifications` (default `false`), mirroring recipe's `rcr_kill_requested`. This is the
cross-process signal. Schema is auto-managed from the field spec (run "Sync with Filesystem" /
`update_database`); **no migration** — and no data migration is needed (platform is pre-launch).

Also add the terminal status constant **`STATUS_CANCELLED = 'cancelled'`**
(`ai_conversation_messages_class.php:33-35`). The `aim_status` column is `varchar(20)`, which
already holds `'cancelled'`.

> Rationale for a flag column over a status sentinel: the running turn keeps `aim_status =
> running` until it actually stops, so the flag is a clean "request pending" signal distinct
> from the "it has stopped" terminal state, exactly as recipes model it.

### 4.2 Cancel endpoint — `views/profile/chat_cancel.php` (+ admin twin)
Follow the existing profile JSON-POST convention (`chat_turn_action.php`, `chat_confirm.php`):
one-line `views/profile/chat_cancel.php` requiring `views/admin/chat_cancel.php`; reachable at
`JOAI_BASE + 'chat_cancel'`.

Behavior:
1. `header('Content-Type: application/json')`; local `_fail($msg)` helper.
2. Auth: `SessionControl::is_logged_in()`; `$uid = get_user_id()`. (No CSRF token — consistent
   with the sibling chat endpoints, which authorize by session + owner-scope only.)
3. Input: `message_id` via `LibraryFunctions::fetch_variable_local($_POST, ...)`.
4. Load + owner-check through the parent conversation (canonical pattern,
   `chat_turn_action.php:40-50`): load `AiConversationMessage`, verify not deleted, load its
   `AiConversation`, verify `aic_owner_user_id === $uid` and not deleted.
5. Verify `aim_status === STATUS_RUNNING` (a completed/failed/cancelled turn is a benign no-op
   → return `{success:true, already_settled:true}`).
6. Mutate with the targeted static writer (never `save()` — rows may be sealed):
   `AiConversationMessage::updateColumns((int)$id, ['aim_cancel_requested' => true])`.
7. Respond `{success:true}`.

### 4.3 Worker detection — thread the message id into the chat context
`ChatTurnContext` currently holds the conversation but **not** the assistant message id, and its
`shouldContinue()` (`includes/ChatTurnContext.php:142`) does no DB re-read. Changes:
- Thread the assistant `message_id` into `ChatTurnContext` (available as `$assistant_msg` in
  `ChatTurn::runAndFinalize()` / `ChatRunner::runTurn()`).
- Add `ChatTurnContext::isCancelRequested()` modeled on `RecipeRunContext::isKillRequested()`:
  a fresh `SELECT aim_cancel_requested FROM aim_... WHERE aim_message_id = ?`.
- Have `ChatTurnContext::shouldContinue()` return
  `['stop_reason' => 'cancelled', 'detail' => 'cancelled by user']` when set (checked before the
  wall-clock check), so `AgentLoop` picks it up at the next iteration boundary
  (`AgentLoop.php:118`) with **no change to AgentLoop itself**.

### 4.4 Prompt-time responsiveness — in-stream abort (in scope)
The loop-boundary check (§4.3) only fires **between** LLM calls / tool steps. A common chat turn
is a *single long generation*, so a boundary-only cancel would not interrupt until that
generation finished — which is precisely when a user wants out. Therefore in-stream abort is a
**core requirement**, not optional:

- Piggyback a **throttled** cancel re-read on the streaming callback. The provider fires a text
  delta per chunk into `ChatTurnContext::emitText()` (`includes/ChatTurnContext.php:183`). Add a
  throttle there (re-check at most every ~500 ms / N chunks to avoid a `SELECT` per token); when
  cancel is set, signal the provider to stop.
- The provider's SSE read loop (`OpenAiCompatibleProvider::consumeStream()`,
  `OpenAiCompatibleProvider.php:242`) must honor that signal — break the `while (!feof($res))`
  loop and let the existing `finally` (`:294`) `fclose($res)` close the upstream connection
  (this also frees the Ollama/model runner promptly). The `AnthropicProvider` streaming path
  needs the same hook.
- Mechanism: pass a `shouldAbort` callable into the provider's `createMessageStreamed()`, or have
  `emitText()` throw a dedicated `TurnCancelled` exception that `ChatRunner`/`ChatTurn` catch and
  route to the cancelled finalization. Prefer the callable — throwing through the stream parser
  is harder to reason about. Decide at implementation, but the in-stream check must exist.

### 4.5 Finalization — write the cancelled terminal state
`ChatTurn::runAndFinalize()` (`includes/ChatTurn.php:34`) unconditionally writes
`STATUS_COMPLETE` (`:62`). Add a branch: when `AgentLoop` returns (or the stream aborts with)
`stop_reason === 'cancelled'`, write **`STATUS_CANCELLED`** instead, **persisting whatever partial
answer was already streamed** (so the user keeps the half-answer), and clear
`aim_cancel_requested`. Model the terminal write on `ChatTurn::markFailed()` (`:156`).
`ChatRunner::stopReasonNote()` (`includes/ChatRunner.php:143`) needs a `'cancelled'` case.
Because both the fpm-detach path and the CLI worker call `runAndFinalize()`, this covers the web
page and the `/api/v1` surface at once.

### 4.6 Poll — surface the cancelled state
`views/admin/chat_poll.php:60-91` branches on `aim_status`; add a `cancelled` branch alongside
complete/failed that returns `{status:'cancelled', assistant_html, conversation_usage}` (with
the partial answer rendered) so the page can settle.

### 4.7 Frontend — the button + terminal handling (`includes/chat_view_body.php`)
- **Mount:** a Cancel control in the composer, shown only while a turn is in flight. `setBusy()`
  (`:668`) is the single choke point that knows a turn is running — extend it to show/hide the
  Cancel button next to the thinking indicator (`:227`) / Send button (`:250`).
- **In-flight id:** capture the running assistant `message_id` (available at
  `streamInto(data.message_id)`, `:875`, and the confirm path `:1329`) into a module-scope var
  like the existing `lastTurn` (`:762`) so the Cancel handler can post it.
- **Handler:** POST `{message_id}` to `JOAI_BASE + 'chat_cancel'`. On success, leave the poll
  running — the worker will flip the row to `cancelled` and the poll renders it. (Do not tear
  down the bubble optimistically; let the terminal poll settle it, so a race where the turn
  completes first still renders correctly.)
- **Terminal state:** in `pollMessage`'s `tick()` add a branch after the `failed` check
  (`:424`): `if (data.status === 'cancelled') → onCancelled`. Thread an `onCancelled` callback
  through `streamInto` (`:467-483`) that renders the kept partial answer with a small
  "Cancelled" marker and calls `setBusy(false)`.
- **Button disabling:** disable the Cancel button once clicked (prevent double-post) until the
  poll settles.

---

## 5. Edge cases & decisions

- **Cancel after completion** — endpoint no-ops when `aim_status !== running`; returns success.
- **Race: turn completes between click and flag write** — worker already wrote
  `STATUS_COMPLETE`; the flag is ignored; poll renders the complete answer. Harmless.
- **Turn awaiting tool confirmation** — a turn parked on a pending action is not `running` in the
  generating sense; Cancel targets `running` turns only. (Declining the action uses the existing
  confirm card.) Out of scope; document the distinction in the UI copy.
- **Partial answer** — kept and shown, marked cancelled. Preferred over discarding for UX.
- **Usage/tokens** — whatever was consumed up to the abort is counted if the provider reported it
  in-stream; do not fabricate. Minor; note in docs.
- **Sealed-vault messages** — all status writes go through `updateColumns()` (targeted UPDATE),
  never `save()`, so sealed content is never re-encrypted incorrectly.
- **Auth** — session + conversation-owner scope only, consistent with sibling endpoints; no CSRF
  token (matches the existing chat `fetch`es).

---

## 6. Integration points (implementation checklist)

| # | File | Change |
|---|------|--------|
| 1 | `data/ai_conversation_messages_class.php` | Add `STATUS_CANCELLED` const; add `aim_cancel_requested` bool field spec |
| 2 | `views/profile/chat_cancel.php` (+ `views/admin/chat_cancel.php`) | New JSON POST endpoint (§4.2) |
| 3 | `includes/ChatTurnContext.php` | Accept message id; add `isCancelRequested()`; `shouldContinue()` returns cancelled stop_reason (§4.3); throttled in-stream check in `emitText()` (§4.4) |
| 4 | `includes/ChatRunner.php` / `includes/ChatTurn.php` | Pass message id into context; cancelled branch in finalization writing `STATUS_CANCELLED` + partial content; `stopReasonNote()` cancelled case (§4.5) |
| 5 | `includes/llm/OpenAiCompatibleProvider.php` + `includes/llm/AnthropicProvider.php` | Honor a `shouldAbort` signal in the SSE read loop; close stream on abort (§4.4) |
| 6 | `views/admin/chat_poll.php` | `cancelled` branch (§4.6) |
| 7 | `includes/chat_view_body.php` | Cancel button in `setBusy()`; capture in-flight id; handler POST; `onCancelled` poll branch + render (§4.7) |

No new route in `serve.php` (profile view auto-discovery covers `chat_cancel`). No AgentLoop
change (the shared `shouldContinue()` boundary already exists).

---

## 7. Documentation

Update the joinery_ai chat/async-turn documentation (the plugin's `docs/` overview covering the
send → worker → poll lifecycle) to describe the cancel path as part of the current design:
the `aim_cancel_requested` signal, the `STATUS_CANCELLED` terminal state, and the cooperative
re-read at the AgentLoop boundary + in-stream. Write it as the end state (no "new"/"previously").

---

## 8. Acceptance criteria

1. While the assistant is generating, a Cancel control is visible in the composer; it is hidden
   at rest and after the turn settles.
2. Clicking Cancel during a **long single generation** stops it within ~1 s (in-stream abort),
   closes the upstream model connection, and unlocks the composer.
3. Clicking Cancel during a **multi-step (tool-using) turn** stops it at the next step boundary
   without running further tools.
4. The cancelled turn is persisted as `STATUS_CANCELLED` with any partial answer kept and shown
   with a "Cancelled" marker; the poll settles to the cancelled state.
5. Cancel after the turn already finished is a harmless no-op (complete answer still shows).
6. Works on both the member page (fpm-detach path) and the `/api/v1` chat surface (CLI worker).
7. Owner scoping: a user cannot cancel another user's turn (endpoint returns "not found").
8. No regression to the existing tool-action confirm/decline card.

### Test plan
- New harness test (`plugins/joinery_ai/tests/`) with `@joinery-test` header: send a turn against
  a slow/stub provider, POST `chat_cancel`, assert the row reaches `STATUS_CANCELLED`, partial
  content preserved, and `aim_cancel_requested` cleared.
- Owner-scope test: user B cannot cancel user A's message.
- Race test: cancel after completion → no-op, status stays `complete`.
- Manual browser pass on `dev.getjoinery.com` against the Mac Studio 35B (long generation) to
  confirm the ~1 s in-stream stop.
