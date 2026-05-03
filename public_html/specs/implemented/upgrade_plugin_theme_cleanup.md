# Upgrade Pipeline: Plugin/Theme Logic Cleanup

## Problem

The recent upgrade simplification (`specs/implemented/upgrade_simplification.md`) cut `utils/upgrade.php` from 1,985 to ~1,593 lines and made the apply flow legible. But a second-pass review shows the plugin/theme handling specifically still has:

- **A silent-failure bug** in system-required detection: a theme/plugin marked `is_system=true, receives_upgrades=false` is filtered out and never installed, with no warning.
- **A two-source-of-truth race**: `receives_upgrades` lives in both the on-disk manifest and a DB column (`plg_receives_upgrades` / `thm_receives_upgrades`). The post-deploy "stale" SQL block runs raw `UPDATE plg_plugins ... WHERE plg_name NOT IN (...)` in upgrade.php, *after* the file swap, instead of inside `PluginManager::sync()` where it belongs.
- **Theme/plugin parallel-but-divergent code**: four near-duplicate function pairs (`get_all_themes_info` / `get_all_plugins_info`, `get_system_required_themes` / `get_system_required_plugins`, plus the two download loops in the apply body) that differ only in `theme.json` vs `plugin.json` and field-name prefixes.
- **An asymmetric source-of-truth**: `get_upgradable_themes()` reads disk; `get_upgradable_plugins()` queries the DB. Same job, opposite mechanism.
- **A redundant lookup table**: `$source_theme_manifest` is built via `array_column()` only to immediately call `array_keys()` on it.

None of this is broken (the model is sound — the audit traced a realistic 5-theme/8-plugin site through end-to-end and every preserved/required/stale case landed correctly). It's just verbose and has overlapping concepts that make the code harder to read than it needs to be.

## Goal

Surgical clean-up. No restructuring of the apply flow. Fix the one real bug, move the stale-marking into the sync layer where it belongs, collapse the four near-duplicate function pairs into single parameterized helpers, and resolve the disk-vs-DB asymmetry. After this, `upgrade.php` is ~125 lines shorter, the plugin/theme model has a single source of truth (filesystem), and the silent-failure edge case is gone.

The `is_system` field stays. It's used by `adm/admin_themes.php` (delete-protection), the marketplace view, and `ThemeManager` / `PluginManager` sync — deleting it would be a larger, riskier change with no clear payoff. The cleanup is to fix how `is_system` is *consumed*, not to remove it.

---

## The Eight Cleanups

### 1. Fix the silent `is_system && receives_upgrades` filter

**Bug.** `get_system_required_themes()` (lines 1346–1359) and `get_system_required_plugins()` (lines 1362–1377) both gate the result on `$receives_upgrades && $is_system`. A theme marked `is_system=true, receives_upgrades=false` (operator says "this is a system theme but I've forked it locally") evaporates: it doesn't get downloaded as required, and it doesn't get preserved either (the preservation copy step keys on `receives_upgrades=false`, but only for extensions *already on disk* — a missing system theme stays missing).

**Fix.** Drop `$receives_upgrades` from the conditional. System-required means system-required, regardless of whether the local site has marked it for upgrade:

```php
function get_system_required_themes($theme_dir) {
    $themes = [];
    foreach (glob($theme_dir . '/*/theme.json') as $json_file) {
        $theme_data = json_decode(file_get_contents($json_file), true);
        if (!empty($theme_data['is_system'])) {
            $themes[] = basename(dirname($json_file));
        }
    }
    return $themes;
}
```

The download path that adds `required_themes` to `$themes_to_download` (lines ~656–668) already handles the "already-installed but ensure-latest" case — system themes always get the latest version on every upgrade. That's the correct behavior for a flag named `is_system`.

**Removes:** ~6 lines (two `$receives_upgrades` reads + their AND-clause + the now-dead local var). Negligible line count, but eliminates a real footgun.

---

### 2. Move stale-marking into `PluginManager::sync()` / `ThemeManager::sync()`

**Problem.** Lines 1143–1182 of `upgrade.php` run two raw `UPDATE` statements after the deploy swap to mark plugins/themes as `'stale'` when they're flagged `receives_upgrades=true` in the DB but no longer in the source manifest:

```php
$q = $dblink->prepare("UPDATE plg_plugins SET plg_status = 'stale'
    WHERE plg_receives_upgrades = true AND plg_name <> ALL(?::text[])
    AND (plg_status IS NULL OR plg_status <> 'stale')");
```

This is doing sync work *outside* the sync layer. Two consequences: (1) raw SQL on table internals leaks into upgrade.php; (2) the stale flag is set strictly *after* the file swap, so a partial-extraction failure that leaves staging in a bad state means the flag is never updated and the next deploy can't tell what's actually missing.

**Fix.** Move the logic into `AbstractExtensionManager::sync()` — the parent already iterates the on-disk extensions and runs ghost-detection, and the field name is generic (`$this->table_prefix . '_status'`), so the stale-marking is identical for plugins and themes apart from the prefix. Add an `array $options = []` parameter; the subclasses just pass it through.

```php
// In upgrade.php, replace the raw SQL block with:
$source_plugin_names = array_column($source_published_plugins, 'name');
$source_theme_names  = array_column($source_published_themes,  'name');
$plugin_result = $plugin_manager->sync(['source_manifest' => $source_plugin_names]);
$theme_result  = $theme_manager->sync (['source_manifest' => $source_theme_names]);
```

```php
// In AbstractExtensionManager::sync($options = []), after ghost-detection:
if (isset($options['source_manifest']) && is_array($options['source_manifest'])) {
    $this->markStaleAgainstManifest($options['source_manifest']);
}
```

`markStaleAgainstManifest()` is a single new protected method on the abstract that uses `$this->table_prefix` to build the UPDATE — same SQL pattern that's already in `upgrade.php`, just lifted into the right place. `PluginManager::sync()` and `ThemeManager::sync()` accept `$options` and pass it to `parent::sync($options)`. Sync still works without the option (back-compat for the admin "Sync with Filesystem" button, which has no manifest context).

**Removes:** ~45 lines from `upgrade.php` (the SQL block + the array-flip prep). Adds ~15 lines to `AbstractExtensionManager` (one method, used by both subclasses). Net: ~30 lines saved, zero duplication, and `upgrade.php` no longer knows the column-name prefixes.

---

### 3. Collapse `get_all_themes_info` / `get_all_plugins_info` into `get_installed_extension_info($dir, $type)`

**Problem.** Lines 1434–1450 (`get_all_themes_info`) and 1457–1473 (`get_all_plugins_info`) are 16-line functions identical except for the manifest filename:

```php
foreach (glob($theme_dir . '/*/theme.json') as $json_file) { ... }
foreach (glob($plugin_dir . '/*/plugin.json') as $json_file) { ... }
```

**Fix.**

```php
function get_installed_extension_info($extension_dir, $type) {
    // $type: 'theme' or 'plugin'
    $manifest_name = $type . '.json';
    $info = [];
    foreach (glob($extension_dir . '/*/' . $manifest_name) as $json_file) {
        $data = json_decode(file_get_contents($json_file), true) ?: [];
        $name = basename(dirname($json_file));
        $receives_upgrades = $data['receives_upgrades'] ?? false;
        $info[$name] = [
            'name' => $name,
            'display_name' => $data['display_name'] ?? $name,
            'version' => $data['version'] ?? 'unknown',
            'receives_upgrades' => $receives_upgrades,
            'will_upgrade' => $receives_upgrades === true,
        ];
    }
    ksort($info);
    return $info;
}
```

Call sites switch from `get_all_themes_info($live_directory . '/theme')` to `get_installed_extension_info($live_directory . '/theme', 'theme')`.

**Removes:** ~16 lines (two 16-line functions → one 16-line function).

---

### 4. Collapse `get_system_required_themes` / `get_system_required_plugins` into one helper

Same pattern as #3 — paired functions differing only in manifest filename. After cleanup #1 simplifies the body, the two functions become trivially mergeable:

```php
function get_system_required_extensions($extension_dir, $type) {
    $names = [];
    foreach (glob($extension_dir . '/*/' . $type . '.json') as $json_file) {
        $data = json_decode(file_get_contents($json_file), true);
        if (!empty($data['is_system'])) {
            $names[] = basename(dirname($json_file));
        }
    }
    return $names;
}
```

Caller updates: `get_system_required_themes($dir)` → `get_system_required_extensions($dir, 'theme')`.

**Removes:** ~14 lines (two functions → one).

---

### 5. Make `get_upgradable_plugins()` read disk like `get_upgradable_themes()`

**Problem.** Asymmetric source of truth:

- `get_upgradable_themes($theme_dir)` (lines 1327–1339) — globs `theme/*/theme.json`, returns names where `receives_upgrades === true`
- `get_upgradable_plugins()` (lines 1414–1428) — instantiates `MultiPlugin(['plg_receives_upgrades' => true])`, queries the DB

Same conceptual job, opposite mechanism. The DB query is justified by "plugins always have a DB row" (true, because `PluginManager::sync()` populates it on every upgrade and on the admin Sync button), but the asymmetry is a footgun: a maintainer reading the apply flow has to keep two mental models in their head ("themes are filesystem-driven, plugins are DB-driven, but really both *should* match because sync runs first…").

**Fix.** Make `get_upgradable_plugins()` glob `plugins/*/plugin.json` and read the `receives_upgrades` flag from the manifest, exactly like the theme version:

```php
function get_upgradable_plugins($plugin_dir) {
    $plugins = [];
    foreach (glob($plugin_dir . '/*/plugin.json') as $json_file) {
        $data = json_decode(file_get_contents($json_file), true);
        if (($data['receives_upgrades'] ?? false) === true) {
            $plugins[] = basename(dirname($json_file));
        }
    }
    return $plugins;
}
```

Calling site (line 394) updates: `get_upgradable_plugins()` → `get_upgradable_plugins($live_directory . '/plugins')`. (Or, with cleanup #3 already landed, both collapse further into `get_upgradable_extensions($dir, $type)`.)

**Removes:** ~15 lines (DB query setup + try/catch + error_log). Plus a `require_once` of `data/plugins_class.php` becomes unneeded for this path.

**Tradeoff.** A plugin manually deleted from disk between syncs is now silently skipped rather than DB-found-and-404'd. Today's behavior logs "404, no archive available" for the missing plugin; the new behavior just doesn't try. Both end with the same outcome (plugin not upgraded). The new behavior is quieter, which is fine — the admin Sync button is the path to reconcile manual disk changes.

---

### 6. Drop the redundant `$source_*_manifest` array_column

**Problem.** Lines 388–389:

```php
$source_theme_manifest  = array_column($source_published_themes,  null, 'name');
$source_plugin_manifest = array_column($source_published_plugins, null, 'name');
```

Builds a name-keyed view of the published-archives list. But the only use of either var is `array_keys($source_theme_manifest)` and `array_keys($source_plugin_manifest)` two lines later (392, 396). Building a full keyed copy just to take its keys is wasted work — `array_column($source_published_themes, 'name')` returns the names directly.

**Fix.**

```php
$themes_to_download  = array_values(array_intersect(
    get_upgradable_extensions($live_directory . '/theme', 'theme'),
    array_column($source_published_themes, 'name')
));
$plugins_to_download = array_values(array_intersect(
    get_upgradable_extensions($live_directory . '/plugins', 'plugin'),
    array_column($source_published_plugins, 'name')
));
```

Both `$source_theme_manifest` and `$source_plugin_manifest` go away.

**Removes:** ~4 lines. Trivial in size, but removes two unused intermediate vars and makes the intent obvious in one expression.

---

### 7. Collapse the two download loops into one helper

**Problem.** Lines 696–712 (themes) and 716–732 (plugins) are mirror loops:

```php
foreach ($themes_to_download as $theme_name) {
    $theme_url = $theme_url_by_name[$theme_name] ?? null;
    if (!$theme_url) { $skipped_items[] = "Theme: {$theme_name} — no URL in source manifest"; continue; }
    upgrade_echo("Downloading theme: {$theme_name}...");
    flush();
    $result = download_and_extract($theme_url, $stage_directory . '/theme/');
    if ($result['success']) { upgrade_echo(" ✓<br>"); $downloaded_count++; }
    else { upgrade_echo(" ⚠ skipped (...)<br>"); $skipped_items[] = "Theme: {$theme_name} — " . $result['error']; }
}
```

The plugin block is the same with `Theme`→`Plugin` and `theme`→`plugin` substitutions.

**Fix.** Extract a helper called twice:

```php
function download_extension_set($names, $url_lookup, $type, $target_subdir, $stage_directory, &$skipped_items) {
    $type_capitalized = ucfirst($type);
    $count = 0;
    foreach ($names as $name) {
        $url = $url_lookup[$name] ?? null;
        if (!$url) { $skipped_items[] = "{$type_capitalized}: {$name} — no URL in source manifest"; continue; }
        upgrade_echo("Downloading {$type}: {$name}...");
        flush();
        $result = download_and_extract($url, $stage_directory . '/' . $target_subdir . '/');
        if ($result['success']) { upgrade_echo(" ✓<br>"); $count++; }
        else {
            upgrade_echo(" ⚠ skipped (" . htmlspecialchars($result['error']) . ")<br>");
            $skipped_items[] = "{$type_capitalized}: {$name} — " . $result['error'];
        }
    }
    return $count;
}
```

Call sites:

```php
$downloaded_count  = download_extension_set($themes_to_download,  $theme_url_by_name,  'theme',  'theme',   $stage_directory, $skipped_items);
$downloaded_count += download_extension_set($plugins_to_download, $plugin_url_by_name, 'plugin', 'plugins', $stage_directory, $skipped_items);
```

**Removes:** ~30 lines (two 17-line loops → one 17-line helper + two 1-line calls).

---

### 8. Add an extension-flag doc comment at the top of `upgrade.php`

**Problem.** Three flags (`receives_upgrades`, `included_in_publish`, `is_system`) apply independently and the conjunction matrix is non-trivial. After the cleanups above, the flags are easier to find but the *semantics* still aren't documented anywhere central — a maintainer has to read three different functions to figure out what each flag means and who sets it.

**Fix.** Add a doc-comment block near the top of `upgrade.php` (just above the existing file-level comment, or alongside it):

```php
/**
 * EXTENSION FLAG MODEL
 *
 *   receives_upgrades  — operator on the *target* site says: replace this on upgrade.
 *                        Default true. Set false to keep a local fork.
 *   included_in_publish — operator on the *source* site says: include this in the
 *                        published archives. Default true. Set false for dev-only or
 *                        deprecated extensions.
 *   is_system          — flagged in theme.json/plugin.json as "must always be present
 *                        on every site" (e.g. the admin theme). Always pulled fresh
 *                        on upgrade regardless of receives_upgrades.
 */
```

Pure docs; no code change. Lands in the same PR as the rest of the cleanup since the cleanups make the flag references in the file consistent enough that a single doc block is now accurate.

**Removes:** 0 lines (adds ~12 lines of comment). Net negative on size, but pays for itself the first time someone has to debug the upgrade flow.

---

## What Gets Removed (Summary)

| Cleanup | Scope | Lines |
|---|---|---|
| 1. Fix `is_system && receives_upgrades` AND-conditional | upgrade.php | ~6 (+ bug fix) |
| 2. Move stale-marking into sync() | upgrade.php → PluginManager/ThemeManager | ~25 net |
| 3. Unify `get_all_*_info()` | upgrade.php | ~16 |
| 4. Unify `get_system_required_*()` | upgrade.php | ~14 |
| 5. Make `get_upgradable_plugins()` disk-driven | upgrade.php | ~15 |
| 6. Drop redundant `$source_*_manifest` lookup | upgrade.php | ~4 |
| 7. Collapse download loops into one helper | upgrade.php | ~30 |
| 8. Add extension-flag doc comment | upgrade.php | -12 (adds docs) |
| **Total** | | **~98 net + 1 bug fix + 1 doc block** |

`upgrade.php` size: 1,593 → ~1,485 lines. Plus the manager files each gain ~10 lines, all of which is sync logic that conceptually belongs there.

---

## What Stays the Same

- The `?serve-upgrade` JSON response shape and field names
- The `published_themes` / `published_plugins` array structure (objects with `name`, `version`, `url`)
- The `required_themes` / `required_plugins` array structure (flat name list)
- The pre-swap preservation copy step in `DeploymentHelper::copyPreservedToStaging()` — verbose but correct, and consolidating it would obscure the pre-swap timing
- The `is_system` flag itself (still consumed by `adm/admin_themes.php`, the marketplace, and the sync managers)
- The `receives_upgrades` flag (semantics unchanged)
- The `included_in_publish` flag (publisher-only, unchanged)
- The validation chain (PHP syntax, plugin loading, bootstrap, tarball structure, active theme)
- Stale reconciliation behavior — same outcome, different file

---

## What Stays Messy

Honest about what this doesn't fix:

- `receives_upgrades` still lives in both the on-disk manifest and the DB column. Cleanup #2 makes the sync between them tighter, but the dual representation remains — that's intentional, since the DB lets the admin UI filter and the manifest is what tarballs ship with.
- Three flags (`receives_upgrades`, `included_in_publish`, `is_system`) still apply independently. They're orthogonal and the conjunction matrix is non-trivial. A documentation block at the top of `upgrade.php` could help, but isn't part of this spec — see the Optional Follow-up.
- The apply flow is still procedural and ~1,000 lines of inline steps. Same as `upgrade_simplification.md` noted.

---

## Order of Operations

Each cleanup is independent. Suggested order:

1. **Cleanup 1 (silent-failure fix)** — smallest change, one-line conditional fix, gets the bug out of the way first.
2. **Cleanup 6 (drop `$source_*_manifest`)** — trivial, pure deletion.
3. **Cleanups 3 + 4 (unify get_all_* and get_system_required_*)** — mechanical extraction, lowest risk.
4. **Cleanup 5 (`get_upgradable_plugins` → disk)** — slight behavioral change (no more "DB row exists but file missing → log 404"). Validate by running an upgrade with a manually-deleted plugin in disk to confirm the new "silent skip" behavior is acceptable.
5. **Cleanup 7 (download loop helper)** — extraction, validate by running a normal upgrade end-to-end.
6. **Cleanup 2 (move stale-marking into sync)** — biggest behavioral move. Validate by:
   - Running a normal upgrade where source manifest matches local plugins (no stale markings expected)
   - Running an upgrade after deliberately removing a plugin from the source manifest (the DB row should get marked `'stale'` during sync, not after)
   - Running the admin "Sync with Filesystem" button (should still work without `source_manifest` option — back-compat preserved)
7. **Cleanup 8 (extension-flag doc comment)** — pure docs, no code change. Land last so the doc accurately reflects the cleaned-up code.

Each step keeps `upgrade.php` fully working; commits are bite-sized.

---

## Testing

After each cleanup, run a full upgrade against `joinerydemo` (or any cheap test target) and verify:

- Core, themes, and plugins all download and extract correctly
- Preserved (`receives_upgrades=false`) extensions survive the swap
- System (`is_system=true`) themes/plugins are present after upgrade even if not previously installed
- Stale plugins (in DB, not in source manifest) are marked `'stale'` after deploy
- The admin Plugins page shows the right counts (active, stale, etc.)
- No PHP errors in `/var/www/html/joinerytest/logs/error.log`

Cleanup #2 specifically requires a manual stale-plugin scenario: install a plugin on the target, then re-publish the source with that plugin removed (e.g., delete its `included_in_publish` flag), then upgrade. Confirm the plugin row shows `plg_status='stale'` after sync.
