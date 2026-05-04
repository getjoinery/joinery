# Spec: upgrade.php reads `receives_upgrades` from the wrong manifest

## Problem

When `upgrade.php` runs on a remote node, the decision about which extensions to
download and whether to preserve them is based on the **live installation's**
`receives_upgrades` flag. This creates a bootstrapping deadlock:

1. Operator sets `receives_upgrades: false` in `theme.json` to protect a custom theme.
2. Later, operator changes it to `true` (theme is now maintained by the platform).
3. Operator publishes an upgrade that includes the new `theme.json`.
4. Remote node runs `upgrade.php` — reads the **live** `theme.json`, sees `false`,
   skips downloading the theme entirely, and never receives the flag change.

The only escape is a manual `sed` / file edit on the remote node before the upgrade.

## Root Cause

Two places in the upgrade pipeline read from the **live** manifest instead of the
**staged** (package) manifest:

### 1. Download decision — `upgrade.php:get_upgradable_extensions()`

```php
$themes_to_download = array_values(array_intersect(
    get_upgradable_extensions($live_directory . '/theme', 'theme'),  // ← reads LIVE
    array_column($source_published_themes, 'name')
));
```

`get_upgradable_extensions()` reads the live installation and filters to
`receives_upgrades === true`. Themes where the live value is `false` are never
downloaded, so the updated `theme.json` from the package never reaches staging.

### 2. Preservation decision — `DeploymentHelper::copyPreservedToStaging()`

```php
// Theme exists in both live and staging — check the live version's flag
$manifest_path = $live_path . '/theme.json';   // ← reads LIVE
if (isset($manifest['receives_upgrades']) && $manifest['receives_upgrades'] === false) {
    $should_copy = true; // overwrites staged version with live version
}
```

Even if the theme were somehow downloaded into staging, this step would overwrite
it with the live version (whose `theme.json` still says `false`).

## Fix

**The staged (package) manifest is the authoritative source for `receives_upgrades`.**
The live manifest's flag is only meaningful for themes that are NOT in the package.

### Change 1 — `upgrade.php`: download all published-and-installed extensions

Remove the `receives_upgrades=true` filter from the download decision. Download
every extension that (a) is locally installed and (b) the source has published.

```php
// Before
$themes_to_download = array_values(array_intersect(
    get_upgradable_extensions($live_directory . '/theme', 'theme'),
    array_column($source_published_themes, 'name')
));

// After
$installed_theme_names = get_installed_extension_names($live_directory . '/theme');
$themes_to_download = array_values(array_intersect(
    $installed_theme_names,
    array_column($source_published_themes, 'name')
));
```

Add `get_installed_extension_names($ext_dir)` — returns all installed extension
names without filtering by `receives_upgrades`.

Update the display table (`get_installed_extension_info()`) to accept the list of
published names so it can show accurate `will_upgrade` / `skipped` status:
- `will_upgrade: true` if the source has published the extension (it will be
  downloaded; the staged manifest then decides preservation)
- `will_upgrade: false` if the source has not published it (will not be touched)

### Change 2 — `DeploymentHelper::copyPreservedToStaging()`: read staged manifest

When a theme/plugin is present in both live and staging, read `receives_upgrades`
from the **staged** manifest, not the live manifest.

```php
// Before: read from live
$manifest_path = $live_path . '/theme.json';

// After: read from staging
$manifest_path = $stage_path . '/theme.json';
// (fall back to live manifest if staged has no theme.json — unusual/corrupt package)
```

Same change for plugins (`plugin.json`).

## Behaviour After Fix

| Live flag | Staged flag | Source published | Result        |
|-----------|-------------|-----------------|---------------|
| `true`    | `true`      | yes             | upgraded ✓   |
| `false`   | `true`      | yes             | upgraded ✓ (was: skipped) |
| `true`    | `false`     | yes             | preserved ✓  |
| `false`   | `false`     | yes             | preserved ✓  |
| any       | —           | no              | preserved (live copied) ✓ |

The live manifest's flag is now only consulted as a fallback for the edge case
where the staged package has no manifest (corrupt/missing file).

## Files Changed

- `utils/upgrade.php` — download decision + display table
- `includes/DeploymentHelper.php` — `copyPreservedToStaging()`

## Notes

- `receives_upgrades: false` (preserve) is still fully honoured — just via the
  **staged** manifest rather than the live one.
- This change does not affect `included_in_publish` (control-plane side). That
  flag still controls what enters the package in the first place.
- No migration needed; no DB changes.
