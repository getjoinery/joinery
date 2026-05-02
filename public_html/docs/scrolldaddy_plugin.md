# ScrollDaddy Plugin

ScrollDaddy is a DNS filtering service. Devices (phone, laptop, etc.) query ScrollDaddy's DNS resolver instead of their ISP's, and the resolver decides block / allow / rewrite for every lookup based on the user's policy.

This plugin (`public_html/plugins/dns_filtering/`) provides the admin UI and data model. The actual DNS resolver is a separate Go service at `/home/user1/scrolldaddy-dns/` that reads PostgreSQL databases from one or more Joinery deployments — see "Resolver Configuration" below.

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

Custom domain rules render at the bottom of the editor with inline AJAX add/delete (via `plugins/dns_filtering/ajax/block_rule_add.php` and `block_rule_delete.php`), decoupled from the main save button — adding rules is iterative, while category toggles are set-and-forget.

## Device Creation

`SdDevice::createDevice()` automatically calls `SdScheduledBlock::getOrCreateAlwaysOnBlock($device_id)` after saving the device, ensuring every device has its always-on block available for policy edits on first page load.

## DNS Resolver Flow

The Go resolver (`/home/user1/scrolldaddy-dns/`) reads all block data from PostgreSQL every ~60 seconds via `LightReload()`. On each DNS query:

1. Identify the device from its resolver UID (unique per device, embedded in DoH/DoT URL or set in the config profile).
2. Iterate the device's blocks. For each block, check `isBlockActive()` — always-on short-circuits to `true`, scheduled blocks evaluate the current time against their schedule + timezone.
3. Merge policy from all active blocks into effective sets: categories to block, domains to block, domains to allow, SafeSearch trigger, SafeYouTube trigger.
4. Apply precedence: allow rules > block rules > category blocklist > upstream DNS.

Implementation: `resolver.go:Resolve()` handles the merge. `cache.go:LightReload()` handles the 60s refresh. `db.go:LoadScheduledBlocks()` is the single source query.

### Resolver Configuration

The resolver is brand-neutral and can serve multiple Joinery deployments simultaneously. Each deployment has its own database; the resolver unions all of them in memory. Device resolver UIDs are 128-bit random values and are globally unique across deployments, so the union is collision-free.

**Single deployment (current ScrollDaddy setup):** the legacy `SCD_DB_*` env vars still work.

**Multiple deployments:** set `SCD_JOINERY_DB_URLS` to a comma-separated list of PostgreSQL DSNs:
```
SCD_JOINERY_DB_URLS=host=scrolldaddy-db dbname=joinery user=scd password=xxx sslmode=disable,host=networksentry-db dbname=joinery user=scd password=xxx sslmode=disable
```
Or URL form: `postgres://user:password@host/dbname,...`

Each DB must be reachable from the resolver IPs (`45.56.103.84`, `97.107.131.227`). The resolver validates the schema of every configured DB at startup and retries if any fail. Blocklist domains are unioned from all DBs (both deployments share the same blocklist source, so this is typically a no-op).

See the resolver's `README.md` and `/etc/scrolldaddy/OPS_GUIDE.md` for ops details.

## Key Files

- **Data model:** `plugins/dns_filtering/data/scheduled_blocks_class.php`, `scheduled_block_filters_class.php`, `scheduled_block_services_class.php`, `scheduled_block_rules_class.php`, `devices_class.php`, `profiles_class.php`
- **UI:** `plugins/dns_filtering/views/profile/scheduled_block_edit.php`, `devices.php`
- **Business logic:** `plugins/dns_filtering/logic/scheduled_block_edit_logic.php`, `devices_logic.php`
- **AJAX:** `plugins/dns_filtering/ajax/block_rule_add.php`, `block_rule_delete.php`
- **Category list:** `plugins/dns_filtering/includes/ScrollDaddyHelper.php` (`$filters`, `$services`)
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
