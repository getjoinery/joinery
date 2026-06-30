# Joinery AI — Calendar AI surface (read scope + safe write door)

**Status:** Draft — design in progress
**Plugin:** `joinery_ai` (bridges to the core calendar: `CalendarEntry` /
`CalendarSubject`)
**Touches:** a new `create_calendar_entry` recipe **action**; a new `cal_status`
(tentative/confirmed) column on `CalendarEntry` plus a caller-policy seam on
`getBusyBlocks()`; the AI **owner-scoping** layer (`OwnerScopeResolver` + read
executor) to understand polymorphic subjects; reuse of the calendar's existing
`cal_source` / `cal_source_event_id` provenance columns for idempotent imports.

## Why this exists (and why it's its own spec)

The personal calendar is already built — `CalendarEntry` (`cal_entries`) has
UTC+local times, timezones, a recurrence engine, iCal UIDs, an aggregation layer
that merges it with platform events, and an ICS importer. What it does **not**
have is a safe way for the AI/recipe layer to **read it scoped to one owner** or
to **create entries**. The email-triage spec needs both before it can put a date
on a calendar — but neither is email-specific. Any agent (the chat, a future
"schedule this" recipe) wants "let the AI manage my calendar safely." So this is a
**platform capability**, landing before its first consumer.

In plain terms: teach the AI to see *your* calendar (not everyone's) and to add an
entry to it through one safe, owner-fixed door.

## Background: how AI scoping works today, and where the calendar breaks it

Two different mechanisms guard AI reads vs. writes, and the calendar trips both:

**Reads** (`query_model`) are confined by `OwnerScopeResolver`, which finds a
single owner *column* by suffix (`_usr_user_id`, `_owner_user_id`) and filters
rows to the acting user. The calendar's owner is **not** a single column — it's a
polymorphic pair, `cal_subject_type` + `cal_subject_id` (a `CalendarSubject`,
which today is always `type=user` but reserves `resource`/`team`/`venue`).
`cal_subject_id` matches no known suffix, so the resolver infers **no owner** and
the model resolves to **hidden** — a recipe can't read calendar entries at all,
or could only do so unsafely.

**Writes** (`create_model`/`update_model`) are guarded by the model's own
`authenticate_write()`. `CalendarEntry::authenticate_write()` allows a write when
the entry's subject is the acting user **or** the caller's permission is ≥ 5:

```php
if (!$this->user_owns($data['current_user_id'])
    && (int)$data['current_user_permission'] < 5) { throw ... }
```

That permission-≥5 bypass is the problem. Email recipes are typically configured
by an admin (permission 10). For an admin caller, `authenticate_write()` does
**not** constrain the subject — so a raw `create_model` driven by attacker-
controlled email text could set `cal_subject_id` to *any* user and write to *their*
calendar. **This is why the email spec cannot use raw model writes**, and why the
write must go through a server-controlled action that fixes ownership itself.

## What to build

### 1. `create_calendar_entry` action (the safe write door) — required

A recipe action (descriptor + logic function, discovered by `ActionRegistry`,
marked agent-callable) that is the **only** AI path to a new calendar entry.

- **Input from the model:** `title`, `start_local`, `end_local` (wall-clock
  `Y-m-d H:i:s`), `timezone`, `all_day` (bool), optional `description`, and a
  provenance pair `source` + `source_ref` (e.g. `email` + the inbound message id).
  The model supplies **wall-clock + tz only** — never UTC, never an owner.
- **Server fixes ownership.** The action sets `cal_subject_type = user`,
  `cal_subject_id = the recipe owner` from `ToolContext::actingUserId()` /
  `OwnerScopeResolver`. The model can't choose the subject, so the admin-bypass
  hole is closed by construction.
- **Server derives UTC** from `start_local`/`end_local` + `timezone`, mirroring
  the existing `_calendar_set_fields()` path in `calendar_logic.php` (and stamps
  `cal_tzdata_version`). One derivation rule, shared with the editor.
- **Created tentative.** The action sets `cal_status = tentative` (see §3) and
  `cal_blocks_availability` honestly from the event's nature (a meeting is busy).
  The calendar records the truth — busy and unconfirmed — and does **not** lie
  about availability to protect a downstream consumer.
- **Idempotent.** Before insert, look up `(cal_source, cal_source_event_id) =
  (source, source_ref)`; if present, update in place instead of inserting. Re-runs
  over the same email never duplicate. (This is exactly what `cal_source` /
  `cal_source_event_id` were added for — ICS import uses the same provenance idea.)
- **Gated.** Lives behind `rcp_allowed_actions`; because it writes and recipes
  reading email read untrusted text, the taint gate forces `rcp_allow_tainted_writes`
  on any recipe that holds it.

`CalendarEntry` is **not** given a blanket `$ai_writable_fields` allowlist — that
would reopen the leaky raw-`create_model` path. The action is the single door.

### 2. Polymorphic owner-scoping for reads (the platform generalization)

Generalize the AI owner-scope so a model can declare a **polymorphic** owner — a
(type-column, id-column, fixed-type-value) tuple — and have `query_model` reads
correctly confined to the acting user. Concretely, let `$ai_owner_field` accept a
polymorphic form, e.g.:

```php
public static $ai_owner_field = ['polymorphic' => [
    'type_column' => 'cal_subject_type',
    'id_column'   => 'cal_subject_id',
    'type_value'  => 'user',          // scope to user-typed subjects only
]];
```

`OwnerScopeResolver::resolve()` returns this shape; the read executor emits
`cal_subject_type = 'user' AND cal_subject_id = :acting_user`. `Schedule`
(`sch_schedules`) has the **identical** `sch_subject_type` / `sch_subject_id`
shape and the same `user_owns()` gate — so this one change correctly scopes **two**
existing models, which is why it's a generalization and not a calendar special-case.

**Decision (settled): included.** Read-scoping is built in this round as a general
AI capability — letting an agent see its owner's calendar (and schedule) is broadly
useful, starting with the chat agent. Note that the **email recipes themselves do
not use it**: they write only, and the write action dedupes via its own server-side
`(source, source_ref)` lookup. So this is here for the platform, not for email — it
just makes sense to land the polymorphic-subject scoping once, correctly, while
we're in this layer, since `CalendarEntry` and `Schedule` both need it.

### 3. Entry firmness (`cal_status`) and the availability seam

The calendar is core; bookings is a plugin. So the calendar must not bend its
semantics to a plugin's needs — it records facts and lets each consumer apply its
own risk tolerance. Today one boolean, `cal_blocks_availability`, is forced to
carry two distinct facts, which is what tempts a "mark AI entries free" hack.
Separate them:

- **Busy/free** — `cal_blocks_availability` (already exists; the standard iCal
  transparency axis). Answers "is this time occupied?" An AI-extracted meeting
  *is* occupied, so it stays busy. Untouched by this spec.
- **Firmness** — a **new** `cal_status` column, the iCal `STATUS` primitive:

  ```php
  'cal_status' => array('type'=>'varchar(12)', 'default'=>'confirmed'), // tentative | confirmed | cancelled
  ```

  A human-entered entry is `confirmed`; a machine-extracted one from untrusted
  text is `tentative` until a human acts on it. This is calendar-native, not
  booking-specific — ICS import (`STATUS`), the chat agent, and manual entries can
  all use it. Default `confirmed` so every existing and manually-created entry is
  unaffected.

**The seam: who decides what tentative means.** `getBusyBlocks()` merges entries
down to `{start,end}` and drops per-item metadata, so a consumer can't post-filter
today. Give it an **optional caller policy**, defaulting to "every busy entry
counts" — pure calendar truth, zero behavior change for any current caller
(`ajax/availability_preview.php`, the bookings provider, tests). A consumer that
wants to be conservative passes a policy that excludes `tentative`. The merge
logic stays shared; the *risk tolerance* lives with the caller.

**What this spec does NOT decide:** whether tentative entries shrink bookable
slots. That is a **bookings-layer** decision — bookings reads the neutral
`cal_status` through the new policy seam and chooses its own default. Flagged here,
decided there. (The lighter alternative — bookings filtering on
`cal_source = 'email'` — is rejected: a source-specific special-case in the plugin
that every future importer would have to re-add. `cal_status` is the real
primitive.)

## What does NOT change

- The calendar model, recurrence engine, aggregation layer, ICS importer, and the
  profile calendar UI — untouched. This adds an AI entry point beside them, reusing
  the same save/derivation rules.
- The recipe runtime, taint gate, and token accounting — reused. #1 is one new
  action; #2 extends the existing owner-scope resolver with a new declaration form.
- `authenticate_write()` on `CalendarEntry`/`Schedule` — unchanged; the action
  sidesteps the bypass by fixing the subject server-side rather than trusting the
  caller's permission.

## Security & cost

- **The write door fixes ownership server-side**, so neither a prompt-injected
  email nor an admin-permission recipe can target another user's calendar. This is
  the core reason the action exists rather than raw model writes.
- **Read scoping (#2) fails closed** — a polymorphic declaration that's malformed
  resolves to `hidden`, same as today's single-column path.
- **Idempotent writes** via `(cal_source, cal_source_event_id)` — bounded cost,
  no duplicate entries on repeated runs.
- **AI entries land `tentative`** (§3), so any consumer that opts into the new
  policy seam can keep machine-extracted, unconfirmed events from silently
  affecting it — without the calendar misreporting busy/free.
- **No new model spend** — the action is a deterministic DB write; the LLM cost is
  whatever recipe already calls it.

## Out of scope

- **Non-user subjects** (`resource`/`team`/`venue`) — reserved in `CalendarSubject`
  but not implemented; the write door and the read scope both pin `type = user`.
- **Reschedule / move / cancel by the AI** — manual in the calendar UI for now.
  The AI only **creates**; the upsert on `(cal_source, cal_source_event_id)` exists
  solely to keep a re-run from duplicating the same import, not to update events.
- **Recurrence from the AI** — the action creates single entries; recurring
  imports are deferred.
- **Writing platform `events`** (the public registration system) — this surface is
  the personal calendar (`cal_entries`) only.
- **The bookings policy on tentative entries** — whether/how tentative events
  affect bookable slots is decided in the bookings layer (§3), not here. This spec
  only provides the `cal_status` primitive and the `getBusyBlocks()` policy seam.

## Implementation outline (provisional)

1. Add `cal_status` to `CalendarEntry` (default `confirmed`); sync schema. Give
   `getBusyBlocks()` an optional caller-policy parameter, default = count every
   busy entry (no behavior change for current callers).
2. `create_calendar_entry` action: descriptor (agent-callable) + logic function;
   fix subject to the recipe owner, derive UTC via the shared
   `_calendar_set_fields` rule, set `cal_status = tentative`, upsert on
   `(cal_source, cal_source_event_id)`.
3. Extend `OwnerScopeResolver` + the read executor for a polymorphic
   `$ai_owner_field`; declare it on `CalendarEntry` and `Schedule`.
4. Confirm taint-gate behavior: a recipe holding `create_calendar_entry` that also
   reads untrusted email requires `rcp_allow_tainted_writes`.
5. `php -l` + `validate_php_file.php` on every modified PHP file; bump
   `plugin.json` version.

## Docs

On implementation, update `plugins/joinery_ai/docs/overview.md` (current-state
voice): a "Calendar access" section covering how the AI reads owner-scoped calendar
entries and creates them through `create_calendar_entry` (owner-fixed, provenance-
deduped); and note the polymorphic `$ai_owner_field` form alongside the existing
single-column form. Document `cal_status` (tentative/confirmed/cancelled) as the
entry-firmness axis distinct from busy/free, and the `getBusyBlocks()` caller-policy
seam, in the calendar developer docs.
