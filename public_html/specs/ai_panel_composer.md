# AI Panel Composer — free-text asks from the area panel

**Status:** Draft — not built.
**Depends on:** `specs/implemented/ai_recipes_multi_mailbox_and_ai_panel.md` (the panel),
`specs/implemented/ai_action_queue.md` (the approval queue) — both built —
and `specs/ai_hot_turn_egress_approval.md` (hot-turn web egress approval,
required by exemplar 3; lands first).

## What this is

A text field in the area AI panel. The user types a one-off instruction and
the AI does the work as an ordinary chat turn, scoped to the area they are
looking at. Anything it wants to change lands in the existing approval
queue; nothing runs behind the user's back.

Three exemplar asks this spec must make work end to end:

1. *"Go through the emails from the last two days and add all the senders
   to my contact list"* — bulk read + many small writes (Parts 3 and 5).
2. *"Find all appointments mentioned in last week's mail and add them to my
   calendar"* — cross-area write: area context scopes attention, not
   permission, so reading mail and proposing calendar entries in one turn
   is normal (Part 4).
3. *"Investigate this specific email — follow the links and do a security
   analysis of what you find"* — message-level context (Part 2) plus web
   tools, under the hot-turn egress approval rule
   (`specs/ai_hot_turn_egress_approval.md`): every link the AI wants to
   follow after reading sealed content is a card the user approves.

This is Phase 3 of the panel spec, which deliberately shipped design-only.
The backend it needs (conversations, the streaming worker, the queue, the
panel drawer) all exists; this spec adds the entry point, the area context,
the first area-useful write action (contacts), and the bulk-approval
ergonomics that a request like the example above makes necessary.

## Design principles

1. **No third door.** The composer creates a normal chat conversation with a
   `ChatTurnContext`. Every mutating tool call queues as a pending action.
   This spec adds no new write path and no standing approvals.
2. **The click is the deliberate act.** `MailboxContact` is deliberate-entry
   only — mail traffic must never write the address book (the spam-harvest
   hole documented in `mailbox_contacts_class.php`). The AI reading a message
   and *proposing* a contact does not breach that: the row is only created
   when the owner approves a card showing the literal address. The invariant
   this spec preserves: **a contact row exists only because a human acted on
   that specific address** — typed it, imported it, or approved it.
3. **Sealed stays sealed.** Reading sealed mail makes the turn hot; proposals
   from a hot turn seal to the owner's vault or are refused. All existing
   queue behaviour; the composer inherits it unchanged.

## Part 1 — the composer UI (`ai_panel.js`)

A text input plus send button at the top of the drawer body, above the recipe
cards. Submitting:

- **First ask:** POSTs the existing `joinery_ai/chat_send` action with no
  `conversation_id` plus the new area-context fields (below). The reply
  streams into a mini-thread rendered inside the drawer via the existing
  `chat_poll` handle, exactly as the chat page does.
- **Follow-ups:** continue the same conversation while the drawer stays on
  that thread. A back control returns to the recipes/waiting view; the
  panel remembers the active conversation id per area for the page life
  only (no persistence — reopening the page starts fresh, and the full
  conversation is always in the member chat list).
- Pending-action cards raised by the turn appear in the existing "Waiting
  for you" section (the panel already refreshes it after activity) and
  inline in the mini-thread as the chat page renders them.
- A link on the mini-thread opens the full conversation on the member chat
  page for anything the drawer is too small for.

A small **Web access** toggle sits beside the composer, off by default,
scoped to the drawer's active conversation — it calls the existing
`chat_set_capabilities` action to flip `aic_web_search`. When an ask needs
the web while the toggle is off, the model tells the user to flip it (see
Part 2's prompt block) rather than failing silently.

No new rendering system: the mini-thread reuses the transcript markup and
CSS classes the shared chat body already defines, at drawer width.

## Part 2 — area-scoped conversations

`chat_send` (new-conversation path only) accepts two optional fields:

- `area` — same enum as `ai_panel_state` (`mailbox` for now).
- `mailbox` — the open mailbox address, as the reader host surface
  (`currentAddress()`) reports it.
- `message_id` — optional; the message currently open in the reader, so
  "this email" means the thing on screen. The reader host surface gains a
  `currentMessageId()` accessor beside `currentAddress()`, and the panel
  passes it through. Server-side the id must resolve to a message in a
  mailbox the user holds, or it is dropped (same posture as the address
  check).

Stored on the conversation as new columns:

- `aic_area` varchar(40), default `''` — empty for ordinary chats.
- `aic_context` text, default NULL — small JSON bag of area context
  (`{"mailbox": "me@example.com", "message_id": 123}`). Not sealed: it
  names a mailbox and a row id the same way `rcp_config` does; it contains
  no message content.

Behaviour:

- The server validates the address against the user's grants (same check
  `AiPanelService` performs) and drops it if not held — the model never
  sees an address the user cannot open.
- `AiPromptBuilder` appends an area block to the system prompt when
  `aic_area` is set: which area the user is in, which mailbox is open,
  which message is open (when the context carries one), and that work
  should default to that scope unless the user says otherwise. The block
  also tells the model: when an ask needs the web and web access is off,
  say so and point at the drawer's Web access toggle; and recurring bulk
  asks belong in recipes.
  Prompt-level scoping, not a security boundary — the real boundary is
  unchanged (owner scope on reads, the queue on writes).
- Composer conversations are created with `aic_data_access` on (the point
  of the composer is doing work) and otherwise take the same defaults and
  security-level resolution (`ChatLevel::resolveForNew`) as any new chat.
  They appear in the member chat list like any conversation.

## Part 3 — contacts become reachable by the AI

Generic model writes are the wrong tool here: `MailboxContacts` (the
service) owns normalization, the keyed blind-index hash, upsert-vs-insert,
and sealing — a raw `create_model` row would bypass all four. So:

- **Read surface:** `MailboxContact` declares `$ai_readable = true` with
  owner scope on `imc_usr_user_id`, so the model can check what is already
  in the address book before proposing duplicates. Sealed address/name
  columns decrypt in-window through the existing sealed-read path, like
  messages.
- **Write surface:** a new mailbox plugin API action `contact_add`
  (`plugins/mailbox/logic/contact_add_logic.php`) — input `mailbox`
  (address, validated against the caller's grants), `address`, and optional
  `display_name`; body delegates to the `MailboxContacts` service.
  Descriptor: `requires_session`, `mutates` true, `ai_agent` callable. It
  is a normal member API action; the AI reaches it through `invoke_action`,
  which is already queueable — each proposed add is one card showing the
  literal address and name.
- `MailboxContact` gains `SOURCE_APPROVED = 'approved'` and the service
  accepts it: rows created through an approved queued action are audit-
  distinguishable from typed (`manual`) and imported (`import`) rows while
  carrying the same deliberate-act status. Direct (non-AI) calls to
  `contact_add` record `manual` as today.
- `$ai_writable_fields` stays undeclared on `MailboxContact`, permanently.
  A comment on the class says why.

## Part 4 — calendar entries become proposable

Same pattern as contacts, for the same reason at a softer grade. Personal
calendar entries (`CalendarEntry`, already `$ai_readable`) store paired
local + UTC times with a tzdata version stamp, and one shared method —
`set_core_fields()` — owns that consistency for every existing write path
(form, importer). Raw AI field-writes could land a mismatched pair, so
calendar writes also go through an action, not `$ai_writable_fields`:

- New core API action `calendar_entry_add`
  (`logic/calendar_entry_add_logic.php`) — input `title`, `start_local`,
  `end_local`, `timezone` (defaults to the caller's session timezone),
  `all_day`, optional `reminder_minutes`. Body builds the entry through
  `set_core_fields()`, pins the subject to the acting user
  (`CalendarSubject::TYPE_USER`, own id — `authenticate_write` enforces
  this anyway for members; the action just never offers a choice), sets
  `cal_type` `personal` and `cal_source` `ai`. Descriptor:
  `requires_session`, `mutates` true, `ai_agent` callable.
- The AI reaches it through `invoke_action`, so each proposed entry is one
  card — literal title, date, start/end in the user's timezone — and the
  appointments-from-mail ask becomes N cards plus Approve all.
- `$ai_writable_fields` stays undeclared on `CalendarEntry`; a comment on
  the class says the action is the AI write path and why.
- Recurrence is out of scope for the action's first cut: an ask that needs
  a recurring entry gets a single-occurrence proposal and says so.

## Part 5 — bulk approval

The example ask can queue a dozen cards. Three additions to the panel's
"Waiting for you" section (chat page cards are unchanged — they resolve
one at a time where the conversation gives each one context):

- **Structural grouping.** Pending actions sharing an exact group key —
  tool, invoked action name, area, conversation — render as one card: a
  mechanical header (the shared action plus a count), then one row per
  item carrying that item's full literal facts, with a per-row decline.
  Grouping is presentation only, client-side, over the same per-item
  rows: nothing is judged "similar" (no fuzzy merging of near-duplicate
  proposals — two variant spellings stay two adjacent rows, where the
  eye catches them and one click drops one), no fact is summarized away,
  and every item keeps its own action id and per-item resolution.
- **Approve all** — on a group card, resolves that group's rendered rows;
  in the section header (shown when two or more pending actions are
  listed), resolves exactly the pending actions currently rendered — the
  ids the client is showing, never "whatever is pending by then", so
  nothing is approved sight-unseen. Confirmation states the count.
- **Decline all** — same scopes, same confirmation.

Server side: a new `ai_actions_resolve_batch` action — input `action_ids`
(list, capped at 50) and `resolution`; loops the existing
`ActionQueue::resolve()` per id, continues past per-item failures, returns
per-id outcomes plus the new pending count. No new resolution semantics:
each item still re-validates live at execute time, still writes its event
row, still reseals. The panel renders per-item results (approved / failed
and why) before collapsing the section.

Recurring bulk work ("do this every morning") stays the recipes' job — the
composer reply should say so when asked, via a line in the area prompt
block, not new machinery.

## Sealing and posture

Nothing new. The composer turn is a chat turn: sealed reads make it hot,
hot proposals seal to the owner or refuse, approval in-window reseals
results. The one addition, `aic_context`, is deliberately unsealed (scope
metadata, not content) — same standing as `rcp_config` bindings.

## Out of scope

- Any new standing-approval / recipe-style write door for the composer.
- Traffic-derived contact rows without per-address approval, in any form.
- Composer on areas other than `mailbox` (the plumbing is area-generic;
  each new area opts in by exposing context fields, as the panel spec set
  up).
- Persisting the drawer's active-conversation selection across page loads.

## Open decisions

- **D1 — resolved.** The batch buttons act on the rendered list (all the
  user's pending actions, as the panel lists them), with structural
  grouping (Part 5) keeping a large list scannable. Fuzzy near-duplicate
  merging was considered and rejected: judging two proposals "the same"
  is semantic work the literal-facts doctrine keeps off the approval
  surface.
- **D2 — resolved.** Data access on; web access off by default with the
  drawer's Web access toggle (Part 1), and the model directed to point at
  the toggle when an ask needs the web.
- **D3 — resolved.** The hot-turn fetch approval rule lives in its own
  spec, `specs/ai_hot_turn_egress_approval.md`, which this spec depends
  on for exemplar 3.

## Documentation (at build time)

- `plugins/joinery_ai/docs/overview.md` — composer subsection under the
  panel section; `aic_area`/`aic_context`; batch resolve action.
- `plugins/mailbox/docs/overview.md` — `contact_add` action, the AI read
  surface on contacts, the `approved` source, and the deliberate-entry
  invariant as it now reads; the reader host surface's `currentMessageId()`.
- `docs/calendar.md` — `calendar_entry_add` as the AI write path and the
  `ai` source value.
- CLAUDE.md doc-index lines only if a description stops matching.

## Testing (at build time)

- `contact_add` logic test: grant validation, normalization/dedup through
  the service, sealed-at-rest for a vault holder, `approved` vs `manual`
  source stamping.
- Queue test additions: an `invoke_action` proposal against `contact_add`
  renders a card with the literal address; approve creates the row via the
  service (hash present, sealed when owner has a vault).
- Batch resolve test: mixed approve outcomes (one succeeds, one fails
  live re-validation), cap enforcement, ownership check per id.
- `calendar_entry_add` logic test: subject pinned to the caller, paired
  local/UTC times consistent through `set_core_fields()`, source stamped
  `ai`, foreign-subject attempt refused.
- Panel test additions: `chat_send` accepts/validates the area fields;
  context stored; grant-failure drops the address; `message_id` outside
  the caller's mailboxes dropped.
- Browser pass on dev: composer ask → streamed reply → cards → approve all
  → contact visible in the mailbox contacts UI; a mixed batch (contacts +
  a calendar entry) renders as two group cards, never one.
