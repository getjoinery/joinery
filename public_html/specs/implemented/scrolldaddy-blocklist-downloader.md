# ScrollDaddy Blocklist Downloader

**Status:** Draft

---

## Summary

Build a scheduled task that downloads third-party blocklist data, stores it in `bld_blocklist_domains`, and triggers a DNS server cache reload. This is the missing piece that enables category-based content filtering (ads, malware, adult content, gambling, etc.) — the DNS server already has the machinery to enforce these blocks but the domain data table is currently empty.

---

## Architecture

```
┌──────────────────────────────────┐
│  Web Server (Joinery container)  │
│                                  │
│  Scheduled Task: DownloadBlock.. │
│      │                           │
│      ├─ 1. Fetch lists from URLs │──── (HTTPS GET to GitHub, etc.)
│      ├─ 2. Parse domains         │
│      ├─ 3. Write to DB           │──── bld_blocklist_domains table
│      └─ 4. POST /reload          │──── DNS server (optional)
│                                  │
│  Admin: Scheduled Tasks page     │──── Configure frequency, enable/disable
│                                  │
└──────────────────────────────────┘
         │
         │ reads every 3600s
         ▼
┌──────────────────────────────────┐
│  DNS Server (Linode)             │
│                                  │
│  cache.FullReload()              │
│      └─ LoadBlocklistDomains()   │──── SELECT bld_category_key, bld_domain
│                                  │
│  resolver.Resolve()              │
│      └─ IsDomainBlocked(domain,  │
│           categoryKey)           │
└──────────────────────────────────┘
```

---

## 1. Data Class: `bld_blocklist_domains`

The table already exists conceptually (the Go DNS server queries it) but needs a PHP data class so the scheduled task can write to it.

**File:** `plugins/scrolldaddy/data/blocklist_domains_class.php`

### Field Specifications

```php
class BlocklistDomain extends SystemBase {
    public static $prefix = 'bld';
    public static $tablename = 'bld_blocklist_domains';
    public static $pkey_column = 'bld_blocklist_domain_id';

    public static $field_specifications = array(
        'bld_blocklist_domain_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'bld_category_key'        => array('type'=>'varchar(64)'),
        'bld_domain'              => array('type'=>'varchar(255)'),
    );
}
```

The Multi class needs a `getMultiResults` supporting `category_key` as an option, plus a `delete_by_category($category_key)` method for the bulk replace workflow.

### Bulk Write Strategy

Individual INSERT via SystemBase would be far too slow for hundreds of thousands of domains. The scheduled task should use raw SQL with batch inserts:

```php
// 1. Drop index (speeds up bulk insert)
DROP INDEX IF EXISTS idx_bld_category_key

// 2. Truncate the entire table (instant, no per-row WAL)
TRUNCATE bld_blocklist_domains

// 3. Bulk insert all categories with multi-row INSERT batches
INSERT INTO bld_blocklist_domains (bld_category_key, bld_domain)
VALUES ($1, $2), ($1, $3), ...   -- batches of 5000 rows

// 4. Recreate index
CREATE INDEX idx_bld_category_key ON bld_blocklist_domains (bld_category_key)

// 5. Update stats
ANALYZE bld_blocklist_domains

// 6. Bump the version timestamp so the DNS server knows to reload
UPDATE stg_settings SET stg_value = NOW()::text WHERE stg_name = 'scrolldaddy_blocklist_version'
```

**Why TRUNCATE instead of DELETE:** TRUNCATE is near-instant regardless of table size because it deallocates pages directly rather than generating a WAL entry per row. The tradeoff is it clears the whole table at once, but since we're replacing all categories in a single run, that's fine.

**Batch size:** 5000 rows per INSERT statement balances query parsing overhead against memory. The full 2M-row insert should complete in 3-5 seconds.

---

## 2. Category-to-Source Mapping

Each ScrollDaddyHelper filter key maps to one or more external blocklist URLs. The task downloads these lists, parses domains from them, and stores them under the matching `bld_category_key`.

### Primary Sources

| Category Key | Description | Source | URL |
|---|---|---|---|
| `ads_small` | Ads (Relaxed) | Hagezi Light | `https://raw.githubusercontent.com/hagezi/dns-blocklists/main/domains/light.txt` |
| `ads_medium` | Ads (Balanced) | Hagezi Normal | `https://raw.githubusercontent.com/hagezi/dns-blocklists/main/domains/multi.txt` |
| `ads` | Ads (Strict) | Hagezi Pro | `https://raw.githubusercontent.com/hagezi/dns-blocklists/main/domains/pro.txt` |
| `malware` | Malware (Relaxed) | URLhaus | `https://urlhaus.abuse.ch/downloads/hostfile/` |
| `ip_malware` | Malware (Balanced) | Hagezi TIF | `https://raw.githubusercontent.com/hagezi/dns-blocklists/main/domains/tif.txt` |
| `ai_malware` | Malware (Strict) | Hagezi TIF + Phishing Army Extended | (merge two lists) |
| `typo` | Phishing | Phishing Army | `https://phishing.army/download/phishing_army_blocklist_extended.txt` |
| `porn` | Adult Content | Hagezi NSFW | `https://raw.githubusercontent.com/hagezi/dns-blocklists/main/domains/nsfw.txt` |
| `porn_strict` | Adult Content (Strict) | OISD NSFW | `https://nsfw.oisd.nl/domainswild` |
| `gambling` | Gambling | Hagezi Gambling | `https://raw.githubusercontent.com/hagezi/dns-blocklists/main/domains/gambling.txt` |
| `social` | Social Media | StevenBlack Social | `https://raw.githubusercontent.com/StevenBlack/hosts/master/alternates/social/hosts` |
| `fakenews` | Disinformation | StevenBlack Fakenews | `https://raw.githubusercontent.com/StevenBlack/hosts/master/alternates/fakenews/hosts` |
| `cryptominers` | Cryptomining | ZeroDot1 CoinBlocker | `https://zerodot1.gitlab.io/CoinBlockerLists/hosts_browser` |
| `dating` | Dating Sites | UT1 Toulouse | `https://raw.githubusercontent.com/olbat/ut1-blacklists/master/blacklists/dating/domains` |
| `drugs` | Drugs | UT1 Toulouse | `https://raw.githubusercontent.com/olbat/ut1-blacklists/master/blacklists/drugs/domains` |
| `games` | Games | UT1 Toulouse | `https://raw.githubusercontent.com/olbat/ut1-blacklists/master/blacklists/games/domains` |
| `ddns` | Dynamic DNS | Hagezi DynDNS | `https://raw.githubusercontent.com/hagezi/dns-blocklists/main/domains/dyndns.txt` |
| `dnsvpn` | VPN/DNS Bypass | Hagezi DoH/VPN | `https://raw.githubusercontent.com/hagezi/dns-blocklists/main/domains/doh.txt` |

### Categories Without Sources (Deferred)

These filter keys exist in ScrollDaddyHelper but don't have readily available free blocklists. They can be implemented later with custom curation or commercial data:

| Category Key | Description | Notes |
|---|---|---|
| `noai` | AI services | No standard list exists. Could build a custom list of AI service domains. |
| `filehost` | File hosting | Could curate from UT1 or build custom. |
| `gov` | Government sites | Highly region-specific. Not a typical content filter category. |
| `iot` | IoT | Niche. Could use Hagezi or custom curation. |
| `nrd_small` | New domains (week) | Requires a paid NRD feed or custom tracking. |
| `nrd` | New domains (month) | Same — NRD data is typically commercial. |
| `torrents` | Torrent sites | Could curate a small custom list. |
| `urlshort` | URL shorteners | Could build a small custom list (bit.ly, tinyurl.com, etc.). |

### Source Configuration Storage

Store the category-to-URL mapping in a PHP constant array within the task class, not in the database. This keeps it version-controlled and simple. The admin can enable/disable the task and set frequency via the scheduled tasks UI, but the list of sources is code-managed.

If custom URL overrides are needed later, add a `config_fields` entry in the task JSON to let admins override individual source URLs via the admin UI.

---

## 3. Scheduled Task: DownloadBlocklists

### Files

- `plugins/scrolldaddy/tasks/DownloadBlocklists.php` — Task class
- `plugins/scrolldaddy/tasks/DownloadBlocklists.json` — Task config

### JSON Config

```json
{
    "name": "Download Blocklists",
    "description": "Downloads domain blocklists from external sources and stores them in the database for DNS filtering. Optionally triggers a DNS server cache reload.",
    "default_frequency": "daily",
    "default_time": "04:00:00",
    "config_fields": {}
}
```

Daily at 4 AM is the default. Most upstream lists update daily or less frequently. Running more often wastes bandwidth and DB writes without meaningful benefit.

### Task Logic

```
run(config):
  1. Initialize counters (categories_updated, total_domains, errors)
  2. Download phase — for each category_key → source_url in the mapping:
     a. HTTP GET the source URL (timeout: 30s, follow redirects)
     b. If download fails → log error, skip this category
     c. Parse the response body into a domain list (see Parser below)
     d. If fewer than 10 domains → log warning, skip (corrupt/empty upstream)
     e. Store parsed domains in memory keyed by category
     f. Increment counters
  3. Write phase — bulk replace all data at once:
     a. BEGIN transaction
     b. DROP INDEX idx_bld_category_key
     c. TRUNCATE bld_blocklist_domains
     d. For each category's domain list: multi-row INSERT in batches of 5000
     e. CREATE INDEX idx_bld_category_key ON bld_blocklist_domains (bld_category_key)
     f. COMMIT
     g. ANALYZE bld_blocklist_domains
  4. Bump version — UPDATE stg_settings SET stg_value = NOW() WHERE stg_name = 'scrolldaddy_blocklist_version'
  5. Return status summary: "Updated N categories, M total domains, E errors"
```

**Note:** Downloads happen first, then a single TRUNCATE + INSERT cycle writes everything. This means the table is empty for only a few seconds during the write phase, minimizing the window where a DNS reload could see an empty table.

### Parser

External lists come in two main formats. The parser should handle both:

**Domain-per-line** (Hagezi, OISD, Phishing Army, UT1):
```
example.com
bad-site.org
# This is a comment
```

**Hosts file** (StevenBlack, URLhaus, CoinBlocker):
```
0.0.0.0 example.com
127.0.0.1 bad-site.org
# This is a comment
```

**Parser rules:**
1. Trim whitespace from each line
2. Skip empty lines
3. Skip lines starting with `#` (comments)
4. Skip lines starting with `!` (adblock-style comments)
5. If line contains a space, take the second field (hosts format: `0.0.0.0 domain`)
6. Strip trailing comments (anything after ` #`)
7. Lowercase the domain
8. Validate: must contain at least one `.` and no spaces
9. Skip `localhost`, `0.0.0.0`, `127.0.0.1`, `broadcasthost`, `local`, and any IP-only entries
10. Deduplicate within each category

### Merged Categories

Some categories combine multiple source lists:

- `ai_malware` (Malware Strict): Download Hagezi TIF + Phishing Army Extended, merge both domain sets, deduplicate, store under `ai_malware`.

### DNS Server Reload Trigger

After all categories are updated, POST to the DNS server's `/reload` endpoint:

```php
$internal_url = $settings->get_setting('scrolldaddy_dns_internal_url');
if ($internal_url) {
    $ch = curl_init(rtrim($internal_url, '/') . '/reload');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}
```

This is optional — the DNS server reloads blocklists on its own every 3600 seconds. The explicit trigger just makes changes take effect immediately.

Note: The `/reload` endpoint is localhost-only on the DNS server. If the web server is on a different machine (current architecture), this POST will be blocked. Options:
- Skip the trigger and rely on the 3600s auto-reload
- Add the web server's IP to an allowed list in the Go service (future enhancement)
- SSH tunnel (overkill)

The auto-reload is acceptable for blocklist updates since they're daily, not time-sensitive.

---

## 4. DNS Server Version Check

The DNS server's `FullReload()` currently scans the entire `bld_blocklist_domains` table (2M rows) every 3600 seconds. Since the data only changes once daily, 23 out of 24 reloads are wasted work.

**Optimization:** Before doing the full scan, check a version timestamp. If it hasn't changed since the last reload, skip.

### Implementation

**PHP task (writer):** After the bulk write completes, update a setting:
```sql
-- Use stg_settings (no new table needed)
UPDATE stg_settings SET stg_value = NOW()::text WHERE stg_name = 'scrolldaddy_blocklist_version'
```

**Go DNS server (reader):** In `FullReload()`, before loading domains:
```go
// Check if blocklist data has changed since last reload
currentVersion := database.GetBlocklistVersion()  // SELECT stg_value FROM stg_settings WHERE stg_name = 'scrolldaddy_blocklist_version'
if currentVersion == c.lastBlocklistVersion {
    logger.Debug("blocklist data unchanged, skipping full reload")
    return nil
}
// ... proceed with full load ...
c.lastBlocklistVersion = currentVersion
```

This turns 23 out of 24 hourly reloads into a single-row SELECT (~0.1ms) instead of a 2M-row scan.

**Migration:** Add the setting via a standard data migration:
```sql
INSERT INTO stg_settings (stg_name, stg_value) VALUES ('scrolldaddy_blocklist_version', '');
```

---

## 5. Error Handling

- **Download failure** (timeout, HTTP error, DNS failure): Log the error, skip the category, continue with remaining categories. Since TRUNCATE clears the whole table, all successfully downloaded categories are still written — only failed categories are missing until the next run.
- **Parse failure** (empty list, malformed data): If a downloaded list parses to fewer than 10 domains, treat it as suspicious and skip — log a warning.
- **All downloads fail**: If zero categories downloaded successfully, skip the TRUNCATE/write phase entirely — keep existing data. Return `error` status.
- **Database failure**: If the transaction fails, roll back. The old data remains intact (TRUNCATE hasn't committed). Return `error` status.

---

## 5. Monitoring

The scheduled task status is visible in the Joinery admin at the Scheduled Tasks page:
- **Last run time** and **next scheduled run**
- **Last run status**: success / error / skipped
- **Last run message**: "Updated 18 categories, 847,231 total domains, 0 errors"

If the task hasn't run in 48 hours or the last status is `error`, the admin should investigate.

The DNS server's `/stats` endpoint also reports `blocklist_domains_total` — if this drops to 0, something is wrong.

---

## 6. Implementation Order

1. **Create `blocklist_domains_class.php`** — data class with field specs so `update_database` creates the table
2. **Add `scrolldaddy_blocklist_version` migration** — insert the setting into `stg_settings`
3. **Create `DownloadBlocklists.php` + `.json`** — scheduled task with the domain parser, TRUNCATE + bulk INSERT, and version bump
4. **Update Go DNS server** — add version check to `FullReload()` in `cache.go`, add `GetBlocklistVersion()` to `db.go`
5. **Run the task once manually** from the admin Scheduled Tasks page to verify it works
6. **Verify on DNS server** — check `/stats` endpoint shows blocklist domain counts > 0
7. **Test end-to-end** — enable an ads or malware filter for a device, verify blocked domains return NXDOMAIN via the `/test` endpoint

---

## 7. Expected Data Volumes

Based on current upstream list sizes:

| Category | Approximate Domains |
|---|---|
| `ads` (Strict / Hagezi Pro) | ~400,000 |
| `ads_medium` (Hagezi Normal) | ~200,000 |
| `ads_small` (Hagezi Light) | ~100,000 |
| `porn` (Hagezi NSFW) | ~200,000 |
| `porn_strict` (OISD NSFW) | ~500,000 |
| `malware` (URLhaus) | ~30,000 |
| `ip_malware` (Hagezi TIF) | ~400,000 |
| `gambling` (Hagezi) | ~150,000 |
| `typo` (Phishing Army) | ~100,000 |
| Others (smaller lists) | ~5,000–50,000 each |

**Total estimated rows:** ~1.5–2 million

**Database impact:** ~100–200 MB of table + index storage. TRUNCATE avoids per-row WAL entries, and the post-write ANALYZE updates planner stats. Much lighter than a DELETE-based approach.

**Write performance:** With TRUNCATE + batched INSERT (5000/batch) + deferred index creation, the full 2M-row write should complete in 3-5 seconds.

**DNS server reload performance:** The version check (single-row SELECT on `stg_settings`) makes 23 out of 24 hourly reloads effectively free. The one reload that does run streams 2M rows in ~2-3 seconds.

**DNS server memory impact:** ~100–200 MB additional RAM for the in-memory domain sets. The current 1 GB Linode has headroom for this.

---

## 8. Developer Notes

- The `bld_blocklist_domains` table is append-heavy and query-simple (full table scan on each FullReload). Add an index on `bld_category_key` for the DELETE-by-category operation.
- The Go DNS server's `LoadBlocklistDomains()` in `db.go` already reads this table correctly — no Go changes needed.
- Some upstream lists are very large (500K+ domains). Downloading should stream the response rather than loading it all into memory. PHP's `fopen` with a stream context or `curl` with `CURLOPT_FILE` writing to a temp file is appropriate.
- Add the relevant `/docs/` documentation about the blocklist system to the existing ScrollDaddy developer docs.
