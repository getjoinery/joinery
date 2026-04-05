---
title: Extension Deprecation Flag
status: planned
priority: low
---

# Extension Deprecation Flag

## Overview

Add a `"deprecated"` flag to theme.json and plugin.json manifests so that retired extensions are clearly marked, hidden from default admin views, excluded from new-site deployments, and guarded against accidental activation.

This is separate from the existing `is_stock` flag, which controls whether an extension is platform-provided vs. client-created. A deprecated extension is still stock (it ships in the repo and receives updates for sites already running it), but it is no longer recommended for use on new sites.

## Manifest Changes

### theme.json / plugin.json

Add two optional fields:

```json
{
    "name": "scrolldaddy",
    "version": "1.0.0",
    "is_stock": true,
    "deprecated": true,
    "superseded_by": "scrolldaddy-html5"
}
```

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `deprecated` | bool | `false` | Marks the extension as deprecated |
| `superseded_by` | string\|null | `null` | Name of the replacement extension (optional) |

Both fields are informational metadata read from the manifest. No new database columns are needed.

## Behavior Changes

### 1. Admin Theme/Plugin List Pages

**Files:** `adm/admin_themes.php`, `adm/admin_plugins.php`

- Show a **"Deprecated" badge** (`<span class="badge bg-dark">Deprecated</span>`) in the Status/Type column for deprecated extensions.
- If `superseded_by` is set, append text below the badge: `Replaced by {name}`.
- **Sort deprecated extensions to the bottom** of the table, after all non-deprecated extensions. Within the deprecated group, preserve existing sort order.

### 2. Activation Warning

**Files:** `adm/logic/admin_themes_logic.php`, `adm/logic/admin_plugins_logic.php`

When a user attempts to activate a deprecated theme or plugin:

- Read the manifest and check the `deprecated` flag.
- If deprecated, return an error/warning message: _"This extension is deprecated. Use {superseded_by} instead."_ (or just _"This extension is deprecated."_ if no replacement is specified).
- **Do not hard-block activation.** The warning is informational. The extension activates normally. This is important because existing sites may need to re-activate after troubleshooting.

### 3. Deployment: New Installs

**File:** `includes/DeploymentHelper.php`

When building archives for **new site installation** (the initial tarball that `install.sh` deploys):

- Read each extension's manifest during archive creation.
- **Exclude** extensions where `deprecated: true`. They should not be present on a freshly installed site.

This applies to the archive-building path, not to `updateInstalledThemesOnly()`.

### 4. Deployment: Existing Site Upgrades

**File:** `includes/DeploymentHelper.php` — `updateInstalledThemesOnly()`

**No change to existing behavior.** Deprecated extensions that are already installed on a site continue to receive updates from staging, exactly like any other stock extension. The `is_stock` flag governs update behavior; `deprecated` does not affect it.

This ensures sites currently running a deprecated extension don't silently stop receiving fixes.

### 5. Extension Scanner

**File:** `includes/AbstractExtensionManager.php` — `loadMetadataIntoModel()`

No change needed. The scanner already reads and returns the full manifest. Admin pages read the manifest directly when checking the deprecated flag. If a database column is desired later, it can be added, but for this spec, reading from the JSON at display time is sufficient.

## Implementation Notes

### Reading the flag

Since both admin pages already read manifests for `is_stock`, the pattern is established. For the list pages, read the deprecated flag alongside existing manifest reads:

```php
$manifest_path = PathHelper::getIncludePath("theme/{$theme_name}/theme.json");
$manifest = json_decode(file_get_contents($manifest_path), true);
$is_deprecated = $manifest['deprecated'] ?? false;
$superseded_by = $manifest['superseded_by'] ?? null;
```

For the activation warning in logic files, read the manifest before processing the activate action.

### Sorting

In the logic files that build the extension arrays, add a sort step after loading all extensions:

```php
usort($themes, function($a, $b) {
    $a_dep = $a['deprecated'] ?? false;
    $b_dep = $b['deprecated'] ?? false;
    if ($a_dep !== $b_dep) return $a_dep ? 1 : -1;
    return 0; // preserve existing order within groups
});
```

### New install exclusion

In the archive-building code path, skip any directory whose manifest has `deprecated: true`:

```php
$manifest = json_decode(file_get_contents($manifest_path), true);
if (!empty($manifest['deprecated'])) {
    if ($verbose) echo "  Skipping deprecated $type: $name\n";
    continue;
}
```

## Extensions to Mark as Deprecated

### Plugins
- `scrolldaddy` — superseded_by: `scrolldaddy-html5`

### Themes
Candidate themes to evaluate (non-HTML5 versions that have HTML5 replacements):
- `phillyzouk` — superseded_by: `phillyzouk-html5`
- `linka-reference` — superseded_by: `linka-reference-html5`
- `devonandjerry` — superseded_by: `devonandjerry-html5`
- `zoukphilly` — superseded_by: `zoukphilly-html5`
- `zoukroom` — superseded_by: `zoukroom-html5`
- `galactictribune` — superseded_by: `galactictribune-html5`
- `jeremytunnell` — superseded_by: `jeremytunnell-html5`
- `empoweredhealth` — superseded_by: `empoweredhealth-html5`

The `falcon` theme (non-HTML5, no HTML5 variant) should be evaluated separately.

## What This Does NOT Do

- **No runtime behavior change.** Deprecated extensions that are active continue to work identically. No warnings are shown to end users.
- **No database schema changes.** The flag is read from the JSON manifest at display time.
- **No hard blocks.** Activation is warned, not prevented.
- **No change to upgrade flow.** Sites already running deprecated extensions keep getting updates.

## Documentation

Add a section to the existing [Plugin Developer Guide](/docs/plugin_developer_guide.md) documenting the `deprecated` and `superseded_by` manifest fields, explaining their effect on admin display, activation warnings, and deployment.
