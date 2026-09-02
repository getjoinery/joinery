# Event Manager

## Overview

The Event Manager plugin (`/plugins/event_manager/`) is the platform's event subsystem: events, sessions, locations, registrations, waiting lists, and ICS calendar feeds. It **depends on the store plugin** — paid event registration fulfills through the cart via the store's FulfillmentRegistry. With the plugin inactive, event URLs hard-404 and core surfaces (site directory, profile dashboard, admin user page, bulk-email targeting) degrade cleanly to their event-less forms.

**Owns:** `evt_events`, `evs_event_sessions` (+ session files), `evr_event_registrants`, `ewl_event_waiting_lists`, `evt_event_types`, `loc_locations`.

**Core URLs (plugin-delegated in serve.php):** `/events`, `/event/{slug}`, `/event/{slug}/{date}`, `/location/{slug}`, `/event_waiting_list`, the profile event pages, and the ICS handler routes (`/event/{slug}.ics`, `/event/{slug}/{date}.ics`, `/events/calendar.ics` — served by handler files in `includes/`, no view resolution). Admin pages live at `/plugins/event_manager/admin/*`. The event admin page lists the emails sent to the event's registrants and to its waiting list (`MultiEmail` `recipient_group` on the `event` / `event_waiting_list` providers), each linking to its delivery page; "Email registrants" and "Email waiting list" queue a new one through `/admin/admin_users_message`.

## Key Pieces

| Piece | Role |
|-------|------|
| `includes/fulfillment_providers/EventRegistrationFulfillment.php` | Fulfills event-registration products at charge time: creates the `EventRegistrant`, links it on the order item (`odi_evr_event_registrant_id`) |
| `includes/access_gate_providers/EventRegistrationGate.php` | `event_registration` access gate: videos/files gated to an event's registrants |
| `includes/recipient_group_providers/` | `event` and `event_waiting_list` bulk-email targeting providers |
| `includes/calendar_item_sources/` | Feeds events into the core calendar |
| `includes/ics_event_route.php` / `ics_calendar_route.php` | ICS download handlers |
| `tasks/` | `WeeklyEventsDigest`, `SendPostEventSurveys` scheduled tasks |

## Extension-Point Registrations (serve.php)

The plugin registers with core registries at load time: SEO metadata (`event`, `location`), tier-gated content summary (`Events`), entity photos (`event`, `location`), the `event` message-context resolver, recipient-group providers, the events profile-dashboard section, the Events admin-user panel, and the event-registration access gate and fulfillment provider.

## Recurring Events

Recurring event architecture (virtual vs. materialized instances) is documented in [Recurring Events](recurring_events.md).

## Activation

`activate.php` is idempotent and self-guarded. It folds the pre-extraction analytics columns (`sev_evt_event_id` / `sev_evs_event_session_id`) into the generalized `sev_entity_type` / `sev_entity_id` pair and drops them, merges the `event` and `location` photo caps into the core `max_entity_photos` setting, and claims the plugin's scheduled-task rows. Activation requires core `update_database` to have run first (the generalized columns must exist); it aborts with a clear message otherwise. On upgrade, `update_database` runs a one-time auto-activation: event_manager activates when `evt_events` has rows (activating store first to satisfy the dependency).

Settings keep their pre-extraction core names (`events_active`, ...) via the per-setting `legacy_core: true` flag in `plugin.json`.

Themes whose views are event-coupled declare `"requires_plugins": ["event_manager"]` in `theme.json`; `ThemeManager` blocks activating such a theme without the plugin.

## Related Docs

- [Recurring Events](recurring_events.md) — virtual/materialized recurring instances
- [Store overview](../../store/docs/overview.md) — the fulfillment seam this plugin registers into
