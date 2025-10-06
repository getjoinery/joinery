# Joinery Validation System Specification

**STATUS: Phase 3 Core Migration Complete - Ready for Testing**
**NEXT: Test templates and forms, then final cleanup**

**Phase 3 Migration:** Migrate all existing forms to use Joinery validation. This involves:
1. Updating the `set_validate()` method to output Joinery validation JavaScript
2. Updating 3 template files to include joinery-validate.js instead of jquery.validate.js
3. Cleaning field names in 5 form files (remove quotes/brackets from validation rules)

**KEY INSIGHT:** We don't need to add JavaScript to every file - just update the ONE `set_validate()` method! See Phase 3 section for complete strategy.

## Summary
Create a vanilla JavaScript validation library that replaces jQuery Validation plugin. The implementation provides a standalone validation system with clean, modern JavaScript APIs.

## The Problem
- 61 PHP files call `set_validate()` which outputs jQuery validation JavaScript
- Removing jQuery means these forms break

## The Solution
Create `joinery-validate.js` as a pure JavaScript validation library with NO jQuery dependencies. This file will be included in page templates, replacing the existing jQuery validation script includes. The FormWriter `set_validate()` method will be updated to output Joinery validation JavaScript instead of jQuery validation JavaScript.

## Current Setup
jQuery validation is currently included in 3 template files:
1. `/includes/AdminPage-uikit3.php` - Local file: `/assets/js/jquery.validate-1.9.1.js`
2. `/includes/PublicPageFalcon.php` - CDN: `https://cdn.jsdelivr.net/npm/jquery-validation@1.21.0/dist/jquery.validate.min.js`
3. `/includes/PublicPageTailwind.php` - CDN: `https://cdn.jsdelivr.net/npm/jquery-validation@1.21.0/dist/jquery.validate.min.js`

## Implementation

### Phase 1 & 2: Pure JavaScript Validation (COMPLETED)

**Created:**
- `/assets/js/joinery-validate.js` - Pure JavaScript validation library (NO jQuery, ~15KB)
- `/utils/forms_example_bootstrap_native.php` - Comprehensive test form with all field types
- Updated `/includes/RouteHelper.php` - `.php` URLs return 404 (not silent redirect)

**Key Features:**
- Auto-detects bracket notation: `interval` finds `interval[]`
- Built-in validators: required, email, url, number, minlength, maxlength, min, max, equalTo, time, date
- AJAX validation with configurable field names (`dataFieldName` parameter)
- Bootstrap 5 classes: `is-invalid`, `is-valid`, `invalid-feedback`
- Parameter substitution in error messages (`{0}` → actual value)
- Custom validators: `JoineryValidator.addValidator(name, method, message)`

**Important for Phase 3:**
- Validation works by calling `JoineryValidation.init(formId, options)` in JavaScript
- Test file uses manual initialization - Phase 3 will update `set_validate()` to output this automatically
- Clean field names required in validation rules (no quotes/brackets)

## Phase 3: System-Wide Migration (NOT YET STARTED)

**Goal:** Migrate all forms using jQuery validation to pure Joinery validation system.

### Migration Strategy

**KEY INSIGHT:** The `set_validate()` method already outputs validation JavaScript - we just need to update it to output Joinery validation instead of jQuery validation!

#### Step 1: Update FormWriter set_validate() Method

**File: `/includes/FormWriterBase.php`**

**IMPORTANT:** A working version of the updated `set_validate()` method already exists and has been tested. Simply use the existing implementation as-is without modification.

The updated method:
- Outputs Joinery validation JavaScript instead of jQuery validation
- Maintains full compatibility with existing `set_validate()` calls
- Preserves all existing features (custom validators, debug mode, custom JS)
- Calls `JoineryValidation.init()` with the form ID and validation options

**Benefits of this approach:**
- ✅ No changes needed to individual form files (except cleaning field names)
- ✅ `set_validate()` continues to work exactly as before
- ✅ All 61 files automatically get Joinery validation
- ✅ No code duplication

#### Step 2: Template File Updates

Replace jQuery validation includes with Joinery validation in all template files:

**File: `/includes/AdminPage-uikit3.php`**
```php
// REMOVE (lines 79 and 100):
<script type="text/javascript" src="/assets/js/jquery.validate-1.9.1.js"></script>

// ADD:
<script src="/assets/js/joinery-validate.js"></script>
```

**File: `/includes/PublicPageFalcon.php`**
```php
// REMOVE (line 596):
<script src="https://cdn.jsdelivr.net/npm/jquery-validation@1.21.0/dist/jquery.validate.min.js"></script>

// ADD:
<script src="/assets/js/joinery-validate.js"></script>
```

**File: `/includes/PublicPageTailwind.php`**
```php
// REMOVE (line 185):
<script src="https://cdn.jsdelivr.net/npm/jquery-validation@1.21.0/dist/jquery.validate.min.js"></script>

// ADD:
<script src="/assets/js/joinery-validate.js"></script>
```

**Note:** Keep jQuery include if present - only remove jQuery validation plugin

#### Step 3: Clean Up Field Names in Form Files

**Only 5 files need changes (not 61):**

1. `/adm/admin_coupon_code_edit.php`
   - Line 121: `$validation_rules['"event_list[]"']` → `$validation_rules['event_list']`

2. `/adm/admin_event_bundle_edit.php`
   - Line 59: `$validation_rules['"event_list[]"']` → `$validation_rules['event_list']`

3. `/utils/forms_example_bootstrap.php`
   - Line 30: `$validation_rules['"products_list[]"']` → `$validation_rules['products_list']`
   - Line 32: `$validation_rules['"interval[]"']` → `$validation_rules['interval']`

4. `/utils/forms_example_uikit.php`
   - Line 32: `$validation_rules['"products_list[]"']` → `$validation_rules['products_list']`
   - Line 34: `$validation_rules['"interval[]"']` → `$validation_rules['interval']`

5. `/utils/forms_example_tailwind.php`
   - Line 30: `$validation_rules['"products_list[]"']` → `$validation_rules['products_list']`
   - Line 32: `$validation_rules['"interval[]"']` → `$validation_rules['interval']`

#### Step 4: Cleanup After Full Migration

**4.1 Remove Old jQuery Validation Files**
```bash
# Only after ALL forms are migrated and tested:
rm /var/www/html/joinerytest/public_html/assets/js/jquery.validate-1.9.1.js
```

**4.2 Documentation Updates**
- Update developer documentation to reference Joinery validation
- Update CLAUDE.md with new validation patterns

### Migration Checklist

**Pre-Migration:**
- [x] Test Phase 2 implementation thoroughly (`/utils/forms_example_bootstrap_native.php`)
- [x] Backup `/includes/FormWriterBase.php` before modifying
- [ ] Identify forms with AJAX validation (need URL updates)

**Step 1 - Update FormWriter:**
- [x] Update `set_validate()` method in `/includes/FormWriterBase.php` (lines 356-436)
- [x] Test with `/utils/forms_example_bootstrap_native.php`
- [x] Verify JavaScript output is valid

**Step 2 - Template Updates:**
- [x] Update `/includes/AdminPage-uikit3.php` (replace jQuery validation with Joinery)
- [x] Update `/includes/PublicPageFalcon.php` (replace jQuery validation with Joinery)
- [x] Update `/includes/PublicPageTailwind.php` (replace jQuery validation with Joinery)
- [ ] Test each template renders correctly

**Step 3 - Form Field Name Cleanup:**
- [x] Update 5 files with quoted bracket notation (see Step 3 section for list)
- [ ] Test updated forms

**Post-Migration:**
- [ ] Remove `/assets/js/jquery.validate-1.9.1.js`
- [ ] Update documentation
- [ ] Move this spec to `/specs/implemented/`

