# Specification: Migrate PlainForm Methods to FormWriter V2 - Phase 2 (Non-Admin Pages)

**Status:** Pending
**Priority:** Medium
**Date Created:** 2025-11-01
**Related Specifications:**
- `/specs/migrate_admin_forms_to_formwriter_v2.md` - Phase 1: Admin Forms Migration
- `/docs/formwriter.md` - FormWriter V2 documentation

---

## 1. Overview

### What is this migration?

Phase 2 extends the FormWriter V2 migration to non-admin pages that use `PlainForm()` methods. These are public-facing and profile pages that currently call `Address::PlainForm()` and `PhoneNumber::PlainForm()` static methods.

**Note:** In Phase 1, we removed the `PlainForm()` method definitions from `Address` and `PhoneNumber` model classes. This means any remaining calls to these methods will result in runtime errors.

### Why is this needed?

The `PlainForm()` pattern was a special-case form rendering method used in model classes. It violated the separation of concerns principle:
- Business logic (models) contained form rendering code
- Made forms difficult to maintain and test
- Couldn't use FormWriter V2's advanced features (model binding, automatic field filling, validation integration)

**Solution:** Remove PlainForm entirely and use standard patterns:
- Profile pages: Use logic files + FormWriter V2 (following the admin pattern)
- Product requirements: Embed form field definitions directly in requirement classes

---

## 2. Files Requiring Migration

### 2.1 Profile Pages (Migrated ✅)
These pages have already been migrated in the previous conversation:

- ✅ `/views/profile/phone_numbers_edit.php` - Uses standard add/edit pattern with logic file
- ✅ `/views/profile/address_edit.php` - Uses standard add/edit pattern with logic file

**Status:** Complete - These pages now use FormWriter V2 with logic file separation

---

### 2.2 Public Pages (Requires Migration)

#### `/views/profile/event_register_finish.php` - ⏳ **PENDING**

**Location:** `/var/www/html/joinerytest/public_html/views/profile/event_register_finish.php`

**Current Usage:**
```php
// Line 35: Calls PlainForm for address
Address::PlainForm($formwriter, $user_address, array('privacy' => 1, 'usa_type' => 'HM'));

// Line 40: Calls PlainForm for phone
PhoneNumber::PlainForm($formwriter, $phone_number);
```

**Purpose:** Event registration completion page where users provide address and phone information if not already present

**Migration Approach:**
1. Extract form field logic from the removed PlainForm methods
2. Replace calls with direct FormWriter field definitions
3. Keep inline form (not a separate logic file) - this page has conditional logic based on user's existing data
4. Preserve conditional logic: only show address/phone fields if user doesn't already have them

**Form Fields to Add:**

For Address (from removed PlainForm method):
```php
$formwriter->dropinput('usa_cco_country_code_id', 'Country', [
    'options' => Address::get_country_drop_array2()
]);
$formwriter->textinput('usa_address1', 'Street Address', [
    'maxlength' => 255,
    'validation' => ['required' => true]
]);
$formwriter->textinput('usa_address2', 'Apt, Suite, etc. (optional)', [
    'maxlength' => 255
]);
$formwriter->textinput('usa_city', 'City', [
    'maxlength' => 255,
    'validation' => ['required' => true]
]);
$formwriter->textinput('usa_state', 'State/Province', [
    'maxlength' => 255,
    'validation' => ['required' => true]
]);
$formwriter->textinput('usa_zip_code_id', 'Zip/Postcode', [
    'maxlength' => 255,
    'validation' => ['required' => true]
]);
```

For Phone (from removed PlainForm method):
```php
$country_codes = PhoneNumber::get_country_code_drop_array();
$formwriter->dropinput('phn_cco_country_code_id', 'Country code', [
    'options' => $country_codes
]);
$formwriter->textinput('phn_phone_number', 'Phone Number', [
    'maxlength' => 20,
    'validation' => ['required' => true]
]);
```

**Special Notes:**
- Address/Phone display is conditional on user's existing data (line 32-37, 39-48)
- Uses V1 FormWriter currently (line 19)
- Should migrate to V2 FormWriter in same update
- Form action should redirect back to `/profile/event_register_finish` (line 26)

---

### 2.3 Product Requirement Classes (Requires Migration)

#### `/data/products_class.php` - ⏳ **PENDING**

**Location:** `/var/www/html/joinerytest/public_html/data/products_class.php`

**Current Usage:**
Lines with PlainForm calls:
```php
// Line 118: PhoneNumber requirement
PhoneNumber::PlainForm($formwriter, NULL);

// Lines 257, 262, 267: Address requirement
Address::PlainForm($formwriter, NULL, array('privacy' => 1, 'usa_type' => 'HM'));
Address::PlainForm($formwriter, NULL, array('privacy' => 1, 'usa_type' => 'HM'));
Address::PlainForm($formwriter, NULL, array('privacy' => 1, 'usa_type' => 'HM'));
```

**Context:** These are requirement classes for product registration:
- `PhoneNumberRequirement` (class extends BasicProductRequirement, lines 105-143)
- `AddressRequirement` (class extends BasicProductRequirement, lines ~250-270)

**Migration Approach:**
Replace PlainForm calls with direct field definitions in the `get_form()` method of each requirement class.

**PhoneNumberRequirement::get_form() - Replace:**
```php
// OLD: PhoneNumber::PlainForm($formwriter, NULL);

// NEW: Replace with direct field definitions
$country_codes = PhoneNumber::get_country_code_drop_array();
echo $formwriter->dropinput('phn_cco_country_code_id', 'Country code', [
    'options' => $country_codes
]);
echo $formwriter->textinput('phn_phone_number', 'Phone Number', [
    'maxlength' => 20,
    'validation' => ['required' => true]
]);
```

**AddressRequirement::get_form() - Replace:**
```php
// OLD: Address::PlainForm($formwriter, NULL, array('privacy' => 1, 'usa_type' => 'HM'));

// NEW: Replace with direct field definitions
$country_codes = Address::get_country_drop_array2();
echo $formwriter->dropinput('usa_cco_country_code_id', 'Country', [
    'options' => $country_codes
]);
echo $formwriter->textinput('usa_address1', 'Street Address', [
    'maxlength' => 255,
    'validation' => ['required' => true]
]);
echo $formwriter->textinput('usa_address2', 'Apt, Suite, etc. (optional)', [
    'maxlength' => 255
]);
echo $formwriter->textinput('usa_city', 'City', [
    'maxlength' => 255,
    'validation' => ['required' => true]
]);
echo $formwriter->textinput('usa_state', 'State/Province', [
    'maxlength' => 255,
    'validation' => ['required' => true]
]);
echo $formwriter->textinput('usa_zip_code_id', 'Zip/Postcode', [
    'maxlength' => 255,
    'validation' => ['required' => true]
]);
```

**Special Notes:**
- These are HTML output methods in requirement classes (note the `echo` statements)
- The FormWriter instance is passed as a parameter: `$formwriter`
- Both address calls pass `array('privacy' => 1, 'usa_type' => 'HM')` but this was only used internally by PlainForm - those fields don't need to be set in the new version
- The validation data is already defined in `get_validation_info()` methods (lines 121-127, 253-265)

---

## 3. Migration Checklist

### For `/views/profile/event_register_finish.php`:
- [ ] Migrate FormWriter from V1 to V2 (line 19)
- [ ] Replace `Address::PlainForm()` call with direct field definitions (line 35)
- [ ] Replace `PhoneNumber::PlainForm()` call with direct field definitions (line 40)
- [ ] Run syntax validation: `php -l /var/www/html/joinerytest/public_html/views/profile/event_register_finish.php`
- [ ] Test address field display when user has no address
- [ ] Test phone field display when user has no phone
- [ ] Test form submission with valid data
- [ ] Test form validation errors

### For `/data/products_class.php`:
- [ ] Migrate `PhoneNumberRequirement::get_form()` to use direct field definitions (line 118)
- [ ] Migrate `AddressRequirement::get_form()` to use direct field definitions (lines 257, 262, 267)
- [ ] Run syntax validation: `php -l /var/www/html/joinerytest/public_html/data/products_class.php`
- [ ] Run method existence test: `php "/home/user1/joinery/joinery/maintenance scripts/method_existence_test.php" /var/www/html/joinerytest/public_html/data/products_class.php`
- [ ] Test product registration with address requirement
- [ ] Test product registration with phone number requirement
- [ ] Test product registration with both requirements
- [ ] Test that validation messages still work correctly

---

## 4. Implementation Notes

### FormWriter Field Methods Used

Both migrations use these FormWriter methods:

```php
// Dropdown inputs
$formwriter->dropinput($field_name, $label, [
    'options' => $options_array
]);

// Text inputs
$formwriter->textinput($field_name, $label, [
    'maxlength' => 255,
    'validation' => ['required' => true]
]);
```

### How to Get Field Options

**For Address Country Codes:**
```php
$country_codes = Address::get_country_drop_array2();
```

**For Phone Country Codes:**
```php
$country_codes = PhoneNumber::get_country_code_drop_array();
```

### Removed PlainForm Method References

The following static methods have been removed from data classes and are no longer callable:
- `Address::PlainForm($formwriter, $address_object, $options)`
- `PhoneNumber::PlainForm($formwriter, $phone_object)`

Any other references to these methods will cause runtime "Call to undefined method" errors.

---

## 5. Related Work

### Already Completed
- ✅ Removed `PlainForm()` method from `/data/address_class.php`
- ✅ Removed `PlainForm()` method from `/data/phone_number_class.php`
- ✅ Migrated `/views/profile/phone_numbers_edit.php` to use FormWriter V2 with logic file
- ✅ Migrated `/views/profile/address_edit.php` to use FormWriter V2 with logic file
- ✅ Migrated `/adm/admin_phone_edit.php` to use FormWriter V2 with logic file
- ✅ Migrated `/adm/admin_address_edit.php` to use FormWriter V2 with logic file

### Admin Pages Status
- ✅ All 69 admin pages migrated to FormWriter V2
- ✅ Admin spec: `/specs/migrate_admin_forms_to_formwriter_v2.md` marked as completed

---

## 6. Success Criteria

Phase 2 is complete when:

1. ✅ No remaining calls to `Address::PlainForm()` or `PhoneNumber::PlainForm()`
2. ✅ Both non-admin pages successfully use direct FormWriter field definitions
3. ✅ All files pass syntax validation (`php -l`)
4. ✅ All modified files pass method existence test
5. ✅ Public event registration page works correctly with address/phone fields
6. ✅ Product registration pages work correctly with address/phone requirements
7. ✅ This spec moved to `/specs/implemented/`

---

## Appendix A: Complete PlainForm Usage Map

**Last Verified:** 2025-11-01

**Files to Migrate:**
```
/var/www/html/joinerytest/public_html/views/profile/event_register_finish.php (Lines: 35, 40)
/var/www/html/joinerytest/public_html/data/products_class.php (Lines: 118, 257, 262, 267)
```

**Backup Locations (uploads directory):**
- `/var/www/html/joinerytest/uploads/upgrades/public_html/views/profile/event_register_finish.php`
- `/var/www/html/joinerytest/uploads/upgrades/public_html/data/products_class.php`

**No remaining PlainForm references in:**
- `/adm/` directory ✅
- `/logic/` directory ✅
- `/views/profile/address_edit.php` ✅ (migrated in Phase 1)
- `/views/profile/phone_numbers_edit.php` ✅ (migrated in Phase 1)
- `/data/address_class.php` ✅ (method removed)
- `/data/phone_number_class.php` ✅ (method removed)

