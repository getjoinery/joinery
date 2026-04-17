# ScrollDaddy Unified Blocks Spec

## Overview

Collapse ScrollDaddy's two parallel filtering stacks — **profile-level** (always-on) and **scheduled-block-level** (time-windowed) — into a single **Block** model. Every device has one implicit **always-on block** plus N optional **scheduled blocks**. Every block has the same shape (rules + filters + services) and an optional schedule.

### What this accomplishes

1. **One data model, not two.** Today, filters, services, and rules exist in *two* parallel table sets — once at profile level (`sdf_filters` / `sds_services` / `sdr_rules`) and once at block level (`sbf_*` / `sbs_*` / `sbr_*`). After: only the block-level tables, with an `sdb_is_always_on` flag marking the always-on instance.

2. **One editor UI.** Today, an always-on filter lives in `filters_edit.php` (checkbox widget), an always-on domain rule lives in `rules.php` (separate page), a scheduled-block filter lives in `scheduled_block_edit.php` (3-state dropdown), and a scheduled-block domain rule also lives on `rules.php` (in a different mode). After: one editor (`scheduled_block_edit.php`) that serves both always-on and scheduled blocks with consistent widgets.

3. **Widget consistency.** The scheduled-block editor already uses a 3-state (`—` / Block / Allow) widget, which is strictly more expressive than a checkbox. Promoting this everywhere removes a UX inconsistency and gives users an explicit "no opinion" option on the always-on block.

4. **One DNS resolver query path.** Today the resolver loads both `sdr_rules` and the profile-level filter/service tables *and* the block-level equivalents, then merges on every query. After: only the block-level tables, with an always-on block that the resolver treats as "always active" via one short-circuit.

5. **Retire a legacy concept.** The `sdp_profiles` table's "secondary profile" pattern is already marked `// (legacy)` in `devices_class.php:330`. After unification, `sdp_profiles` stops carrying filtering policy entirely — it keeps only the fields it still needs for resolver identity and timezone. This is the moment to clean that up, before launch.

6. **Feature-gate consistency.** Today's code applies `scrolldaddy_custom_rules` to the `rules.php` page but not to profile-level filter toggles. After, the gating lives in one place (the block editor), applied consistently per section.

### Why pre-launch is the right time

- **Zero data loss risk.** Prod has 1 user, 1 device, 0 custom rules, 0 scheduled blocks. The migration is effectively empty.
- **No support load.** No real users are using the current split UI; nobody is going to file a ticket about muscle-memory breakage.
- **Refactor cost only grows.** Post-launch, each of the six wins above becomes 5–10× harder to extract because real data and real user behavior make every change a coordination problem.

## Current State

ScrollDaddy's filtering is configured along three dimensions — **rules** (per-hostname), **filters** (category filters like malware/ads/porn/gambling), and **services** (per-service like YouTube/Reddit/Discord) — each of which has a *profile-level* (always-on) representation **and** a *block-level* (scheduled) representation. That's six tables doing three jobs.

### Profile-level (always-on) stack
These are the "baseline policy" tables, owned by an `sdp_profiles` row associated with a device's primary profile.

| Dimension | Table | Data class | File |
|---|---|---|---|
| Rules | `sdr_rules` | `SdRule` / `MultiSdRule` | `data/rules_class.php` |
| Filters | `sdf_filters` | `SdFilter` / `MultiSdFilter` | `data/filters_class.php` |
| Services | `sds_services` | `SdService` / `MultiSdService` | `data/services_class.php` |
| Also: `sdp_safesearch` / `sdp_safeyoutube` on `sdp_profiles` | | `SdProfile` | `data/profiles_class.php` |

- **Filters/Services UI:** `/profile/scrolldaddy/filters_edit?device_id=X` (`views/profile/filters_edit.php`, logic at `logic/filters_edit_logic.php`) — uses **checkbox** widgets.
- **Rules UI:** `/profile/scrolldaddy/rules?device_id=X` (`views/profile/rules.php`, logic at `logic/rules_logic.php`) — plain table with Add/Delete form.
- **Feature gates:** Rules section gated by `scrolldaddy_custom_rules`. Filter/service checkboxes are not gated.

### Block-level (scheduled) stack
These are the "time-windowed override" tables, owned by an `sdb_scheduled_blocks` row.

| Dimension | Table | Data class | File |
|---|---|---|---|
| Rules | `sbr_scheduled_block_rules` | `SdScheduledBlockRule` | `data/scheduled_block_rules_class.php` |
| Filters | `sbf_scheduled_block_filters` | `SdScheduledBlockFilter` | `data/scheduled_block_filters_class.php` |
| Services | `sbs_scheduled_block_services` | `SdScheduledBlockService` | `data/scheduled_block_services_class.php` |
| Parent | `sdb_scheduled_blocks` | `SdScheduledBlock` | `data/scheduled_blocks_class.php` |

- **UI:** `/profile/scrolldaddy/scheduled_block_edit?device_id=X&block_id=Y` — uses **3-state dropdown** widgets for category rules; no domain-rules section today (surprisingly); domain rules are edited via the standalone `rules.php` page in "block mode."
- **Feature gate:** `scrolldaddy_max_scheduled_blocks` (count of scheduled blocks; currently Basic=0, Premium=2, Pro=100).

### DNS resolver (Go, `/home/user1/scrolldaddy-dns/`)
The resolver currently loads both stacks and merges them per query:

- `internal/db/db.go:319-344` — `LoadRules()` reads `sdr_rules`
- Profile-level filter/service loads (line numbers TBD during implementation — verify which of `sdf_filters` / `sds_services` / profile `safesearch`/`safeyoutube` columns are actually queried)
- `internal/db/db.go:454-539` — loaders for `sbf_*` / `sbs_*` / `sbr_*`
- `internal/resolver/resolver.go:371-439` — `isBlockActive()` evaluates schedule + timezone
- `internal/cache/cache.go:149-366` — `LightReload()` refreshes all paths every ~60s

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
All three configuration dimensions migrate out of the profile-level tables into the block-level tables:

| Dimension | Before (profile-level) | After (block-level on always-on block) |
|---|---|---|
| Custom domain rules | `sdr_rules` | `sbr_scheduled_block_rules` |
| Category filters | `sdf_filters` | `sbf_scheduled_block_filters` |
| Service toggles | `sds_services` | `sbs_scheduled_block_services` |
| SafeSearch | `sdp_profiles.sdp_safesearch` (bool column) | `sbf_scheduled_block_filters` row with `filter_key = 'safesearch'` |
| SafeYouTube | `sdp_profiles.sdp_safeyoutube` (bool column) | `sbf_scheduled_block_filters` row with `filter_key = 'safeyoutube'` |

SafeSearch and SafeYouTube become filter keys in the unified filter table — same representation as all other filter toggles, distinguished only by `filter_key`. The resolver already switches on filter_key, so this is free to integrate.

### The sdp_profiles concept after unification
`sdp_profiles` stops carrying filtering policy. It retains only what's needed for DNS identity:
- `sdp_usr_user_id` — ownership (stays)
- Resolver identity fields needed for DoH/DoT routing (stay if any; otherwise drop the table entirely in a future pass)
- Schedule fields (`sdp_schedule_start`, `sdp_schedule_end`, etc.) — **these come out**, since the "secondary profile schedule" is already marked legacy and scheduled blocks own time-windowed behavior now
- `sdp_safesearch`, `sdp_safeyoutube` — removed (moved to filter rows as above)

**Decision for this spec:** keep `sdp_profiles` as a thin table holding just the resolver-UID and user reference. Dropping the table entirely is possible but probably a follow-up — some code paths still use profile IDs for resolver-UID lookups, and untangling that is scope creep.

### Feature gating (unchanged from prior spec rev)
- `scrolldaddy_custom_rules` gates the ability to create/edit custom **domain rules** in any block (always-on or scheduled). Free tier sees the section locked with upsell.
- `scrolldaddy_advanced_filters` gates the ad/malware/fakenews/typo filter category rows in the editor. Already implemented; unchanged.
- `scrolldaddy_max_scheduled_blocks` counts *scheduled* blocks only. The always-on block is exempt (one per device, exists unconditionally).
- **Category filters** (social/gambling/adult/drugs/etc.) remain ungated on the always-on block — see "Free-Tier Product Principle" at the bottom.

## Schema Changes

1. **Add column** `sdb_is_always_on BOOLEAN NOT NULL DEFAULT false` on `sdb_scheduled_blocks`, with a partial unique index as shown above.
2. **Confirm nullability** of `sdb_schedule_start` / `sdb_schedule_end` / `sdb_schedule_days` / `sdb_schedule_timezone` — they already appear nullable per the current `$field_specifications`, but double-check at implementation time since always-on blocks leave these NULL.
3. **Drop tables** (after migration step, in the cleanup deploy):
   - `sdr_rules`
   - `sdf_filters`
   - `sds_services`
4. **Drop columns** on `sdp_profiles`:
   - `sdp_safesearch`, `sdp_safeyoutube` (moved to `sbf_*` as filter rows)
   - Optionally: `sdp_schedule_start`, `sdp_schedule_end`, `sdp_schedule_days`, `sdp_schedule_timezone` — only if we're also killing the "secondary profile schedule" legacy code path. Safe to leave for now if they're still referenced; revisit later.
5. **Remove classes:** `SdRule` / `MultiSdRule`, `SdFilter` / `MultiSdFilter`, `SdService` / `MultiSdService`.

All schema changes are driven by `$field_specifications` updates on the data classes + `update_database`'s column-drop pass once the class definitions are gone. No hand-rolled ALTER scripts needed.

## Data Migration

**Effectively not needed.** At spec time prod has 1 user, 1 device, 0 custom rules, 0 scheduled blocks. Checking filter/service counts too:

```sql
SELECT (SELECT COUNT(*) FROM sdf_filters WHERE sdf_is_active = true) AS active_filters,
       (SELECT COUNT(*) FROM sds_services WHERE sds_is_active = true) AS active_services,
       (SELECT COUNT(*) FROM sdr_rules) AS active_rules,
       (SELECT COUNT(*) FROM sdp_profiles WHERE sdp_safesearch = true) AS safesearch_on,
       (SELECT COUNT(*) FROM sdp_profiles WHERE sdp_safeyoutube = true) AS safeyoutube_on;
```

Run this at deploy time. If all counts are zero (likely), the migration is:

1. Create one always-on block for the one existing device.
2. Nothing to copy.

If any count is non-zero when we run it, the migration expands to:

1. Create always-on block per device.
2. Copy `sdr_rules` → `sbr_scheduled_block_rules`.
3. Copy `sdf_filters` → `sbf_scheduled_block_filters`.
4. Copy `sds_services` → `sbs_scheduled_block_services`.
5. For every profile with `sdp_safesearch = true`, insert an `sbf_scheduled_block_filters` row with `filter_key = 'safesearch'`, `action = 0`.
6. Same for `sdp_safeyoutube` with `filter_key = 'safeyoutube'`.

Each step joins via `sdd_devices.sdd_sdp_profile_id_primary = sdp.sdp_profile_id OR sdd_sdp_profile_id_secondary = sdp.sdp_profile_id` to find the right always-on block. Migration is idempotent: block-creation uses `NOT EXISTS`, copies use `NOT EXISTS (block_id, key|hostname, action)` guards.

No separate migration file pre-committed — write the SQL at deploy time based on actual row counts. With zero rows, this is a one-INSERT deploy step.

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

- `data/scheduled_blocks_class.php`
  - Add `sdb_is_always_on` to `$field_specifications`.
  - Short-circuit `is_active_now()` to return `true` when `sdb_is_always_on` is set.
  - `permanent_delete()` on an always-on block: either disallow it, or treat the always-on block as un-deletable at the class level. It should only be removed as part of device deletion (existing cascade in `devices_class.php:321-328`).

- `data/devices_class.php`
  - In `createDevice()` (line 66-94), after `$device->save()` at line 92, auto-create an `SdScheduledBlock` with `sdb_is_always_on = true`, `sdb_sdd_device_id = $device->key`, `sdb_name = 'Always On'`, `sdb_is_active = true`.
  - Also backfill the same block for the one existing prod device at deploy time (one-line SQL or a small admin utility).

- `data/profiles_class.php`
  - Remove `add_rule()`, `delete_rule()`, `permanent_delete_all_rules()` methods.
  - Remove the `MultiSdRule` block inside `count_blocks()` (lines 118-124).
  - Remove the `$this->permanent_delete_all_rules()` call inside `permanent_delete()` (line 244).
  - Remove `sdp_safesearch` and `sdp_safeyoutube` from `$field_specifications`. Remove `SdFilter` / `SdService` cascade calls in `permanent_delete()` (lines 245-246) — the profile-level filter/service classes go away entirely.
  - Remove `require_once` for `rules_class.php`, `filters_class.php`, `services_class.php`.

- `logic/scheduled_block_edit_logic.php`
  - Allow loading an always-on block without schedule fields.
  - The scheduled-block-count limit check filters `sdb_is_always_on = false` so the always-on block doesn't count toward the limit (add option key in `MultiSdScheduledBlock::getMultiResults()` if needed).
  - Accept the always-on case when handling save: skip schedule validation if `sdb_is_always_on = true`.

- `views/profile/scheduled_block_edit.php`
  - Hide the entire schedule section (lines ~70-136) when `sdb_is_always_on = true`.
  - Render page title and breadcrumb as "Always-On Rules" in always-on mode; keep "Edit Scheduled Block" otherwise.
  - Append new "Custom Domain Rules" section at the bottom — table of existing `sbr_*` rows (hostname + Block/Allow + delete button) plus an inline Add form. Behavior: inline AJAX (see Files to add).
  - When `scrolldaddy_custom_rules = false`, render the Custom Domain Rules section disabled with the upsell prompt (see Free-tier behavior in UI Plan).

- `views/profile/devices.php`
  - Replace the "Always-On Filters" row (lines 122-133) — change the Edit link from `/profile/scrolldaddy/filters_edit?device_id=X` to `/profile/scrolldaddy/scheduled_block_edit?device_id=X&block_id={always_on_block_id}`. Label stays "Always-On Rules".
  - Update `$num_blocks_always[$device->key]` in `logic/devices_logic.php:91-97` to count the always-on block's rules/filters/services instead of `SdProfile::count_blocks()`.
  - Remove the "Custom Rules" menu item on line 118 of the actions menu (no longer a separate page).

- `data/scheduled_block_filters_class.php`
  - Extend the filter key registry (or the keys recognized in validation) to include `safesearch` and `safeyoutube` so these can be stored as normal filter rows on the always-on block.

- Data class registration: `PluginManager::sync()` auto-handles the schema update on the next admin sync.

### Files to add

- `plugins/scrolldaddy/ajax/block_rule_add.php`
  - Inputs: `block_id`, `hostname`, `action` (0=block, 1=allow).
  - Authz: load the block → its device → its user; confirm session user matches.
  - Gate: confirm `scrolldaddy_custom_rules` is true for the user.
  - Delegates to `SdScheduledBlock::add_rule()` for validation + insert (already hostname-validates).
  - Returns rendered `<tr>` HTML (or structured JSON and JS renders client-side — pick during implementation).

- `plugins/scrolldaddy/ajax/block_rule_delete.php`
  - Inputs: `rule_id`.
  - Authz: load rule → its block → its device → its user; confirm session user matches.
  - Delegates to `SdScheduledBlock::delete_rule($rule_id)`.
  - Returns `{success: true}` JSON.

### Files to delete

Profile-level duplicates:
- `data/rules_class.php` (`SdRule`, `MultiSdRule`)
- `data/filters_class.php` (`SdFilter`, `MultiSdFilter`)
- `data/services_class.php` (`SdService`, `MultiSdService`)

Old standalone rules UI:
- `views/profile/rules.php`
- `logic/rules_logic.php`

Old always-on filter editor (now absorbed by `scheduled_block_edit.php`):
- `views/profile/filters_edit.php`
- `logic/filters_edit_logic.php`

After deletion, `grep -rE 'SdRule|MultiSdRule|SdFilter\b|MultiSdFilter|SdService\b|MultiSdService|filters_edit_logic|rules_logic|filters_edit\.php|rules\.php'` should return zero hits outside of docs / specs / historical changelog files.

### Routing
The `/profile/scrolldaddy/rules` and `/profile/scrolldaddy/filters_edit` routes die with their views. Add `serve.php` redirects: both → `/profile/scrolldaddy/devices` (301). Device ID isn't carried in the redirect — by this point links in the wild are dev-only since there's only one user.

## DNS Resolver Changes (scrolldaddy-dns)

All changes in `/home/user1/scrolldaddy-dns/`. Expanded scope vs. a rules-only refactor — now also removes profile-level filter and service loads. Estimated impact: ~300 lines removed, ~50-80 added (bigger than the rules-only estimate; the exact shape depends on how the resolver currently consumes profile-level filter/service/safesearch data).

1. **`internal/db/db.go`**
   - Delete `LoadRules()` (lines 317-344) and the `sdr_rules` schema validation (lines 146-148).
   - Delete any equivalent `LoadFilters()` / `LoadServices()` or profile-level filter/service loaders (grep during implementation — exact function names TBD; look for readers of `sdf_filters`, `sds_services`, `sdp_safesearch`, `sdp_safeyoutube`).
   - Delete profile-level filter/service schema validations.
   - Add `IsAlwaysOn bool` to `ScheduledBlockRow` struct (around line 51-59).
   - Update `LoadScheduledBlocks()` query (lines 414-451) to `SELECT sdb_is_always_on`.
   - The scheduled-block filter loader must continue to accept `safesearch` and `safeyoutube` as valid filter keys (these may already work if the resolver's filter_key switch has a default branch — verify).

2. **`internal/cache/cache.go`**
   - Remove profile-level loaders from the `LightReload()` sequence (around line 167 for rules, plus equivalent lines for filters/services).
   - Remove `ruleMap`, any `filterMap`/`serviceMap` variables, and the per-query merge logic that combines profile-level and block-level policy.
   - In the scheduled-block build pass (lines 248-320), carry through `IsAlwaysOn`.
   - The always-on block's filters/services/rules now flow through the same code path as scheduled blocks — no special-casing in cache.

3. **`internal/resolver/resolver.go`**
   - `isBlockActive()` (line 373): `if block.IsAlwaysOn { return true }` at the top.
   - Remove any code path that separately evaluates profile-level filters/services against a query before checking scheduled blocks. Everything becomes "iterate all active blocks, merge their policy."
   - SafeSearch / SafeYouTube resolution: the existing rewrite logic that fires `safesearch_rewrite` / `safeyoutube_rewrite` (per `plugins/scrolldaddy/ajax/test_domain.php:147-149` which logs these reasons) needs to read its trigger from the always-on block's filter rows rather than `sdp_safesearch` / `sdp_safeyoutube` booleans. One of:
     - (a) Keep the existing rewrite code, just change its input source from a profile boolean to "is filter_key=safesearch with action=block on any active block".
     - (b) Fold SafeSearch/SafeYouTube into the generic block/allow filter flow. Harder because they're rewrites, not blocks.
     - Pick (a) — smallest change, keeps the rewrite semantics intact.

4. **Tests**
   - `internal/resolver/resolver_test.go` — delete tests that rely on profile-level filter/service/rule maps; add tests for an always-on block whose filters/services/rules apply at any time; keep scheduled-window tests.
   - `internal/cache/cache_test.go` — update `LoadForTest()` helper signature if it exposes separate profile-level arguments.

Resolver version bump: minor (e.g., 1.4.0 → 1.5.0). Deploy via the existing `make release` → installer flow documented in CLAUDE.md.

## Rollout Plan

With 0 existing filter/service/rule rows in prod, there's no data-preservation concern, so the PHP and resolver deploys can ship back-to-back on the same day.

1. **Run the zero-data check** (SQL from Data Migration section). If any count is non-zero, handle migration before deploying.
2. **Deploy PHP** with: `sdb_is_always_on` column added; always-on block auto-created for the one existing device (via the new-device hook or a one-line SQL insert); profile-level rule/filter/service classes and old UI files removed; `filters_edit.php` + `rules.php` routes redirect to `/profile/scrolldaddy/devices`.
3. **Deploy resolver** immediately after: profile-level loaders removed, `IsAlwaysOn` wired through, same-day deploy avoids a window where the UI and resolver disagree about policy sources.
4. `sdr_rules` / `sdf_filters` / `sds_services` are dropped and profile columns removed by `update_database` (empty tables/columns, no cleanup concern). Runs automatically after class files are gone.

Rollback: if something goes sideways between steps 2 and 3, the old resolver still works because the tables remain as long as the class files exist. If the class files are already deleted, re-creating the empty tables from a known-good schema dump is a one-command recovery (no real data was ever in them). Keep a snapshot of the scrolldaddy DB immediately before step 2 just in case.

## Testing

- **Data model tests** (`tests/models/`):
  - `SdDevice::createDevice()` produces exactly one always-on block with `sdb_is_always_on = true`.
  - `SdScheduledBlock::is_active_now()` returns true for always-on blocks regardless of schedule fields.
  - `MultiSdScheduledBlock` option keys: scheduled-block-limit check filters out always-on rows (new option key or explicit WHERE).
  - Adding a rule to the always-on block via `SdScheduledBlock::add_rule()` works the same as adding to a scheduled block.

- **Resolver tests:**
  - New test: always-on block filters + rules apply at any time (no schedule window).
  - New test: SafeSearch / SafeYouTube fire when set as filter keys on the always-on block.
  - Existing scheduled-window tests continue to pass unmodified.

- **Manual browser pass (joinerytest first, then prod):**
  - Log in, go to `/profile/scrolldaddy/devices`, verify the iPad row shows "Always-On Rules" as its first entry with Edit → unified editor.
  - Open the always-on editor: verify schedule section is hidden, page title reads "Always-On Rules", all filter/service/SafeSearch toggles persist correctly on save.
  - Verify domain-rule add/delete AJAX works and the row updates without page reload.
  - Create a scheduled block, confirm it still works (schedule section visible, saves correctly, `is_active_now()` flips the "Active now" badge when the window matches).
  - As a Basic-tier user: verify the Custom Domain Rules section on the always-on block renders the upsell prompt and is read-only.
  - Visit old URLs `/profile/scrolldaddy/rules` and `/profile/scrolldaddy/filters_edit` and confirm they 301 to the devices page.
  - On the DNS side: run a `dig` query through the resolver for a domain blocked by the always-on block; verify it's blocked. Then toggle the category off, wait for cache reload, verify it resolves.

## Docs to Update

No existing `/docs/` file covers the ScrollDaddy plugin architecture. During implementation, create `/docs/scrolldaddy_plugin.md` covering:
- Block model (always-on vs scheduled)
- Tier gating (`scrolldaddy_custom_rules`, `scrolldaddy_max_scheduled_blocks`)
- How the Go DNS resolver consumes blocks (high-level, with a pointer to `/home/user1/scrolldaddy-dns/README.md`)
- Data-flow diagram: admin UI → DB → resolver cache → DNS response

Add this doc to the Documentation Index in `CLAUDE.md` under the existing alphabetical list.

## Open Questions

1. **`sdp_profiles` remainder.** After filter/service/SafeSearch/rule columns come off, does the profile table still carry anything that matters to the resolver (e.g., the resolver UID for DoH/DoT routing), or can it be dropped entirely? Decision deferred to implementation — check the resolver's profile ID usage before deciding.

2. **`cdd_profile_id_primary` / `cdd_profile_id_secondary` columns on `sdd_devices`.** These are legacy ControlD-era pointers. If `sdp_profiles` sheds all policy, these columns likely also stop mattering. Worth a cleanup pass; not blocking.

3. **The "secondary profile schedule" feature** (code-marked legacy at `devices_class.php:330` and uses `sdp_schedule_*` columns) was the old way to do time-windowed policy before scheduled blocks existed. It's fully replaceable by scheduled blocks. If nothing still references it after the dust settles, drop the `sdp_schedule_*` columns too; otherwise leave as a follow-up task.

## Free-Tier Product Principle

The Basic tier is intentionally positioned for two audiences: people kicking the tires, and people struggling with addiction who need full-time category blocking (especially adult content) as a recovery aid. Content-category filtering on the always-on block is therefore **not gated** for free users — blocking porn or gambling 24/7 on one device is the core Basic use case, not an upsell teaser.

This means:
- Categories on the always-on block (`rule_porn`, `rule_gambling`, `rule_drugs`, `rule_social`, etc.) remain ungated, matching existing code behavior.
- Paid tiers are differentiated by **scheduled blocks** (time-windowed control), **custom domain rules** (per-hostname control), **advanced filters** (ads / malware / phishing / fake news), and **device count** — not by withholding the base "block a category full-time" capability.
- Future tier changes should preserve this floor. Gating categories behind a paywall would put recovery tooling behind a payment, which is out of scope for this product.

---

# Phase 2 — Retire `sdp_profiles`

## Why

After Phase 1, `sdp_profiles` holds only identity fields (`sdp_profile_id`, `sdp_usr_user_id`, `sdp_is_active`, timestamps) and the vestigial `sdp_schedule_*` columns that nobody reads. No filtering policy lives there. Every device still gets a profile row created at `createDevice()` time, but the row is a pure indirection that adds no value. This phase deletes the table, the FK columns on `sdd_devices`, the `SdProfile` class, and every code path that touches profile identity.

Pre-launch is still the cheapest time to do this — 1 user, 1 profile row on prod, one follow-up deploy. Post-launch, there is real user data and the "secondary profile schedule" legacy path (`get_active_profile()` etc.) becomes harder to justify removing without a migration.

## Current state

### Database
- `sdp_profiles` table: 1 row on prod (profile 12 tied to Jeremy's iPad). Columns: `sdp_profile_id`, `sdp_usr_user_id`, `sdp_is_active`, `sdp_create_time`, `sdp_delete_time`, `sdp_schedule_start`, `sdp_schedule_end`, `sdp_schedule_days`, `sdp_schedule_timezone`.
- `sdd_devices.sdd_sdp_profile_id_primary` — FK into `sdp_profiles`. Always populated.
- `sdd_devices.sdd_sdp_profile_id_secondary` — FK into `sdp_profiles`. Legacy, never populated in practice (comments in code say "legacy").
- No other tables hold FKs into `sdp_profiles`.
- ControlD-era columns (`cdd_profile_id_primary`, `cdd_profile_id_secondary`) — verified not populated; `sdd_devices` schema no longer references them (they were renamed during the `ctld→sd` migration).

### PHP references
- **`plugins/scrolldaddy/data/profiles_class.php`** — `SdProfile` / `MultiSdProfile` classes. Delete.
- **`plugins/scrolldaddy/data/devices_class.php`** —
  - `$field_specifications`: `sdd_sdp_profile_id_primary`, `sdd_sdp_profile_id_secondary` — remove.
  - `SdDevice::createDevice($device, $profile1, $profile2, $post_vars)` — signature must change to `createDevice($device, $user, $post_vars)`.
  - Legacy methods that read secondary profile's schedule:
    - `get_time_to_active_profile()` — delete.
    - `get_schedule_string()` — delete.
    - `get_active_profile()` — delete.
    - `permanent_delete_profile($which)` — delete.
  - `are_filters_editable()` — currently checks primary profile; review and make profile-free (likely: just read a column on the device itself, or remove the feature if no longer used).
  - `permanent_delete()` — remove the two profile-cascade branches.
  - `MultiSdDevice::getMultiResults()` — one filter (`sdd_profile_id`) references a column that never existed; either dead code or a bug. Remove.
- **`plugins/scrolldaddy/logic/device_edit_logic.php`** — drops its `SdProfile::createProfile()` call (around line 86) and passes the device through alone.
- **`plugins/scrolldaddy/ajax/test_domain.php`** — has two dead branches to clean up:
  - Lines 129–136: reshapes `$data['profile_type']` / `$data['schedule_active']` — neither key is emitted by the current resolver. Remove the block.
  - Lines 157–158: handles `reason === 'profile_not_found'` — the reason is never reached after Phase 1 (the `profile == nil` path was already deleted). Remove the `elseif` branch.
- **`plugins/scrolldaddy/ajax/scan_url.php`** — lines 331–333 have the same dead `profile_type` / `schedule_active` shaping. Remove.
- **`plugins/scrolldaddy/logic/profile_delete_logic.php`** — delete entirely.
- **`plugins/scrolldaddy/views/profile/profile_delete.php`** — delete entirely (the user-facing "delete profile" page).
- **Stale `require_once`** for `profiles_class.php` in: `device_edit_logic.php`, `device_delete_logic.php`, `device_soft_delete_logic.php`, `devices_logic.php`, `activation_logic.php`, `data/device_backups_class.php`. Remove each.

### Go resolver references (`/home/user1/scrolldaddy-dns/`)
- **`internal/db/db.go`** — `DeviceRow.PrimaryProfileID` (line 35), `LoadDevices()` SELECT of `sdd_sdp_profile_id_primary` (line 226), `ValidateSchema` entry for `sdp_profiles` (lines 132–134) and the profile-FK columns on `sdd_devices` (line 129). Remove all.
- **`internal/cache/cache.go`** — `DeviceInfo.PrimaryProfileID` (line 34), `ProfileInfo` struct (lines 45–47), `Cache.profiles` map (line 66), `Cache.GetProfile()` (lines 113–117), `LoadForTest` profiles parameter (line 355), the thin `ProfileInfo` population loop in `LightReload()` (around lines 182–192). Remove all.
- **`internal/resolver/resolver.go`** — `ResolveResult.ActiveProfileID` (line 48), the `activeProfileID := device.PrimaryProfileID` assignment (line 182), and its use in the result struct (line 245). Remove.
- **`internal/resolver/resolver_test.go`** — `makeProfile()` stub, `makeCache()`'s `profiles` parameter, call sites that pass a profile map (`map[int64]*cache.ProfileInfo{...}`). Simplify.
- **`internal/doh/handler.go`** — line 409 emits `"active_profile_id": result.ActiveProfileID` in the `/test` endpoint JSON. Remove the key (zero consumers — confirmed).
- ~~`internal/resolver/resolver.go` — `ReasonProfileNotFound` constant~~ (already deleted during post-Phase-1 cleanup; no work remaining here).

### `are_filters_editable()` — impulse-lock feature
`SdDevice::are_filters_editable()` enforces a "Sunday-only edits" lockout (with grace periods for newly-created or just-activated devices), opted out of via `sdd_allow_device_edits`. This is a real product feature aimed at the recovery audience: it prevents a user in recovery from impulsively removing their own blocks at 2am. **Keep it.**

The method's current body reads one vestigial check — `if(!$this->get('sdd_sdp_profile_id_primary')) return true;` — that exists only because pre-profile devices existed at some point. Remove that branch alongside the profile-column removal; the rest of the method keeps working as-is (all its inputs are on `sdd_devices` already).

All three callers of `are_filters_editable()` (`logic/test_logic.php`, `views/profile/scheduled_block_edit.php`, `views/profile/device_edit.php`) stay unchanged.

## Schema changes

1. **Drop FK columns** on `sdd_devices`:
   - `sdd_sdp_profile_id_primary`
   - `sdd_sdp_profile_id_secondary`
2. **Drop table** `sdp_profiles`.
3. Updates to `$field_specifications` on `SdDevice` remove the two columns — `update_database` handles the DB drop on next run after the class change ships.

## PHP changes

### Files to modify
- **`data/devices_class.php`**
  - Remove `sdd_sdp_profile_id_primary` and `sdd_sdp_profile_id_secondary` from `$field_specifications`.
  - New `createDevice($device, $user, $post_vars)` signature. Creates device → creates always-on block (existing call) → returns. No profile creation.
  - Delete `get_time_to_active_profile()`, `get_schedule_string()`, `get_active_profile()`, `permanent_delete_profile()`.
  - Simplify `permanent_delete()` — remove the two profile-cascade branches (lines 331–338 of the current file).
  - Keep `are_filters_editable()` — remove only the stale `sdd_sdp_profile_id_primary` check branch; the rest of the method and all three callers stay as-is.
  - Remove the broken `sdd_profile_id` filter in `MultiSdDevice::getMultiResults()`.
- **`logic/device_edit_logic.php`** — delete the `SdProfile::createProfile()` call; pass `$user` directly to `SdDevice::createDevice()`.
- **`logic/devices_logic.php`**, **`device_delete_logic.php`**, **`device_soft_delete_logic.php`**, **`activation_logic.php`** — remove `require_once(... profiles_class.php)`.
- **`data/device_backups_class.php`** — remove the now-pointless `profiles_class.php` include (verify no backup logic reads profile data — investigation shows it doesn't).
- **`views/profile/scheduled_block_edit.php`** — no change (it calls `are_filters_editable()`, which is being kept).

### Files to delete
- `data/profiles_class.php`
- `logic/profile_delete_logic.php`
- `views/profile/profile_delete.php`

### Grep verification
After the delete pass, these commands should return zero hits outside `specs/implemented/` and migration files:
```
grep -rE 'SdProfile\b|MultiSdProfile|sdp_profile|sdd_sdp_profile_id_|profile_delete_logic|profiles_class\.php' plugins/ views/ logic/ data/
```

## DNS resolver changes (`/home/user1/scrolldaddy-dns/`)

1. **`internal/db/db.go`**
   - Remove `PrimaryProfileID int64` from `DeviceRow`.
   - Drop the `COALESCE(d.sdd_sdp_profile_id_primary, 0)` SELECT column and corresponding Scan target in `LoadDevices()`.
   - Delete the `sdp_profiles` entry from `ValidateSchema`'s expected map.
   - Remove `sdd_sdp_profile_id_primary` and `sdd_sdp_profile_id_secondary` from the `sdd_devices` expected columns (currently absent — confirm).

2. **`internal/cache/cache.go`**
   - Delete `ProfileInfo` struct and the explanatory comment.
   - Delete `Cache.profiles` map field, its initialization in `New()`, and `Cache.GetProfile()`.
   - Remove `PrimaryProfileID` from `DeviceInfo`.
   - `LightReload()`: drop the thin-ProfileInfo build pass.
   - `LoadForTest()`: drop the `profiles` parameter.
   - `CacheStats`: drop the `Profiles` field and the line that populates it in `GetStats()`.

3. **`internal/resolver/resolver.go`**
   - Drop `ResolveResult.ActiveProfileID`.
   - Delete the `activeProfileID := device.PrimaryProfileID` assignment and its consumers (line 245).

4. **`internal/resolver/resolver_test.go`**
   - Drop `makeProfile()` stub.
   - Change `makeCache()` signature to `(devices, blocklists)`.
   - Update every test call site to drop the profiles-map argument.
   - Drop the `primaryID` argument from `makeDevice()` helper; tests don't need it.

5. **`internal/doh/handler.go`** (line 409)
   - Remove the `"active_profile_id"` key from the `/test` endpoint JSON response.

6. ~~`internal/resolver/resolver.go`~~ — `ReasonProfileNotFound` already deleted (in post-Phase-1 cleanup). No step here.

6. **Tests**
   - `go test ./...` must pass after all of the above.

Resolver version bump: minor (e.g., 1.6.0 → 1.7.0). Deploy via the existing `make release` → installer flow.

## Rollout plan

Same two-deploy pattern as Phase 1, same-day for minimal split-brain window.

1. **PHP deploy** with all the class / logic / view changes; `update_database` drops the two `sdd_devices` FK columns on next admin sync.
2. **Resolver deploy** (v1.7.0) immediately after — removes its now-dead profile-loading code.
3. **Database cleanup**: `DROP TABLE sdp_profiles;` — one row to lose on prod (verified: Jeremy's profile 12, no data).

Rollback: `sdp_profiles` can be re-created as an empty table from a schema dump if needed; since no code reads or writes it after Phase 2, this is cosmetic only.

## Testing

- **Data model tests**: `SdDevice::createDevice()` runs without profile arguments; permanent-delete cascades correctly (blocks go away, no orphans).
- **Resolver tests**: all existing tests pass with the simplified cache/struct layouts.
- **Manual browser pass** on joinerytest first, then prod:
  - Create a new device through `device_edit` — confirm device appears with its always-on block, no errors.
  - Soft-delete and permanent-delete a device — confirm no orphan rows.
  - Confirm the block editor still opens and saves correctly.
  - Confirm `/test` endpoint on the resolver no longer returns `active_profile_id` (or that any consumer tolerates its absence).

## Risks

- **`/test` endpoint shape change.** The `active_profile_id` field is emitted by `internal/doh/handler.go:409`. Confirmed zero consumers in the PHP codebase (`test_domain.php`, `scan_url.php`, `test.php` do not read it). Safe to remove.
- **`are_filters_editable()` is NOT being retired.** It's a real impulse-lock for the recovery audience (Sunday-only edits). The only change is removing its now-unreachable `sdd_sdp_profile_id_primary` branch. All three call sites continue to work.
- **Legacy ControlD columns.** The investigation found no evidence that `cdd_profile_id_*` columns still exist on `sdd_devices`. Verify with `\d sdd_devices` at implementation time before running the DROP migration; if any remain, include them in the cleanup.

## Open questions

None at spec time — all resolved above. Append here if any surface during implementation.

