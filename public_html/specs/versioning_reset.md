# Specification: Versioning Reset and Consolidation

## Background

The platform has accumulated versioning inconsistencies. Upgrade packages in `upg_upgrades` are numbered in the 2.x range, the upgrade script self-version constant (`UPGRADE_SCRIPT_VERSION`) is at `2.70.1`, and two redundant/unused version settings exist in the database. This spec covers resetting the system to a clean `0.8.x` baseline, introducing a proper patch version, and removing the dead weight.

## Version Scheme Going Forward

**Format: `major.minor.patch`** — e.g. `0.8.1`, `0.8.2`, `1.0.0`, `1.0.1`.

Normal publishes auto-increment the patch. Version bumps (e.g. `0.9` or `1.0`) are done by manually entering new major/minor values in the publish form, with patch resetting to `1`.

Starting version: `0.8.1`.

This requires adding a `upg_patch_version` column to `upg_upgrades` (handled automatically by the data class system) and updating `publish_upgrade.php` to auto-increment the patch rather than the minor version.

## What `system_version` Does

`system_version` is a setting in `stg_settings` that records what version of Joinery is installed on a given site. It has two roles:

1. **Display** — shown in the admin UI so site owners can see what they're running
2. **Upgrade detection** — `upgrade.php` fetches the latest available version from the source server and compares against `system_version`: newer = upgrade available, equal = up to date, older = running ahead

It is written by `upgrade.php` on the *client* site after a successful upgrade. The source/publish server never sets it for itself. The test server currently shows `0.30` (last upgrade applied there).

---

## Current State

| Item | Location | Current Value | Problem |
|---|---|---|---|
| `UPGRADE_SCRIPT_VERSION` | `upgrade.php:3` | `2.70.1` | Unused dead code — defined but never referenced |
| Upgrade packages | `upg_upgrades` table | 2.75 – 2.95 (21 records) | No archive files exist on disk — orphaned stubs |
| `system_version` setting | `stg_settings` | `0.30` (test server) | Already low; will update automatically on next applied upgrade |
| `database_version` setting | `stg_settings` | `0.30` | Deprecated — redundant copy of system_version, should be deleted |
| `db_migration_version` setting | `stg_settings` | `150` | Unused legacy artifact, should be deleted |
| Plugin versions | `plugins/*/plugin.json` | 1.0.0 – 1.1.0 | Fine as-is |
| Theme versions | `theme/*/theme.json` | 1.0.0 – 2.1.0 | Fine as-is |
| Migration sequence numbers | `migrations/migrations.php` | Up to 96 | Internal sequence, unrelated to system versioning — leave alone |

---

## Scope

### In scope
1. Delete the 21 orphaned upgrade package records from `upg_upgrades` (no archive files exist anyway)
2. Add `upg_patch_version` field to `upgrades_class.php`
3. Update `publish_upgrade.php` to use `major.minor.patch` versioning with patch auto-increment
4. Update `upgrade.php` and `publish_upgrade.php` to handle 3-part version strings throughout
5. Remove `UPGRADE_SCRIPT_VERSION` constant from `upgrade.php` (unused dead code)
6. Implement the version consolidation work from `specs/implemented/version_number_consolidation.md` (that spec was marked implemented prematurely — the migrations and code changes were never actually done):
   - Add migrations to delete `database_version` and `db_migration_version` settings
   - Fix `publish_upgrade.php` to use form-provided version for SQL filename (instead of reading `database_version`)
   - Remove the `database_version` display line from `upgrade.php`

### Out of scope
- Plugin/theme version numbers (independent component versioning, leave at current values)
- Migration sequence numbers (internal, not user-facing)
- `system_version` on client sites (updates automatically when they apply the next upgrade)

---

## Changes Required

### 1. Delete orphaned upgrade records

Manually confirm and delete the 21 stale records in `upg_upgrades` (versions 2.75–2.95). No archive files exist on disk for any of them, so they serve no purpose.

```sql
DELETE FROM upg_upgrades WHERE upg_major_version = 2 AND upg_minor_version BETWEEN 75 AND 95;
```

After this, the next `publish_upgrade.php` run will auto-detect no existing records and start at `0.8.1`.

### 2. Remove `UPGRADE_SCRIPT_VERSION` — `upgrade.php:3`

Delete the constant — it is defined but never referenced anywhere in the codebase.

```php
// DELETE this line:
define('UPGRADE_SCRIPT_VERSION', '2.70.1');
```

### 3. Add `upg_patch_version` to `upgrades_class.php`

In `data/upgrades_class.php`, add the patch version field alongside the existing major and minor:

```php
// BEFORE:
'upg_major_version' => array('type'=>'int4', 'required'=>true),
'upg_minor_version' => array('type'=>'int4', 'required'=>true),

// AFTER:
'upg_major_version' => array('type'=>'int4', 'required'=>true),
'upg_minor_version' => array('type'=>'int4', 'required'=>true),
'upg_patch_version' => array('type'=>'int4', 'default'=>0),
```

The column is created automatically by the `update_database` system. Run it via admin utilities after deploying.

### 4. Update `publish_upgrade.php` — version auto-detection and format

**Auto-detect logic** — currently increments minor. Change to keep major/minor from the last record and increment patch instead:

```php
// BEFORE:
$cli_major = $cli_major ?? $last->get('upg_major_version');
$cli_minor = $cli_minor ?? ($last->get('upg_minor_version') + 1);

// AFTER:
$cli_major = $cli_major ?? ($last ? $last->get('upg_major_version') : 0);
$cli_minor = $cli_minor ?? ($last ? $last->get('upg_minor_version') : 8);
$cli_patch = $cli_patch ?? ($last ? ($last->get('upg_patch_version') + 1) : 1);
```

To do a version bump (e.g. `1.0`), pass `--version 1.0` on the CLI or enter new values in the form — patch resets to `1`.

**Version string** — update all places that build the version string from major.minor to major.minor.patch:

```php
// BEFORE:
$version = $version_major . '.' . $version_minor;

// AFTER:
$version = $version_major . '.' . $version_minor . '.' . $version_patch;
```

**Archive filenames** become `joinery-core-0.8.1.tar.gz` and `joinery-install-0.8.1.sql.gz`.

**Form fields** — keep all three inputs (major, minor, patch), each pre-filled with the auto-detected next value. Normally only the patch field changes.

**Database storage** — set `upg_patch_version` when saving the upgrade record:

```php
$upgrade->set('upg_major_version', $version_major);
$upgrade->set('upg_minor_version', $version_minor);
$upgrade->set('upg_patch_version', $version_patch);
```

**MultiUpgrade filter** — add `patch_version` option key alongside existing `major_version` and `minor_version` in `upgrades_class.php`.

### 5. Update `upgrade.php` — version string handling

Anywhere `upgrade.php` builds a version string from the upgrade record, include patch:

```php
// BEFORE:
$version = $upgrade->get('upg_major_version') . '.' . $upgrade->get('upg_minor_version');

// AFTER:
$version = $upgrade->get('upg_major_version') . '.' . $upgrade->get('upg_minor_version') . '.' . $upgrade->get('upg_patch_version');
```


### 6. Add version consolidation migrations — `migrations/migrations.php`

Add at the end of the file:

```php
// =============================================================================
// VERSION CONSOLIDATION - Remove redundant settings
// =============================================================================

// Remove deprecated database_version setting
$migration = array();
$migration['database_version'] = '0.97';
$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'database_version'";
$migration['migration_sql'] = "DELETE FROM stg_settings WHERE stg_name = 'database_version';";
$migration['migration_file'] = NULL;
$migrations[] = $migration;

// Remove deprecated db_migration_version setting
$migration = array();
$migration['database_version'] = '0.98';
$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'db_migration_version'";
$migration['migration_sql'] = "DELETE FROM stg_settings WHERE stg_name = 'db_migration_version';";
$migration['migration_file'] = NULL;
$migrations[] = $migration;
```

Note: The `version_number_consolidation.md` spec used database_version numbers `0.70` and `0.71` but the migration sequence is now at 96, so use `0.97` and `0.98`.

### 7. Fix SQL filename in `publish_upgrade.php`

Verify the SQL generation call references the form-provided `$version` (derived from major.minor.patch), not `$settings->get_setting('database_version')`. The latter is being deleted.

### 8. Remove `database_version` display from `upgrade.php`

Search `upgrade.php` for any line displaying `database_version` setting and remove it.

---

## Verification Steps

- [ ] `upg_upgrades` table is empty (confirm with SELECT)
- [ ] `upg_upgrades` table has `upg_patch_version` column (after `update_database`)
- [ ] `UPGRADE_SCRIPT_VERSION` constant removed from `upgrade.php`
- [ ] Running migrations via admin utilities removes `database_version` and `db_migration_version` from `stg_settings`
- [ ] `publish_upgrade.php` form pre-fills major=0, minor=8, patch=1 (auto-detected from empty table)
- [ ] Publishing creates `joinery-core-0.8.1.tar.gz` and `joinery-install-0.8.1.sql.gz` with matching names
- [ ] After a client applies upgrade 0.8.1, their `system_version` shows `0.8.1`
- [ ] Second publish auto-increments to `0.8.2`
