# Spec: ScrollDaddy Query Log — User-Facing Log Viewer

**Status:** Pending implementation
**Plugin:** `scrolldaddy`
**Related feature:** Device management, per-device query logging

---

## Overview

Three related changes:

1. **Device edit page** — Add a toggle for the user to enable/disable query logging on each device.

2. **Actions dropdown** — Add a "View Query Log" item to the per-device actions dropdown, visible only when the device has logging enabled.

3. **Query log page** — A new page at `/profile/querylog?device_id=X` that fetches and displays the last N query log entries from the DNS server, with a "Clear Log" action.

---

## Background: How Logging Works

The DNS server writes per-device query logs to flat files keyed by `sdd_resolver_uid`. It reads the `sdd_log_queries` boolean from its in-memory cache (loaded from the database at each reload). Toggling the flag in Joinery takes effect on the DNS server's next cache reload — no additional API call is required.

The DNS server exposes two API endpoints used by this feature:

- `GET /device/{uid}/log?lines=N` — Returns the last N lines of the device's log as tab-separated plain text (auth: `X-API-Key`)
- `POST /device/{uid}/log/purge` — Truncates the log file; returns `{"status":"purged"}` (auth: `X-API-Key`)

Log line format (tab-separated):
```
2026-04-06T12:34:56Z  facebook.com  A  BLOCKED  category_blocklist  ads  no
```
Fields: `timestamp`, `domain`, `qtype`, `result`, `reason`, `category`, `cached`

---

## Change 1: Logging Toggle on Device Edit Page

### Location

Add to `plugins/scrolldaddy/views/profile/device_edit.php` and its logic file `plugins/scrolldaddy/logic/device_edit_logic.php`.

### UI

Add a yes/no dropdown after the timezone field (before the submit button):

```
Query Logging
[ Off ▼ ]       (options: Off / On)
```

Only show this field when editing an **existing** device (not when creating a new one — `$device->key` is set). There is no meaningful log to configure for an unactivated device, but it is acceptable to show the field regardless of activation state; the DNS server simply won't write logs for an inactive device.

Include a short explanation beneath the field:

> When on, your device's DNS queries are logged on the ScrollDaddy server. Logs include the domain name, query type (A/AAAA), result (blocked/allowed), and timestamp. No IP addresses are stored. You can view and clear your logs at any time.

### Logic changes

In `device_edit_logic.php`, when processing a POST that includes `device_id` (edit, not create):

- Read `sdd_log_queries` from POST: accept `'1'` as true, anything else as false.
- Set on the device object and save.

No DNS server API call is needed when toggling — the server picks up the change on next reload.

---

## Change 2: "View Query Log" in Actions Dropdown

### Location

`plugins/scrolldaddy/views/profile/devices.php` — the active device card actions dropdown.

### Rule

Add "View Query Log" as a fourth dropdown item **only when `$device->get('sdd_log_queries')` is true**. The item links to `/profile/querylog?device_id=X`.

Dropdown item order (active device):
1. Connection Details → `/profile/activation?device_id=X`
2. Edit Device → `/profile/device_edit?device_id=X`
3. Test a Domain/Page → `/profile/test?device_id=X`
4. View Query Log → `/profile/querylog?device_id=X` *(only when logging is on)*

---

## Change 3: Query Log Page

### Route

`/profile/querylog?device_id=X`

Add to `plugins/scrolldaddy/serve.php` alongside the other `/profile/*` routes:

```php
'/profile/querylog' => [
    'view' => 'views/profile/querylog',
    'plugin_specify' => 'scrolldaddy'
],
```

### Access

- Must be logged in; redirect to `/login` if not.
- `device_id` must be present and owned by the logged-in user; redirect to `/profile/devices` if not.
- If the device is not activated, show an appropriate message (no log to view).
- If logging is off on the device, show a message explaining that logging is not enabled, with a link to the device edit page to turn it on.

### Page Layout

**Breadcrumbs:**
```php
'breadcrumbs' => array(
    'Devices' => '/profile/devices',
    'Query Log' => '',
),
```

**Title:** `Query Log`

**Page header:** `Query Log — [Device Name]`

### Log Retrieval

The logic file calls the DNS server:

```
GET {scrolldaddy_dns_internal_url}/device/{sdd_resolver_uid}/log?lines={n}
X-API-Key: {scrolldaddy_dns_api_key}
```

- Default `n`: 100. Supported values: 100, 250, 500. Read from `$_GET['lines']`; clamp to the nearest supported value.
- 10-second cURL timeout.
- On error (non-2xx, cURL failure, or empty/missing body), pass an error flag to the view rather than failing silently.
- Parse the plain-text response: split on newlines, split each line on tab, skip malformed lines.

The logic file passes to the view:
- `$device` — the loaded device object
- `$device_name` — display name string
- `$lines` — array of parsed log entries (each an associative array: `timestamp`, `domain`, `qtype`, `result`, `reason`, `category`, `cached`)
- `$lines_requested` — integer (100/250/500)
- `$fetch_error` — bool, true if the DNS server call failed

### Display

**Controls row** (above the table):

- "Show" selector: `100 / 250 / 500` entries — submits a GET form to reload the page with `?device_id=X&lines=N`. No JavaScript required for this control.
- "Clear Log" button — opens a native `confirm()` dialog, then POSTs to `/ajax/purge_querylog` with `device_id`. On success, reloads the page. Vanilla JS only.

**Log table columns:**

| Time | Domain | Type | Result | Reason | Cached |
|------|--------|------|--------|--------|--------|

- **Time**: format as local device timezone (use `sdd_timezone` from the device; parse the UTC ISO timestamp and shift it). Display as `Apr 6, 2026 12:34:55 PM EDT` or similar readable format.
- **Result**: styled badge — BLOCKED (red), REFUSED (orange), FORWARDED (green), others (grey).
- **Reason**: human-readable. Map using the same reason codes from `test_domain.php`:
  - `category_blocklist` + category → `Blocked: [Category Name]` (use the category display name map from `test_domain.php`)
  - `custom_block_rule` → `Custom block rule`
  - `custom_allow_rule` → `Custom allow rule`
  - `safesearch_rewrite` → `SafeSearch rewrite`
  - `safeyoutube_rewrite` → `Safe YouTube rewrite`
  - `not_blocked` → `Allowed`
  - `unknown_device` → `Unknown device`
  - `inactive_device` → `Device inactive`
  - `upstream_failed` → `Upstream DNS failed`
  - Anything else: show raw reason string
- **Cached**: `Yes` / `No`

**Empty state:** If `$lines` is empty and no fetch error, show: `No queries logged yet. Queries will appear here once the device starts using ScrollDaddy DNS.`

**Fetch error state:** If `$fetch_error` is true, show: `Could not retrieve log from the DNS server. Please try again in a moment.`

**Logging off state:** If `sdd_log_queries` is false, show: `Query logging is not enabled for this device. [Enable it on the device edit page].`

---

## AJAX: Purge Endpoint

**File:** `plugins/scrolldaddy/ajax/purge_querylog.php`
**Method:** POST
**Parameters:** `device_id` (integer)

- Must be logged in.
- Load device, verify ownership (`authenticate_read`).
- Device must be active and have logging enabled.
- Call `POST {dns_url}/device/{uid}/log/purge` with `X-API-Key` header.
- Return JSON: `{"success": true}` or `{"success": false, "message": "..."}`.
- 10-second cURL timeout.

---

## Files to Create or Modify

| File | Change |
|------|--------|
| `plugins/scrolldaddy/serve.php` | Add route for `/profile/querylog` |
| `plugins/scrolldaddy/views/profile/device_edit.php` | Add `sdd_log_queries` toggle field with explanation |
| `plugins/scrolldaddy/logic/device_edit_logic.php` | Handle `sdd_log_queries` in POST processing |
| `plugins/scrolldaddy/views/profile/devices.php` | Add "View Query Log" item to active device dropdown (conditional) |
| `plugins/scrolldaddy/views/profile/querylog.php` | New: query log page view |
| `plugins/scrolldaddy/logic/querylog_logic.php` | New: loads device, fetches log from DNS server |
| `plugins/scrolldaddy/ajax/purge_querylog.php` | New: purge log AJAX endpoint |

---

## Out of Scope

- Real-time / streaming log updates
- Filtering or searching within the log
- Log export (CSV, etc.)
- Admin-side log viewing
- Per-entry deletion
