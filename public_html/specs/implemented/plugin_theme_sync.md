# Spec: Plugin & Theme Sync — Comprehensive Maintenance Action

**Status:** Pending implementation  
**Affects:** PluginManager, ThemeManager, admin UI, upgrade pipeline

---

## Problem

When plugin code changes between deployments (e.g. new columns in `$field_specifications`, new data classes, updated deletion rules), the production database is not updated. This is because:

1. `update_database` deliberately excludes plugin tables (plugins have independent lifecycles).
2. `upgrade.php` calls `PluginManager::sync()` (base class — filesystem scan only) instead of `syncWithFilesystem()` (which also updates tables and deletion rules).
3. The only way to trigger plugin table updates is deactivate → activate, which has side effects (deletes scheduled tasks, deletion rules, version tracking).
4. There is no admin UI action to trigger a sync.

**Real-world impact:** A `sdd_log_queries` column was added to the ScrollDaddy plugin's `$field_specifications` and deployed to production. Because the column was never created in the database, any `save()` call on a device failed, causing a 404 on the device delete page.

---

## Design

### Core idea

`PluginManager` already has `syncWithFilesystem()` which does everything needed. The problem is that nobody calls it. Rather than maintaining two public methods (`sync()` and `syncWithFilesystem()`), **override `sync()` in PluginManager** to be the comprehensive method. This way every existing caller — `upgrade.php`, `postInstall()`, marketplace install — automatically gets the full behavior.

`ThemeManager::sync()` is already comprehensive (filesystem scan + active status + component types). No changes needed there.

### What PluginManager::sync() will do (after this change)

1. **Filesystem scan** — discover new plugins, update metadata from manifests, ghost-detect missing directories (inherited from `parent::sync()`)
2. **Update tables for all active plugins** — call `DatabaseUpdater::runPluginTablesOnly()` per active plugin (adds missing columns, creates new tables)
3. **Run pending migrations for all active plugins** — execute unapplied migrations from each active plugin's `/migrations/` directory
4. **Register deletion rules** — call `PluginHelper::registerAllActiveDeletionRules()` to ensure rules match current code

### What gets removed

- `PluginManager::syncWithFilesystem()` — its body moves into `sync()`, then the method is deleted
- The one external caller (`migrations/theme_plugin_registry_sync.php` line 57) is updated to call `sync()` instead

---

## Changes

### 1. PluginManager::sync() override

**File:** `includes/PluginManager.php`

Move the body of `syncWithFilesystem()` into a new `sync()` override. Add pending migration execution for active plugins.

```php
public function sync() {
    $result = parent::sync();

    // Update database tables for all active plugins
    require_once(PathHelper::getIncludePath('includes/DatabaseUpdater.php'));
    require_once(PathHelper::getIncludePath('data/plugins_class.php'));
    $database_updater = new DatabaseUpdater();
    $active_plugins = new MultiPlugin(['plg_active' => 1]);
    $active_plugins->load();
    $table_messages = [];
    $migration_messages = [];
    foreach ($active_plugins as $plugin) {
        $plugin_name = $plugin->get('plg_name');

        // Update tables (adds missing columns, creates new tables)
        $table_result = $database_updater->runPluginTablesOnly($plugin_name);
        if (!empty($table_result['messages'])) {
            $table_messages = array_merge($table_messages, $table_result['messages']);
        }

        // Run pending migrations
        try {
            $migration_results = $this->runPendingMigrations($plugin_name);
            foreach ($migration_results as $m) {
                if (!empty($m['error'])) {
                    $migration_messages[] = "$plugin_name: " . $m['error'];
                } elseif (!empty($m['id'])) {
                    $migration_messages[] = "$plugin_name: applied " . $m['id'];
                }
            }
        } catch (Exception $e) {
            $migration_messages[] = "$plugin_name: migration error — " . $e->getMessage();
        }
    }
    $result['table_messages'] = $table_messages;
    $result['migration_messages'] = $migration_messages;

    // Register deletion rules for ALL active plugins
    require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
    PluginHelper::registerAllActiveDeletionRules();

    return $result;
}
```

Delete `syncWithFilesystem()` after moving its logic.

### 2. Update migration caller

**File:** `migrations/theme_plugin_registry_sync.php`

Change line 57 from:
```php
$synced_plugins = $plugin_manager->syncWithFilesystem();
```
to:
```php
$synced_plugins = $plugin_manager->sync();
```

### 3. upgrade.php — report table and migration messages

**File:** `utils/upgrade.php`

No method call changes needed (it already calls `$plugin_manager->sync()`). But update the result reporting to include `table_messages` and `migration_messages` from the enhanced return value:

```php
// After existing plugin sync reporting...
if (!empty($plugin_result['table_messages'])) {
    upgrade_echo("  Table updates: " . implode(", ", $plugin_result['table_messages']) . "<br>");
}
if (!empty($plugin_result['migration_messages'])) {
    foreach ($plugin_result['migration_messages'] as $mm) {
        upgrade_echo("  Migration: " . $mm . "<br>");
    }
}
```

### 4. Admin "Sync" action — Plugins page

**File:** `adm/admin_plugins.php`

Add "Sync with Filesystem" to the Options dropdown:

```php
$altlinks['Sync with Filesystem'] = '/admin/admin_plugins?action=sync_filesystem';
```

**File:** `adm/logic/admin_plugins_logic.php`

Add handler for `sync_filesystem` action (alongside existing `check_updates` handler):

```php
} elseif ($action === 'sync_filesystem') {
    try {
        $plugin_manager = new PluginManager();
        $result = $plugin_manager->sync();

        $parts = [];
        if (!empty($result['added'])) {
            $parts[] = count($result['added']) . ' new plugin(s) discovered';
        }
        if (!empty($result['updated'])) {
            $parts[] = count($result['updated']) . ' plugin(s) updated';
        }
        if (!empty($result['table_messages'])) {
            $parts[] = count($result['table_messages']) . ' table change(s)';
        }
        if (!empty($result['migration_messages'])) {
            $parts[] = count($result['migration_messages']) . ' migration(s) applied';
        }

        if (empty($parts)) {
            $message = 'Sync complete. Everything is up to date.';
        } else {
            $message = 'Sync complete: ' . implode(', ', $parts) . '.';
        }
        // Include table detail if any
        if (!empty($result['table_messages'])) {
            $message .= '<br><small>' . htmlspecialchars(implode('; ', $result['table_messages'])) . '</small>';
        }
        $message_type = 'success';
    } catch (Exception $e) {
        $message = 'Sync failed: ' . htmlspecialchars($e->getMessage());
        $message_type = 'danger';
    }
```

### 5. Admin "Sync" action — Themes page

**File:** `adm/admin_themes.php`

Add "Sync with Filesystem" to the Options dropdown:

```php
$altlinks['Sync with Filesystem'] = '/admin/admin_themes?action=sync_filesystem';
```

**File:** `adm/logic/admin_themes_logic.php`

Add handler in the switch statement:

```php
case 'sync_filesystem':
    $result = $theme_manager->sync();

    $parts = [];
    if (!empty($result['added'])) {
        $parts[] = count($result['added']) . ' new theme(s) discovered';
    }
    if (!empty($result['updated'])) {
        $parts[] = count($result['updated']) . ' theme(s) updated';
    }
    if (!empty($result['components'])) {
        $c = $result['components'];
        $component_parts = [];
        if ($c['created'] > 0) $component_parts[] = $c['created'] . ' created';
        if ($c['updated'] > 0) $component_parts[] = $c['updated'] . ' updated';
        if ($c['deactivated'] > 0) $component_parts[] = $c['deactivated'] . ' deactivated';
        if (!empty($component_parts)) {
            $parts[] = 'components: ' . implode(', ', $component_parts);
        }
    }

    if (empty($parts)) {
        $message = 'Sync complete. Everything is up to date.';
    } else {
        $message = 'Sync complete: ' . implode(', ', $parts) . '.';
    }
    break;
```

### 6. update_database — optional plugin/theme sync step

**File:** `utils/update_database.php`

Add a final step after all core operations complete:

```php
// Step N: Sync plugins and themes
echo '<h3>Step N: Plugin & Theme Sync</h3>';
require_once(PathHelper::getIncludePath('includes/PluginManager.php'));
require_once(PathHelper::getIncludePath('includes/ThemeManager.php'));

$theme_manager = ThemeManager::getInstance();
$theme_result = $theme_manager->sync();
// Report theme sync results...

$plugin_manager = PluginManager::getInstance();
$plugin_result = $plugin_manager->sync();
// Report plugin sync results (tables, migrations, deletion rules)...
```

This runs after the core database is fully up to date, so plugin tables are created against a current schema.

---

## Files modified

| File | Change |
|------|--------|
| `includes/PluginManager.php` | Override `sync()` with full behavior; delete `syncWithFilesystem()` |
| `migrations/theme_plugin_registry_sync.php` | Change `syncWithFilesystem()` call to `sync()` |
| `utils/upgrade.php` | Add table/migration message reporting |
| `adm/admin_plugins.php` | Add "Sync with Filesystem" to Options dropdown |
| `adm/logic/admin_plugins_logic.php` | Add `sync_filesystem` action handler |
| `adm/admin_themes.php` | Add "Sync with Filesystem" to Options dropdown |
| `adm/logic/admin_themes_logic.php` | Add `sync_filesystem` action handler |
| `utils/update_database.php` | Add plugin/theme sync as final step |

---

## What this does NOT change

- **Base `AbstractExtensionManager::sync()`** — untouched; still the low-level filesystem scanner called via `parent::sync()` in both managers
- **`ThemeManager::sync()`** — already comprehensive; no changes needed
- **Plugin install/activate/deactivate/uninstall** — unchanged; these already handle their own table updates and lifecycle hooks
- **`update_database` core pipeline** — still excludes plugins from the core table update; plugin tables are handled in the new final step via `PluginManager::sync()`

---

## Testing

1. Add a new column to a plugin's `$field_specifications` without deactivating/reactivating
2. Run "Sync with Filesystem" from admin plugins page
3. Verify the column is created in the database
4. Verify deletion rules are current
5. Verify `update_database` (admin utilities page) also triggers the sync
6. Verify `upgrade.php` reports table changes during deploy
7. Test ghost detection: rename a plugin directory, sync, verify it is deactivated
8. Test migration execution: add a pending migration to a plugin, sync, verify it runs
