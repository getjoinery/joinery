# Specification: Upgrade Pipeline — Rename & Reconciliation Gap

## Problem

The upgrade pipeline assumes plugin and theme identity is stable across versions. When a plugin or theme is renamed (or added or removed) on the source side, the deploy silently mishandles it.

### How we found this

The `scrolldaddy` plugin was renamed to `dns_filtering` (and a new `theme/scrolldaddy/` was extracted from the old plugin). The upgrade ran on the prod scrolldaddy.app deployment with these symptoms:

- DB migrations applied correctly: `plg_plugins.plg_name` updated, settings renamed, `active_theme_plugin` pointer updated.
- Files **did not** ship: `plugins/dns_filtering/` was never created, `theme/scrolldaddy/` was never created, the obsolete `plugins/scrolldaddy/` was preserved.
- Plugin sync re-added a fresh `plg_plugins` row for `scrolldaddy` (because the directory still existed on disk, but the migration had already renamed the original row to `dns_filtering`), creating a duplicate.
- The site stayed up only because the static page cache served the pre-deploy homepage.

We recovered manually by tar-piping the new dirs to prod, deleting the obsolete dir, flipping `theme_template`, deleting the duplicate row, reactivating `dns_filtering`, and clearing the page cache.

## Root cause

Three code paths in the upgrade pipeline tie plugin/theme identity to the **prod-side state** rather than the **source manifest**:

1. **`utils/upgrade.php` — discovery.** `get_installed_plugins()` reads `plg_plugins` rows on prod *before migrations run*. `get_installed_stock_themes()` reads the live filesystem. The download loop asks the source for each name. After a rename, the prod DB still says the old name → source 404s on the old name → new name is never asked for.

2. **`includes/DeploymentHelper.php` — `copyCustomToStaging()`.** Treats "in live, not in stage" as "custom upload, preserve". After a rename, the obsolete directory looks identical to a custom plugin and is preserved.

3. **`utils/upgrade.php` — page cache.** No flush after deploy. If old code rendered a page that's still in cache, the new code's render is hidden until the cache entry expires.

Compounded: even if we fixed (1) and (2), sync's ghost-detection would re-add a row for an obsolete-but-on-disk plugin, breaking the renamed plugin's identity.

## Design choices

These are the orthogonal questions and the choices made for this work.

### Q1: Where does the source advertise its stock manifest?

**Chosen: extend the existing `?serve-upgrade` response.** Add `stock_plugins[]` and `stock_themes[]` arrays (each entry: `name`, `version`, `url`). The data is computed from `static_files/plugins/*.tar.gz` and `static_files/themes/*.tar.gz` (which `publish_upgrade.php` already produces). No new endpoint needed.

Rejected: separate endpoint (more roundtrips); compute-time list rebuild on every upgrade (cheap but fragile).

### Q2: When a stock plugin/theme disappears from source, what does prod do?

**Chosen: mark as stale, preserve files and DB row.** A new status value `stale` (in `plg_status` and `thm_status`) flags the row for admin review. Files stay in place on disk; the plugin keeps working until the admin chooses to remove it.

Rejected: auto-remove (destructive, especially for plugins with data tables); refuse upgrade until resolved (too disruptive).

### Q3: Active theme protection

**Moot, given Q2.** Because we never auto-remove, the active theme is never threatened by a missing-from-source condition. If someone deliberately uninstalls the active theme later, that's an explicit operator action, handled separately.

### Q4: Implicit vs explicit renames

**Chosen: implicit.** No `renames.json` manifest. A rename is just "old name disappears from source manifest, new name appears in source manifest, migration handles the DB-side rename". This aligns with how schema changes already work — data class changes plus a migration are paired, never one without the other. Adding an explicit rename mechanism would impose coordination cost on every renamer.

If a renamer forgets the migration, the result is: new plugin downloads cleanly, old plugin marked stale (with its DB row preserved). Recoverable, not catastrophic.

### Q5: Scope — one PR or piecemeal?

**Chosen: one PR.** All three issues share a root cause; the cleanup is small enough to ship together.

## Design

### Source side

`utils/upgrade.php`'s `?serve-upgrade` response gains two arrays:

```json
{
  "system_version": "0.8.30",
  "core_location": "https://scrolldaddy.app/static_files/joinery-core-0.8.30.tar.gz",
  "release_date": "...",
  "release_notes": "...",
  "stock_themes": [
    { "name": "scrolldaddy", "version": "1.0.0", "url": "https://scrolldaddy.app/static_files/themes/scrolldaddy-1.0.0.tar.gz" },
    { "name": "default",     "version": "1.0.0", "url": "https://scrolldaddy.app/static_files/themes/default-1.0.0.tar.gz" }
  ],
  "stock_plugins": [
    { "name": "dns_filtering", "version": "1.0.0", "url": "https://scrolldaddy.app/static_files/plugins/dns_filtering-1.0.0.tar.gz" },
    { "name": "bookings",      "version": "1.0.0", "url": "https://scrolldaddy.app/static_files/plugins/bookings-1.0.0.tar.gz" }
  ]
}
```

Helper functions (in `utils/upgrade.php`):
- `get_published_stock_themes($static_files_dir)` — globs `themes/*.tar.gz`, parses `name-version.tar.gz` filenames, returns array of entries.
- `get_published_stock_plugins($static_files_dir)` — same for plugins.

### Prod side

#### Discovery rewrite

`utils/upgrade.php`'s download loop (currently around line 440) switches to:

```php
// Build download list from source manifest, not prod state
$themes_to_download = $decode_response['stock_themes'] ?? [];
$plugins_to_download = $decode_response['stock_plugins'] ?? [];
```

Each entry has its own URL — no need to compose URLs from a `theme_endpoint` template anymore (the `theme_endpoint` key can stay for backward compat or be removed).

The functions `get_installed_plugins()` and `get_installed_stock_themes()` are no longer called for the download decision. They may still be useful for status display, so keep them but mark as informational.

#### Stale reconciliation

A new step runs **after** migrations and the standard plugin/theme sync, with the cached source manifest in scope:

```
For each row in plg_plugins:
    if plg_name NOT in stock_plugins manifest AND plg_is_stock = true:
        plg_status = 'stale'

For each row in thm_themes:
    if thm_name NOT in stock_themes manifest AND thm_is_stock = true:
        thm_status = 'stale'
```

Rows whose plugin/theme directory is on disk and is_stock=true but not in the manifest indicate a source-side removal/rename. The row is preserved (so any data continues to work) and flagged for admin review.

#### Sync ghost-detection awareness

`PluginManager::sync()` already deactivates plugins whose directory is missing on disk (ghost detection). This logic should leave `plg_status = 'stale'` rows alone — stale plugins still have files on disk; their state is "intentionally preserved across a source-side disappearance," not "broken."

Practically, ghost detection is fine as-is: it triggers only when the **directory is missing**, which is unrelated to staleness. But code review should confirm the two states don't overlap. Same for theme ghost detection.

#### `copyCustomToStaging` refactor

The existing logic (preserve anything in live but not in stage) is correct for both custom plugins AND stale-after-rename plugins. No semantic change is needed; rename comments to clarify both cases.

If desired, can also stamp an internal flag onto preserved-but-not-in-manifest entries so the post-deploy reconciliation step doesn't have to re-derive. Not strictly necessary.

#### Page cache flush

At the end of `upgrade.php`, after migration and sync succeed, recursively delete the contents of `cache/static_pages/`:

```php
$cache_dir = $full_site_dir . '/cache/static_pages';
if (is_dir($cache_dir)) {
    exec('find ' . escapeshellarg($cache_dir) . ' -mindepth 1 -delete');
}
```

Skip on dry-run.

### New status value: `stale`

Added to plugin and theme lifecycle.

**Plugin states:**
- `active` — `plg_active=1`, normally running
- `inactive` — `plg_active=0`, deactivated by operator or sync ghost-detection
- `stale` — `plg_active=0`, `plg_status='stale'`. Was stock. No longer in source manifest. Files preserved. Admin can choose to uninstall.
- (no row) — uninstalled

**Theme states:** same shape with `thm_status`.

**Admin UI implications** (not shipped in this PR — separate follow-up if needed):
- Plugins page: stale plugins shown with a warning banner and an "Uninstall" action.
- Themes page: stale themes shown with a warning; if active, a strongly-worded warning that the site will use stale code.
- These aren't blockers for the pipeline fix; the data is in place for the UI to read whenever someone builds it.

### Implementation files

Source side:
- `utils/upgrade.php` (serve-upgrade block) — add manifest helpers and emit them.

Prod side:
- `utils/upgrade.php` (download loop) — read manifest from response.
- `utils/upgrade.php` (post-sync block) — stale reconciliation step.
- `utils/upgrade.php` (end) — cache flush.
- `includes/DeploymentHelper.php` (`copyCustomToStaging`) — comments updated; logic unchanged.
- `includes/PluginManager.php` — verify ghost detection plays well with `stale`. Likely no change.
- `includes/ThemeManager.php` — same.

No DB schema change (status columns are varchar, accept new value).
No data migration (stale is computed at upgrade time; existing rows aren't touched until the next upgrade encounters them).

## Three categories of plugins/themes

The `is_stock` flag conflates two things in current code: "lives in the source repo" and "should auto-deploy to every site." The following categories matter for this pipeline:

| Category | In source repo | `is_stock` | Auto-ships via pipeline | Examples |
|---|---|---|---|---|
| **Stock** | yes | `true` | yes — every site gets it | `bookings`, `email_forwarding`, `default` theme |
| **First-party brand-specific** | yes | `false` | no — operator deploys manually per site | `scrolldaddy` theme, `getjoinery` theme |
| **Custom (admin-uploaded)** | no | `false` (or absent) | no — preserved on prod, not refreshed from source | One-off plugins uploaded via admin |

This spec only governs the auto-ship pipeline (stock). First-party brand-specific themes/plugins live in the source repo but are deployed deployment-by-deployment via manual ops (tar pipe, SFTP, or whatever). The publish step's `is_stock=true` filter correctly excludes them; the stale-reconciliation logic uses `plg_is_stock=true` / `thm_is_stock=true` so they aren't flagged as stale either. They are simply outside the pipeline.

If a future use case needs first-party brand-specific things to flow through the pipeline (e.g., several brand sites managed centrally), that would be a separate spec — likely introducing a third metadata flag or a per-deployment "include this brand" allowlist.

## Out of scope

- **Admin UI for stale plugins/themes.** Status field is set; visualization deferred until needed.
- **First-party brand-specific deploys.** See category table above. Manual ops for now.
- **Auto-rename detection** (e.g. "noticed scrolldaddy disappeared and dns_filtering appeared, did you mean rename?"). The migration is the source of truth.
- **Custom plugin/theme upload flow.** Custom plugins (is_stock=false) keep working through the same preserve mechanism. Their lifecycle is unchanged.
- **Server Manager dashboard preview** of what would change before applying upgrade. Useful but separate concern.
- **Refresh-archive coordination** — the existing `--refresh-archives` flow already triggers source-side regeneration; this spec doesn't change it.

## Test plan

Hard to write a CI test for this pipeline. Manual verification:

1. **Rename test.** On dev, rename a stock plugin (e.g. `bookings` → `appointments`), commit, publish. Apply upgrade on a sister test site. Verify: new plugin directory arrives, old directory is preserved, old `plg_plugins` row marked `stale`, new row created/migrated, sync clean, no duplicate rows, page cache cleared.
2. **Deletion test.** Remove a stock plugin from source. Publish. Apply upgrade. Verify: prod's row marked stale, files preserved, no removal.
3. **Custom plugin test.** Install a custom (is_stock=false) plugin on a test site. Run upgrade. Verify: custom plugin survives untouched, no stale flag.
4. **Page cache test.** Edit a homepage view, deploy, hit homepage. Verify the new render appears immediately, not the cached version.

Tests 1 and 4 are the primary verification of the gaps that motivated this spec.

## When to revisit

- If multiple renames happen and the implicit-rename approach proves error-prone, revisit Q4 (explicit renames manifest).
- If admin operators frequently uninstall stale plugins by hand, build the admin-UI follow-up.
- If a deployment ships dozens of plugins/themes, the per-tarball download approach may need parallelization. Currently sequential is fine.
