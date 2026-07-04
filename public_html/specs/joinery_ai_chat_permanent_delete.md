# Joinery AI — Permanent delete for conversations (and their uploaded files)

**Status:** Draft — not yet implemented.
**Plugin:** `joinery_ai`
**Depends on:** `specs/implemented/joinery_ai_chat_management.md` (the existing
soft-delete `delete` action), `specs/implemented/joinery_ai_chat_turn_copy_delete.md`
(per-turn soft-delete), `specs/joinery_ai_file_uploads.md` (the attachment `File`
rows this is really about reclaiming).

## Goal

Give "delete" a real endpoint: a way for a conversation (and everything under
it — messages, attachment links, and the uploaded `File` bytes they point at)
to actually be removed, not just hidden from the thread list.

## In plain terms

Today, clicking "Delete" on a conversation says *"This cannot be undone"* — but
under the hood it only hides the thread. The conversation row, every message in
it, and any files you uploaded into it all stay in the database and in storage
forever, invisibly. This spec is about closing that gap: deciding when and how
a deleted conversation's data — especially uploaded files, which are the part
that actually costs storage — gets truly removed.

## Current state (verified in code)

- The conversation `delete` action (`chat_thread_action_logic.php`) calls
  `AiConversation::soft_delete()` — the generic one-column
  `SET aic_delete_time = now()` every model gets from `SystemBase`. Nothing
  else happens.
- That does **not** cascade: `aim_conversation_messages` rows keep
  `aim_delete_time IS NULL`, `aia_message_attachments` rows are untouched, and
  the `File` rows they reference (bytes on disk/cloud) are untouched. The
  conversation just drops out of `MultiAiConversation`'s `deleted = false`
  filter.
- The per-turn `delete` action (`chat_turn_action_logic.php`) is the same
  shape at the message level: it soft-deletes one user+assistant pair via
  `AiConversationMessage::soft_delete()`, no cascade to that message's
  attachments either.
- The real cleanup logic already exists and is already correct:
  `AiConversationMessage::permanent_delete()` walks its attachment links and
  calls `AiMessageAttachment::permanent_delete()`, which deletes the
  underlying `File` (bytes and all) before removing the link row.
  `AiConversation`'s `aim_aic_conversation_id` deletion rule is
  `action => permanent_delete`, so permanently deleting a conversation would
  correctly recurse through every message → every attachment → every `File`.
- **Nothing calls it.** There is no admin purge page, no retention job, no
  cron task, and no other code path anywhere that calls `permanent_delete()`
  on an `AiConversation` or `AiConversationMessage`. The cascade is fully
  built and unreachable.

So today: soft-deleting a chat hides it: it does not free any storage, and
there is currently no way — UI or scheduled — to ever free it.

## What's already correct (reused, not rebuilt)

- The `permanent_delete()` cascade chain (conversation → message → attachment
  → `File`) — verified correct against the deletion system's rules.
- `File`'s own cleanup (on-disk/cloud byte removal) inside its
  `permanent_delete()`.
- Ownership checks on every existing chat action
  (`aic_owner_user_id === session user`, permission ≥ 5) — whatever triggers
  a permanent delete should be gated the same way.

None of that needs to change. This spec is only about **what decides to call
`permanent_delete()`, and when.**

## Decisions to resolve

### A. What triggers a permanent delete?

- **A1 — Immediate hard delete.** Change the existing `delete` action to call
  `permanent_delete()` directly instead of `soft_delete()`. Simplest; matches
  the UI copy's existing "cannot be undone" promise exactly. No undo window,
  no trash view.
- **A2 — Two-stage: soft-delete now, purge later.** Keep today's `delete` as
  a "move to trash" gesture (recoverable via the existing `undelete()`, which
  no UI currently exposes), and add a scheduled task
  (`docs/scheduled_tasks.md` pattern: a `ScheduledTask` class + JSON config,
  cron-driven) that permanently deletes any conversation whose
  `aic_delete_time` is older than a retention window.
- **A3 — Manual admin purge.** An admin-side "Deleted conversations" list
  with a permanent-delete button per row (or an "empty trash" bulk action),
  no automatic timer.

A2 is the shape the rest of the platform already leans toward (soft-delete +
retention is the established pattern for logs/audit-style data elsewhere), and
it's the only option that gives a genuine grace period before data is
unrecoverable — worth weighing against A1's simplicity given the UI already
tells users deletion is final.

### B. Retention window (if A2)

How long a soft-deleted conversation survives before the purge task reclaims
it. Needs a plugin setting (e.g. `joinery_ai_chat_delete_retention_days`) with
a sane default — tunable, not hardcoded.

### C. Scope — conversations only, or per-turn deletes too?

The per-turn `delete` action (`joinery_ai_chat_turn_copy_delete.md`) has the
identical gap: a soft-deleted message's attachment links and `File`s are
never reclaimed either, and there's no parent-conversation-delete event to
ride on since the conversation itself may still be very much alive. Decide
whether the same purge mechanism (A2) also sweeps individually-soft-deleted
messages inside otherwise-live conversations, or whether this spec covers
conversation-level deletes only and per-turn cleanup is a follow-on.

### D. UX

- If A2 is chosen: does anything need to change in the UI (a "recently
  deleted" view, an explicit "delete forever" action), or does the trash
  period stay invisible to the user (delete still reads as final, it just
  isn't instantaneous under the hood)?
- If A1 is chosen: no UX change — the existing confirmation copy is already
  accurate.

### E. Where the purge job lives (if A2)

A `ScheduledTask` (`docs/scheduled_tasks.md`) is the idiomatic fit: a
`ConversationPurge.php` + `.json` pair, `default_frequency: daily`, querying
`aic_conversations WHERE aic_delete_time IS NOT NULL AND aic_delete_time < now() - retention`
and calling `permanent_delete()` on each match (which already does the right
thing all the way down).

## Security / correctness notes

- Whatever triggers the permanent delete must be gated exactly like the
  existing soft-delete action (owner match + permission ≥ 5) if it's a
  user-facing action (A1/A3); a scheduled task (A2) runs with system
  authority and doesn't need per-row auth, but should still only ever match
  already-soft-deleted rows.
- No new invariant to design — `permanent_delete()`'s cascade is already
  verified correct (this was checked against `del_deletion_rules` and the
  manual attachment-cascade override in `AiConversationMessage`).

## Out of scope

- Any change to `File`'s own deletion mechanics.
- Recipe-attached files (`specs/joinery_ai_file_uploads.md` Decision C) — not
  built yet, so nothing to purge there.
- A generic "trash" system for other plugins/models — this spec is
  joinery_ai-chat-specific.

## Docs

On implementation, update `plugins/joinery_ai/docs/overview.md` and
`specs/implemented/joinery_ai_chat_management.md`'s delete-action note (which
currently says permanent removal "stays with the platform deletion system" —
make that concrete once it has a real trigger).
