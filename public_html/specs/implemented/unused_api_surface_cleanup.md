# Spec: Remove Unused Public API Surface from Theme/Plugin System

**Status:** Pending implementation
**Area:** ThemeHelper, PluginHelper, ComponentBase, PluginManager

---

## Problem

During implementation of the theme-plugin dependency feature, `ThemeHelper::switchTheme()` was discovered to be a dead method with no callers that also bypassed the new `requires_plugins` validation — so it was removed. A broader audit found the same pattern throughout the theme/plugin system: a large number of public methods that were written speculatively as API surface but never used.

These methods carry real maintenance cost: they must be considered when adding validation logic (as with `switchTheme()`), they mislead developers into thinking they're part of the active API, and they appear in tooling output (IDE autocomplete, static analysis).

---

## Two Categories of Change

### Category A: Remove — zero callers anywhere in the codebase

Methods with no callers, even internally. Pure dead code.

### Category B: Make protected — internal use only, incorrectly public

Methods that are called within their own file but have no external callers. These are implementation details that shouldn't be part of the public API.

---

## Changes by File

### `includes/ComponentBase.php`

| Method | Category | Notes |
|--------|----------|-------|
| `getVersion()` | Remove | Use `get('version')` — same thing |
| `getDescription()` | Remove | Use `get('description')` |
| `getAuthor()` | Remove | Use `get('author')` |
| `getAssetUrl()` | Remove | Zero callers |
| `fileExists()` | Remove | Zero callers |
| `getFiles()` | Remove | Zero callers |
| `toArray()` | Remove | Zero callers |
| `getRequirements()` | Make protected | Only called by `checkRequirements()` internally |

`getVersion/Description/Author` are thin wrappers around `get()` with a hardcoded key — they add no value over the existing generic accessor.

### `includes/ThemeHelper.php`

| Method | Category | Notes |
|--------|----------|-------|
| `initialize()` | Remove | Had callers only in `switchTheme()` (already removed) and `initializeActive()` (also being removed) |
| `isActive()` | Remove | Zero callers anywhere |
| `initializeActive()` | Remove | Zero callers |
| `validateAll()` | Remove | Zero callers |
| `getPublicPageBase()` | Remove | Zero callers; use `get('publicPageBase')` |

`registerTheme()` (private) is only called from `initialize()` — remove it too.

### `includes/PluginHelper.php`

**Remove — zero callers anywhere (including internally):**

| Method | Notes |
|--------|-------|
| `initialize()` | Zero callers — confirmed no internal or external callers |
| `hasAdminInterface()` | Only called by `initialize()` (being removed) |
| `hasCustomRouting()` | Only called by `initialize()` (being removed) |
| `hasMigrations()` | Only called by `initialize()` (being removed) |
| `getMigrationsPath()` | Only called by private `checkMigrations()`, which is only called by `initialize()` |
| `getDataModels()` | Pure stub — zero callers |
| `providesFeature()` | Pure stub — zero callers |
| `getPluginDescription()` | Duplicate of `get('description')` — zero callers |
| `getService()` | Pure stub — zero callers |
| `hasService()` | Pure stub — zero callers |
| `getByType()` | Pure stub — zero callers |
| `providesTheme()` | Pure stub — zero callers |
| `validateAsThemeProvider()` | Pure stub — zero callers |
| `getValidThemeProviders()` | Pure stub — zero callers |

Also remove private methods `registerAdminMenu()` and `checkMigrations()` — both only called by `initialize()`.

**Make protected (internal callers that are staying):**

| Method | Called by |
|--------|-----------|
| `isActive()` | `getActivePlugins()`, `isPluginActive()` — both staying |
| `getAdminMenuItems()` | `validate()` (external callers exist) — `registerAdminMenu()` also calls it but is being removed |
| `getApiEndpoints()` | `validate()` (external callers exist) |

### `includes/PluginManager.php`

**Remove:**

| Method | Notes |
|--------|-------|
| `repair()` | Deprecated, only calls `validateAllPlugins()` |
| `validateAllPlugins()` | Only caller is `repair()` — goes with it |
| `canActivate()` | Zero callers anywhere |

**Make protected:**

| Method | Called internally at |
|--------|---------------------|
| `runPendingMigrations()` | Lines 129, 733 (install/activate paths) |
| `validatePlugin()` | Lines 132, 550, 719, 959, 971 |
| `getDependents()` | Lines 646, 792 (deactivate/uninstall) |

---

## Docs to Update

| File | Change |
|------|--------|
| `docs/plugin_developer_guide.md` | Line 396 references `PluginManager::runPendingMigrations()` as if public — update to note it is an internal method. No behavioral change; just corrects the implied API. |

No other doc references to any of these methods were found.

---

## Out of Scope

- Removing `checkRequirements()` itself — it IS called externally (by PluginHelper and ThemeHelper validation)
- Any changes to plugin.json or theme.json field definitions
- Plugin-to-plugin dependency handling

---

## Implementation Notes

- Run `php -l` and `validate_php_file.php` on each modified file after changes
- After removing PluginManager methods, grep for `repair(`, `validateAllPlugins(`, `canActivate(` to confirm no callers were missed
