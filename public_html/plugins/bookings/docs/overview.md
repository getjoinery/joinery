# Bookings Plugin

Booking is a feature layered on the core personal calendar: it publishes a host's availability (the busy projection from the calendar) and writes confirmed bookings back as calendar items. The plugin owns booking types, the public booking flow, and the scheduling-provider seam; the calendar, schedules, and slot generation are core.

Gated by the `bookings_active` setting.

## Booking types

`BookingType` (`data/booking_types_class.php`, `bkt_booking_types`) configures a bookable meeting. Key fields:

- `bkt_provider` (default `native`), `bkt_external_type_uri` — scheduling backend (see the seam below).
- `bkt_usr_user_id` — the host; availability comes from this user's one schedule.
- `bkt_slug` — globally unique; the public URL is `/book/{slug}`.
- `bkt_duration_minutes`, `bkt_slot_increment_minutes`, `bkt_buffer_before_minutes`, `bkt_buffer_after_minutes`, `bkt_min_notice_minutes`, `bkt_rolling_days`, `bkt_window_start`/`bkt_window_end`, `bkt_max_per_day`/`bkt_max_per_week` — slot shape and bounds.
- `bkt_location_mode` / `bkt_location_details`, `bkt_pro_product_id` (paid), `bkt_svy_survey_id` (intake survey).
- `bkt_cancel_notice_minutes`, `bkt_cancellation_policy_text` — invitee cancel/reschedule rules.
- `bkt_send_native_emails` (default on), `bkt_reminder_minutes_csv` (default `1440,60`).

Admin CRUD: `/plugins/bookings/admin/admin_booking_types` (list) and `admin_booking_type_edit` (create/edit). The edit form renders the bulk fields through `FormWriter::fromDescriptor()`; the host picker and the location box (gated on the location mode via `visibility_rules`) are hand-added.

## Public booking flow

- **Vanity URL** `/book/{slug}` (one `serve.php` placeholder route) resolves the type by its globally-unique slug.
- **Slots endpoint** `POST /api/v1/action/bookings/booking_slots` (JSON `{slug, start, end}`, sessionless) — public, read-only JSON, computed by the provider from `SlotGenerator` + the busy projection (at `busy` visibility, so no item titles leak) + per-period caps.
- **Booking page** renders the `slot_picker` from the slots endpoint plus a FormWriter form (name, email, notes) and the intake survey inline (`Question::output_question`). The invitee needs no account — an inactive user record is matched/created by email.
- **Race-safe creation:** a transaction holding a per-host PostgreSQL advisory lock re-runs the slot conflict check before inserting, so two simultaneous submissions for one slot produce exactly one booking.
- **Hiding a slot and refusing it are the same check.** The submitted time is validated by re-generating the open slots and looking for an exact match, so every rule that shapes the picker — working hours, the increment grid, buffers, minimum notice, the rolling or fixed window, per-period caps, and any existing booking — also rejects a hand-made post. There is no separate submission-side rule set to keep in sync.
- **Confirmation:** a BOOKED `bkn_bookings` row, confirmation emails to invitee + host (ICS attached, manage link carrying `bkn_action_token`), and an in-app notification to the host. The booking then appears on the host's personal calendar via `BookingItemSource`, which also removes the slot from future availability.

## Invitee self-service

`/booking/manage?token={bkn_action_token}` — cancel (with reason) or reschedule (a `slot_picker` for the same type; the same booking row moves to the new slot, re-checked race-safely; the old time frees automatically). No login — the token is the credential. Enforces `bkt_cancel_notice_minutes` and shows the policy text.

## Host & admin operations

- Host "my bookings": `/profile/bookings/my_bookings` — upcoming bookings with a host-side cancel (the invitee is emailed a rebook link). The notice-minutes rule does not bind hosts.
- Admin: `/plugins/bookings/admin/admin_bookings` (list with status filter) and `admin_booking` (detail with cancel / mark no-show / mark completed; shows intake answers). Either-party cancellation emails the other side.

## Reminders & follow-ups

`BookingEmailsTask` (`tasks/BookingEmailsTask.php`, `every_run`) sends reminder emails at each configured offset before start and a follow-up after end. Sends are recorded in `bke_booking_emails` keyed by booking + kind + offset + **booking start time**, so the task is idempotent and a rescheduled booking earns a fresh set of reminders. Suppressed for canceled / no-show bookings and types with native emails off.

## Scheduling-provider seam

`SchedulingServiceProvider` (`includes/SchedulingServiceProvider.php`) is a pluggable interface; `SchedulingProviderRegistry` auto-discovers implementations in `includes/scheduling_providers/`. Two modes declared per provider by `getMode()`:

- **headless** — Joinery renders its own slot picker and form; the provider is just the availability/booking backend (native).
- **embed** — the booking page embeds the provider's widget; webhooks ingest the booking.

The booking page branches once on mode; everything downstream (booking rows, calendar items, admin, analytics) is provider-agnostic.

`NativeSchedulingProvider` (`includes/scheduling_providers/NativeSchedulingProvider.php`) is the only shipped implementation — mode `headless`, built on `SlotGenerator` and the plugin's own booking creation, needs no connection, and is the default for every booking type.

**External providers** (Calendly, Acuity), the `ProviderConnection` model and credential storage, the connect/import UI, and the webhook endpoints are out of scope here and live in `specs/external_scheduling_integrations.md`. The interface above is the full contract (including `embed` mode and webhook methods) so they slot in without reopening it.

## Calendar integration

`BookingItemSource` (`includes/calendar_item_sources/BookingItemSource.php`) projects confirmed bookings and active paid holds where the subject is host onto their calendar and into the busy projection — the one item that both shows on the calendar and removes the time from future availability. Canceled/deleted bookings stop projecting and leave the calendar automatically.

**Undecided: tentative entries and availability.** AI-extracted calendar entries (`specs/joinery_ai_calendar_ai_surface.md`) land `cal_status = 'tentative'` and still block availability by default. `CalendarItemSourceRegistry::getBusyBlocks()` exposes an optional `$include` policy precisely so this plugin can choose to exclude tentative items from a host's bookable slots — that choice has not been made; `NativeSchedulingProvider` currently passes no policy and treats every busy item the same regardless of firmness (see `docs/calendar.md` § Firmness).
