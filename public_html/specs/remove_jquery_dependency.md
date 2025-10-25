# Specification: Remove jQuery Dependency

**Status:** Phase 0 ✅ COMPLETE (Select2 Replacement) | Phase 1 (Migration) In Progress | Phase 2 (Cleanup) Pending
**Priority:** High
**Estimated Effort:** Phase 1 (Migration) 6.5-8.5 hours | Phase 2 (Cleanup) 45 minutes | Total: 7-9 hours
**Date Created:** 2025-10-24
**Related Specification:** Phase 0 Complete - See `/specs/implemented/replace_select2_with_native_dropdown.md`

---

## 1. Overview

Removing the global jQuery dependency in two phases:

**Phase 1 - Migration:** Convert code from jQuery to vanilla JavaScript
1. **Loading jQuery dynamically** when needed (already done via jquery-loader.js)
2. **Converting admin page field visibility** from jQuery show/hide to vanilla JavaScript
3. **Converting AJAX interactions** from jQuery $.ajax() to Fetch API

**Phase 2 - Cleanup:** Remove jQuery files and CDN includes from the application
1. **Removing jQuery from default page templates**
2. **Cleaning up jQuery files** from theme directories
3. **Removing bundled jQuery Validate files**

**Note:** jQuery is loaded dynamically only when needed, not globally on every page.

**Prerequisites:** Select2 replacement with native HTML5 `<input>` + `<datalist>` - See implemented specification for details (`/specs/implemented/replace_select2_with_native_dropdown.md`).

---

## 2. Current State Analysis

### 2.1 FormWriter Visibility Rules Feature (New - Simplifies Future Development)

**IMPORTANT UPDATE (2025-10-25):** FormWriter now includes built-in field visibility rules with automatic container detection. This means:

1. **For new admin pages:** Use FormWriter's `visibility_rules` parameter instead of custom jQuery/JavaScript code
   ```php
   $formwriter->dropinput('question_type', 'Question Type', [
       'options' => ['text' => 'Text', 'choice' => 'Multiple Choice'],
       'visibility_rules' => [
           'text' => ['show' => ['text_options'], 'hide' => ['choice_options']],
           'choice' => ['show' => ['choice_options'], 'hide' => ['text_options']]
       ]
   ]);
   ```

2. **Automatic container detection:** Just pass field IDs to `show`/`hide` arrays. The system automatically checks for `field_id_container` elements (which FormWriter creates by default) and falls back to the field ID if needed.

3. **Smooth fade transitions:** All visibility changes include automatic 300ms fade effects with CSS classes.

**See:** `/docs/formwriter.md` (Section 4) for complete documentation

### 2.2 jQuery Usage Map

**Global jQuery Loading Points:**
- `/includes/PublicPageFalcon.php` - jQuery 3.7.1 CDN (all public pages)
- `/includes/PublicPageTailwind.php` - jQuery 3.7.1 CDN (all public pages)
- `/includes/AdminPage-uikit3.php` - jQuery 3.4.1 CDN (all admin pages, loaded twice)
- `/theme/galactictribune/assets/js/jquery-3.4.1.min.js` - Bundled locally
- `/theme/default/assets/js/jquery-3.4.1.min.js` - Bundled locally
- `/theme/devonandjerry/assets/js/jquery-3.4.1.min.js` - Bundled locally
- `/theme/zoukphilly/assets/js/jquery-3.4.1.min.js` - Bundled locally

**Comprehensive jQuery Usage Summary:**
- **Total jQuery instances:** 333 usage instances across 29 files
- **Primary usage:** Show/hide form field visibility based on input selections
- **Secondary usage:** Form interactions and AJAX calls

**Files Using jQuery (29 files):**

**Admin Pages (13 files) - Heavy jQuery usage for conditional field visibility:**
- `/adm/admin_analytics_activitybydate.php` - Show/hide chart elements
- `/adm/admin_analytics_email_stats.php` - Show/hide analytics sections
- `/adm/admin_analytics_users.php` - Show/hide user metrics
- `/adm/admin_coupon_code_edit.php` - Show/hide coupon options
- `/adm/admin_email_template_edit.php` - Show/hide template options
- `/adm/admin_event_edit.php` - Show/hide event configuration fields
- `/adm/admin_product_edit.php` - Show/hide product options
- `/adm/admin_product_version_edit.php` - Show/hide version fields
- `/adm/admin_public_menu_edit.php` - Show/hide menu options
- `/adm/admin_question_edit.php` - **HEAVY jQuery usage** (50+ instances) for conditional validation fields based on question type
- `/adm/admin_settings_email.php` - Show/hide email provider options
- `/adm/admin_settings_payments.php` - Show/hide payment method configuration
- `/adm/admin_settings.php` - Show/hide settings sections

**Form & Include Files (6 files):**
- `/includes/FormWriterBase.php` - Base form writer utilities
- `/includes/FormWriterBootstrap.php` - Bootstrap form field rendering (Select2)
- `/includes/FormWriterHTML5.php` - HTML5 form field rendering (Select2)
- `/includes/FormWriterUIKit.php` - UIKit form field rendering (Select2)
- `/includes/FormWriterV2Bootstrap.php` - FormWriter v2 Bootstrap (jQuery test code)
- `/includes/PublicPageTailwind.php` - Public page template

**View & Template Files (7 files):**
- `/views/cart.php` - Shopping cart interactions
- `/views/post.php` - Post display interactions
- `/views/profile/subscriptions.php` - Subscription management (AJAX)
- `/utils/api_example_js_create.php` - API example with jQuery AJAX
- `/utils/api_example_js_list.php` - API example with jQuery AJAX
- `/utils/api_example_js_single.php` - API example with jQuery AJAX
- `/utils/forms_example_bootstrapv2.php` - FormWriter v2 test file

**Data & Helper Files (3 files):**
- `/data/products_class.php` - Product class (possible jQuery comment or string)
- `/includes/StripeHelper.php` - Stripe integration helper
- `/utils/products_list.php` - Products list rendering

**jQuery Used For:**
1. **Show/Hide Field Visibility** (MOST COMMON) - Conditional field display based on form input selections
2. **AJAX Interactions** - Dynamic loading of content and form handling
3. **DOM Manipulation** - General element property/attribute changes

**Note on Select2:** Completely removed in Phase 1 (see `/specs/implemented/replace_select2_with_native_dropdown.md`). AJAX dropdowns now use native HTML5 with inline vanilla JavaScript.

### 2.2 Form Validation
- ✅ JoineryValidator is pure JavaScript, independent of jQuery - no migration work needed

### 2.3 Migration Work Summary
- **Phase 1:** 13 admin pages + 4 AJAX files require jQuery to vanilla JS conversion (see Section 3)
- **Phase 2:** 3 template files need jQuery CDN removal + 4 theme files need jQuery deletion (see Section 4)
- **Out of Scope:** Plugin at `/plugins/controld/assets/js/main.js` uses jQuery but not prioritized for Phase 1/2

---

## 3. Phase 1 - Migration Tasks (Active Work)

**Select2 Replacement (Phase 0):** ✅ COMPLETE - See `/specs/implemented/replace_select2_with_native_dropdown.md`

**These tasks require active code modification to migrate from jQuery to vanilla JavaScript.**

### 3.1 Convert 13 Admin Pages to Vanilla JavaScript

Refactor conditional field visibility from jQuery show/hide to FormVisibility helper library.

**High Priority (50+ jQuery instances):**
- [ ] `/adm/admin_question_edit.php` - Reference implementation

**Medium Priority (10-50 jQuery instances):**
- [ ] `/adm/admin_analytics_activitybydate.php`
- [ ] `/adm/admin_analytics_email_stats.php`
- [ ] `/adm/admin_analytics_users.php`
- [ ] `/adm/admin_coupon_code_edit.php`
- [ ] `/adm/admin_email_template_edit.php`
- [ ] `/adm/admin_event_edit.php`
- [ ] `/adm/admin_product_edit.php`
- [ ] `/adm/admin_product_version_edit.php`
- [ ] `/adm/admin_settings_email.php`
- [ ] `/adm/admin_settings_payments.php`

**Low Priority (1-10 jQuery instances):**
- [ ] `/adm/admin_public_menu_edit.php`
- [ ] `/adm/admin_settings.php`

### 3.2 Convert AJAX from jQuery to Fetch API

Replace jQuery $.ajax() with Fetch API in 4 files.

- [ ] `/views/profile/subscriptions.php` - Replace `$("#appointments").load()` with fetch
- [ ] `/utils/api_example_js_create.php` - Replace `$.ajax()` with fetch
- [ ] `/utils/api_example_js_list.php` - Replace `$.ajax()` with fetch
- [ ] `/utils/api_example_js_single.php` - Replace `$.ajax()` with fetch

---

## 4. Phase 2 - Cleanup Tasks (Breaking Changes - Remove)

**After Phase 1 migration completes, these files and includes must be removed to prevent jQuery from being loaded globally. Removing these breaks backward compatibility but eliminates unnecessary jQuery dependency.**

### 4.1 Remove jQuery CDN from Page Templates

Remove jQuery script tags that load jQuery globally on every page.

- [ ] `/includes/PublicPageFalcon.php` - Remove jQuery 3.7.1 CDN script tag
- [ ] `/includes/PublicPageTailwind.php` - Remove jQuery 3.7.1 CDN script tag
- [ ] `/includes/AdminPage-uikit3.php` - Remove both jQuery 3.4.1 CDN script tags (appears twice)

### 4.2 Delete Bundled jQuery Files from Themes

Remove locally bundled jQuery files that are no longer needed.

- [ ] `/theme/galactictribune/assets/js/jquery-3.4.1.min.js`
- [ ] `/theme/default/assets/js/jquery-3.4.1.min.js`
- [ ] `/theme/devonandjerry/assets/js/jquery-3.4.1.min.js`
- [ ] `/theme/zoukphilly/assets/js/jquery-3.4.1.min.js`

### 4.3 Delete jQuery Validate Plugin Files

Remove jQuery Validate plugin files that are no longer used.

- [ ] All jQuery Validate files in theme directories
- [ ] Legacy jQuery Validate references from utility files

---

## 5. Implementation Support & Resources

**Status:** ✅ Resources created and ready for Phase 1 implementation

### 5.1 Utilities Already Created ✅

- ✅ **FormVisibility Helper** (`/assets/js/form-visibility-helper.js`) - 300+ lines, 30+ DOM manipulation methods

### 5.2 Quick Reference Pattern for Admin Pages

Standard pattern used across all admin pages:
```javascript
// 1. Include helper
<script src="/assets/js/form-visibility-helper.js"></script>

// 2. Define visibility rules
const visibilityRules = {
  '1': { show: ['field1'], hide: ['field2'] },
  '2': { show: ['field2'], hide: ['field1'] }
};

// 3. Listen to changes
document.getElementById('select-id').addEventListener('change', function() {
  const rules = visibilityRules[this.value];
  FormVisibility.setVisibility(rules.show, rules.hide);
});
```

---

## 6. Related References

### 6.1 Phase 0 Related Files (Completed - See implemented spec)
- ✅ **Specification:** `/specs/implemented/replace_select2_with_native_dropdown.md` - Modified 6 FormWriter classes, deleted scratch.php and select2 vendor directory

### 6.2 Related Specifications
- Phase 0 Spec: `/specs/implemented/replace_select2_with_native_dropdown.md` (Select2 Replacement)
- Project Guide: `/CLAUDE.md`
- Admin Page Documentation: `/docs/admin_pages.md`

### 6.3 Themes & Plugins Using jQuery

**Note:** After Phase 2 cleanup, themes and plugins that require jQuery must include it themselves.

**Plugins:**
- **ControlD Plugin:**
  - `assets/js/controld-plugin.js` - Event binding, device management ($(document).ready, $(document).on, .click, .change)
  - `assets/js/main.js` - Extensive DOM manipulation, sliders, mobile menu, animations, form validation ($.ajax, custom jQuery methods)
  - `views/login.php` - Input focus management ($().focus)
  - `views/cart.php` - Form field visibility (hide/show, commented out)
  - `includes/FormWriter.php` - Validation error styling (addClass/removeClass)
  - `assets/js/swiper-bundle.min.js` - Third-party carousel library

**Themes:**
- **Canvas Theme:**
  - `views/cart.php` - Prevent duplicate form submissions, disable buttons during submission
  - `views/post.php` - Comment toggle with animation (.toggle)

- **Tailwind Theme:**
  - `views/events.php` - Category selector navigation with redirect ($(location).attr)
  - `views/cart.php` - Prevent duplicate form submissions

- **Default Theme:**
  - `includes/FormWriter.php` - Form validation error styling (addClass/removeClass for error containers)

- **Devon & Jerry Theme:**
  - `includes/FormWriter.php` - Form validation error styling

- **Zouk Philly Theme:**
  - `includes/FormWriter.php` - Form validation error styling

- **Other Themes (Galactic Tribune, Falcon, Plugin, Zouk Room):** No custom jQuery usage

---

## 7. Phase 1 Implementation Notes

### 7.1 Show/Hide Field Visibility Pattern

Most admin pages follow this pattern:
1. Select/dropdown change triggers update function
2. Function shows/hides form field containers
3. Function enables/disables form controls
4. Initial call on page load sets initial state

**Solution:** Use FormVisibility helper library + vanilla JS event listeners (see Section 11 for detailed examples)

### 7.2 FormVisibility Helper Integration

When converting admin pages:
1. **Include helper:** `<script src="/assets/js/form-visibility-helper.js"></script>`
2. **Replace jQuery patterns:**
   - `$("#id").show()` → `FormVisibility.show('id')`
   - `$("#id").hide()` → `FormVisibility.hide('id')`
   - `$("#id").prop('disabled', true)` → `FormVisibility.setEnabled('id', false)`
3. **Convert event listeners:** `$("#id").change(fn)` → `document.getElementById('id').addEventListener('change', fn)`
4. **Use data structures** instead of if-else chains for better maintainability

### 7.3 AJAX to Fetch API Conversion

When converting jQuery AJAX:
1. **Basic pattern:** Replace `$.ajax()` with `fetch()`
2. **Response handling:** Use `.then()` instead of success/error callbacks
3. **Headers:** Pass as options object, not as separate parameter
4. **Data:** Use URLSearchParams for GET, FormData for files, JSON.stringify for JSON
5. **Error handling:** Check `response.ok` AND implement `.catch()`

See section 11.3 of this specification for detailed AJAX conversion patterns and examples

### 7.4 Testing Strategy

Test in this order:
1. **Syntax validation** - `php -l filename.php` passes
2. **Individual pages** - Each admin page forms work correctly
3. **Cross-browser** - Test on at least Chrome and Firefox
4. **Mobile** - Test touch interactions on actual device
5. **AJAX** - Verify Fetch calls work and data loads correctly

### 7.5 Using the Conversion Checklist

For consistent, organized conversions:
1. Use the conversion patterns provided in Section 11
2. Use the general conversion template provided
3. Test using pre/post conversion checklists
4. Verify with provided verification commands

### 7.6 Rollback Plan

All admin pages have `.bak` backups created during Phase 2:
- Use `git checkout` to revert to original if needed
- Or restore from `.bak` files
- FormVisibility and jquery-loader are additive (won't break existing code)
- Phase 1 (Select2) can be rolled back independently from Phase 2

---

## 8. Risk Assessment (Phase 1 & 2)

### 8.1 High Risk (Phase 1)
- **Show/hide logic breaks** - Could make form fields inaccessible
  - *Mitigation*: Comprehensive testing of each admin page; Use FormVisibility helper for consistency
- **AJAX conversions fail** - Endpoints might return different data formats
  - *Mitigation*: Test each converted file; Verify endpoint responses match expectations

### 8.2 Medium Risk
- **FormVisibility helper insufficient** - Edge cases not covered
  - *Mitigation*: Review implementation guide; Extend helper if needed
- **jQuery loader fails** - jQuery plugins won't initialize
  - *Mitigation*: Test conditional loading; Implement error logging
- **Cross-browser compatibility** - Fetch API not supported in older IE
  - *Mitigation*: Add Fetch polyfill if needed; Test on required browsers

### 8.3 Low Risk
- **Performance degradation** - Vanilla JS slower than jQuery
  - *Mitigation*: Benchmarking; jQuery wasn't optimized anyway
- **Mobile touch events** - Event listeners don't work on mobile
  - *Mitigation*: Test on actual mobile devices; Event listeners work fine on touch

---

## 9. Success Metrics (Phase 1 & 2)

After Phase 1 and Phase 2 implementation is complete, verify:
1. **Zero jQuery in main codebase** - Except for jquery-loader.js (conditional loader only)
2. **Page load time improvement** - Measure with and without change
3. **Form functionality parity** - All existing forms work identically
4. **Test coverage** - 100% of dropdown functionality tested
5. **Browser compatibility** - Works on all supported browsers
6. **Accessibility** - Keyboard navigation and screen reader support
7. **Performance** - AJAX performance equal to or better than Select2

---

## 10. Phase 1 Preparation Summary ✅

**Status:** Comprehensive planning and resource creation complete - Phase 1 ready for implementation

**Created Utilities & Resources:**

### 🛠️ Utility Libraries Created
- ✅ **FormVisibility Helper** (`/assets/js/form-visibility-helper.js`) - 300+ line library with 30+ DOM manipulation methods, null-safe, full documentation

### 📋 Detailed Analysis Completed
- ✅ Analyzed 13 admin pages, identified 4 AJAX files, mapped patterns, created reference implementation, organized by priority

**Implementation Approach:**
1. Use FormVisibility helper library for consistent DOM manipulation
2. Start with Priority 1 admin pages (admin_question_edit.php as reference)
3. Test each conversion thoroughly
4. Use patterns provided in Section 11
5. Final validation and cleanup

**Estimated Effort for Implementation:**
- Phase 1 (Migration):
  - Admin page refactoring: 4-5 hours (13 files)
  - AJAX conversion: 1-1.5 hours (4 files)
  - Testing: 1-2 hours
  - **Phase 1 Total: 6.5-8.5 hours**
- Phase 2 (Cleanup):
  - Template cleanup: 30 minutes
  - Delete jQuery files: 15 minutes
  - **Phase 2 Total: 45 minutes**
- **Combined Total: 7-9 hours**

---

## 11. Phase 1 Detailed Implementation Guide

### 11.1 Vanilla JavaScript Patterns

**Pattern 1: Show/Hide Elements**
```javascript
// jQuery
$("#element-id").show();
$("#element-id").hide();

// Vanilla JavaScript
document.getElementById("element-id").style.display = '';
document.getElementById("element-id").style.display = 'none';

// Using FormVisibility Helper (Recommended)
FormVisibility.show('element-id');
FormVisibility.hide('element-id');
```

**Pattern 2: Enable/Disable Form Fields**
```javascript
// jQuery
$("#checkbox-id").prop('disabled', true);

// Vanilla JavaScript
document.getElementById("checkbox-id").disabled = true;

// Using FormVisibility Helper
FormVisibility.setEnabled('checkbox-id', false);
```

**Pattern 3: Set Checked State**
```javascript
// jQuery
$("#checkbox-id").attr('checked', false);

// Vanilla JavaScript
document.getElementById("checkbox-id").checked = false;

// Using FormVisibility Helper
FormVisibility.setChecked('checkbox-id', false);
```

**Pattern 4: Get Element Value**
```javascript
// jQuery
var value = $("#element-id").val();

// Vanilla JavaScript
var value = document.getElementById("element-id").value;

// Using FormVisibility Helper
var value = FormVisibility.getValue('element-id');
```

**Pattern 5: Change Event Listener**
```javascript
// jQuery
$("#element-id").change(function() {
    // Handle change
});

// Vanilla JavaScript
document.getElementById("element-id").addEventListener('change', function(event) {
    // Handle change
});

// Using FormVisibility Helper
FormVisibility.onChange('element-id', function(event) {
    // Handle change
});
```

**Pattern 6: DOM Ready**
```javascript
// jQuery
$(document).ready(function() {
    // Code here
});

// Vanilla JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Code here
});
```

### 11.2 Admin Page Conversion Pattern

Most admin pages follow the same pattern:

**Step 1: Identify the Pattern**
- Find the select/dropdown that triggers changes
- Find the show/hide/enable/disable operations
- Identify initial state setup

**Step 2: Create Conversion**
```php
<?php
// Include helper at top of page
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
// ... other includes

// Later in the page, replace jQuery with:
?>

<!-- Include FormVisibility Helper -->
<script src="/assets/js/form-visibility-helper.js"></script>

<script type="text/javascript">
/**
 * Update form field visibility/state based on selection
 */
(function() {
    'use strict';

    // Define visibility rules by selection value
    const visibilityRules = {
        '1': {
            show: ['field1', 'field2'],
            hide: ['field3', 'field4'],
            enable: ['option1'],
            disable: ['option2', 'option3']
        },
        '2': {
            show: ['field3', 'field4'],
            hide: ['field1', 'field2'],
            enable: ['option2'],
            disable: ['option1', 'option3']
        }
        // ... more rules
    };

    function updateFormState() {
        const selectedValue = FormVisibility.getValue('select-id');
        const rules = visibilityRules[selectedValue] || visibilityRules['1'];

        // Apply all rules
        FormVisibility.setVisibility(rules.show, rules.hide);
        FormVisibility.setMultipleEnabled(rules.enable, true);
        FormVisibility.setMultipleEnabled(rules.disable, false);
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        const selectElement = document.getElementById('select-id');
        if (!selectElement) return;

        // Set initial state
        updateFormState();

        // Add change listener
        selectElement.addEventListener('change', updateFormState);
    });
})();
</script>
```

### 11.3 AJAX to Fetch API Conversion

**Basic Conversion Pattern:**

```javascript
// jQuery AJAX
$.ajax({
    type: "GET",
    url: '/api/endpoint',
    data: { key: value },
    success: function(data) {
        console.log('Success:', data);
    },
    error: function() {
        console.error('Error');
    }
});

// Fetch API
fetch('/api/endpoint?key=' + encodeURIComponent(value))
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        console.log('Success:', data);
    })
    .catch(error => {
        console.error('Error:', error);
    });
```

**POST Request Pattern:**

```javascript
// jQuery
$.ajax({
    type: 'POST',
    url: '/api/create',
    contentType: 'application/json',
    data: JSON.stringify({ name: 'value' }),
    success: function(data) { /* ... */ }
});

// Fetch
fetch('/api/create', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name: 'value' })
})
    .then(response => response.json())
    .then(data => { /* ... */ });
```

**Form Data Pattern:**

```javascript
// jQuery
$.ajax({
    type: 'POST',
    url: '/upload',
    data: new FormData(document.getElementById('myForm')),
    processData: false,
    contentType: false,
    success: function(data) { /* ... */ }
});

// Fetch
const formData = new FormData(document.getElementById('myForm'));
fetch('/upload', {
    method: 'POST',
    body: formData
    // Don't set Content-Type - browser sets it with boundary
})
    .then(response => response.json())
    .then(data => { /* ... */ });
```

**Load HTML Pattern:**

```javascript
// jQuery
$("#appointments").load('endpoint');

// Fetch
fetch('endpoint')
    .then(response => response.text())
    .then(html => {
        document.getElementById('appointments').innerHTML = html;
    });
```

### 11.4 Admin Pages to Convert Checklist

#### High Priority (50+ jQuery instances)
- [ ] `/adm/admin_question_edit.php` - Reference implementation

#### Medium Priority (10-50 jQuery instances)
- [ ] `/adm/admin_analytics_activitybydate.php`
- [ ] `/adm/admin_analytics_email_stats.php`
- [ ] `/adm/admin_analytics_users.php`
- [ ] `/adm/admin_coupon_code_edit.php`
- [ ] `/adm/admin_email_template_edit.php`
- [ ] `/adm/admin_event_edit.php`
- [ ] `/adm/admin_product_edit.php`
- [ ] `/adm/admin_product_version_edit.php`
- [ ] `/adm/admin_settings_email.php`
- [ ] `/adm/admin_settings_payments.php`

#### Low Priority (1-10 jQuery instances)
- [ ] `/adm/admin_public_menu_edit.php`
- [ ] `/adm/admin_settings.php`

### 11.5 AJAX Files to Convert Checklist

- [ ] `/views/profile/subscriptions.php` - Convert `$("#appointments").load()` to fetch
- [ ] `/utils/api_example_js_list.php` - Convert `$.ajax()` to fetch
- [ ] `/utils/api_example_js_create.php` - Convert `$.ajax()` to fetch
- [ ] `/utils/api_example_js_single.php` - Convert `$.ajax()` to fetch

### 11.6 Conversion Workflow

**For Each File:**

1. **Create Backup**
   ```bash
   cp /path/to/file.php /path/to/file.php.bak
   ```

2. **Include FormVisibility Helper (if needed)**
   ```html
   <script src="/assets/js/form-visibility-helper.js"></script>
   ```

3. **Find jQuery Patterns**
   - `$(document).ready(...)` → `document.addEventListener('DOMContentLoaded', ...)`
   - `$("#id").show()` → `FormVisibility.show('id')`
   - `$("#id").hide()` → `FormVisibility.hide('id')`
   - `$("#id").prop('disabled', ...)` → `FormVisibility.setEnabled('id', ...)`
   - `$("#id").change(fn)` → `document.getElementById('id').addEventListener('change', fn)`

4. **Test**
   ```bash
   php -l /path/to/file.php
   ```

5. **Browser Testing**
   - Check form interactions
   - Verify all show/hide operations work
   - Check browser console for errors

### 11.7 Quick Reference - jQuery to Vanilla JS

| jQuery | Vanilla JS | FormVisibility |
|--------|-----------|-----------------|
| `$("#id").val()` | `document.getElementById('id').value` | `FormVisibility.getValue('id')` |
| `$("#id").show()` | `document.getElementById('id').style.display = ''` | `FormVisibility.show('id')` |
| `$("#id").hide()` | `document.getElementById('id').style.display = 'none'` | `FormVisibility.hide('id')` |
| `$("#id").prop('disabled', true)` | `document.getElementById('id').disabled = true` | `FormVisibility.setEnabled('id', false)` |
| `$("#id").attr('checked', false)` | `document.getElementById('id').checked = false` | `FormVisibility.setChecked('id', false)` |
| `$("#id").change(fn)` | `document.getElementById('id').addEventListener('change', fn)` | `FormVisibility.onChange('id', fn)` |
| `$(document).ready(fn)` | `document.addEventListener('DOMContentLoaded', fn)` | N/A |
| `$.ajax({...})` | `fetch(...).then(...).catch(...)` | N/A |

---

## 12. Future Considerations

After jQuery removal:
1. **Evaluate other jQuery plugins** - Check if any other plugins require jQuery
2. **Modernize remaining JavaScript** - Consider ES6 modules and bundling
3. **Performance monitoring** - Track metrics to ensure improvements are maintained
