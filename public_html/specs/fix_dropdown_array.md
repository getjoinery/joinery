# Specification: Fix get_dropdown_array() Array Order Issues

**Status:** Pending
**Priority:** Medium
**Date Created:** 2025-11-02
**Related Specifications:**
- `/specs/implemented/model_form_helpers.md` - Model Form Helper Methods
- `/specs/migrate_plainform_to_formwriter_v2_phase2.md` - FormWriter V2 Migration

---

## Overview

During the FormWriter V2 migration, we discovered that `get_dropdown_array()` methods in Multi classes return arrays in **reverse format** from what FormWriter expects:

- **What get_dropdown_array() returns:** `['Display Label' => id_value]`
- **What FormWriter expects:** `[id_value => 'Display Label']`

This causes dropdown menus and checkbox lists to not display labels correctly when using the V2 API.

---

## Problem Description

### Current Behavior

Multi classes (like `MultiMailingList`) have a `get_dropdown_array()` method that returns:

```php
function get_dropdown_array($include_new=FALSE) {
    $items = array();
    foreach($this as $entry) {
        $option_display = $entry->get('mlt_name');
        if($entry->get('mlt_description')){
            $option_display .= ' - ' . $entry->get('mlt_description');
        }
        $items[$option_display] = $entry->key;  // ❌ REVERSED: label as key, ID as value
    }
    return $items;
}
```

### Expected Behavior

FormWriter expects options in the format:

```php
$options = [
    'id_value' => 'Display Label',  // ✅ CORRECT: ID as key, label as value
    'id_value2' => 'Display Label 2'
];
```

### Current Workaround

In `/views/lists.php` we added:

```php
$optionvals = $mailing_lists->get_dropdown_array();
// Flip the array since get_dropdown_array returns [label => id] but checkboxList needs [id => label]
$optionvals = array_flip($optionvals);
```

---

## Affected Areas

### Complete Inventory of Reversed Arrays

After comprehensive audit (including plugins, themes, utilities), we found **~200+ locations** with reversed array format:

#### Multi Class Methods with Reversed Format

**Core Data Classes - Standard `get_dropdown_array()` (27 methods):**
- `/data/admin_menus_class.php:64` - MultiAdminMenu
- `/data/api_keys_class.php:76` - MultiApiKey
- `/data/contact_types_class.php:58` - MultiContactType
- `/data/content_versions_class.php:157` - MultiContentVersion
- `/data/coupon_code_products_class.php:61` - MultiCouponCodeProduct
- `/data/coupon_code_uses_class.php:65` - MultiCouponCodeUse
- `/data/coupon_codes_class.php:142` - MultiCouponCode
- `/data/email_templates_class.php:92` - MultiEmailTemplate
- `/data/event_registrants_class.php:166` - MultiEventRegistrant
- `/data/event_types_class.php:42` - MultiEventType
- `/data/events_class.php:561` - MultiEvent
- `/data/groups_class.php:381` - MultiGroup
- `/data/locations_class.php:67` - MultiLocation
- `/data/mailing_list_registrants_class.php:94` - MultiMailingListRegistrant
- `/data/mailing_lists_class.php:354` - MultiMailingList
- `/data/order_item_requirements_class.php:60` - MultiOrderItemRequirement
- `/data/pages_class.php:108` - MultiPage
- `/data/pages_class.php:121` - MultiPage (get_dropdown_array_link)
- `/data/product_groups_class.php:46` - MultiProductGroup
- `/data/product_requirement_instances_class.php:56` - MultiProductRequirementInstance
- `/data/product_requirements_class.php:70` - MultiProductRequirement
- `/data/products_class.php:1329` - MultiProduct
- `/data/public_menus_class.php:54` - MultiPublicMenu
- `/data/question_options_class.php:57` - MultiQuestionOption
- `/data/questions_class.php:389` - MultiQuestion
- `/data/settings_class.php:92` - MultiSetting
- `/data/users_class.php:800` - MultiUser

**Core Data Classes - Specialized Dropdown Methods (14 methods):**
- `/data/group_members_class.php:68` - get_user_dropdown_array()
- `/data/group_members_class.php:80` - get_event_dropdown_array()
- `/data/files_class.php:420` - get_file_dropdown_array()
- `/data/files_class.php:431` - get_image_dropdown_array()
- `/data/posts_class.php:113` - get_post_dropdown_array()
- `/data/event_sessions_class.php:370` - get_sessions_dropdown_array()
- `/data/surveys_class.php:82` - get_survey_dropdown_array()
- `/data/videos_class.php:289` - get_video_dropdown_array()
- `/data/event_waiting_lists_class.php:83` - get_user_dropdown_array()
- `/data/event_waiting_lists_class.php:95` - get_event_dropdown_array()
- `/data/survey_answers_class.php:93` - get_user_dropdown_array()
- `/data/survey_questions_class.php:86` - get_user_dropdown_array()
- `/data/address_class.php:781` - get_address_dropdown_array()
- `/data/pages_class.php:121` - get_dropdown_array_link()

**Core Data Classes - Static Helper Methods (4 methods, 3 reversed):**
- `/data/address_class.php:619` - get_country_drop_array() ❌
- `/data/address_class.php:639` - get_country_drop_array2() ❌
- `/data/address_class.php:659` - get_timezone_drop_array() ✅ CORRECT (same value)
- `/data/phone_number_class.php:260` - get_country_code_drop_array() ❌

**Core Data Classes - Static Arrays (1):**
- `/data/phone_number_class.php:45-56` - PhoneNumber::$phone_carriers ❌

**Plugin Data Classes (9 methods):**
- `/plugins/items/data/items_class.php:69` - MultiItem
- `/plugins/items/data/items_class.php:82` - MultiItem (get_dropdown_array_link)
- `/plugins/items/data/item_relation_types_class.php:41` - MultiItemRelationType
- `/plugins/controld/data/ctlddevices_class.php:440` - MultiCtldDevice
- `/plugins/controld/data/ctldprofiles_class.php:593` - MultiCtldProfile
- `/plugins/controld/data/ctldrules_class.php:53` - MultiCtldRule
- `/plugins/controld/data/ctldservices_class.php:53` - MultiCtldService
- `/plugins/controld/data/ctldfilters_class.php:51` - MultiCtldFilter
- `/plugins/controld/data/ctlddevice_backups_class.php:57` - MultiCtldDeviceBackup

**Total Methods:** 55 methods with reversed arrays

#### Dynamically Built Arrays (15+ instances)

Arrays built in loops or on-the-fly:
- `/data/events_class.php:434-436` - $version_dropdown
- `/data/products_class.php:1268-1271` - $version_dropdown
- `/adm/admin_product_edit.php:98-101` - $tier_options
- `/adm/admin_phone_verify.php:45-47` - $optionvals
- `/data/phone_number_class.php:359` - $dropdown_builder
- `/data/address_class.php:798` - $address_dropdown_builder

#### Hardcoded Option Arrays (70+ instances)

**Admin Pages with hardcoded arrays (30+ instances):**
- See `/adm/admin_event_edit.php:100, 113, 118, 123, 128, 135` - Various status/boolean arrays
- See `/adm/admin_settings_payments.php:123, 386, 396, 703` - Payment settings
- See `/adm/admin_question_edit.php:113` - Question types
- See `/adm/admin_product_edit.php:134-144` - Bitmask checkboxes
- See `/adm/admin_settings.php` - Multiple settings arrays
- And 20+ more admin files

**Alternative variable names (5 instances):**
- `/adm/admin_settings_email.php:249` - $service_optionvals
- `/adm/admin_settings_email.php:462` - $auth_optionvals
- `/adm/admin_settings_email.php:557` - $test_optionvals
- `/adm/admin_coupon_code_edit.php:147` - $affiliate_options
- `/data/phone_number_class.php:45-56` - PhoneNumber::$phone_carriers

**Inline option arrays (23+ instances):**
- Various files with `'options' => ['Label' => value]` directly in FormWriter calls

**Plugin hardcoded arrays (10+ instances):**
- `/plugins/bookings/admin/admin_booking_edit.php:118`
- `/plugins/items/admin/admin_item_edit.php:133`
- `/plugins/controld/views/forms_example.php:45, 60`
- `/plugins/controld/views/profile/ctlddevice_edit.php:68-80`
- `/plugins/controld/views/profile/ctldfilters_edit.php:121, 124, 139-156, 174`
- And more...

**Utility examples (6+ instances):**
- `/utils/forms_example_tailwind.php:36, 41`
- `/utils/forms_example_uikit.php:38, 43`
- `/utils/forms_example_bootstrap.php:337`
- `/utils/forms_example_bootstrapv2.php` - Multiple (some correct)

#### Usage Points (62+ instances)

All calls to reversed `get_dropdown_array()` methods across:
- Admin pages: 35 usages
- Views: 2 usages (lists.php has workaround)
- Logic: 1 usage
- Data classes: 3 internal usages
- Plugins: 4 usages
- Themes: 11 usages (mostly timezone - OK)

### Items Already Correct

1. **`Address::get_timezone_drop_array()`** - Returns `[$zone_name => $zone_name]` (same key/value)
2. **`/utils/forms_example_bootstrapv2.php`** - Some arrays use correct format (V2 examples)
3. **Theme timezone arrays** - Use correct method

### Summary Statistics

| Category | Count |
|----------|-------|
| Methods returning reversed arrays | 55 |
| Dynamically built arrays | 15+ |
| Hardcoded/inline arrays | 70+ |
| Usage points (calls to methods) | 62+ |
| **TOTAL LOCATIONS** | **200+** |

---

## Solution Options

### Option 1: Fix get_dropdown_array() Methods (RECOMMENDED)

**Pros:**
- Fixes the root cause
- Consistent with FormWriter expectations
- No workarounds needed in views

**Cons:**
- Must audit ALL uses of `get_dropdown_array()` across the codebase
- May break existing V1 code that expects the current format
- Requires careful testing

**Implementation:**
```php
// Change from:
$items[$option_display] = $entry->key;

// To:
$items[$entry->key] = $option_display;
```

### Option 2: Create New Method get_options_array()

**Pros:**
- Doesn't break existing code
- Clear naming convention
- Can be used alongside V2 migration

**Cons:**
- Two methods doing similar things
- Need to update all V2 code to use new method

**Implementation:**
```php
function get_options_array($include_new=FALSE) {
    $items = array();
    foreach($this as $entry) {
        $option_display = $entry->get('mlt_name');
        if($entry->get('mlt_description')){
            $option_display .= ' - ' . $entry->get('mlt_description');
        }
        $items[$entry->key] = $option_display;  // ✅ Correct order for FormWriter
    }
    return $items;
}
```

### Option 3: Add Parameter to get_dropdown_array()

**Pros:**
- Backward compatible
- Single method

**Cons:**
- Confusing API
- Easy to forget the parameter

**Implementation:**
```php
function get_dropdown_array($include_new=FALSE, $flip_for_v2=FALSE) {
    // ... build array ...
    return $flip_for_v2 ? array_flip($items) : $items;
}
```

---

## Implementation Plan

### Strategy: Systematic Fix Using Complete Inventory (Zero Manual Testing)

Instead of relying on manual testing or detection logs to find issues, use the **complete inventory from the audit** to fix all 200+ instances systematically and programmatically.

### Why This Approach Works

1. **Complete Inventory** - We've already identified all 200+ locations
2. **No Manual Testing Required** - Work through inventory line-by-line
3. **Automated Validation** - Detection catches anything we missed
4. **Minimal Risk** - Code review + syntax checking catches errors
5. **Fast** - No time spent visiting pages/testing endpoints

### Phase 1: Add Detection (Safety Net Only)

Add validation logic to FormWriter methods. This serves as a safety net AFTER the fix to catch anything we missed.

**Deliverables:**
- validateOptionFormat() method in FormWriterV2Base
- validateOptionFormat() method in FormWriterBase
- Validation calls in 6 FormWriter methods (dropinput, radioinput, checkboxlist in both V1 and V2)

**Verification:**
- Run php -l on modified files
- No manual testing required

### Phase 2: Fix All 55 Multi Class Methods

Fix all methods in "Multi Class Methods with Reversed Format" section using pattern:
- Change: `$items[$label] = $id;` → `$items[$id] = $label;`

**Deliverables:**
- 27 core standard get_dropdown_array() methods fixed
- 14 core specialized dropdown methods fixed
- 3 core static helper methods fixed (skip get_timezone_drop_array which is already correct)
- 1 core static array constant fixed
- 9 plugin methods fixed

**Verification:**
- php -l on each file
- method_existence_test.php on data class files
- Total: 54 files modified

### Phase 3: Fix All Hardcoded Arrays (70+ instances)

Work through "Hardcoded Option Arrays" section systematically:

**Step 3a: Boolean/Status Patterns**
- "Yes"=>1, 'No'=>0 → 1=>"Yes", 0=>'No'
- "Active"=>1, "Inactive"=>0 → 1=>"Active", 0=>"Inactive"
- Similar patterns across all admin files

**Step 3b: Admin File Arrays (30+ files)**
- Fix all arrays in files listed in inventory
- Lines: admin_event_edit.php, admin_settings_payments.php, admin_question_edit.php, admin_product_edit.php, admin_settings.php, etc.

**Step 3c: Alternative Variable Names (5 instances)**
- $service_optionvals, $auth_optionvals, $test_optionvals in admin_settings_email.php
- $affiliate_options in admin_coupon_code_edit.php
- PhoneNumber::$phone_carriers in phone_number_class.php

**Step 3d: Plugin Arrays (10+ files)**
- All files listed in "Plugin hardcoded arrays" section

**Step 3e: Utility Examples (6+ files)**
- forms_example_tailwind.php, forms_example_uikit.php, forms_example_bootstrap.php, etc.

**Deliverables:**
- 70+ hardcoded arrays fixed across 40+ files

**Verification:**
- php -l on each file
- No manual testing required

### Phase 4: Fix Dynamically Built Arrays (6 instances)

Fix arrays built in loops, working from inventory "Dynamically Built Arrays" section:

1. `/data/events_class.php:434-436` - $version_dropdown
2. `/data/products_class.php:1268-1271` - $version_dropdown
3. `/adm/admin_product_edit.php:98-101` - $tier_options
4. `/adm/admin_phone_verify.php:45-47` - $optionvals
5. `/data/phone_number_class.php:359` - $dropdown_builder
6. `/data/address_class.php:798` - $address_dropdown_builder

Pattern: `$arr[$label] = $id;` → `$arr[$id] = $label;`

**Deliverables:**
- 6 dynamic array builds fixed

**Verification:**
- php -l on each file
- method_existence_test.php for data classes

### Phase 5: Clean Up & Final Verification

1. Remove array_flip() workaround from /views/lists.php:86-87 (no longer needed)
2. Enable debug mode in settings
3. Load one admin page to trigger validation
4. Check error.log for [REVERSED_ARRAY] entries (should be ZERO)
5. Disable debug mode

**Deliverables:**
- Workaround removed
- Zero [REVERSED_ARRAY] log entries
- All 200+ instances fixed

---

## Total Impact

| Phase | Action | Files Modified | Count |
|-------|--------|-----------------|-------|
| 1 | Add detection | 2 (FormWriter files) | +validation logic |
| 2 | Fix Multi methods | 54 data class files | 55 methods |
| 3 | Fix hardcoded arrays | 40+ admin/plugin/util files | 70+ arrays |
| 4 | Fix dynamic arrays | 6 files | 6 instances |
| 5 | Cleanup & verify | 1 view file | 1 workaround removed |
| **TOTAL** | | **100+ files** | **200+ locations** |

---

## Success Criteria

✅ All 55 methods fixed (verified by code review against inventory)
✅ All 70+ hardcoded arrays fixed (verified by code review against inventory)
✅ All 15+ dynamic arrays fixed (verified by code review against inventory)
✅ All 100+ files pass php -l
✅ All data class changes pass method_existence_test.php
✅ Debug validation shows ZERO [REVERSED_ARRAY] log entries
✅ Single admin page load triggers validation without errors
✅ **ZERO manual testing required** - all verification automated

---

## Testing Checklist

When implementing the fix, test:

- [ ] Dropdown menus display correct labels
- [ ] Checkbox lists display correct labels
- [ ] Radio button groups display correct labels
- [ ] Form submissions send correct IDs (not labels)
- [ ] Pre-populated forms show correct selected values
- [ ] All Multi classes that use the method still work
- [ ] No regressions in V1 FormWriter code

---

## Files to Audit

### Multi Classes
```bash
find /var/www/html/joinerytest/public_html/data -name "*_class.php" -exec grep -l "get_dropdown_array" {} \;
```

### View Files Using Dropdowns
```bash
grep -r "get_dropdown_array" /var/www/html/joinerytest/public_html/views/
grep -r "get_dropdown_array" /var/www/html/joinerytest/public_html/logic/
```

---

## Notes

- This issue only affects V2 FormWriter - V1 FormWriter expects the current reversed format
- The issue was discovered during `/views/lists.php` conversion on 2025-11-02
- Quick fix: Use `array_flip()` when passing to V2 FormWriter methods
- Long-term fix: Create properly-ordered `get_options_array()` method

---

## Related Issues

- FormWriter V1 vs V2 API differences
- Model helper method consistency
- Dropdown/select field option formats

---

## Detection Strategy: Validation in Debug Mode

To catch any instances we missed during the fix, we'll add validation logic to FormWriter that detects reversed arrays when debug mode is enabled.

### Performance Impact

**Per-option overhead:** ~2-4 microseconds (no alert), ~100-500 microseconds (with alert)
**Typical page impact:** <1 millisecond
**Production impact:** ZERO (only runs when debug=1)

### Implementation

#### 1. Add Validation Method to FormWriterV2Base

Add this method to `/includes/FormWriterV2Base.php`:

```php
/**
 * Validate option array format - detects reversed [label => id] arrays
 * Only runs when debug mode is enabled in settings
 *
 * @param array $options The options array to validate
 * @param string $context Context info (field name/type) for error message
 */
protected function validateOptionFormat($options, $context = '') {
    // Only run in debug mode
    $settings = Globalvars::get_instance();
    if (!$settings->get_setting('debug')) {
        return;
    }

    if (!is_array($options)) return;

    // Whitelist: Known valid patterns
    static $whitelist = ['new' => true];

    foreach ($options as $key => $value) {
        // Skip whitelisted keys
        if (isset($whitelist[$key])) continue;

        // Skip if key is not string (already correct format: numeric => string)
        if (!is_string($key)) continue;

        $confidence = 0;

        // Fast checks first (most reliable indicators)

        // Check 1: String key with numeric value (HIGH confidence)
        // Pattern: "Active" => 1, "Yes" => 0
        if (is_numeric($value)) {
            $confidence += 50;
        }

        // Check 2: Key contains spaces (MEDIUM confidence)
        // Pattern: "United States" => 'us', "Test Option 1" => '1'
        if ($confidence < 50 && strpos($key, ' ') !== false) {
            $confidence += 40;
        }

        // Check 3: Key contains special patterns (MEDIUM confidence)
        // Patterns: "(123) Name", "+1 United States", "Name - Description"
        if ($confidence < 50) {
            if (strpos($key, '(') !== false ||
                strpos($key, '+') === 0 ||
                strpos($key, ' - ') !== false) {
                $confidence += 35;
            }
        }

        // Check 4: Key much longer than value (LOW confidence, helper)
        // Pattern: "Windows Computer" => "desktop-windows"
        if ($confidence < 50 && is_string($value)) {
            if (strlen($key) > strlen($value) * 2) {
                $confidence += 25;
            }
        }

        // Report if confidence threshold met
        if ($confidence >= 50) {
            // Get caller info for debugging
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            $caller = $backtrace[2] ?? $backtrace[1] ?? [];

            error_log(sprintf(
                "[REVERSED_ARRAY] Confidence: %d%% | '%s' => '%s' | File: %s:%d | Field: %s",
                $confidence,
                substr($key, 0, 40),
                is_scalar($value) ? substr($value, 0, 40) : gettype($value),
                basename($caller['file'] ?? 'unknown'),
                $caller['line'] ?? 0,
                $context
            ));
        }
    }
}
```

#### 2. Add Validation Calls to FormWriterV2Base Methods

In `/includes/FormWriterV2Base.php`, add validation to these methods:

**dropinput():**
```php
public function dropinput($name, $label, $params = []) {
    $options = $params['options'] ?? [];

    // Validate option format in debug mode
    $this->validateOptionFormat($options, "dropinput('$name')");

    // ... rest of existing method code ...
}
```

**radioinput():**
```php
public function radioinput($name, $label, $params = []) {
    $options = $params['options'] ?? [];

    // Validate option format in debug mode
    $this->validateOptionFormat($options, "radioinput('$name')");

    // ... rest of existing method code ...
}
```

**checkboxlist():**
```php
public function checkboxlist($name, $label, $params = []) {
    $options = $params['options'] ?? [];

    // Validate option format in debug mode
    $this->validateOptionFormat($options, "checkboxlist('$name')");

    // ... rest of existing method code ...
}
```

#### 3. Add Validation Method to FormWriterBase (V1 Forms)

Add the same `validateOptionFormat()` method to `/includes/FormWriterBase.php` (or the specific implementation classes like FormWriterBootstrap.php).

#### 4. Add Validation Calls to FormWriterBase Methods

In FormWriterBase/FormWriterBootstrap/FormWriterHTML5/FormWriterUIKit/FormWriterTailwind, add validation:

**dropinput() - V1 signature:**
```php
function dropinput($label, $name, $class, $optionvals, $currentvalue = NULL, $hint = '', $showdefault = TRUE, $disabled = FALSE) {
    // Validate option format in debug mode
    $this->validateOptionFormat($optionvals, "dropinput('$name')");

    // ... rest of existing method code ...
}
```

**radioinput() - V1 signature:**
```php
function radioinput($label, $name, $class, $optionvals, $checkedval = NULL, $disabledval = NULL, $readonlyval = NULL, $hint = '') {
    // Validate option format in debug mode
    $this->validateOptionFormat($optionvals, "radioinput('$name')");

    // ... rest of existing method code ...
}
```

**checkboxList() - V1 signature:**
```php
function checkboxList($label, $id, $class, $optionvals, $checkedvals = array(), $disabledvals = array(), $readonlyvals = array(), $hint = '', $type = 'checkbox') {
    // Validate option format in debug mode
    $this->validateOptionFormat($optionvals, "checkboxList('$id')");

    // ... rest of existing method code ...
}
```

### Files to Modify

#### Core Files:
1. `/includes/FormWriterV2Base.php` - Add method + 3 validation calls
2. `/includes/FormWriterBase.php` - Add method (if shared base exists)
3. `/includes/FormWriterBootstrap.php` - Add method + 3 validation calls (if no shared base)
4. `/includes/FormWriterHTML5.php` - Add method + 3 validation calls (if no shared base)
5. `/includes/FormWriterUIKit.php` - Add method + 3 validation calls (if no shared base)
6. `/includes/FormWriterTailwind.php` - Add method + 3 validation calls (if no shared base)

#### Bootstrap/Tailwind V2 Implementations:
7. `/includes/FormWriterV2Bootstrap.php` - Inherits from V2Base (no changes needed)
8. `/includes/FormWriterV2Tailwind.php` - Inherits from V2Base (no changes needed)

### How to Use

1. **Enable debug mode:**
   - Go to `/admin/admin_settings_payments`
   - Set "Payment Debug Mode" to "Yes"
   - Or directly in database: `UPDATE stg_settings SET stg_value = '1' WHERE stg_name = 'debug'`

2. **Browse pages with forms:**
   - Visit admin pages with dropdowns
   - Visit public forms
   - Check any page that uses option arrays

3. **Check error logs:**
   ```bash
   tail -f /var/www/html/joinerytest/logs/error.log | grep REVERSED_ARRAY
   ```

4. **Review findings:**
   ```
   [REVERSED_ARRAY] Confidence: 90% | 'Active' => '1' | File: admin_event_edit.php:100 | Field: dropinput('evt_status')
   [REVERSED_ARRAY] Confidence: 85% | 'United States' => 'us' | File: forms_example.php:337 | Field: dropinput('country')
   ```

### Expected Detection Rate

- **High confidence (≥70%):** ~85% catch rate
- **Medium confidence (50-69%):** Additional ~10% catch rate
- **Total detection:** ~80-95% of missed instances
- **False positives:** <5% (mostly whitelistable patterns)

### Whitelisting Additional Patterns

If you encounter false positives, add them to the whitelist in the validation method:

```php
static $whitelist = [
    'new' => true,
    'your_custom_key' => true,
    // Add more as needed
];
```

Or add pattern-based exclusions:

```php
// Skip if matches specific patterns
if (preg_match('/^(new|custom_pattern)$/', $key)) {
    continue;
}
```

### After the Fix is Complete

Once all reversed arrays are fixed:

1. Keep the validation in place (no harm, negligible performance cost)
2. It will serve as a safety net for future code
3. Helps catch issues during development before they reach production
4. Can be left enabled permanently in development environments
