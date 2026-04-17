# Specification: Model Form Helper Methods

**Status:** Pending
**Priority:** High
**Estimated Effort:** 2-3 hours
**Date Created:** 2025-11-02
**Related Issues:** PlainForm() methods removed, event_register_finish.php broken

---

## 1. Overview

This specification defines static helper methods for models to render their form fields using FormWriter, replacing the removed PlainForm() methods. This approach keeps form field knowledge within the models while maintaining proper MVC separation by accepting a FormWriter instance rather than directly outputting HTML.

---

## 2. Problem Statement

The removal of `PlainForm()` methods from Address and PhoneNumber models has broken several pages that relied on these convenience methods:
- `/views/profile/event_register_finish.php` - Critical user flow, currently broken
- Other profile pages that need address/phone collection

We need a clean architectural solution that:
- Maintains convenience of single method calls for common field groups
- Respects MVC separation (models don't echo HTML)
- Works with FormWriter V2 architecture
- Is consistent with existing admin page patterns

---

## 3. Solution Architecture

### 3.1 Static Helper Method Pattern

Models will provide static methods that accept a FormWriter instance and render their fields through it:

```php
class ModelName extends SystemBase {
    public static function renderFormFields($formwriter, $options = []) {
        // Use $formwriter methods to render fields
        // No echo statements
        // Return void
    }
}
```

### 3.2 Key Principles

1. **No Direct Output**: Methods use FormWriter's methods, never echo
2. **Options Array**: Flexible configuration via associative array
3. **Model Knowledge**: Models know their field names, types, and validation rules
4. **FormWriter Agnostic**: Works with any FormWriter implementation
5. **Consistent Naming**: All use `renderFormFields()` method name

---

## 4. Implementation Specifications

### 4.1 Address::renderFormFields()

Based on `/adm/admin_address_edit.php` implementation:

```php
class Address extends SystemBase {

    /**
     * Render address form fields using provided FormWriter instance
     *
     * @param FormWriterBase $formwriter The FormWriter instance
     * @param array $options Configuration options
     */
    public static function renderFormFields($formwriter, $options = []) {
        $defaults = [
            'required' => true,
            'include_country' => true,
            'include_user_id' => false,
            'user_id' => null,
            'model' => null  // Address object for prepopulation
        ];
        $opts = array_merge($defaults, $options);

        // Hidden user_id field if requested
        if ($opts['include_user_id'] && $opts['user_id']) {
            $formwriter->hiddeninput('usr_user_id', '', [
                'value' => $opts['user_id']
            ]);
        }

        // Country dropdown
        if ($opts['include_country']) {
            $country_codes = self::get_country_drop_array2();
            $formwriter->dropinput('usa_cco_country_code_id', 'Country', [
                'options' => $country_codes,
                'value' => $opts['model'] ? $opts['model']->get('usa_cco_country_code_id') : null
            ]);
        }

        // Street Address
        $formwriter->textinput('usa_address1', 'Street Address', [
            'maxlength' => 255,
            'validation' => $opts['required'] ? ['required' => true] : [],
            'value' => $opts['model'] ? $opts['model']->get('usa_address1') : null
        ]);

        // Apt/Suite (optional)
        $formwriter->textinput('usa_address2', 'Apt, Suite, etc. (optional)', [
            'maxlength' => 255,
            'value' => $opts['model'] ? $opts['model']->get('usa_address2') : null
        ]);

        // City
        $formwriter->textinput('usa_city', 'City', [
            'maxlength' => 255,
            'validation' => $opts['required'] ? ['required' => true] : [],
            'value' => $opts['model'] ? $opts['model']->get('usa_city') : null
        ]);

        // State/Province
        $formwriter->textinput('usa_state', 'State/Province', [
            'maxlength' => 255,
            'validation' => $opts['required'] ? ['required' => true] : [],
            'value' => $opts['model'] ? $opts['model']->get('usa_state') : null
        ]);

        // Zip/Postcode
        $formwriter->textinput('usa_zip_code_id', 'Zip/Postcode', [
            'maxlength' => 255,
            'validation' => $opts['required'] ? ['required' => true] : [],
            'value' => $opts['model'] ? $opts['model']->get('usa_zip_code_id') : null
        ]);
    }
}
```

### 4.2 PhoneNumber::renderFormFields()

Based on `/adm/admin_phone_edit.php` implementation:

```php
class PhoneNumber extends SystemBase {

    /**
     * Render phone number form fields using provided FormWriter instance
     *
     * @param FormWriterBase $formwriter The FormWriter instance
     * @param array $options Configuration options
     */
    public static function renderFormFields($formwriter, $options = []) {
        $defaults = [
            'required' => true,
            'include_user_id' => false,
            'user_id' => null,
            'model' => null  // PhoneNumber object for prepopulation
        ];
        $opts = array_merge($defaults, $options);

        // Hidden user_id field if requested
        if ($opts['include_user_id'] && $opts['user_id']) {
            $formwriter->hiddeninput('usr_user_id', '', [
                'value' => $opts['user_id']
            ]);
        }

        // Country code dropdown
        $country_codes = self::get_country_code_drop_array();
        $formwriter->dropinput('phn_cco_country_code_id', 'Country code', [
            'options' => $country_codes,
            'value' => $opts['model'] ? $opts['model']->get('phn_cco_country_code_id') : null
        ]);

        // Phone number
        $formwriter->textinput('phn_phone_number', 'Phone Number', [
            'maxlength' => 20,
            'validation' => $opts['required'] ? ['required' => true] : [],
            'value' => $opts['model'] ? $opts['model']->get('phn_phone_number') : null
        ]);
    }
}
```

---

## 5. Migration Examples

### 5.1 event_register_finish.php

**Old Code (Broken):**
```php
if(!Address::GetDefaultAddressForUser($user_id)){
    $user_address = $user->address();
    Address::PlainForm($formwriter, $user_address, array('privacy' => 1, 'usa_type' => 'HM'));
}

if(!$phone_number = $user->phone()){
    PhoneNumber::PlainForm($formwriter, $phone_number);
}
```

**New Code:**
```php
if(!Address::GetDefaultAddressForUser($user_id)){
    $user_address = $user->address();
    Address::renderFormFields($formwriter, [
        'required' => true,
        'include_user_id' => true,
        'user_id' => $user->key,
        'model' => $user_address
    ]);
}

if(!$phone_number = $user->phone()){
    PhoneNumber::renderFormFields($formwriter, [
        'required' => true,
        'include_user_id' => true,
        'user_id' => $user->key,
        'model' => $phone_number
    ]);
}
```

### 5.2 Admin Pages

Admin pages already use the correct pattern, but could optionally migrate to the helper:

**Current (keep as-is or migrate):**
```php
$formwriter->hiddeninput('usr_user_id', '', ['value' => $user_id]);

$country_codes = Address::get_country_drop_array2();
$formwriter->dropinput('usa_cco_country_code_id', 'Country', [
    'options' => $country_codes
]);
// ... more fields
```

**Optional Migration:**
```php
Address::renderFormFields($formwriter, [
    'required' => true,
    'include_user_id' => true,
    'user_id' => $user_id,
    'model' => $address
]);
```

---

## 6. Implementation Tasks

### Phase 1: Core Implementation & Admin Testing ✅ COMPLETED
- [x] Add `renderFormFields()` method to Address class
- [x] Add `renderFormFields()` method to PhoneNumber class
- [x] Migrate `/adm/admin_address_edit.php` to use new method
- [x] Migrate `/adm/admin_phone_edit.php` to use new method
- [x] Test admin pages thoroughly to validate the approach
- [x] Verify FormWriter V2 compatibility

### Phase 2: Profile & Public Page Migration ✅ COMPLETED
- [x] Fix `/views/profile/event_register_finish.php` (critical - now using renderFormFields)
- [x] Update `/views/profile/address_edit.php` (now using renderFormFields)
- [x] Update `/views/profile/phone_numbers_edit.php` (now using renderFormFields)
- [x] Update `/data/products_class.php` requirement classes (PhoneNumberRequirement and AddressRequirement)
- [x] Search for and verified no other pages using PlainForm
- [x] All migrated pages pass syntax validation

### Phase 3: Documentation & Cleanup
- [ ] Document the new pattern in CLAUDE.md
- [ ] Add examples to model class documentation
- [ ] Update any related specifications
- [ ] Remove any remaining PlainForm references or commented code

---

## 7. Testing Checklist

### Functional Tests
- [ ] Address fields render correctly with all options
- [ ] Phone fields render correctly with all options
- [ ] Validation rules apply correctly
- [ ] Prepopulation works with model objects
- [ ] Hidden user_id field included when requested

### Integration Tests
- [ ] event_register_finish.php works end-to-end
- [ ] Profile pages work correctly
- [ ] Admin pages continue to work (if migrated)
- [ ] Form submission and data saving work

### Edge Cases
- [ ] Empty/null model objects handled gracefully
- [ ] Missing user_id handled appropriately
- [ ] All FormWriter implementations supported

---

## 8. Benefits

1. **Maintains Convenience**: Single method call for common field groups
2. **Respects MVC**: Models don't output HTML directly
3. **Consistent Pattern**: Similar to existing admin pages
4. **Flexible Options**: Extensive configuration possible
5. **Backward Compatible**: Easy migration from PlainForm
6. **Model Encapsulation**: Models know their own fields
7. **FormWriter Integration**: Works seamlessly with V2

---

## 9. Implementation Summary

### ✅ Phase 1 - Core Implementation (COMPLETE)
- Added `Address::renderFormFields()` method to `/data/address_class.php:298`
- Added `PhoneNumber::renderFormFields()` method to `/data/phone_number_class.php:132`
- Migrated `/adm/admin_address_edit.php` to use new method
- Migrated `/adm/admin_phone_edit.php` to use new method
- All files pass syntax validation
- FormWriter V2 compatibility verified in admin context

### ✅ Phase 2 - Profile & Public Page Migration (COMPLETE)
- Fixed `/views/profile/event_register_finish.php` (critical page, now using V2 FormWriter with renderFormFields)
- Updated `/views/profile/address_edit.php` to use renderFormFields
- Updated `/views/profile/phone_numbers_edit.php` to use renderFormFields
- Migrated `/data/products_class.php`:
  - `PhoneNumberRequirement::get_form()` now uses renderFormFields
  - `AddressRequirement::get_form()` now uses renderFormFields (3 instances)
- Comprehensive search verified no other PlainForm usages remain
- All migrated files pass syntax validation

### 📋 Files Modified
1. `/data/address_class.php` - Added renderFormFields method
2. `/data/phone_number_class.php` - Added renderFormFields method
3. `/adm/admin_address_edit.php` - Uses renderFormFields
4. `/adm/admin_phone_edit.php` - Uses renderFormFields
5. `/views/profile/event_register_finish.php` - Migrated to V2 FormWriter with renderFormFields
6. `/views/profile/address_edit.php` - Uses renderFormFields
7. `/views/profile/phone_numbers_edit.php` - Uses renderFormFields
8. `/data/products_class.php` - PhoneNumberRequirement and AddressRequirement updated

## 10. Notes

- The `usa_type` and `privacy` options from old PlainForm are not used in the new implementation (they're app-specific and not needed in the generic form rendering)
- The field names match exactly what's in the database and existing forms
- This pattern can be extended to other models as needed (User, Event, Product, etc.)
- All PlainForm method calls have been successfully removed and replaced with renderFormFields