# Specification: Version Number Consolidation

> **Note:** `mailgun_version` is out of scope for this spec. It tracks an external API version and is unrelated to Joinery's internal versioning.

## Problem Statement

The system has multiple version-related settings that are redundant or unused:

| Setting | Current Value | Status |
|---------|---------------|--------|
| `system_version` | (empty on test servers, set on distribution servers) | **Keep** - Used by upgrade.php for version comparison |
| `database_version` | 0.30 | **Remove** - Redundant, copied from system_version |
| `db_migration_version` | 150 | **Remove** - Unused legacy artifact |

### Current Inconsistency in publish_upgrade.php

The current code has a versioning inconsistency:
- **Archive filename** uses form input: `joinery-{$version_major}-{$version_minor}.tar.gz`
- **SQL filename** uses `database_version` setting: `joinery-install-{$version}.sql.gz`

These can be different values (e.g., archive is "2.1" but SQL file is "0.30"), which is confusing.

### How database_version Was Set

Migration 0.39 creates `database_version` by copying from `system_version`:
```sql
UPDATE stg_settings SET stg_value = (SELECT stg_value FROM stg_settings WHERE stg_name = 'system_version')
WHERE stg_name = 'database_version'
```

It's literally just a copy - completely redundant.

## Proposed Solution

Consolidate to a single version number:

### System Version (Keep Existing)
- **Format:** `MAJOR.MINOR` (e.g., `2.0`, `2.1`)
- **Stored in:** `stg_settings.system_version`
- **Used for:** Upgrade version comparison, display in admin UI
- **Note:** Only populated on distribution servers that publish archives

### Form-Provided Version (For Archive Creation)
- The version entered in `publish_upgrade.php` form should be used consistently for:
  - Archive filename: `joinery-2-1.tar.gz`
  - SQL filename: `joinery-install-2.1.sql.gz`
- `system_version` is set by clients after receiving an upgrade (not by publisher)

### Settings to Remove
- `database_version` - Redundant copy of system_version
- `db_migration_version` - No code references it

---

## Code Changes

### Phase 1: Database Migration

**File: `migrations/migrations.php`** - Add at end of file:

```php
// =============================================================================
// VERSION CONSOLIDATION - Remove redundant settings
// =============================================================================

// Remove deprecated database_version setting
$migration = array();
$migration['database_version'] = '0.70';
$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'database_version'";
$migration['migration_sql'] = "DELETE FROM stg_settings WHERE stg_name = 'database_version';";
$migration['migration_file'] = NULL;
$migrations[] = $migration;

// Remove deprecated db_migration_version setting
$migration = array();
$migration['database_version'] = '0.71';
$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'db_migration_version'";
$migration['migration_sql'] = "DELETE FROM stg_settings WHERE stg_name = 'db_migration_version';";
$migration['migration_file'] = NULL;
$migrations[] = $migration;
```

### Phase 2: Update publish_upgrade.php

**File: `utils/publish_upgrade.php`**

Use form-provided version consistently instead of reading from database:

**Lines 43-51:** Use form version for SQL file
```php
// BEFORE:
$version = $settings->get_setting('database_version') ?: '0.1';
echo "Generating install SQL file (version $version)...<br>";
flush();

$create_sql_cmd = sprintf(
    'php %s %s',
    escapeshellarg($full_site_dir . '/public_html/utils/create_install_sql.php'),
    escapeshellarg($version)
);

// AFTER:
$version = $version_major . '.' . $version_minor;
echo "Generating install SQL file (version $version)...<br>";
flush();

$create_sql_cmd = sprintf(
    'php %s %s',
    escapeshellarg($full_site_dir . '/public_html/utils/create_install_sql.php'),
    escapeshellarg($version)
);
```

### Phase 3: Update upgrade.php

**File: `utils/upgrade.php`**

**Line 691:** Remove database_version display
```php
// BEFORE:
echo 'Database Version: '.$settings->get_setting('database_version').'<br>';

// AFTER:
// (remove this line entirely)
```

---

## Summary of Changes

| File | Changes |
|------|---------|
| `migrations/migrations.php` | Add 2 migrations to delete `database_version` and `db_migration_version` |
| `utils/publish_upgrade.php` | Use form-provided version for SQL filename |
| `utils/upgrade.php` | Remove `database_version` display (line 691) |

---

## Success Criteria

- [ ] `database_version` setting removed from database
- [ ] `db_migration_version` setting removed from database
- [ ] `publish_upgrade.php` uses form version (not database setting) for SQL filename
- [ ] Archive and SQL filenames use the same version number
- [ ] `upgrade.php` no longer displays `database_version`
