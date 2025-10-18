# Publish Upgrade System Analysis

## Executive Summary

The `publish_upgrade.php` file is the server-side component that creates and publishes upgrade packages for distribution to other Joinery installations. This comprehensive analysis identifies the current workflow, architectural issues, security concerns, and required improvements.

## Current Workflow

### 1. System Status Check (Lines 14-17)
- Checks if `upgrade_server_active` setting is enabled
- Exits if disabled with a plain text message

### 2. Permission Check (Line 20)
- Verifies user has admin permission level 8 or higher

### 3. Upgrade Publishing Process (Lines 22-99)
**When POST request contains `version_major` and `version_minor`:**

#### Step 1: Input Processing (Lines 24-30)
- Reads `$_REQUEST['version_major']` and `$_REQUEST['version_minor']`
- Constructs filename: `current_upgrade{major}-{minor}.upg.zip`
- Sets output path to `{baseDir}/{site_template}/static_files/`

#### Step 2: Permission Validation (Lines 34-65)
- Checks directory permissions and ownership
- Verifies www-data ownership
- Validates user read/write permissions

#### Step 3: File Collection (Lines 73-80)
- Recursively collects all files from `public_html` directory
- Excludes `.git` and `.gitignore` folders
- Creates zip archive with excluded filenames removed from paths

#### Step 4: Database Recording (Lines 88-94)
- Creates new Upgrade record
- Stores major/minor versions, filename, and release notes
- Saves to database

### 4. Display Form (Lines 101+)
**When no POST request or for initial display:**

1. Lists recent upgrades (10 most recent)
2. Displays form to create new upgrade
3. Auto-suggests version numbers based on latest in database
4. Allows entry of release notes

## Current Architecture

### Entry Point
- **File:** `/var/www/html/joinerytest/public_html/utils/publish_upgrade.php`
- **URL:** `/utils/publish_upgrade`
- **Access:** Requires permission level 8 (admin)
- **Link:** Available in admin settings when `upgrade_server_active` is enabled

### Data Model
- **Class:** `Upgrade` (extends `SystemBase`)
- **Table:** `upg_upgrades`
- **Primary Key:** `upg_upgrade_id` (auto-increment)
- **Fields:**
  - `upg_major_version` (int4) - Major version number
  - `upg_minor_version` (int4) - Minor version number
  - `upg_name` (varchar 64) - Filename of upgrade package
  - `upg_release_notes` (text) - Release notes text
  - `upg_create_time` (timestamp) - Auto-generated timestamp

- **Multi class:** `MultiUpgrade` - For querying multiple upgrades

### Related Settings (Database `stg_settings` table)
- **`upgrade_server_active`** - Boolean (0/1), enables/disables server functionality
- **`upgrade_source`** - URL string, used by client to find server

### Related Files
- **Upgrade receiving:** `/utils/upgrade.php` - Client-side upgrade receiver
- **Settings UI:** `/adm/admin_settings.php` (lines 1025-1031)
- **Data model:** `/data/upgrades_class.php`

## Critical Issues Found

### Issue 1: No Input Validation/Sanitization
**Location:** Lines 24-25, 92
**Severity:** CRITICAL
**Problem:**
```php
$version_major = $_REQUEST['version_major'];
$version_minor = $_REQUEST['version_minor'];
// ... directly used without type checking
$upgrade->set('upg_release_notes', $_REQUEST['release_notes']);
// ... no HTML escaping or sanitization
```
**Impact:**
- Could accept invalid version formats
- SQL injection risk if directly used in queries
- XSS risk when release notes displayed
- Could store malicious content in database

**Required Fix:** Type validation and sanitization

---

### Issue 2: File Path Manipulation Risk
**Location:** Lines 24-30
**Severity:** CRITICAL
**Problem:**
```php
$filename = 'current_upgrade'.$version_major.'-'.$version_minor.'.upg.zip';
$file_output_location = $full_site_dir.'/static_files/'.$filename;
```
**Issues:**
- No path traversal prevention
- Version numbers not validated for special characters
- Could potentially create files outside intended directory
- Vulnerable to directory traversal: `../../../etc/`

**Required Fix:** Strict path validation and integer type-checking

---

### Issue 3: Missing Error Handling & Rollback
**Location:** Lines 69-70, 77-80
**Severity:** CRITICAL
**Problem:**
```php
$file = fopen($file_output_location, 'w') or die("can't open file");
fclose($file);
// No error checking on create_zip result
create_zip(...);
// ... and then database record created regardless
```
**Issues:**
- Uses `die()` instead of proper exception handling
- No rollback if zip creation fails
- Zip may be partially created but file collection fails
- Database record created even if zip fails
- Orphaned files and database inconsistency

**Required Fix:** Structured error handling with transaction safety

---

### Issue 4: Deprecated Direct File Includes
**Location:** Lines 2-6
**Severity:** CRITICAL
**Problem:**
```php
require_once( __DIR__ . '/../includes/Globalvars.php');
require_once( __DIR__ . '/../includes/SessionControl.php');
require_once( __DIR__ . '/../includes/AdminPage.php');
require_once( __DIR__ . '/../includes/LibraryFunctions.php');
require_once( __DIR__ . '/../data/upgrades_class.php');
```
**Issues:**
- Uses direct file paths instead of PathHelper
- Violates codebase patterns (see CLAUDE.md critical rule)
- Inconsistent with other utility files like `upgrade.php`
- Will break if directory structure changes
- Prevents theme/plugin file overrides

**Required Fix:** Use `PathHelper::getIncludePath()` for all includes

---

### Issue 5: No Version Number Validation
**Location:** Lines 27, 79
**Severity:** CRITICAL
**Problem:**
- No validation that version numbers are integers
- Could produce invalid filenames with special characters
- Race condition: version could change between validation and save
- No check for duplicate version numbers

**Required Fix:** Integer validation, unique constraint

---

### Issue 6: FormWriter Not Initialized Per Codebase Pattern
**Location:** Line 130
**Severity:** HIGH
**Problem:**
```php
$formwriter = new FormWriter('form1');
```
**Issues:**
- FormWriter instantiated directly instead of via `$page->getFormWriter()`
- Doesn't use theme-aware approach (see CLAUDE.md patterns)
- Different from all admin pages in codebase
- Won't support theme overrides

**Required Fix:** Use `$page->getFormWriter('form1')` pattern

---

### Issue 7: Wrong Menu ID
**Location:** Line 108
**Severity:** MEDIUM
**Problem:**
```php
'menu-id'=> 'orders-list',  // This is for orders, not upgrades!
```
**Issues:**
- Wrong menu ID causes incorrect menu highlighting
- Should reference upgrade or publish-upgrade menu ID
- Confuses admin navigation

**Required Fix:** Correct menu ID

---

### Issue 8: Wrong Field Names in MultiUpgrade Queries
**Location:** Lines 136, 147
**Severity:** HIGH
**Problem:**
```php
$major = new MultiUpgrade(array(), array('major_version' => 'DESC'));
$minor = new MultiUpgrade(array(), array('minor_version' => 'DESC'));
```
**Issues:**
- Field names are wrong (should be prefixed with 'upg_')
- Database columns are 'upg_major_version' and 'upg_minor_version'
- Sorting likely fails silently, returning unordered results
- Auto-suggested version numbers may be wrong

**Required Fix:** Check `MultiUpgrade::getMultiResults()` for correct option keys

---

## High Priority Issues

### Issue 9: No Transaction Safety
**Location:** Lines 69-94
**Problem:**
- File created, then database record added
- If database write fails, zip file remains orphaned
- If zip fails after record created, database has stale record

**Fix:** Wrap in database transaction or reverse-sequence operations

---

### Issue 10: Unrestricted File Access
**Location:** Lines 29-30
**Problem:**
- Upgrade files stored in public web directory
- Anyone with URL can download full codebase
- No authentication required for downloads
- No rate limiting

**Fix:** Store in non-web-accessible location or require authentication

---

### Issue 11: Direct Output Instead of Logging
**Location:** Lines 77, 98
**Problem:**
- Outputs directly to stdout during zip creation
- No option to suppress or log output
- Progress information clutters page
- Difficult to debug issues

**Fix:** Implement structured logging

---

### Issue 12: Hard-coded Paths
**Location:** Lines 75, 79
**Problem:**
- Uses direct path concatenation
- No use of PathHelper for path resolution
- Inconsistent with architecture

**Fix:** Replace with PathHelper methods

---

## Medium Priority Issues

### Issue 13: Unused Permission Variables
**Location:** Lines 35-53
**Problem:**
- Complex permission bit calculations never used
- Code is hard to maintain
- Could be simplified

**Fix:** Cleanup or refactor permission checking

---

### Issue 14: No Zip Integrity Verification
**Location:** Line 82-85
**Problem:**
- Only checks file existence, not validity
- Corrupted zip would pass validation
- No checksums or integrity verification

**Fix:** Add zip validation after creation

---

### Issue 15: No Audit Logging
**Problem:**
- No record of who published what upgrade and when
- Cannot trace upgrade history
- Security and debugging issues

**Fix:** Add logging to error log with user and timestamp

---

## Database Integration Points

### Download Flow
```
upgrade.php (client instance)
  -> GET /utils/upgrade?serve-upgrade=1
  -> Returns JSON: {system_version, upgrade_name, upgrade_location, release_date, release_notes}
  -> Downloads ZIP from upgrade_location
```

### Data Consistency Issues
- No cascading deletes (upgrade file orphaned if record deleted)
- Version numbers not unique (duplicates allowed)
- No soft-delete support (upgrades deleted are gone forever)
- No status tracking (draft, published, archived)

## Security Concerns Summary

### 1. Unauthenticated File Access
- Upgrade packages in web-accessible directory
- Anyone can download full codebase

### 2. Path Traversal Vulnerability
- Version numbers not validated
- Could escape `static_files` directory

### 3. Input Validation Missing
- Version numbers could contain special characters
- Release notes not sanitized for XSS

### 4. No Audit Trail
- Cannot track who published what
- No version history or rollback capability

### 5. Race Conditions
- Two admins could publish same version simultaneously
- File could be overwritten during zip creation

## Implementation Roadmap

### Phase 1: Critical Security Fixes (2-3 hours)
1. Fix includes to use PathHelper
2. Add input validation for version numbers (integer only)
3. Sanitize release notes (htmlentities)
4. Fix FormWriter initialization
5. Add proper error handling with rollback

### Phase 2: Data Safety (2-3 hours)
1. Implement transaction safety
2. Add duplicate version detection
3. Add file integrity checks
4. Implement logging

### Phase 3: Enhancement (3-4 hours)
1. Move file location to non-web-accessible
2. Improve version management UI
3. Add audit logging
4. Improve API

## Files to Modify

1. **`/var/www/html/joinerytest/public_html/utils/publish_upgrade.php`**
   - Main file with most issues
   - Requires refactoring

2. **`/var/www/html/joinerytest/public_html/data/upgrades_class.php`**
   - Add unique constraint for version pairs
   - May need field specifications update

3. **`/var/www/html/joinerytest/public_html/migrations/migrations.php`**
   - Add schema updates for constraints
   - Add field for audit trail if implemented

4. **`/var/www/html/joinerytest/public_html/adm/admin_settings.php`**
   - No changes needed (already configured correctly)

## Recommendations

### Immediate Actions (Must Do)
1. Fix PathHelper includes in publish_upgrade.php
2. Add integer validation for version numbers
3. Add HTML sanitization for release notes
4. Fix FormWriter initialization
5. Correct menu ID

### Short Term (Should Do)
1. Implement transaction safety
2. Add duplicate version detection
3. Validate MultiUpgrade field names
4. Add error logging

### Medium Term (Nice to Have)
1. Move files to secure location
2. Add version status tracking
3. Implement audit logging
4. Add pre-publish validation

## Related Documentation
- **System Overview:** See CLAUDE.md for architecture
- **Upgrade System Comparison:** See specs/upgrade_system.md for full feature comparison with deploy.sh
- **Admin Pages Guide:** See docs/admin_pages.md for admin page patterns
- **Plugin Developer Guide:** See docs/plugin_developer_guide.md for architecture patterns

