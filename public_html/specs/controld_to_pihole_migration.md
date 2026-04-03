# ControlD Replacement: Self-Hosted DNS Filtering Service

**Status:** Draft
**Plugin:** scrolldaddy (new plugin, parallel to existing controld plugin)
**Scope:** Replace ControlD cloud API with a self-hosted DNS filtering service that replicates ControlD's architecture
**Related spec:** [ScrollDaddy DNS Service](scrolldaddy_dns_service.md) -- full Go application specification

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
                    [Apache Reverse Proxy]
                     (host-level, TLS via Certbot)
                             |
                   [ScrollDaddy DNS Service]
                     (Go binary inside Docker container)
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

1. **ScrollDaddy DNS Service** -- A Go program (written by Claude) that handles DNS-over-HTTPS and DNS-over-TLS. Reads device/profile/blocklist data from the database, caches in memory, reloads periodically. Fully specified in **[scrolldaddy_dns_service.md](scrolldaddy_dns_service.md)**.

2. **Apache Reverse Proxy** (host-level) -- Uses the existing Joinery deployment pattern: Apache on the Docker host with Certbot for Let's Encrypt TLS. Proxies `dns.scrolldaddy.app` traffic to the Go service port inside the container. Same pattern already used for site domains.

3. **Scrolldaddy Plugin (new)** -- A new Joinery plugin (`plugins/scrolldaddy/`), created by copying the existing `controld` plugin and removing all ControlD API dependencies. The original `controld` plugin remains untouched as a fallback. Both plugins share the same database tables (`ctld_` prefixed), so switching between them requires no data migration.

4. **Blocklist Downloader** -- A Joinery scheduled task (PHP) using the existing scheduled task framework. Runs periodically to fetch community blocklist URLs, parse them, and store domains in the database.

5. **Blocklist Admin Page** -- A plugin admin page at `plugins/scrolldaddy/admin/admin_blocklist_sources.php` for managing blocklist source URLs.

6. **Container Install Script** -- A bash script (`install_scrolldaddy_dns.sh`) that installs Go, compiles the DNS service binary, and configures supervisord to run it alongside Apache and PostgreSQL inside the Docker container.

---

## 3. Blocklist Management

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

**New table: `bls_blocklist_sources`** (class: `BlocklistSource` / `MultiBlocklistSource`)

```
bls_blocklist_source_id (PK, serial)
bls_category_key (varchar) -- maps to filter category
bls_url (text) -- blocklist URL to download
bls_name (varchar) -- human-readable name
bls_format (varchar) -- 'hosts', 'domains', 'adblock' (parsing format)
bls_is_active (bool)
bls_last_download_time (timestamp)
bls_domain_count (int) -- number of domains after parsing
```

**New table: `bld_blocklist_domains`** (class: `BlocklistDomain` / `MultiBlocklistDomain`)

```
bld_blocklist_domain_id (PK, serial)
bld_category_key (varchar) -- which category this domain belongs to
bld_domain (varchar) -- the blocked domain
```

Index: `CREATE INDEX idx_blocklist_domain ON bld_blocklist_domains(bld_category_key, bld_domain)`

### Blocklist Downloader (Joinery Scheduled Task)

Uses the existing Joinery scheduled task framework. Two files in `plugins/scrolldaddy/tasks/`:

**`DownloadBlocklists.json`**
```json
{
    "name": "Download Blocklists",
    "description": "Fetches community blocklist URLs, parses domains, and stores them in the database for the DNS service",
    "default_frequency": "daily",
    "default_time": "04:00:00",
    "config_fields": {
        "dns_service_url": {
            "type": "text",
            "label": "DNS Service Internal URL",
            "required": false
        }
    }
}
```

**`DownloadBlocklists.php`** implements `ScheduledTaskInterface`:

```
function run(array $config):
    1. Load all active rows from bls_blocklist_sources
    2. Group sources by category_key
    3. For each category:
       a. For each source in this category:
          - Fetch URL via file_get_contents() or curl
          - If fetch fails: log warning, skip to next source
          - Parse based on bls_format:
            * 'hosts': Extract second column from "0.0.0.0 domain.com" lines (skip comments, localhost)
            * 'domains': One domain per line (skip comments, blank lines)
            * 'adblock': Extract domain from "||domain.com^" patterns
          - Normalize all domains: lowercase, strip trailing dots
          - Collect all domains for this category
       b. Deduplicate across all sources for this category
       c. Begin transaction:
          - DELETE FROM bld_blocklist_domains WHERE bld_category_key = [category]
          - INSERT all deduplicated domains for this category
          - UPDATE bls_blocklist_sources SET bls_last_download_time = now(),
            bls_domain_count = [count per source]
       d. Commit transaction
    4. After all categories processed, signal DNS service to reload:
       - POST to http://localhost:8053/reload (configurable via task config dns_service_url)
    5. Return status: "Downloaded X sources, Y total domains across Z categories"
```

### Admin Page for Blocklist Sources

**Location:** `plugins/scrolldaddy/admin/admin_blocklist_sources.php`

A standard Joinery admin table page (following the patterns in `docs/admin_pages.md`) that allows admins to:
- View all blocklist sources with columns: Name, Category, URL, Format, Active, Last Downloaded, Domain Count
- Add new blocklist source (name, category_key dropdown, URL, format dropdown, active toggle)
- Edit existing sources
- Delete sources
- Manual "Download Now" action for individual sources
- Manual "Download All" action (triggers the full scheduled task logic)

**Route:** Auto-discovered as a plugin admin page at `/admin/admin_blocklist_sources` via the existing plugin admin route discovery.

**Logic file:** `plugins/scrolldaddy/admin/logic/admin_blocklist_sources_logic.php`

### Service Blocking

ControlD's 400+ individual service toggles (Spotify, Facebook, etc.) are handled as **curated domain lists per service**, stored in the same blocklist infrastructure:

**New table: `svd_service_domains`** (class: `ServiceDomain` / `MultiServiceDomain`)

```
svd_service_domain_id (PK, serial)
svd_service_key (varchar) -- e.g., 'spotify', 'facebook'
svd_service_category (varchar) -- e.g., 'audio', 'social'
svd_domain (varchar) -- domain to block
```

Pre-populated with domains for each service. When a user blocks "Spotify", the DNS service loads those domains into the profile's blocked set.

The existing `ControlDHelper::$services` array already has the service keys and categories. What's needed is the domain-to-service mapping data, which can be:
- Scraped from ControlD's public documentation (if available)
- Built manually from known service domains
- Sourced from community lists (e.g., `nextdns` has similar per-service domain lists)

**Phase approach:** Start with the top 20 most-used services. The infrastructure supports adding more over time.

---

## 4. New Scrolldaddy Plugin (copy-then-convert)

### 4.0 Plugin Strategy: Copy First, Then Convert

The `scrolldaddy` plugin is created by **copying the entire `controld` plugin** to `plugins/scrolldaddy/`, then incrementally removing ControlD API dependencies. This approach:

- Starts with a fully working plugin on day one
- Allows incremental conversion (one logic file at a time)
- Keeps the original `controld` plugin untouched as a fallback
- Both plugins share the same database tables (`ctld_` prefixed) -- switching requires only changing which plugin is active

**Copy steps:**
1. `cp -r plugins/controld/ plugins/scrolldaddy/`
2. Update `plugin.json`: name, description, version (reset to 1.0.0)
3. Update `serve.php`: change route prefix from `/controld` to `/scrolldaddy`
4. Update `settings_form.php`: replace `controld_key` setting with `scrolldaddy_dns_host` and `scrolldaddy_dns_internal_url`
5. Update `theme.json` if needed (display name, asset paths)
6. Update CSS/JS asset paths to reference `plugins/scrolldaddy/assets/`
7. All data classes stay the same (same table names, same class names) -- they are imported from the scrolldaddy plugin's own `data/` directory but point to the same `ctld_` tables
8. New columns (`cdd_resolver_uid`, `cdp_safesearch`, `cdp_safeyoutube`) are added to existing data class `$field_specifications` -- the controld plugin ignores them harmlessly

**After copying, the conversion work is:**

### 4.1 Remove ControlDHelper.php

Delete `plugins/scrolldaddy/includes/ControlDHelper.php`. Move the static `$filters` and `$services` arrays to a new lightweight file (`plugins/scrolldaddy/includes/ScrollDaddyHelper.php`) that contains only the data arrays and utility methods -- no API calls, no curl

### 4.2 Device Creation (ctlddevice_edit_logic.php + CtldDevice)

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

### 4.3 Filter Toggling (CtldProfile::update_remote_filters)

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

### 4.4 Custom Rules (CtldProfile::add_rule, delete_rule)

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

### 4.5 Device Deletion (CtldDevice::permanent_delete)

**Current flow:** Call ControlD API to delete device and profiles, then delete locally.

**New flow:** Just delete locally. DNS service stops serving that resolver UID on next reload.

### 4.6 Scheduling (CtldProfile::add_or_edit_schedule)

**Current flow:** Call ControlD API to create/modify/delete schedule.

**New flow:** Just save schedule params to local DB. The DNS service evaluates schedules on every query (see DNS service spec Section 6).

### 4.7 Device Activation (CtldDevice::check_activate)

**Current flow:** Poll ControlD API for device status.

**New flow:** Auto-activate on creation. The device is "active" as soon as it's created -- the resolver UID works immediately. The user just needs to configure their device to use the DoH URL. No activation polling needed. The `cdd_is_active` field is set to true on creation.

### 4.8 Device Setup Instructions

After creating a device, show the user their unique DoH URL and setup instructions:

```
Your DNS resolver URL:
https://dns.scrolldaddy.app/resolve/a1b2c3d4e5f6...

To set up your device:
- iPhone/iPad: Settings > General > VPN & Device Management > DNS > Configure DNS > https://dns.scrolldaddy.app/resolve/a1b2c3d4e5f6...
- Android: Settings > Network > Private DNS > a1b2c3d4e5f6.dns.scrolldaddy.app
- Windows: [instructions]
- Mac: [instructions]
```

Note: Android's Private DNS uses DNS-over-TLS (DoT), not DoH. The Go service handles DoT on port 853 using subdomain-based device identification (see DNS service spec Section 8).

### 4.9 Soft Delete (ctlddevice_soft_delete_logic.php)

**Current flow:** Call `$cd->modifyDevice()` to set ControlD device status to disabled, then update local DB.

**New flow:** Just set `cdd_is_active = false` and `cdd_delete_time = now()` in the local DB. The DNS service will return REFUSED for inactive devices on next reload.

### 4.10 Device Name Edit (ctlddevice_edit_logic.php)

**Current flow:** Call `$cd->modifyDevice()` to rename device at ControlD, then update local DB.

**New flow:** Just update `cdd_device_name` locally. The DNS service doesn't use the device name.

---

## 5. Database Changes

### 5.1 Settings Changes

The scrolldaddy plugin uses its own settings (prefixed `scrolldaddy_`). The controld plugin's `controld_key` setting is left untouched.

| Setting | Purpose |
|---------|---------|
| `scrolldaddy_dns_host` | DNS service hostname (e.g., `dns.scrolldaddy.app`) |
| `scrolldaddy_dns_internal_url` | Internal URL to DNS service for reload/stats (e.g., `http://localhost:8053`) |

### 5.2 Existing Table Changes (additive only)

Because both plugins share the same tables, changes are **additive only** -- new columns are added, no columns are removed or renamed. The controld plugin ignores the new columns; the scrolldaddy plugin ignores the ControlD-specific columns.

**CtldDevice (cdd_ctlddevices) -- add columns:**

| Field | Type | Notes |
|-------|------|-------|
| `cdd_resolver_uid` | varchar(32) | Unique DoH path identifier, generated locally via `bin2hex(random_bytes(16))`. The scrolldaddy plugin populates this on device creation. The controld plugin ignores it. |

Existing ControlD-specific columns (`cdd_device_id`, `cdd_controld_resolver`, `cdd_profile_id_primary`, `cdd_profile_id_secondary`) stay in place -- they are used by the controld plugin and ignored by scrolldaddy.

**CtldProfile (cdp_ctldprofiles) -- add columns:**

| Field | Type | Notes |
|-------|------|-------|
| `cdp_safesearch` | boolean, default false | Per-profile SafeSearch toggle |
| `cdp_safeyoutube` | boolean, default false | Per-profile SafeYouTube toggle |

Existing ControlD-specific columns (`cdp_profile_id`, `cdp_schedule_id`) stay in place.

**Schedule days format:** `cdp_schedule_days` currently uses PHP `serialize()`. The scrolldaddy plugin will write JSON (`json_encode()`), and its logic files will read with `json_decode()`. A data migration converts existing serialized rows to JSON format. The controld plugin also handles JSON fine since `json_decode` is used in newer code paths. If rollback to controld is needed, the JSON format is compatible.

**CtldFilter, CtldService, CtldRule:** No changes needed. These tables already store the right data -- the scrolldaddy plugin just stops syncing them to a remote API.

### 5.3 New Tables

**bls_blocklist_sources** -- Blocklist URL registry (see Section 3)

**bld_blocklist_domains** -- Parsed domains from blocklists (see Section 3)

**svd_service_domains** -- Domains per service for service-level blocking (see Section 3)

---

## 6. Container Infrastructure

### 6.1 Current Docker Container Setup

The existing Joinery Docker container (Dockerfile.template) runs:
- **Ubuntu 24.04** base image
- **Apache** (foreground process, keeps container alive)
- **PostgreSQL 16** (started as service in CMD)
- **Cron** (started as service in CMD, runs scheduled tasks every 15 minutes)

The container exposes ports 80 (HTTP) and 5432 (PostgreSQL). The Docker host runs an Apache reverse proxy with Certbot for SSL, forwarding site domains to the container.

### 6.2 Container Modifications Needed

The Go DNS service must run alongside Apache and PostgreSQL inside the container. This requires:

1. **supervisord** to manage multiple foreground processes (replaces the bare `apache2ctl -D FOREGROUND` CMD)
2. **Go toolchain** installed during Docker build to compile the binary (removed after compilation to reduce image size)
3. **Additional port exposure**: 8053 (DoH, for host reverse proxy) and 853 (DoT, direct passthrough)

### 6.3 Install Script: install_scrolldaddy_dns.sh

Located at: `maintenance_scripts/install_tools/install_scrolldaddy_dns.sh`

This script runs during the Docker image build (called from Dockerfile) and handles all non-PHP setup. It is idempotent (safe to run multiple times).

```bash
#!/bin/bash
# install_scrolldaddy_dns.sh
# Installs the ScrollDaddy DNS service inside a Joinery Docker container.
# Called during Docker image build.
#
# What it does:
# 1. Installs Go toolchain
# 2. Compiles the scrolldaddy-dns binary from source
# 3. Installs supervisord
# 4. Creates supervisord configuration for Apache, PostgreSQL, cron, and scrolldaddy-dns
# 5. Cleans up Go toolchain (only the compiled binary is needed at runtime)
#
# Prerequisites:
# - Ubuntu 24.04 base (matches Dockerfile.template)
# - PostgreSQL 16 already installed (by install.sh server)
# - Apache already installed (by install.sh server)
# - Cron already installed (by Dockerfile.template)
# - scrolldaddy-dns/ Go source directory must exist at /var/www/html/${SITENAME}/scrolldaddy-dns/
#
# Usage:
#   ./install_scrolldaddy_dns.sh <SITENAME>

set -e

SITENAME="$1"
if [ -z "$SITENAME" ]; then
    echo "Usage: ./install_scrolldaddy_dns.sh <SITENAME>"
    exit 1
fi

SITE_ROOT="/var/www/html/${SITENAME}"
DNS_SRC="${SITE_ROOT}/scrolldaddy-dns"
DNS_BIN="/usr/local/bin/scrolldaddy-dns"

echo "=== Installing ScrollDaddy DNS Service ==="

# Step 1: Install Go toolchain
echo "Installing Go 1.22..."
apt-get update -qq
apt-get install -y -qq golang-go > /dev/null 2>&1

# Step 2: Compile the binary
echo "Compiling scrolldaddy-dns..."
cd "$DNS_SRC"
go mod download
go build -o "$DNS_BIN" ./cmd/dns
chmod 755 "$DNS_BIN"
echo "Binary installed at $DNS_BIN"

# Step 3: Install supervisord
echo "Installing supervisord..."
apt-get install -y -qq supervisor > /dev/null 2>&1

# Step 4: Create supervisord configuration
cat > /etc/supervisor/conf.d/scrolldaddy.conf << 'SUPERVISORD_EOF'
[program:scrolldaddy-dns]
command=/usr/local/bin/scrolldaddy-dns
autostart=true
autorestart=true
startsecs=5
startretries=3
stderr_logfile=/var/log/scrolldaddy-dns.err.log
stdout_logfile=/var/log/scrolldaddy-dns.out.log
stdout_logfile_maxbytes=10MB
stderr_logfile_maxbytes=10MB
SUPERVISORD_EOF

cat > /etc/supervisor/conf.d/apache.conf << 'EOF'
[program:apache]
command=apache2ctl -D FOREGROUND
autostart=true
autorestart=true
EOF

cat > /etc/supervisor/conf.d/cron.conf << 'EOF'
[program:cron]
command=cron -f
autostart=true
autorestart=true
EOF

# Step 5: Clean up Go toolchain to reduce image size
echo "Cleaning up build tools..."
apt-get remove -y -qq golang-go > /dev/null 2>&1
apt-get autoremove -y -qq > /dev/null 2>&1
rm -rf /root/go /root/.cache/go-build

echo "=== ScrollDaddy DNS Service installed ==="
```

### 6.4 Dockerfile.template Changes

The Dockerfile.template needs these additions:

```dockerfile
# After the existing "RUN ./install.sh server" line:

# Copy scrolldaddy-dns Go source code
COPY scrolldaddy-dns/ /var/www/html/${SITENAME}/scrolldaddy-dns/

# Install ScrollDaddy DNS service (compiles Go binary, installs supervisord)
RUN cd /var/www/html/${SITENAME}/maintenance_scripts/install_tools && \
    ./install_scrolldaddy_dns.sh ${SITENAME}

# Additional ports for DNS service
EXPOSE 80 5432 8053 853
```

The CMD section changes from running `apache2ctl -D FOREGROUND` directly to using supervisord:

```dockerfile
CMD set +H && \
    # ... existing PostgreSQL startup and _site_init.sh logic ... \
    # Set environment variables for scrolldaddy-dns
    echo "[program:scrolldaddy-dns]" > /etc/supervisor/conf.d/scrolldaddy-env.conf && \
    echo "environment=SCD_DB_HOST=\"localhost\",SCD_DB_PORT=\"5432\",SCD_DB_NAME=\"${SITENAME}\",SCD_DB_USER=\"postgres\",SCD_DB_PASSWORD=\"${POSTGRES_PASSWORD}\"" >> /etc/supervisor/conf.d/scrolldaddy-env.conf && \
    # Start all services via supervisord (replaces apache2ctl -D FOREGROUND)
    /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
```

### 6.5 Docker Run Port Mapping Changes

The `install.sh` site command needs to map two additional ports. Following the existing pattern where DB_PORT = PORT + 1000:

| Port | Mapping | Purpose |
|------|---------|---------|
| `PORT` | → container 80 | Web (existing) |
| `PORT + 1000` | → container 5432 | PostgreSQL (existing) |
| `PORT + 2000` | → container 8053 | DoH (new, for Apache reverse proxy) |
| `PORT + 3000` | → container 853 | DoT (new, direct passthrough for Android) |

Example for a site on port 8080: web=8080, DB=9080, DoH=10080, DoT=11080.

### 6.6 Host-Level Apache Reverse Proxy for DNS

Uses the same Apache + Certbot pattern already in place for site domains. A new VirtualHost on the Docker host:

```apache
<VirtualHost *:80>
    ServerName dns.scrolldaddy.app
    ProxyPreserveHost On
    ProxyPass /resolve/ http://localhost:10080/resolve/
    ProxyPassReverse /resolve/ http://localhost:10080/resolve/
    ProxyPass /health http://localhost:10080/health
    ProxyPassReverse /health http://localhost:10080/health
</VirtualHost>
```

Then run Certbot to add SSL: `certbot --apache -d dns.scrolldaddy.app`

After Certbot, all traffic to `https://dns.scrolldaddy.app/resolve/{uid}` is proxied via TLS to the Go service inside the container.

**DoT passthrough:** Port 853 is mapped directly from host to container. The Go service handles its own TLS for DoT. The host just needs the port open in the firewall. A wildcard DNS record `*.dns.scrolldaddy.app` points to the host IP.

### 6.7 DNS Domain Configuration

**One-time manual setup at domain registrar:**

| Record | Type | Value |
|--------|------|-------|
| `dns.scrolldaddy.app` | A | `23.239.11.53` (Docker host IP) |
| `*.dns.scrolldaddy.app` | A | `23.239.11.53` (for DoT subdomain routing) |

---

## 7. Implementation Plan

### Phase 1: Go DNS Service

Build the core service first and prove it works against the existing database before touching anything on the PHP side. The existing controld plugin data (devices, profiles, filters, rules) provides real test data.

**1a. Blocklist infrastructure (prerequisite -- the Go service needs blocklist data to read):**
1. Create data model classes for `bls_blocklist_sources`, `bld_blocklist_domains`, `svd_service_domains` -- these are new tables, added via the controld plugin's data directory initially (or standalone scripts) so they exist before the Go service starts
2. Populate `bls_blocklist_sources` with community blocklist URLs for initial categories
3. Run a one-time blocklist download to populate `bld_blocklist_domains`
4. Add `cdd_resolver_uid` column to `cdd_ctlddevices` and populate existing devices with generated UIDs
5. Add `cdp_safesearch` and `cdp_safeyoutube` columns to `cdp_ctldprofiles`

**1b. Go DNS service:**
1. Install Go on test server (`sudo apt install golang-go`)
2. Create `scrolldaddy-dns/` Go project with full directory structure per **[scrolldaddy_dns_service.md](scrolldaddy_dns_service.md)**
3. Implement all modules: config, db, cache, doh, dot, resolver, upstream
4. Test locally against the test database -- verify it reads existing devices/profiles/filters/rules, resolves queries, blocks domains

**Deliverable:** A working DNS service running on the test server that can resolve DoH queries, block domains based on profile/category, and read all config from the existing Joinery database.

### Phase 2: Copy Plugin & Convert

With the Go service proven, create the new scrolldaddy plugin and strip out ControlD dependencies.

**2a. Copy and rebrand the plugin:**
1. `cp -r plugins/controld/ plugins/scrolldaddy/`
2. Update `plugin.json` (name: "ScrollDaddy", version: "1.0.0", description updated)
3. Update `serve.php` route prefix from `/controld` to `/scrolldaddy`
4. Update `settings_form.php`: replace `controld_key` with `scrolldaddy_dns_host` and `scrolldaddy_dns_internal_url`
5. Update `theme.json` display name
6. Update asset paths in CSS/JS references to `plugins/scrolldaddy/assets/`
7. Verify the copied plugin loads and works identically to controld (it still has ControlDHelper at this point -- that is fine, it is a working copy)

**2b. Remove ControlD API dependency:**
1. Create `ScrollDaddyHelper.php` -- move `$filters` and `$services` arrays from ControlDHelper, no API methods
2. Delete `ControlDHelper.php` from the scrolldaddy plugin
3. Update all `require` / `new ControlDHelper()` references to use ScrollDaddyHelper
4. Simplify `ctlddevice_edit_logic.php` -- generate resolver UID locally, no API calls for device/profile creation
5. Simplify `ctldfilters_edit_logic.php` / `CtldProfile::update_remote_filters()` -- just update local DB
6. Simplify `CtldProfile::update_remote_services()` -- just update local DB
7. Simplify `rules_logic.php` / `CtldProfile::add_rule()` / `delete_rule()` -- just update local DB
8. Simplify `CtldProfile::add_or_edit_schedule()` -- just update local DB
9. Simplify `CtldDevice::permanent_delete()` -- just delete local records
10. Simplify `ctld_activation_logic.php` / `CtldDevice::check_activate()` -- auto-activate on creation
11. Simplify `ctlddevice_soft_delete_logic.php` -- just update local DB, no API call
12. Update views to show DoH resolver URL instead of ControlD setup instructions
13. Update activation page to show DoH/DoT setup URLs instead of ControlD app downloads
14. Data migration: convert existing `cdp_schedule_days` from PHP `serialize()` to `json_encode()` format

**2c. Blocklist admin & scheduled task (inside the new scrolldaddy plugin):**
1. Create admin page `plugins/scrolldaddy/admin/admin_blocklist_sources.php` with logic file
2. Build the `DownloadBlocklists` scheduled task (`plugins/scrolldaddy/tasks/`)
3. Move the blocklist data model classes into `plugins/scrolldaddy/data/`

### Phase 3: Container Infrastructure & Deployment

1. Create `install_scrolldaddy_dns.sh` (Section 6.3)
2. Modify `Dockerfile.template` to include Go compilation and supervisord (Section 6.4)
3. Update `install.sh` port mapping (Section 6.5)
4. Set up host-level Apache reverse proxy for DNS domain (Section 6.6)
5. Configure DNS records (Section 6.7) -- should already be done by now
6. Test end-to-end: build container, verify DNS service starts, test DoH resolution via public URL

### Phase 4: Advanced Features & Polish

1. Add SafeSearch/SafeYouTube UI to filter edit page
2. Service-level blocking: populate `svd_service_domains` with service domain lists
3. Update documentation references
4. Test switching between controld and scrolldaddy plugins to verify fallback works

---

## 8. What Changes, What Stays, What's New

### Controld Plugin
- **Untouched** -- The existing `plugins/controld/` directory is not modified at all. It remains as a working fallback if the self-hosted DNS service has issues.

### Scrolldaddy Plugin (new, forked from controld)
- **ControlDHelper.php** -- Replaced with lightweight `ScrollDaddyHelper.php` (data arrays only, no API calls)
- **Device creation** -- 2 DB inserts instead of 5 API calls + 2 DB inserts
- **Filter toggling** -- 1 DB update instead of API call + DB update
- **Rule/schedule/delete operations** -- DB-only, no API calls
- **All views/templates** -- Carried over from controld (minor text changes for DoH URLs)
- **All business logic** -- Carried over (edit restrictions, deactivation PINs, tier limits)
- **Database tables** -- Shared with controld (`ctld_` prefix). New columns added, no columns removed.
- **Filter/service category keys** -- Same keys, same UI

### New Infrastructure
- **ScrollDaddy DNS Service** -- Go binary (see [scrolldaddy_dns_service.md](scrolldaddy_dns_service.md))
- **Blocklist management** -- Download scheduled task, admin page, 3 new data model classes
- **Container infrastructure** -- supervisord, Go compilation, additional port mappings
- **Per-profile SafeSearch/SafeYouTube** -- DNS rewrites in Go service

### Switching Between Plugins
Both plugins share the same database tables. To switch:
1. Disable one plugin, enable the other (in Joinery plugin settings)
2. No data migration needed -- device, profile, filter, rule data is the same
3. The controld plugin syncs to ControlD's cloud API; the scrolldaddy plugin relies on the local Go DNS service
4. ControlD-specific columns (`cdd_device_id`, `cdd_controld_resolver`, `cdp_profile_id`, `cdp_schedule_id`) are ignored by scrolldaddy
5. Scrolldaddy-specific columns (`cdd_resolver_uid`, `cdp_safesearch`, `cdp_safeyoutube`) are ignored by controld

### Uses Existing Joinery Systems
- **Scheduled tasks** -- Blocklist downloader uses existing ScheduledTaskInterface, auto-discovered, admin-configurable
- **Admin pages** -- Blocklist source management uses standard admin page patterns inside the plugin
- **Docker deployment** -- Same Dockerfile.template, install.sh, Apache reverse proxy + Certbot pattern
- **Cron** -- Already installed and running in containers every 15 minutes
- **Data model classes** -- New tables follow existing SystemBase/SystemMultiBase patterns

### Improved Over ControlD
- **No API latency** -- Filter changes take effect in 30-60 seconds (DB reload) vs. API round-trip per change
- **No API rate limits** -- No external service to throttle us
- **No vendor dependency** -- Full control over the DNS infrastructure
- **Schedule evaluation** -- Per-query (instant transitions) vs. ControlD's mechanism
- **Cost** -- No per-device monthly fees to ControlD

---

## 9. Open Questions

1. **Hosting location:** DECIDED — Same Docker container. The container is built once via `install.sh` and runs continuously; PHP code updates happen inside the running container without restarting it, so the DNS service is never disrupted.

2. **Blocklist curation:** DECIDED — Launch with community blocklists for categories that have well-maintained sources (ads, malware, porn, gambling, social, fakenews, cryptominers, phishing). Defer categories without good community sources (dating, drugs, games, ddns, filehost, gov, iot, nrd, urlshort, dnsvpn). For service blocking, reverse-engineer domain lists from the service keys (e.g., "spotify" → spotify.com, scdn.co, etc.) for the most-used services. The admin page allows adding sources for any category at any time without code changes.

3. **Service domain mapping:** DECIDED — Reverse-engineer domain lists for all 400+ services from the existing service keys. The complete service list is in `ControlDHelper::$services` across 12 categories (audio, career, finance, gaming, hosting, news, recreation, shop, social, tools, vendors, video). Build the domain mapping as a separate data file (`service_domains.sql` or similar) that populates the `svd_service_domains` table. Each service key maps to 1-10 domains typically (e.g., spotify → spotify.com, scdn.co). This is a research task that can be done with a cheaper model.

4. **Resolver UID format:** DECIDED — 32-character lowercase hex string generated via `bin2hex(random_bytes(16))`. 128 bits of randomness, URL-safe, unguessable.

5. **Query logging/stats:** DECIDED — Skip for v1. The `/test` diagnostic endpoint handles debugging. Per-device stats (block counts, query counts) can be added later as a future improvement.

6. **Fallback behavior:** DECIDED — Serve from cached data if DB goes down. SERVFAIL if cache was never loaded. Supervisord restarts the process if it crashes. See DNS service spec Section 14.

7. **Table/class renaming:** DECIDED — Not applicable. The scrolldaddy plugin shares tables with the controld plugin (`ctld_` prefix) to enable switching between them. The data classes keep their existing names (CtldDevice, CtldProfile, etc.). Renaming would break the shared-table approach.

9. **Plugin strategy:** DECIDED — Create a new `plugins/scrolldaddy/` plugin by copying the existing `plugins/controld/` plugin, then converting it. The controld plugin stays untouched as a fallback. Both plugins share the same database tables (`ctld_` prefix). Switching requires only changing which plugin is active -- no data migration.

8. **Wildcard TLS for DoT:** DECIDED — Domain registered at Namecheap, DNS managed by Cloudflare. Use `certbot` with the `certbot-dns-cloudflare` plugin for automated wildcard cert issuance and renewal for `*.dns.scrolldaddy.app`. Requires a Cloudflare API token with DNS edit permissions, stored in a credentials file on the Docker host. Command: `certbot certonly --dns-cloudflare --dns-cloudflare-credentials /etc/letsencrypt/cloudflare.ini -d "*.dns.scrolldaddy.app"`. The resulting cert/key files are mounted into the container for the Go service to use for DoT.
