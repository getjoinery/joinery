# Fix Publish Upgrade System Specification

## Overview
The publish_upgrade.php system is a **superadmin-only tool** (permission level 8+) for creating and distributing system upgrade packages. This specification focuses on fixing code quality issues, reliability problems, and broken functionality.

## Context
- **Access:** Requires permission level 8 (superadmin)
- **Users:** Trusted administrators with full system access
- **Focus:** Code quality, reliability, and maintainability over security hardening

## Required Fixes

### 1. Fix File Include Pattern
**Problem:** Violates project architecture by using direct paths instead of PathHelper

**Current (WRONG):**
```php
require_once(__DIR__ . '/../includes/Globalvars.php');
require_once(__DIR__ . '/../includes/SessionControl.php');
require_once(__DIR__ . '/../includes/AdminPage.php');
require_once(__DIR__ . '/../includes/LibraryFunctions.php');
require_once(__DIR__ . '/../data/upgrades_class.php');
```

**Required (CORRECT):**
```php
// PathHelper, Globalvars, SessionControl are pre-loaded - remove those requires
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/upgrades_class.php'));
```

### 2. Fix FormWriter Initialization
**Problem:** Not using theme-aware pattern

**Current (WRONG):**
```php
$formwriter = new FormWriter('form1');
```

**Required (CORRECT):**
```php
$formwriter = $page->getFormWriter('form1');
```

### 3. Fix Menu ID
**Problem:** Using wrong menu ID ('orders-list')

**Current (Line 108):**
```php
'menu-id' => 'orders-list',
```

**Required:**
```php
'menu-id' => 'settings',  // or appropriate admin menu ID
```

### 4. Add Cleanup for Partial Files
**Problem:** When zip creation fails, partial files are left on disk

**Add cleanup on failure (around lines 82-85):**
```php
// CURRENT:
if(!file_exists($file_output_location)){
    echo "Failed to write the zip file: $file_output_location...aborting.<br>";
    exit;
}

// CHANGE TO:
if(!file_exists($file_output_location) || filesize($file_output_location) == 0){
    // Clean up empty/partial file
    if(file_exists($file_output_location)) {
        @unlink($file_output_location);
    }
    echo "Failed to write the zip file: $file_output_location...aborting.<br>";
    exit;
}
```

**Also check zip creation result (around line 80):**
```php
// CURRENT:
create_zip($files_list, $file_output_location, $exclude_filenames, $remove_relative_path, true);

// CHANGE TO:
$zip_result = create_zip($files_list, $file_output_location, $exclude_filenames, $remove_relative_path, true);
if(!$zip_result) {
    // Clean up failed file
    if(file_exists($file_output_location)) {
        @unlink($file_output_location);
    }
    echo "Failed to create zip file<br>";
    exit;
}
```

### 5. Update create_zip Function
**Problem:** Uses exit() instead of returning errors

**Update create_zip() to:**
- Return false on errors instead of calling exit()
- Throw exceptions for error handling
- Remove debug echo statements or make them optional

### 6. Prevent Overwriting Existing Versions
**Problem:** Overwriting old packages could break client downloads

Add simple check in `publish_upgrade.php` (after line 25):
```php
// Check if this version already exists in the database
$existing = new MultiUpgrade(
    array('major_version' => $version_major, 'minor_version' => $version_minor),
    array(),
    1
);
$existing->load();

if ($existing->count() > 0) {
    echo "Version {$version_major}.{$version_minor} already exists. Please use a different version number.<br>";
    exit;
}
```

**Note:** This prevents accidental overwriting of published packages. To republish, increment the minor version.

## Implementation Checklist

- [x] Fix include statements to use PathHelper
- [x] Fix FormWriter initialization
- [x] Fix menu ID
- [x] Add cleanup for partial zip files on failure
- [x] Update create_zip to return false on errors
- [x] Prevent overwriting existing versions

## Success Criteria
1. Code follows project patterns (PathHelper, FormWriter)
2. Partial/failed zip files are cleaned up automatically
3. No orphaned files left on disk after failures
4. Existing versions cannot be overwritten (must increment version)

## Files to Modify
1. `/utils/publish_upgrade.php` - Main implementation

## Notes
- This is an internal tool for trusted superadmins
- Focus is on reliability and code quality, not security hardening
- XSS protection and complex validation are not required
- Audit logging is not necessary for this internal tool