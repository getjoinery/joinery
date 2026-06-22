# Scheduling System — Personal Calendar + Availability Engine (core)

## Overview

Build a **personal calendar** at the center of the platform. Every time-bound thing in the system (events, native entries, and later bookings and external feeds) projects onto one timeline per subject. Availability is *derived from* the calendar (a projection of it), never the other way around.

This spec covers the **core, reusable** pieces — the calendar, the availability engine, the calendar UI components, and native personal entries. It is designed so any feature can consume schedules and the calendar; the appointment-booking product is a separate layer.

The core is layered:

1. **Personal calendar (core)** — a subject-owned timeline, a typed calendar-item model, and a `CalendarItemSource` registry through which every feature contributes items. "Busy time" is a derived projection of these items. This is the platform's central scheduling integration point.
2. **Availability engine (core)** — schedules, weekly windows, date overrides, and a slot generator that consumes the busy projection. General-purpose: usable by bookings, staff scheduling, resource booking, or any feature that needs "who is free when."
3. **Calendar UI components (core)** — a month/week calendar grid (the personal calendar's primary surface) and a slot picker, built as component-system types.

**Booking is a separate spec.** The appointment-booking product — booking types, the public booking page, the booking lifecycle, intake, paid bookings, and the pluggable scheduling-provider seam — lives in **`specs/native_booking_flow.md`**, which builds on the seams shipped here (the `CalendarItemSource` registry, the busy projection, `SlotGenerator`, and the calendar UI components). Keeping it separate is the point of this design: the general pieces land in core and only the appointment product lives in the bookings plugin.

**Subject identity (decided up front).** A calendar — and the schedule that defines its working hours — belongs to a *schedulable subject*, not directly to a user. `user` is the only type implemented now; `resource`, `team`, and `venue` are reserved. The seam is deliberately **narrow**: a subject is carried as a single `CalendarSubject` value object, one resolver is the only code that knows subject types exist, and only **two tables** store an owner (`sch_schedules` and `cal_items`). Adding a subject type later means editing the resolver and two tables, not the codebase, so building it in now costs almost nothing and the future extension stays cheap.

**Ownership model (decided).** The calendar is a system of record only for items that originate in the calendar itself; everything else is a read-only projection it links out to, with no write-back and no two-way sync. The full rule and its consequences are in the Calendar Ownership Model section below.

**External integrations are a separate, deferred spec.** Importing from / connecting to Calendly, Acuity, Google, and Outlook lives in `specs/external_scheduling_integrations.md`. The seams here are designed so all of it is additive — external calendars are just another `CalendarItemSource`.

**Pre-launch note:** The platform has no production users. Schema fields may be dropped/renamed freely; no data-preservation migrations are required.

---

## Integration-Point Inventory (decided up front)

Every platform system the core calendar/scheduling feature touches, and how:

| System | Integration |
|---|---|
| **Events system** | Read-only projection: events the subject leads + their active registrations appear as calendar items (and thus in the busy projection) via `EventItemSource`. Recurring events project through `Event::get_instances_for_range()`. |
| **Component system** | Calendar grid (personal calendar surface) and slot picker as component types (programmatic rendering, HTML5/vanilla JS, universal framework). |
| **AI discovery** | The `Schedule` and `CalendarEntry` classes keep `ai_readable` flags with appropriate descriptions. |
| **Routing** | Plugin view auto-discovery for the availability editor; core view for the personal calendar; a core ajax feed for the calendar grid. |
| **Future (not in this spec, seams reserved)** | Additional item sources (bookings, tasks, subscription renewals), non-user subjects (resource/team/venue), the booking feature (`specs/native_booking_flow.md`), external calendars (`specs/external_scheduling_integrations.md`). |

---

## Building the data layer with the scaffold generator

Every new table this spec introduces is a plain CRUD model behind **bespoke presentation** — the calendar grid, the availability editor, and the native-entry authoring are all hand-built UI, none of them a generated list/edit page. That is exactly the model-only case the scaffold generator (`specs/implemented/scaffolding_code_generator.md`) is for; its own example for `surfaces:["data"]` is "an entity with bespoke presentation (e.g. a calendar)." So the new models are **generated from manifests, not hand-written**: the generator emits the data class + Multi collection class — requires, `$field_specifications`, constructor, `getMultiResults()` filter branches, deletion wiring.

**Why generate these rather than hand-write them — stated plainly.** The reason is *not* effort or tokens saved. Measured honestly, generating four data-only classes is roughly break-even against writing them by hand once the manifest authoring and the verification pause are counted. The reasons that do hold up are two:

1. **Consistency.** Models that are provably identical in structure, guaranteed `validate_php_file.php`-clean, with no copy-paste drift — and a forcing function to declare every field, filter, and deletion rule up front in one manifest before any code exists.
2. **This is a deliberate real-world test of the generator.** The scaffold generator was just built and has not yet run against non-trivial inputs. This spec is its first real consumer, and it exercises the corners on purpose — subject-keyed `unique_with`, PostgreSQL `time` columns, and the non-standard-ownership stub path. Treating this as a shakedown is the point: gaps in the generator surface here, under a checkpoint written into the plan (the pause after Phase 2.1), rather than later in some spec with no guard.

| Entity (class) | Table | `surfaces` | Into |
|---|---|---|---|
| `Schedule` | `sch_schedules` | `["data"]` | core |
| `ScheduleWindow` | `scw_schedule_windows` | `["data"]` | core |
| `ScheduleOverride` | `sco_schedule_overrides` | `["data"]` | core |
| `CalendarEntry` (stored native entries) | `cal_items` | `["data"]` | core |

Two manifest choices are non-obvious and decided here:

- **Polymorphic owner → flagged auth.** `sch_schedules` and `cal_items` are owned by a `CalendarSubject` (`subject_type` + `subject_id`), not a real `usr_users` FK. The manifest sets `owner_field` to the `subject_id` column, which makes the generator emit `authenticate_read/write()` against that column (its documented behavior for non-standard ownership); the polymorphic resolve-then-owner-or-staff check (only a `user`-typed subject maps to a user id) is filled in by hand. The user-deletion cascade these tables depend on is declared in each manifest's `delete.foreign_key_actions`, since there is no real FK to carry it.
- **`time` columns, no form to break.** `scw_*_time` / `sco_*_time` are PostgreSQL `time` (wall-clock), which has no form-input mapping — irrelevant here because `surfaces:["data"]` emits no form.

Because this run is the generator's shakedown, Phase 2.1 runs it for the schedule manifests first and is immediately followed by a hard **pause to verify the output is what this spec needs before anything is built on top of it** (see the gate after Phase 2.1).

---

## Layer 1: Personal Calendar (core)

Lives in core (`/data/`, `/includes/calendar/`) because a calendar is a property of a subject, not of any one feature. This layer is the integration point the rest of the platform plugs into.

### Schedulable subject

`CalendarSubject` (`includes/calendar/CalendarSubject.php`) is a small value object — `{type, id}` — plus a resolver that turns it into the owner record (display name, timezone, avatar). It is the **single place** that knows subject types exist; everything else passes a `CalendarSubject` around without branching on type. `user` is the only implemented type.

It is not a table. Owner identity is stored as `subject_type` + `subject_id` columns on the only two owner-bearing tables — `sch_schedules` and `cal_items` — and resolved live. **Trade-off, accepted:** a polymorphic owner can't be a database foreign key to `usr_users`, so those two tables give up DB-level referential integrity on the owner column. The cost is contained by being just two tables and is covered by a declarative `$foreign_key_actions` cascade on user deletion (see Deletion Strategy); a cheap orphan check can backstop it if ever needed.

### Calendar item

The unit on the timeline. A value object (`includes/calendar/CalendarItem.php`), not necessarily a stored row — most items are projected live from their owning system:

| Field | Meaning |
|---|---|
| `start_utc` / `end_utc` | UTC instants (all wire data is UTC) |
| `all_day` | day-spanning vs. timed |
| `type` | `event` / `booking` / `external` / `personal` / … (drives colour + icon) |
| `title` | display label — **owner-visible only** |
| `url` | deep link to the owning record — owner-visible only |
| `blocks_availability` | whether this item removes time from availability |
| `visibility` | `details` (owner sees title/url) or `busy` (opaque) |
| `source` | the `CalendarItemSource` key that produced it |
| `source_key` | stable id per item (`{source}:{record-id}`) — lets the UI redraw/diff items, ICS export assign a UID, and click-to-edit identify the item |

Two fields are worth a note:

- **`visibility`** — a personal calendar shows the owner "Dentist, 2pm," but when a stranger loads the owner's public booking page the same item must read only as *busy*, never leaking the title. Enforcement is at the **projection boundary**, not by trusting callers: the public availability path requests items at `busy` level and the registry strips `title`/`url` before they leave the source aggregation. Owner-facing calendar requests get `details`.
- **`source_key`** — a stable id per item, so the calendar can redraw and diff items, ICS export can give each a UID, and click-to-edit knows which item was picked. Collapsing genuine duplicates (the same meeting arriving from two sources) only becomes possible once an external feed can echo a native item, so that dedup behavior is specified in `specs/external_scheduling_integrations.md`, not here.

> **Note:** the stored native-entry model is `CalendarEntry` (`data/calendar_entry_class.php`, table `cal_items`), distinct from the `CalendarItem` value object. `NativeCalendarItemSource` reads `CalendarEntry` rows and emits `CalendarItem` value objects.

### Calendar item sources

`includes/calendar/CalendarItemSource.php` — the single contract every feature implements to put things on calendars:

```php
interface CalendarItemSource {
    public static function getKey(): string;
    /** @return CalendarItem[] for the subject within the window, at the requested visibility */
    public function getItems(
        CalendarSubject $subject,
        string $start_utc,
        string $end_utc,
        string $visibility            // 'details' | 'busy'
    ): array;
}
```

Auto-discovered (EmailSender-style registry) from core `includes/calendar/item_sources/` and active plugins' `includes/calendar_item_sources/`. Core implementations:

- **EventItemSource** (core) — events where the subject is leader, plus their active registrations; recurring events via `Event::get_instances_for_range()` so virtual (unmaterialized) instances appear. `blocks_availability = true`, `visibility` honoured.
- **NativeCalendarItemSource** (core) — native `cal_items` entries (see Layer 1 / native entries).

The bookings plugin adds a `BookingItemSource` through this same registry (`specs/native_booking_flow.md`); external calendars add an `ExternalCalendarItemSource` (`specs/external_scheduling_integrations.md`). The registry being the only coupling point is the whole reason this lives in core: a new source appears on every calendar and gates every availability calculation with no change to the generator, the grid, or any other source.

### Busy projection

There is exactly **one** upstream contract — items. "Busy time" is a *derived view*: aggregate `getItems(...)` across the registry at `busy` visibility, keep those with `blocks_availability === true`, reduce to `{start, end}`, and merge overlaps. This projection is the only thing the availability engine and `SlotGenerator` consume. One registration therefore yields both outcomes — the item shows on the calendar **and** blocks availability — instead of a feature implementing two separate things.

---

## Calendar Ownership Model

The calendar is a **system of record only for items that originate in the calendar itself.** Everything else is a **read-only projection** it links out to. There is no write-back to external systems and no two-way sync.

- **Native entries** (`type = personal`) — created directly on the calendar (a personal appointment, a block of busy time). Stored in `cal_items`, owned by the calendar, fully editable here. Exposed through the registry like any other source via `NativeCalendarItemSource`, so they appear on the calendar and — when `blocks_availability` — gate availability.
- **Projected items** (events, and later bookings and external feeds) — owned by their originating system. The calendar renders them and deep-links to them (`url`) but never edits or moves them.

Consequences this locks in:

- `CalendarItemSource` stays a **read-only contract** — `getItems()` only, no write methods. The registry never grows a write path.
- **No sync engine, no conflict resolution** — every item has exactly one owner, so nothing is ever authoritative in two places.
- **No inline editing of projected items** — the deliberate concession. The calendar is a viewing + native-authoring surface; mutations to other systems route through those systems.
- "Block out time" is a native busy entry, not a fake event — the natural availability primitive.

Scope guards for native entries: start with single timed / all-day entries (recurrence deferred); do **not** fold schedule overrides into native entries — overrides shape the recurring availability *template*, native entries are concrete one-offs, and they stay separate models.

---

## Layer 2: Availability Engine (core)

Schedules define a subject's working hours; the slot generator turns hours-minus-busy into open slots. Pure, core, reusable.

### Data model

**`sch_schedules`** (Schedule / MultiSchedule, `data/schedule_class.php`) — **one row per subject** (unique on the subject): it *is* the subject's availability and the timezone anchor for their windows.
- `sch_subject_type` / `sch_subject_id` — the owning `CalendarSubject` (`user` only now; `resource` / `team` / `venue` reserved), **unique together**. One of only two owner-bearing tables; see *Schedulable subject* for the no-FK trade-off.
- `sch_timezone` — IANA timezone the windows are defined in.

**`scw_schedule_windows`** (ScheduleWindow / MultiScheduleWindow)
- `scw_sch_schedule_id` (fk)
- `scw_day_of_week` — 0=Sunday … 6=Saturday
- `scw_start_time`, `scw_end_time` — PostgreSQL `time` (wall-clock in the schedule's timezone)
- Validation: end must be after start; windows do not cross midnight — an overnight span is entered as two windows on adjacent days.

**`sco_schedule_overrides`** (ScheduleOverride / MultiScheduleOverride)
- `sco_sch_schedule_id` (fk)
- `sco_date` — the date being overridden (in the schedule's timezone)
- `sco_start_time`, `sco_end_time` — nullable `time`
- Semantics: if any override rows exist for a date, they **replace** the weekly windows for that date. A single row with null start/end = fully unavailable. Rows with times = that date's windows.

**Timezone exception (document explicitly):** unlike every other time in the database, `scw_*_time` / `sco_*_time` are wall-clock times in `sch_timezone`, not UTC instants — "9am Monday" must survive DST transitions. The slot generator converts wall-clock windows to UTC instants per concrete date.

### Slot generator

`includes/scheduling/SlotGenerator.php` — pure computation, no HTTP, unit-testable:

Inputs: schedule (windows + overrides + timezone), date range (UTC), slot duration, slot increment, buffer before/after, minimum notice, **busy blocks (the busy projection from the calendar-item registry)**.
Output: array of open slots (`['start' => UTC, 'end' => UTC]`).

Algorithm: expand weekly windows over the concrete dates (applying overrides, converting wall-clock → UTC per date), subtract busy blocks padded by buffers, anchor the increment grid to each window's start and emit duration-long slots that clear busy and pass min-notice. Per-period caps (max bookings/day/week) are **not** the generator's job — a consumer counts its own records and suppresses capped days.

### Availability editor

`/profile/bookings/availability` — a profile page over the core models: edit the subject's single availability — weekly windows (per-day rows of start/end ranges, FormWriter), date overrides, and timezone. Uses the calendar grid component to preview the resulting availability against the subject's existing calendar items. Self-documenting controls, no explainer prose.

The engine is core so any future feature can consume schedules. The editor is the engine's editing + testing surface; in the current build it ships within the bookings plugin's profile namespace (`/profile/bookings/availability`), since that is where it is nav-reachable — promoting it to a standalone core profile page is a file move if a second consumer materializes.

---

## Layer 3: Calendar UI Components

Two component types, HTML5/vanilla JS, universal (`css_framework` omitted), in `/views/components/`:

**`calendar_grid`** — month and week views of arbitrary timed items; the personal calendar's primary rendering surface. Programmatic rendering: `ComponentRenderer::render(null, 'calendar_grid', ['items' => [...], 'view' => 'month', ...])` where items are `['start','end','title','url','color','type']`. Optionally takes a `feed_url` for JSON loading + client-side month paging — the personal calendar feed serves the owner's aggregated items (`details` visibility). Emits a `calendardayclick` event for click-to-create.

**`slot_picker`** — a date strip/mini-month, an available time list, and a timezone selector (auto-detected via `Intl.DateTimeFormat().resolvedOptions().timeZone`, user-overridable). Loads slots from a JSON `slots_url`, emits the chosen slot into a hidden form field. Consumer: any "pick a time" flow (the public booking page is the first).

Both render times in the viewer's timezone; all wire data is UTC.

A **personal calendar page** (`/profile/calendar`) renders `calendar_grid` against the owner's aggregated item feed (`/ajax/calendar_feed`) — the home surface where events and native entries appear on one timeline, and where native entries are created and edited.

---

## Deletion Strategy (foreign-key actions, decided once)

- **Schedule** — one per subject; not independently deletable (it is the subject's availability, removed only when the subject is). Windows and overrides cascade with it.
- **Native calendar entry (`cal_items`)** — owned by the calendar; hard or soft delete removes it from the calendar and the busy projection. No external side effects (nothing else references it).
- **Subject deletion** — deletes the subject's schedules and native calendar entries via `$foreign_key_actions`. Because the subject columns aren't real foreign keys, this declarative cascade is what enforces owner cleanup. (The booking and external-integration specs add their own rows to this cascade.)

---

## Phases

Each phase lands working and tested before the next starts. Within each phase, work is broken into steps sized to be built, integrated, and verified independently; each step lists its **integration checkpoint**.

### Phase 1 — Personal calendar core

- **1.1 Subject identity + calendar item model.** `CalendarItem` value object, `CalendarSubject` resolver (`user` only). No tables yet.
  *Checkpoint:* unit tests construct items and resolve a user subject to name/timezone.
- **1.2 Item-source registry.** `CalendarItemSource` interface, auto-discovery registry, `EventItemSource` (leader + registrations, recurring via `get_instances_for_range()`), visibility handling at the projection boundary.
  *Checkpoint:* registry discovers sources; a subject's event commitments appear as items at `details` for the owner and as opaque blocks at `busy`.
- **1.3 Busy projection.** The projection function (items → `blocks_availability` → merged `{start,end}`).
  *Checkpoint:* unit tests prove the projection drops titles at `busy` and merges overlaps correctly.

### Phase 2 — Availability engine

- **2.1 Schedule data layer (scaffold-generated).** Write the three manifests (`schedule`, `schedule_window`, `schedule_override`, all `surfaces:["data"]`, subject-keyed with `unique_with` on `sch_schedules`) and run `php utils/scaffold.php` for each. Hand-fill the polymorphic `authenticate_*()` on `Schedule`. `update_database` creates the tables. Model CRUD tests in `/tests/`.
  *Checkpoint:* tables exist; tests create/load a user subject's one schedule with windows and overrides (and soft-delete windows/overrides).

> **⏸ PAUSE — verify the scaffold output before building on it.** This is the first real generation run, so it is also the proof the generator gives this spec what it needs. **Do not start 2.2 until the generated data classes are confirmed against this spec.** Check field names/types, `getMultiResults()` option keys, the polymorphic `authenticate_*()`, the `delete.foreign_key_actions` cascade, and that `php -l` + `validate_php_file.php` pass. If the output is wrong or insufficient, **fix the generator or the manifests and report back before continuing** — do not hand-patch the generated classes into shape.

- **2.2 SlotGenerator.** Pure computation per the algorithm above, consuming the busy projection. Unit tests must cover: DST spring-forward/fall-back, overrides replacing weekly windows, full-day blocks, buffer subtraction, min-notice filtering, increment vs. duration interplay, busy blocks spanning window edges.
  *Checkpoint:* test suite green; generator produces correct UTC slots for fixture schedules with seeded event busy time.
- **2.3 Availability editor.** `/profile/bookings/availability` — weekly windows editor, date overrides, timezone (FormWriter throughout). Plain table/list rendering for now; the calendar preview arrives in 3.2.
  *Checkpoint:* a user sets their availability with windows and a vacation override in the browser; rows verified in the DB.

### Phase 3 — Calendar UI components

- **3.1 `calendar_grid` component.** Month + week views, static `items` config and `feed_url` JSON mode, viewer-timezone rendering, vanilla JS.
  *Checkpoint:* a test page renders seeded events on a month grid; paging months works.
- **3.2 Personal calendar + editor preview.** `/profile/calendar` page fed by the owner's aggregated item feed; the availability editor previews windows-minus-busy on the grid.
  *Checkpoint:* a user sees their events on `/profile/calendar`; editing a window or adding an override visibly changes the editor preview.
- **3.3 `slot_picker` component.** Date strip + time list, timezone auto-detect/override, loads from `slots_url`, writes selection to a hidden field. Verified against a stub slots endpoint.
  *Checkpoint:* picker renders stub slots in multiple timezones and posts the chosen UTC slot.

### Phase 4 — Native personal entries

- **4.1 Native entry store + source.** Scaffold `cal_items` from a `surfaces:["data"]` manifest (subject-keyed; timed/all-day, `blocks_availability`, `visibility`, `type=personal`) and hand-fill its polymorphic auth; hand-write `NativeCalendarItemSource`, registered like any other source. Model CRUD tests.
  *Checkpoint:* a seeded native entry appears on the owner's aggregated feed and, when blocking, in the busy projection.
- **4.2 Authoring on the calendar.** Create / edit / delete native entries from `/profile/calendar` (FormWriter; click-to-create on the grid, edit existing). Busy entries remove availability.
  *Checkpoint:* a user blocks a window on their calendar in the browser; the block shows on the grid and that time disappears from their availability.

### Booking — separate spec

The native booking flow (booking types, public booking page, lifecycle, intake, paid, the scheduling-provider seam) is **out of scope for this spec** and lives in `specs/native_booking_flow.md`. It builds purely on the seams shipped above — the `CalendarItemSource` registry, the busy projection, `SlotGenerator`, and the calendar UI components — with no core rework.

### Later (separate specs when prioritized)

#### Recurring native entries
Repeat rules on `cal_items` (the Phase 4 store ships single instances only). Recurrence expansion can reuse the event system's `get_instances_for_range()` approach rather than a second engine.

#### Non-user subjects (resource / team / venue scheduling)
Implement additional `subject_type` values: room/equipment/venue calendars and team pools. The schema and interfaces already key on (`subject_type`, `subject_id`); this fills in the resolver and the editor surfaces.

---

## Files

**Generate (scaffold manifests → data classes, `surfaces:["data"]`):** committed manifest JSON for each new model, run through `php utils/scaffold.php`, then hand-fill the emitted auth/business stubs. Outputs: `data/schedule_class.php`, `data/schedule_window_class.php`, `data/schedule_override_class.php`, `data/calendar_entry_class.php`. The `Schedule` and `CalendarEntry` polymorphic owner-or-staff `authenticate_*()` is hand-filled.

**Create (core, hand-written):** `includes/calendar/CalendarItem.php` (value object — distinct from the stored `cal_items` model), `includes/calendar/CalendarSubject.php`, `includes/calendar/CalendarItemSource.php`, `includes/calendar/CalendarItemSourceRegistry.php`, `includes/calendar/item_sources/EventItemSource.php`, `includes/calendar/item_sources/NativeCalendarItemSource.php`, `includes/scheduling/SlotGenerator.php`, `views/components/calendar_grid.{json,php}`, `views/components/slot_picker.{json,php}`, the personal calendar view + native-entry editor (`/profile/calendar`), and the calendar feed ajax endpoint.

---

## Documentation

When implemented, current-state docs (no migration narration):

- New `docs/calendar.md` — the personal calendar: subject identity, the ownership model (native system-of-record vs. read-only projections), the calendar-item model, native entries, the `CalendarItemSource` registry and visibility/projection rules, the busy projection.
- New `docs/scheduling.md` — availability engine: schedules, the wall-clock-time exception, SlotGenerator, calendar UI components.
- Add the new docs to the CLAUDE.md documentation index via the admin agent-files editor (`/admin/admin_agent_files`).
