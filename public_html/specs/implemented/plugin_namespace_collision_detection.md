# Plugin Namespace Collision Detection

## Overview

When two plugins independently define the same PHP class name or database table name, silent or catastrophic failures occur at runtime. This spec adds collision detection to `PluginManager::validatePlugin()` so conflicts are caught at install time rather than discovered in production.

## Problem Summary

Three collision types exist, with different severity:

| Collision Type | Risk | Behavior |
|---|---|---|
| Same PHP class name | **Fatal** | PHP fatal error on page load when both plugins are active |
| Same `$tablename` | **High** | `getModelClassForTable()` silently maps the table to the wrong class; schema management confused |
| Same `$prefix`, different table names | **Low** | Cosmetically confusing; no runtime failure in practice |

The goal is to block installs that would cause the first two, and warn on the third.

## Approach

Regex-scan all `/data/` directories — core and every installed plugin except the one being installed — to build a map of existing class names, table names, and prefixes. Then regex-scan the incoming plugin's `/data/` and compare.

No files are `require`d, so there's no risk of triggering the very fatal error we're trying to prevent, and no need to filter out accidentally-loaded classes.

## Implementation

### Changes to `PluginManager.php`

Add collision detection inside `validatePlugin()`, after the existing conflict check block (around line 456, before `storeDependencies`).

```php
// Scan all existing data classes (core + installed plugins, excluding self)
$existing_classes = $existing_tables = $existing_prefixes = [];
$scan_dirs = [PathHelper::getAbsolutePath('data')];
$plugins_dir = PathHelper::getAbsolutePath('plugins');
foreach (glob($plugins_dir . '/*/data') ?: [] as $dir) {
    if (strpos($dir, "plugins/{$plugin_name}/") === false) {
        $scan_dirs[] = $dir;
    }
}
foreach ($scan_dirs as $dir) {
    foreach (glob($dir . '/*.php') ?: [] as $file) {
        $content = file_get_contents($file);
        preg_match_all('/^\s*class\s+([A-Za-z_]\w*)/m', $content, $cm);
        preg_match_all('/\$tablename\s*=\s*[\'"]([a-z0-9_]+)[\'"]/', $content, $tm);
        preg_match_all('/\$prefix\s*=\s*[\'"]([a-z0-9_]+)[\'"]/', $content, $pm);
        $existing_classes = array_merge($existing_classes, $cm[1]);
        foreach ($tm[1] as $t) $existing_tables[$t] = basename(dirname($dir));
        foreach ($pm[1] as $p) $existing_prefixes[$p] = basename(dirname($dir));
    }
}

// Scan the incoming plugin
$incoming_classes = $incoming_tables = $incoming_prefixes = [];
$data_dir = PathHelper::getAbsolutePath("plugins/{$plugin_name}/data");
foreach (glob($data_dir . '/*.php') ?: [] as $file) {
    $content = file_get_contents($file);
    preg_match_all('/^\s*class\s+([A-Za-z_]\w*)/m', $content, $cm);
    preg_match_all('/\$tablename\s*=\s*[\'"]([a-z0-9_]+)[\'"]/', $content, $tm);
    preg_match_all('/\$prefix\s*=\s*[\'"]([a-z0-9_]+)[\'"]/', $content, $pm);
    $incoming_classes = array_merge($incoming_classes, $cm[1]);
    $incoming_tables = array_merge($incoming_tables, $tm[1]);
    $incoming_prefixes = array_merge($incoming_prefixes, $pm[1]);
}

// Check collisions
foreach ($incoming_classes as $cls) {
    if (in_array($cls, $existing_classes)) {
        $results['valid'] = false;
        $results['errors'][] = "Class name collision: '{$cls}' is already defined";
    }
}
foreach ($incoming_tables as $tbl) {
    if (isset($existing_tables[$tbl])) {
        $results['valid'] = false;
        $results['errors'][] = "Table name collision: '{$tbl}' is already used by {$existing_tables[$tbl]}";
    }
}
foreach ($incoming_prefixes as $pfx) {
    if (isset($existing_prefixes[$pfx]) && empty(array_intersect($incoming_tables, array_keys($existing_tables)))) {
        $results['warnings'][] = "Table prefix '{$pfx}' is also used by {$existing_prefixes[$pfx]} — consider a more distinctive prefix";
    }
}
```

## Validation Behaviour Summary

| Scenario | Result |
|---|---|
| New plugin class name matches installed plugin class | **Error** — install blocked |
| New plugin table name matches installed plugin table | **Error** — install blocked |
| New plugin prefix matches installed plugin prefix (no table collision) | **Warning** — install proceeds |
| No collision | Silent pass |

## Edge Cases

- **Reinstall / upgrade**: The incoming plugin's directory is excluded from the scan dirs, so it won't collide with itself.
- **Plugin with no `/data/` directory**: `glob()` returns empty — no collision possible, no error.
- **Multi-class files**: The regex extracts all class declarations from each file, so files defining multiple classes are handled correctly.
- **Core model classes**: Core `/data/` is included in the scan dirs, so collisions with core models are caught.

## Documentation Update

Add a note to `/docs/plugin_developer_guide.md` under the data class section:

> **Choosing a prefix:** Your plugin's table prefix (e.g. `abc` in `abc_items`) must be unique across all plugins installed on a site. Use a short abbreviation of your plugin name — at least 3 characters. The system will block installation if your class names or table names collide with an installed plugin, and will warn if your prefix matches even when table names don't.
