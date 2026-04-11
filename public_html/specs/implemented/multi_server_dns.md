# Multi-Server DNS Support

## Overview

Run two ScrollDaddy DNS server instances on separate hosts for DNS-level redundancy. If the primary server goes down, client resolvers automatically fail over to the secondary via standard DNS NS record behavior. Both servers share one PostgreSQL database (on the primary host) and operate independently.

## Goals

- Zero-downtime DNS resolution when one server is unavailable
- Accurate device "last seen" detection during install flow (query both servers)
- Unified query log view across both servers (merged on demand)
- Minimal code changes and zero additional load during normal query resolution

## Non-Goals

- Database replication or failover
- Load balancing or traffic splitting
- Real-time synchronization between servers

---

## Architecture

```
                   ┌──────────────┐
                   │  Registrar   │
                   │  ns1 = IP_A  │
                   │  ns2 = IP_B  │
                   └──────┬───────┘
                          │
              ┌───────────┴───────────┐
              ▼                       ▼
     ┌─────────────────┐    ┌─────────────────┐
     │   Server A       │    │   Server B       │
     │   (primary)      │    │   (secondary)    │
     │                  │    │                  │
     │  ScrollDaddy DNS │    │  ScrollDaddy DNS │
     │  PostgreSQL      │◄───│  (connects to    │
     │                  │    │   Server A DB)   │
     │  Query logs:     │    │  Query logs:     │
     │  /var/log/sd/    │    │  /var/log/sd/    │
     └─────────────────┘    └─────────────────┘
              ▲                       ▲
              │                       │
     ┌────────┴───────────────────────┴────────┐
     │            Joinery Plugin               │
     │  Primary: Server A (authoritative)      │
     │  Queries both during install flow only   │
     └─────────────────────────────────────────┘
```

Both servers load the same device/profile/blocklist data from the shared database. Each maintains its own in-memory cache, query logs, and last-seen tracking independently.

---

## DNS Server Changes

### 1. New Configuration

Add one new environment variable:

| Variable | Default | Purpose |
|----------|---------|---------|
| `SCD_PEER_URL` | (blank) | Base URL of the peer server's API (e.g., `http://10.0.0.2:8053`) |

The peer's API key is assumed to be the same as `SCD_API_KEY` since both servers are managed together. If blank, peer features are disabled (single-server mode, current behavior preserved).

Add to `Config` struct in `internal/config/config.go`:
```go
PeerURL string // SCD_PEER_URL
```

Add to `config.Load()`:
```go
PeerURL: os.Getenv("SCD_PEER_URL"),
```

Add to `dist/scrolldaddy.env.example`:
```bash
# Peer DNS server URL for log merging (leave blank for single-server mode)
SCD_PEER_URL=
```

### 2. Peer Log Merging — `/device/{uid}/log`

When `SCD_PEER_URL` is configured, the `/device/{uid}/log` endpoint fetches logs from both the local files and the peer server, merges them chronologically, and returns the combined result.

**Changes to `internal/doh/handler.go`:**

Modify the `deviceLog` handler:

```
1. Read local log lines via h.queryLog.ReadTail(uid, n) (existing behavior)
2. If h.peerURL is set:
   a. Make GET request to {peerURL}/device/{uid}/log?lines={n}
      - Include X-API-Key header using h.apiKey
      - Timeout: 3 seconds (don't block if peer is down)
   b. If request succeeds, split response body into lines
   c. Merge local + peer lines by parsing the RFC3339 timestamp prefix
   d. Sort chronologically, take last N lines
   e. Return merged result
3. If peer is unreachable or SCD_PEER_URL is blank, return local-only (current behavior)
```

**Important: Prevent recursive peer calls.** When server A calls server B's `/device/{uid}/log`, server B must NOT call server A back. Add a query parameter `?peer=0` (or header `X-Peer-Request: true`) to the outgoing peer request. When the handler sees this flag, skip the peer fetch and return local-only.

**Merge logic:**

```go
func mergeLogLines(local, peer []string, n int) []string {
    // Both slices are already in chronological order (oldest to newest)
    // Merge sort by RFC3339 timestamp (first field before \t)
    // Return last n lines from merged result
}
```

Log lines are tab-separated with RFC3339 timestamp as the first field:
```
2026-04-10T14:23:45Z\texample.com\tA\tBLOCKED\tcategory_blocklist\tadult\tno
```

Parse up to the first `\t` for sorting. Lines with unparseable timestamps sort to the end.

### 3. Peer Log Purge — `/device/{uid}/log/purge`

When `SCD_PEER_URL` is configured, also forward the purge request to the peer:

```
1. Purge local log (existing behavior)
2. If h.peerURL is set:
   a. POST to {peerURL}/device/{uid}/log/purge?peer=0
   b. Include X-API-Key header
   c. Timeout: 3 seconds
   d. Log but don't fail if peer is unreachable
3. Return success
```

### 4. Pass PeerURL to Handler

In `cmd/dns/main.go`, pass `cfg.PeerURL` to the DoH handler constructor. Add a `peerURL` field to the `Handler` struct.

### 5. No Changes to Last Seen

Last seen remains in-memory only. The Joinery plugin handles multi-server queries for last seen during device setup (see below).

### 6. No Changes to `/reload`

The Joinery plugin will call `/reload` on both servers independently when needed (see below).

---

## Joinery Plugin Changes

### 1. New Settings

Add new settings to the plugin's settings form and migrations:

| Setting | Purpose |
|---------|---------|
| `scrolldaddy_dns_server_ip` | Primary server's public IP (for Windows/router instructions and mobileconfig `ServerAddresses`) |
| `scrolldaddy_dns_secondary_internal_url` | Internal API URL for the secondary server (e.g., `http://23.x.x.x:8053`) |
| `scrolldaddy_dns_secondary_api_key` | API key for the secondary server (may differ from primary) |
| `scrolldaddy_dns_secondary_server_ip` | Secondary server's public IP (for setup instructions and mobileconfig) |

The external-facing hostname (`scrolldaddy_dns_host`) remains singular — both servers respond to the same domain. NS records handle failover at the DNS level, and DoH/DoT URLs don't change from the user's perspective.

**File: `plugins/scrolldaddy/settings_form.php`** — Add fields for secondary internal URL and API key.

**File: `plugins/scrolldaddy/migrations/`** — Add migration to insert the new settings with blank defaults.

### 2. Device Setup Verification (Install Flow)

**File: `plugins/scrolldaddy/logic/devices_logic.php`**

Currently queries primary server's `/device/{uid}/seen`. Change to query both servers when the secondary is configured:

```
For each device in the list:
  1. Query primary: GET {primary_internal_url}/device/{uid}/seen
  2. If secondary is configured AND primary reports seen=false:
     Query secondary: GET {secondary_internal_url}/device/{uid}/seen
  3. Device is "seen" if EITHER server reports seen=true
  4. Use the most recent last_seen timestamp from either response
```

This ensures that no matter which NS record the user's device resolves through during setup, the plugin detects it. The secondary is only queried when the primary hasn't seen the device, keeping additional API calls to a minimum.

**Optimization:** Once a device has been marked as seen/activated, stop querying entirely. The "seen" check is only relevant during the setup flow for new devices.

### 3. Helper Method for Dual-Server API Calls

**File: `plugins/scrolldaddy/includes/ScrollDaddyApiClient.php`** (new file)

Extract the existing cURL logic into a small helper to avoid duplicating URL/auth handling:

```php
class ScrollDaddyApiClient {
    
    // Call an endpoint on the primary server
    public static function callPrimary($path, $method = 'GET', $timeout = 5) { ... }
    
    // Call an endpoint on the secondary server (returns null if not configured)
    public static function callSecondary($path, $method = 'GET', $timeout = 5) { ... }
    
    // Call an endpoint on both servers, return both responses
    public static function callBoth($path, $method = 'GET', $timeout = 5) { ... }
}
```

### 4. Blocklist Reload

**File: `plugins/scrolldaddy/tasks/DownloadBlocklists.php`**

Currently calls `POST /reload` on the primary server after updating blocklists. Change to call both:

```
1. POST {primary_internal_url}/reload (existing)
2. If secondary is configured:
   POST {secondary_internal_url}/reload
   Log result but don't fail task if secondary is unreachable
```

### 5. Domain Test

**File: `plugins/scrolldaddy/ajax/test_domain.php`**

Currently calls `GET /test?uid={uid}&domain={domain}` on the primary. No change needed — this is a diagnostic tool and the primary is authoritative. Both servers have the same rules from the shared database, so results would be identical.

### 6. Query Log Viewing

No plugin changes needed for log viewing. The DNS server's peer log merging handles this transparently — the plugin calls the primary's `/device/{uid}/log` endpoint as before, and the primary merges in the secondary's logs automatically.

---

## Infrastructure Setup

### Second Server

1. Provision a new Linode (or equivalent)
2. Run the ScrollDaddy DNS installer
3. Configure `scrolldaddy.env`:
   - `SCD_DB_HOST` = primary server's IP
   - `SCD_DB_PORT` = 5432
   - `SCD_PEER_URL` = `http://{primary_ip}:8053`
   - Same `SCD_API_KEY` as primary
   - Same `SCD_DOT_*` cert/key settings (wildcard cert works for both)
4. On the primary server, update `scrolldaddy.env`:
   - `SCD_PEER_URL` = `http://{secondary_ip}:8053`

### PostgreSQL Access

On the primary server, allow connections from the secondary:

1. **pg_hba.conf** — Add line for secondary server's IP:
   ```
   host    scrolldaddy    scrolldaddy_dns    {secondary_ip}/32    scram-sha-256
   ```
2. **postgresql.conf** — Ensure `listen_addresses` includes the interface the secondary can reach (or `*`)
3. **Firewall** — Open port 5432 for the secondary server's IP only

### DNS Peer API Access

Each server needs to reach the other's API port (8053) for log merging:

- **Firewall on primary**: Allow inbound 8053 from secondary IP
- **Firewall on secondary**: Allow inbound 8053 from primary IP

### NS Records

At the domain registrar, register two nameservers with glue records:

```
ns1.scrolldaddy.app → {primary_ip}
ns2.scrolldaddy.app → {secondary_ip}
```

For each domain using ScrollDaddy DNS, set nameservers to `ns1.scrolldaddy.app` and `ns2.scrolldaddy.app`.

### DoH/DoT Access

Both servers must accept DoH (443 via Caddy) and DoT (853) traffic:

- **Caddy** on secondary: Same config as primary, reverse proxying to local port 8053
- **Wildcard TLS cert**: Same cert on both servers (for DoT SNI-based routing)
- **Firewall**: Both servers open 443 and 853 to the world

---

## Client Failover Behavior

ScrollDaddy uses DoH (HTTPS) and DoT (TLS) — not plain DNS on port 53. The traditional "primary/secondary DNS server" model doesn't directly apply. Instead, failover relies on two mechanisms depending on platform:

1. **Multiple A records**: Both server IPs published as A records for `dns.scrolldaddy.app`. HTTPS/TLS clients that implement Happy Eyeballs (RFC 8305) race connections to both IPs and use whichever responds first.
2. **Multiple DNS server entries**: Platforms that configure DNS by IP address (Windows, routers) can list both servers explicitly.

### Per-Platform Failover Summary

| Platform | Protocol | Failover mechanism | Speed | Notes |
|----------|----------|-------------------|-------|-------|
| **iOS/iPadOS** | DoH | Happy Eyeballs via multiple A records | ~250ms | Also supports `ServerAddresses` array in mobileconfig for hardcoded IPs (bypasses bootstrap DNS) |
| **macOS** | DoH | Happy Eyeballs via multiple A records | ~250ms | Same `ServerAddresses` support as iOS |
| **Android** | DoT | Sequential retry on multiple A records | 3-5 sec | Slower failover; tries IPs one at a time |
| **Chrome/Edge/Brave** | DoH | Happy Eyeballs via multiple A records | ~250ms | Standard HTTPS connection behavior |
| **Firefox** | DoH | Happy Eyeballs via multiple A records | ~250ms | `network.trr.bootstrapAddr` only accepts one IP |
| **Windows 11** | DoH | Multiple DNS server entries (IP-based config) | 2-5 sec | Users configure both IPs, each with DoH template |
| **Linux** | DoT/DoH | Multiple A records or multiple server entries | Varies | systemd-resolved supports multiple DoT servers |
| **Routers** | DoH | Multiple server entries | 3-5 sec | Most support configuring multiple upstream DoH servers |

### Key Findings

- **DoH gets near-instant failover** on Apple, Chrome, and Firefox because DoH is standard HTTPS and modern HTTPS stacks implement Happy Eyeballs — parallel connection attempts with 250ms stagger.
- **Android DoT is the weakest link** — sequential attempts mean 3-5 seconds of noticeable delay if the first IP is down.
- **Windows is IP-based** — users configure explicit DNS server IPs, not hostnames. The setup instructions must show both IPs with DoH templates mapped to each.
- **Apple mobileconfig profiles** support a `ServerAddresses` array (iOS 14+) that hardcodes server IPs directly in the profile. This bypasses the bootstrap DNS resolution problem entirely and is the most reliable approach for Apple devices.

### Bootstrap Resolution

Every platform must resolve the DoH/DoT hostname (e.g., `dns.scrolldaddy.app`) using plaintext DNS before it can use encrypted DNS. If that bootstrap resolution only returns one IP, failover depends on re-resolution with short TTLs. Mitigation:

- Publish both A records with short TTL (60-300 seconds)
- Use `ServerAddresses` in Apple mobileconfig profiles to bypass bootstrap entirely
- For Windows, the IP-based config inherently avoids this problem

---

## Setup Instructions Changes (Activation Page + Mobileconfig)

The activation page and mobileconfig profile must be updated to support two-server redundancy.

### New Settings

Add two settings for the public-facing server IPs (distinct from the internal API URLs):

| Setting | Purpose |
|---------|---------|
| `scrolldaddy_dns_server_ip` | Primary server's public IP (shown in Windows/router setup instructions) |
| `scrolldaddy_dns_secondary_server_ip` | Secondary server's public IP (shown when configured) |

These go in the same migration as the other secondary settings and in the settings form.

### Mobileconfig Changes

**File: `plugins/scrolldaddy/views/profile/mobileconfig.php`**

Add `ServerAddresses` array to the DNSSettings dict when server IPs are configured:

```xml
<key>ServerAddresses</key>
<array>
    <string>{primary_ip}</string>
    <string>{secondary_ip}</string>
</array>
```

This tells iOS/macOS exactly which IPs to connect to, bypassing bootstrap DNS resolution and enabling immediate Happy Eyeballs failover between both servers.

**File: `plugins/scrolldaddy/logic/mobileconfig_logic.php`** — Pass `server_ips` array to the view.

### Activation View Changes

**File: `plugins/scrolldaddy/views/profile/activation.php`**

**Windows card** — Currently hardcodes a single IP (`45.56.103.84`). Update to:
- Show both IPs when secondary is configured
- Instruct users to add both as DNS servers with DoH encryption enabled
- Each IP gets the same DoH URL template

**Router card** — Add note that users can configure both server IPs for redundancy when their router supports multiple upstream DNS servers.

**File: `plugins/scrolldaddy/logic/activation_logic.php`** — Pass `server_ips` array to the view.

---

## Testing Plan

### DNS Server
1. **Single-server backward compatibility**: Verify that with `SCD_PEER_URL` blank and no secondary plugin settings, everything works exactly as before
2. **Peer log merging**: Generate queries on both servers for the same device; call `/device/{uid}/log` on either server; verify merged output is chronologically sorted and contains entries from both
3. **Peer log recursion prevention**: Verify `?peer=0` flag prevents infinite loops
4. **Peer unreachable**: Verify that if the peer is down, `/device/{uid}/log` returns local-only results without errors or delays beyond the 3-second timeout
5. **Log purge**: Verify purge clears logs on both servers

### Joinery Plugin
6. **Install flow last seen**: Configure device to use secondary server's IP; send a DNS query through secondary; verify plugin's device list shows the device as "seen"
7. **Dual reload**: After blocklist update, verify both servers reload by checking `/stats` on each
8. **Settings form**: Verify new secondary settings and IP fields save and load correctly

### Setup Instructions & Failover
9. **Mobileconfig with ServerAddresses**: Download a mobileconfig with both IPs configured; verify the XML contains `ServerAddresses` array with both IPs; install on iOS/macOS and verify DNS works
10. **Windows instructions**: With both IPs configured, verify the activation page shows both IPs with instructions to add each as a DNS server
11. **Single-server activation**: With no secondary configured, verify activation page shows only the primary IP and mobileconfig has no `ServerAddresses` (or just the primary)
12. **Multi-A-record failover**: Publish both A records for `dns.scrolldaddy.app`; stop primary server; verify DNS queries still resolve on iOS, Android, Chrome, and Firefox
13. **Windows failover**: Configure both IPs as DNS servers on Windows 11 with DoH; stop primary; verify DNS still resolves through secondary

---

## Files Modified

### DNS Server (`/home/user1/scrolldaddy-dns/`)
| File | Change |
|------|--------|
| `internal/config/config.go` | Add `PeerURL` field and env loading |
| `internal/doh/handler.go` | Add `peerURL` field; modify `deviceLog` for peer merging; modify `deviceLogPurge` for peer forwarding; add `mergeLogLines` helper; add recursion guard |
| `cmd/dns/main.go` | Pass `PeerURL` to handler constructor |
| `dist/scrolldaddy.env.example` | Add `SCD_PEER_URL` |

### Joinery Plugin (`plugins/scrolldaddy/`)
| File | Change |
|------|--------|
| `settings_form.php` | Add secondary URL, API key, and server IP fields |
| `migrations/` | New migration for secondary settings and server IPs |
| `includes/ScrollDaddyApiClient.php` | New helper for single/dual server API calls |
| `logic/devices_logic.php` | Query both servers for last seen during install flow |
| `logic/activation_logic.php` | Pass server IPs array to view |
| `logic/mobileconfig_logic.php` | Pass server IPs array to view |
| `views/profile/activation.php` | Windows: show both IPs; Router: note about two servers |
| `views/profile/mobileconfig.php` | Add `ServerAddresses` array with both IPs |
| `tasks/DownloadBlocklists.php` | Call `/reload` on both servers |

### Infrastructure
| Item | Change |
|------|--------|
| Primary `scrolldaddy.env` | Add `SCD_PEER_URL` |
| Primary `pg_hba.conf` | Allow secondary IP |
| Primary firewall | Open 5432 and 8053 for secondary IP |
| Secondary server | Full ScrollDaddy DNS install |
| Domain registrar | Add ns2 glue record |
