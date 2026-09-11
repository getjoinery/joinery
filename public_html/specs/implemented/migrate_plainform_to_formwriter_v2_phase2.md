# Specification: Migrate Non-Admin Forms to FormWriter V2 - Phase 2

**Status:** In Progress
**Priority:** Medium
**Date Created:** 2025-11-01
**Last Updated:** 2025-11-02
**Related Specifications:**
- `/specs/implemented/model_form_helpers.md` - Model Form Helper Methods (Complete ✅)
- `/docs/formwriter.md` - FormWriter V2 documentation

---

## Overview

After the critical PlainForm migration work is complete (see `/specs/implemented/model_form_helpers.md`), remaining non-admin pages still using FormWriter V1 can be optionally migrated to V2 for consistency and to take advantage of V2 features (automatic model validation, automatic form filling, etc.).

**These pages are NOT broken** - they simply use the older FormWriter V1 API. Migration is optional and can be done gradually.

---

## Remaining Work: Phase 2.2+ Pages

### High Priority - Core User Flows

| File | Status | Effort |
|------|--------|--------|
| `/views/login.php` | ⏳ Pending | Low |
| `/views/register.php` | ⏳ Pending | Low |
| `/views/password-reset-1.php` | ⏳ Pending | Low |
| `/views/password-reset-2.php` | ⏳ Pending | Medium |
| `/views/password-set.php` | ⏳ Pending | Low |
| `/views/profile/account_edit.php` | ✅ Complete | Low |
| `/views/profile/password_edit.php` | ✅ Complete | Low |
| `/views/profile/contact_preferences.php` | ✅ Complete | Low |
| `/views/profile/change-tier.php` | ✅ Complete | Low |
| `/views/profile/event_withdraw.php` | ✅ Complete | Low |
| `/views/profile/address_edit.php` | ✅ Complete | Low |
| `/views/profile/phone_numbers_edit.php` | ✅ Complete | Low |
| `/views/profile/event_register_finish.php` | ✅ Complete | Low |

**Profile Pages Completed:** 2025-11-02
**Target for remaining pages:** Q1 2026

### Medium Priority - Public Pages (FormWriter V1)

| File | Status | Forms | Complexity |
|------|--------|-------|------------|
| `/views/event.php` | ⏳ Pending | 1 | Moderate |
| `/views/event_waiting_list.php` | ⏳ Pending | 1 | Simple |
| `/views/product.php` | ⏳ Pending | 2 | Moderate |
| `/views/cart.php` | ⏳ Pending | 5 | Complex |
| `/views/list.php` | ⏳ Pending | 1 | Simple |
| `/views/lists.php` | ⏳ Pending | 1 | Simple |
| `/views/post.php` | ⏳ Pending | 2 | Simple |
| `/views/survey.php` | ⏳ Pending | 1 | Moderate |

**Target:** Q2 2026

### Low Priority - Utility/Development Pages (FormWriter V1)

| File | Status | Usage |
|------|--------|-------|
| `/utils/publish_upgrade.php` | ⏳ Pending | Development utility |
| `/utils/upgrade.php` | ⏳ Pending | Development utility |
| `/utils/test_components.php` | ⏳ Pending | Development utility |

**Target:** Q3 2026 (if needed)

---

## Migration Pattern

When migrating these pages, use the V2 FormWriter pattern with automatic features:

```php
// Load model if editing
$model = new ClassName($id, TRUE);

// Get V2 FormWriter with automatic validation and filling
$formwriter = $page->getFormWriter('form1', 'v2', [
    'model' => $model,
    'edit_primary_key_value' => $model->key
]);

$formwriter->begin_form();

// Fields auto-populate from model, validation auto-detected from field_specifications
$formwriter->textinput('field_name', 'Field Label');
// ... more fields ...

$formwriter->submitbutton('btn_submit', 'Submit');
$formwriter->end_form();
```

**Benefits:**
- No need to manually specify `value` for each field
- Validation rules auto-detected from model field_specifications
- Much less boilerplate code
- See `/docs/formwriter.md` Section 9.1 for complete details

---

## Completed Work

### Profile Pages Migration (2025-11-02)

Successfully migrated all `/views/profile/` pages to FormWriter V2:

**Key Changes:**
- Updated `PublicPageBase::getFormWriter()` to support V2 syntax with `$version` and `$options` parameters
- Migrated 8 profile pages to V2 field syntax with options arrays
- Added `checkboxList` method to FormWriter V2 (for newsletter subscriptions)
- Fixed Canvas theme `public_footer()` to call parent method for proper message clearing
- All pages now use model binding and auto-validation where applicable

**Bug Fixes During Migration:**
- Fixed critical `bindParam` vs `bindValue` bug in `SystemBase::check_for_duplicate()` that caused false duplicate detection
- Added FormWriter V2 `checkboxList` documentation to `/docs/formwriter.md`

**Files Modified:**
- `/includes/PublicPageBase.php` - Enhanced getFormWriter() for V2 support
- `/includes/FormWriterV2Base.php` - Added checkboxList() method
- `/includes/SystemBase.php` - Fixed check_for_duplicate() to use bindValue instead of bindParam
- `/theme/canvas/includes/PublicPage.php` - Fixed public_footer() to call parent
- All profile view files - Migrated to V2 syntax

---

## Notes

- All critical PlainForm runtime errors have been resolved (see `/specs/implemented/model_form_helpers.md`)
- These remaining pages use FormWriter V1 which is still fully functional
- Migration should follow the `renderFormFields()` pattern for form groups (Address, PhoneNumber) or manual V2 migration for single-form pages
- No timeline pressure - these are enhancements, not critical fixes
- Profile pages migration completed successfully with all functionality preserved
