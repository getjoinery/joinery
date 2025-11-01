# Specification: Migrate Non-Admin Forms to FormWriter V2 - Phase 2

**Status:** Pending
**Priority:** Medium
**Date Created:** 2025-11-01
**Last Updated:** 2025-11-01
**Related Specifications:**
- `/specs/implemented/migrate_admin_forms_to_formwriter_v2.md` - Phase 1: Admin Forms Migration (Complete ✅)
- `/docs/formwriter.md` - FormWriter V2 documentation

---

## 1. Overview

### What is this migration?

Phase 2 extends the FormWriter V2 migration to all non-admin pages that still use FormWriter V1. This includes:

1. **PlainForm() method calls** - Public pages using `Address::PlainForm()` and `PhoneNumber::PlainForm()` (will cause runtime errors since methods were removed in Phase 1)
2. **Legacy FormWriter V1 calls** - All other public-facing pages and profile pages still using FormWriter V1

### Why is this needed?

**Immediate Issue:** Phase 1 removed the `PlainForm()` method definitions from data classes. Any remaining calls will result in "Call to undefined method" runtime errors.

**Long-term Benefits:**
- Standardize entire system on FormWriter V2
- Enable modern form features (automatic model binding, validation integration, etc.)
- Consistent form API across entire application
- Easier maintenance and testing

### Migration Strategy

For non-admin pages, we have two approaches depending on complexity:

1. **Simple forms (mostly input fields):** Migrate to V2 inline in the view
2. **Complex forms (conditional logic, multiple steps):** Extract to logic file following admin pattern (optional)

**Priority Order:**
1. 🔴 **CRITICAL:** Pages with PlainForm calls (runtime errors if not fixed)
2. 🟠 **HIGH:** Core user flows (registration, login, profile pages)
3. 🟡 **MEDIUM:** Public pages (event, product, list pages)
4. 🟢 **LOW:** Utility/development pages

---

## 2. Files Requiring Migration

### 2.1 Profile Pages (Migrated ✅)
These pages have already been migrated in Phase 1:

- ✅ `/views/profile/phone_numbers_edit.php` - FormWriter V2 with logic file
- ✅ `/views/profile/address_edit.php` - FormWriter V2 with logic file

**Status:** Complete - These pages now use FormWriter V2 with logic file separation

---

### 2.2 Critical Priority - PlainForm Usage (Runtime Errors if Not Fixed)

These pages call removed PlainForm methods and **WILL FAIL at runtime**:

#### `/views/profile/event_register_finish.php` - 🔴 **CRITICAL**

**File:** `/var/www/html/joinerytest/public_html/views/profile/event_register_finish.php`

**FormWriter Usage:** Line 19 - V1 FormWriter
**PlainForm Calls:** Lines 35, 40 - Will cause runtime error!

**Current Code:**
```php
Line 19: $formwriter = $page->getFormWriter('form1');  // V1
Line 35: Address::PlainForm($formwriter, $user_address, array('privacy' => 1, 'usa_type' => 'HM'));
Line 40: PhoneNumber::PlainForm($formwriter, $phone_number);
```

**Purpose:** Event registration completion page where users provide address and phone information if not already present.

**Migration Approach:**
1. Migrate FormWriter from V1 to V2 (line 19)
2. Replace PlainForm calls with direct field definitions
3. Keep inline form (not a separate logic file) - this page has conditional logic
4. Preserve conditional logic: only show address/phone fields if user doesn't already have them

**Form Fields to Add for Address:**
```php
$country_codes = Address::get_country_drop_array2();
$formwriter->dropinput('usa_cco_country_code_id', 'Country', [
    'options' => $country_codes
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

**Form Fields to Add for Phone:**
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

**Migration Checklist:**
- [ ] Migrate FormWriter from V1 to V2 (line 19)
- [ ] Replace `Address::PlainForm()` with direct field definitions (line 35)
- [ ] Replace `PhoneNumber::PlainForm()` with direct field definitions (line 40)
- [ ] Run syntax validation: `php -l`
- [ ] Test address field display when user has no address
- [ ] Test phone field display when user has no phone
- [ ] Test form submission with valid data

---

#### `/data/products_class.php` - 🔴 **CRITICAL**

**File:** `/var/www/html/joinerytest/public_html/data/products_class.php`

**PlainForm Calls:** Lines 118, 257, 262, 267 - Will cause runtime error!

**Context:** Used by product registration requirements:
- Line 118: `PhoneNumberRequirement::get_form()` method
- Lines 257, 262, 267: `AddressRequirement::get_form()` method

**Migration Approach:**
Replace PlainForm calls with direct field definitions in requirement class `get_form()` methods.

**PhoneNumberRequirement::get_form() (Line 118) - Replace:**
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

**AddressRequirement::get_form() (Lines 257, 262, 267) - Replace:**
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

**Migration Checklist:**
- [ ] Migrate `PhoneNumberRequirement::get_form()` (line 118)
- [ ] Migrate `AddressRequirement::get_form()` (lines 257, 262, 267)
- [ ] Run syntax validation: `php -l`
- [ ] Run method existence test
- [ ] Test product registration with address requirement
- [ ] Test product registration with phone number requirement
- [ ] Test validation messages still work correctly

---

### 2.3 High Priority - Core User Flows (FormWriter V1)

#### Authentication Pages

| File | Lines | Forms | Status |
|------|-------|-------|--------|
| `/views/login.php` | 32 | 1 form (login) | ⏳ Pending |
| `/views/register.php` | 19 | 1 form (registration) | ⏳ Pending |
| `/views/password-reset-1.php` | 13 | 1 form (email entry) | ⏳ Pending |
| `/views/password-reset-2.php` | 106 | 1 form (password reset) | ⏳ Pending |
| `/views/password-set.php` | 39 | 1 form (password set) | ⏳ Pending |

**Scope:** Simple forms with 1-2 fields each
**Migration:** Straightforward - change to V2 FormWriter inline

#### Profile Pages

| File | Lines | Forms | Status |
|------|-------|-------|--------|
| `/views/profile/account_edit.php` | 30 | 1 form (account edit) | ⏳ Pending |
| `/views/profile/password_edit.php` | 13 | 1 form (password change) | ⏳ Pending |
| `/views/profile/contact_preferences.php` | 13 | 1 form (preference settings) | ⏳ Pending |
| `/views/profile/change-tier.php` | 1 | 1 form (subscription tier) | ⏳ Pending |
| `/views/profile/event_withdraw.php` | 33 | 1 form (event withdrawal) | ⏳ Pending |

**Scope:** Simple to moderate forms with conditional logic
**Migration:** Straightforward - change to V2 FormWriter inline

---

### 2.4 Medium Priority - Public Pages (FormWriter V1)

| File | Lines | Forms | Status | Complexity |
|------|-------|-------|--------|------------|
| `/views/event.php` | 191 | 1 form (event registration) | ⏳ Pending | Moderate |
| `/views/event_waiting_list.php` | 30 | 1 form (waiting list signup) | ⏳ Pending | Simple |
| `/views/product.php` | 1,99 | 2 forms (add to cart, enquiry) | ⏳ Pending | Moderate |
| `/views/cart.php` | 139,153,252,296,339 | 5 forms (checkout flow) | ⏳ Pending | Complex |
| `/views/list.php` | 27 | 1 form (filter/search) | ⏳ Pending | Simple |
| `/views/lists.php` | 25 | 1 form (filter/search) | ⏳ Pending | Simple |
| `/views/post.php` | 78,170 | 2 forms (comment, reply) | ⏳ Pending | Simple |
| `/views/survey.php` | 3 | 1 form (survey submission) | ⏳ Pending | Moderate |

**Scope:** Public-facing pages with various complexity levels
**Migration:** Requires analysis of each form's functionality

---

### 2.5 Low Priority - Utility/Development Pages (FormWriter V1)

| File | Usage | Status |
|------|-------|--------|
| `/utils/publish_upgrade.php` | Development utility | ⏳ Pending |
| `/utils/upgrade.php` | Development utility | ⏳ Pending |
| `/utils/test_components.php` | Development utility | ⏳ Pending |

**Scope:** Internal/development pages
**Migration:** Can be deferred if not used in production flow

---

## 3. Migration Priority & Phases

### Phase 2.1 - CRITICAL (MUST FIX IMMEDIATELY)
These pages will error if not fixed:
- [ ] `/views/profile/event_register_finish.php` (PlainForm usage)
- [ ] `/data/products_class.php` (PlainForm usage)

**Target:** Complete ASAP to prevent runtime errors

### Phase 2.2 - HIGH (Core Functionality)
These pages are part of essential user journeys:
- [ ] `/views/login.php`
- [ ] `/views/register.php`
- [ ] `/views/password-reset-1.php`
- [ ] `/views/password-reset-2.php`
- [ ] `/views/password-set.php`
- [ ] `/views/profile/account_edit.php`
- [ ] `/views/profile/password_edit.php`
- [ ] `/views/profile/contact_preferences.php`
- [ ] `/views/profile/change-tier.php`
- [ ] `/views/profile/event_withdraw.php`

**Target:** Q1 2026

### Phase 2.3 - MEDIUM (Public Pages)
Public-facing content pages:
- [ ] `/views/event.php`
- [ ] `/views/event_waiting_list.php`
- [ ] `/views/product.php`
- [ ] `/views/cart.php`
- [ ] `/views/list.php`
- [ ] `/views/lists.php`
- [ ] `/views/post.php`
- [ ] `/views/survey.php`

**Target:** Q2 2026

### Phase 2.4 - LOW (Utilities)
Development/internal pages:
- [ ] `/utils/publish_upgrade.php`
- [ ] `/utils/upgrade.php`
- [ ] `/utils/test_components.php`

**Target:** Q3 2026 (if needed)

---

## 4. Testing Checklist for All Migrations

For each page migrated, verify:

- [ ] **Syntax:** `php -l /path/to/file.php` passes without errors
- [ ] **Methods:** `php "/home/user1/joinery/joinery/maintenance scripts/method_existence_test.php" /path/to/file.php` shows no issues
- [ ] **Page Load:** Page loads without errors in browser
- [ ] **Form Rendering:** All form fields render correctly
- [ ] **Form Submission:** Form submits without errors
- [ ] **Validation:** Client-side validation works (if applicable)
- [ ] **Error Handling:** Validation error messages display correctly
- [ ] **Data Save:** Data is correctly saved/processed

---

## 5. FormWriter V1 to V2 Migration Reference

### Basic Migration Pattern

**V1 (Old):**
```php
$formwriter = $page->getFormWriter('form1');
$formwriter->begin_form();
echo $formwriter->textinput('Field Label', 'field_name', NULL, 20, $default_value);
$formwriter->submitbutton('btn_submit', 'Submit');
$formwriter->end_form();
```

**V2 (New):**
```php
$formwriter = $page->getFormWriter('form1', 'v2');
$formwriter->begin_form();
$formwriter->textinput('field_name', 'Field Label', ['value' => $default_value]);
$formwriter->submitbutton('btn_submit', 'Submit');
$formwriter->end_form();
```

### Common Field Type Mappings

| Field Type | V1 Method | V2 Method | Notes |
|------------|-----------|-----------|-------|
| Text | `textinput()` | `textinput()` | Parameter order changes |
| Textarea | `textbox()` | `textbox()` | Parameter order changes |
| Dropdown | `dropinput()` | `dropinput()` | Parameter order and options format |
| Checkbox | `checkbox()` | `checkbox()` | Parameter order |
| Radio | `radio()` | `radio()` | Parameter order |
| Hidden | `hiddeninput()` | `hiddeninput()` | Parameter order |

### Key V2 Features Available

- **Automatic validation:** Define validation rules inline
- **Model binding:** Auto-fill from model objects
- **Conditional fields:** Show/hide based on conditions
- **Custom attributes:** HTML5 data attributes
- **Grouped fields:** fieldset support

---

## 6. Success Criteria

Phase 2 is complete when:

1. ✅ No calls to `Address::PlainForm()` or `PhoneNumber::PlainForm()` remain
2. ✅ All non-admin forms use FormWriter V2
3. ✅ All files pass syntax validation (`php -l`)
4. ✅ All files pass method existence test
5. ✅ All migrated pages have been tested manually
6. ✅ This spec moved to `/specs/implemented/`

---

## Appendix A: Complete FormWriter V1 Usage Map

**Last Verified:** 2025-11-01

**Total Non-Admin Pages Using V1:** 22 files
**Critical (PlainForm):** 2 files
**High Priority:** 10 files
**Medium Priority:** 8 files
**Low Priority:** 3 files

### By Category

**Profile Pages (7):**
```
✅ /views/profile/phone_numbers_edit.php - MIGRATED
✅ /views/profile/address_edit.php - MIGRATED
⏳ /views/profile/account_edit.php
⏳ /views/profile/password_edit.php
⏳ /views/profile/contact_preferences.php
⏳ /views/profile/change-tier.php
⏳ /views/profile/event_withdraw.php
🔴 /views/profile/event_register_finish.php - CRITICAL (PlainForm)
```

**Authentication Pages (5):**
```
⏳ /views/login.php
⏳ /views/register.php
⏳ /views/password-reset-1.php
⏳ /views/password-reset-2.php
⏳ /views/password-set.php
```

**Public Pages (8):**
```
⏳ /views/event.php
⏳ /views/event_waiting_list.php
⏳ /views/product.php
⏳ /views/cart.php
⏳ /views/list.php
⏳ /views/lists.php
⏳ /views/post.php
⏳ /views/survey.php
```

**Data Classes (1):**
```
🔴 /data/products_class.php - CRITICAL (PlainForm in requirement classes)
```

**Utility Pages (3):**
```
⏳ /utils/publish_upgrade.php
⏳ /utils/upgrade.php
⏳ /utils/test_components.php
```

---

## Appendix B: Related Files Not Requiring Migration

These files use getFormWriter but are **NOT being migrated** (they're part of other systems):

- `/includes/AdminPage.php` - Base class definition (no migration needed)
- `/includes/PublicPageBase.php` - Base class definition (no migration needed)
- `/includes/ThemeHelper.php` - Helper class (no migration needed)

---

## Appendix C: PlainForm Methods Removed in Phase 1

The following methods were removed from data classes in Phase 1 and are no longer callable:

- `Address::PlainForm($formwriter, $address_object, $options)`
- `PhoneNumber::PlainForm($formwriter, $phone_object)`

Any remaining calls to these methods **WILL CAUSE RUNTIME ERRORS:**
```
Call to undefined static method Address::PlainForm()
Call to undefined static method PhoneNumber::PlainForm()
```

**Files with remaining calls (CRITICAL):**
- `/views/profile/event_register_finish.php` (lines 35, 40)
- `/data/products_class.php` (lines 118, 257, 262, 267)

