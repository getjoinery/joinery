# Spec: ScrollDaddy Domain/Page Test — Dedicated Page + Actions Dropdown

**Status:** Pending implementation  
**Plugin:** `scrolldaddy-html5`  
**Related feature:** Domain Test (devices page, per-device card)

---

## Overview

Three related changes:

1. **Actions dropdown** — Replace the "i" (connection details) and pencil (edit device) icon links in the top-right of each device card with a single actions dropdown menu. The dropdown contains all per-device actions, including the new "Test a Domain/Page" option.

2. **Dedicated test page** — Move the "Test a Domain/Page" feature out of the inline card UI and into its own page (`/profile/test`), which the dropdown links to. The card-level inline test widget is removed entirely.

3. **URL scan mode** — The test page gains a second mode: if the user's input includes a path, it fetches that page, extracts all external domains, and checks each one against the filter, returning a grouped summary.

---

## Change 1: Actions Dropdown on Device Cards

### Current UI (to be replaced)

Each active device card's top-right `.icon` area contains two sibling links:

```html
<div class="icon">
    <a href="/profile/activation?device_id=X" title="Connection Details"><!-- i icon --></a>
    <a href="/profile/device_edit?device_id=X"><!-- edit/pencil icon --></a>
</div>
```

Inactive (not yet activated) cards have only the edit link.

### New UI

**Active device cards (3 actions):** Replace the `.icon` content with a dropdown button. Implement in vanilla JS (scrolldaddy-html5 is a zero-dependency theme — no Bootstrap, no jQuery). Toggle a class on click, close on outside click. The trigger is a small button with a chevron or ellipsis icon, styled to fit the card header.

Dropdown items:
- Connection Details → `/profile/activation?device_id=X`
- Edit Device → `/profile/device_edit?device_id=X`
- Test a Domain/Page → `/profile/test?device_id=X`

**Inactive device cards (1 action):** Replace the `.icon` content with a plain link (no dropdown): `Edit Device` → `/profile/device_edit?device_id=X`.

### Inline test widget removal

The "Test a Domain/Page" section at the bottom of each active device card (search icon row, input, Test button, result div) is removed entirely. The rest of the card body is unchanged.

---

## Change 2: Dedicated Test Page

**Route:** `/profile/test?device_id=X`

**serve.php entry required:** Yes — all `/profile/*` pages require explicit entries. Add alongside the existing profile routes using the same `dynamic` route pattern.

**Access:** Must be logged in; user must own the device. If `device_id` is missing or the device doesn't belong to the user, redirect to `/profile/devices`.

**Active devices only:** If the device exists but is not yet activated, show an appropriate message explaining that the test feature requires an activated device.

### Page Layout

**Breadcrumbs:**
```php
'breadcrumbs' => array(
    'Devices' => '/profile/devices',
    'Test a Domain/Page' => '',
),
```

**Title:** `Test a Domain/Page`

**Page header:** `Test a Domain/Page — [Device Name]`

The test UI:
- A single text input with placeholder `e.g. facebook.com or https://example.com/page`
- A "Test" button
- A result area below

The logic file loads the device and passes `$device` and `$device_name` to the view. AJAX calls are made from the browser.

### New files

| File | Purpose |
|------|---------|
| `plugins/scrolldaddy-html5/views/profile/test.php` | Test page view |
| `plugins/scrolldaddy-html5/logic/test_logic.php` | Loads and validates device, passes to view |

The existing `ajax/test_domain.php` is reused for single-domain checks. `ajax/scan_url.php` handles URL scans (see Change 3).

---

## Change 3: URL Scan Mode on the Test Page

### Input Detection

Mode is determined by whether a path component is present after the hostname:

1. Strip any `http://` or `https://` prefix
2. Check if the remaining string contains a `/` followed by at least one non-empty character

- **Domain mode** (existing behavior): no path — e.g. `facebook.com` or `https://facebook.com`
- **URL scan mode** (new): path present — e.g. `facebook.com/about` or `https://example.com/some-page`

### Domain Mode Results (unchanged)

Single result: domain, status (BLOCKED/ALLOWED/REWRITTEN/REFUSED), reason detail, active profile name.

### URL Scan Results Display

**Loading state:** `Fetching page... (this may take a few seconds)`

**On completion:**

Header: `Scan complete: X domains checked (Y blocked, Z allowed)`

If the domain cap was reached: show a notice — `Showing first 50 external domains found.`

Results grouped by status, most actionable first:

1. **Blocked** (red)
2. **Refused** (orange)
3. **Rewritten** (blue) — SafeSearch or SafeYouTube rewrites
4. **Allowed** (collapsed by default, toggle to expand)

Each entry: domain name + reason/detail string.

**Error cases:**
- Page fetch failed: clear error, no partial results
- No external domains found: `No external domains found on this page`
- Malformed input: inline validation before any request

---

## Backend: URL Scan Endpoint

**File:** `plugins/scrolldaddy-html5/ajax/scan_url.php`  
**Method:** POST (URL may be long; avoid GET query string limits)  
**Parameters:** `device_id` (integer), `url` (string)

### Security

- Same device ownership check as `test_domain.php`
- URL must use `http` or `https` scheme — reject others
- SSRF protection: resolve the hostname before fetching; reject if it resolves to any RFC 1918 address (10.x, 172.16–31.x, 192.168.x) or loopback (127.x, ::1)
- Cap extracted domains at 50 — if more are found, proceed with the first 50 and note this in the response

### Page Fetching

cURL with:
- Realistic browser User-Agent string
- 15-second timeout
- Follow up to 5 redirects
- Only accept `text/html` responses (check Content-Type; abort on anything else)

If no protocol is present in the input, prepend `https://`.

After following redirects, use the **final resolved URL** to determine the page's own domain for exclusion purposes (not the original input URL).

### Domain Extraction

Parse the fetched HTML and extract hostnames from:

- `<script src="...">`
- `<link href="...">` (stylesheets, preloads)
- `<img src="...">`
- `<source srcset="...">` — srcset contains comma-separated entries; each entry is one or more whitespace-separated tokens where the first token is the URL
- `<iframe src="...">`
- `<video src="...">`, `<audio src="...">`
- `<form action="...">`
- CSS `url(...)` references within inline `<style>` blocks

For each URL found:
1. Parse the hostname
2. Skip relative URLs (no hostname)
3. Skip non-HTTP schemes (data:, blob:, javascript:, etc.)
4. **Skip if the hostname shares the same registrable domain as the scanned page** — e.g. scanning `example.com` also skips `static.example.com`, `cdn.example.com`. Match on the last two domain labels (or three for known second-level TLDs like `.co.uk` — a simple heuristic is fine, no need for a full public suffix library).
5. Deduplicate — check each unique hostname once

### Domain Checking

For each extracted hostname, call the DNS server `/test` endpoint using the device's `sdd_resolver_uid`.

Run checks with a concurrency of 5 at a time. Apply a 30-second total wall-clock limit across all checks. If the limit is hit, return whatever results have completed with `"truncated": true` in the response.

### Response Format

```json
{
  "success": true,
  "mode": "url_scan",
  "scanned_url": "https://example.com/some-page",
  "page_domain": "example.com",
  "domains_found": 62,
  "domains_checked": 50,
  "capped": true,
  "truncated": false,
  "results": [
    {
      "domain": "doubleclick.net",
      "result": "BLOCKED",
      "reason": "category_blocklist",
      "category": "ads",
      "detail": "Ads (Strict)",
      "profile": "Primary"
    },
    {
      "domain": "fonts.googleapis.com",
      "result": "FORWARDED",
      "reason": "not_blocked",
      "detail": "Not matched by any filter or rule. Queries are forwarded to upstream DNS.",
      "profile": "Primary"
    }
  ]
}
```

`capped` is true when more than 50 domains were found and the list was trimmed. `truncated` is true when the 30-second wall-clock limit was hit before all checks completed.

---

## Files to Modify or Create

| File | Change |
|------|--------|
| `plugins/scrolldaddy-html5/serve.php` | Add route entry for `/profile/test` |
| `plugins/scrolldaddy-html5/views/profile/devices.php` | Replace `.icon` with dropdown (active) or plain link (inactive); remove inline test widget |
| `plugins/scrolldaddy-html5/views/profile/test.php` | New: test page view |
| `plugins/scrolldaddy-html5/logic/test_logic.php` | New: loads and validates device for test page |
| `plugins/scrolldaddy-html5/ajax/scan_url.php` | New: URL fetch + multi-domain check endpoint |

`ajax/test_domain.php` is unchanged.

---

## Out of Scope

- Scanning JavaScript-loaded resources (XHR, fetch, dynamic imports) — static HTML parsing only
- Parsing external stylesheets for additional resource URLs
- Headless browser rendering
- Storing or logging scan results
- Batch scanning of multiple URLs
