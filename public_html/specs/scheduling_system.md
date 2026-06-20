# Scheduling System — Native Booking Engine

## Overview

Build a complete appointment-scheduling capability (Calendly-class) on the Joinery platform — self-hosted, no external dependency. The design is layered so the general pieces land in core and only the appointment product lives in the bookings plugin:

1. **Core availability engine** — schedules, weekly windows, date overrides, busy-time aggregation, and a slot generator. General-purpose: usable by bookings, staff scheduling, resource booking, or any future feature that needs "who is free when."
2. **Core calendar UI components** — a month/week calendar grid and a slot picker, built as component-system types. Usable by any feature that renders timed items.
3. **Bookings plugin** — booking types, the public booking page, booking lifecycle (confirm / cancel / reschedule / remind), intake questions, paid bookings.
4. **Scheduling provider seam** — a pluggable `SchedulingServiceProvider` interface and busy-source registry where the native engine is one implementation, so external services can slot in later without rearchitecting. This spec ships the seam and the native implementation only.

**External integrations are a separate, deferred spec.** Importing from / connecting to Calendly, Acuity, Google, and Outlook lives in `specs/external_scheduling_integrations.md`. The target market is the "degoogle" crowd, so that work is import-first (migrate off the external service) and ongoing calendar sync is its lowest priority. The seams here are designed so all of it is additive — see Layer 1's busy-source registry and Layer 4's provider interface.

**Supersedes:** `specs/calendly_integration.md` (deleted — it described fixing integration files that were removed in commit e1547005). `specs/calendly_feature_ideas.md` (deleted — Phase 6 below is the consolidated backlog).

**Pre-launch note:** The platform has no production users. Schema fields may be dropped/renamed freely; no data-preservation migrations are required. The 5 dev values in `usr_calendly_uri` are discarded.

---

## Integration-Point Inventory (decided up front)

Every platform system the scheduling feature touches, and how:

| System | Integration |
|---|---|
| **OAuth2 core** (`includes/oauth/`) | Not used by the native engine. Google/Microsoft/Calendly OAuth is part of `specs/external_scheduling_integrations.md`. |
| **Scheduled tasks** | Reminder/follow-up sender task (`BookingEmailsTask`). |
| **Questions/Surveys** | Intake questions on booking types via `bkt_svy_survey_id` (same pattern as `evt_svy_survey_id`). No new question-collection mechanism. |
| **Products/payments** | Paid bookings via the existing `bkt_pro_product_id` / `bkn_pro_product_id` links. Purchase-time data collection via Product Requirements. |
| **Email** (`SystemMailer` / `EmailSender`) | Confirmation, cancellation, reschedule, reminder, and follow-up emails as email templates. ICS invite attached to confirmations via `IcsHelper`. |
| **ICS / calendar-links** | `IcsHelper` for VEVENT generation; Spatie calendar-links for add-to-calendar buttons; per-booking ICS download. |
| **Events system** | Read-only: event registrations and event-leader slots count as busy time via a busy source. Bookings do NOT create `evt_events` rows — the two models stay parallel (fixed-time events vs. slot bookings). |
| **Component system** | Calendar grid and slot picker as component types (programmatic rendering, HTML5/vanilla JS, universal framework). |
| **Routing** | Plugin view auto-discovery for most pages; one serve.php route for the vanity booking URL with placeholders. |
| **In-app notifications** | Notify host on new booking / cancellation. |
| **Analytics** | UTM capture at booking time (`bkn_utm_*` fields) feeding the existing visitor-events/conversion system. |
| **AI discovery** | Booking/BookingType classes keep `ai_readable` flags; new schedule classes get appropriate descriptions. |
| **Future (not in this spec, seams reserved)** | SMS reminders (channel column on reminder config), team scheduling (host-pool join table), workflow engine (trigger/offset/template table), embeddable widget (slots JSON endpoint is already public). |

---

## Layer 1: Core Availability Engine

Lives in core (`/data/`, `/includes/scheduling/`) because availability is a property of users, not of the bookings feature.

### Data model

**`sch_schedules`** (Schedule / MultiSchedule, `data/schedules_class.php`)
- `sch_schedule_id` (pk)
- `sch_usr_user_id` — owner
- `sch_name` — e.g. "Working hours" (a user may have several named schedules)
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

### Busy-time sources

`includes/scheduling/BusySourceInterface.php`:

```php
interface BusySourceInterface {
    public static function getKey(): string;
    /** @return array of ['start' => UTC string, 'end' => UTC string] */
    public function getBusyBlocks(int $user_id, string $start_utc, string $end_utc): array;
}
```

Auto-discovered (EmailSender-style registry) from core `includes/scheduling/busy_sources/` and active plugins' `includes/busy_sources/`. Initial implementations:

- **EventBusySource** (core) — events where the user is leader, plus their active registrations. Recurring events contribute through `Event::get_instances_for_range()` so virtual (unmaterialized) instances count as busy, not just materialized rows.
- **BookingBusySource** (bookings plugin) — confirmed bookings where the user is host, including buffer padding.

External calendars (Google/Outlook) contribute busy time through this same registry via an `ExternalCalendarBusySource` defined in `specs/external_scheduling_integrations.md`. Because the registry is the only coupling point, that source slots in with no change to the generator or any other busy source — the seam is the whole reason it lives here in core.

### Slot generator

`includes/scheduling/SlotGenerator.php` — pure computation, no HTTP, unit-testable:

Inputs: schedule (windows + overrides + timezone), date range (UTC), slot duration, slot increment, buffer before/after, minimum notice, busy blocks (merged from all sources).
Output: array of open slots (`['start' => UTC, 'end' => UTC]`).

Algorithm: expand weekly windows over the concrete dates (applying overrides, converting wall-clock → UTC per date), subtract busy blocks padded by buffers, walk each free range in increment steps emitting slots of the requested duration, drop slots starting before `now + min_notice`.

Per-period caps (max bookings/day/week) are **not** the generator's job — the bookings layer counts its own bookings and suppresses slots on capped days before rendering. Days and weeks for cap counting are bounded in the schedule's timezone.

### Availability editor

`/profile/bookings/availability` — a **bookings plugin** view over the core models. The engine is core so any future plugin can consume schedules without depending on bookings, but a nav-reachable page belongs to the feature that gives it meaning: with bookings inactive there would be nothing to define hours *for*. The editor ships with the plugin, gated by `bookings_active`; if a second consumer materializes later, promoting the page to core is a file move with no data migration.

The page: list the user's schedules; edit a schedule's weekly windows (per-day rows of start/end ranges, FormWriter) and date overrides. Uses the calendar grid component to preview the resulting availability. Self-documenting controls, no explainer prose.

---

## Layer 2: Calendar UI Components

Two component types, HTML5/vanilla JS, universal (`css_framework` omitted), in `/views/components/`:

**`calendar_grid`** — month and week views of arbitrary timed items. Programmatic rendering: `ComponentRenderer::render(null, 'calendar_grid', ['items' => [...], 'view' => 'month', ...])` where items are `['start','end','title','url','color']`. Optionally takes a `feed_url` for JSON loading + client-side month paging. Consumers: availability editor preview, admin bookings calendar, future admin events calendar.

**`slot_picker`** — the Calendly-style booking UI: date strip/mini-month on the left, available time list on the right, timezone selector (auto-detected via `Intl.DateTimeFormat().resolvedOptions().timeZone`, user-overridable). Loads slots from a JSON `slots_url`, emits the chosen slot into a hidden form field. Consumer: public booking page (and any future "pick a time" flow).

Both render times in the viewer's timezone; all wire data is UTC.

---

## Layer 3: Bookings Plugin (native flow)

### Schema changes

**`bkt_booking_types`** — remove `bkt_calendly_event_type_uri`; add:

| Field | Type | Purpose |
|---|---|---|
| `bkt_provider` | varchar(32), default `native` | Scheduling provider key |
| `bkt_external_type_uri` | varchar(255), nullable | Provider's event-type identifier (external providers) |
| `bkt_sch_schedule_id` | int8 | Schedule that drives availability (native) |
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
- **Slots endpoint:** plugin ajax endpoint returning JSON slots for a type + month, backed by SlotGenerator + busy sources + per-period caps. Public, read-only, rate-limit-friendly (no session required).
- **Booking page:** slot_picker component + FormWriter form (name, email, additional notes), intake survey questions rendered inline via `Question::output_question()`. Invitee does not need an account; an inactive user record is created/matched by email (same pattern the old sync used).
- **Booking creation is race-safe:** creation runs in a transaction holding a per-host advisory lock and re-runs the slot conflict check (all registered busy sources + caps) before inserting. If the slot is gone, the invitee is returned to the picker with a refreshed slot list. Two simultaneous submissions for one slot must produce exactly one booking. (When the external-calendar busy source is later added, it participates in this same re-check.)
- **Paid types:** if `bkt_pro_product_id` is set, the chosen slot is held — a `CREATED` booking with `bkn_hold_expires_time` 15 minutes out (mirroring `evr_expires_time` temporary reserves) — and the user is sent through the product purchase flow. Held slots count as busy, so other viewers never see them. Confirmation fires on purchase completion via the product purchase hooks and re-runs the same locked conflict check: if the slot was lost (hold expired mid-checkout, someone else booked), the booking lands in `NEEDS_ATTENTION`, the host is notified, and the payment follows the standard refund path. Expired unpaid holds release automatically.
- **Confirmation:** booking row (status BOOKED), confirmation email to invitee + host (ICS attached, add-to-calendar links, cancel/reschedule links containing `bkn_action_token`), in-app notification to host.

### Invitee self-service cancel / reschedule

`/booking/manage?token={bkn_action_token}` — shows the booking; cancel (with reason) or reschedule (slot_picker for the same type; old slot released, new confirmed, calendars updated). Enforces `bkt_cancel_notice_minutes` and displays the policy text. No login required; the token is the credential.

### Host and admin cancellation

Hosts cancel their own bookings from the "my bookings" profile page (with reason); admins from the booking detail page. Either way the other party is emailed — an invitee whose booking was canceled gets a "pick a new time" link to the booking type. The notice-minutes rule does not bind hosts/admins.

### Reminders and follow-ups

A `BookingEmailsTask` scheduled task (every_run frequency): sends reminder emails at configured offsets before start (`bkt_reminder_minutes_csv`, default "1440,60"), and a follow-up after end. Sends are recorded (a `bke_booking_emails` log table) so the task is idempotent; the log key is booking + offset + **booking start time**, so a rescheduled booking earns fresh reminders for its new time. Suppressed when `bkt_send_native_emails` is false or the booking is canceled/no-show.

### Admin

Rework the existing read-only pages into full CRUD: booking type edit (FormWriter, `visibility_rules` for provider-/location-dependent fields), bookings list with status filters + calendar_grid view, booking detail with cancel / mark-no-show actions.

---

## Layer 4: Scheduling Provider Abstraction

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

The booking page logic branches once on mode; everything downstream (booking rows, admin, analytics, client history) is provider-agnostic. Webhook ingestion is **idempotent on the external URI** — duplicate deliveries are no-ops, and a cancellation arriving before (or without) its creation parks for the reconciliation sync task instead of erroring.

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

- **Schedule** — cannot be soft-deleted while a non-deleted booking type references it; the editor names the dependent types. Windows and overrides cascade with their schedule.
- **BookingType** — soft delete stops new bookings; existing future bookings stand (reminders and manage links keep working).
- **Booking** — soft delete only; canceled bookings keep their rows for history and analytics.
- **User as host** — deletion cancels their future bookings (invitees notified) and deletes their schedules via `$foreign_key_actions`. (External calendar/provider connections add to this cascade in the external-integrations spec.)
- **User as invitee** — deletion cancels their future bookings (host notified).

---

## Phases

Each phase lands working and tested before the next starts. The provider interface is defined in Phase 3 even though only `native` exists then — externals slot in without rearchitecting.

Within each phase, the work is broken into steps sized to be built, integrated, and verified independently. Every step ends in a state that runs on dev — no step leaves another step's work half-wired. Each step lists its **integration checkpoint**: what is observably working when the step is done.

### Phase 1 — Core availability engine

- **1.1 Schedule data layer.** `Schedule`/`ScheduleWindow`/`ScheduleOverride` classes (+ Multi classes), `update_database` creates the tables. Model CRUD tests in `/tests/models/`.
  *Checkpoint:* tables exist; tests create/load/soft-delete schedules with windows and overrides.
- **1.2 SlotGenerator.** Pure computation per the algorithm above. Unit tests must cover: DST spring-forward/fall-back dates, overrides replacing weekly windows, full-day blocks, buffer subtraction, min-notice filtering, increment vs. duration interplay, busy blocks spanning window edges.
  *Checkpoint:* test suite green; generator produces correct UTC slots for fixture schedules.
- **1.3 Busy-source registry.** `BusySourceInterface`, auto-discovery registry, `EventBusySource` (leader + registrations, including virtual recurring instances via `get_instances_for_range()`). Tests with seeded events including a recurring parent.
  *Checkpoint:* registry discovers sources; a user's event commitments appear as busy blocks and suppress generator slots.
- **1.4 Availability editor.** `/profile/bookings/availability` (plugin view over the core models) — schedule list, weekly windows editor, date overrides (FormWriter throughout). Plain table/list rendering for now; the calendar preview arrives in 2.2.
  *Checkpoint:* a user creates "Working hours" with windows and a vacation override in the browser; rows verified in the DB.

### Phase 2 — Calendar UI components

- **2.1 `calendar_grid` component.** Month + week views, static `items` config and `feed_url` JSON mode, viewer-timezone rendering, vanilla JS.
  *Checkpoint:* a test page renders seeded events on a month grid; paging months works.
- **2.2 Grid integration.** Availability editor shows the schedule's computed availability (windows minus busy) on the grid.
  *Checkpoint:* editing a window or adding an override visibly changes the preview.
- **2.3 `slot_picker` component.** Date strip + time list, timezone auto-detect/override, loads from `slots_url`, writes selection to a hidden field. Verified against a stub slots endpoint.
  *Checkpoint:* picker renders stub slots in multiple timezones and posts the chosen UTC slot.

### Phase 3 — Native booking flow (the usable MVP)

- **3.1 Plugin schema + provider seam.** Booking/BookingType field rework (add/rename/drop per Schema changes above), drop `usr_calendly_uri` from core, `SchedulingServiceProvider` interface + registry + `NativeSchedulingProvider` (slots via SlotGenerator; creation lands in 3.4).
  *Checkpoint:* plugin sync applies the schema; registry returns the native provider; validator passes on all touched files.
- **3.2 Admin booking-type CRUD.** Full create/edit form (duration, schedule, buffers, notice, window, caps, location via `visibility_rules`). Needed now — every later step requires a configurable type.
  *Checkpoint:* an admin creates a working booking type bound to a schedule in the browser.
- **3.3 Public slot browsing.** Slots JSON endpoint (generator + busy sources + per-period caps), serve.php vanity route, booking page rendering `slot_picker` from the real endpoint.
  *Checkpoint:* visiting `/book/{slug}` shows genuinely open times; booking-capped days and busy times are absent.
- **3.4 Booking creation (free types).** Invitee form (name/email/notes), user match-or-create by email, race-safe creation (per-host advisory lock + conflict re-check), BOOKED row, `BookingBusySource` (with buffers), confirmation emails to both sides with ICS + add-to-calendar, host in-app notification.
  *Checkpoint:* end-to-end booking on dev; both emails arrive (verify via `iem_inbound_email_messages`); the slot disappears from the picker; two concurrent submissions for one slot produce exactly one booking.
- **3.5 Invitee self-service.** `/booking/manage?token=…` — cancel with reason, reschedule via slot_picker, `bkt_cancel_notice_minutes` enforcement, policy text display, notification emails.
  *Checkpoint:* cancel and reschedule both work from the email links; too-late cancellation is refused.
- **3.6 Reminders + follow-ups.** `BookingEmailsTask` (every_run), `bke_booking_emails` send log for idempotency, suppression rules (canceled, no-show, `bkt_send_native_emails` off).
  *Checkpoint:* task run against a near-future booking sends exactly one reminder; rerun sends nothing.
- **3.7 Intake surveys.** `bkt_svy_survey_id` rendering inline on the booking form, answers stored against the invitee user, shown on the admin booking detail.
  *Checkpoint:* booked with intake answers; answers visible in admin.
- **3.8 Paid bookings.** 15-minute slot hold (hidden from other viewers), product purchase flow handoff, confirmation via purchase hooks with locked conflict re-check (`NEEDS_ATTENTION` + host notification + refund path on conflict), hold expiry releasing the slot.
  *Checkpoint:* Stripe test purchase confirms a booking; an abandoned hold expires and the slot returns.
- **3.9 Admin and host operations.** Bookings list with status filters + `calendar_grid` view, booking detail with cancel / mark-no-show, host "my bookings" profile page with host-side cancel (invitee notified with a rebook link).
  *Checkpoint:* admin sees bookings on a calendar and can cancel and mark no-show; a host cancel emails the invitee.

### External integrations (deferred — separate spec)

External calendar sync (Google/Outlook busy-read + write-back) and external scheduling providers (Calendly import/embed, Acuity) are **out of scope for this spec.** They are designed import-first for the degoogle market in `specs/external_scheduling_integrations.md` and build purely on the seams shipped above (the busy-source registry and the `SchedulingServiceProvider` interface) — no native rework.

### Phase 4 — Later (separate specs when prioritized)

The items below are not in scope for Phases 1–3. Each warrants its own spec before implementation begins.

#### Waitlist auto-promotion
When a confirmed booking is canceled, the first waiter for that slot gets a time-limited claim link by email (token-gated, same mechanism as `bkn_action_token`). If unclaimed within the window, the next waiter is notified. The waitlist queue has a natural sort order by `bkn_create_time` on `WAITLISTED` rows.

#### Event workflow / automation engine
A trigger-action table: trigger (booking created / canceled / rescheduled / no-show), offset (e.g., −1440 minutes = 24 hours before start), channel (email / SMS), template. `BookingEmailsTask` already handles fixed offsets from config fields; the workflow engine generalizes this to admin-configurable per-type rules. Underpins customizable reminder/follow-up timing and content, and the no-show follow-up path.

#### Team scheduling (round robin / collective)
A `bkt_booking_type_hosts` join table adds a pool of hosts to a type. **Round robin:** slot generator unions availability across hosts and assigns each booking to the next host in rotation (by booking count). **Collective:** slot generator intersects availability — only times all required hosts are simultaneously free are offered. Both require SlotGenerator to accept multiple schedules/busy-source sets.

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

**Create (core):** `data/schedules_class.php`, `data/schedule_windows_class.php`, `data/schedule_overrides_class.php`, `includes/scheduling/SlotGenerator.php`, `includes/scheduling/BusySourceInterface.php`, `includes/scheduling/BusySourceRegistry.php`, `includes/scheduling/busy_sources/EventBusySource.php`, `views/components/calendar_grid.{json,php}`, `views/components/slot_picker.{json,php}`.

**Create (plugin):** `includes/SchedulingServiceProvider.php`, `includes/SchedulingProviderRegistry.php`, `includes/scheduling_providers/NativeSchedulingProvider.php`, `includes/busy_sources/BookingBusySource.php`, `data/booking_emails_class.php`, public views (`views/book/…`, manage page), profile views (availability editor, my bookings), ajax (slots endpoint), `tasks/BookingEmailsTask.{json,php}`, reworked admin pages.

**Modify:** `plugins/bookings/data/bookings_class.php`, `booking_types_class.php` (schema above), `plugin.json` (settings, menu), `serve.php` (vanity route), `data/users_class.php` + `adm/admin_users_edit.php` + logic (drop `usr_calendly_uri`), `views/booking.php` + `theme/tailwind/views/booking.php` (replace placeholder/dead embed with the native flow).

**Delete:** `specs/calendly_integration.md` (done alongside this spec).

External-integration files (`calendar_connections`, `provider_connections`, Calendly/Acuity providers, OAuth consumers, webhook endpoints, `ProviderSyncTask`, connection UIs, Calendly settings) are listed in `specs/external_scheduling_integrations.md`.

---

## Documentation

When implemented, current-state docs (no migration narration):

- New `docs/scheduling.md` — core engine: schedules, the wall-clock-time exception, busy sources (and the registry seam), SlotGenerator, calendar UI components.
- New `plugins/bookings/docs/overview.md` — booking types, public flow, the native provider, and the `SchedulingServiceProvider` seam (noting external implementations live in the external-integrations spec).
- Update `docs/scheduled_tasks.md` task list if it enumerates tasks.
- Add both new docs to the CLAUDE.md documentation index via the admin agent-files editor (`/admin/admin_agent_files`).
