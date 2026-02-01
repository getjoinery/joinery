# Plugin & Theme Permanent Delete Specification

## Overview

Add a "Permanently Delete" action for **both plugins and themes** that removes database records AND files from the filesystem. This is distinct from the current "Uninstall" action which keeps files on disk for easy reinstallation.

**Both plugins and themes will have identical UI patterns, but plugins have more cleanup logic due to their database components.**

## Current Behavior

### Lifecycle States (Same for Plugins and Themes)

| State | Files on Disk | Database Record | Description |
|-------|---------------|-----------------|-------------|
| Not Installed | Yes | No | Files exist, not registered |
| Installed/Inactive | Yes | Yes (inactive) | Registered but not active |
| Active | Yes | Yes (active) | Currently in use |
| Uninstalled | Yes | No | Uninstall ran, files remain |

### Architecture Difference: Plugins vs Themes

**Plugins** (backend-only) can have:
- Database tables and data models
- Settings in `stg_settings`
- Admin menu entries
- Version tracking (`plugin_versions` table)
- Uninstall scripts to clean up the above

**Themes** (frontend-only) have:
- Views, templates, static assets
- No database tables
- No settings
- No uninstall scripts needed

### Current Plugin Uninstall Scripts

Three plugins have uninstall scripts:
- `/plugins/bookings/uninstall.php` → `bookings_uninstall()`
- `/plugins/items/uninstall.php` → `items_uninstall()`
- `/plugins/controld/uninstall.php` → `controld_uninstall()`

**Plugin uninstall script pattern:**
```php
function {name}_uninstall() {
    // 1. Drop tables (in dependency order with CASCADE)
    // 2. Remove settings: DELETE FROM stg_settings WHERE stg_name LIKE '{name}_%'
    // 3. Remove admin menu entries
    // 4. Return true/false
}
```

**Themes do not have uninstall scripts** - they have no database components to clean up.

### Current Plugin `uninstall()` Method
Located in `/data/plugins_class.php`:
1. Validates not active (must deactivate first)
2. Checks no dependencies
3. Runs uninstall script if exists
4. Clears version tracking (plugin_versions table)
5. Deletes database record
6. **Does NOT delete files**

### Current Theme Behavior
Themes have no delete method. Since themes don't have database components, they only need a `permanent_delete_with_files()` method that removes the DB record and deletes files.

## Proposed Behavior

### New "Permanently Delete" Action

**Plugin Flow:**
1. Check prerequisites (not active, no dependencies)
2. Run `uninstall()` which runs uninstall script, clears versions, deletes DB record
3. Delete the `/plugins/{name}/` directory

**Theme Flow:**
1. Check prerequisites (not active, not system theme)
2. Delete the database record
3. Delete the `/theme/{name}/` directory

### Stock vs Custom Warnings (Same for Both)

| Type | Warning Message |
|------|-----------------|
| Stock | "This will permanently delete all files. Stock {plugins/themes} can be re-downloaded later via the upgrade system." |
| Custom | "⚠️ WARNING: This will permanently delete all files and data. Custom {plugins/themes} cannot be recovered!" |

### Prerequisites

**Plugins:**
1. Must NOT be active
2. No other plugins depend on it
3. Must NOT be an active theme provider (plugin that provides the currently active theme)

**Themes:**
1. Must NOT be active (currently selected theme)
2. Must NOT be a system theme (`thm_is_system = true`)

### Protected Items

**System Themes** (`thm_is_system = true`):
- Cannot be uninstalled or permanently deleted
- UI should not show delete actions for system themes
- Example: `falcon` theme if marked as system

**Active Theme Provider Plugins:**
- Plugins that provide the currently active theme cannot be deleted
- UI should not show delete actions for these plugins
- Example: If active theme is `falcon`, the `falcon` plugin cannot be deleted

### Pre-flight Checks

Before starting any deletion, verify:
1. Directory exists and is writable
2. All files within are deletable (check permissions recursively)

This catches permission errors early rather than leaving the system in a partial state.

## Implementation Plan

### 1. Create Shared Helper for Directory Deletion

Add to `/includes/LibraryFunctions.php`:

```php
/**
 * Check if a directory can be deleted (all files/subdirs are writable)
 * @param string $dir Directory path
 * @return array ['can_delete' => bool, 'errors' => array of problem paths]
 */
public static function check_directory_deletable($dir) {
    $result = array('can_delete' => true, 'errors' => array());

    if (!is_dir($dir)) {
        return $result; // Doesn't exist = can "delete"
    }

    if (!is_writable($dir)) {
        $result['can_delete'] = false;
        $result['errors'][] = $dir;
        return $result;
    }

    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            $sub_result = self::check_directory_deletable($path);
            if (!$sub_result['can_delete']) {
                $result['can_delete'] = false;
                $result['errors'] = array_merge($result['errors'], $sub_result['errors']);
            }
        } else {
            if (!is_writable($path)) {
                $result['can_delete'] = false;
                $result['errors'][] = $path;
            }
        }
    }

    return $result;
}

/**
 * Recursively delete a directory and all contents
 * @param string $dir Directory path
 * @return bool Success
 */
public static function delete_directory($dir) {
    if (!is_dir($dir)) {
        return false;
    }

    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            self::delete_directory($path);
        } else {
            unlink($path);
        }
    }

    return rmdir($dir);
}
```

### 2. Add Method to Plugin Class (`/data/plugins_class.php`)

```php
/**
 * Permanently delete plugin files from filesystem
 * Runs uninstall first if not already uninstalled
 * @return array Results with success, errors, messages
 */
public function permanent_delete_with_files() {
    $results = array(
        'success' => false,
        'errors' => array(),
        'messages' => array(),
        'warnings' => array()
    );

    try {
        $plugin_name = $this->get('plg_name');
        $plugin_dir = PathHelper::getIncludePath('plugins/' . $plugin_name);

        // Pre-flight check: verify we can delete files before making any changes
        if (is_dir($plugin_dir)) {
            require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
            $perm_check = LibraryFunctions::check_directory_deletable($plugin_dir);
            if (!$perm_check['can_delete']) {
                $results['errors'][] = "Permission denied. Cannot delete: " . implode(', ', array_slice($perm_check['errors'], 0, 3));
                return $results;
            }
        }

        // Run uninstall if not already uninstalled
        if ($this->get('plg_status') !== 'uninstalled') {
            $uninstall_result = $this->uninstall();
            if (!$uninstall_result['success']) {
                $results['errors'] = $uninstall_result['errors'];
                return $results;
            }
            $results['messages'] = array_merge($results['messages'], $uninstall_result['messages']);
        }

        // Delete files
        if (is_dir($plugin_dir)) {
            if (LibraryFunctions::delete_directory($plugin_dir)) {
                $results['success'] = true;
                $results['messages'][] = "Deleted plugin directory";
            } else {
                $results['errors'][] = "Failed to delete plugin directory";
            }
        } else {
            $results['success'] = true;
            $results['messages'][] = "Plugin directory already removed";
        }

    } catch (Exception $e) {
        $results['errors'][] = $e->getMessage();
    }

    return $results;
}
```

### 3. Add Method to Theme Class (`/data/themes_class.php`)

```php
/**
 * Permanently delete theme - removes database record and files
 * Note: Themes don't have uninstall scripts (no database components to clean up)
 * @return array Results with success, errors, messages
 */
public function permanent_delete_with_files() {
    $results = array(
        'success' => false,
        'errors' => array(),
        'messages' => array(),
        'warnings' => array()
    );

    try {
        $theme_name = $this->get('thm_name');
        $theme_dir = PathHelper::getIncludePath('theme/' . $theme_name);

        // Check if system theme
        if ($this->get('thm_is_system')) {
            $results['errors'][] = 'Cannot delete system theme';
            return $results;
        }

        // Check if active theme
        $settings = Globalvars::get_instance();
        if ($settings->get_setting('theme') === $theme_name) {
            $results['errors'][] = 'Cannot delete active theme. Switch to another theme first.';
            return $results;
        }

        // Pre-flight check: verify we can delete files before making any changes
        if (is_dir($theme_dir)) {
            require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
            $perm_check = LibraryFunctions::check_directory_deletable($theme_dir);
            if (!$perm_check['can_delete']) {
                $results['errors'][] = "Permission denied. Cannot delete: " . implode(', ', array_slice($perm_check['errors'], 0, 3));
                return $results;
            }
        }

        // Delete theme database record
        $this->permanent_delete();
        $results['messages'][] = "Removed theme from database";

        // Delete files
        if (is_dir($theme_dir)) {
            if (LibraryFunctions::delete_directory($theme_dir)) {
                $results['success'] = true;
                $results['messages'][] = "Deleted theme directory";
            } else {
                $results['errors'][] = "Failed to delete theme directory";
            }
        } else {
            $results['success'] = true;
            $results['messages'][] = "Theme directory already removed";
        }

    } catch (Exception $e) {
        $results['errors'][] = $e->getMessage();
    }

    return $results;
}
```

### 4. Update Admin UI - Plugins (`/adm/admin_plugins.php`)

Add "Permanently Delete" to actions dropdown:

```php
// For inactive/installed plugins
} elseif ($plugin_status === 'inactive' || $plugin_status === 'installed') {
    $actions['Activate'] = "javascript:submitPluginAction('activate', '$plugin_name')";
    if (!$is_active_theme_provider) {
        $actions['Uninstall'] = "javascript:confirmPluginAction('uninstall', '$plugin_name', 'Are you sure you want to uninstall this plugin?')";

        // Permanent delete with stock/custom warning
        $is_stock = $plugin['plugin'] ? $plugin['plugin']->is_stock() : false;
        $warning = $is_stock
            ? 'This will permanently delete all plugin files. Stock plugins can be re-downloaded later.'
            : 'WARNING: This will permanently delete all plugin files. Custom plugins cannot be recovered!';
        $actions['Permanently Delete'] = "javascript:confirmPluginAction('permanent_delete', '$plugin_name', '$warning')";
    }
}

// For uninstalled plugins
} elseif ($plugin_status === 'uninstalled') {
    $actions['Install'] = "javascript:submitPluginAction('install', '$plugin_name')";

    $is_stock = $plugin['plugin'] ? $plugin['plugin']->is_stock() : false;
    $warning = $is_stock
        ? 'This will permanently delete all plugin files. Stock plugins can be re-downloaded later.'
        : 'WARNING: This will permanently delete all plugin files. Custom plugins cannot be recovered!';
    $actions['Permanently Delete'] = "javascript:confirmPluginAction('permanent_delete', '$plugin_name', '$warning')";
}
```

### 5. Update Admin UI - Themes (`/adm/admin_themes.php`)

Add "Permanently Delete" action, respecting system theme protection:

```php
// Check if system theme - no delete actions allowed
$is_system = $theme['theme'] ? $theme['theme']->get('thm_is_system') : false;

// For inactive themes (non-system only)
if (($theme_status === 'inactive' || $theme_status === 'installed') && !$is_system) {
    $actions['Activate'] = "javascript:submitThemeAction('activate', '$theme_name')";

    $is_stock = $theme['theme'] ? $theme['theme']->is_stock() : false;
    $warning = $is_stock
        ? 'This will permanently delete all theme files. Stock themes can be re-downloaded later.'
        : 'WARNING: This will permanently delete all theme files. Custom themes cannot be recovered!';
    $actions['Permanently Delete'] = "javascript:confirmThemeAction('permanent_delete', '$theme_name', '$warning')";
}

// For system themes - show disabled option with explanation
if ($is_system) {
    $actions['System Theme'] = "javascript:alert('System themes cannot be deleted.')";
}
```

### 6. Add Action Handlers

**`/adm/logic/admin_plugins_logic.php`:**
```php
case 'permanent_delete':
    $plugin_name = LibraryFunctions::fetch_variable('plugin_name', NULL, 1, '');
    if ($plugin_name) {
        require_once(PathHelper::getIncludePath('data/plugins_class.php'));
        $plugin = Plugin::GetByColumn('plg_name', $plugin_name);

        if ($plugin) {
            $result = $plugin->permanent_delete_with_files();
        } else {
            // No DB record - just delete files
            $plugin_dir = PathHelper::getIncludePath('plugins/' . $plugin_name);
            require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
            $result = array('success' => false, 'errors' => array(), 'messages' => array());
            if (is_dir($plugin_dir) && LibraryFunctions::delete_directory($plugin_dir)) {
                $result['success'] = true;
                $result['messages'][] = "Plugin files deleted";
            } else {
                $result['errors'][] = "Failed to delete plugin files";
            }
        }

        if ($result['success']) {
            $session->add_message('info', "Plugin '{$plugin_name}' permanently deleted.", $return);
        } else {
            $session->add_message('error', implode(', ', $result['errors']), $return);
        }
    }
    break;
```

**`/adm/logic/admin_themes_logic.php`:**
```php
case 'permanent_delete':
    $theme_name = LibraryFunctions::fetch_variable('theme_name', NULL, 1, '');
    if ($theme_name) {
        require_once(PathHelper::getIncludePath('data/themes_class.php'));
        $theme = Theme::GetByColumn('thm_name', $theme_name);

        if ($theme) {
            // Check system theme protection
            if ($theme->get('thm_is_system')) {
                $session->add_message('error', "Cannot delete system theme '{$theme_name}'.", $return);
                break;
            }
            $result = $theme->permanent_delete_with_files();
        } else {
            // No DB record - just delete files
            $theme_dir = PathHelper::getIncludePath('theme/' . $theme_name);
            require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
            $result = array('success' => false, 'errors' => array(), 'messages' => array());
            if (is_dir($theme_dir) && LibraryFunctions::delete_directory($theme_dir)) {
                $result['success'] = true;
                $result['messages'][] = "Theme files deleted";
            } else {
                $result['errors'][] = "Failed to delete theme files";
            }
        }

        if ($result['success']) {
            $session->add_message('info', "Theme '{$theme_name}' permanently deleted.", $return);
        } else {
            $session->add_message('error', implode(', ', $result['errors']), $return);
        }
    }
    break;
```

## File Changes Summary

| File | Changes |
|------|---------|
| `/includes/LibraryFunctions.php` | Add `check_directory_deletable()` and `delete_directory()` static methods |
| `/data/plugins_class.php` | Add `permanent_delete_with_files()` method |
| `/data/themes_class.php` | Add `permanent_delete_with_files()` method |
| `/adm/admin_plugins.php` | Add "Permanently Delete" action to dropdown |
| `/adm/logic/admin_plugins_logic.php` | Add 'permanent_delete' case handler |
| `/adm/admin_themes.php` | Add "Permanently Delete" action to dropdown (with system theme check) |
| `/adm/logic/admin_themes_logic.php` | Add 'permanent_delete' case handler (with system theme check) |

## UI Summary

### Plugin Actions Dropdown - Inactive State
```
[Actions ▼]
├── Activate
├── Uninstall          ← keeps files, runs uninstall script
└── Permanently Delete ← removes files
```

### Plugin Actions Dropdown - Uninstalled State
```
[Actions ▼]
├── Install
└── Permanently Delete
```

### Theme Actions Dropdown - Inactive State
```
[Actions ▼]
├── Activate
└── Permanently Delete ← removes files (no uninstall needed)
```

### Theme Actions Dropdown - System Theme
```
[Actions ▼]
└── System Theme (disabled) ← shows alert explaining protection
```

### Confirmation Dialogs

| Type | Stock | Custom |
|------|-------|--------|
| Plugin | "This will permanently delete all plugin files. Stock plugins can be re-downloaded later." | "⚠️ WARNING: This will permanently delete all plugin files. Custom plugins cannot be recovered!" |
| Theme | "This will permanently delete all theme files. Stock themes can be re-downloaded later." | "⚠️ WARNING: This will permanently delete all theme files. Custom themes cannot be recovered!" |

## Edge Cases

| Edge Case | Handling |
|-----------|----------|
| File permission errors | Pre-flight check catches this before any changes; reports problem files |
| Directory doesn't exist | Success (already deleted), clean up database |
| Active plugin/theme | Block with message "Must deactivate first" |
| Plugin dependencies | Block with message listing dependents |
| Active theme provider plugin | Block - UI hides delete action for these plugins |
| Active theme | Block with "Cannot delete active theme" |
| System theme | Block with "Cannot delete system theme" - no actions shown in UI |
| No DB record (files only) | Allow deletion - just removes files |

## Testing Plan

| Test | Plugin | Theme |
|------|--------|-------|
| Permanent delete stock item | Verify files deleted, can re-download | Same |
| Permanent delete custom item | Verify files deleted, warning shown | Same |
| Permanent delete uninstalled item | Verify only file deletion | Same |
| Permanent delete inactive item | Verify uninstall + delete sequence | Same |
| Permanent delete with dependencies | Verify blocked with message | N/A (themes don't have deps) |
| File permission errors | Verify pre-flight catches error, no partial state | Same |
| Delete active item | Verify blocked | Same |
| Delete active theme provider plugin | Verify blocked, no action in UI | N/A |
| Delete system theme | N/A | Verify blocked, no actions in UI |
