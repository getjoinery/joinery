# External Scheduling Integrations — Import & Connect

## Overview

Companion to `specs/scheduling_system.md` (the native booking engine). That spec ships the complete self-hosted scheduling product **and the seams** that let external scheduling and calendar services plug in without rearchitecting:

- the `CalendarItemSource` registry (native spec, Layer 1), and
- the `SchedulingServiceProvider` interface + registry (native spec, Layer 4), with `NativeSchedulingProvider` as the only shipped implementation.

This spec adds the external implementations on top of those seams. It is **additive** — no native code is rearchitected.

**Priority: deferred.** Not a near-term feature.

**Why import-first.** The target market is the "degoogle" crowd — hosts moving *off* Google and Calendly onto self-hosted scheduling. For them, **import (migration) is more valuable than live sync.** Importing an external account's configuration so the host can recreate it natively and *leave* the external service is the point. Ongoing two-way calendar sync (read busy from / write back to Google/Outlook) does the opposite — it re-tethers users to the services they're trying to leave — so it sits at the bottom of this spec, useful only for hosts who deliberately want to keep one foot in Google.

**Depends on (must already be shipped by the native spec):** the `SchedulingServiceProvider` interface + registry, the `bkt_provider` / `bkt_external_type_uri` booking-type fields, and the `CalendarItemSource` registry. This spec provides implementations for those contracts; it does not change them.

**Pre-launch note:** The platform has no production users. No data-preservation migrations are required.

---

## Two ways to use an external account

A host connecting an external scheduling/calendar account can do one of two things. The first is the reason this spec exists; the second is a convenience for holdouts.

1. **Migrate (import → native).** Connect the account once, read its event types via `listEventTypes()`, and recreate each as a **native** booking type (`bkt_provider='native'`) bound to a native schedule. The host then runs entirely on Joinery and disconnects the external service. This is the primary, highest-value path for the degoogle market — the external account is a *source*, not a permanent dependency.

2. **Proxy (stay external).** Keep the external service as the live backend: `bkt_provider='calendly'|'acuity'` types that either embed the provider's widget (Calendly) or drive the native slot picker through the provider's API (Acuity). Bookings still land in the unified local `bkn_bookings` table for one history/admin/analytics view. For hosts who want to keep their existing tool. Secondary.

Both paths use the same `SchedulingServiceProvider` contract; migration uses only `listEventTypes()`, proxy uses the full surface.

---

## Provider abstraction (external implementations)

The interface, registry, mode model (`headless` vs `embed`), and `NativeSchedulingProvider` are defined in the native spec. This spec adds the connection storage, the connect/import UI, the external provider implementations, and the webhook endpoints.

### Connections

**`bpc_provider_connections`** (bookings plugin)
- `bpc_provider_connection_id` (pk)
- `bpc_usr_user_id` — the host
- `bpc_provider`
- `bpc_credentials` — SecretBox-encrypted JSON (OAuth token, or API key + user id)
- `bpc_external_user_uri` — provider-side account/user identifier
- `bpc_webhook_uri` / `bpc_webhook_signing_key` — registered subscription handle + secret (encrypted)
- `bpc_status`, timestamps

Hosts connect at `/profile/bookings/connections`: OAuth providers show a Connect button (consent flow), key-based providers show the provider's `getConnectionFields()` via FormWriter. After connecting, `listEventTypes()` powers an import step.

**Import step (the migration path):** the import UI lists the external event types and lets the host bring each in as either a **native** type (recreate `bkt_provider='native'` — duration, and a native schedule the host then tunes) or a **proxy** type (`bkt_provider` + `bkt_external_type_uri`, kept live on the external service). Native is the default and the recommended choice; the page frames proxy as "keep running this on {provider}." When every type a host cares about has been imported as native, they can disconnect with nothing left behind.

### Calendly provider

- **Auth:** Calendly OAuth2 (`auth.calendly.com`). Add `CalendlyOAuthProvider` to the core catalog (`includes/oauth/providers/`, settings `oauth_calendly_client_id` / `oauth_calendly_client_secret`, secret via SecretBox) — it's just endpoints + credentials, consistent with the catalog's purpose. The plugin ships a `CalendlySchedulingOAuthConsumer` (purpose `bookings_calendly`) that stores the token on the connection row.
- **Mode:** embed + webhooks. Calendly's v2 API exposes event types, scheduled events, and invitees but does not let third parties create invitee bookings, so a *proxy* Calendly type embeds the Calendly inline widget (the commented-out code in `theme/tailwind/views/booking.php` is the reference; it becomes a provider-rendered fragment, not a theme fork). Tracking: pass the local booking-type id and a correlation nonce via the embed's UTM/tracking parameters; the webhook payload echoes them back. (A *migrated* Calendly type is plain native and uses none of this.)
- **Webhooks:** register `invitee.created` / `invitee.canceled` org/user-scope subscriptions via `POST /webhook_subscriptions`; verify the `Calendly-Webhook-Signature` HMAC with the stored signing key; map v2 payloads (top-level `payload` resource, URIs not ids) into Booking rows.
- **Reconciliation:** a scheduled sync task pages through `/scheduled_events` (cursor pagination via `next_page_token`) to heal missed webhooks.

### Acuity provider

- **Auth:** API key + user ID (HTTP Basic) — connection fields, no OAuth required.
- **Mode:** headless. Acuity's API supports reading availability (`/availability/times`) and creating/canceling appointments (`/appointments`), so proxy Acuity types use the native slot picker UI; invitees never leave the site.
- **Webhooks:** Acuity webhooks for scheduled/rescheduled/canceled, HMAC-SHA256 signature verification, for changes made on the Acuity side.

### Webhook endpoints

`plugins/bookings/ajax/webhook_{provider}.php` — resolve the connection, `verifyWebhook()`, dispatch to `handleWebhook()`. Always 200-fast, log failures, never echo secrets. Ingestion is **idempotent on the external URI** — duplicate deliveries are no-ops, and a cancellation arriving before (or without) its creation parks for the reconciliation sync task instead of erroring.

When a connection breaks (token revoked, refresh failure, key rejected), it is marked errored and the host notified. Headless proxy types backed by an errored connection render a "scheduling temporarily unavailable" booking page, never a broken picker; embed proxy types keep working (the widget doesn't depend on our token).

---

## External calendar sync (Google / Microsoft) — lowest priority

This is the part the degoogle market least wants: a live, ongoing tether to Google/Outlook for busy-read and write-back. It is included for completeness and for hosts who choose to keep it, but it is the last thing to build.

It plugs into the native `CalendarItemSource` registry — `ExternalCalendarItemSource` is just another source, so once it exists the owner's personal calendar shows external events (`type=external`) and the busy projection the slot generator consumes already includes them, with no other change.

**`cal_calendar_connections`** (CalendarConnection, core)
- `cal_calendar_connection_id` (pk)
- `cal_usr_user_id`
- `cal_provider` — `google` | `microsoft`
- `cal_external_calendar_id` — which calendar on the account
- `cal_credentials` — OAuth2Token JSON, SecretBox-encrypted
- `cal_read_busy` (bool), `cal_write_back` (bool)
- `cal_status`, timestamps

**`cbb_calendar_busy_blocks`** — cache of fetched busy blocks (`cbb_cal_calendar_connection_id`, `cbb_start_time`, `cbb_end_time` UTC, `cbb_fetched_time`). Refreshed on demand with a short TTL when slots are requested — there is deliberately no background refresh task (it would spend API quota on hosts nobody is viewing). Browsing tolerates TTL staleness; booking **confirmation** does one live freshness check against connected calendars.

**`ExternalCalendarItemSource`** (core) — calendar items from connected Google/Microsoft calendars (busy-blocking; owner-visible event titles where the account grants them), served from the cache with on-demand refresh.

**Echo dedup (the first place `source_key` dedup applies).** When write-back is on, a confirmed native booking is also written to the host's external calendar, then read back by this source — the same meeting twice. Because write-back stamps the native booking's id into the external event's metadata, this source sets each echoed item's `source_key` to that native id. The calendar aggregation and the busy projection then collapse the echo against the native item, so it shows and counts once. This is the dedup the native spec defers here; until external feeds exist, no two sources produce the same item and there is nothing to collapse.

OAuth flow: a `CalendarSyncOAuthConsumer` (core `includes/oauth/consumers/`, purpose `calendar_sync`) requests `https://www.googleapis.com/auth/calendar` (Google) / `Calendars.ReadWrite` + `offline_access` (Microsoft), stores the token on the connection row. The connect/disconnect UI ships with the bookings plugin at `/profile/bookings/calendars` (engine-vs-surface rule — connections are only consumed through scheduling today; the page promotes to core if a core consumer such as event-registration write-back ever lands). Write-back: on booking confirmation, create an event on each `cal_write_back` calendar (attendee email, intake summary, meeting location); on cancellation, delete it (store the external event id on the booking via `bkn_external_calendar_event_id`).

---

## Phases

Ordered by value for the degoogle market: migration import first, live calendar sync last. Each phase lands working and tested before the next. All phases assume the native spec's seams are already shipped.

### Phase A — Provider connections + migration import (primary)
`bpc_provider_connections` + `/profile/bookings/connections` (Connect button for OAuth providers, `getConnectionFields()` form for key-based). `CalendlyOAuthProvider` in the core catalog + the plugin's `CalendlySchedulingOAuthConsumer`. `listEventTypes()` for Calendly, and the import step that recreates each external event type as a **native** booking type (default) or links it as a proxy.
*Checkpoint:* a real Calendly account connects; its event types appear in the import UI; importing one as native creates a working native booking type the host can run with Calendly disconnected.

### Phase B — Calendly proxy (embed + webhooks)
For hosts who keep Calendly live: booking page branches to `getEmbedHtml()` with tracking params; webhook registration, signature verification, `invitee.created`/`invitee.canceled` ingestion into `bkn_bookings`; the reconciliation sync task (`ProviderSyncTask`).
*Checkpoint:* booking through the embedded widget produces a local Booking row; canceling in Calendly cancels it locally.

### Phase C — Acuity proxy (headless)
`AcuitySchedulingProvider`: API-key connection, `listEventTypes()` for import, `getAvailableSlots()`/`createBooking()`/`cancelBooking()` against the Acuity API, webhook ingestion for Acuity-side changes.
*Checkpoint:* an Acuity account connects; its types import (native or proxy); a proxy Acuity type books through the native slot picker and the appointment shows in Acuity.

### Phase D — External calendar sync, Google then Microsoft (lowest priority)
- **D.1 Connections + consent (Google).** `CalendarConnection` + busy-cache classes, `CalendarSyncOAuthConsumer`, `/profile/bookings/calendars` connect/disconnect UI. *Checkpoint:* OAuth consent round-trips; encrypted token stored; connection listed.
- **D.2 Busy read (Google).** Fetch via the Calendar API, on-demand TTL cache, `ExternalCalendarItemSource`, live freshness check wired into booking confirmation. *Checkpoint:* an event created directly in Google Calendar suppresses the matching slot.
- **D.3 Write-back (Google).** Create external event on confirmation, store external id, delete on cancellation. *Checkpoint:* a booking appears on the host's Google Calendar and disappears on cancel.
- **D.4 Microsoft.** Same three capabilities via the Graph API, behind the now-proven seams. *Checkpoint:* connect, busy-read, and write-back all work against an Outlook calendar.

---

## Deletion strategy (additions to the native spec)

- **CalendarConnection** — disconnect deletes cached busy blocks; events already written to the external calendar are left in place.
- **ProviderConnection** — disconnect deactivates the host's proxy booking types for that provider (migrated native types are unaffected — they have no connection); ingested bookings remain.
- **User as host** — extends the native `$foreign_key_actions`: host deletion also deletes their calendar connections and provider connections.

---

## Files

**Create (core):** `data/calendar_connections_class.php`, `data/calendar_busy_blocks_class.php`, `includes/calendar/item_sources/ExternalCalendarItemSource.php`, `includes/oauth/providers/CalendlyOAuthProvider.php`, `includes/oauth/consumers/CalendarSyncOAuthConsumer.php`.

**Create (plugin):** `includes/scheduling_providers/CalendlySchedulingProvider.php`, `includes/scheduling_providers/AcuitySchedulingProvider.php`, `includes/oauth_consumers/CalendlySchedulingOAuthConsumer.php`, `data/provider_connections_class.php`, profile views (provider connections, calendar connections), ajax (`ajax/webhook_calendly.php`, `ajax/webhook_acuity.php`), `tasks/ProviderSyncTask.{json,php}`.

**Modify:** `plugins/bookings/data/bookings_class.php` (`bkn_external_calendar_event_id` write-back handle, if not already added natively), `theme/tailwind/views/booking.php` (embed branch for proxy Calendly types), `settings.json` (Calendly OAuth credentials), `plugin.json` (connection menu items).

---

## Documentation

When implemented, fold into `plugins/inbound_email`-style plugin docs under `plugins/bookings/docs/` — an "external integrations" page covering connect, import (migrate vs proxy), and calendar sync. Current-state only, per the docs rules.
