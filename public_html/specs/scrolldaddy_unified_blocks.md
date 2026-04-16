# ScrollDaddy Unified Blocks Spec

## Overview

Unify ScrollDaddy's two parallel filtering systems — "Custom Rules" (always-on, profile-level) and "Scheduled Blocks" (time-windowed, device-level) — into a single **Block** model. Every device gets one implicit **always-on block** plus N optional **scheduled blocks**. All blocks share the same shape: rules + filters + services, optionally gated by a schedule.

This gives users one mental model, collapses the UI from two pages to one, and simplifies the DNS resolver to a single query path.

## Current State

### Two parallel rule systems

**1. Profile-level Custom Rules (always-on)**
- Table: `sdr_rules` (`sdr_hostname`, `sdr_is_active`, `sdr_action`, `sdr_via`, `sdr_sdp_profile_id`)
- Data classes: `SdRule`, `MultiSdRule` in `plugins/scrolldaddy/data/rules_class.php:11-48`
- UI: `/profile/scrolldaddy/rules?device_id=X` (`plugins/scrolldaddy/views/profile/rules.php`)
- Logic: `plugins/scrolldaddy/logic/rules_logic.php`
- Feature gate: `scrolldaddy_custom_rules` (boolean)
- Applies 24/7, attached to a profile

**2. Scheduled Blocks (time-windowed)**
- Parent table: `sdb_scheduled_blocks` (`sdb_schedule_start`, `sdb_schedule_end`, `sdb_schedule_days`, `sdb_schedule_timezone`, `sdb_sdd_device_id`)
- Child tables:
  - `sbr_scheduled_block_rules` — per-block custom domain rules
  - `sbf_scheduled_block_filters` — per-block ad/malware filter toggles
  - `sbs_scheduled_block_services` — per-block service blocks (Spotify, etc.)
- Data classes: `SdScheduledBlock`, `SdScheduledBlockRule`, `SdScheduledBlockFilter`, `SdScheduledBlockService`
- UI: `/profile/scrolldaddy/scheduled_block_edit?device_id=X&block_id=Y`
- Logic: `plugins/scrolldaddy/logic/scheduled_block_edit_logic.php`
- Feature gate: `scrolldaddy_max_scheduled_blocks` (integer; currently Basic=0, Premium=2, Pro=100)
- Applies only during scheduled window via `is_active_now()` (`scheduled_blocks_class.php:160-211`)

### DNS resolver (Go, /home/user1/scrolldaddy-dns/)
Both tables are loaded into memory and merged on each query:
- `internal/db/db.go:319-344` — `LoadRules()` reads `sdr_rules`
- `internal/db/db.go:512-539` — `LoadScheduledBlockDomainRules()` reads `sbr_scheduled_block_rules`
- `internal/resolver/resolver.go:371-439` — `isBlockActive()` evaluates schedule + timezone
- `internal/cache/cache.go:149-366` — `LightReload()` refreshes both paths every ~60s

## Target Architecture

### Single Block model
Every device has one **always-on block** (auto-created, cannot be deleted) plus zero or more **scheduled blocks**. Both kinds live in `sdb_scheduled_blocks`, distinguished by a new column:

```sql
ALTER TABLE sdb_scheduled_blocks
  ADD COLUMN sdb_is_always_on BOOLEAN NOT NULL DEFAULT false;

CREATE UNIQUE INDEX sdb_one_always_on_per_device
  ON sdb_scheduled_blocks (sdb_sdd_device_id)
  WHERE sdb_is_always_on = true AND sdb_delete_time IS NULL;
```

The always-on block ignores `sdb_schedule_*` fields; the resolver's `isBlockActive()` returns `true` immediately when `sdb_is_always_on = true`.

### Rules, filters, and services all live on blocks
After migration, `sdr_rules` is dropped. All custom rules — whether "always on" or "during schedule" — live in `sbr_scheduled_block_rules` under their parent block.

Per-block filter toggles (`sbf_*`) and service blocks (`sbs_*`) already exist on scheduled blocks; the always-on block gets the same shape, so a user can set (e.g.) ad blocking or Spotify blocking at the always-on level instead of only at the profile level.

### Feature gating
- `scrolldaddy_custom_rules` still gates the ability to create/edit rules **in any block** (including the always-on one). Free tier loses rule editing everywhere.
- `scrolldaddy_max_scheduled_blocks` still limits *scheduled* blocks only. The always-on block is exempt (always exists, one per device).
- No pricing changes required — the gating story from the recent pricing refactor (Basic=0/false, Premium=2/true, Pro=100/true) remains semantically identical.

## Schema Changes

1. **Add column** `sdb_is_always_on BOOLEAN NOT NULL DEFAULT false` on `sdb_scheduled_blocks`. Partial unique index as above.
2. **Make `sdb_schedule_start` / `sdb_schedule_end` / `sdb_schedule_days` nullable** if not already, since always-on blocks won't populate them. Verify current nullability in the data class and update `$field_specifications` accordingly.
3. **Drop** `sdr_rules` table (after migration runs and is verified).
4. **Remove** `SdRule` / `MultiSdRule` classes and the feature's settings in `tier_features.json` remains unchanged.

All schema changes are driven by `$field_specifications` updates on the data classes + one migration for the drop. No manual ALTER scripts.

## Data Migration

**Not needed.** At spec time prod has 1 user, 1 device, 0 rows in `sdr_rules`, and 0 scheduled blocks. There's no existing data to preserve.

- The `SdRule` class and `sdr_rules` table get removed entirely in the cleanup deploy (see Rollout).
- The one existing device gets its always-on block created by the same new-device hook used for future devices (see PHP Changes).
- No separate migration file required.

If a handful of rules get created between now and implementation, the fastest thing is a quick ad-hoc SQL copy into `sbr_scheduled_block_rules` at deploy time, not a full migration framework.

## UI Plan

The standalone rules page goes away. Custom domain rules move into the block edit page (`scheduled_block_edit.php`), which serves both scheduled blocks and the always-on block.

### Page order (top to bottom)
1. Block name
2. Schedule section — **hidden when `sdb_is_always_on = true`**
3. Category rules (existing 3-state `—/Block/Allow` dropdowns for Social, Messaging, Gambling, Crypto, Gaming, Adult, Drug, News, Shopping, Dating; Advanced filters for tiers with `scrolldaddy_advanced_filters`)
4. **Custom Domain Rules** (new section, always visible, not collapsible)
   - Table of existing rules: hostname + Block/Allow + delete button per row
   - Inline "Add" form below the table: hostname input + Block/Allow select + Add button

### Add/Delete interaction
Inline AJAX — same cadence as the current standalone rules page. Domain rules are iterative (users often add several at a time), and decoupling from the main form means the big "Save Scheduled Block" button doesn't need to orchestrate per-row state.

- **Add:** POST to a new ajax endpoint (e.g. `plugins/scrolldaddy/ajax/block_rule_add.php`) with `{block_id, hostname, action}`; returns the rendered `<tr>` HTML; JS appends to the table.
- **Delete:** POST to `block_rule_delete.php` with `{rule_id}`; JS removes the `<tr>` on success.
- Both endpoints enforce ownership (rule's parent block's device belongs to the session user) and the `scrolldaddy_custom_rules` feature gate.
- Category rules stay on the main form (still save on "Save Scheduled Block") — their cadence is different: "configure once, forget."

### Free-tier behavior (`scrolldaddy_custom_rules = false`)
Render the section disabled with an upsell prompt rather than hiding it. A free impression of the paid feature is the point — the user sees the table as it would look and a clear path to unlock it.

- Existing rules (migrated from `sdr_rules` for legacy users who got them somehow) render read-only; no add form; delete disabled.
- Empty state copy:
  > **Custom domain rules** *(Premium & Pro)*
  > Block or allow specific websites by domain — like `youtube.com` or `reddit.com`.
  > **[Upgrade]**

  The `[Upgrade]` button links to `/scrolldaddy/pricing`.

### Always-on block mode
When editing the always-on block (`sdb_is_always_on = true`):
- Page title and header read **"Always-On Rules"** (vs "Edit Scheduled Block" for scheduled blocks).
- The name is **fixed** — not renameable. It's a singleton per device, and renaming has no semantic value and invites confusion (users could rename it to collide with a scheduled block's label).
- Schedule section is entirely omitted.
- Everything else — category rules (filters/services 3-state dropdowns) and custom domain rules — is identical to a scheduled block. Filters and services are fully surfaced on the always-on block from day one, not deferred. The DNS resolver's existing block-merge precedence logic applies uniformly when both always-on and a scheduled block are active.

### Devices list page (`views/profile/devices.php`)
- Single list per device combining the always-on block and scheduled blocks, in that order.
- Always-on block label: "Always-On Rules" with an Edit link.
- Scheduled block labels show their schedule window next to the name.
- Remove the separate "Rules" link/button that currently points to `/profile/scrolldaddy/rules`.
- "Add scheduled block" button disabled with upsell if `scrolldaddy_max_scheduled_blocks = 0`.

## PHP Changes

### Files to modify
- `data/scheduled_blocks_class.php` — add `sdb_is_always_on` to `$field_specifications`; update `is_active_now()` to short-circuit `true` when `sdb_is_always_on` is set; add an `add_rule()` method guard that accepts rules for always-on blocks the same way as scheduled blocks.
- `data/devices_class.php` — on device creation (near line 82, after primary profile is wired up), auto-create the always-on block for the new device. Existing prod device gets its always-on block via this same path on first page load or a one-line SQL insert at deploy time.
- `data/profiles_class.php` — remove any `SdRule` reference in the deletion-cascade logic (rules no longer belong to profiles).
- `logic/scheduled_block_edit_logic.php` — allow editing an always-on block without a schedule; the scheduled-block-limit check (`count_all() >= max_blocks`) must filter `sdb_is_always_on = false` so the always-on block doesn't count toward the limit.
- `views/profile/scheduled_block_edit.php` — hide schedule section when `sdb_is_always_on = true`; render page title as "Always-On Rules" in that mode; render the new Custom Domain Rules section at the bottom (inline AJAX add/delete; disabled-with-upsell for `scrolldaddy_custom_rules = false`).
- `views/profile/devices.php` — device row shows the always-on block alongside scheduled blocks in a single list; "Always-On Rules" renders as the first item with an Edit link straight to `/profile/scrolldaddy/scheduled_block_edit?device_id=X&block_id=Y`. Remove the separate "Rules" link (`devices.php:170` currently references `scrolldaddy_max_scheduled_blocks`).
- Data class registration: `PluginManager::sync()` will auto-handle the schema update on next admin sync.

### Files to add
- `plugins/scrolldaddy/ajax/block_rule_add.php` — ownership + feature-gate check (`scrolldaddy_custom_rules`), hostname validation (reuse `SdScheduledBlock::add_rule()` which already validates), insert into `sbr_scheduled_block_rules`, return rendered row HTML.
- `plugins/scrolldaddy/ajax/block_rule_delete.php` — ownership check, soft-delete, return success JSON.

### Files to delete
- `data/rules_class.php` (`SdRule`, `MultiSdRule`)
- `views/profile/rules.php`
- `logic/rules_logic.php`
- Any residual `SdRule` references in `devices_class.php` / `profiles_class.php` cascade logic — grep `SdRule|MultiSdRule` after the file deletions and clean up stragglers.

### Routing
The `/profile/scrolldaddy/rules` route dies with the view. Add a redirect from the old URL to the device detail page for link compatibility: one entry in `serve.php` mapping `/profile/scrolldaddy/rules` → `/profile/scrolldaddy/devices?device_id={id}` (or just 301 to `/profile/scrolldaddy/devices`).

## DNS Resolver Changes (scrolldaddy-dns)

All changes in `/home/user1/scrolldaddy-dns/`. Impact: ~150-200 lines removed, ~30-50 added.

1. **`internal/db/db.go`**
   - Delete `LoadRules()` (lines 317-344) and the `sdr_rules` schema validation (lines 146-148).
   - Add `IsAlwaysOn bool` to `ScheduledBlockRow` struct (line 51-59).
   - Update `LoadScheduledBlocks()` query (lines 414-451) to `SELECT sdb_is_always_on`.

2. **`internal/cache/cache.go`**
   - Remove `database.LoadRules()` call (line 167) and the `ruleMap` variable and merge logic (lines 167-217).
   - In the scheduled-block build pass (lines 248-320), carry through `IsAlwaysOn`.

3. **`internal/resolver/resolver.go`**
   - `isBlockActive()` (line 373): `if block.IsAlwaysOn { return true }` at the top.
   - No other logic changes — block merging already handles multiple active blocks generically.

4. **Tests**
   - `internal/resolver/resolver_test.go` — remove tests that rely on profile-level `Rules` map; add a test for an always-on block applying outside any schedule window.
   - `internal/cache/cache_test.go` — update `LoadForTest()` helper signature if it exposes a separate rules argument.

Resolver version bump: minor (e.g., 1.4.0 → 1.5.0). Deploy via the existing `make release` → installer flow documented in CLAUDE.md.

## Rollout Plan

Simplified — with 0 existing rules in prod, there's no data-preservation concern, so both deploys can ship back-to-back on the same day.

1. **Deploy PHP** with: `sdb_is_always_on` column added, always-on block auto-created for the one existing device (on first page load via the new-device hook, or a one-line SQL insert), `SdRule` class + rules view/logic/ajax removed.
2. **Deploy resolver** immediately after with `LoadRules()` removed and `IsAlwaysOn` support. Same-day deploy avoids any window where the UI and the resolver disagree about where rules live.
3. `sdr_rules` is dropped by `update_database` (empty table, no cleanup concern). No step 3 deploy needed.

Rollback: between step 1 and step 2 the old resolver's `LoadRules()` would fail because `sdr_rules` is dropped in step 1's `update_database` run. If rollback is needed, re-create an empty `sdr_rules` table — no data to restore since it was always empty.

## Testing

- **Data model tests** (`tests/models/`): new-device hook creates exactly one always-on block; `is_active_now()` returns true for always-on regardless of schedule; scheduled-block-limit check filters out always-on rows.
- **Resolver tests:** new test asserting an always-on block's rules apply at any time; existing schedule-window tests continue to pass.
- **Manual browser pass:** log in as a test user on joinerytest, create a device, verify the always-on block appears as the first row in the device UI with Edit going to the unified editor; verify schedule section is hidden on always-on; verify domain-rule add/delete AJAX works; verify a scheduled block still works; verify the upsell prompt renders for a Basic-tier user.

## Docs to Update

No existing `/docs/` file covers the ScrollDaddy plugin architecture. During implementation, create `/docs/scrolldaddy_plugin.md` covering:
- Block model (always-on vs scheduled)
- Tier gating (`scrolldaddy_custom_rules`, `scrolldaddy_max_scheduled_blocks`)
- How the Go DNS resolver consumes blocks (high-level, with a pointer to `/home/user1/scrolldaddy-dns/README.md`)
- Data-flow diagram: admin UI → DB → resolver cache → DNS response

Add this doc to the Documentation Index in `CLAUDE.md` under the existing alphabetical list.

## Open Questions

None at spec time — all resolved above. Any new questions surfaced during implementation should be appended here.

## Free-Tier Product Principle

The Basic tier is intentionally positioned for two audiences: people kicking the tires, and people struggling with addiction who need full-time category blocking (especially adult content) as a recovery aid. Content-category filtering on the always-on block is therefore **not gated** for free users — blocking porn or gambling 24/7 on one device is the core Basic use case, not an upsell teaser.

This means:
- Categories on the always-on block (`rule_porn`, `rule_gambling`, `rule_drugs`, `rule_social`, etc.) remain ungated, matching existing code behavior.
- Paid tiers are differentiated by **scheduled blocks** (time-windowed control), **custom domain rules** (per-hostname control), **advanced filters** (ads / malware / phishing / fake news), and **device count** — not by withholding the base "block a category full-time" capability.
- Future tier changes should preserve this floor. Gating categories behind a paywall would put recovery tooling behind a payment, which is out of scope for this product.
