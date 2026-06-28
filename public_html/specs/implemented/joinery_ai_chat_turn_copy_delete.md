# Joinery AI — Chat turn copy & delete (Tier 1 turn actions)

**Status:** Implemented
**Plugin:** `joinery_ai`
**Touches:** `ChatRender`, a new `chat_turn_action` endpoint, `chat.php`
(transcript JS), `joinery_ai.css`.
**Relates to:** `joinery_ai_chat_turn_actions.md` (the deferred Tier 2 cluster —
stop / regenerate / edit-and-resend).

## Goal

Per-message controls on each turn in the admin chat (`/admin/joinery_ai/chat`),
the message-level analog of the sidebar thread menu (pin / rename / delete).
Controls reveal on hover or keyboard focus of a turn and apply to both the user
and assistant bubbles.

Tier 1 is the two actions that need no model involvement:

- **Copy** — copies the turn's raw text to the clipboard. Assistant turns copy
  the markdown source (`aim_content`); user turns copy the typed text. Entirely
  client-side (`navigator.clipboard`, with an `execCommand` fallback) — no server
  round-trip.
- **Delete** — soft-deletes the exchange (sets `aim_delete_time`). Deleting a
  query (a user turn) also removes its answer — the assistant row immediately
  after it; deleting a standalone answer removes just that one. Confirmed through
  the system modal (`JoineryModal.confirm`, danger style), with wording that
  reflects which case applies; on success every deleted bubble is removed from
  the transcript (the endpoint returns the `deleted_ids`).

## Why single-turn delete is safe

`ChatRunner::buildHistoryMessages()` rebuilds the model transcript from
non-deleted rows and runs it through `normalizeAlternating()`, which drops
leading assistant turns and merges consecutive same-role turns. A gap left by a
deleted turn therefore never yields a non-alternating message array on the next
call. The alternation invariant lives in transcript assembly, so delete needs no
turn-pairing logic at the action layer.

## Surface

- `ChatRender::actionsHtml()` — shared copy/delete toolbar emitted by both
  `userBubble()` and `assistantBubble()`. Raw copy text rides on the bubble's
  `data-raw` attribute; the row id on `data-message-id`.
- `views/admin/chat_turn_action.php` — AJAX/JSON endpoint
  (`/admin/joinery_ai/chat_turn_action`, POST), action `delete`. Ownership is
  enforced through the parent conversation (`aic_owner_user_id === uid`,
  permission ≥ 5), mirroring `chat_thread_action.php`. For a user turn it also
  finds the immediately-following message and soft-deletes it when that turn is
  an assistant reply; the response carries `deleted_ids` so the client removes
  every affected bubble.
- `joai-chat-action*` styles in `joinery_ai.css`; copy/delete JS in `chat.php`,
  event-delegated on the transcript so it covers both load-rendered and
  swapped-in bubbles.

The optimistic user bubble drawn client-side on send carries no message id yet,
so it shows no toolbar until the page reloads with the persisted row — by design;
the send endpoint returns the assistant message id, not the user one.

## Out of scope

Stop, regenerate, and edit-and-resend — the model-rerun cluster — live in
`joinery_ai_chat_turn_actions.md` (deferred).
