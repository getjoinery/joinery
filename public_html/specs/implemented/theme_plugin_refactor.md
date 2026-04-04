---
title: Extension Lifecycle Hardening (Themes & Plugins)
status: completed
priority: high
---

# Extension Lifecycle Hardening (Themes & Plugins)

## Implementation Strategy

Work through the files in this order to minimize the time the system is in a mixed state:

1. **Schema additions** — `thm_install_error`, `sct_plugin_name` fields + backfill migration. Run update_database to create columns.
2. **AbstractExtensionManager base changes** — `activate()`, `deactivate()`, `afterActivate()`, abstract hooks, ghost detection in `sync()`, concrete `loadMetadataIntoModel()`. Syntax-check and validate.
3. **ThemeManager + theme model + theme admin** — `onActivate()`, `afterActivate()`, `onDeactivate()`. Remove `Theme::activate()`. Add `get_all_themes_with_status()`. Update admin_themes_logic callers. Update AJAX theme switcher. Update migration script.
4. **PluginManager + plugin model + plugin admin** — `onActivate()`, `onDeactivate()`, `install()`, `uninstall()`, `permanentDeleteTables()`. Remove lifecycle methods from Plugin model. Update `permanent_delete_with_files()`. Update admin_plugins_logic callers. Remove dead code from PluginHelper.
5. **update_database fixes** — Advisory lock, stop migrations on first failure.
6. **UI cleanup + docs** — Remove sync buttons and handlers. Update admin_scheduled_tasks_logic for `sct_plugin_name`. Update all five documentation files.

Test each step before moving to the next. The system should remain functional after each step completes.

### Implementation Notes

These details were verified during pre-implementation analysis and must be handled correctly:

- **`loadMetadataIntoModel()` is currently abstract** on AbstractExtensionManager. Making it concrete is safe. Both ThemeManager and PluginManager currently implement it independently with no `parent::` call. After making the base concrete, both subclasses must be updated to call `parent::loadMetadataIntoModel()` first, then set their type-specific fields.

- **`repair_plugin` action in admin_plugins_logic.php** (lines 193-214) calls `Plugin::install()` directly. This must be updated to `$plugin_manager->install($plugin_name)` alongside the other install/activate/deactivate/uninstall callers. The repair flow also clears `plg_install_error` and resets `plg_status` before calling install — this pre-cleanup should remain.

- **MultiPlugin and MultiTheme use different filter keys for active status.** MultiPlugin uses `['plg_active' => 1]` (int). MultiTheme uses `['thm_is_active' => true]` (bool via `PDO::PARAM_BOOL`). The ghost detection code in `sync()` cannot use a generic filter in the base class. Instead, each subclass should override a method like `getActiveExtensions()` that returns the correct query, or the base `sync()` should call through the subclass's existing Multi class with the correct options. Simplest approach: add `getActiveFilterOptions()` as an abstract method that returns the correct array for each type.

---

## Problem

The theme and plugin lifecycle systems have fragility points that can leave the system in broken or inconsistent states, lifecycle logic is scattered across Models, Helpers, and Managers, and several lifecycle stages have missing or incorrect behavior.

### Fragility Issues

1. **Non-atomic multi-step operations** — Theme activation writes to three places with no transaction.
2. **No concurrency protection on update_database** — Two simultaneous runs can execute the same migrations twice.
3. **Silent failures** — Broken manifests silently skipped. AJAX theme switcher bypasses activation.
4. **Plugin activation bug** — `Plugin::activate()` (used in production) never runs activate.php hooks and never registers deletion rules. `PluginHelper::activate()` (which does those things) has zero callers. This means activate.php hooks never run and deletion rules are never registered on activation.
5. **Scheduled tasks survive deactivation** — Tasks keep running against disabled plugin code.
6. **Schema changes not picked up on activate** — Plugin tables are deliberately excluded from `update_database` (correct — core can't know about plugins at compile time). But if a developer modifies `field_specifications` on an already-installed plugin, the only path is a non-obvious "Sync with Filesystem" button. Activation should handle this automatically.
7. **Permanent delete leaves orphaned tables** — Plugin tables with potentially millions of rows remain in the database forever after permanent deletion.
8. **Ghost active extensions** — If a plugin/theme directory is deleted while marked active, sync() doesn't deactivate it. Runtime errors follow.
9. **No plugin ownership on scheduled tasks** — Tasks linked to plugins only by fragile filesystem glob. Renaming a JSON file orphans the task record.
10. **Theme admin only queries database** — Unlike the plugin admin (which scans filesystem), the theme admin page only loads from `MultiTheme`. New theme directories placed on the filesystem don't appear until something creates a database record.

### Architecture Issues

11. **Lifecycle logic in the wrong places** — Activate/deactivate lives on Models and Helpers instead of the Manager layer where install/sync already live.
12. **Two parallel incomplete activation implementations** — Neither `Plugin::activate()` nor `PluginHelper::activate()` does everything needed.

---

## Architecture: Keep the Split, Complete the Base

Keep all 6 classes. Keep the Manager/Helper split (intentionally designed — Managers for lifecycle, Helpers for runtime queries). Add the missing lifecycle operations to AbstractExtensionManager where they belong.

```
ComponentBase (unchanged — runtime)    AbstractExtensionManager (gains activate/deactivate)
├── ThemeHelper (unchanged, read-only) ├── ThemeManager (gains onActivate hook)
└── PluginHelper (cleanup dead code)   └── PluginManager (gains full lifecycle)

Theme model — remove activate(), pure CRUD
Plugin model — remove lifecycle methods, pure CRUD
```

---

## Policy Decisions

| Decision | Policy | Rationale |
|----------|--------|-----------|
| Uninstall: keep plugin tables? | **Yes** | Allows reinstall without data loss. Uninstall = "disable." |
| Permanent delete: drop plugin tables? | **Yes** | Full cleanup. No FK constraints from core→plugin. |
| Permanent delete: clean up migration records? | **Yes** | Full cleanup. Allows clean re-install after re-upload. |
| Uninstall: clean up dependency records? | **Yes** | Stale records confuse dependency validation. |
| Deletion rule registration failure: fatal? | **No** | Log and continue. Better than blocking activation. |
| Ghost active extensions: auto-deactivate on sync? | **Yes** | Active extension with no directory causes runtime errors. |
| Ghost inactive extensions: auto-remove on sync? | **No** | Show "Missing" in UI, let admin decide. |
| update_database: include plugin tables? | **No** | Deliberately excluded — core can't know plugins at compile time. |
| Activate: run table updates? | **Yes** | `onActivate()` calls `runPluginTablesOnly()`. Natural gate for schema changes. |
| "Sync with Filesystem" button | **Remove from UI** | All lifecycle paths handle their own state. CLI available for troubleshooting. |
| Scheduled task ownership: add `sct_plugin_name`? | **Yes** | Explicit ownership enables reliable suspend/resume/delete. |
| Theme deactivation: supported? | **No** | Always exactly one active theme. Switch, don't deactivate. |
| Theme admin listing: scan filesystem? | **Yes** | Match plugin admin pattern. Show unregistered themes from filesystem. |
| Component sync: in transaction or after? | **After** | Non-critical to atomicity. Runs in `afterActivate()` hook, non-fatal. |

---

## Complete Plugin Lifecycle

### Stage 1: Discovery

**Trigger:** Admin visits plugin admin page (filesystem scan in listing), or `upgrade.php` runs.

**Flow:**
The plugin admin listing (`MultiPlugin::get_all_plugins_with_status()`) already scans the filesystem and merges with database records. New plugin directories show up automatically with metadata from plugin.json, even without a database record. The "Install" action creates the record.

`sync()` (called by `upgrade.php`) additionally:
- Creates `plg_plugins` records for new directories
- Refreshes metadata for existing records
- **Auto-deactivates ghost plugins** — active in DB but directory missing (NEW)
- Runs `runPluginTablesOnly()` for all active plugins
- Registers deletion rules for all active plugins

**Edge cases:**
- **Broken plugin.json** → `loadMetadataIntoModel()` writes warning to `plg_install_error`, visible in admin UI
- **Directory deleted while plugin active** → Auto-deactivated by sync with logged warning
- **Directory deleted while plugin inactive** → Shows as "Missing" in admin UI, manual cleanup available

### Stage 2: Install

**Trigger:** Admin clicks "Install".

**Flow (via `PluginManager::install($name)`):**
1. Begin transaction
2. Validate plugin name and directory exist
3. `validatePlugin($name)` — PHP version, extensions, dependencies, conflicts
4. Store dependencies in `pld_plugin_dependencies`
5. `DatabaseUpdater::runPluginTablesOnly($name)` — creates all plugin tables
6. `runPendingMigrations($name)` — executes migrations
7. Update record: `plg_status = 'inactive'`, `plg_installed_time = NOW()`
8. Commit (or rollback on failure — PostgreSQL DDL is transactional)

**Edge cases:**
- **Migration fails** → Entire install rolls back including table creation. `plg_status = 'error'`, `plg_install_error` = message.
- **Re-install after uninstall** → Tables still exist. `runPluginTablesOnly()` adds new columns. Already-applied migrations skipped.
- **Re-install after permanent delete** → Clean slate. All tables recreated, all migrations re-run.

### Stage 3: Activate

**Trigger:** Admin clicks "Activate".

**Flow (via `PluginManager::activate($name)` → `AbstractExtensionManager::activate()`):**
1. Begin transaction (with `inTransaction()` guard)
2. Load Plugin model from database
3. Call `PluginManager::onActivate($name, $model, $dblink)`:
   a. `validatePlugin($name)` — re-checks dependencies
   b. `DatabaseUpdater::runPluginTablesOnly($name)` — picks up schema changes since install
   c. Run `activate.php` hook if it exists
   d. Register deletion rules (non-fatal on failure, logged)
   e. Set `plg_active = 1`, `plg_activated_time`, `plg_last_activated_time`
   f. Resume scheduled tasks by `sct_plugin_name`
4. Base method sets `plg_status = 'active'`, calls `$model->save()`
5. Commit transaction

**Edge cases:**
- **activate.php throws** → Transaction rolls back. Plugin stays inactive.
- **Developer changed field_specifications** → `runPluginTablesOnly()` picks up new columns. Deactivate → activate is the workflow.
- **Task resume before tasks exist** → Query returns empty. No error.

### Stage 4: Runtime

**Every request:** PathHelper loads Helpers at boot. Plugin routes registered via `PluginHelper::initialize()`. Scheduled tasks run via cron, filtered by `sct_is_active = true`.

**Schema changes during development:** Modify `field_specifications` → deactivate → activate picks up the change automatically. For deployment, `upgrade.php` handles it via sync.

### Stage 5: Deactivate

**Flow (via `PluginManager::deactivate($name)` → `AbstractExtensionManager::deactivate()`):**
1. Begin transaction
2. Load Plugin model
3. Call `PluginManager::onDeactivate($name, $model, $dblink)`:
   a. Check if active theme provider → block with clear error
   b. Check dependents → block if other plugins need this one
   c. Run `deactivate.php` hook if it exists
   d. Remove deletion rules
   e. Set `plg_active = 0`, `plg_activated_time = null`, `plg_last_deactivated_time`
   f. Suspend scheduled tasks by `sct_plugin_name` (`sct_is_active = false`)
4. Base method sets `plg_status = 'inactive'`, calls `$model->save()`
5. Commit transaction

### Stage 6: Uninstall

**Flow (via `PluginManager::uninstall($name)`):**
1. Check plugin is not active (must deactivate first)
2. Check no dependents
3. Run `uninstall.php` hook + `{name}_uninstall()` function
4. Remove deletion rules
5. Delete scheduled task records (by `sct_plugin_name`)
6. Delete version tracking records (`plv_plugin_versions`)
7. Delete dependency records (`pld_plugin_dependencies`)
8. Set `plg_status = 'uninstalled'`, `plg_active = 0`

**Preserved:** Plugin database tables, data, migration records, plugin files on disk.

### Stage 7: Permanent Delete

**Flow (via `Plugin::permanent_delete_with_files()`):**
1. If not already uninstalled → calls `PluginManager::uninstall()` first
2. Check directory is deletable
3. **Drop all plugin database tables** via `PluginManager::permanentDeleteTables()` — BEFORE deleting files (needs class definitions to discover table names)
4. **Delete migration records** (`plm_plugin_migrations`)
5. Delete plugin directory from filesystem
6. Delete `plg_plugins` database record

**Table discovery uses regex** — reads each `*_class.php` file and extracts `$tablename` via pattern match. Does not require loading the class (avoids issues with broken syntax):

```php
$content = file_get_contents($model_file);
if (preg_match('/\$tablename\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
    $tablename = $matches[1];
    $dblink->exec("DROP TABLE IF EXISTS " . $tablename . " CASCADE");
}
```

**Safety:** No DB-level foreign keys from core→plugin tables. `CASCADE` handles inter-plugin constraints.

### Stage 8: Repair

**Trigger:** Admin clicks "Repair" on a plugin with `plg_status = 'error'`.

**Flow:** Clears error state, re-runs `PluginManager::install($name)`.

---

## Complete Theme Lifecycle

### Admin Listing

**Current problem:** Theme admin page loads only from `MultiTheme` (database). New filesystem themes are invisible until synced.

**Fix:** Add `Theme::get_all_themes_with_status()` (matching `MultiPlugin::get_all_plugins_with_status()` pattern). Scans `/theme/*/` directories, merges with database records. Unregistered themes show with metadata from theme.json and available actions.

### Activation

**Flow (via `ThemeManager::activate($name)` → `AbstractExtensionManager::activate()`):**
1. Begin transaction
2. Load Theme model (if not in database, run sync for this theme first)
3. Call `ThemeManager::onActivate($name, $model, $dblink)`:
   a. Validate theme classes (publicPageBase, formWriterBase files exist)
   b. Deactivate all other themes: `UPDATE thm_themes SET thm_is_active = false, thm_status = 'installed'`
   c. Set `thm_is_active = true` on this theme
   d. Update or create `theme_template` setting
4. Base method sets `thm_status = 'active'`, calls `$model->save()`
5. Commit transaction
6. `afterActivate()` hook runs `syncComponentTypes()` — post-commit, non-fatal, logged on failure

**Deactivation:** Not supported directly. `onDeactivate()` throws: "Activate a different theme instead."

---

## Changes to AbstractExtensionManager

### New: `activate($name)` — transaction-wrapped

```php
public function activate($name) {
    $dbconnector = DbConnector::get_instance();
    $dblink = $dbconnector->get_db_link();
    
    $this_transaction = false;
    if (!$dblink->inTransaction()) {
        $dblink->beginTransaction();
        $this_transaction = true;
    }
    
    try {
        $model = $this->getExistingByName($name);
        if (!$model) {
            throw new Exception(ucfirst($this->extension_type) . " '$name' not found in database.");
        }
        
        $this->onActivate($name, $model, $dblink);
        
        $model->set($this->table_prefix . '_status', 'active');
        $model->save();
        
        if ($this_transaction) {
            $dblink->commit();
        }
    } catch (Exception $e) {
        if ($this_transaction) {
            $dblink->rollBack();
        }
        throw $e;
    }
    
    // Post-commit hook (non-transactional, non-fatal)
    try {
        $this->afterActivate($name, $model);
    } catch (Exception $e) {
        error_log(ucfirst($this->extension_type) . " post-activation task failed for '$name': " . $e->getMessage());
    }
}
```

### New: `deactivate($name)` — same transaction pattern, no afterDeactivate needed

### Updated: `sync()` — auto-deactivate ghosts

After the existing filesystem scan loop, query active records and deactivate any whose directory is missing. Log a warning for each.

### Updated: `loadMetadataIntoModel()` — concrete base with error logging

Change from abstract to concrete with shared manifest validation. Returns `true` on success (subclass continues loading type-specific fields) or `false` on failure (sets `_install_error` on model). Subclasses call `parent::loadMetadataIntoModel()` first.

### New abstract methods

```php
abstract protected function onActivate($name, $model, $dblink);
abstract protected function onDeactivate($name, $model, $dblink);
abstract protected function getActiveFilterOptions(); // Returns Multi filter array for active extensions
protected function afterActivate($name, $model) {} // Default no-op, overridden by ThemeManager
```

ThemeManager implements `getActiveFilterOptions()` returning `['thm_is_active' => true]`.
PluginManager implements it returning `['plg_active' => 1]`.
Used by `sync()` ghost detection to query active extensions with the correct filter.

---

## Changes to ThemeManager

- **`onActivate()`** — Validates theme classes, deactivates all other themes, sets `thm_is_active`, updates `theme_template` setting.
- **`afterActivate()`** — Calls `syncComponentTypes()`. Non-fatal, logged on failure.
- **`onDeactivate()`** — Throws exception. Themes deactivate by activating a different theme.
- **`loadMetadataIntoModel()`** — Calls parent, then sets `thm_display_name`, `thm_description`, `thm_version`, `thm_author`, `thm_is_stock`, `thm_is_system`.

---

## Changes to PluginManager

- **`onActivate()`** — Validates dependencies, runs `runPluginTablesOnly()`, runs activate.php hook, registers deletion rules (non-fatal), sets `plg_active`, timestamps, resumes tasks by `sct_plugin_name`.
- **`onDeactivate()`** — Checks theme provider, checks dependents, runs deactivate.php hook, removes deletion rules, sets `plg_active = 0`, timestamps, suspends tasks by `sct_plugin_name`.
- **`install($name)`** — Transaction-wrapped. Validates, creates tables, runs migrations, updates record. Moved from Plugin model.
- **`uninstall($name)`** — Checks inactive, checks dependents, runs uninstall.php, removes deletion rules, deletes tasks/versions/dependencies, sets status=uninstalled. Moved from Plugin model.
- **`permanentDeleteTables($name)`** — Reads `*_class.php` files via regex to extract table names. Drops each with `CASCADE`. Deletes `plm_plugin_migrations` records. Called by `Plugin::permanent_delete_with_files()` BEFORE files are deleted.
- **`loadMetadataIntoModel()`** — Calls parent, then sets `plg_is_stock`, `plg_installed_time`.

---

## Changes to PluginHelper

Remove dead `activate()` and `deactivate()` methods (zero external callers). Everything else unchanged.

---

## Changes to Models

### Theme model (`data/themes_class.php`)

- **Remove:** `activate()` method
- **Add field:** `'thm_install_error' => array('type' => 'text', 'is_nullable' => true)`
- **Add:** `get_all_themes_with_status()` static method — scans `/theme/*/` directories, merges with database records (matching the existing `MultiPlugin::get_all_plugins_with_status()` pattern)

### Plugin model (`data/plugins_class.php`)

- **Remove:** `activate()`, `deactivate()`, `install()`, `uninstall()`, `is_plugin_active()`, `$activation_cache`, `clear_activation_cache()`
- **Modify `permanent_delete_with_files()`** to call `PluginManager::uninstall()` and `PluginManager::permanentDeleteTables()` instead of `$this->uninstall()`
- **Keep:** `is_active()`, `get_by_plugin_name()`, `get_status_badge()`, `prepare()`, `get_all_plugins_with_status()`

### ScheduledTask model (`data/scheduled_tasks_class.php`)

- **Add field:** `'sct_plugin_name' => array('type' => 'varchar(100)', 'is_nullable' => true)`
- **Add MultiScheduledTask filter:** `'plugin_name'` option mapping to `sct_plugin_name`

---

## Schema Changes

### New fields (auto-created by update_database)

| Model | Field | Type | Purpose |
|-------|-------|------|---------|
| Theme | `thm_install_error` | text, nullable | Store manifest/validation warnings |
| ScheduledTask | `sct_plugin_name` | varchar(100), nullable | Track owning plugin |

### Migration: backfill `sct_plugin_name`

Scans `plugins/*/tasks/*.json`, matches each JSON filename to `sct_task_class` in the database, sets `sct_plugin_name` accordingly.

### Task creation update

When tasks are activated via `admin_scheduled_tasks_logic.php`, set `sct_plugin_name` from the discovered `source` field (which already contains `'plugin:' . $plugin_name`).

---

## Standalone Fixes

### Advisory lock on update_database

Add `pg_try_advisory_lock(99999)` near the top. Non-blocking — second caller gets "already running" immediately. Lock auto-released on connection close.

### Stop migrations on first failure

Change migration failure handling to `break`. Matches the comment at top of `migrations/migrations.php` which says "IT BAILS ON ERROR" but the code currently contradicts.

### AJAX theme switcher

Replace `MultiSetting` manipulation in `ajax/theme_switch_ajax.php` with `ThemeManager::getInstance()->activate($theme)`.

---

## Admin UI Changes

### Remove "Sync with Filesystem" buttons

- `adm/admin_plugins.php` line 30: remove `$altlinks['Sync with Filesystem']`
- `adm/admin_themes.php` line 24: remove `$altlinks['Sync with Filesystem']`
- `adm/logic/admin_plugins_logic.php` lines 62-87: remove `action === 'sync'` handler
- `adm/logic/admin_themes_logic.php` lines 70-96: remove `case 'sync'` handler

All lifecycle paths now handle their own sync automatically. For troubleshooting, sync is available via CLI.

### Update theme admin listing

`adm/logic/admin_themes_logic.php`: Replace `MultiTheme` query with `Theme::get_all_themes_with_status()` (filesystem + database merge).

---

## Files Modified

| File | Changes |
|------|---------|
| `includes/AbstractExtensionManager.php` | Add `activate()`, `deactivate()`, `afterActivate()`, abstract hooks, ghost detection in `sync()`, concrete `loadMetadataIntoModel()` |
| `includes/ThemeManager.php` | Add `onActivate()`, `onDeactivate()`, `afterActivate()`, update `loadMetadataIntoModel()` |
| `includes/PluginManager.php` | Add `onActivate()`, `onDeactivate()`, `install()`, `uninstall()`, `permanentDeleteTables()`, update `loadMetadataIntoModel()` |
| `includes/PluginHelper.php` | Remove dead `activate()`, `deactivate()` |
| `data/themes_class.php` | Remove `activate()`, add `thm_install_error` field, add `get_all_themes_with_status()` |
| `data/plugins_class.php` | Remove lifecycle methods, update `permanent_delete_with_files()` |
| `data/scheduled_tasks_class.php` | Add `sct_plugin_name` field and MultiScheduledTask filter |
| `adm/admin_themes.php` | Remove "Sync with Filesystem" altlink |
| `adm/admin_plugins.php` | Remove "Sync with Filesystem" altlink |
| `adm/logic/admin_themes_logic.php` | Remove sync handler. Use `$theme_manager->activate($name)`. Use `Theme::get_all_themes_with_status()`. |
| `adm/logic/admin_plugins_logic.php` | Remove sync handler. Use `$plugin_manager->method($name)` for all lifecycle calls including `repair_plugin` action (lines 193-214) which currently calls `Plugin::install()` directly. |
| `adm/logic/admin_scheduled_tasks_logic.php` | Set `sct_plugin_name` when creating tasks from plugin discovery |
| `ajax/theme_switch_ajax.php` | Replace MultiSetting manipulation with `ThemeManager::activate()` |
| `migrations/theme_plugin_registry_sync.php` | Use `$theme_manager->activate()` |
| `utils/update_database.php` | Add advisory lock, stop migrations on first failure |

No files created. No files deleted.

---

## Testing Plan

- Activate/deactivate themes via admin UI and AJAX. Verify `thm_is_active` and `theme_template` always agree. Test with invalid publicPageBase — clear error. Test rollback on failure.
- Full plugin lifecycle via admin UI: install → activate → deactivate → uninstall → permanent delete. Verify:
  - activate.php / deactivate.php hooks run
  - Scheduled tasks suspend on deactivate, resume on activate, delete on uninstall
  - Dependency checks block invalid operations
  - Active theme provider deactivation is blocked
  - Permanent delete drops plugin tables and cleans up migration records
  - Re-install after permanent delete works (clean slate)
  - Add a column to a plugin data class, deactivate → activate, verify column exists in database
- Two simultaneous update_database — second gets "already running."
- Failing migration stops subsequent ones.
- Delete a plugin directory manually, trigger sync via upgrade.php — verify auto-deactivated.
- Corrupt a plugin.json, trigger sync — verify error in admin UI.
- New theme directory placed on filesystem — verify it appears in theme admin page.
- Backfill migration correctly populates `sct_plugin_name` for existing tasks.

---

## Documentation Changes

### `docs/plugin_developer_guide.md`

Rewrite the lifecycle section:

- **Plugin lifecycle state diagram** — Discovery → Install → Activate ↔ Deactivate → Uninstall → Permanent Delete
- **PluginManager is the single entry point** for all lifecycle operations. Plugin models are pure CRUD.
- **Table update workflow** — Tables created on install, updated on activate. `update_database.php` deliberately excludes plugins (core can't know about plugins at compile time). Developer workflow for schema changes: modify `field_specifications` → deactivate → activate.
- **activate.php / deactivate.php hooks** are guaranteed to run on every activate/deactivate.
- **Scheduled task behavior** — Suspended on deactivation (`sct_is_active = false`), deleted on uninstall. Tasks track owning plugin via `sct_plugin_name`.
- **Uninstall vs permanent delete** — Uninstall preserves tables/data (allows reinstall). Permanent delete drops tables, cleans up migration records, removes files.
- **"Sync with Filesystem" removed from admin UI** — All lifecycle paths sync automatically. For troubleshooting via CLI: `php -r "require_once('includes/PathHelper.php'); require_once(PathHelper::getIncludePath('includes/PluginManager.php')); (new PluginManager())->syncWithFilesystem();"`

### `docs/deletion_system.md`

- Line 143: Change `PluginManager->syncWithFilesystem()` reference to explain deletion rules are registered during `PluginManager::activate()` (via `onActivate()`).
- Line 259: Replace "Run Sync with Filesystem from admin plugins page" with: "Deactivate and re-activate the plugin, or run `PluginHelper::registerAllActiveDeletionRules()` from CLI."

### `docs/deploy_and_upgrade.md`

- **Advisory lock on update_database** — Concurrent runs rejected with "already running." Uses `pg_try_advisory_lock()`, auto-released on connection close.
- **Migration halt-on-failure** — Migrations stop on first failure. Fix the issue and re-run.
- **Plugin sync during upgrade** — `upgrade.php` calls sync which handles ghost detection, metadata refresh, and table updates for active plugins.

### `docs/scheduled_tasks.md`

- **Plugin ownership** — Tasks track owning plugin via `sct_plugin_name`. Set when tasks are activated from plugin JSON definitions.
- **Lifecycle behavior** — Suspended when plugin deactivated, resumed on reactivate, permanently deleted on uninstall.

### `CLAUDE.md`

Under "Development Workflow > Adding New Features":
- Plugin lifecycle operations go through PluginManager, not the Plugin model
- Schema changes to plugin data classes take effect on next activate (not update_database)
