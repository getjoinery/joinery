# Calendly Integration Spec

## Overview

Complete the partially-built Calendly integration in the Bookings plugin. The integration syncs Calendly event types and scheduled events into the Joinery booking system, and embeds a Calendly scheduling widget on the public booking page.

## Current State

The integration is ~40-50% complete. The data layer and API client are mostly built but everything is disabled.

### What exists and works (data layer):

- **Database fields**: `bkn_calendly_event_uri` on Booking, `bkt_calendly_event_type_uri` on BookingType, `usr_calendly_uri` on User
- **Settings** (in `stg_settings`): `calendly_organization_uri`, `calendly_organization_name`, `calendly_api_key`, `calendly_api_token`
- **Model methods**: `Booking::GetByCalendlyUri()`, `BookingType::GetByCalendlyUri()`, `User::GetByCalendlyUri()` with proper Multi-class filter support
- **Admin UI**: Settings fields for all 4 Calendly credentials in `admin_settings.php`, "Sync with Calendly" links on admin booking pages, Calendly URI field on user edit page
- **Admin booking page** (`admin_booking.php`): Already calls `BookingType::GetByCalendlyUri()` to display the linked booking type

### What exists but is disabled:

| File | Purpose | Status |
|------|---------|--------|
| `utils/calendly_synchronize.php` | Pull sync: event types → BookingType, scheduled events → Booking | Core logic written, disabled on line 15-17 |
| `ajax/calendly_webhook.php` | Webhook: handle `invitee.created` | Basic handler, disabled on line 9-11 |
| `ajax/calendly_webhook_cancel.php` | Webhook: handle `invitee.canceled` | Basic handler, disabled on line 8-10 |
| `ajax/calendly_init.php` | Unknown purpose (disabled immediately) | Disabled on line 8-10 |
| `utils/calendly_get_uri.php` | Fetches current user's Calendly URI | Disabled |
| `theme/tailwind/views/booking.php` | Embeds Calendly inline widget | Code exists but only in tailwind theme |
| `tests/integration/calendly_test.php` | API connectivity test | Disabled |

### What the sync script does (when enabled):

1. Calls Calendly API to fetch event types for the organization
2. For each event type: creates or updates a `BookingType` record, linking by `bkt_calendly_event_type_uri`
3. Calls Calendly API to fetch scheduled events
4. For each event: creates or updates a `Booking` record, linking by `bkn_calendly_event_uri`
5. For each booking: fetches invitees, links client user by email (or creates a new User)

### API client functions (in `calendly_synchronize.php`):

- `get_event_types_info($event_uri, $min_start_time, $status)` — GET `/event_types`
- `get_booking_info($event_uri, $min_start_time, $status)` — GET `/scheduled_events`
- `get_booking_invitees($event_uri, $status)` — GET `/scheduled_events/{id}/invitees`
- `get_organization_for_user($user_uri)` — GET `/organization_memberships`

All use curl with Bearer token auth from `calendly_api_token` setting.

---

## Issues to Fix

### 1. Code quality in `calendly_synchronize.php`

- Uses old-style includes (`require_once(__DIR__ . '/../...')`) — should use PathHelper
- Has `echo` debug statements throughout (lines 32-33, 57, 86, 96-98, 101, 118, 129-131) — should use proper logging or remove
- Error handling is `echo + exit` (line 62) — should use LogicResult or exceptions
- API functions are loose functions at bottom of file — should be extracted to a `CalendlyHelper` class in `includes/`
- No pagination handling — hardcoded `count=100`, Calendly API may return more
- No curl error checking — `curl_exec()` result is decoded directly without checking for `false`
- `get_organization_for_user()` references undefined `$extra_params` variable (line 209)

### 2. Webhook handlers use wrong field names

- `calendly_webhook.php` uses `prd_status`, `prd_time`, `prd_link` — these look like old `ProductDetail` fields, not `Booking` fields (`bkn_status`, `bkn_start_time`, `bkn_location`)
- `calendly_webhook_cancel.php` references `ProductDetail` class and `prd_status` — should be `Booking` class
- Both webhooks use the old Calendly v1 webhook payload format (nested `payload.event` structure). The current Calendly API v2 uses a different webhook payload format. Need to verify which API version is targeted.

### 3. Frontend widget (tailwind theme only)

- `theme/tailwind/views/booking.php` has the Calendly inline widget code but it's only in the tailwind theme
- Uses `salesforce_uuid` as a tracking parameter to link back to Joinery records
- Needs to be available in the default theme too, or made into a component

### 4. Missing: Webhook registration

- No code exists to register webhook subscriptions with the Calendly API
- Calendly requires POST to `/webhook_subscriptions` to set up webhook delivery
- Need to register for `invitee.created` and `invitee.canceled` events
- Should be part of admin setup flow or a one-time registration script

### 5. Missing: Feature gating

- No setting to enable/disable Calendly integration globally
- The "Sync with Calendly" admin links always show even if Calendly isn't configured
- The booking widget should only embed if the booking type has a Calendly URI

---

## Implementation Plan

### Phase 1: Extract and clean up the API client

**Create `includes/CalendlyHelper.php`:**
- Extract all 4 API functions from `calendly_synchronize.php` into a proper class
- Add curl error checking (check for `false` return, check HTTP status codes)
- Add pagination support (Calendly uses `next_token` cursor pagination)
- Add a `is_configured()` static method that checks if the required settings are populated
- Fix the undefined `$extra_params` bug in `get_organization_for_user()`

### Phase 2: Fix the sync script

**Update `utils/calendly_synchronize.php`:**
- Remove the disable block (lines 15-17)
- Use PathHelper for includes
- Replace echo debug output with proper admin page output (use AdminPage)
- Use CalendlyHelper for API calls
- Add try/catch error handling around API calls and saves
- Handle the case where `$booking->location` might be null (line 102)

### Phase 3: Fix webhook handlers

**Update `ajax/calendly_webhook.php`:**
- Remove disable block
- Update to use Booking class with correct field names (`bkn_status`, `bkn_start_time`, `bkn_location`)
- Update payload parsing for Calendly API v2 webhook format
- Add webhook signature verification (Calendly signs webhooks with a signing key)
- Add error logging

**Update `ajax/calendly_webhook_cancel.php`:**
- Remove disable block
- Replace `ProductDetail` with `Booking` class
- Update field names to `bkn_` prefix
- Update payload parsing for v2 format
- Add webhook signature verification
- Add error logging

### Phase 4: Add webhook registration

**Create admin action or script for webhook setup:**
- POST to `https://api.calendly.com/webhook_subscriptions` with:
  - `url`: the webhook endpoint URL (e.g., `https://site.com/ajax/calendly_webhook`)
  - `events`: `["invitee.created", "invitee.canceled"]`
  - `organization`: from `calendly_organization_uri` setting
  - `scope`: `organization`
  - `signing_key`: store in settings for verification
- Add a "Register Webhooks" button to admin settings or the booking admin page
- Store the webhook subscription URI so it can be updated/deleted later

### Phase 5: Feature gating and UI

- Add a `calendly_active` setting (boolean) to gate the integration
- Conditionally show "Sync with Calendly" links only when configured
- Move the Calendly inline widget from tailwind theme to the default theme's `booking.php`
- Only embed the widget if the booking type has a `bkt_calendly_event_type_uri` set

### Phase 6: Re-enable and test

- Remove disable blocks from all files
- Re-enable `tests/integration/calendly_test.php`
- Test the full flow: configure credentials → sync event types → sync bookings → webhook events
- Verify the embedded widget works and passes the salesforce_uuid correctly

---

## Files to Create

| File | Purpose |
|------|---------|
| `includes/CalendlyHelper.php` | API client class |

## Files to Modify

| File | Changes |
|------|---------|
| `utils/calendly_synchronize.php` | Remove disable, use CalendlyHelper, clean up output |
| `ajax/calendly_webhook.php` | Remove disable, fix field names, update to v2 payload |
| `ajax/calendly_webhook_cancel.php` | Remove disable, fix class/field names, update to v2 payload |
| `ajax/calendly_init.php` | Evaluate if still needed; remove if not |
| `utils/calendly_get_uri.php` | Update to use CalendlyHelper |
| `tests/integration/calendly_test.php` | Remove disable, use CalendlyHelper |
| `plugins/bookings/admin/admin_booking_types.php` | Gate "Sync with Calendly" link behind `calendly_active` |
| `plugins/bookings/admin/admin_bookings.php` | Gate "Sync with Calendly" link behind `calendly_active` |
| `views/booking.php` or theme equivalent | Add Calendly widget embed (gated by booking type having a Calendly URI) |

## Documentation

Update `docs/plugin_developer_guide.md` or create a new `docs/calendly_integration.md` with setup instructions (how to get API token, configure settings, register webhooks).
