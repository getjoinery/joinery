# ScrollDaddy Plugin

ScrollDaddy is a DNS filtering service. Devices (phone, laptop, etc.) query ScrollDaddy's DNS resolver instead of their ISP's, and the resolver decides block / allow / rewrite for every lookup based on the user's policy.

This plugin (`public_html/plugins/scrolldaddy/`) provides the admin UI and data model. The actual DNS resolver is a separate Go service at `/home/user1/scrolldaddy-dns/` that reads the same PostgreSQL database.

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

A single editor (`views/profile/scheduled_block_edit.php`) serves both always-on and scheduled blocks. In always-on mode, the page title reads "Always-On Rules", the name is fixed, and the schedule controls are hidden. Category rules use consistent 3-state dropdowns (`— / Block / Allow`) everywhere; "—" means the block takes no stance, so the setting is inherited from whatever default applies when no block has an opinion.

Custom domain rules render at the bottom of the editor with inline AJAX add/delete (via `plugins/scrolldaddy/ajax/block_rule_add.php` and `block_rule_delete.php`), decoupled from the main save button — adding rules is iterative, while category toggles are set-and-forget.

## Device Creation

`SdDevice::createDevice()` automatically calls `SdScheduledBlock::getOrCreateAlwaysOnBlock($device_id)` after saving the device, ensuring every device has its always-on block available for policy edits on first page load.

## DNS Resolver Flow

The Go resolver (`/home/user1/scrolldaddy-dns/`) reads all block data from PostgreSQL every ~60 seconds via `LightReload()`. On each DNS query:

1. Identify the device from its resolver UID (unique per device, embedded in DoH/DoT URL or set in the config profile).
2. Iterate the device's blocks. For each block, check `isBlockActive()` — always-on short-circuits to `true`, scheduled blocks evaluate the current time against their schedule + timezone.
3. Merge policy from all active blocks into effective sets: categories to block, domains to block, domains to allow, SafeSearch trigger, SafeYouTube trigger.
4. Apply precedence: allow rules > block rules > category blocklist > upstream DNS.

Implementation: `resolver.go:Resolve()` handles the merge. `cache.go:LightReload()` handles the 60s refresh. `db.go:LoadScheduledBlocks()` is the single source query.

See the resolver's `README.md` and `/etc/scrolldaddy/OPS_GUIDE.md` for ops details.

## Key Files

- **Data model:** `plugins/scrolldaddy/data/scheduled_blocks_class.php`, `scheduled_block_filters_class.php`, `scheduled_block_services_class.php`, `scheduled_block_rules_class.php`, `devices_class.php`, `profiles_class.php`
- **UI:** `plugins/scrolldaddy/views/profile/scheduled_block_edit.php`, `devices.php`
- **Business logic:** `plugins/scrolldaddy/logic/scheduled_block_edit_logic.php`, `devices_logic.php`
- **AJAX:** `plugins/scrolldaddy/ajax/block_rule_add.php`, `block_rule_delete.php`
- **Category list:** `plugins/scrolldaddy/includes/ScrollDaddyHelper.php` (`$filters`, `$services`)
- **DNS resolver source:** `/home/user1/scrolldaddy-dns/` (Go)
