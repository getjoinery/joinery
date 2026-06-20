# Scheduling System — Personal Calendar + Booking Engine

## Overview

Build a **personal calendar** at the center of the platform, with booking as a feature layered on top — a calendar with booking features, not a booking system with a calendar view. Every time-bound thing in the system (events, bookings, tasks, renewals, and later external feeds) projects onto one timeline per subject. Availability and booking are *derived from* the calendar (a projection of it), never the other way around.

The design is layered so the general pieces land in core and only the appointment product lives in the bookings plugin:

1. **Personal calendar (core)** — a subject-owned timeline, a typed calendar-item model, and a `CalendarItemSource` registry through which every feature contributes items. "Busy time" is a derived projection of these items. This is the platform's central scheduling integration point.
2. **Availability engine (core)** — schedules, weekly windows, date overrides, and a slot generator that consumes the busy projection. General-purpose: usable by bookings, staff scheduling, resource booking, or any feature that needs "who is free when."
3. **Calendar UI components (core)** — a month/week calendar grid (the personal calendar's primary surface) and a slot picker, built as component-system types.
4. **Bookings plugin** — booking types, the public booking page, booking lifecycle (confirm / cancel / reschedule / remind), intake questions, paid bookings. Booking publishes availability and writes booking items back onto the calendar.
5. **Scheduling provider seam** — a pluggable `SchedulingServiceProvider` interface where the native engine is one implementation, so external services can slot in later without rearchitecting. This spec ships the seam and the native implementation only.

**Subject identity (decided up front).** A calendar — and the schedule that defines its working hours — belongs to a *schedulable subject*, not directly to a user. `user` is the only type implemented now; `resource`, `team`, and `venue` are reserved. The seam is deliberately **narrow**: a subject is carried as a single `CalendarSubject` value object, one resolver is the only code that knows subject types exist, and only **two tables** store an owner (`sch_schedules` and `cal_items`); booking types carry a plain `usr_users` host FK, not a subject key. Adding a subject type later means editing the resolver and two tables, not the codebase, so building it in now costs almost nothing and the future extension stays cheap.

**Ownership model (decided).** The calendar is a system of record only for items that originate in the calendar itself; everything else is a read-only projection it links out to, with no write-back and no two-way sync. The full rule and its consequences are in the Calendar Ownership Model section below.

**External integrations are a separate, deferred spec.** Importing from / connecting to Calendly, Acuity, Google, and Outlook lives in `specs/external_scheduling_integrations.md`. The target market is the "degoogle" crowd, so that work is import-first (migrate off the external service) and ongoing calendar sync is its lowest priority. The seams here are designed so all of it is additive — external calendars are just another `CalendarItemSource`, external booking backends just another provider.

**Supersedes:** `specs/calendly_integration.md` (deleted — it described fixing integration files that were removed in commit e1547005). `specs/calendly_feature_ideas.md` (deleted — Phase 5 below is the consolidated backlog).

**Pre-launch note:** The platform has no production users. Schema fields may be dropped/renamed freely; no data-preservation migrations are required. The 5 dev values in `usr_calendly_uri` are discarded.

---

## Integration-Point Inventory (decided up front)

Every platform system the calendar/scheduling feature touches, and how:

| System | Integration |
|---|---|
| **Events system** | Read-only projection: events the subject leads + their active registrations appear as calendar items (and thus in the busy projection) via `EventItemSource`. Recurring events project through `Event::get_instances_for_range()`. Bookings do NOT create `evt_events` rows — the two models stay parallel (fixed-time events vs. slot bookings). |
| **Component system** | Calendar grid (personal calendar surface) and slot picker as component types (programmatic rendering, HTML5/vanilla JS, universal framework). |
| **Questions/Surveys** | Intake questions on booking types via `bkt_svy_survey_id` (same pattern as `evt_svy_survey_id`). No new question-collection mechanism. |
| **Products/payments** | Paid bookings via the existing `bkt_pro_product_id` / `bkn_pro_product_id` links. Purchase-time data collection via Product Requirements. |
| **Email** (`SystemMailer` / `EmailSender`) | Confirmation, cancellation, reschedule, reminder, and follow-up emails as email templates. ICS invite attached to confirmations via `IcsHelper`. |
| **ICS / calendar-links** | `IcsHelper` for VEVENT generation; Spatie calendar-links for add-to-calendar buttons; per-booking ICS download; personal-calendar ICS export feed. |
| **Scheduled tasks** | Reminder/follow-up sender task (`BookingEmailsTask`). |
| **Routing** | Plugin view auto-discovery for most pages; one serve.php route for the vanity booking URL with placeholders. |
| **In-app notifications** | Notify host on new booking / cancellation. |
| **Analytics** | UTM capture at booking time (`bkn_utm_*` fields) feeding the existing visitor-events/conversion system. |
| **AI discovery** | Booking/BookingType/Schedule classes keep `ai_readable` flags with appropriate descriptions. |
| **OAuth2 core** (`includes/oauth/`) | Not used by the native engine. Google/Microsoft/Calendly OAuth is part of `specs/external_scheduling_integrations.md`. |
| **Future (not in this spec, seams reserved)** | Additional item sources (tasks, subscription renewals), non-user subjects (resource/team/venue), team scheduling (host-pool join table), workflow engine, embeddable widget. |

---

## Layer 1: Personal Calendar (core)

Lives in core (`/data/`, `/includes/calendar/`) because a calendar is a property of a subject, not of the bookings feature. This layer is the integration point the rest of the platform plugs into.

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

Auto-discovered (EmailSender-style registry) from core `includes/calendar/item_sources/` and active plugins' `includes/calendar_item_sources/`. Initial implementations:

- **EventItemSource** (core) — events where the subject is leader, plus their active registrations; recurring events via `Event::get_instances_for_range()` so virtual (unmaterialized) instances appear. `blocks_availability = true`, `visibility` honoured (titles shown to the owner, opaque to bookers).
- **BookingItemSource** (bookings plugin) — confirmed bookings where the subject is host, including buffer padding. `blocks_availability = true`.

External calendars (Google/Outlook) project through this same registry via an `ExternalCalendarItemSource` defined in `specs/external_scheduling_integrations.md`. The registry being the only coupling point is the whole reason this lives in core: a new source appears on every calendar and gates every availability calculation with no change to the generator, the grid, or any other source.

### Busy projection

There is exactly **one** upstream contract — items. "Busy time" is a *derived view*: aggregate `getItems(...)` across the registry at `busy` visibility, keep those with `blocks_availability === true`, reduce to `{start, end}`, and merge overlaps. This projection is the only thing the availability engine and `SlotGenerator` consume. One registration therefore yields both outcomes — the item shows on the calendar **and** blocks availability — instead of a feature implementing two separate things.

---

## Calendar Ownership Model

The calendar is a **system of record only for items that originate in the calendar itself.** Everything else is a **read-only projection** it links out to. There is no write-back to external systems and no two-way sync.

- **Native entries** (`type = personal`) — created directly on the calendar (a personal appointment, a block of busy time). Stored in `cal_items`, owned by the calendar, fully editable here. Exposed through the registry like any other source via `NativeCalendarItemSource`, so they appear on the calendar and — when `blocks_availability` — gate booking availability.
- **Projected items** (events, bookings, and later external feeds) — owned by their originating system. The calendar renders them and deep-links to them (`url`) but never edits or moves them. Rescheduling a booking happens in the booking flow; editing an event happens in the events system.

Consequences this locks in:

- `CalendarItemSource` stays a **read-only contract** — `getItems()` only, no write methods, no per-source mutation. The registry never grows a write path.
- **No sync engine, no conflict resolution** — every item has exactly one owner, so nothing is ever authoritative in two places.
- **No inline editing of projected items** — the deliberate concession. The calendar is a viewing + native-authoring surface; mutations to other systems route through those systems. (Drag-to-reschedule-anything would require the sync engine this design avoids.)
- "Block out time" is a native busy entry, not a fake event — the natural availability primitive.

Scope guards for native entries: start with single timed / all-day entries (recurrence deferred); do **not** fold schedule overrides into native entries — overrides shape the recurring availability *template*, native entries are concrete one-offs, and they stay separate models.

---

## Layer 2: Availability Engine (core)

Schedules define a subject's working hours; the slot generator turns hours-minus-busy into open slots. Pure, core, reusable.

### Data model

**`sch_schedules`** (Schedule / MultiSchedule, `data/schedules_class.php`) — **one row per subject** (unique on the subject): it *is* the subject's availability and the timezone anchor for their windows. Multiple named schedules per subject are a later addition (re-introduce a name + the per-type override) if ever wanted.
- `sch_schedule_id` (pk)
- `sch_subject_type` / `sch_subject_id` — the owning `CalendarSubject` (`user` only now; `resource` / `team` / `venue` reserved), **unique together**. One of only two owner-bearing tables; see *Schedulable subject* for the no-FK trade-off.
- `sch_timezone` — IANA timezone the windows are defined in
- `sch_create_time`, `sch_update_time`, `sch_delete_time`

**`scw_schedule_windows`** (ScheduleWindow / MultiScheduleWindow)
- `scw_schedule_window_id` (pk)
- `scw_sch_schedule_id` (fk)
- `scw_day_of_week` — 0=Sunday … 6=Saturday (matches `sct_schedule_day_of_week` convention)
- `scw_start_time`, `scw_end_time` — PostgreSQL `time` (wall-clock in the schedule's timezone)
- Validation: `scw_end_time` must be after `scw_start_time`. Windows do not cross midnight — an overnight span is entered as two windows on adjacent days. (Same rule for override times.)

**`sco_schedule_overrides`** (ScheduleOverride / MultiScheduleOverride)
- `sco_schedule_override_id` (pk)
- `sco_sch_schedule_id` (fk)
- `sco_date` — the date being overridden (in the schedule's timezone)
- `sco_start_time`, `sco_end_time` — nullable `time`
- Semantics: if any override rows exist for a date, they **replace** the weekly windows for that date. A single row with null start/end = fully unavailable that date. Rows with times = that date's windows.

**Timezone exception (document explicitly):** unlike every other time in the database, `scw_*_time` / `sco_*_time` are wall-clock times in `sch_timezone`, not UTC instants — "9am Monday" must survive DST transitions. The slot generator converts wall-clock windows to UTC instants per concrete date.

### Slot generator

`includes/scheduling/SlotGenerator.php` — pure computation, no HTTP, unit-testable:

Inputs: schedule (windows + overrides + timezone), date range (UTC), slot duration, slot increment, buffer before/after, minimum notice, **busy blocks (the busy projection from the calendar-item registry)**.
Output: array of open slots (`['start' => UTC, 'end' => UTC]`).

Algorithm: expand weekly windows over the concrete dates (applying overrides, converting wall-clock → UTC per date), subtract busy blocks padded by buffers, walk each free range in increment steps emitting slots of the requested duration, drop slots starting before `now + min_notice`.

Per-period caps (max bookings/day/week) are **not** the generator's job — the bookings layer counts its own bookings and suppresses slots on capped days before rendering. Days and weeks for cap counting are bounded in the schedule's timezone.

### Availability editor

`/profile/bookings/availability` — a **bookings plugin** view over the core models. The engine is core so any future feature can consume schedules, but a nav-reachable page belongs to the feature that gives it meaning: with bookings inactive there would be nothing to define hours *for*. The editor ships with the plugin, gated by `bookings_active`; if a second consumer materializes later, promoting the page to core is a file move with no data migration.

The page: edit the subject's single availability — weekly windows (per-day rows of start/end ranges, FormWriter), date overrides, and timezone. Uses the calendar grid component to preview the resulting availability against the subject's existing calendar items. Self-documenting controls, no explainer prose.

---

## Layer 3: Calendar UI Components

Two component types, HTML5/vanilla JS, universal (`css_framework` omitted), in `/views/components/`:

**`calendar_grid`** — month and week views of arbitrary timed items; the personal calendar's primary rendering surface. Programmatic rendering: `ComponentRenderer::render(null, 'calendar_grid', ['items' => [...], 'view' => 'month', ...])` where items are `['start','end','title','url','color','type']`. Optionally takes a `feed_url` for JSON loading + client-side month paging — the personal calendar feed serves the owner's aggregated items (`details` visibility). Consumers: the personal calendar page, availability editor preview, admin bookings calendar.

**`slot_picker`** — the booking UI: date strip/mini-month on the left, available time list on the right, timezone selector (auto-detected via `Intl.DateTimeFormat().resolvedOptions().timeZone`, user-overridable). Loads slots from a JSON `slots_url`, emits the chosen slot into a hidden form field. Consumer: public booking page (and any future "pick a time" flow).

Both render times in the viewer's timezone; all wire data is UTC.

A **personal calendar page** (`/profile/calendar`) renders `calendar_grid` against the owner's aggregated item feed — the home surface where events, bookings, and native entries appear on one timeline, and where native entries are created and edited.

---

## Layer 4: Bookings Plugin (native flow)

Booking is a feature on top of the calendar: it publishes a subject's availability (the projection) and writes confirmed bookings back as calendar items via `BookingItemSource`.

### Schema changes

**`bkt_booking_types`** — remove `bkt_calendly_event_type_uri`; add:

| Field | Type | Purpose |
|---|---|---|
| `bkt_provider` | varchar(32), default `native` | Scheduling provider key |
| `bkt_external_type_uri` | varchar(255), nullable | Provider's event-type identifier (external providers) |
| `bkt_usr_user_id` | int8 | Host (FK to `usr_users`); availability is the host's one schedule |
| `bkt_duration_minutes` | int4 | Meeting length |
| `bkt_slot_increment_minutes` | int4, default 30 | Slot start-time granularity |
| `bkt_buffer_before_minutes` / `bkt_buffer_after_minutes` | int4, default 0 | Padding around bookings |
| `bkt_min_notice_minutes` | int4, default 240 | Earliest bookable offset from now |
| `bkt_rolling_days` | int4, default 60 | How far ahead slots are offered |
| `bkt_window_start` / `bkt_window_end` | date, nullable | Fixed booking window (overrides rolling) |
| `bkt_max_per_day` / `bkt_max_per_week` | int4, nullable | Booking caps |
| `bkt_location_mode` | varchar(32) | `none` / `in_person` / `phone` / `video` / `custom` |
| `bkt_location_details` | text | Address, static link, or instructions |
| `bkt_svy_survey_id` | int8, nullable | Intake survey |
| `bkt_cancel_notice_minutes` | int4, nullable | Minimum notice for invitee cancel/reschedule |
| `bkt_cancellation_policy_text` | text | Shown on booking page and enforced |
| `bkt_send_native_emails` | bool, default true | Off by default for external providers (they send their own) |

**`bkn_bookings`** — rename `bkn_calendly_event_uri` → `bkn_external_uri`; drop redundant `bkn_type`; add `bkn_provider`, `bkn_invitee_timezone`, `bkn_action_token` (random token for invitee cancel/reschedule links), `bkn_hold_expires_time` (paid pending holds), `bkn_canceled_by` / `bkn_cancel_reason`, `bkn_is_no_show`, `bkn_utm_source/medium/campaign/content/term`. Status: `CREATED` (0) serves as the pending-hold state; a new `NEEDS_ATTENTION` constant covers paid bookings whose slot was lost during checkout.

`bkt_slug` gains a **global unique constraint** — it is the public booking URL.

**Core `usr_users`** — drop `usr_calendly_uri` (field, edit form, logic). Per-host external-provider identity is handled by the connections table in the external-integrations spec, not by a column on the user.

### Public booking flow

- **Vanity URL:** `/book/{slug}` — one serve.php placeholder route resolving the booking type (and through it the host) by globally-unique `bkt_slug`. The platform has no public username on users and this spec does not introduce one; a per-host listing page (`/book/{username}`) can come if public user handles ever exist for their own reasons.
- **Slots endpoint:** plugin ajax endpoint returning JSON slots for a type + month, backed by SlotGenerator + the busy projection + per-period caps. Public, read-only, rate-limit-friendly (no session required). The projection is requested at `busy` visibility — no item titles ever reach a public caller.
- **Booking page:** slot_picker component + FormWriter form (name, email, additional notes), intake survey questions rendered inline via `Question::output_question()`. Invitee does not need an account; an inactive user record is created/matched by email.
- **Booking creation is race-safe:** creation runs in a transaction holding a per-host advisory lock and re-runs the slot conflict check (the busy projection + caps) before inserting. If the slot is gone, the invitee is returned to the picker with a refreshed slot list. Two simultaneous submissions for one slot must produce exactly one booking. (When the external-calendar item source is later added, it participates in this same projection and re-check.)
- **Paid types:** if `bkt_pro_product_id` is set, the chosen slot is held — a `CREATED` booking with `bkn_hold_expires_time` 15 minutes out (mirroring `evr_expires_time` temporary reserves) — and the user is sent through the product purchase flow. Held slots count as busy (the `BookingItemSource` projects holds), so other viewers never see them. Confirmation fires on purchase completion via the product purchase hooks and re-runs the same locked conflict check: if the slot was lost, the booking lands in `NEEDS_ATTENTION`, the host is notified, and the payment follows the standard refund path. Expired unpaid holds release automatically.
- **Confirmation:** booking row (status BOOKED), confirmation email to invitee + host (ICS attached, add-to-calendar links, cancel/reschedule links containing `bkn_action_token`), in-app notification to host. The booking now appears on the host's personal calendar via `BookingItemSource`.

### Invitee self-service cancel / reschedule

`/booking/manage?token={bkn_action_token}` — shows the booking; cancel (with reason) or reschedule (slot_picker for the same type; old slot released, new confirmed). Enforces `bkt_cancel_notice_minutes` and displays the policy text. No login required; the token is the credential.

### Host and admin cancellation

Hosts cancel their own bookings from the "my bookings" profile page (with reason); admins from the booking detail page. Either way the other party is emailed — an invitee whose booking was canceled gets a "pick a new time" link to the booking type. The notice-minutes rule does not bind hosts/admins.

### Reminders and follow-ups

A `BookingEmailsTask` scheduled task (every_run frequency): sends reminder emails at configured offsets before start (`bkt_reminder_minutes_csv`, default "1440,60"), and a follow-up after end. Sends are recorded (a `bke_booking_emails` log table) so the task is idempotent; the log key is booking + offset + **booking start time**, so a rescheduled booking earns fresh reminders for its new time. Suppressed when `bkt_send_native_emails` is false or the booking is canceled/no-show.

### Admin

Rework the existing read-only pages into full CRUD: booking type edit (FormWriter, `visibility_rules` for provider-/location-dependent fields), bookings list with status filters + calendar_grid view, booking detail with cancel / mark-no-show actions.

---

## Layer 5: Scheduling Provider Abstraction

### Interface

`plugins/bookings/includes/SchedulingServiceProvider.php`, registry auto-discovers implementations in `plugins/bookings/includes/scheduling_providers/` (same discovery pattern as `EmailServiceProvider` / `includes/email_providers/`):

```php
interface SchedulingServiceProvider {
    public static function getKey(): string;          // 'native' | 'calendly' | 'acuity'
    public static function getLabel(): string;
    /** 'headless' — Joinery renders slots and forms; 'embed' — provider widget + webhook ingestion */
    public static function getMode(): string;
    /** Connection fields for API-key providers; OAuth providers return [] and supply a connect URL */
    public static function getConnectionFields(): array;
    public static function getConnectUrl(?int $user_id): ?string;   // OAuth consent URL or null

    public function listEventTypes(ProviderConnection $conn): array;   // for import/link UI

    // Headless mode only — embed providers never receive these calls:
    public function getAvailableSlots(BookingType $type, string $start_utc, string $end_utc): array;
    public function createBooking(BookingType $type, array $invitee, string $slot_start_utc): Booking;
    public function cancelBooking(Booking $booking, string $reason): bool;

    // Embed mode only:
    public function getEmbedHtml(BookingType $type, array $tracking): string;

    // Change ingestion (any provider that pushes updates):
    public function registerWebhooks(ProviderConnection $conn): void;
    public function verifyWebhook(array $headers, string $raw_body, ProviderConnection $conn): bool;
    public function handleWebhook(array $payload, ProviderConnection $conn): void;
}
```

Two integration modes, declared per provider by `getMode()`:

- **Headless**: Joinery renders its own slot picker and booking form; the provider is just the availability/booking backend. The invitee never sees the provider's UI.
- **Embed**: the booking page embeds the provider's widget with tracking parameters carrying the local booking-type id; webhooks ingest the resulting booking into `bkn_bookings`.

The booking page logic branches once on mode; everything downstream (booking rows, calendar items, admin, analytics, client history) is provider-agnostic. Webhook ingestion is **idempotent on the external URI** — duplicate deliveries are no-ops, and a cancellation arriving before (or without) its creation parks for the reconciliation sync task instead of erroring.

When a connection breaks (token revoked, refresh failure, key rejected), it is marked errored and the host notified. Headless types backed by an errored connection render a "scheduling temporarily unavailable" booking page, never a broken picker; embed types keep working (the widget doesn't depend on our token).

### Native provider

`NativeSchedulingProvider` — mode `headless`, implemented on SlotGenerator and the plugin's own booking creation. Needs no connection row. Default for all booking types; the abstraction costs native users nothing. It is the **only** provider this spec ships.

### What this spec does not build

The interface above is the full contract — including `embed` mode, webhook ingestion, and `listEventTypes()` — so external providers slot in without reopening it. But the things only externals need are out of scope here and live in `specs/external_scheduling_integrations.md`:

- the `ProviderConnection` model (`bpc_provider_connections`) and SecretBox-encrypted credential storage,
- the `/profile/bookings/connections` connect + import UI,
- the Calendly and Acuity provider implementations and their OAuth/API-key consumers,
- the `plugins/bookings/ajax/webhook_{provider}.php` endpoints and the reconciliation sync task.

The native engine references none of these at runtime; a booking type's `bkt_provider` simply defaults to `native`.

---

## Deletion Strategy (foreign-key actions, decided once)

- **Schedule** — one per subject; not independently deletable (it is the subject's availability, removed only when the subject is). Windows and overrides cascade with it. Booking types reference the host directly (`bkt_usr_user_id`), not the schedule, so there is nothing to orphan.
- **BookingType** — soft delete stops new bookings; existing future bookings stand (reminders and manage links keep working).
- **Booking** — soft delete only; canceled bookings keep their rows for history and analytics. The `BookingItemSource` stops projecting canceled/deleted bookings, so they leave the calendar automatically.
- **Native calendar entry (`cal_items`)** — owned by the calendar; hard or soft delete removes it from the calendar and the busy projection. No external side effects (nothing else references it).
- **Subject as host** — deletion cancels their future bookings (invitees notified) and deletes their schedules and native calendar entries via `$foreign_key_actions`. Because the subject columns aren't real foreign keys, this declarative cascade is what enforces owner cleanup. (External calendar/provider connections add to this cascade in the external-integrations spec.)
- **User as invitee** — deletion cancels their future bookings (host notified).

---

## Phases

Each phase lands working and tested before the next starts. The provider interface is defined in the booking phase even though only `native` exists then — externals slot in without rearchitecting.

Within each phase, the work is broken into steps sized to be built, integrated, and verified independently. Every step ends in a state that runs on dev — no step leaves another step's work half-wired. Each step lists its **integration checkpoint**: what is observably working when the step is done.

### Phase 1 — Personal calendar core

- **1.1 Subject identity + calendar item model.** `CalendarItem` value object, `CalendarSubject` resolver (`user` only). No tables yet.
  *Checkpoint:* unit tests construct items and resolve a user subject to name/timezone.
- **1.2 Item-source registry.** `CalendarItemSource` interface, auto-discovery registry, `EventItemSource` (leader + registrations, recurring via `get_instances_for_range()`), visibility handling at the projection boundary. Tests with seeded events including a recurring parent.
  *Checkpoint:* registry discovers sources; a subject's event commitments appear as items at `details` for the owner and as opaque blocks at `busy`.
- **1.3 Busy projection.** The projection function (items → `blocks_availability` → merged `{start,end}`).
  *Checkpoint:* unit tests prove the projection drops titles at `busy` and merges overlaps correctly.

### Phase 2 — Availability engine

- **2.1 Schedule data layer.** `Schedule`/`ScheduleWindow`/`ScheduleOverride` classes (+ Multi classes, subject-keyed), `update_database` creates the tables. Model CRUD tests in `/tests/models/`.
  *Checkpoint:* tables exist; tests create/load a user subject's one schedule with windows and overrides (and soft-delete windows/overrides).
- **2.2 SlotGenerator.** Pure computation per the algorithm above, consuming the busy projection. Unit tests must cover: DST spring-forward/fall-back dates, overrides replacing weekly windows, full-day blocks, buffer subtraction, min-notice filtering, increment vs. duration interplay, busy blocks spanning window edges.
  *Checkpoint:* test suite green; generator produces correct UTC slots for fixture schedules with seeded event busy time.
- **2.3 Availability editor.** `/profile/bookings/availability` (plugin view over the core models) — weekly windows editor, date overrides, timezone (FormWriter throughout). Plain table/list rendering for now; the calendar preview arrives in 3.2.
  *Checkpoint:* a user sets their availability with windows and a vacation override in the browser; rows verified in the DB.

### Phase 3 — Calendar UI components

- **3.1 `calendar_grid` component.** Month + week views, static `items` config and `feed_url` JSON mode, viewer-timezone rendering, vanilla JS.
  *Checkpoint:* a test page renders seeded events on a month grid; paging months works.
- **3.2 Personal calendar + editor preview.** `/profile/calendar` page fed by the owner's aggregated item feed; the availability editor previews windows-minus-busy on the grid.
  *Checkpoint:* a user sees their events on `/profile/calendar`; editing a window or adding an override visibly changes the editor preview.
- **3.3 `slot_picker` component.** Date strip + time list, timezone auto-detect/override, loads from `slots_url`, writes selection to a hidden field. Verified against a stub slots endpoint.
  *Checkpoint:* picker renders stub slots in multiple timezones and posts the chosen UTC slot.

### Phase 4 — Native personal entries

- **4.1 Native entry store + source.** `cal_items` (subject-keyed; timed/all-day, `blocks_availability`, `visibility`, `type=personal`), `NativeCalendarItemSource` registered like any other source. Model CRUD tests.
  *Checkpoint:* a seeded native entry appears on the owner's aggregated feed and, when blocking, in the busy projection.
- **4.2 Authoring on the calendar.** Create / edit / delete native entries from `/profile/calendar` (FormWriter; click-to-create on the grid, edit existing). Busy entries remove availability.
  *Checkpoint:* a user blocks Tuesday 2–4pm on their calendar in the browser; the block shows on the grid and that time disappears from their booking slots.

### Phase 5 — Native booking flow (the usable MVP)

- **5.1 Plugin schema + provider seam.** Booking/BookingType field rework (add/rename/drop per Schema changes above), drop `usr_calendly_uri` from core, `SchedulingServiceProvider` interface + registry + `NativeSchedulingProvider` (slots via SlotGenerator; creation lands in 5.4).
  *Checkpoint:* plugin sync applies the schema; registry returns the native provider; validator passes on all touched files.
- **5.2 Admin booking-type CRUD.** Full create/edit form (duration, host, buffers, notice, window, caps, location via `visibility_rules`). Needed now — every later step requires a configurable type.
  *Checkpoint:* an admin creates a working booking type bound to its host in the browser.
- **5.3 Public slot browsing.** Slots JSON endpoint (generator + busy projection at `busy` visibility + per-period caps), serve.php vanity route, booking page rendering `slot_picker` from the real endpoint.
  *Checkpoint:* visiting `/book/{slug}` shows genuinely open times; booking-capped days and busy times are absent; no item titles leak to the endpoint.
- **5.4 Booking creation (free types).** Invitee form (name/email/notes), user match-or-create by email, race-safe creation (per-host advisory lock + conflict re-check), BOOKED row, `BookingItemSource` (with buffers), confirmation emails to both sides with ICS + add-to-calendar, host in-app notification.
  *Checkpoint:* end-to-end booking on dev; both emails arrive (verify via `iem_inbound_email_messages`); the slot disappears from the picker; the booking shows on the host's `/profile/calendar`; two concurrent submissions for one slot produce exactly one booking.
- **5.5 Invitee self-service.** `/booking/manage?token=…` — cancel with reason, reschedule via slot_picker, `bkt_cancel_notice_minutes` enforcement, policy text display, notification emails.
  *Checkpoint:* cancel and reschedule both work from the email links; too-late cancellation is refused.
- **5.6 Reminders + follow-ups.** `BookingEmailsTask` (every_run), `bke_booking_emails` send log for idempotency, suppression rules (canceled, no-show, `bkt_send_native_emails` off).
  *Checkpoint:* task run against a near-future booking sends exactly one reminder; rerun sends nothing.
- **5.7 Intake surveys.** `bkt_svy_survey_id` rendering inline on the booking form, answers stored against the invitee user, shown on the admin booking detail.
  *Checkpoint:* booked with intake answers; answers visible in admin.
- **5.8 Paid bookings.** 15-minute slot hold (hidden from other viewers), product purchase flow handoff, confirmation via purchase hooks with locked conflict re-check (`NEEDS_ATTENTION` + host notification + refund path on conflict), hold expiry releasing the slot.
  *Checkpoint:* Stripe test purchase confirms a booking; an abandoned hold expires and the slot returns.
- **5.9 Admin and host operations.** Bookings list with status filters + `calendar_grid` view, booking detail with cancel / mark-no-show, host "my bookings" profile page with host-side cancel (invitee notified with a rebook link).
  *Checkpoint:* admin sees bookings on a calendar and can cancel and mark no-show; a host cancel emails the invitee.

### External integrations (deferred — separate spec)

External calendar sync (Google/Outlook busy-read + write-back) and external scheduling providers (Calendly import/embed, Acuity) are **out of scope for this spec.** They are designed import-first for the degoogle market in `specs/external_scheduling_integrations.md` and build purely on the seams shipped above (the `CalendarItemSource` registry and the `SchedulingServiceProvider` interface) — no native rework.

### Phase 6 — Later (separate specs when prioritized)

The items below are not in scope for Phases 1–5. Each warrants its own spec before implementation begins.

#### Recurring native entries
Repeat rules on `cal_items` (the Phase 4 store ships single instances only). Recurrence expansion can reuse the event system's `get_instances_for_range()` approach rather than a second engine.

#### Non-user subjects (resource / team / venue scheduling)
Implement additional `subject_type` values: room/equipment/venue calendars and team pools. The schema and interfaces already key on (`subject_type`, `subject_id`); this fills in the resolver and the editor surfaces.

#### Waitlist auto-promotion
When a confirmed booking is canceled, the first waiter for that slot gets a time-limited claim link by email (token-gated, same mechanism as `bkn_action_token`). If unclaimed within the window, the next waiter is notified. The waitlist queue has a natural sort order by `bkn_create_time` on `WAITLISTED` rows.

#### Event workflow / automation engine
A trigger-action table: trigger (booking created / canceled / rescheduled / no-show), offset (e.g., −1440 minutes = 24 hours before start), channel (email / SMS), template. `BookingEmailsTask` already handles fixed offsets from config fields; the workflow engine generalizes this to admin-configurable per-type rules.

#### Team scheduling (round robin / collective)
A `bkt_booking_type_hosts` join table adds a pool of hosts to a type. **Round robin:** slot generator unions availability across hosts and assigns each booking to the next host in rotation (by booking count). **Collective:** slot generator intersects availability — only times all required hosts are simultaneously free are offered. Both require SlotGenerator to accept multiple subjects' projections.

#### Embeddable booking widget
A standalone JS component (`/book/{slug}/widget.js`) that renders the slot picker and booking form inside any `<div data-joinery-booking="slug">` on an external site. The slots endpoint is already public and the booking form is already HTML5/vanilla JS — the main work is packaging and cross-origin handling.

#### Booking analytics dashboard
Queries over `bkn_bookings` and `bke_booking_emails`: booking counts, completion rate, cancellation rate, no-show rate, top booking times, per-type performance. Filterable by type, host, date range. New admin page with Chart.js (already present). UTM fields (`bkn_utm_*`) feed attribution breakdowns.

#### SMS reminders
Twilio (or similar) integration, SMS consent captured at booking, and an SMS channel in the workflow engine (above). Meaningful only after the workflow engine is in place.

#### Video conferencing link auto-creation
On booking confirmation, create a unique meeting link via the Zoom API, Google Meet (Calendar API), or Teams (Graph API) and store it in a new `bkn_conferencing_url` field, included in confirmation emails and the booking detail. Google Meet is the lowest-friction path — a calendar event created via the Calendar API (wired by the external-integrations spec's calendar write-back) automatically carries a Meet link. Zoom and Teams require separate OAuth integrations.

#### Add guests at booking
An "additional attendees" field on the booking form (up to N email addresses), stored in a `bkg_booking_guests` table. All guests receive confirmation and reminder emails and appear in the calendar write-back attendee list.

#### Booking page branding
Per-type customization fields: `bkt_cover_image_id`, `bkt_accent_color`, `bkt_welcome_message`. Rendered on the public booking page.

#### Attendee check-in
`bkn_checked_in` (bool) + `bkn_checked_in_time` on bookings. Admin or mobile-friendly check-in UI; optionally a QR code per booking (signed token) for invitee self-check-in.

#### Routing forms
A pre-booking questionnaire with conditional branching: based on answers, route the invitee to a different booking type, host, or external URL. Builds on the existing questions system but adds a routing-rules table.

---

## Files

**Create (core):** `includes/calendar/CalendarItem.php`, `includes/calendar/CalendarSubject.php`, `includes/calendar/CalendarItemSource.php`, `includes/calendar/CalendarItemSourceRegistry.php`, `includes/calendar/item_sources/EventItemSource.php`, `includes/calendar/item_sources/NativeCalendarItemSource.php`, `data/calendar_items_class.php`, `data/schedules_class.php`, `data/schedule_windows_class.php`, `data/schedule_overrides_class.php`, `includes/scheduling/SlotGenerator.php`, `views/components/calendar_grid.{json,php}`, `views/components/slot_picker.{json,php}`, personal calendar view + native-entry editor (`/profile/calendar`).

**Create (plugin):** `includes/SchedulingServiceProvider.php`, `includes/SchedulingProviderRegistry.php`, `includes/scheduling_providers/NativeSchedulingProvider.php`, `includes/calendar_item_sources/BookingItemSource.php`, `data/booking_emails_class.php`, public views (`views/book/…`, manage page), profile views (availability editor, my bookings), ajax (slots endpoint, personal calendar feed), `tasks/BookingEmailsTask.{json,php}`, reworked admin pages.

**Modify:** `plugins/bookings/data/bookings_class.php`, `booking_types_class.php` (schema above), `plugin.json` (settings, menu), `serve.php` (vanity route), `data/users_class.php` + `adm/admin_users_edit.php` + logic (drop `usr_calendly_uri`), `views/booking.php` + `theme/tailwind/views/booking.php` (replace placeholder/dead embed with the native flow).

**Delete:** `specs/calendly_integration.md` (done alongside this spec).

External-integration files (`calendar_connections`, `provider_connections`, `ExternalCalendarItemSource`, Calendly/Acuity providers, OAuth consumers, webhook endpoints, `ProviderSyncTask`, connection UIs, Calendly settings) are listed in `specs/external_scheduling_integrations.md`.

---

## Documentation

When implemented, current-state docs (no migration narration):

- New `docs/calendar.md` — the personal calendar: subject identity, the ownership model (native system-of-record vs. read-only projections), the calendar-item model, native entries, the `CalendarItemSource` registry and visibility/projection rules, the busy projection.
- New `docs/scheduling.md` — availability engine: schedules, the wall-clock-time exception, SlotGenerator, calendar UI components.
- New `plugins/bookings/docs/overview.md` — booking types, public flow, the native provider, and the `SchedulingServiceProvider` seam (noting external implementations live in the external-integrations spec).
- Update `docs/scheduled_tasks.md` task list if it enumerates tasks.
- Add the new docs to the CLAUDE.md documentation index via the admin agent-files editor (`/admin/admin_agent_files`).
