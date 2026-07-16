# Joinery AI — Chat turn actions (regenerate / edit-and-resend)

**Status:** Deferred — Tier 2. The lightweight per-turn actions (copy / delete)
shipped first (`joinery_ai_chat_turn_copy_delete.md`), and **Stop shipped as
Cancel** (`specs/implemented/chat_cancel_inflight_prompt.md`): endpoint
`chat_cancel`, flag `aim_cancel_requested`, read cooperatively in
`ChatTurnContext::shouldContinue()` and per stream chunk via `shouldAbort()`;
a cancelled turn finalizes as **CANCELLED keeping the partial answer**. This
spec covers the two remaining actions — regenerate / edit-and-resend — and is
on hold until that work is requested.
**Plugin:** `joinery_ai`
**Touches:** `ChatRunner`, the chat endpoints, the chat composer + transcript JS.

## Goal

Give the operator the remaining two controls every mature chat client has for a
turn just finished: **re-roll** the last reply, and **edit your own message and
run again from there**. Today there is no way to redo a reply or fix a prompt
short of typing a new message. (Halting a running reply already exists — the
Cancel button, see Status above.)

## Why these two are one spec

They are the same machinery, not two features. A chat turn already runs as an
**asynchronous lifecycle**: `chat_send` creates a user row plus a `running`
assistant placeholder, detaches the request (`fastcgi_finish_request`), runs one
`AgentLoop` turn, and finalizes the placeholder while the page polls `chat_poll`
(`ChatAsync`, `chat_send.php:117`). Both actions are operations on that one
lifecycle:

- **Regenerate** discards the latest assistant row and re-runs the same turn.
- **Edit-and-resend** rewrites a user row, drops everything after it, and re-runs.

They are the *same* primitive — *truncate the transcript to a boundary message,
then spawn a fresh turn* — and both reuse the existing `chat_run_and_finalize()`
path verbatim. Decided once, the two share a truncation helper and the async
finalize that already exists. The shipped cancel machinery is untouched; a
running regenerated turn is cancellable exactly like any other turn.

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

- **Regenerate:** an action on the latest assistant bubble. On click, remove that
  bubble from the DOM, show the thinking indicator, POST `chat_regenerate`, and
  poll the returned new `message_id` exactly like `send()` does.
- **Edit:** an action on a user bubble that swaps it for a textarea seeded with the
  current text (Save / Cancel). On Save, optimistically drop that bubble's tail
  from the DOM, POST `chat_edit`, and poll the new `message_id`. Reuses the
  existing `pollMessage` / `appendReply` flow.

Both reuse the poll-and-render plumbing already in `chat.php` (including the
existing Cancel button on a running turn); neither adds a new client-side
lifecycle.

## What does NOT change

- `AgentLoop`'s loop, the risk heuristic / confirmation boundary, token
  accounting, `CostGuard`, lazy discovery, and reader-mode fetch. The loop reads
  one new halt signal through the `shouldContinue()` hook it already calls.
- The async finalize path (`chat_run_and_finalize`) — reused as-is; truncation
  happens before it, never inside it.
- `ChatRender` and the transcript markup — a regenerated/edited turn renders
  through the same bubble path as any other.

## Security & cost

- **Owner-scoped, like every chat endpoint.** `chat_regenerate` and `chat_edit`
  resolve the conversation and check `aic_owner_user_id === uid` and
  permission ≥ 5 before touching anything — a caller can only redo/edit their
  own threads.
- **No new spend path.** Regenerate and edit run one ordinary turn each, already
  bounded by the per-turn token budget and the monthly `CostGuard` cap (both
  re-checked via `enforceGlobalCap()` as in `chat_send`).
- **Truncation is soft-delete**, consistent with the rest of the platform —
  rows are hidden from context, not hard-removed, so an audit trail survives.

## Out of scope

- **Conversation branching / version tree** for edits (keep-both-and-switch) —
  linear truncation only.
- **Rolling back executed actions** on cancel/regenerate — side effects already
  committed are not reversed.
- Rename / delete / pin / search / export and copy/highlight — the conversation-
  management + render-polish cluster, specced separately.

## Implementation outline

1. Shared `truncateAndRerun($conversation, $uid, $boundary_msg)` helper:
   soft-delete the tail, create the placeholder, call `chat_run_and_finalize`.
2. Endpoints `chat_regenerate`, `chat_edit` — owner-scoped, mirroring
   `chat_send` / `chat_poll` auth; both call the shared helper.
3. `chat.php`: Regenerate on the latest assistant bubble; Edit on user bubbles
   (textarea swap) — both reusing `pollMessage`.
4. `php -l` + `validate_php_file.php` on every modified PHP file; bump the plugin
   version in `plugin.json`.

## Docs

On implementation, update `plugins/joinery_ai/docs/overview.md` (current-state
voice): in the "Asynchronous turns" section, document Regenerate and
Edit-and-resend (the truncate-and-rerun primitive and its linear semantics)
alongside the existing cancel documentation.
