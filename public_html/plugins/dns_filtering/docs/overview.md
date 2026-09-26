# ScrollDaddy Plugin

ScrollDaddy is a DNS filtering service. Devices (phone, laptop, etc.) query ScrollDaddy's DNS resolver instead of their ISP's, and the resolver decides block / allow / rewrite for every lookup based on the user's policy.

This plugin (`public_html/plugins/dns_filtering/`) provides the admin UI and data model. The actual DNS resolver is a separate Go service at `/home/user1/scrolldaddy-dns/` that reads each Joinery site it filters for over HTTPS, through the site's resolver snapshot action and a scoped machine key — see "Resolver Configuration" below.

## Block Model

All filtering policy lives on **blocks**, stored in `sdb_scheduled_blocks`. Every device has one **always-on block** (auto-created on device creation, `sdb_is_always_on = true`) plus zero or more **scheduled blocks** with time windows. A block is "active" if it is always-on, or if the current time falls within its schedule.

Each block has three kinds of rules, stored in sibling tables:

| Rule Type | Table | What it does | Example |
|---|---|---|---|
| **Category filters** | `sbf_scheduled_block_filters` | Block/allow a curated category from the blocklist | `filter_key='gambling', action=0` → block all gambling sites |
| **Service toggles** | `sbs_scheduled_block_services` | Block/allow a named service and all its domains | `service_key='reddit', action=0` → block Reddit |
| **Custom domain rules** | `sbr_scheduled_block_rules` | Block/allow one specific hostname | `hostname='youtube.com', action=1` → allow YouTube |

Action: `0` = block, `1` = allow. Allow rules override blocks of the same domain during the block's active window.

### SafeSearch and SafeYouTube

SafeSearch (forces safe variants of Google/Bing/DuckDuckGo) and SafeYouTube (Restricted Mode) are triggered by filter rows with `filter_key='safesearch'` or `filter_key='safeyoutube'` on any currently-active block. The resolver rewrites the DNS response to a CNAME rather than blocking.

## Tier Gating

Three subscription tiers, set via `sbt_subscription_tiers.sbt_features` JSON on each tier:

| Feature key | Basic | Premium | Pro |
|---|---|---|---|
| `scrolldaddy_max_devices` | 1 | 3 | 10 |
| `scrolldaddy_custom_rules` | false | true | true |
| `scrolldaddy_advanced_filters` | false | true | true |
| `scrolldaddy_max_scheduled_blocks` | 0 | 2 | 100 |

**`scrolldaddy_custom_rules`** gates the ability to add/edit custom domain rules (the `sbr_*` table) in any block. The Custom Domain Rules section of the block editor is locked with an upsell prompt for free users.

**`scrolldaddy_advanced_filters`** gates the "advanced" filter category rows (ads, malware, fake news, phishing). Category filters at the general level (social, gambling, adult, drugs, etc.) are **not gated** — see below.

**`scrolldaddy_max_scheduled_blocks`** counts only scheduled blocks (`sdb_is_always_on = false`). The always-on block is exempt — it always exists, one per device.

### Free-tier principle

Content-category filtering on the always-on block is intentionally ungated. ScrollDaddy's free tier supports people who need full-time category blocking (especially adult content) as a recovery aid — putting that behind a paywall is off-limits. See `specs/implemented/scrolldaddy_unified_blocks.md` for the full rationale.

## Editor UI

A single editor (`views/profile/scheduled_block_edit.php`) serves both always-on and scheduled blocks, with two render modes branching on `sdb_is_always_on`.

**Always-on mode** — the baseline policy. Page title reads "Always-On Rules", name is fixed, schedule controls are hidden. Each category renders as a binary `Block | Allow` segmented radio. The form submits `'0'` for Block (writes/keeps an `action=0` row) and the empty string for Allow (deletes any existing row). **Critical:** "Allow" must mean "no row," not an explicit `action=1` row, because the resolver merge (`resolver.go:183-239`) unions `AllowKeys` from every active block to delete categories from the effective block set — an explicit allow row on always-on would silently erase a `Block` override on a scheduled block. The resolver default (no row → not blocked) gives free Allow on the baseline.

**Scheduled mode** — an overrides list against the always-on baseline. Existing overrides render as one row each (category label + `Block | Allow` segmented radio + Remove button). Adding an override uses an inline `<select>`-into-list picker at the bottom (a category dropdown with `<optgroup>`s mirroring the always-on grouping, plus a `Block | Allow` action dropdown and an "Add override" button). Categories already overridden are filtered out of the picker. Empty list shows a muted "No overrides — this schedule uses your always-on rules" placeholder.

**Tier downgrade behavior** (option C): when a user with existing advanced-filter overrides (ads, malware, fakenews, typo — see `ScrollDaddyHelper::getRestrictedFilters()`) loses `scrolldaddy_advanced_filters`, the rows render in the override list with a **disabled** segmented radio (cannot edit action) but a **working** Remove button. Remove appends `<input name="remove_advanced_keys[]">` to the form so `scheduled_block_edit_logic.php` can explicitly delete the row even though `update_filters()` is called with `$skip_keys` to preserve untouched advanced rows. The picker excludes advanced filters for downgraded users, so they can't add new ones.

Custom domain rules render at the bottom of the editor with inline add/delete via the `dns_filtering/block_rule_add` and `dns_filtering/block_rule_delete` API actions, decoupled from the main save button — adding rules is iterative, while category toggles are set-and-forget.

## Device Creation

`SdDevice::createDevice()` automatically calls `SdScheduledBlock::getOrCreateAlwaysOnBlock($device_id)` after saving the device, ensuring every device has its always-on block available for policy edits on first page load.

## API Surface

The plugin exposes its logic layer as REST API actions under the `dns_filtering/` namespace (`POST /api/v1/action/dns_filtering/{action}`) — the surface the ScrollDaddy mobile apps are written against. All actions are sessioned; every tier gate and ownership check runs server-side exactly as on the web, because both surfaces call the same logic functions.

| Action | Purpose |
|---|---|
| `devices` | List devices; each entry includes `doh_url`, `dot_hostname`, a per-block summary, `last_seen`, and the hard-block hostname list |
| `device_edit` | Create a device (omit `device_id`) or edit one; `scrolldaddy_max_devices` gate |
| `device_delete` | Permanently delete a device (`device_id`, `confirm=1`) |
| `device_soft_delete` | Deactivate a device |
| `block_list` | All blocks for one owned device, full contents |
| `scheduled_block_edit` | Read one block (no `action` key) or save/delete with the web editor's submit semantics (`action=edit` / `action=delete`) |
| `block_rule_add` | Add a custom domain rule (`block_id` or `device_id`, `hostname`, `action`, optional `hard_block`); `scrolldaddy_custom_rules` gate |
| `block_rule_delete` | Delete a custom domain rule (`rule_id`) |
| `block_filter_set` | Set/clear one filter or service toggle (save-on-change editors) |
| `catalog` | Filter/service catalog with `advanced` flags; static per deployment — cache client-side |
| `account_summary` | Tier name, the five `scrolldaddy_*` feature flags, device count vs. limit |
| `querylog` | Fetch a device's query log from the DNS server |
| `purge_querylog` | Truncate a device's query log |
| `test_domain` | Test one domain against a device's filter |
| `scan_url` | Fetch a page and test its external domains; heavy (seconds). SSRF-guarded — see below |

The web AJAX endpoints (`ajax/block_rule_add.php`, `block_rule_delete.php`, `block_filter_set.php`, `purge_querylog.php`, `test_domain.php`, `scan_url.php`) are thin wrappers over the same logic functions, preserving their original JSON shapes (and `test_domain`'s GET contract).

`scan_url`'s SSRF boundary is the platform-shared `UrlSafetyValidator` (`includes/UrlSafetyValidator.php`), called from `logic/scan_url_logic.php` with `allowed_ports => null` so the page scanner can reach dev sites on non-standard ports: scheme allowlist, all-resolved-IPs private/loopback/link-local/reserved rejection (fail closed), `CURLOPT_RESOLVE` IP pinning, and manual redirect walking with per-hop revalidation. Covered by `tests/unit/url_safety_validator_test.php`.

### Hard-block rules

`sbr_hard_block` on `sbr_scheduled_block_rules` marks a custom rule for **connection-level enforcement by the client apps' tunnel/VPN layers. The DNS resolver ignores the column** — DNS-level behavior is identical with or without it.

- Settable only on **block**-action rules belonging to the device's **always-on block** (`block_rule_add` rejects it elsewhere). The tunnel syncs a static hostname list with no scheduler, so a hard-block rule on a time-windowed block would be enforced 24/7 at the connection level while staying scheduled at the DNS level. Restricting it to the always-on block keeps "hard block" meaning "always blocked, at both layers."
- Rides the `scrolldaddy_custom_rules` tier gate — no separate feature flag.
- Device API responses (`devices`, `block_list`) include `hard_block_hostnames`: the de-duplicated active, block-action, hard-block hostnames on the always-on block (`ScrollDaddyHelper::getHardBlockHostnames()`) — the list apps sync into their tunnel extensions.
- The web editor does not expose the flag; it is app-driven.

## Config Delivery Channels

A device is filtered once it queries the resolver by its UID. That UID reaches
the device two ways, both pointing at the same per-device DoH/DoT endpoints
(`https://{dns_host}/resolve/{resolver_uid}`):

- **Configuration profile (`.mobileconfig`)** — `logic/mobileconfig_logic.php`
  emits a downloadable profile the user installs from the web devices page.
  This is the path for laptops, other phones, and any device the app doesn't
  run on.
- **Native app (`NEDNSSettingsManager`)** — the ScrollDaddy iOS app
  (`ios/scrolldaddy-ios`, on JoineryDNSFilterKit) saves the same DoH URL
  directly as a system encrypted-DNS configuration on the phone it runs on — no
  download, no copy/paste. It reads the URL from the `devices` action's
  `doh_url` (the identical string the `.mobileconfig` generator emits) and,
  after a one-time OS enable, applies every later change (new UID, server
  switch) silently. See `docs/mobile_apps.md` § JoineryDNSFilterKit and
  `specs/implemented/scrolldaddy_ios_app.md`.
- **Native app (`VpnService`)** — the ScrollDaddy Android app
  (`android/scrolldaddy-android`, on `joinery-android-dnsfilter`) runs a local
  `VpnService` that claims only DNS traffic and forwards every query to the same
  per-device `doh_url` from the `devices` action. Consent is a single in-app
  dialog — no Settings trip. Uninstalling or stopping the service restores
  the device's original DNS automatically. See `docs/mobile_apps.md`
  § JoineryDNSFilterKit and `specs/implemented/scrolldaddy_android_app.md`.
- **Private DNS (DoT)** — Android 9+ carries a system-wide encrypted-DNS
  setting, and the resolver identifies devices by DoT SNI subdomain
  (`{resolver_uid}.{dns_host}`, the `dot_hostname` field). Apps can't set Private
  DNS programmatically, so this is a manual path: a user who needs their VPN
  slot for something else (Tailscale, a work VPN) types the `dot_hostname` into
  Settings → Private DNS. Same policy, no VpnService.

All channels are pure delivery of the same server-side policy; the resolver
behaves identically regardless of how the UID reached the device.

## DNS Resolver Flow

The Go resolver (`/home/user1/scrolldaddy-dns/`) asks each site for its snapshot every `SCD_RELOAD_INTERVAL` seconds (60 by default), sending the version it already holds; an unchanged site answers with one small reply (`internal/source`). It downloads the category blocklists the snapshot names itself, one URL at a time, whenever a list is missing or older than `SCD_BLOCKLIST_REFRESH_HOURS` (24 by default); a list that fails to download or parses to too few domains keeps its previous copy. Every good snapshot and list is written to `/var/lib/scrolldaddy/cache/` and read back at startup, so a restarted server filters from its first query even while a site is unreachable (`/health` then answers 200 `stale`). On each DNS query:

1. Identify the device from its resolver UID (unique per device, embedded in DoH/DoT URL or set in the config profile).
2. Iterate the device's blocks. For each block, check `isBlockActive()` — always-on short-circuits to `true`, scheduled blocks evaluate the current time against their schedule + timezone.
3. Merge policy from all active blocks into effective sets: categories to block, domains to block, domains to allow, SafeSearch trigger, SafeYouTube trigger.
4. Apply precedence: allow rules > block rules > category blocklist > upstream DNS.

Implementation: `internal/resolver/resolver.go:Resolve()` handles the merge; `internal/source/source.go` keeps the cache filled from the sites and the lists.

### Resolver Configuration

The resolver is brand-neutral and can serve multiple Joinery deployments simultaneously; it unions their snapshots in memory. Device resolver UIDs are 128-bit random values and are globally unique across deployments, so the union is collision-free.

Each site is one `SCD_JOINERY_SITES` entry, comma-separated, in the form `{base URL}|{public key}|{secret}`:
```
SCD_JOINERY_SITES=https://scrolldaddy.app|public_…|secret_…,https://networksentry.example|public_…|secret_…
```
The entry is shown once, when the key is issued on that site's **DNS server access** panel (below). The base must be an `https://` origin with no path, and each site may be listed once. Nothing reads a site's database from another machine: a site's PostgreSQL answers only its own host.

See the resolver's `README.md` and `/etc/scrolldaddy/OPS_GUIDE.md` for ops details.

### Resolver snapshot and DNS server access

`POST /api/v1/action/dns_filtering/resolver_snapshot` (`logic/resolver_snapshot_logic.php`) answers with everything a DNS server needs to filter this site's devices:

- `devices`: `id`, `uid`, `timezone`, `log_queries` — active, undeleted, with a resolver UID;
- `blocks`: `id`, `device_id`, `name`, `always_on`, `start`, `end`, `days`, `timezone`, and `filters`, `services` and `domains` lists of `{key, action}` — active, undeleted blocks; domain rules active only;
- `blocklist_sources`: the category → URL list and the skip list from `blocklist_sources.json` (`BlocklistSources`), which the DNS servers download and parse themselves;
- `version`: sha256 of the canonical JSON of those three, and `generated_at`.

A caller sends the `if_version` it holds; when it still matches, the answer is `{version, unchanged: true}`.

Only a machine key scoped to this action can call it (`requires_scoped_key`; see `docs/api.md` § Scoped machine keys). The **DNS server access** panel under the plugin's settings (`includes/settings_actions.php`) mints one per configured server through `resolver_key_issue` and ends it through `resolver_key_revoke` (`DnsResolverAccess`):

- the key is read-only, scoped to `dns_filtering/resolver_snapshot`, and restricted to the server's IPv4 address (`dns_filtering_dns_server_ip` / `dns_filtering_dns_secondary_server_ip`, copied when the key is minted — the panel flags a key whose address no longer matches the setting);
- the secret is shown once, as the server's `SCD_JOINERY_SITES` entry for this site (`{base URL}|{public key}|{secret}`); issuing again replaces the key, which is how a key is rotated;
- the key belongs to the admin who issued it. Its scope confines it to the snapshot action, which needs no permission level, so the owner's role and password do not affect it. `User` refuses to delete an account that owns a live scoped key, so the owner cannot be removed out from under the servers: the key is revoked, or re-issued by another admin, first;
- the key ids are kept in `dns_filtering_resolver_key_primary` and `dns_filtering_resolver_key_secondary`.

## Key Files

- **Data model:** `plugins/dns_filtering/data/scheduled_blocks_class.php`, `scheduled_block_filters_class.php`, `scheduled_block_services_class.php`, `scheduled_block_rules_class.php`, `devices_class.php`, `profiles_class.php`
- **UI:** `plugins/dns_filtering/views/profile/scheduled_block_edit.php`, `devices.php`
- **Business logic:** `plugins/dns_filtering/logic/` — page logic (`scheduled_block_edit_logic.php`, `devices_logic.php`, …) plus the API action logic (`block_rule_add_logic.php`, `scan_url_logic.php`, `catalog_logic.php`, …)
- **API actions:** `POST /api/v1/action/dns_filtering/{scan_url|test_domain|purge_querylog|block_rule_add|block_rule_delete|block_filter_set}` — the editor's page JS calls these with the browser-session credential; each has a `_logic_descriptor()` on its logic file
- **Resolver snapshot & keys:** `plugins/dns_filtering/logic/resolver_snapshot_logic.php`, `resolver_key_issue_logic.php`, `resolver_key_revoke_logic.php`, `includes/DnsResolverAccess.php`, `includes/settings_actions.php`
- **Blocklist sources:** `plugins/dns_filtering/blocklist_sources.json`, read by `includes/BlocklistSources.php`
- **Category list & API exports:** `plugins/dns_filtering/includes/ScrollDaddyHelper.php` (`$filters`, `$services`, `exportDevice()`, `exportBlock()`, `getHardBlockHostnames()`)
- **DNS resolver source:** `/home/user1/scrolldaddy-dns/` (Go)

## Marketing Infrastructure

### Page head metadata

The scrolldaddy plugin's `PublicPage.php` no longer overrides `global_includes_top()` or emits its own Open Graph / meta-description block. Per-page SEO/OG/Twitter Card tags come from `$hoptions` populated by each view and rendered by the platform-level `PublicPageBase::global_includes_top()`. See `docs/seo_metadata.md` for the canonical pattern.

### `?coupon=CODE` auto-apply

A marketing URL like `https://scrolldaddy.app/?coupon=PH2026` captures the code to the session, validates it, and applies it automatically to the next cart. The capture lives on `SessionControl` (alongside UTM capture — it's the same pattern: URL → session stickiness → attribution log): `capture_marketing_coupon()`, `apply_pending_coupon_to_cart($cart)`, and `get_pending_coupon_flash()`. The hook is in `RouteHelper::processRoutes()` gated behind `isset($_GET['coupon'])`. Pricing and cart views show a brief "Coupon X will be applied at checkout" flash when the code is valid. Invalid/expired codes fail silently (campaigns outlive coupons) but are still logged as `TYPE_COUPON_ATTEMPT` rows for diagnostics.

### UTM attribution

UTM capture runs on every public page view via `SessionControl::save_visitor_event()`. The first touch is sticky on `$_SESSION['utm_*']`, and any conversion event fired later in the session reads back from there — so `PURCHASE`, `SIGNUP`, and `LIST_SIGNUP` rows are all attributed to the original source without needing to join the visitor history back together.

### Conversion events + attribution reports

Cart-add, checkout-start, purchase, signup, and list-signup events write to `vse_visitor_events` with a `vse_ref_type` / `vse_ref_id` pair pointing at the canonical entity (order/user/mailing_list). See `docs/analytics.md` for the full event catalog and the Attribution admin page at `/admin/admin_analytics_attribution`.
