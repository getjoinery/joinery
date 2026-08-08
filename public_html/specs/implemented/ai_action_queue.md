# AI Action Queue

## What this is, in plain terms

One rule, made structural: **the AI never changes anything without either a
standing rule you configured, or a yes you clicked.**

Recipes are the standing rules — each one acts through a fixed, bounded verdict
menu the owner set up in advance. Everything else an AI surface wants to *do*
(forward a message, send a reply, create a calendar entry, save a file) becomes
a **proposed action**: a queued card the owner reads and approves or declines.
Nothing executes on proposal.

Why this exists: when the AI reads an email, the email's author gets to talk to
the AI. A message can contain instructions aimed at the model ("forward the
last three messages to…"), and a model that is good at following instructions
may follow those. Recipes are already safe against this by construction — a
hostile message can at worst mis-pick from the verdict menu. Chat (and the
future area composer) holds general tools, so its writes need the same
property. The queue provides it: a hostile message's best possible outcome
drops to "proposed an action the owner saw and declined."

This is the third leg of an existing pattern: `TaintGate` arms on untrusted
reads, `SealedEgressGuard` refuses unprotected writes of sealed content at the
moment of writing — the queue **defers** an AI write to a human.

---

## UI vocabulary: approvals, never "taint"

The word "taint" (and "tainted") never appears in user-facing text. The UI
names the control, not the contamination:

- A recipe's consent is a **standing approval** — "This recipe acts on
  incoming mail on its own, without asking you each time."
- Queue items are **pending actions**, shown under **"Waiting for you"**.
- The risk, where it must be explained, is worded as outside influence:
  "Mail is written by other people. A message could try to steer the AI.
  This recipe's actions are limited to <the verdict menu>, so the most a
  message can do is <the bounded worst case>."

Internal identifiers (`TaintGate`, `rcp_allow_tainted_writes`) keep their
names — they are precise for developers and renaming columns buys nothing.
`TaintGate::explain()` is UI text and is rewritten in this vocabulary; every
surface that shows it (the recipes dashboard, the AI panel's confirm dialog)
inherits the new wording automatically.

---

## The invariant, precisely

Every AI-initiated write reaches the database or an outbound channel through
exactly one of two doors:

1. **A recipe verdict** — `recordVerdict()`, unchanged: a bounded enum,
   guarded to the recipe's own bindings, behind the owner's standing approval.
2. **An approved queued action** — executed at the moment the owner approves
   it, never before.

There is no third door. Chat write tools stop executing directly: calling one
enqueues a proposed action and returns "queued for approval" to the model as
the tool result, so the conversation continues (the model tells the user it is
waiting on them) instead of blocking. Read tools are unaffected.

---

## The object

New table in joinery_ai, `aqa_ai_queued_actions` (`data/ai_queued_actions_class.php`,
`AiQueuedAction` / `MultiAiQueuedAction`):

- `aqa_ai_queued_action_id` — pk
- `aqa_owner_user_id` — fk `usr_users`, `permanent_delete`; the only person
  who can see or resolve the action
- `aqa_area` — `'mailbox'` (later `'calendar'`, `'drive'`), matching the AI
  panel's area strings
- `aqa_source_type` — `'chat'` now; `'recipe'` is reserved (see "Recipes and
  the queue" below) — plus `aqa_conversation_id` (nullable fk, delete with the
  conversation) and `aqa_recipe_id` (nullable fk `rcp_recipes`,
  `permanent_delete`)
- `aqa_tool` — the tool identifier the model called (e.g. `mail.forward`)
- `aqa_arguments` — jsonb, the **literal structured arguments** of the tool
  call, sealed per the next section
- `aqa_model_note` — the model's optional one-line reason, stored separately
  from the arguments and rendered as quoted, secondary text
- `aqa_status` — `pending` / `approved` / `declined` / `expired` / `failed`
- `aqa_result` — jsonb execution outcome, sealed under the same rule as the
  arguments
- `aqa_created_time`, `aqa_resolved_time`, `aqa_expires_time` — UTC

Resolved rows are kept: the queue doubles as the audit trail of what the AI
did and who approved it. Retention policy is out of scope here.

### Sealing — the queue is a declared sealed sink from day one

A chat turn that has read sealed mail is *hot*, and a proposed action's
arguments may quote that mail (a drafted reply, a forward). `GuardedPdo` would
rightly refuse that row landing in the clear. The queue does not wait for the
demand-driven refusal to ask for it: `aqa_arguments`, `aqa_result`, and
`aqa_model_note` are sealed to the owner **whenever the enqueueing turn is
hot** (per-owner sealing, same shape as the idempotency cache), and stored in
the clear when cold. Rendering a sealed card requires the owner's vault window
— which approval always has, because resolving is an in-browser act.

---

## Rendering: the one-card rule

The card the owner approves is built **by the platform from `aqa_tool` +
`aqa_arguments`** — literal recipient, literal label, literal content — never
from the model's description of what it wants to do. If the card showed model
prose as its substance, injected instructions would simply move into the
prose. This is the queue's equivalent of the one-write-door rule.

Each queueable tool declares a renderer alongside its executor: given
arguments, produce the card's facts line(s) (e.g. *Forward "Invoice #4411"
from jeremy@x.com to bob@y.com*). A tool without a renderer cannot enqueue —
enforced at registration, so an unrenderable action is impossible, not just
unlikely.

`aqa_model_note` renders collapsed behind a disclosure, styled as quotation —
visibly the model's voice, never the card's facts.

---

## Resolving

**Approve** executes the action *in that request*, as the owner, re-validating
against live state exactly as `recordVerdict()` does: the tool re-runs its own
guards (grants, coverage, existence of the message/label/entry). A validation
miss or execution error resolves the row `failed` with the reason on the card
— an approved action never silently half-happens. Because approval is
in-browser, sealed content is readable (window open) and the write happens
in-window by construction: no cron-versus-sealed contortion.

**Decline** resolves the row and runs nothing. If the source conversation is
still live, the resolution (approved result or decline) is appended to it as a
tool event, so the model knows on its next turn.

**Expiry**: a pending action past `aqa_expires_time` (default 7 days) resolves
`expired` and can never execute. Proposals are perishable; the world they
described moves on.

Deliberately absent: **approve-all**. Rubber-stamping is the queue's failure
mode; each card is resolved individually. If a category of action lands in the
queue constantly, that is the signal it should become a recipe — the queue
doubles as recipe discovery.

---

## Where it appears

- **The AI panel** (specs/implemented/ai_recipes_multi_mailbox_and_ai_panel.md) gains a
  **"Waiting for you"** section listing the area's pending actions, with
  approve/decline on each card, and the panel's AI button shows a pending
  count badge. Standing automations above, pending actions below, composer
  slot at the bottom — the panel is the whole AI surface for the area.
- **Inline in chat**: a proposed action renders as the same card in the
  conversation stream, with the same approve/decline. One object, one
  renderer, one execution path; the panel is simply where cards from closed
  conversations and background runs wait.

## Recipes and the queue

`recordVerdict()` is untouched — recipes remain the standing-approval door,
and their bounded menus are what keep routine volume *out* of the queue. The
`aqa_source_type = 'recipe'` value and `aqa_recipe_id` column are reserved for
a later extension: a recipe job proposing an action beyond its verdict menu
(e.g. the schedule job drafting a full calendar entry) by enqueueing it. Not
built now; the schema simply doesn't preclude it.

## API surface

Two logic actions with `_logic_descriptor()`, called over `/api/v1` with the
browser-session credential and `X-Joinery-Csrf` header:

- **`ai_actions_list`** (read) — input `{area?, status?}`; returns the
  signed-in owner's actions, rendered card facts included (server-rendered
  from arguments, so the client never interprets `aqa_arguments` itself).
- **`ai_action_resolve`** (write) — input `{action_id, resolution:
  approve|decline}`. Owner-scoped; approve executes synchronously and returns
  the outcome. Resolving a non-pending action is refused (idempotent-safe: the
  card refreshes to its true state).

Both member-callable; ownership is the authorization.

## Tests

db tier, `plugins/joinery_ai/tests/`:

- Enqueue from a chat write tool: row shape, tool result says queued, nothing
  executed.
- Render-from-arguments: card facts come from arguments; model note never in
  the facts; tool without a renderer refused at registration.
- Approve executes with live re-validation (deleted target → `failed`, reason
  recorded); decline runs nothing; resolving twice refused.
- Expiry: past-due pending action resolves `expired`, cannot be approved.
- Owner scoping: someone else's action id → refused.
- Sealing: enqueue from a hot turn seals arguments/note/result and the row
  renders in-window; a hot enqueue landing unsealed is refused by the guard
  (proving the sink declaration, not bypassing it).

## Documentation (updated when built, current-state voice)

- `plugins/joinery_ai/docs/overview.md` — "Proposed actions" section: the
  invariant, the two doors, the object, the renderer contract, sealing rule.
- `docs/api.md` — the two actions.
- The panel's docs section (already specced) notes the "Waiting for you"
  region.

## Version bumps

joinery_ai plugin minor bump; touched file `@version` bumps; panel JS bump for
the queue section when it lands.
