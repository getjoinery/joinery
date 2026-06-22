# Native Booking Flow — Bookings Plugin

## Overview

Booking is a feature layered on the **core personal calendar** (`specs/scheduling_system.md`): it publishes a subject's availability (the busy projection) and writes confirmed bookings back onto the calendar as items. A calendar with booking features, not a booking system with a calendar view.

This spec covers everything booking-specific — booking types, the public booking page, the booking lifecycle (confirm / cancel / reschedule / remind), intake questions, paid bookings — plus a pluggable **scheduling-provider seam** so external services can slot in later. It is the "usable MVP" layer that sits on top of the core scheduling engine.

**Depends on `specs/scheduling_system.md`**, which provides:
- `CalendarSubject` (subject identity) and the schedule data model (`sch_schedules` / `scw_schedule_windows` / `sco_schedule_overrides`).
- `SlotGenerator` (availability computation) and the availability editor.
- The `CalendarItemSource` registry + the busy projection (the single upstream contract bookings both reads — for availability — and writes to — via `BookingItemSource`).
- The `calendar_grid` and `slot_picker` calendar UI components.

**External integrations are a separate, deferred spec.** Connecting to Calendly, Acuity, Google, and Outlook lives in `specs/external_scheduling_integrations.md`. The seams here are designed so all of it is additive — external booking backends are just another provider, external calendars just another `CalendarItemSource`.

**Supersedes:** `specs/calendly_integration.md` and `specs/calendly_feature_ideas.md` (both deleted — the Later backlog below is the consolidation).

**Pre-launch note:** The platform has no production users. Schema fields may be dropped/renamed freely; no data-preservation migrations are required. The dev values in `usr_calendly_uri` are discarded.

---

## Integration-Point Inventory (decided up front)

Every platform system the booking feature touches, and how:

| System | Integration |
|---|---|
| **Calendar (core)** | Bookings read the busy projection (at `busy` visibility) for availability and write confirmed bookings back via `BookingItemSource`, where the subject is host. Holds project too, so other viewers never see a held slot. |
| **Questions/Surveys** | Intake questions on booking types via `bkt_svy_survey_id` (same pattern as `evt_svy_survey_id`). No new question-collection mechanism. |
| **Products/payments** | Paid bookings via the existing `bkt_pro_product_id` / `bkn_pro_product_id` links. Purchase-time data collection via Product Requirements. |
| **Email** (`SystemMailer` / `EmailSender`) | Confirmation, cancellation, reschedule, reminder, and follow-up emails. ICS invite attached to confirmations via `IcsHelper`. |
| **ICS / calendar-links** | `IcsHelper` for VEVENT generation; Spatie calendar-links for add-to-calendar buttons; per-booking ICS download. |
| **Scheduled tasks** | Reminder/follow-up sender task (`BookingEmailsTask`). |
| **Routing** | Plugin view auto-discovery for most pages; one serve.php route for the vanity booking URL with a slug placeholder. |
| **In-app notifications** | Notify host on new booking / cancellation. |
| **Analytics** | UTM capture at booking time (`bkn_utm_*` fields) feeding the existing visitor-events/conversion system. |
| **AI discovery** | Booking / BookingType classes keep `ai_readable` flags with appropriate descriptions. |
| **OAuth2 core** (`includes/oauth/`) | Not used by the native engine. Google/Microsoft/Calendly OAuth is part of `specs/external_scheduling_integrations.md`. |

---

## Building the data layer with the scaffold generator

The booking send-log is a plain CRUD model behind bespoke presentation, so it is **generated** from a `surfaces:["data"]` manifest via `php utils/scaffold.php`:

| Entity (class) | Table | `surfaces` | Into |
|---|---|---|---|
| `BookingEmail` (send log) | `bke_booking_emails` | `["data"]` | `plugins/bookings` |

**What is *not* scaffolded.** The generator is creation-only — it never edits an existing file. The plugin's `booking_types_class.php` and `bookings_class.php` already exist; their schema changes (below) and admin CRUD rework are hand edits. They do adopt the same descriptor-driven forms the generator emits — their edit forms render through `FormWriter::fromDescriptor()` — so a hand-built booking-type form and a generated form share one field-declaration style.

---

## Layer 1: Bookings Plugin (native flow)

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

`bkt_slug` gains a **global unique constraint** — it is the public booking URL.

**`bkn_bookings`** — rename `bkn_calendly_event_uri` → `bkn_external_uri`; drop redundant `bkn_type`; add `bkn_provider`, `bkn_invitee_timezone`, `bkn_action_token` (random token for invitee cancel/reschedule links), `bkn_hold_expires_time` (paid pending holds), `bkn_canceled_by` / `bkn_cancel_reason`, `bkn_is_no_show`, `bkn_utm_source/medium/campaign/content/term`. Status: `CREATED` (0) serves as the pending-hold state; a new `NEEDS_ATTENTION` constant covers paid bookings whose slot was lost during checkout.

**Core `usr_users`** — drop `usr_calendly_uri` (field, edit form, logic). Per-host external-provider identity is handled by the connections table in the external-integrations spec, not by a column on the user.

### Public booking flow

- **Vanity URL:** `/book/{slug}` — one serve.php placeholder route resolving the booking type (and through it the host) by globally-unique `bkt_slug`.
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

## Layer 2: Scheduling Provider Abstraction

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

> **Note:** `ProviderConnection` is defined by `specs/external_scheduling_integrations.md`, not built here. The native engine carries no dependency on it; in the shipped interface the connection parameters are left untyped so the native provider needs nothing external.

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

- **BookingType** — soft delete stops new bookings; existing future bookings stand (reminders and manage links keep working). Booking types reference the host directly (`bkt_usr_user_id`), not the schedule.
- **Booking** — soft delete only; canceled bookings keep their rows for history and analytics. The `BookingItemSource` stops projecting canceled/deleted bookings, so they leave the calendar automatically.
- **Subject as host** — deletion cancels their future bookings (invitees notified). Their schedules and native calendar entries are cleaned up by the core spec's cascade. (External calendar/provider connections add to this cascade in the external-integrations spec.)
- **User as invitee** — deletion cancels their future bookings (host notified).

---

## Phases

Each phase lands working and tested before the next starts. The provider interface is defined in the first phase even though only `native` exists then — externals slot in without rearchitecting. Within each phase, work is broken into steps that each end in a state that runs on dev; each step lists its **integration checkpoint**.

### Phase 1 — Plugin schema + provider seam

- **1.1 Plugin schema + provider seam.** Booking/BookingType field rework (add/rename/drop per Schema changes above — **hand edits to the existing `bookings_class.php` / `booking_types_class.php`; the generator is creation-only**), drop `usr_calendly_uri` from core, `SchedulingServiceProvider` interface + registry + `NativeSchedulingProvider` (slots via SlotGenerator; creation lands in 1.4).
  *Checkpoint:* plugin sync applies the schema; registry returns the native provider; validator passes on all touched files.
- **1.2 Admin booking-type CRUD.** Full create/edit form (duration, host, buffers, notice, window, caps, location via `visibility_rules`), rendered through `FormWriter::fromDescriptor()`. Needed now — every later step requires a configurable type.
  *Checkpoint:* an admin creates a working booking type bound to its host in the browser.
- **1.3 Public slot browsing.** Slots JSON endpoint (generator + busy projection at `busy` visibility + per-period caps), serve.php vanity route, booking page rendering `slot_picker` from the real endpoint.
  *Checkpoint:* visiting `/book/{slug}` shows genuinely open times; booking-capped days and busy times are absent; no item titles leak to the endpoint.
- **1.4 Booking creation (free types).** Invitee form (name/email/notes), user match-or-create by email, race-safe creation (per-host advisory lock + conflict re-check), BOOKED row, `BookingItemSource` (with buffers), confirmation emails to both sides with ICS + add-to-calendar, host in-app notification.
  *Checkpoint:* end-to-end booking on dev; both emails arrive; the slot disappears from the picker; the booking shows on the host's `/profile/calendar`; two concurrent submissions for one slot produce exactly one booking.
- **1.5 Invitee self-service.** `/booking/manage?token=…` — cancel with reason, reschedule via slot_picker, `bkt_cancel_notice_minutes` enforcement, policy text display, notification emails.
  *Checkpoint:* cancel and reschedule both work from the email links; too-late cancellation is refused.
- **1.6 Reminders + follow-ups.** `BookingEmailsTask` (every_run), `bke_booking_emails` send log for idempotency (scaffold-generated, `surfaces:["data"]`, into the plugin), suppression rules.
  *Checkpoint:* task run against a near-future booking sends exactly one reminder; rerun sends nothing.
- **1.7 Intake surveys.** `bkt_svy_survey_id` rendering inline on the booking form, answers stored against the invitee user, shown on the admin booking detail.
  *Checkpoint:* booked with intake answers; answers visible in admin.
- **1.8 Paid bookings.** 15-minute slot hold (hidden from other viewers), product purchase flow handoff, confirmation via purchase hooks with locked conflict re-check (`NEEDS_ATTENTION` + host notification + refund path on conflict), hold expiry releasing the slot.
  *Checkpoint:* Stripe test purchase confirms a booking; an abandoned hold expires and the slot returns.
- **1.9 Admin and host operations.** Bookings list with status filters + `calendar_grid` view, booking detail with cancel / mark-no-show, host "my bookings" profile page with host-side cancel (invitee notified with a rebook link).
  *Checkpoint:* admin sees bookings on a calendar and can cancel and mark no-show; a host cancel emails the invitee.

### External integrations (deferred — separate spec)

External scheduling providers (Calendly import/embed, Acuity) are **out of scope.** They are designed import-first for the degoogle market in `specs/external_scheduling_integrations.md` and build purely on the seams shipped above (the `CalendarItemSource` registry and the `SchedulingServiceProvider` interface) — no native rework.

### Later (separate specs when prioritized)

The items below are not in scope. Each warrants its own spec.

#### Waitlist auto-promotion
When a confirmed booking is canceled, the first waiter for that slot gets a time-limited claim link by email (token-gated, same mechanism as `bkn_action_token`). If unclaimed within the window, the next waiter is notified. The queue sorts by `bkn_create_time` on `WAITLISTED` rows.

#### Event workflow / automation engine
A trigger-action table: trigger (booking created / canceled / rescheduled / no-show), offset, channel (email / SMS), template. `BookingEmailsTask` already handles fixed offsets from config fields; the workflow engine generalizes this to admin-configurable per-type rules.

#### Team scheduling (round robin / collective)
A `bkt_booking_type_hosts` join table adds a pool of hosts to a type. **Round robin:** the slot generator unions availability across hosts and assigns each booking to the next host in rotation. **Collective:** the generator intersects availability — only times all required hosts are simultaneously free are offered. Both require SlotGenerator to accept multiple subjects' projections.

#### Embeddable booking widget
A standalone JS component (`/book/{slug}/widget.js`) that renders the slot picker and booking form inside any `<div data-joinery-booking="slug">` on an external site. The slots endpoint is already public and the form already vanilla — the main work is packaging and cross-origin handling.

#### Booking analytics dashboard
Queries over `bkn_bookings` and `bke_booking_emails`: counts, completion rate, cancellation rate, no-show rate, top times, per-type performance. Filterable by type/host/date range. UTM fields feed attribution breakdowns.

#### SMS reminders
Twilio (or similar), SMS consent at booking, and an SMS channel in the workflow engine. Meaningful only after the workflow engine is in place.

#### Video conferencing link auto-creation
On confirmation, create a meeting link (Zoom / Google Meet / Teams) in a new `bkn_conferencing_url` field, included in confirmation emails and detail. Google Meet via the Calendar API is the lowest-friction path.

#### Add guests at booking
An "additional attendees" field (up to N emails), stored in a `bkg_booking_guests` table; all guests receive confirmations/reminders and appear in the calendar write-back attendee list.

#### Booking page branding
Per-type customization: `bkt_cover_image_id`, `bkt_accent_color`, `bkt_welcome_message`, rendered on the public page.

#### Attendee check-in
`bkn_checked_in` + `bkn_checked_in_time`; admin or mobile check-in UI; optional per-booking QR (signed token) for self-check-in.

#### Routing forms
A pre-booking questionnaire with conditional branching: route the invitee to a different type, host, or external URL based on answers. Builds on the questions system plus a routing-rules table.

---

## Files

**Generate (scaffold manifest → data class, `surfaces:["data"]`):** committed manifest JSON, run through `php utils/scaffold.php`. Output: `plugins/bookings/data/booking_emails_class.php`.

**Create (plugin, hand-written):** `includes/SchedulingServiceProvider.php`, `includes/SchedulingProviderRegistry.php`, `includes/scheduling_providers/NativeSchedulingProvider.php`, `includes/calendar_item_sources/BookingItemSource.php`, public views (`views/book.php`, `views/booking_manage.php`), profile views (my bookings), ajax (slots endpoint), `tasks/BookingEmailsTask.{json,php}`, reworked admin pages (booking-type edit via `FormWriter::fromDescriptor()`, bookings list/detail).

**Modify:** `plugins/bookings/data/bookings_class.php`, `booking_types_class.php` (schema above), `plugin.json` (settings, menu), `serve.php` (vanity route + manage route), `data/users_class.php` + `adm/admin_users_edit.php` + logic (drop `usr_calendly_uri`).

External-integration files (`provider_connections`, `ExternalCalendarItemSource`, Calendly/Acuity providers, OAuth consumers, webhook endpoints, `ProviderSyncTask`, connection UIs) are listed in `specs/external_scheduling_integrations.md`.

---

## Documentation

When implemented, current-state docs (no migration narration):

- New `plugins/bookings/docs/overview.md` — booking types, public flow, the native provider, and the `SchedulingServiceProvider` seam (noting external implementations live in the external-integrations spec).
- Update `docs/scheduled_tasks.md` task list if it enumerates tasks.
- Add the new doc to the CLAUDE.md documentation index via the admin agent-files editor (`/admin/admin_agent_files`).
