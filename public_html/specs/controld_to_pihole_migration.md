# ControlD Replacement: Self-Hosted DNS Filtering Service

**Status:** Draft
**Plugin:** controld (Scrolldaddy)
**Scope:** Replace ControlD cloud API with a self-hosted DNS filtering service that replicates ControlD's architecture

---

## 1. Background & Motivation

The Scrolldaddy product currently uses the ControlD cloud API for DNS-based content filtering. The business relationship with ControlD has ended. This spec covers building a self-hosted replacement that works the same way ControlD does -- per-device DNS resolver endpoints accessible over the internet.

### What ControlD Actually Does

ControlD's architecture is straightforward:

1. Each device gets a **unique DoH (DNS-over-HTTPS) resolver URL** like `https://dns.controld.com/abc123`
2. The device configures that URL as its DNS provider (works on any network, anywhere)
3. When a DNS query arrives at that URL, ControlD identifies the device by the URL path (`abc123`)
4. It looks up which profile/blocklists apply to that device
5. If the domain is blocked: returns NXDOMAIN
6. If the domain is allowed: forwards the query to upstream DNS and returns the answer

That's it. The "magic" is just **unique URL paths for device identification** + **a DNS proxy with per-profile blocklists**.

### Current Plugin Architecture

- **ControlDHelper.php** (1749 lines) -- wraps ControlD's REST API via curl
- **6 data model classes** -- CtldDevice, CtldProfile, CtldFilter, CtldService, CtldRule, CtldDeviceBackup
- **Local database** -- caches filter/service/rule state per profile (mirrors ControlD's state)
- **Views** -- device dashboard, filter editor, rule editor, scheduling UI
- **Logic files** -- device CRUD, filter toggling, rule management, scheduling

### Key Insight: We Already Have Most of It

The Joinery plugin already handles all the management:
- Creating/deleting devices and profiles
- Toggling filter categories on/off
- Managing custom block/allow rules
- Scheduling (primary + secondary profiles)
- Subscription tier limits (max devices, advanced filters, etc.)
- Deactivation PINs, edit restrictions, etc.

Currently, every management action makes TWO writes: one to the local database AND one to the ControlD API. **If we run our own DNS server that reads from our own database, we eliminate the API entirely.** The PHP side just writes to the database; the DNS server reads from it.

---

## 2. Target Architecture

```
                          Internet
                             |
                    [HTTPS Termination]
                      (Caddy / nginx)
                             |
                   [ScrollDaddy DNS Service]
                     (lightweight Go binary)
                        /          \
              DoH endpoint       Reads from
           /resolve/{uid}        PostgreSQL
                |                    |
         Per-device            Same database
         DNS filtering         as Joinery
                |
         [Upstream DNS]
        (1.1.1.1 / 8.8.8.8)
```

### Components

1. **ScrollDaddy DNS Service** -- A small Go program (~500-1000 lines) that:
   - Listens for DNS-over-HTTPS requests at `/resolve/{resolver_uid}`
   - Extracts the resolver UID from the URL path
   - Looks up which profile applies (from PostgreSQL, cached in memory)
   - Checks the queried domain against that profile's blocklists (in-memory hash sets)
   - Returns NXDOMAIN for blocked domains
   - Forwards allowed queries to upstream DNS (Cloudflare 1.1.1.1, Google 8.8.8.8)
   - Periodically reloads profiles and blocklists from the database

2. **HTTPS Termination** -- Caddy or nginx handles TLS certificates for `dns.scrolldaddy.app` (or similar domain) and proxies to the Go service

3. **Joinery Plugin (modified)** -- The existing PHP plugin, with ControlDHelper replaced by direct database writes. No external API calls needed.

4. **Blocklist Downloader** -- A cron job or scheduled task that fetches community blocklist URLs, parses them into domain lists, and stores them in the database

---

## 3. The DNS Service (Go)

### Why Go?

- `miekg/dns` is the standard DNS library, battle-tested, used by CoreDNS
- Single static binary, trivial to deploy
- Excellent concurrency for handling many DNS queries
- Small memory footprint with efficient hash set lookups

### Core Logic (pseudocode)

```
on HTTP request to /resolve/{resolver_uid}:
    1. Parse DNS query from DoH request body (application/dns-message)
    2. Look up resolver_uid in cache -> get profile_id
    3. If not found: return REFUSED
    4. Get queried domain name from DNS question
    5. Check domain against profile's blocked domains (hash set lookup)
       - Check exact match: "ads.example.com"
       - Check parent domains: "example.com", "com" (wildcard blocking)
    6. If blocked: return NXDOMAIN response
    7. If allowed: forward query to upstream DNS, return response
```

### DoH Protocol (RFC 8484)

DNS-over-HTTPS is simple:
- **POST** to endpoint with `Content-Type: application/dns-message`, body is raw DNS wire format
- **GET** with `?dns=` parameter (base64url-encoded DNS wire format)
- Response: `Content-Type: application/dns-message`, body is raw DNS wire format response

The `miekg/dns` library handles all the wire format parsing/serialization.

### In-Memory Data Structures

```go
type ProfileCache struct {
    // resolver_uid -> profile_id
    resolvers map[string]int64

    // profile_id -> set of blocked domains
    blockedDomains map[int64]map[string]bool

    // profile_id -> set of allowed domains (bypass rules)
    allowedDomains map[int64]map[string]bool

    // Reload interval
    reloadTicker *time.Ticker
}
```

**Memory estimation:** A blocklist of 500,000 domains uses ~50MB as a Go map. Most profiles will share the same underlying blocklist data, so using per-category domain sets with per-profile category membership keeps memory efficient.

Optimized structure:

```go
type BlocklistCache struct {
    // category_key -> set of domains in that category
    // e.g., "ads" -> {"doubleclick.net": true, "googlesyndication.com": true, ...}
    categories map[string]map[string]bool

    // profile_id -> list of enabled category keys
    // e.g., 42 -> ["ads", "malware", "porn"]
    profileCategories map[int64][]string

    // profile_id -> custom blocked domains (from cdr_ctldrules with action=0)
    customBlocked map[int64]map[string]bool

    // profile_id -> custom allowed domains (from cdr_ctldrules with action=1)
    customAllowed map[int64]map[string]bool

    // resolver_uid -> profile_id (active profile, considering schedule)
    resolvers map[string]int64
}
```

### Reload Strategy

The DNS service reloads data from PostgreSQL periodically:
- **Every 30-60 seconds:** Reload resolver->profile mappings and profile->category assignments (lightweight query)
- **Every 1-6 hours:** Reload full blocklist domain sets (heavier, only when blocklists are updated)
- **On signal (SIGHUP):** Force immediate full reload (triggered after blocklist download)

This means changes made through the Joinery UI take effect within 30-60 seconds without any API call to the DNS service.

### Scheduling Support

The DNS service handles scheduling natively by checking the schedule fields:

```go
func (c *BlocklistCache) getActiveProfileForResolver(uid string) int64 {
    device := c.devices[uid]
    if device.secondaryProfileID == 0 {
        return device.primaryProfileID
    }

    // Check if current time falls within schedule
    now := time.Now().In(device.timezone)
    currentDay := strings.ToLower(now.Format("Mon"))

    if !contains(device.scheduleDays, currentDay) {
        return device.primaryProfileID
    }

    if isInTimeRange(now, device.scheduleStart, device.scheduleEnd) {
        return device.secondaryProfileID
    }
    return device.primaryProfileID
}
```

No separate scheduled task needed -- the DNS service evaluates the schedule on every query. This is more accurate than polling and has zero delay.

### API Endpoints (minimal, for health/stats only)

The DNS service exposes a small internal API (not public-facing):

```
GET /health                    -- Health check
GET /stats                     -- Query counts, cache size, uptime
POST /reload                   -- Force reload from database
GET /resolve/{uid}?dns=...     -- DoH GET (public)
POST /resolve/{uid}            -- DoH POST (public)
```

### SafeSearch & SafeYouTube

These can be implemented directly in the DNS service as DNS rewrites per-profile:

```go
// If safesearch is enabled for this profile:
if profile.safesearch {
    rewrites := map[string]string{
        "www.google.com":    "forcesafesearch.google.com",
        "www.bing.com":      "strict.bing.com",
        "duckduckgo.com":    "safe.duckduckgo.com",
    }
    if cname, ok := rewrites[queryDomain]; ok {
        // Return CNAME response pointing to safe version
    }
}

// If safeyoutube is enabled:
if profile.safeyoutube {
    if queryDomain == "www.youtube.com" || queryDomain == "youtube.com" {
        // Return CNAME to restrict.youtube.com
    }
}
```

This is **per-profile**, unlike Pi-hole where it would be global.

---

## 4. Blocklist Management

### Blocklist Sources

Community-maintained blocklists replace ControlD's curated categories. Each category maps to one or more blocklist URLs:

| Category Key | Display Name | Blocklist Source(s) |
|-------------|--------------|-------------------|
| `ads_small` | Ads & Trackers (Relaxed) | EasyList |
| `ads_medium` | Ads & Trackers (Balanced) | EasyList + AdGuard DNS filter |
| `ads` | Ads & Trackers (Strict) | Steven Black unified hosts |
| `porn` | Adult Content | Steven Black porn extension, OISD NSFW |
| `porn_strict` | Adult Content (Strict) | Above + additional NSFW lists |
| `malware` | Malware (Relaxed) | URLhaus, abuse.ch |
| `ip_malware` | Malware (Balanced) | Above + Threatfox |
| `ai_malware` | Malware (Strict) | Above + PhishTank + OpenPhish |
| `gambling` | Gambling | Steven Black gambling extension |
| `social` | Social Media | Steven Black social extension |
| `fakenews` | Hoaxes & Disinfo | Steven Black fakenews extension |
| `cryptominers` | Cryptocurrency | NoCoin blocklist |
| `noai` | AI Services | Custom curated list |
| `dating` | Dating Sites | Custom curated list |
| `drugs` | Illegal Drugs | Custom curated list |
| `games` | Games | Custom curated list |
| `torrents` | Torrent Sites | Custom curated list |
| `typo` | Phishing | PhishTank, OpenPhish |
| `ddns` | Dynamic DNS | Custom curated list |
| `filehost` | File Hosting | Custom curated list |
| `gov` | Government | Custom curated list |
| `iot` | IoT | Custom curated list |
| `nrd_small` | New Domains (Week) | NRD feed (if available) |
| `nrd` | New Domains (Month) | NRD feed (if available) |
| `urlshort` | URL Shorteners | Custom curated list |
| `dnsvpn` | VPN & DNS Providers | Custom curated list |

### Blocklist Download & Storage

**New table: `scd_blocklist_sources`**

```
scd_blocklist_source_id (PK, serial)
scd_category_key (varchar) -- maps to filter category
scd_url (text) -- blocklist URL to download
scd_name (varchar) -- human-readable name
scd_format (varchar) -- 'hosts', 'domains', 'adblock' (parsing format)
scd_is_active (bool)
scd_last_download_time (timestamp)
scd_domain_count (int) -- number of domains after parsing
```

**New table: `scd_blocklist_domains`**

```
scd_blocklist_domain_id (PK, serial)
scd_category_key (varchar) -- which category this domain belongs to
scd_domain (varchar) -- the blocked domain
```

Index: `CREATE INDEX idx_blocklist_domain ON scd_blocklist_domains(scd_category_key, scd_domain)`

**Download process** (PHP cron job or scheduled task):
1. For each active source in `scd_blocklist_sources`, fetch the URL
2. Parse the file format (hosts file, domain list, or adblock format)
3. Extract domains, deduplicate
4. Delete old domains for that category, insert new ones
5. Send SIGHUP or POST /reload to the DNS service

### Service Blocking

ControlD's 400+ individual service toggles (Spotify, Facebook, etc.) are handled as **curated domain lists per service**, stored in the same blocklist infrastructure:

**New table: `scd_service_domains`**

```
scd_service_domain_id (PK, serial)
scd_service_key (varchar) -- e.g., 'spotify', 'facebook'
scd_service_category (varchar) -- e.g., 'audio', 'social'
scd_domain (varchar) -- domain to block
```

Pre-populated with domains for each service. When a user blocks "Spotify", the DNS service loads those domains into the profile's blocked set.

The existing `ControlDHelper::$services` array already has the service keys and categories. What's needed is the domain-to-service mapping data, which can be:
- Scraped from ControlD's public documentation (if available)
- Built manually from known service domains
- Sourced from community lists (e.g., `nextdns` has similar per-service domain lists)

**Phase approach:** Start with the top 20 most-used services. The infrastructure supports adding more over time.

---

## 5. Changes to the Joinery Plugin

### 5.1 Remove ControlDHelper.php entirely

Every place that calls `new ControlDHelper()` and makes API calls is replaced with direct database operations. The DNS service reads from the same database.

### 5.2 Device Creation (ctlddevice_edit_logic.php + CtldDevice)

**Current flow:**
1. Create local profile -> call ControlD API to create remote profile -> get profile ID
2. Call ControlD API to create device -> get device ID and resolver UID
3. Save IDs locally

**New flow:**
1. Create local profile (just a database row -- no API call)
2. Generate a unique resolver UID (e.g., `bin2hex(random_bytes(16))` = 32-char hex string)
3. Create local device with resolver UID
4. Done. The DNS service picks up the new resolver on its next reload (30-60 seconds).

```php
// OLD
$cd = new ControlDHelper();
$result = $cd->createProfile($name);
$profile1_key = $result['body']['profiles'][0]['PK'];
$result = $cd->modifyProfileOptions($profile1_key, 'b_resp', 1, 3, NULL);
// ... many more API calls ...

// NEW
$profile1 = new CtldProfile(NULL);
$profile1->set('cdp_usr_user_id', $user->key);
$profile1->set('cdp_is_active', true);
$profile1->save();
// That's it. No API calls.
```

### 5.3 Filter Toggling (CtldProfile::update_remote_filters)

**Current flow:** For each changed filter, call `$cd->modifyProfileFilter()` API, then update local cache.

**New flow:** Just update the local database. The DNS service reloads.

```php
// OLD: ~100 lines of API call + local cache sync logic
$result = $cd->modifyProfileFilter($this->get('cdp_profile_id'), $all_filter_key, $newvalue);
if($result['success']){ ... update local cache ... }

// NEW: Just update the local record
$filter->set('cdf_is_active', $newvalue);
$filter->save();
// DNS service picks it up on next reload
```

This simplifies `update_remote_filters()` from ~100 lines to ~30 lines. Same for `update_remote_services()`.

### 5.4 Custom Rules (CtldProfile::add_rule, delete_rule)

**Current flow:** Call ControlD API to create/delete rule, then update local DB.

**New flow:** Just update local DB.

```php
// OLD
$cd = new ControlDHelper();
$result = $cd->createRule($this->get('cdp_profile_id'), 1, $hostnames_array, null, $action);
if($result['success']){ ... save locally ... }

// NEW
$rule = new CtldRule(NULL);
$rule->set('cdr_cdp_ctldprofile_id', $this->key);
$rule->set('cdr_rule_hostname', $hostname);
$rule->set('cdr_is_active', 1);
$rule->set('cdr_rule_action', $action);
$rule->save();
```

### 5.5 Device Deletion (CtldDevice::permanent_delete)

**Current flow:** Call ControlD API to delete device and profiles, then delete locally.

**New flow:** Just delete locally. DNS service stops serving that resolver UID on next reload.

### 5.6 Scheduling (CtldProfile::add_or_edit_schedule)

**Current flow:** Call ControlD API to create/modify/delete schedule.

**New flow:** Just save schedule params to local DB. The DNS service evaluates schedules on every query (see Section 3).

### 5.7 Device Activation (CtldDevice::check_activate)

**Current flow:** Poll ControlD API for device status.

**New flow options:**

**Option A (simplest):** Auto-activate on creation. The device is "active" as soon as it's created -- the resolver UID works immediately. The user just needs to configure their device to use the DoH URL. No activation polling needed.

**Option B (verify):** The DNS service tracks query counts per resolver. The Joinery plugin queries the DNS service's `/stats/{uid}` endpoint to check if the device has made queries. Mark active when first query is detected.

**Recommendation:** Option A. Simplify the UX -- create device, get resolver URL, configure device, done. Remove the activation polling entirely. The `cdd_is_active` field becomes unnecessary or is set to true immediately.

### 5.8 Device Setup Instructions

After creating a device, show the user their unique DoH URL and setup instructions:

```
Your DNS resolver URL:
https://dns.scrolldaddy.app/resolve/a1b2c3d4e5f6...

To set up your device:
- iPhone/iPad: Settings > General > VPN & Device Management > DNS > Configure DNS > https://dns.scrolldaddy.app/resolve/a1b2c3d4e5f6...
- Android: Settings > Network > Private DNS > dns.scrolldaddy.app
- Windows: [instructions]
- Mac: [instructions]
```

Note: Android's Private DNS uses DNS-over-TLS (DoT), not DoH. The DNS service should also support DoT on port 853 for Android compatibility. Alternatively, provide a DNS profile/config file that users can install.

---

## 6. Database Changes

### 6.1 Settings Changes

| Old Setting | New Setting | Purpose |
|------------|-------------|---------|
| `controld_key` | Remove | No external API key needed |
| -- | `scrolldaddy_dns_host` | DNS service hostname (e.g., `dns.scrolldaddy.app`) |
| -- | `scrolldaddy_dns_internal_url` | Internal URL to DNS service for reload/stats (e.g., `http://localhost:8053`) |

### 6.2 Existing Table Changes

**CtldDevice (cdd_ctlddevices):**

| Field | Change | Notes |
|-------|--------|-------|
| `cdd_device_id` | Rename/repurpose to `cdd_resolver_uid` | Unique DoH path identifier (generated locally) |
| `cdd_controld_resolver` | Remove | Was ControlD's resolver endpoint |
| `cdd_profile_id_primary` | Remove | Was ControlD's remote profile ID (redundant with `cdd_cdp_ctldprofile_id_primary`) |
| `cdd_profile_id_secondary` | Remove | Was ControlD's remote profile ID (redundant) |
| `cdd_is_active` | Simplify | Set to true on creation (always active) |
| All other fields | Keep | device_name, device_type, user_id, timezone, deactivation_pin, allow_device_edits, etc. |

**CtldProfile (cdp_ctldprofiles):**

| Field | Change | Notes |
|-------|--------|-------|
| `cdp_profile_id` | Remove | Was ControlD's remote profile ID |
| `cdp_schedule_id` | Remove | Was ControlD's remote schedule ID |
| `cdp_safesearch` | Add (bool) | Per-profile SafeSearch toggle |
| `cdp_safeyoutube` | Add (bool) | Per-profile SafeYouTube toggle |
| All schedule fields | Keep | cdp_schedule_start, end, days, timezone |

**CtldFilter, CtldService, CtldRule:** No structural changes needed. These tables already store the right data -- they just no longer need to be "synced" to a remote API.

### 6.3 New Tables

**scd_blocklist_sources** -- Blocklist URL registry (see Section 4)

**scd_blocklist_domains** -- Parsed domains from blocklists (see Section 4)

**scd_service_domains** -- Domains per service for service-level blocking (see Section 4)

---

## 7. Implementation Plan

### Phase 1: DNS Service MVP

Build the Go DNS service with minimal features:

1. DoH endpoint at `/resolve/{uid}`
2. Read resolver->profile mappings from PostgreSQL
3. Read profile->categories from PostgreSQL (join cdf_ctldfilters)
4. Read blocked domains from `scd_blocklist_domains`
5. Domain lookup: check exact match + parent domain matching
6. Forward allowed queries to upstream (1.1.1.1)
7. Return NXDOMAIN for blocked queries
8. Periodic reload (every 60 seconds)
9. Health check endpoint

**Deliverable:** A working DNS service that can resolve queries, block domains based on profile/category, and read config from the existing Joinery database.

### Phase 2: Blocklist Infrastructure

1. Create `scd_blocklist_sources` and `scd_blocklist_domains` tables (as data model classes)
2. Populate `scd_blocklist_sources` with community blocklist URLs for each category
3. Build blocklist downloader (PHP scheduled task or standalone script):
   - Fetch URL, parse hosts/domain/adblock format
   - Store parsed domains in `scd_blocklist_domains`
4. Add admin page to manage blocklist sources
5. Test with a few categories (ads, malware) to verify end-to-end flow

### Phase 3: Plugin Refactor

1. Remove `ControlDHelper.php`
2. Simplify `CtldDevice::createDevice()` -- generate resolver UID locally, no API calls
3. Simplify `CtldProfile::createProfile()` -- just create DB row
4. Simplify `CtldProfile::update_remote_filters()` -- just update local DB
5. Simplify `CtldProfile::update_remote_services()` -- just update local DB
6. Simplify `CtldProfile::add_rule()` / `delete_rule()` -- just update local DB
7. Simplify `CtldProfile::add_or_edit_schedule()` -- just update local DB
8. Simplify `CtldDevice::permanent_delete()` -- just delete local records
9. Simplify `CtldDevice::check_activate()` -- auto-activate or remove
10. Update views to show DoH resolver URL instead of ControlD setup instructions
11. Update `settings_form.php` to remove controld_key, add new settings

### Phase 4: Scheduling & Advanced Features

1. Add schedule evaluation to DNS service (check schedule on each query)
2. Add SafeSearch/SafeYouTube DNS rewrites to DNS service
3. Add DoT (DNS-over-TLS) support for Android Private DNS compatibility
4. Add per-resolver query stats tracking
5. Service-level blocking: create `scd_service_domains` table, populate with service domain lists

### Phase 5: Infrastructure & Deployment

1. Set up DNS service on production server (Docker container or systemd service)
2. Configure Caddy/nginx for TLS termination on `dns.scrolldaddy.app`
3. Set up blocklist download cron job
4. Set up monitoring/alerting for DNS service uptime
5. Documentation: device setup instructions for iOS, Android, Windows, Mac
6. Load testing: verify DNS service handles expected query volume

### Phase 6: Cleanup

1. Remove unused database fields (cdd_controld_resolver, cdp_profile_id, cdp_schedule_id, etc.)
2. Update test suite
3. Update documentation references
4. Consider renaming ctld* prefixes to scd* (scrolldaddy) -- optional, cosmetic

---

## 8. What Changes, What Stays, What's New

### Simplified (less code)
- **Device creation** -- 2 DB inserts instead of 5 API calls + 2 DB inserts
- **Filter toggling** -- 1 DB update instead of API call + DB update
- **Rule management** -- 1 DB operation instead of API call + DB operation
- **Schedule management** -- 1 DB update instead of API call + DB update
- **Device deletion** -- DB deletes only, no API calls
- **ControlDHelper.php** -- Deleted entirely (1749 lines removed)

### Unchanged
- **All views/templates** -- Device dashboard, filter editor, rule editor (minor text changes only)
- **All business logic** -- Edit restrictions, deactivation PINs, subscription tier limits
- **All data model classes** -- Same tables, same fields (minus a few removed columns)
- **Filter category keys** -- Same keys (ads, porn, malware, etc.), same UI
- **Service blocking keys** -- Same keys (spotify, facebook, etc.), same UI
- **Scheduling model** -- Same primary/secondary profile concept, same schedule fields

### New
- **ScrollDaddy DNS Service** -- Go binary (~500-1000 lines)
- **Blocklist management** -- Download, parse, store community blocklists
- **Self-hosted infrastructure** -- DNS server, TLS, monitoring
- **Per-profile SafeSearch/SafeYouTube** -- Now per-profile (was per-profile in ControlD too, but Pi-hole couldn't do it)

### Improved Over ControlD
- **No API latency** -- Filter changes take effect in 30-60 seconds (DB reload) vs. API round-trip per change
- **No API rate limits** -- No external service to throttle us
- **No vendor dependency** -- Full control over the DNS infrastructure
- **Schedule evaluation** -- Per-query (instant transitions) vs. ControlD's mechanism
- **Cost** -- No per-device monthly fees to ControlD

---

## 9. Infrastructure Requirements

### DNS Server Host

The Go DNS service needs:
- A server with a public IP address (can be the same server as Joinery, or separate)
- A domain name for the DoH endpoint (e.g., `dns.scrolldaddy.app`)
- TLS certificate (Let's Encrypt via Caddy, or similar)
- Access to the PostgreSQL database (same host or network-accessible)
- Ports: 443 (HTTPS/DoH), optionally 853 (DoT for Android)

**Resource requirements are minimal:**
- CPU: DNS queries are fast lookups -- a single core handles thousands of queries/second
- RAM: ~100-200MB for blocklists in memory (500K domains = ~50MB as hash map)
- Disk: Negligible (binary + blocklist cache)

### Docker Deployment (recommended)

```dockerfile
FROM golang:1.22-alpine AS builder
COPY . /app
WORKDIR /app
RUN go build -o scrolldaddy-dns ./cmd/dns

FROM alpine:3.19
COPY --from=builder /app/scrolldaddy-dns /usr/local/bin/
EXPOSE 8053
CMD ["scrolldaddy-dns", "--db-host=...", "--db-name=...", "--upstream=1.1.1.1"]
```

With Caddy for TLS:
```
dns.scrolldaddy.app {
    reverse_proxy /resolve/* localhost:8053
    reverse_proxy /health localhost:8053
}
```

### Android Private DNS Support

Android uses DNS-over-TLS (DoT), not DoH. Two options:

**Option A:** Add DoT support to the Go service (listen on port 853 with TLS). The `miekg/dns` library supports this natively. Device identification would use a subdomain pattern: `{resolver_uid}.dns.scrolldaddy.app`

**Option B:** Provide an Android app or configuration profile that sets up DoH. Many Android versions now support DoH in addition to DoT.

**Recommendation:** Support both DoH and DoT in the Go service from the start. DoT adds ~50 lines of code.

---

## 10. Open Questions

1. **Hosting location:** Run the DNS service on the existing Joinery server, or a separate VPS? Same server is simpler; separate is more resilient (DNS uptime matters -- if DNS goes down, users can't browse at all).

2. **Blocklist curation:** Some categories (dating, drugs, games, torrents) don't have well-maintained community blocklists. How much effort to invest in curating custom lists vs. dropping those categories?

3. **Service domain mapping:** Where to source the domain lists for per-service blocking (Spotify, Facebook, etc.)? ControlD had this data internally. Options: manual curation, NextDNS's public data, DNS query logging + analysis.

4. **Resolver UID format:** Simple hex string (`a1b2c3d4e5f6`), UUID, or human-readable (`user42-iphone`)? Shorter is better for URLs but must be unguessable to prevent abuse.

5. **DNS service language:** Go is recommended but Python (with `dnspython`) or Rust (with `trust-dns`) are alternatives if Go expertise is limited. Python would be simpler but slower under load.

6. **Query logging/stats:** Should the DNS service log queries for per-device statistics? ControlD provided block counts. This adds storage requirements but is valuable for the UI.

7. **Fallback behavior:** What happens if the DNS service crashes or the database is unreachable? Options: fail-open (forward all queries unfiltered), fail-closed (return SERVFAIL for all queries), or cached (serve from last-known-good blocklist state).

8. **Table/class renaming:** Rename `ctld*` prefixes to `scd*` (scrolldaddy) since we're no longer using ControlD? This is a larger refactor but improves clarity.
