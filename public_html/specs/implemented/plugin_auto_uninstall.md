# Plugin Uninstall Consolidation Spec

## Overview

Two bundled changes to the plugin lifecycle:

1. **Collapse the removal lifecycle.** Today there are four removal-side states (active / deactivated / uninstalled-with-data-preserved / permanently-deleted). Three plugins' worth of evidence (every `uninstall.php` drops its own tables) says operators want one destructive action, not a two-stage teardown. Collapse to three states (active / deactivated / uninstalled) with uninstall as a single destructive action.

2. **Untangle "stock" from "auto-install" in upgrade discovery.** Today `upgrade.php` treats every stock plugin with files on disk as something to auto-refresh, and uses the `'uninstalled'` status as an opt-out signal. The catalog-membership concept (`is_stock`) got conflated with the site-membership concept (installed on this site). Untangle by switching upgrade to refresh *whatever is installed*, regardless of stock/custom. With that change, the opt-out signal isn't needed.

The two changes are coupled: once the `'uninstalled'` status goes away, upgrade's glob-and-skip logic has no status to check anyway. Fixing both together avoids a mid-state where the system is internally inconsistent.

This spec also relocates per-plugin cleanup (settings, menus) out of hand-written `uninstall.php` scripts and into `PluginManager`, driven by declarations in `plugin.json` (per the companion `declarative_plugin_settings` spec).

## Current State

### Four-state removal lifecycle

| State | `plg_status` | Tables | Scaffolding (menus/settings/tasks) | Row in `plg_plugins` | Files on disk |
|---|---|---|---|---|---|
| Active | `active` | yes | yes | yes | yes |
| Deactivated | `inactive` | yes | yes | yes | yes |
| Uninstalled (preserved) | `uninstalled` | yes | no | yes | yes |
| Permanent-deleted | — | no | no | no | optionally deleted |

`PluginManager::uninstall()` at `includes/PluginManager.php:855` leaves tables alone. A separate `permanentDeleteTables()` at `:937` drops them, and `plugins_class.php::permanent_delete_with_files()` at `:247` bundles uninstall + table drop + file deletion.

### What every hand-written `uninstall.php` actually does

- `DROP TABLE IF EXISTS` for each of the plugin's tables (reverse FK order)
- `DELETE FROM stg_settings WHERE stg_name LIKE '{plugin}_%'`
- `DELETE FROM amu_admin_menus WHERE amu_defaultpage LIKE '/plugins/{plugin}/%'`

Five plugins, 27–41 lines each, 174 lines total. All structurally identical. Every one drops tables — so the "preserved data" intermediate state is something every plugin author has been working around.

### Upgrade's plugin discovery conflation

`upgrade.php:1555` (`get_installed_stock_plugins()`) and `:1612` (`get_all_plugins_info()`) do:

1. Load `plg_plugins` rows, collect names where `plg_status === 'uninstalled'`
2. Glob `plugins/*/plugin.json` on disk
3. For each plugin directory, include it in the download list if `is_stock: true` **and** not in the uninstalled list

This mixes three concepts:
- **Catalog member** — "we publish this" (`is_stock: true`)
- **Installed on this site** — operator has this (implied by `plg_plugins` row + directory)
- **Auto-install everywhere** — "this should appear on every site" (implied by the glob + is_stock filter)

There is no plugin in today's system that's genuinely auto-install-everywhere. Every plugin is optional. The glob loop treats the catalog as the auto-install set, and the `'uninstalled'` status is the workaround for that overreach.

## Design

### New three-state lifecycle

| State | Row | Tables | Scaffolding | Files on disk | Code loads |
|---|---|---|---|---|---|
| Active | yes | yes | yes | yes | yes |
| Deactivated | yes | yes | yes | yes | no |
| Uninstalled | **no row** | **dropped** | **dropped** | yes | no |

No `'uninstalled'` status value. No `plg_uninstalled_time` column. No `permanent_delete` admin action. Uninstall = gone. Files stay on disk; operator can `rm` the directory manually if they want them removed (rare and out of scope for the UI).

### New `PluginManager::uninstall()` flow

1. **Delete declared settings** — read `plugin.json` via `PluginHelper::getInstance($pluginName)->getDeclaredSettings()`. Single `DELETE FROM stg_settings WHERE stg_name IN (...)` over the declared names. Settings never declared, or previously declared and since removed from the manifest, are left as orphans — accepted per the settings spec.
2. **Delete admin menus** — existing `syncAdminMenus($name, [])`.
3. **Remove deletion rules** — existing.
4. **Delete scheduled tasks** — existing.
5. **Delete version/dependency records** — existing.
6. **Run `uninstall.php` hook** — plugin-specific external cleanup. Runs *after* declarative cleanup (so it doesn't reason about scaffolding rows the system will delete) but *before* table drop (so the hook can still query the plugin's own tables for external teardown — e.g., enumerating cached DNS records to revoke).
7. **Drop plugin tables** — moved in from `permanentDeleteTables()`. Reuses existing table-discovery regex.
8. **Delete the `plg_plugins` row.**

### Hook failure is fatal

If step 6 throws, **steps 7 and 8 do not run.** Scaffolding cleanup (1–5) has already committed; tables and plugin row remain. Operator sees the error, fixes the hook, re-runs uninstall — which is idempotent (DELETEs in 1–5 are by exact key, no-ops on retry; step 2 `syncAdminMenus($name, [])` is idempotent by design).

Rationale: the hook exists for external cleanup the system can't perform. Proceeding with destructive steps after the hook failed leaves external state dangling while silently reporting success. Failing loud and preserving the tables lets the operator retry or investigate before data is gone.

**UI during partial-failure window:** `plg_status` is unchanged by this flow (we never flip it to anything intermediate). If the plugin was `active`, it reads `active` after the failed uninstall — but with menus and settings already deleted, navigating into it will be broken. Admin plugins page surfaces the hook error as a warning flash on the row. The Uninstall button remains available and re-running it is the resolution path.

### Upgrade endpoint becomes source of truth for stock plugins

Two related behavior changes, both driven by the same principle: the upgrade endpoint is the authoritative source for stock plugin files. Custom plugins remain self-sourced (on-disk copy is the source of truth).

**Upgrade discovery** — replace the glob+stock+uninstalled-skip logic with a straight DB query:

```php
function get_installed_plugins() {
    $plugins = new MultiPlugin();
    $plugins->load();
    $names = [];
    foreach ($plugins as $plugin) {
        $names[] = $plugin->get('plg_name');
    }
    return $names;
}
```

Upgrade refreshes whatever is in `plg_plugins`, regardless of `is_stock`. No opt-out check needed — uninstalled plugins have no row. The download loop attempts each; stock plugins succeed, custom plugins 404 and are skipped via the existing warning path at `upgrade.php:786`.

**Install refreshes from upstream** — when `PluginManager::install($name)` runs, attempt to download a fresh archive from the upgrade endpoint and extract over the plugin directory *before* proceeding with the install. Don't read `is_stock` from the on-disk manifest to decide — the endpoint *is* the source of truth for what's stock. A 404 means this is a custom plugin (endpoint doesn't know about it); any other failure means the endpoint is unreachable.

Failure modes:
- **Endpoint 404** — custom plugin. Silent skip, use on-disk files.
- **Endpoint unreachable / download failed** — log a warning, fall back to on-disk files, install proceeds.

This closes the "uninstalled then reinstalled later with stale code" concern without introducing a second admin action. Stock plugins always get fresh code on install; custom plugins keep their local source-of-truth. Never reading the stale-manifest `is_stock` value sidesteps a failure mode where a plugin's upstream stock status flipped but the disk copy never got refreshed.

The `is_stock` flag's behavioral role is now narrow and clear: *the upgrade endpoint publishes this plugin*. It no longer conflates "should be on every site," and it's no longer consulted at install time.

### How new stock plugins reach a site

Accepted: new upstream stock plugins don't auto-appear on existing sites. Since upgrade only refreshes what's in `plg_plugins` (installed), and the admin plugins UI globs `plugins/*/plugin.json` on disk, a plugin that doesn't already exist on disk won't be visible in the admin UI. The operator gets the plugin onto the site by either:

- **Install button**, for plugins already on disk (e.g., shipped with the initial site install, or copied in manually),
- **Upload**, if a plugin-upload UI exists (or is added later).

Trade-off vs. today's behavior: losing a convenience (new stock plugins auto-landing on disk) to gain a principled model (upgrade = refresh installed, nothing more). Acceptable because no plugin is currently system-required.

### What `uninstall.php` is for

The hook is optional. It exists only for work outside the declarative model:
- External API calls (notify a third party, revoke an API key)
- Filesystem cleanup (remove uploaded files, cached assets outside the DB)
- Data archival (write to a log table before teardown — the hook runs before table drop, so this works)
- State reset in paired services (ScrollDaddy flushing cached DNS records, Server Manager notifying remote nodes)

An empty `uninstall.php` or an absent one is the correct state for most plugins.

### Naming-based fallback: explicitly rejected

Tempting to keep `DELETE FROM stg_settings WHERE stg_name LIKE '{plugin}_%'` as a belt-and-braces default. **Rejected.** Two sources of truth that can disagree. The declared list is the list.

## Implementation Plan

### Phase 1 — Destructive uninstall in `PluginManager`

1. Add `deleteDeclaredSettings()`:
   ```php
   private function deleteDeclaredSettings($pluginName) {
       $helper = PluginHelper::getInstance($pluginName);
       $declared = $helper->getDeclaredSettings();
       if (empty($declared)) return;
       $names = array_column($declared, 'name');
       $dblink = DbConnector::get_instance()->get_db_link();
       $placeholders = implode(',', array_fill(0, count($names), '?'));
       $sql = "DELETE FROM stg_settings WHERE stg_name IN ($placeholders)";
       $stmt = $dblink->prepare($sql);
       $stmt->execute($names);
   }
   ```
   Contract with settings spec: `getDeclaredSettings()` returns `[['name' => ..., ...], ...]` — each entry's `name` key is the `stg_name` value.
2. Reorder `uninstall($name)` to the 8-step flow. Inline `permanentDeleteTables()` as step 7, then delete the standalone method.
3. Step 8 deletes the `plg_plugins` row via SystemBase's `permanent_delete()` (not the Plugin-class `permanent_delete_with_files()` — that one is being removed in step 4).
4. Delete `plugins_class.php::permanent_delete_with_files()` — no longer used.
5. Remove `'uninstalled'` as a status value. Grep for `'uninstalled'` in `plugins_class.php` and remove every branch (status-badge rendering, dead-code checks in the method deleted by #4).
6. Remove `plg_uninstalled_time` from `$field_specifications` in `plugins_class.php`. `DatabaseUpdater` drops columns absent from specs (verified at `includes/DatabaseUpdater.php:437-446`), so next `update_database` run drops it automatically.
7. **One-time migration for existing `plg_status = 'uninstalled'` rows:** add a migration that runs the new destructive uninstall path on each — drop tables, delete the row. Runs as part of the same deploy. Rationale: these rows represent the operator's already-committed intent to uninstall; collapsing them to the new model is the semantically correct move.
8. Confirm `onUninstall()` handles a missing `uninstall.php` silently (debug log, not warning). Add a `file_exists()` guard if not.

### Phase 2 — Wire upgrade endpoint as source of truth for stock plugins

**Upgrade-side (discovery):**
1. In `upgrade.php`:
   - Delete `get_installed_stock_plugins()` and its `is_stock` + `uninstalled` filtering logic. Replace with a DB query that returns every `plg_plugins` row's name.
   - Simplify `get_all_plugins_info()` — drop the `uninstalled` list and the `is_uninstalled` field. Keep `is_stock` for display purposes only.
   - Update the download loop to iterate over DB-reported installed plugins (grep for the current stock-plugin loop to locate).
2. Grep for `Uninstalled` / `is_uninstalled` in `upgrade.php` and remove the status-rendering branch — that state no longer exists.

**Install-side (fresh file fetch):**
3. Add `PluginManager::refreshFromUpstream($name)`. Fetches from the upgrade endpoint (same URL pattern the upgrade loop uses: `$theme_endpoint . '?download=' . urlencode($name) . '&type=plugin'`), downloads to a temp file, extracts into `plugins/{name}/` overwriting. **Does not read the on-disk manifest** — the endpoint's response is the source of truth for whether this plugin is upstream-published.
   - **Endpoint 404:** custom plugin, silent no-op.
   - **Endpoint unreachable / transfer failure:** log a warning, no-op.
   - **Success:** extract over the plugin directory.
4. Call `refreshFromUpstream($name)` at the top of `PluginManager::install($name)`, before any DB work or hook invocations.
5. The endpoint URL comes from the same setting upgrade uses (`theme_endpoint` or equivalent — verify during implementation). If the setting is missing, skip the refresh with a warning (site isn't configured for upstream fetches).

### Phase 3 — Admin UI

Use grep to locate each reference rather than relying on current line numbers (line numbers will shift once the settings spec lands first).

1. `admin_plugins.php`: delete every `$actions['Permanently Delete']` assignment (grep for that string).
2. `admin_plugins.php`: delete the `elseif ($plugin_status === 'uninstalled')` branch — no plugin will ever be in that state.
3. `admin_plugins_logic.php`: delete the `action === 'permanent_delete'` branch.
4. Update the Uninstall confirmation text to: *"This will drop all of this plugin's tables and delete its data. Plugin files will stay on disk. This cannot be undone."*
5. Remove the `confirmPluginAction('permanent_delete', ...)` client-side handler if it's no longer referenced.

### Phase 4 — Convert existing plugins

Verified by reading each file:

| Plugin | Current `uninstall.php` | After conversion |
|---|---|---|
| `bookings` | DROP 2 tables + DELETE settings + DELETE menus | Delete file |
| `items` | DROP tables + DELETE settings + DELETE menus | Delete file |
| `email_forwarding` | DROP 3 tables + DROP 3 sequences + DELETE settings + DELETE `plm_plugin_migrations` rows | Delete file — but see notes below |
| `scrolldaddy` | DELETE settings + DELETE menus only (explicitly does NOT drop `ctld_` tables) | Delete file |
| `server_manager` | DELETE settings only (tables "dropped by plugin system automatically" per comment) | Delete file |

The `ctld_`-preservation logic in `scrolldaddy/uninstall.php` was defensive coding that never actually ran in production — the plugin lives on a single site and has only ever been active. Under the new model, uninstall drops the tables. Fine.

**`email_forwarding` extras worth generalizing:**
- **Sequence drops:** the plugin explicitly drops sequences because `CASCADE` on DROP TABLE doesn't always clean them up. Step 7's generic table-drop may need the same treatment. Verify during implementation — if sequences are orphaned, extend step 7 to drop sequences matching the plugin's table-prefix pattern.
- **`plm_plugin_migrations` cleanup:** currently done per-plugin. Check whether step 5 ("Delete version/dependency records — existing") already covers this table. If not, add it.

Process per plugin:
1. Verify its settings are declared in `plugin.json` (per the settings spec — land that first).
2. Read current `uninstall.php`. If it does *only* DROP TABLE / DELETE settings / DELETE menus, delete the file.
3. If it has external work, trim to that work only. Any lingering DROP TABLE or DELETE settings/menus lines must be removed — those are now the system's job.

### Phase 5 — Docs

Update `docs/plugin_developer_guide.md`:
- **Plugin Lifecycle section** — rewrite. Four states → three. Uninstall is destructive. No more "uninstalled" status or "Permanently Delete" action.
- **"Where does each piece go?" table** — lifecycle row clarifies: *most plugins don't need `uninstall.php`. Create one only for external cleanup (API calls, filesystem, remote state).*
- **"Creating a New Plugin" checklist** — remove the mandatory `uninstall.php` step; replace with an optional note.
- **"Uninstall Script" section** — rewrite to reflect the new contract: what the automated steps do, what the hook is for, hook-failure-is-fatal semantics, example of what belongs (external API revocation) vs. what doesn't (table drops, setting deletes).

Update `docs/deploy_and_upgrade.md`:
- **Upgrade behavior** — note that upgrade refreshes *installed* plugins, not the catalog. Operators who uninstall a plugin won't see it auto-return on next upgrade.

Check any other `/docs/` file for "Permanently Delete" or "uninstalled" references and update.

## Testing

1. **Plugin with no `uninstall.php`, declared settings/menus, has tables** — uninstall removes settings and menu rows, drops tables, deletes `plg_plugins` row. No errors.
2. **Plugin with hand-written `uninstall.php` doing only canonical cleanup** — delete the file, re-run uninstall, identical end state.
3. **Plugin with external cleanup in the hook** — hook runs between step 5 and step 7. Verify hook can still query the plugin's own tables. Verify external call is made.
4. **Hook failure** — throw in the hook. Confirm: scaffolding cleanup (1–5) committed, hook error logged, table drop (7) did NOT run, `plg_plugins` row still exists. Re-run uninstall; completes cleanly (1–5 are idempotent, hook now works, 7–8 run).
5. **Reinstall after uninstall** — plugin reinstalls as fresh install (no tombstone, no preserved data).
6. **Orphan settings** — manually `INSERT` a non-declared setting `bookings_rogue`, uninstall bookings, confirm `bookings_rogue` remains (wasn't declared, correctly left alone).
7. **Admin UI** — single destructive Uninstall button with warning. No Permanently Delete. No "uninstalled" state rendering.
8. **Upgrade after uninstall** — uninstall a stock plugin. Run upgrade. Confirm the plugin is NOT re-downloaded and NOT re-installed. The operator's removal sticks.
9. **Upgrade of installed stock plugin** — normal case. Plugin gets a fresh archive, files on disk updated, no disruption to runtime.
10. **Upgrade with a custom plugin installed** — confirm the download loop attempts it, gets a 404 from the upgrade endpoint, warns and continues without failing the upgrade.
11. **Install a stock plugin with stale on-disk files** — modify a file in `plugins/{name}/` to differ from the upstream archive. Uninstall, then reinstall. Confirm the modified file is overwritten with the upstream copy (fresh archive was fetched and extracted).
12. **Install a custom plugin** — confirm a fetch *is* attempted, endpoint 404s silently, and install falls through to on-disk files without warning.
13. **Install a stock plugin when the upgrade endpoint is unreachable** — simulate endpoint down (connection refused / timeout, not a 404). Install proceeds with on-disk files and logs a warning. Install completes successfully.

## Out of Scope

- Introducing an `is_system` flag for plugins that must always be installed. No current plugin needs this.
- Plugin file removal from disk as an admin action. If an operator wants `plugins/{name}/` gone, they can `rm` it manually.
- Table discovery via reflection instead of regex. Existing regex approach works.
- Core-code uninstall (core doesn't get uninstalled).
- A "force uninstall" that skips the hook on failure. Add if a real need emerges.

## Dependencies

**Depends on `declarative_plugin_settings` spec.** That spec introduces the `settings` key in `plugin.json` and the `PluginHelper::getDeclaredSettings()` accessor that `deleteDeclaredSettings()` calls. Land the settings spec first.

**Orphan cost of manifest-reading:** a plugin that removes a setting from its manifest between versions and is later uninstalled leaves the removed setting's row in `stg_settings`. The system has no record that it ever existed. Matches the orphan-tolerant stance in the settings spec.

## Implementation Notes

Recording concrete decisions and divergences from the plan above, for future readers.

### Phase 1 step 1 used the existing `Setting::unseed_declared()` helper

The spec proposed writing a new `PluginManager::deleteDeclaredSettings()` with a raw `DELETE ... WHERE stg_name IN (...)` query. The settings spec had already shipped a `Setting::unseed_declared($declarations)` helper with the exact same semantics, and `PluginManager::uninstall()` was already calling it — we kept the existing wiring rather than introducing a parallel method.

### Phases 1 and 3 shipped together

Phase 1 step 4 deletes `Plugin::permanent_delete_with_files()`, but that method's only caller is the admin UI's "Permanently Delete" action, which Phase 3 removes. Landing Phase 1 alone would break the admin page in the intermediate state. The two phases ship as a single unit.

### Step 7 drops orphan sequences too

The spec flagged this as "verify during implementation": Joinery's `SERIAL` columns create sequences that are **not** registered as column-owned in `pg_depend`, so `DROP TABLE ... CASCADE` leaves them behind. Verified on `bkn_bookings_bkn_booking_id_seq` and the `efl_* / efa_* / efd_*` sequences. Step 7 now enumerates sequences matching `{tablename}\_%` via `pg_class` and drops each one explicitly. `cleanup_uninstalled_plugins.php` mirrors the same logic so the legacy-row migration cleans up orphans it would otherwise leave.

### Plugin migration records are cleaned in step 5

`plm_plugin_migrations` cleanup was previously done per-plugin (visible in `email_forwarding/uninstall.php` before deletion). The spec asked to verify whether the existing "version/dependency records" step covered it — it didn't. Added a third `MultiPluginMigration` sweep to step 5 so the hook and the post-hook table-drop both see a clean slate.

### `refreshFromUpstream()` extracts with `PharData`, not shell `tar`

`upgrade.php`'s bulk extraction uses `exec('tar -xzf ...')`, and the natural choice for `refreshFromUpstream()` was the same. But shell `tar` tries to restore archive directory modes on existing directories and fails with `Operation not permitted` in mixed-ownership environments (PHP process running as `user1` against `www-data`-owned plugin dirs). `--no-overwrite-dir`, `--no-same-permissions`, and `--touch` didn't suppress the chmod attempts. Switched to PHP-native `PharData::extractTo($root, null, $overwrite=true)`, which uses PHP file operations and leaves existing directory metadata alone. File content replacement is unchanged.

Minor wrinkle: `PharData` requires a recognized archive extension on the file path, so the download writes to `tempnam() . '.tar.gz'` rather than using `tempnam()` directly.

Open question worth revisiting later: `upgrade.php` still uses shell `tar` for its bulk extraction. If we want both paths to use the same extractor we could move them both onto `PharData`, but the use cases differ enough that the divergence is defensible for now.

### `is_stock` keeps structural roles outside uninstall/upgrade-discovery

The spec narrowed the flag's role to "the upgrade endpoint publishes this plugin." That's accurate for the two paths this spec touched (upgrade-time discovery, install-time refresh), but three other subsystems still consult it and must continue to:

- `publish_upgrade.php` skips `is_stock: false` plugins/themes when building distribution archives.
- `DeploymentHelper` / `deploy.sh` preserve `is_stock: false` plugins across the deploy swap (otherwise custom plugins would be wiped).
- `_reconcile_stock_assets.sh` uses `plg_is_stock = true` to decide what to re-download on container boot.

Docs and inline comments were adjusted to reflect that `is_stock`'s role contracted, not disappeared.

### Legacy-menu conversion for `bookings`

Out of scope of this spec but a blocker for a clean uninstall: `bookings` still had three migration-seeded admin menu rows (`bookings-parent`, `bookings`, `booking-types`) that weren't declared in its `plugin.json`. Under the new destructive uninstall, `syncAdminMenus($name, [])` would have left them orphaned. Added a nested `adminMenu` block to `plugins/bookings/plugin.json`; `syncAdminMenus` now tracks their slugs in `plg_metadata._menu_slugs` and cleans them on uninstall. `email_forwarding` and `server_manager` were already declarative; `items` and `scrolldaddy` have no admin menus to declare.

The old migration that originally seeded bookings' menus is now redundant (its `test` SQL skips when the rows exist, and activate's declarative sync upserts the same rows regardless). Left in place — touching old migrations is higher-risk than the payoff justifies.

### `plg_uninstalled_time` column removal required `--cleanup`

The spec stated that removing `plg_uninstalled_time` from `$field_specifications` would cause `update_database` to drop the column on its next run. Verified that the column-drop pass (`processColumnCleanup()`) only runs when `update_database` is invoked with `--cleanup` or `--upgrade` — the default run doesn't touch obsolete columns. One-time `php utils/update_database.php --cleanup` dropped the column on this site; production sites pick it up on their next deploy (upgrade.php runs `update_database` in upgrade mode, which enables cleanup).
