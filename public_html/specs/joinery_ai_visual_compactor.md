# Joinery AI — Visual context compactor

**Status:** Active — awaiting implementation
**Plugin:** `joinery_ai`
**Touches:** `AiConversationMessage` (two columns) + a new `aix_context_actions`
table, the chat transcript builder (one filtering rule), the chat left/transcript
JS and CSS, one compaction endpoint, and the existing `chat_turn_action` delete
case (logs into the unified undo). A new `ChatCompactor` include holds the
summarize call.

## Goal

A long chat silently bloats its own context — every old turn, every dead tangent,
every reference you've moved past still rides along and costs tokens on every
send. Auto-compaction fixes the size but takes the wheel: you don't choose what
survives, and you can't see it happen.

This spec gives the user a **visible, manual** version. A label in the chat shows
how big the context currently is. Click it and the conversation breaks into
boxes; X out the ones you don't need; commit, and everything left is folded into
one compact summary that carries forward. The boxes you dropped grey out in
place. One Undo walks any of it back.

In plain terms: see your context size, hand-pick what to forget, and compact on
your terms — reversibly.

## Why a label + boxes (the mental model)

The whole feature hangs off one idea: **a message is either in context or it
isn't.** "In context" means it's sent to the model on the next turn and counts
toward the size label; "out of context" means it stays visible in the transcript,
greyed, but is skipped at send time and costs nothing.

Everything else is gestures over that one bit:

- **The size label** = the token total of the in-context messages.
- **An X** = stage a box to drop (set it out of context).
- **Compact** = drop the staged boxes *and* replace the remaining in-context
  history with a single summary that is itself in context.
- **Undo** = flip the bit back.

## Schema

Two columns on `AiConversationMessage` (`aim_conversation_messages`):

```php
'aim_in_context' => array('type'=>'bool', 'is_nullable'=>false, 'default'=>true),
'aim_is_summary' => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
```

- `aim_in_context` — the one bit above. Default true, so every existing and new
  message is in context until something compacts it out.
- `aim_is_summary` — marks a message as a generated recap (so the transcript
  renders it as a distinct block and the builder handles it specially, below).

No other content columns change. Soft-delete (`aim_delete_time`) and token counts
(`aim_input_tokens` / `aim_output_tokens`) already exist and are reused as-is.

### The undo log

A small table, `aix_context_actions`, records every reversible context change so
**one** Undo can reverse **any** of them without special-casing each in the UI:

```php
'aix_action_id'           => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
'aix_aic_conversation_id' => array('type'=>'int8', 'required'=>true),
'aix_owner_user_id'       => array('type'=>'int8', 'required'=>true),
'aix_type'                => array('type'=>'varchar(20)', 'required'=>true), // delete_turn | compact
'aix_affected_message_ids'=> array('type'=>'jsonb', 'required'=>true),       // rows whose flag flipped
'aix_summary_message_id'  => array('type'=>'int8'),                          // the recap, for compact
'aix_undone'              => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
'aix_create_time'         => array('type'=>'timestamp(6)', 'default'=>'now()'),
```

An action is reversible because it is **recorded as reversible** — Undo reads the
latest non-undone row for the conversation, applies its inverse, and marks it
`aix_undone = true`. The UI never branches on action type; the record carries
what's needed to reverse it.

## Operations

### Size label

Computed from the stored per-message token counts:
`SUM(aim_input_tokens + aim_output_tokens)` over the conversation's in-context,
non-deleted messages. Rendered as a compact glanceable figure (e.g. "18.2k in
context"). Clicking it enters compaction mode; it is the only entry point.

The label measures **history** size, not total-wire size — the system prompt
`buildSystemPrompt` assembles each turn (date, tool rules, model catalog,
untrusted block) also goes on the wire but isn't message history, so the figure
is "how heavy is this conversation," not the provider's exact input count.

### Compaction mode (staging)

Entering the mode turns each exchange into a box with an **X**. The X is a
**client-side staging toggle** — nothing persists until commit, so the user can X
and un-X freely. A **Compact** button commits; a **Cancel** button leaves the
mode with no change. Boxes already out of context from a previous compaction show
greyed and are not re-stageable here (Undo is their path back).

### Compact (commit) — `chat_context`, POST `action=compact`

One atomic operation, given the staged `excluded_ids`:

1. Resolve the conversation's currently in-context, non-deleted messages.
2. `excluded` = the staged ids → set `aim_in_context = false`. **Dropped, not
   summarized** — this is the junk the user explicitly doesn't want spent on.
3. `to_summarize` = in-context minus excluded → generate one faithful recap of
   their content (see `ChatCompactor`), write it as a new message with
   `aim_is_summary = true`, `aim_in_context = true`, then set
   `aim_in_context = false` on the `to_summarize` rows.
4. Record one `aix_context_actions` row: `type = compact`,
   `affected_message_ids = excluded ∪ to_summarize` (everything that flipped to
   out-of-context), `summary_message_id` = the recap's id.

After commit the transcript shows the dropped and summarized turns greyed in
place, with the recap as a distinct in-context block where the live conversation
resumes. The next compaction naturally includes the previous recap in its
`to_summarize` set (a summary of a summary), which is expected.

This is **fire-and-forget**: no review-the-summary step. The safety net is Undo,
not a preview.

### Undo — `chat_context`, POST `action=undo`

Reverses the latest non-undone action for the conversation:

- `compact` → set `aim_in_context = true` on `affected_message_ids` and
  soft-delete the `summary_message_id` recap. Prior state restored exactly.
- `delete_turn` → clear `aim_delete_time` on `affected_message_ids`.

Then mark the action `aix_undone = true`. Undo makes **no** model call and costs
nothing.

### Unifying delete into the same Undo

The existing per-exchange delete (`chat_turn_action`, `action=delete`) already
soft-deletes a turn and its paired reply. It gains one line: write an
`aix_context_actions` row (`type = delete_turn`, `affected_message_ids` = the
deleted ids). Nothing else about delete changes — but now the same Undo affordance
reverses a delete and a compaction identically.

## Sending: two localized touches

There is exactly one place stored rows become the model's message array —
`ChatRunner::buildHistoryMessages()` — and the system prompt is assembled in a
separate `ChatRunner::buildSystemPrompt()`. Both already receive `$conversation`,
so no new plumbing is needed. The feature touches each once:

- **`buildHistoryMessages`** — its `MultiAiConversationMessage` query (today
  `['conversation_id'=>…, 'deleted'=>false]`) gains `in_context => true`, so
  out-of-context rows never reach the model. It also skips `aim_is_summary = true`
  rows, so a recap doesn't double as an ordinary turn. (The existing
  `normalizeAlternating()` still guards the user/assistant invariants afterward.)
- **`buildSystemPrompt`** — append one more labeled section built from the
  conversation's `in_context = true AND is_summary = true` rows (a recap of
  earlier conversation), so summaries ride in the system preamble and never
  disturb alternation.

Note (2026-07-16): since this spec was written, the durable-memory feature
(`specs/implemented/joinery_ai_memory.md`) also injects into the system prompt
via `ChatMemory` (memory bodies + titles). The compaction-recap section is a
*third* labeled preamble section alongside it — coordinate ordering and labels
in `buildSystemPrompt` so the model can distinguish "recap of this
conversation" from "durable memories about the user." The two mechanisms are
otherwise independent.

The agent loop, providers, confirmation boundary, token accounting, and the
model-control knobs are otherwise untouched.

## ChatCompactor (summarize helper)

A new `includes/ChatCompactor.php`, mirroring the thin-helper shape of
`ChatExport`. It takes the `to_summarize` messages, builds a summarize prompt
(instruct: preserve decisions, facts, names, open threads; drop pleasantries and
restated context; be compact), calls the conversation's **active provider/model**
(the same one the chat uses — no separate model choice), and returns the recap
text plus token usage. The endpoint stores that usage on the recap message, so
the one call a compaction costs is metered like any other turn.

## Endpoints (dual-surface)

Following the established admin-full-file + profile-stub pattern:

- `views/admin/chat_context.php` (+ `views/profile/chat_context.php` stub) —
  owner-scoped (`aic_owner_user_id === uid`, permission ≥ 5):
  - **GET** → the context state: each box `{id, role, is_summary, in_context,
    tokens, preview}` plus `total_in_context_tokens`, so the pane can refresh the
    label and boxes after any operation.
  - **POST `action=compact`** (`excluded_ids[]`) → runs the commit above,
    returns refreshed state.
  - **POST `action=undo`** → reverses the latest action, returns refreshed state.
- `views/admin/chat_turn_action.php` — extend the `delete` case to log an action
  (above). No new file.

## Frontend (chat transcript)

- **Size label** in the chat surface, always visible, clickable to enter
  compaction mode. Vanilla HTML5/CSS/JS (`joai-` classes), no framework.
- **Compaction mode:** per-box X (client-side staging), a **Compact** commit
  button, a **Cancel**. Out-of-context boxes greyed and non-interactive.
- **Recap blocks** rendered as a distinct, labeled in-context box ("Summary of N
  earlier messages").
- **Unified Undo affordance:** every context-changing action drops the **same**
  snackbar — "Exchange deleted — Undo", "Compacted — Undo" — in the same spot.
  Undo calls `chat_context` `action=undo`. One control, one mental model, both
  actions.
- Guided controls only — no explainer prose on the page (docs live in `/docs`).

## What does NOT change

- The turn lifecycle, `AgentLoop`, providers, `ChatRunner`, the confirmation
  boundary, token accounting, model-control knobs — untouched beyond the single
  in-context/summary filtering rule in the transcript builder.
- Message content, soft-delete, and token columns — reused, not altered.

## Security & cost

- **Owner-scoped everywhere.** `chat_context` (both verbs) and the
  `chat_turn_action` delete-logging resolve the conversation and enforce
  `aic_owner_user_id === uid` + permission ≥ 5 — a caller can only compact, undo,
  or delete within their own threads. The undo log is scoped by
  `aix_owner_user_id`.
- **One model call per compaction**, metered and stored on the recap message.
  Undo and the size label cost nothing (a `SUM` and flag flips).
- **No new client-trust surface** — `excluded_ids` are validated against the
  conversation's own message rows server-side before any flag flips.

## Objects — forward design rule (not built here)

The chat has no file/image upload today, so there are no standalone "objects" to
box yet. When uploads are added, **store each upload as its own row** (an
attachment referencing its message), never glued into message text. Give that row
the same `aim_in_context` bit. Then an uploaded reference image becomes its own
box you can X — dropping it at compaction without paying to summarize it — for
free, with no change to this feature. Embedding uploads in content would force a
later extraction; the rule exists to avoid that.

## Out of scope

- **Object boxes** — until uploads exist as rows (see the design rule above).
- **Per-box manual restore** of an arbitrary historical greyed box — v1 reversal
  is Undo (last action). The log already supports multi-step undo; exposing more
  than single-step, and arbitrary per-box re-include, are deferred.
- **Auto-compaction / token-threshold prompts** — this feature is manual only.
- **Redo** — single-direction undo for v1.
- **Editing or regenerating the recap** — fire-and-forget; Undo and re-compact
  are the recourse.

## Implementation outline

1. `AiConversationMessage`: add `aim_in_context`, `aim_is_summary`. New
   `AiContextAction` / `MultiAiContextAction` data class for `aix_context_actions`.
   Sync schema (Sync with Filesystem / `update_database`).
2. `ChatRunner::buildHistoryMessages` — add `in_context => true` to its query and
   skip `is_summary` rows; `ChatRunner::buildSystemPrompt` — append a recap
   section from in-context summary rows.
3. `includes/ChatCompactor.php`: summarize `to_summarize` via the active
   provider; return recap text + usage.
4. Endpoint `chat_context` (GET state, POST compact, POST undo) — owner-scoped;
   `views/profile/chat_context.php` stub.
5. Extend `chat_turn_action` delete to log an `aix_context_actions` row.
6. Frontend: size label + compaction mode (X staging, Compact, Cancel), recap
   block rendering, unified "— Undo" snackbar wired to `chat_context`; CSS.
7. `php -l` + `validate_php_file.php` on every modified PHP file; bump the plugin
   version in `plugin.json`.

## Docs

On implementation, update `plugins/joinery_ai/docs/overview.md` (current-state
voice): a "Compacting context" section covering the size label, compaction mode
(X to drop, Compact to summarize the rest), the in-context bit and how the
builder honors it, and the unified Undo over the action log; and note the
objects-as-rows rule alongside any future uploads work.
