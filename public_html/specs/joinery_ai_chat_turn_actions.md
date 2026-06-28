# Joinery AI — Chat turn actions (stop / regenerate / edit-and-resend)

**Status:** Active — awaiting implementation
**Plugin:** `joinery_ai`
**Touches:** `AiConversationMessage` (one column), `ChatTurnContext`, `AgentLoop`
(read one new halt signal), `ChatRunner`, the chat endpoints, the chat composer
+ transcript JS.
**Pairs with:** `joinery_ai_chat_streaming.md` (the stream callback is the hook
that makes Stop land mid-generation rather than only between steps).

## Goal

Give the operator the three controls every mature chat client has for a turn in
flight or a turn just finished: **halt** a running reply, **re-roll** the last
reply, and **edit your own message and run again from there**. Today a turn, once
sent, runs to completion with no handle on it, and there is no way to redo a reply
or fix a prompt short of typing a new message.

In plain terms: a Stop button while the assistant is working, a Regenerate action
on its last reply, and an Edit action on your own message that re-runs the
conversation from the edited point.

## Why these three are one spec

They are the same machinery, not three features. A chat turn already runs as an
**asynchronous lifecycle**: `chat_send` creates a user row plus a `running`
assistant placeholder, detaches the request (`fastcgi_finish_request`), runs one
`AgentLoop` turn, and finalizes the placeholder to `complete`/`failed` while the
page polls `chat_poll` (`ChatAsync`, `chat_send.php:117`). All three actions are
operations on that one lifecycle:

- **Stop** halts a row that is `running`.
- **Regenerate** discards the latest assistant row and re-runs the same turn.
- **Edit-and-resend** rewrites a user row, drops everything after it, and re-runs.

Regenerate and edit-resend are the *same* primitive — *truncate the transcript to
a boundary message, then spawn a fresh turn* — and both reuse the existing
`chat_run_and_finalize()` path verbatim. Stop is its inverse: a cooperative halt
of a turn already running. Decided once, the three share a truncation helper, a
halt signal, and the async finalize that already exists.

## Shared primitive: truncate-and-rerun

A helper (in `ChatAsync` or a small `ChatTurn` helper) that, given a **boundary
message** in a conversation:

1. Soft-deletes every message **after** the boundary (`aim_delete_time = now()`).
   `ChatRunner::buildHistoryMessages()` already filters `deleted = false`
   (`MultiAiConversationMessage`), so truncated rows vanish from the model's
   context with no other change.
2. Creates a fresh assistant placeholder row (`role = assistant`,
   `status = running`, empty content) — exactly as `chat_send` does
   (`chat_send.php:95`).
3. Runs `chat_run_and_finalize($conversation, $uid, $placeholder)` — the **same**
   detached-turn function `chat_send` uses, including the stream sink and token
   roll-up. No new turn logic.

Both Regenerate and Edit-and-resend are this primitive with a different boundary
and, for edit, a content rewrite first.

## Stop

### Signal

Add one column to `AiConversationMessage`:

```php
'aim_stop_requested' => array('type'=>'bool', 'default'=>false),
```

A new endpoint **`chat_stop`** (POST `message_id`) sets it on the caller's own
`running` assistant row (owner-scoped through the parent conversation, identical
to the `chat_poll` ownership check). It only flips the bool; the detached worker
does the rest.

### Cooperative halt — two checkpoints

The worker runs in the detached fpm process, so the browser can't signal it
directly; it reads the flag from the row. Two checkpoints make Stop responsive:

1. **Between steps** — `ChatTurnContext::shouldContinue()` already gates each loop
   iteration (`ChatTurnContext.php:89`) and `AgentLoop` already halts on its
   return. Extend it to re-read the row and return
   `['stop_reason' => 'stopped', ...]` when `aim_stop_requested` is set. (The
   context gains the placeholder's id so it can issue one cheap `SELECT` per
   iteration — the same row the stream sink already writes.) This catches a Stop
   pressed between tool calls / model calls.
2. **Mid-generation** — when streaming is active (`joinery_ai_chat_streaming.md`),
   the stream-delta sink also checks the flag and **aborts the stream read** so
   Stop lands during a long single completion, not only at the next step boundary.
   Without the streaming work this checkpoint is absent and Stop degrades
   gracefully to step-boundary granularity.

Be honest about granularity in the UI: Stop ends the turn at the next checkpoint;
it does not retroactively unspend tokens already generated.

### Finalize a stopped turn

`AgentLoop` returns with `stop_reason = 'stopped'` and whatever `assistant_text`
accumulated. `chat_run_and_finalize` already writes the resolved text and flips
the row to `complete`; the only addition is a `'stopped'` case in
`ChatRunner::stopReasonNote()` (`ChatRunner.php:128`) so a turn stopped before any
text reads e.g. `_(Stopped.)_` rather than blank. A stopped turn is a **complete**
row (the partial reply is kept), not a failed one. If the stop arrives after a
mutating action was already executed, that side effect stands — Stop halts the
model, it does not roll back work already done.

## Regenerate

**`chat_regenerate`** (POST `conversation_id`): the boundary is the user message
preceding the latest non-deleted assistant row. Run the shared primitive
(soft-delete that assistant row → fresh placeholder → finalize). Offered only on
the **latest** assistant turn — regenerating an earlier one would silently
discard everything after it, which is the Edit gesture, not Regenerate.

Already-executed actions are **not** undone: Regenerate re-runs the model from
your last message; if the prior turn ran a mutating tool, that change remains, and
any new mutating proposal still goes through the confirmation card. So there is no
silent double-execution — a re-proposed action is confirmed again. Disclose this
in the action's helptext ("re-runs from your last message; doesn't undo actions
already taken").

## Edit-and-resend

**`chat_edit`** (POST `message_id`, `content`): the message must be a `user` row
in the caller's own conversation. Rewrite `aim_content` to the edited text, then
run the shared primitive with that message as the boundary — soft-deleting the old
reply and **everything downstream**. The conversation continues linearly from the
edited message.

Linear truncation, not a branching tree: editing abandons the old tail rather than
keeping both branches. A conversation tree (keep-both, switch between versions) is
explicitly out of scope — it is a much larger data-model change for a feature
admins rarely need.

## Frontend (chat.php)

- **Stop:** while a turn is `running` (the existing busy state), show a Stop
  button beside/replacing Send. It POSTs `chat_stop` with the polled `message_id`;
  polling continues and the finalized (stopped→complete) bubble renders normally.
- **Regenerate:** an action on the latest assistant bubble. On click, remove that
  bubble from the DOM, show the thinking indicator, POST `chat_regenerate`, and
  poll the returned new `message_id` exactly like `send()` does.
- **Edit:** an action on a user bubble that swaps it for a textarea seeded with the
  current text (Save / Cancel). On Save, optimistically drop that bubble's tail
  from the DOM, POST `chat_edit`, and poll the new `message_id`. Reuses the
  existing `pollMessage` / `appendReply` flow.

All three reuse the poll-and-render plumbing already in `chat.php`; none add a new
client-side lifecycle.

## What does NOT change

- `AgentLoop`'s loop, the risk heuristic / confirmation boundary, token
  accounting, `CostGuard`, lazy discovery, and reader-mode fetch. The loop reads
  one new halt signal through the `shouldContinue()` hook it already calls.
- The async finalize path (`chat_run_and_finalize`) — reused as-is; truncation
  happens before it, never inside it.
- `ChatRender` and the transcript markup — a regenerated/edited turn renders
  through the same bubble path as any other.

## Security & cost

- **Owner-scoped, like every chat endpoint.** `chat_stop`, `chat_regenerate`, and
  `chat_edit` resolve the conversation and check `aic_owner_user_id === uid` and
  permission ≥ 5 before touching anything — a caller can only stop/redo/edit their
  own threads.
- **No new spend path.** Regenerate and edit run one ordinary turn each, already
  bounded by the per-turn token budget and the monthly `CostGuard` cap (both
  re-checked via `enforceGlobalCap()` as in `chat_send`). Stop can only *reduce*
  spend.
- **Truncation is soft-delete**, consistent with the rest of the platform —
  rows are hidden from context, not hard-removed, so an audit trail survives.

## Out of scope

- **Conversation branching / version tree** for edits (keep-both-and-switch) —
  linear truncation only.
- **Mid-call hard abort** of the provider HTTP request the instant Stop is pressed
  — Stop is cooperative at the two checkpoints above. (Aborting an in-flight
  Guzzle stream is the streaming spec's mechanism; a non-streaming single call
  still runs to its own timeout before the step-boundary check fires.)
- **Rolling back executed actions** on stop/regenerate — side effects already
  committed are not reversed.
- Rename / delete / pin / search / export and copy/highlight — the conversation-
  management + render-polish cluster, specced separately.

## Implementation outline

1. Add `aim_stop_requested` (bool, default false) to `AiConversationMessage`; run
   plugin sync.
2. Shared `truncateAndRerun($conversation, $uid, $boundary_msg)` helper:
   soft-delete the tail, create the placeholder, call `chat_run_and_finalize`.
3. `ChatTurnContext`: accept the placeholder id; `shouldContinue()` re-reads
   `aim_stop_requested` and returns a `'stopped'` halt when set. Stream sink
   checks the same flag and aborts the read (guarded so it's a no-op without the
   streaming work).
4. `ChatRunner::stopReasonNote()`: add a `'stopped'` case.
5. Endpoints `chat_stop`, `chat_regenerate`, `chat_edit` — owner-scoped, mirroring
   `chat_send` / `chat_poll` auth; regenerate/edit call the shared helper, stop
   flips the flag.
6. `chat.php`: Stop button in the busy state; Regenerate on the latest assistant
   bubble; Edit on user bubbles (textarea swap) — all reusing `pollMessage`.
7. `php -l` + `validate_php_file.php` on every modified PHP file; bump the plugin
   version in `plugin.json`.

## Docs

On implementation, update `plugins/joinery_ai/docs/overview.md` (current-state
voice): in the "Asynchronous turns" section, document Stop (the
`aim_stop_requested` signal and the two cooperative checkpoints), Regenerate, and
Edit-and-resend (the truncate-and-rerun primitive and its linear semantics), and
note that a stopped turn finalizes as a complete row keeping its partial reply.
