# ScrollDaddy Domain Test Tool

**Status:** Draft

---

## Summary

Add a "Test Domain" feature to the ScrollDaddy plugin that lets users enter a domain name and see whether it would be blocked or allowed for a specific device, including which filter category or custom rule caused the result and which profile (primary/secondary) is active. This mirrors the domain lookup feature in ControlD.

The DNS server already has a `/test` endpoint that does the heavy lifting — this spec covers the user-facing UI and the PHP bridge to call it.

---

## UI Changes

### Devices page (`/profile/devices`)

Each active device card currently has three sections:
1. Default blocklist (primary profile) → Edit
2. Scheduled blocklist (secondary profile) → Edit / Create
3. Connection Details → Set Up

Two changes:

**Connection Details moves to an icon.** Remove the full "Connection Details" section from the card body. Add an info icon (`fa-regular fa-circle-info`) to the card header row, to the left of the existing edit icon. Clicking it navigates to `/profile/ctld_activation?device_id={id}` (same destination as the current "Set Up" button).

```
Before header:  [Active: Primary]                    [edit]
After header:   [Active: Primary]              [info] [edit]
```

**Test a Domain becomes the third section**, replacing Connection Details:

```
┌──────────────────────────────────────────┐
│ Active: Primary · 2m ago        [i] [✎] │
│ My iPhone                                │
├──────────────────────────────────────────┤
│ 🛡 Default blocklist                     │
│   3 services blocked                [Edit]│
├──────────────────────────────────────────┤
│ 🛡 Scheduled blocklist                   │
│   5 services blocked  M-F 8am-3pm  [Edit]│
├──────────────────────────────────────────┤
│ 🔍 Test a Domain                         │
│   [facebook.com              ] [Test]    │
│                                          │
│   ✗ facebook.com — Blocked               │
│     Matched category: Social Media       │
│     Active profile: Secondary (sched.)   │
└──────────────────────────────────────────┘
```

### Test a Domain section

- Text input (placeholder: "e.g. facebook.com") + "Test" button in a single row
- Result div (initially hidden) appears below the input after testing
- Vanilla JS (no jQuery) handles the AJAX call and result rendering
- Pressing Enter in the input field also triggers the test

### Result Display

**Allowed result:**
```
✓ google.com — Allowed
  Not matched by any filter or rule. Queries are forwarded to upstream DNS.
```

**Blocked by category:**
```
✗ doubleclick.net — Blocked
  Matched category: Ads (Small)
  Active profile: Primary
```

**Blocked by custom rule:**
```
✗ tiktok.com — Blocked
  Matched custom rule: tiktok.com
  Active profile: Primary
```

**Allowed by custom rule (override):**
```
✓ example.com — Allowed (custom rule)
  Matched allow rule: example.com
  Active profile: Primary
```

**SafeSearch/SafeYouTube rewrite:**
```
↻ www.google.com — Rewritten (SafeSearch)
  Redirected to: forcesafesearch.google.com
  Active profile: Primary
```

**Schedule-aware (secondary profile active):**
```
✗ pornhub.com — Blocked
  Matched category: Adult Content
  Active profile: Secondary (schedule active)
```

---

## Architecture

```
Browser                      Web Server                    DNS Server
   │                            │                              │
   ├─ AJAX GET ────────────────>│                              │
   │  /ajax/scrolldaddy/        │                              │
   │  test_domain?              │                              │
   │  device_id=X&domain=Y      │                              │
   │                            ├─ GET ───────────────────────>│
   │                            │  http://{dns_url}/test       │
   │                            │  ?uid={resolver_uid}         │
   │                            │  &domain={domain}            │
   │                            │  X-API-Key: {key}            │
   │                            │                              │
   │                            │<──── JSON response ──────────│
   │<──── JSON response ────────│                              │
   │                            │                              │
   └─ Render result inline      │                              │
```

### Why a PHP proxy?

The DNS server is on a different host and requires an API key. The browser can't call it directly. The PHP endpoint:
- Validates the user owns the device
- Looks up the device's `resolver_uid`
- Adds the API key header
- Forwards to the DNS `/test` endpoint
- Returns the result to the browser

---

## Changes Required

### 1. DNS Server: Open `/test` to API key auth

Currently `/test` is `localhostOnly`. Change it to `requireAPIKey` (same change we made for `/reload`).

**File:** `scrolldaddy-dns/internal/doh/handler.go`

```go
// Before:
mux.HandleFunc("GET /test", localhostOnly(h.test))

// After:
mux.HandleFunc("GET /test", h.requireAPIKey(h.test))
```

### 2. PHP AJAX endpoint

**File:** `plugins/scrolldaddy/ajax/test_domain.php`

- Require authentication (user must be logged in)
- Accept `device_id` and `domain` parameters
- Load the `CtldDevice` by ID, call `authenticate_read()` to verify ownership
- Get the device's `resolver_uid`
- Call the DNS server's `/test` endpoint with `X-API-Key` header
- Map the raw response into user-friendly labels (category keys to display names, reason codes to descriptions)
- Return JSON

**Category display name mapping:**
| Key | Display Name |
|-----|-------------|
| ads_small | Ads (Light) |
| ads_medium | Ads (Medium) |
| ads | Ads (Strict) |
| malware | Malware |
| ip_malware | Malware + IP Threats |
| ai_malware | Malware + Phishing |
| typo | Phishing & Typosquatting |
| porn | Adult Content |
| porn_strict | Adult Content (Strict) |
| gambling | Gambling |
| social | Social Media |
| fakenews | Disinformation |
| cryptominers | Cryptomining |
| dating | Dating |
| drugs | Drugs |
| games | Gaming |
| ddns | Dynamic DNS |
| dnsvpn | DNS/VPN Bypass |

**Reason display mapping:**
| Reason code | User-facing text |
|-------------|-----------------|
| not_blocked | Not matched by any filter or rule. Queries are forwarded to upstream DNS. |
| category_blocklist | Matched category: {display_name} |
| custom_block_rule | Matched custom block rule: {matched_rule} |
| custom_allow_rule | Matched custom allow rule: {matched_rule} (overrides all blocking) |
| safesearch_rewrite | SafeSearch is enabled. Domain is rewritten to enforce safe results. |
| safeyoutube_rewrite | SafeYouTube is enabled. Domain is rewritten to restricted mode. |
| unknown_device | Device not found by DNS server. It may not have synced yet. |
| inactive_device | Device is deactivated. |
| profile_not_found | Profile configuration error. The assigned profile was not found. |

### 3. Devices page changes

**File:** `plugins/scrolldaddy/views/profile/devices.php`

For each active device card:

**a) Add info icon to card header.** In the `job-post_date` div, add an info icon link before the existing edit icon:
```html
<div class="icon">
    <a href="/profile/ctld_activation?device_id={id}"><i class="fa-regular fa-circle-info"></i></a>
    <a href="/profile/device_edit?device_id={id}"><i class="fa-regular fa-edit"></i></a>
</div>
```

**b) Replace "Connection Details" section with "Test a Domain".** Remove the existing Connection Details `job-post_author` block and replace with:
- A section matching the existing card style (`job-post_author` div with `fa-regular fa-magnifying-glass` icon)
- Title: "Test a Domain"
- Inline: text input + "Test" button
- Below: result div (hidden until a test is run)

**c) Inline JavaScript** (at bottom of page, or in a separate JS file):
- Strips protocol/path from input (user might paste `https://www.facebook.com/page` — extract `www.facebook.com`)
- Sends AJAX GET to `/ajax/scrolldaddy/test_domain?device_id={id}&domain={domain}`
- Renders result with appropriate icon and color (green for allowed, red for blocked, blue for rewrite)
- Shows schedule/profile info when relevant
- Each device card gets its own input/result — device_id is embedded as a data attribute

### 4. Route registration

**File:** `plugins/scrolldaddy/serve.php`

Add AJAX route:
```php
'/ajax/scrolldaddy/test_domain' => array(
    'file' => 'plugins/scrolldaddy/ajax/test_domain.php',
),
```

---

## Edge Cases

- **Domain input cleanup:** Strip `https://`, `http://`, trailing paths, trailing dots, whitespace. Convert to lowercase.
- **DNS server unreachable:** Return a clear error ("DNS server is not responding. The test could not be completed.") rather than a generic failure.
- **Empty blocklist categories:** If the user has a filter enabled but the blocklist download failed for that category (e.g., porn list was unavailable), the domain won't show as blocked. This is correct behavior — we show what the DNS server would actually do.
- **Subdomain matching:** The DNS server already walks parent domains (e.g., `ads.facebook.com` checks `facebook.com`). The test endpoint handles this automatically.
- **Schedule timing:** The result reflects the *current* active profile based on the schedule. If the user tests during school hours vs evening, they may see different results. The response includes `schedule_active` and `profile_type` so the UI can indicate this.

---

## Out of Scope

- Testing against a specific profile (always tests against the currently active one based on schedule)
- Batch/bulk domain testing
- Historical query logs (what domains were actually queried)
- Modifying filters from the test result (e.g., "click to unblock")
