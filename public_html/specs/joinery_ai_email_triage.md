# Joinery AI — Inbound email triage (categorize + calendar)

**Status:** Draft — design in progress (picking up tomorrow)
**Depends on:** `joinery_ai_item_pipeline.md` — the triage half is a pipeline
job (per-item digest → validated verdict → handler), which supersedes this
draft's bespoke plumbing: the `aie_email_processing` table (§2) is replaced by
the pipeline's platform processing log, the mailbox binding (§1) is the job's
`rcp_source_config`, and the label/summary writes become the job's
`recordVerdict` handler instead of `update_model` + a bespoke action. See the
sibling `joinery_ai_email_security_scan.md` for the worked example of the same
pattern on the same messages.
Also depends on: `joinery_ai_calendar_ai_surface.md` — the AI cannot read a
scoped calendar or safely create entries today. That prerequisite (the
`create_calendar_entry` owner-fixed action, and optionally polymorphic read
scoping) must land before the scheduling half of this spec. The triage/categorize
half has no such dependency and can proceed independently. **Reading attachments**
additionally assumes `inbound_email_attachment_storage.md` (attachments stored as
discrete `File` objects) and its prerequisite `implemented/file_private_storage.md`.
**Plugin:** `joinery_ai` (reads `mailbox`, writes the calendar/events surface)
**Touches:** the **AI exposure surface** of `InboundEmailMessage` (read +
untrusted-field declaration + a small writable category field), the calendar/event
write path (model allowlist *or* a dedicated action — open question), and two
**recipes** the admin configures. Little-to-no new tool code — this rides the
existing generic recipe tools (`query_model`, `update_model`, `invoke_action`)
and the taint gate.

## Goal

Inbound mail already lands in `iem_inbound_email_messages` after spam/auth
filtering. The messages that survive are still an undifferentiated pile, and
anything with a date in it ("call Tuesday at 3", "invoice due the 15th") has to
be read and hand-entered to land on a calendar.

This spec lets the AI do two things to the mail that passes the filters:

1. **Sort / categorize** — read each new message and tag it (category + a
   one-line summary) so the inbox is triaged automatically. Read-side only;
   reversible; safe to run unattended.
2. **Schedule** — when a message describes a dated event, add it to the calendar.
   Write-side; attacker-influenced; gated and (recommended) confirmed.

In plain terms: the AI files your incoming mail for you, and offers to put any
dates it finds on your calendar.

## The core safety idea (why two recipes, not one)

Inbound email is **attacker-controlled text** — anyone can send you mail, and a
crafted message can try to talk the model into doing more than you intended. The
platform already has the exact defense for this: a recipe can only use the tools,
models, and actions on its **per-recipe allowlists**, and the `TaintGate` refuses
to save or run any recipe that can *both* read untrusted content *and* write,
unless the admin sets `rcp_allow_tainted_writes` as a deliberate acknowledgment.

Locking a recipe down **caps the blast radius; it does not make the email
trustworthy.** So we split the work along the trust boundary:

- **Recipe A — Triage (read + tag).** Allowed tools: `query_model`,
  `update_model` restricted to the email's own category/summary fields. No
  calendar capability. Worst case of an injection: a mislabeled email. Safe to
  run wide open, unattended.
- **Recipe B — Schedule (calendar write).** The *only* recipe holding the
  calendar-write capability and the taint opt-in. Worst case of an injection: a
  junk entry on the owner's own calendar — and nothing else, because that one
  capability is all it has.

One read-only recipe you trust to run automatically; one write recipe that is the
single, gated, auditable door to the calendar.

## What already exists (and is reused as-is)

- **Filtering** — spam/auth verdicts (`iem_spam_verdict`, `iem_spam_score`,
  `iem_learned_verdict`), `iem_is_archived`, soft-delete. "Messages that make it
  through" = a query filter, not new code. The triage recipe selects inbound,
  non-spam, non-archived, non-deleted messages.
- **The generic recipe tools** — `query_model` (read), `update_model` /
  `create_model` (write, gated by each model's `$ai_writable_fields`),
  `invoke_action` (run a registered logic action). No bespoke "categorize" or
  "add to calendar" tool is needed if the models/actions opt in correctly.
- **The taint gate** — `TaintGate::evaluate()` already fires at save and at
  run-start; declaring the email's body/subject/sender as untrusted (below) is
  what makes Recipe B require the opt-in.
- **Owner scoping** — `OwnerScopeResolver` fixes ownership on AI writes from the
  recipe owner, so a write can't be aimed at another user.

## Work to do

### 1. Expose `InboundEmailMessage` to the recipe read surface (taint source)

On `inbound_email_message_class.php`:

```php
public static $ai_readable = true;
// The attacker-controlled fields. Declaring these is what trips the taint
// gate for any recipe that also holds a write tool.
public static $ai_untrusted_fields = ['iem_sender', 'iem_subject', 'iem_body_plain'];
```

**Attachment filenames (and any attachment content the AI reads) are equally
attacker-controlled.** They live on the manifest / `File`, not the message row, so
they're declared untrusted there — the taint gate must treat an attachment-reading
recipe exactly like one reading the body. See §2c.

A message has **no single owner**: it belongs to an address (an *alias*), and an
alias is shared with users through mailbox grants (`ieg_inbound_email_mailbox_grants`,
alias ↔ user, many-to-many). So "the recipe's mail" is defined by configuration,
not by an owner column on the message:

- A recipe **names the mailbox/alias** it processes, and the recipe **owner must
  hold a grant** to that alias (validated at recipe save). The recipe then reads
  messages on that alias.
- The recipe also **names the target calendar** it writes to (see §3), validated
  writable by the recipe owner at save time.

These two config choices — which mailbox in, which calendar out — are set by the
human at configuration time and are trusted at runtime; the LLM never chooses
either.

### 2. Per-recipe processing log (idempotency, not a once-per-message limit)

> **Superseded by the pipeline:** this section's reasoning stands (per-recipe,
> not per-message), but the table is now the platform-owned
> `aip_recipe_item_log` from `joinery_ai_item_pipeline.md` §5 — do not build
> `aie_email_processing`. The sketch below is kept for the rationale.

A recipe must not redo work it already did — re-running shouldn't re-summarize the
same mail or create duplicate calendar events. But a message is **not** limited to
one pass overall: different recipes legitimately handle the same message for
different jobs (categorize, extract events, draft a reply), and a shared mailbox
may feed two people's recipes.

So processing state is tracked **per recipe**, not as a flag on the (shared)
message. A small log table records what each recipe has handled:

```php
// aie_email_processing  (new data class)
'aie_processing_id'  => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
'aie_rcp_recipe_id'  => array('type'=>'int8', 'required'=>true, 'unique_with'=>array('aie_iem_message_id')),
'aie_iem_message_id' => array('type'=>'int8', 'required'=>true),
'aie_processed_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
```

The recipe selects messages on its alias that have **no** processing row for *this
recipe* yet; after handling one, it writes the row. A re-run is a no-op for that
recipe; a *different* recipe is unaffected. This also removes triage state from the
shared message, so two recipes on one mailbox never clobber each other.

### 2b. The triage output is **labels** — reuse, don't invent

There is no new "category" concept. The inbound email plugin already has a labels
feature — `InboundEmailLabel` (named labels) and `InboundLabelMember` (the
message↔label join, multi-valued, with IMAP-folder sync). Triage applies labels.

Because labels are **additive and many-to-many**, the placement/clobber problem
disappears: two recipes labeling the same shared message simply each add their
labels; nothing is overwritten, and the inbox already renders multiple labels.

- **Apply-only, never create.** The recipe picks from the **existing** labels and
  applies the one that fits; if none fits, it does nothing. It does not invent
  labels — that keeps the vocabulary the human's, with no AI label drift.
- **How:** a small `apply_email_label` action (mirrors `create_calendar_entry`) —
  given a message and an existing label, it adds the `InboundLabelMember` join row.
  Non-destructive and reversible.
- **Reach:** label-only for v1. Archiving/starring stays human (a mis-archive from
  a crafted email could hide real mail — loud-and-cheap beats silent-and-expensive).

The label write goes through the action, so the only AI-writable field on the
message is a one-line **`iem_ai_summary`** gist for the inbox, written via
`update_model`:

```php
'iem_ai_summary' => array('type'=>'varchar(280)'),  // one-line gist for the inbox
public static $ai_writable_fields = ['iem_ai_summary'];
```

On a domain at a protected security level, this column is sealed like the other
content columns — a gist is the body in miniature — and decrypts in-session with
the previews (`mailbox_security_levels.md` § AI Processing of Protected
Mail). Labels stay cleartext (operational metadata).

It's a shared field (last-writer-wins is fine for a gist). The message has no owner
column, so message writes — like reads — are constrained to the recipe's configured
mailbox rather than per-row owner scoping.

### 2c. Attachments the AI can see

With `inbound_email_attachment_storage.md` in place, each attachment is a discrete
private `File` linked from the manifest, decoded and ready — no MIME-parsing a raw
blob. That makes attachments available to triage without new fetch plumbing:

- **Metadata, always.** The recipe reads each attachment's filename, content-type,
  and size from the manifest — enough to label ("has an invoice PDF") and summarize.
- **Text-native parts, read directly.** Parts that are already text — a
  `text/plain` attachment, and especially a **`text/calendar` (`.ics`) invite** —
  can be read straight from the `File`. The `.ics` invite is the high-value
  scheduling signal: Recipe B routes it to the `create_calendar_entry` door (§3)
  rather than re-deriving a date from prose.
- **Binary extraction is deferred.** Pulling text out of PDFs, images (OCR), or
  Office docs needs an extraction layer that is its own later spec. v1 reads
  metadata + text-native parts only; it does not crack open binaries.

**Untrusted surface.** A filename and any read attachment content are
attacker-controlled, so an attachment-reading recipe is a tainted reader exactly
like a body-reading one — the two-recipe split and `rcp_allow_tainted_writes`
posture apply unchanged. Reach is read-only here; attachments are never written or
deleted by the AI.

### 3. The calendar write path (Recipe B's one capability)

Recipe B writes via the **`create_calendar_entry` action** from the prerequisite
spec (`joinery_ai_calendar_ai_surface.md`) — the single owner-fixed, auditable
door. The action's **target calendar comes from the recipe's configuration** (the
calendar named in §1, validated writable by the recipe owner at save), *not* from
the LLM. The model fills in only the event (title, local start/end, timezone,
all-day, description) plus the source email id; entries land `cal_status =
tentative`. When the email states no timezone, the action falls back to the
**recipe owner's timezone**; a date with no time becomes an all-day entry. Recipe B
grants the action via `rcp_allowed_actions`, and because it
reads untrusted email and writes, the taint gate requires `rcp_allow_tainted_writes`.

## Recommended automation posture

- **Triage (Recipe A):** automatic. Read-only-plus-its-own-tags, reversible,
  unattended.
- **Schedule (Recipe B):** **auto-add directly — no queue, no approval workflow.**
  The recipe creates the calendar entries itself. The safety net is already in the
  data, not in a pending-approval mechanism: entries land `cal_status = tentative`,
  so a consumer's policy keeps them from silently blocking availability (calendar
  prereq §3), and they're easy to spot and delete. Tentative status *is* the
  lightweight "propose" — the entry exists but is flagged unconfirmed until a human
  acts on it. The recipe's normal **dashboard delivery** lists what it added, so
  there's a review trail without building one.

## What does NOT change

- The inbound email ingestion, spam/auth filtering, storage, and reader UI —
  untouched. This reads what's already there and writes only its own category
  label(s) and the per-recipe processing log.
- The recipe runtime, agent loop, providers, taint gate, owner scoping, token
  caps — reused, not modified. The feature is configuration + model exposure.
- Message bodies are never written by the AI; only the triage verdict fields are.

## Security & cost

- **Trust boundary is the two-recipe split**, enforced by per-recipe allowlists +
  `TaintGate` + `rcp_allow_tainted_writes`. Recipe A has no write-to-anything-but-
  its-own-tags surface; Recipe B's only write is the calendar door.
- **Access-scoped at config time** — a recipe reads only a mailbox its owner is
  granted, and writes only a calendar its owner can write; both validated when the
  recipe is saved. The calendar write itself goes through the prerequisite's
  owner-fixed `create_calendar_entry` action, so attacker text can't redirect it.
- **Metered** by the existing per-recipe `rcp_monthly_token_cap` /
  `rcp_max_tokens` / `rcp_max_iterations`. Triage cost scales with new-mail
  volume — the per-recipe processing log keeps each run to messages that recipe
  hasn't handled yet.
- **No new client-trust surface** — recipes are admin-configured server-side; the
  untrusted input is the email body, which the taint gate is built to contain.

## Open questions (resolve tomorrow)

1. ~~**Ownership of inbound mail.**~~ **Resolved:** a message has no owner column —
   it belongs to an alias, shared with users via mailbox grants. A recipe instead
   **names the mailbox it reads and the calendar it writes**, both validated against
   the recipe owner's access at save time (§1). Idempotency is **per-recipe** via a
   processing log, not a per-message flag (§2), so a message may be handled by more
   than one recipe.
2. ~~**What "the calendar" is.**~~ **Resolved:** a personal calendar already
   exists — `CalendarEntry` (`cal_entries`), with UTC+local times, recurrence, iCal
   UIDs, and `cal_source`/`cal_source_event_id` provenance columns. Email events
   become native entries with `cal_source = 'email'`. The platform `events` model
   (public registration system) is **not** the target. See
   `joinery_ai_calendar_ai_surface.md`.
3. ~~**Calendar write path.**~~ **Resolved: dedicated action**, not raw model
   write. `CalendarEntry::authenticate_write()` bypasses its ownership check for
   permission ≥ 5 callers (which admin-configured recipes are), so a raw
   `create_model` could write to any user's calendar from attacker text. The
   owner-fixing `create_calendar_entry` action in the prerequisite spec is the only
   safe door.
4. ~~**Automation posture for scheduling.**~~ **Resolved: auto-add, no queue.** The
   recipe creates entries directly; `cal_status = tentative` + dashboard listing are
   the safety net, not a pending-approval workflow.
5. ~~**Where the category lives, and what it is.**~~ **Resolved: reuse the existing
   labels feature** (`InboundEmailLabel` / `InboundLabelMember`) — no new category
   concept. Labels are additive and many-to-many, so multiple recipes never clobber.
   Triage applies an *existing* label via an `apply_email_label` action — apply-only,
   never creates (§2b). Sub-item **resolved: keep `iem_ai_summary`** as the one new
   AI-writable message field — a shared one-line gist for the inbox.
6. ~~**Trigger cadence.**~~ **Resolved: scheduled poll, key-gated** — the recipe
   runs on its normal schedule and picks up messages it hasn't processed
   (per-recipe log). On a domain at a protected security level
   (`mailbox_security_levels.md` § AI Processing of Protected Mail), a
   message is digestible only while an unlocked session's key is available; it
   stays pending in the log until then. An on-arrival trigger can come later;
   not v1.
7. ~~**Timezone for ambiguous emails.**~~ **Resolved:** default to the **recipe
   owner's timezone** when the email doesn't state one; a date with no time becomes
   an all-day entry.
8. ~~**Who may call the actions.**~~ **Resolved: recipes only for now.**
   `apply_email_label` and `create_calendar_entry` are not exposed to the chat agent
   yet — that waits on the interactive-use taint posture.

## Implementation outline (provisional)

> **Revisit against `joinery_ai_item_pipeline.md` before implementing.** Recipe
> A (triage) should be a registered pipeline job (`email_triage`): config = the
> mailbox alias (+ label vocabulary source), digest = the same
> `EmailSecurityDigest`-style bounded view (or a lighter variant), verdict =
> {label choice from the existing vocabulary, one-line summary}, handler =
> apply the `InboundLabelMember` row + write `iem_ai_summary`. That removes
> items 2–4 below and the need for `$ai_readable`/`query_model` for the triage
> half. Recipe B (schedule) likely fits the same shape (verdict = event fields
> or none; handler = the `create_calendar_entry` door), but resolve that when
> its calendar prerequisite lands.

1. `InboundEmailMessage`: `$ai_readable`, `$ai_untrusted_fields`, and one
   AI-writable field `iem_ai_summary` (+ `$ai_writable_fields`). No category field.
   Sync schema.
2. New `aie_email_processing` data class (per-recipe processing log) + its Multi.
3. Recipe configuration: a target **mailbox/alias** and target **calendar**, each
   validated against the recipe owner's access at save.
4. `apply_email_label` action — apply an existing label (add `InboundLabelMember`
   row); never creates labels.
5. Calendar write via the prerequisite's `create_calendar_entry` action (depends on
   `joinery_ai_calendar_ai_surface.md`).
6. Attachment read surface (depends on `inbound_email_attachment_storage.md`):
   expose the manifest fields (filename/type/size) to the recipe read surface,
   declare the filename untrusted, and allow reading text-native parts
   (`text/plain`, `text/calendar`) from the linked `File`. No binary extraction.
7. Seed/author **Recipe A (triage)** and **Recipe B (schedule)** with their
   allowlists; B carries `rcp_allow_tainted_writes`.
8. Verify the taint gate behaves: B refuses to run without the opt-in; A stays
   clean (no write tools beyond its own labels).
9. `php -l` + `validate_php_file.php` on every modified PHP file; bump
   `plugin.json` version.

## Docs

On implementation, update `plugins/joinery_ai/docs/overview.md` (current-state
voice): an "Email triage" section covering the two-recipe pattern, the untrusted-
field declaration and taint opt-in, the triage fields, and the calendar door; and
cross-reference `plugins/mailbox/docs/overview.md`.
