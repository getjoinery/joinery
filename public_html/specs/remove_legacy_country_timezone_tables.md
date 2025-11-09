# Specification: Remove Legacy Country and Timezone Tables

## Overview

Remove two dead/legacy database tables (`country` and `timezone`) and consolidate country reference data to use only `cco_country_codes`. This cleanup simplifies the database schema and eliminates duplicate data sources.

## Current State

### Tables to Delete
1. **`timezone`** table (163,009 rows)
   - Dead table with no code references
   - Not used anywhere in the application
   - Contains historical timezone offset data
   - No foreign keys pointing to it
   - Can be safely deleted

2. **`country`** table (252 rows)
   - Legacy simple country list (country_code, country_name)
   - Only one function uses it: `Address::get_country_drop_array()`
   - Duplicate data exists in `cco_country_codes`
   - No foreign keys pointing to it
   - Can be safely deleted after migration

### Table to Keep and Use
**`cco_country_codes`** table (233 rows) - Complete country reference data
```
Columns:
- cco_country_code_id (primary key, int)
- cco_iso_code_2 (char(2) - ISO 3166-1 alpha-2)
- cco_iso_code_3 (char(3) - ISO 3166-1 alpha-3)
- cco_country (varchar - country name)
- cco_capital_city (varchar - capital)
- cco_code (int - phone code)
```

## Current Usage Analysis

### Functions to Update

1. **`Address::get_country_drop_array()`** (address_class.php:619)
   - **Current:** Returns array keyed by `country_code`, value = `country_name` from `country` table
   - **Usage:** Only defined but NOT USED anywhere in codebase
   - **Action:** DELETE this function (it's unused)

2. **`Address::get_country_drop_array2()`** (address_class.php:639) ✓ Already uses cco_country_codes
   - **Current:** Returns array keyed by `cco_country_code_id`, value = `cco_country`
   - **Usage:** Used at address_class.php:317 for address country dropdown
   - **Action:** RENAME to `get_country_drop_array()` to replace the unused function

3. **`Address::GetCountryAbbrFromCountryCode($country_code_id)`** (address_class.php:182)
   - **Current:** Queries cco_country_codes with `cco_code` (phone code) parameter
   - **Usage:** admin_orders.php:136 - displays country abbreviation
   - **Status:** Already uses cco_country_codes, no changes needed

4. **`Address::GetCountryCodeFromCountryAbbr($country_abbr)`** (address_class.php:164)
   - **Current:** Queries cco_country_codes with `cco_iso_code_2` parameter
   - **Usage:** stripe_charges_synchronize.php:357 - converts billing country to country code
   - **Status:** Already uses cco_country_codes, no changes needed

### Data Models Using cco_country_codes ✓ No Changes Needed
- **Address** (data/address_class.php)
  - Field: `usa_cco_country_code_id` (FK to cco_country_codes.cco_country_code_id)
  - Used in forms and dropdowns

- **PhoneNumber** (data/phone_number_class.php)
  - Field: `phn_cco_country_code_id` (FK to cco_country_codes.cco_country_code_id)
  - Uses `get_country_code_drop_array()` (already queries cco_country_codes)

## Implementation Steps

### Phase 1: Code Cleanup

#### Step 1.1: Delete Unused Function
**File:** `/var/www/html/joinerytest/public_html/data/address_class.php`

DELETE lines 619-637:
```php
static function get_country_drop_array() {
    $dbhelper = DbConnector::get_instance();
    $dblink = $dbhelper->get_db_link();

    $sql = "SELECT * FROM country WHERE TRUE";
    try {
        $q = $dblink->prepare($sql);
        $success = $q->execute();
        $q->setFetchMode(PDO::FETCH_OBJ);
    } catch(PDOException $e) {
        $dbhelper->handle_query_error($e);
    }

    $optionvals = array();
    while ($country = $q->fetch()) {
        $optionvals[$country->country_code] = $country->country_name;
    }
    return $optionvals;
}
```

#### Step 1.2: Rename get_country_drop_array2() to get_country_drop_array()
**File:** `/var/www/html/joinerytest/public_html/data/address_class.php`

RENAME function at line 639 from `get_country_drop_array2()` to `get_country_drop_array()`

Also rename the function call at line 317 (in the form building method) from:
```php
$country_codes = self::get_country_drop_array2();
```
to:
```php
$country_codes = self::get_country_drop_array();
```

#### Step 1.3: Verify No Other References
Search codebase for any remaining references to:
- `get_country_drop_array2` (should find only the definition and one call - both will be renamed)
- `country table` queries (should find none after deletion of get_country_drop_array)

**Expected result:** Zero references to the old function or table

### Phase 2: Database Migration

#### Step 2.1: Create Migration
**File:** `/var/www/html/joinerytest/public_html/migrations/migrations.php`

Add two migrations (in order):

```php
// Migration 1: Drop the timezone table
$migration = array();
$migration['database_version'] = '0.XX';  // Use next version number
$migration['test'] = "SELECT COUNT(*) as count FROM pg_tables WHERE tablename = 'timezone' AND schemaname = 'public'";
$migration['migration_sql'] = 'DROP TABLE IF EXISTS public.timezone CASCADE;';
$migration['migration_file'] = NULL;
$migrations[] = $migration;

// Migration 2: Drop the country table
$migration = array();
$migration['database_version'] = '0.XX';  // Use next version number after above
$migration['test'] = "SELECT COUNT(*) as count FROM pg_tables WHERE tablename = 'country' AND schemaname = 'public'";
$migration['migration_sql'] = 'DROP TABLE IF EXISTS public.country CASCADE;';
$migration['migration_file'] = NULL;
$migrations[] = $migration;
```

**Note:** Assign appropriate version numbers by checking the latest version in migrations.php

#### Step 2.2: No Data Class Changes Needed
- No data class definitions for `country` or `timezone` tables exist
- No field specifications reference these tables
- Database will handle schema deletion via migrations

### Phase 3: Install SQL Update

#### Step 3.1: Update Install SQL Generation Spec
**File:** `/var/www/html/joinerytest/public_html/specs/install_sql_generator.md`

Update the essential tables list to:
```php
$essential_data_tables = [
    'stg_settings',
    'amu_admin_menus',
    'cco_country_codes',
    'zone',
    'ety_event_types',
    'emt_email_templates',
    'pmu_public_menus',
    'upg_upgrades'
];
```

Remove `country` table from the include list. The `timezone` table is already excluded.

## Testing Requirements

### Code Testing
1. **Syntax check:** Run `php -l address_class.php` to verify no syntax errors after edits
2. **Method existence test:** Run method_existence_test.php on address_class.php to verify no broken method calls
3. **Search verification:** Grep entire codebase for:
   - `get_country_drop_array2` - should find ZERO results
   - `FROM country` - should find ZERO results
   - `FROM timezone` - should find ZERO results

### Database Testing
1. **Migration test:** Run `update_database` and verify migrations apply successfully
2. **Table existence:** After migration, verify both tables are gone:
   ```bash
   psql -U postgres -d joinerytest -c "\dt country timezone"
   # Should show: "Did not find any relation"
   ```
3. **Data integrity:** Verify cco_country_codes table still has all data:
   ```bash
   psql -U postgres -d joinerytest -c "SELECT COUNT(*) FROM cco_country_codes;"
   # Should show: 233
   ```

### Functional Testing
1. **Address country dropdown:** Create/edit address and verify country dropdown works correctly
2. **Phone number country dropdown:** Create/edit phone number and verify country code dropdown works
3. **Admin orders display:** Verify order display still shows country abbreviations correctly
4. **Stripe webhook:** If webhook is triggered, verify country code parsing still works

## Impact Analysis

### What Changes
- `country` table removed from database
- `timezone` table removed from database
- `Address::get_country_drop_array()` function renamed/consolidated
- Install SQL will be smaller (no dead data)

### What Does NOT Change
- No breaking changes to API or data models
- No changes to user-facing functionality
- Address and PhoneNumber data classes remain unchanged
- Foreign keys and constraints remain unchanged
- All dropdown functionality works identically

### Data Loss
- Minimal: Only removes duplicate/dead data
- `cco_country_codes` already contains all needed country information
- No active user data is affected

## Success Criteria

✓ Code changes applied without syntax errors
✓ All references to old functions/tables removed from codebase
✓ Migrations successfully remove both tables from database
✓ All forms and dropdowns continue working correctly
✓ No broken method calls or references remain
✓ Install SQL generation updated and tested
✓ All tests pass

## Notes

- This is a safe refactoring with no breaking changes
- `cco_country_codes` table already contains all necessary data
- Address and PhoneNumber models already use cco_country_codes
- The only change visible is the consolidation of get_country_drop_array functions
- Can be deployed immediately after testing

## Timeline

1. **Phase 1 (Code):** ~15 minutes
2. **Phase 2 (Migrations):** ~5 minutes
3. **Phase 3 (Install SQL):** ~5 minutes
4. **Testing:** ~15 minutes
5. **Total:** ~40 minutes

## Rollback Plan

If issues arise:
1. Revert code changes in address_class.php
2. Restore `country` and `timezone` tables from database backup
3. Revert migrations (they won't run if table detection fails)

The changes are minimal and isolated, making rollback straightforward.
