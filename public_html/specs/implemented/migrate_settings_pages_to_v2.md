# Specification: Migrate Settings Pages to FormWriter V2 (Special Case)

**Status:** Planning
**Priority:** Medium
**Date Created:** 2025-10-30
**Related Specifications:**
- `/specs/migrate_admin_forms_to_formwriter_v2.md` - Main FormWriter V2 migration spec

---

## 1. Overview

### What Makes Settings Pages Special?

The three settings pages have **extensive jQuery-based visibility logic** that controls which fields are shown/hidden based on other field values. Rather than refactoring this logic to use FormWriter V2's native visibility rules, we will:

1. **Convert form fields from V1 to V2** - Standard field conversion
2. **Keep existing jQuery logic** - Convert jQuery to plain JavaScript but maintain the same structure
3. **Skip V2 visibility features** - Don't use FormWriter V2's `visibility_rules` option

### Why This Approach?

- ✅ **Faster migration** - No need to redesign visibility logic
- ✅ **Lower risk** - Preserve existing tested behavior
- ✅ **Easier to maintain** - Keep familiar patterns
- ✅ **Incremental improvement** - Can refactor to V2 visibility later if needed

---

## 2. Pages to Migrate

### 2.1 admin_settings.php
**Complexity:** 🟡 HIGH (due to size)
**Form Fields:** ~100+ fields
**Visibility Logic:**
- Booking fields (calendly_*)
- Blog fields (show_comments, comments_*, blog_footer_text)
- Social media fields (social_*_link)
- Tracking fields (tracking_code)
- Plugin theme selector (active_theme_plugin)

### 2.2 admin_settings_email.php
**Complexity:** 🟢 MEDIUM
**Form Fields:** ~20-30 fields
**Visibility Logic:**
- Email provider-specific fields
- SMTP configuration fields

### 2.3 admin_settings_payments.php
**Complexity:** 🟢 MEDIUM
**Form Fields:** ~20-30 fields
**Visibility Logic:**
- Payment provider-specific fields (Stripe vs PayPal)
- Test mode vs production mode fields

---

## 3. Migration Pattern

### Step 1: Convert FormWriter Initialization

```php
// BEFORE (V1)
$formwriter = $page->getFormWriter('form1');

$validation_rules = array();
$validation_rules['webDir']['weburl']['value'] = 'true';
$validation_rules['apache_error_log']['remote']['value'] = '/ajax/validate_file_ajax';
echo $formwriter->set_validate($validation_rules);

echo $formwriter->begin_form('form', 'POST', '/admin/admin_settings');
```

```php
// AFTER (V2)
$formwriter = $page->getFormWriter('form1', 'v2');

$formwriter->begin_form();
```

**Key Changes:**
- Add `'v2'` parameter to `getFormWriter()`
- Remove `set_validate()` calls (validation moves to field level)
- Remove parameters from `begin_form()`

---

### Step 2: Convert Individual Fields

#### TextInput Conversion

```php
// BEFORE (V1)
echo $formwriter->textinput("Label", 'field_name', '', 20, $settings->get_setting('field_name'), 'placeholder', 255, '');
```

```php
// AFTER (V2)
$formwriter->textinput('field_name', 'Label', [
    'value' => $settings->get_setting('field_name'),
    'placeholder' => 'placeholder'
]);
```

#### DropInput Conversion

```php
// BEFORE (V1)
$optionvals = array("Yes"=>1, 'No' => 0);
echo $formwriter->dropinput("Label", "field_name", '', $optionvals, $settings->get_setting('field_name'), '', FALSE);
```

```php
// AFTER (V2)
$formwriter->dropinput('field_name', 'Label', [
    'options' => ["Yes"=>1, 'No' => 0],
    'value' => $settings->get_setting('field_name')
]);
```

#### TextBox (Textarea) Conversion

```php
// BEFORE (V1)
echo $formwriter->textbox('Label', 'field_name', 'ctrlHolder', 10, 80, $settings->get_setting('field_name'), '', 'no');
```

```php
// AFTER (V2)
$formwriter->textbox('field_name', 'Label', [
    'value' => $settings->get_setting('field_name'),
    'rows' => 10,
    'cols' => 80,
    'htmlmode' => 'no'
]);
```

#### Field-Level Validation

```php
// BEFORE (V1 - centralized)
$validation_rules['webDir']['weburl']['value'] = 'true';
$validation_rules['apache_error_log']['remote']['value'] = '/ajax/validate_file_ajax';

// AFTER (V2 - per field)
$formwriter->textinput('webDir', 'Web Domain', [
    'value' => $settings->get_setting('webDir'),
    'validation' => ['weburl' => true]
]);

$formwriter->textinput('apache_error_log', 'Apache Error Log Path', [
    'value' => $settings->get_setting('apache_error_log'),
    'validation' => [
        'remote' => [
            'url' => '/ajax/validate_file_ajax',
            'message' => 'File does not exist or is not readable'
        ]
    ]
]);
```

---

### Step 3: Convert jQuery to Plain JavaScript

**IMPORTANT:** Keep the same structure, just remove jQuery dependency.

#### Example: Booking Fields Visibility

```javascript
// BEFORE (jQuery)
function set_booking_choices(){
    var value = $("#bookings_active").val();
    if(value == 0 || value == ''){
        $("#calendly_organization_uri_container").hide();
        $("#calendly_organization_name_container").hide();
        $("#calendly_api_key_container").hide();
        $("#calendly_api_token_container").hide();
    } else {
        $("#calendly_organization_uri_container").show();
        $("#calendly_organization_name_container").show();
        $("#calendly_api_key_container").show();
        $("#calendly_api_token_container").show();
    }
}

$(document).ready(function() {
    set_booking_choices();
    $("#bookings_active").change(function() {
        set_booking_choices();
    });
});
```

```javascript
// AFTER (Plain JavaScript)
function set_booking_choices(){
    const bookingsActive = document.getElementById('bookings_active');
    const value = bookingsActive ? bookingsActive.value : '';

    const containers = [
        'calendly_organization_uri_container',
        'calendly_organization_name_container',
        'calendly_api_key_container',
        'calendly_api_token_container'
    ];

    const display = (value == 0 || value == '') ? 'none' : 'block';

    containers.forEach(function(containerId) {
        const container = document.getElementById(containerId);
        if (container) {
            container.style.display = display;
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    set_booking_choices();

    const bookingsActive = document.getElementById('bookings_active');
    if (bookingsActive) {
        bookingsActive.addEventListener('change', set_booking_choices);
    }
});
```

#### Pattern for All Visibility Functions:

```javascript
// STANDARD PATTERN
function set_[feature]_choices(){
    // 1. Get the controlling field's value
    const controlField = document.getElementById('control_field_id');
    const value = controlField ? controlField.value : '';

    // 2. Define list of dependent containers
    const containers = ['container1', 'container2', 'container3'];

    // 3. Determine visibility based on value
    const display = (value == 'expected_value') ? 'block' : 'none';

    // 4. Apply to all containers
    containers.forEach(function(containerId) {
        const container = document.getElementById(containerId);
        if (container) {
            container.style.display = display;
        }
    });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    set_[feature]_choices();

    // Attach change listener
    const controlField = document.getElementById('control_field_id');
    if (controlField) {
        controlField.addEventListener('change', set_[feature]_choices);
    }
});
```

---

### Step 4: Convert Submit Button

```php
// BEFORE (V1)
echo $formwriter->start_buttons();
echo $formwriter->new_form_button('Submit');
echo $formwriter->end_buttons();
echo $formwriter->end_form();
```

```php
// AFTER (V2)
$formwriter->submitbutton('submit_button', 'Submit');
$formwriter->end_form();
```

---

## 4. Special Cases in admin_settings.php

### 4.1 Conditional Read-Only Fields

Many fields are conditionally read-only based on whether they're hardcoded in `Globalvars_site.php`:

```php
// Read Globalvars_site.php to determine what's hardcoded
$globalvars_site_path = dirname(__DIR__, 2) . '/config/Globalvars_site.php';
$globalvars_hardcoded = array();

if (file_exists($globalvars_site_path)) {
    $globalvars_content = file_get_contents($globalvars_site_path);

    if (preg_match_all('/\$this->settings\[\'([^\']+)\'\]\s*=\s*[\'"]([^\'"]*)[\'"];/', $globalvars_content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $globalvars_hardcoded[$match[1]] = $match[2];
        }
    }
}

// Use conditional rendering
if (isset($globalvars_hardcoded['baseDir'])) {
    $formwriter->textinput('baseDir_readonly', 'Base path (Loaded from Globalvars_site.php)', [
        'value' => $settings->get_setting('baseDir'),
        'readonly' => true
    ]);
} else {
    $formwriter->textinput('baseDir', 'Base path', [
        'value' => $settings->get_setting('baseDir')
    ]);
}
```

**Pattern:** Keep this exact structure, just convert the FormWriter calls to V2.

---

### 4.2 Two-Column Layout Sections

Several sections use Bootstrap grid for two-column layouts:

```php
// Composer section with two-column layout
echo '<div class="row">';
echo '<div class="col-md-6">';
echo '<h5>Composer Settings</h5>';
$formwriter->textinput('composerAutoLoad', 'Composer Path', [
    'value' => $settings->get_setting('composerAutoLoad')
]);
echo '</div>';
echo '<div class="col-md-6">';
echo '<h5>Installed Packages</h5>';
echo '<div style="min-height: 150px;">...</div>';
echo '</div>';
echo '</div>';
```

**Pattern:** Keep the HTML structure exactly as-is, only convert FormWriter calls.

---

### 4.3 Plugin Settings Forms

At the end of admin_settings.php, there's dynamic plugin settings inclusion:

```php
// Scan and include plugin settings forms
$plugins = LibraryFunctions::list_plugins();
foreach($plugins as $plugin) {
    $settings_form = PathHelper::getIncludePath("plugins/$plugin/settings_form.php");
    if(file_exists($settings_form)) {
        echo "<div class='plugin-settings-section'>";
        echo "<h4>" . ucfirst($plugin) . " Plugin</h4>";
        include($settings_form);
        echo "</div>";
    }
}
```

**Pattern:** Leave this section completely untouched. Plugin settings forms will use whatever FormWriter version they're written for.

---

## 5. Common Patterns & Shortcuts

### 5.1 Repeated Yes/No Dropdowns

Since these appear frequently, you can define a reusable array:

```php
// At the top of the file
$yes_no_options = ["Yes"=>1, 'No' => 0];

// Then use throughout
$formwriter->dropinput('register_active', 'Registration active', [
    'options' => $yes_no_options,
    'value' => $settings->get_setting('register_active')
]);

$formwriter->dropinput('subscriptions_active', 'Subscriptions active', [
    'options' => $yes_no_options,
    'value' => $settings->get_setting('subscriptions_active')
]);
```

### 5.2 Settings Field Pattern

Most fields follow this pattern:

```php
$formwriter->textinput('[setting_name]', '[Label]', [
    'value' => $settings->get_setting('[setting_name]')
]);
```

---

## 6. jQuery to JavaScript Conversion Reference

### Common Conversions

| jQuery | Plain JavaScript |
|--------|------------------|
| `$("#id")` | `document.getElementById('id')` |
| `$("#id").val()` | `document.getElementById('id').value` |
| `$("#id").val(value)` | `document.getElementById('id').value = value` |
| `$("#id").hide()` | `document.getElementById('id').style.display = 'none'` |
| `$("#id").show()` | `document.getElementById('id').style.display = 'block'` |
| `$("#id").change(fn)` | `document.getElementById('id').addEventListener('change', fn)` |
| `$(document).ready(fn)` | `document.addEventListener('DOMContentLoaded', fn)` |

### Loop Conversion

```javascript
// jQuery
["#field1", "#field2", "#field3"].forEach(function(selector) {
    $(selector).hide();
});

// Plain JavaScript
['field1', 'field2', 'field3'].forEach(function(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
});
```

---

## 7. Testing Strategy

### For Each Settings Page:

1. **Visual Verification**
   - Load the page
   - Verify all fields render correctly
   - Check field spacing and layout

2. **Visibility Logic Testing**
   - Test each dropdown that controls visibility
   - Verify fields show/hide as expected
   - Test on page load (initial state)
   - Test after changing values

3. **Form Submission Testing**
   - Fill out fields
   - Submit form
   - Verify settings save correctly
   - Check database values

4. **Validation Testing**
   - Test required fields
   - Test remote validation (file paths, URLs)
   - Test format validation (webDir, emails)

---

## 8. Migration Checklist Per Page

### admin_settings.php
- [ ] Convert FormWriter initialization to V2
- [ ] Convert all textinput fields (~50+ fields)
- [ ] Convert all dropinput fields (~30+ fields)
- [ ] Convert all textbox fields (~5+ fields)
- [ ] Convert jQuery visibility functions to plain JavaScript:
  - [ ] `set_booking_choices()`
  - [ ] `set_blog_choices()`
  - [ ] `set_social_choices()` + `check_social_content()`
  - [ ] `set_tracking_choices()`
  - [ ] `set_plugin_theme_choices()`
- [ ] Convert submit button
- [ ] Test all visibility logic
- [ ] Run syntax validation: `php -l adm/admin_settings.php`
- [ ] Run method existence test
- [ ] Manual testing

### admin_settings_email.php
- [ ] Convert FormWriter initialization to V2
- [ ] Convert all form fields
- [ ] Convert jQuery visibility logic to plain JavaScript
- [ ] Convert submit button
- [ ] Test visibility logic
- [ ] Run syntax validation
- [ ] Run method existence test
- [ ] Manual testing

### admin_settings_payments.php
- [ ] Convert FormWriter initialization to V2
- [ ] Convert all form fields
- [ ] Convert jQuery visibility logic to plain JavaScript
- [ ] Convert submit button
- [ ] Test visibility logic
- [ ] Run syntax validation
- [ ] Run method existence test
- [ ] Manual testing

---

## 9. Estimated Time

| Page | Fields | Visibility Functions | Est. Time |
|------|--------|---------------------|-----------|
| admin_settings.php | ~100 | 5 functions | 3-4 hours |
| admin_settings_email.php | ~25 | 2-3 functions | 1-2 hours |
| admin_settings_payments.php | ~25 | 2-3 functions | 1-2 hours |

**Total Estimate:** 5-8 hours

---

## 10. Important Notes

### DO NOT:
- ❌ Use FormWriter V2's `visibility_rules` feature
- ❌ Refactor the visibility logic structure
- ❌ Change field names or values
- ❌ Modify the logic files (they don't need changes)
- ❌ Touch plugin settings forms

### DO:
- ✅ Convert FormWriter method calls to V2 signatures
- ✅ Convert jQuery to plain JavaScript (same logic)
- ✅ Keep all HTML structure identical
- ✅ Maintain field order and grouping
- ✅ Preserve all conditional logic
- ✅ Test thoroughly after conversion

---

## 11. Example: Complete Function Conversion

### BEFORE (jQuery)
```javascript
function set_blog_choices(){
    var value = $("#blog_active").val();
    if(value == 0 || value == ''){
        $("#show_comments_container").hide();
        $("#comments_active_container").hide();
        $("#comments_unregistered_users_container").hide();
        $("#default_comment_status_container").hide();
        $("#comment_notification_emails_container").hide();
        $("#anti_spam_answer_comments_container").hide();
        $("#use_captcha_comments_container").hide();
        $("#blog_footer_text_container").hide();
    } else {
        $("#show_comments_container").show();
        $("#comments_active_container").show();
        $("#comments_unregistered_users_container").show();
        $("#default_comment_status_container").show();
        $("#comment_notification_emails_container").show();
        $("#anti_spam_answer_comments_container").show();
        $("#use_captcha_comments_container").show();
        $("#blog_footer_text_container").show();
    }
}

$(document).ready(function() {
    set_blog_choices();
    $("#blog_active").change(function() {
        set_blog_choices();
    });
});
```

### AFTER (Plain JavaScript)
```javascript
function set_blog_choices(){
    const blogActive = document.getElementById('blog_active');
    const value = blogActive ? blogActive.value : '';

    const containers = [
        'show_comments_container',
        'comments_active_container',
        'comments_unregistered_users_container',
        'default_comment_status_container',
        'comment_notification_emails_container',
        'anti_spam_answer_comments_container',
        'use_captcha_comments_container',
        'blog_footer_text_container'
    ];

    const display = (value == 0 || value == '') ? 'none' : 'block';

    containers.forEach(function(containerId) {
        const container = document.getElementById(containerId);
        if (container) {
            container.style.display = display;
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    set_blog_choices();

    const blogActive = document.getElementById('blog_active');
    if (blogActive) {
        blogActive.addEventListener('change', set_blog_choices);
    }
});
```

---

## 12. Success Criteria

A settings page migration is complete when:

1. ✅ All form fields use FormWriter V2 signatures
2. ✅ No jQuery dependencies remain in visibility logic
3. ✅ All visibility functions work identically to before
4. ✅ Form submission saves all settings correctly
5. ✅ No PHP syntax errors
6. ✅ No undefined method calls
7. ✅ Manual testing confirms all features work
8. ✅ No console errors in browser

---

## 13. Future Improvements (Optional)

After this migration is complete, these pages could be further improved by:

1. Refactoring visibility logic to use FormWriter V2's native `visibility_rules`
2. Consolidating repeated patterns into helper functions
3. Breaking large forms into tabbed sections
4. Adding client-side validation hints
5. Improving responsive layout for mobile

**These improvements are out of scope for this migration.**
