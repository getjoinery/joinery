# Joinery Validation System Specification

**STATUS: Phase 2 Complete - Pure JavaScript Implementation**

**IMPLEMENTATION NOTE:** The original plan called for a jQuery compatibility shim. The actual implementation took a different, better approach: a pure JavaScript validation system with NO jQuery dependencies and cleaner syntax. See Phase 2 for details.

## Summary
Create a vanilla JavaScript validation library that replaces jQuery Validation plugin. The implementation provides a standalone validation system with clean, modern JavaScript APIs.

## The Problem
- 62 PHP files call `set_validate()` which outputs jQuery validation JavaScript
- Removing jQuery means these forms break
- Previous attempts over-engineered the solution

## The Solution
Create `joinery-validate.js` as a standalone JavaScript file that provides the exact same API as jQuery Validation plugin. This file will be included in page templates, replacing the existing jQuery validation script includes.

## Current Setup
jQuery validation is currently included in 3 template files:
1. `/includes/AdminPage-uikit3.php` - Local file: `/assets/js/jquery.validate-1.9.1.js`
2. `/includes/PublicPageFalcon.php` - CDN: `https://cdn.jsdelivr.net/npm/jquery-validation@1.21.0/dist/jquery.validate.min.js`
3. `/includes/PublicPageTailwind.php` - CDN: `https://cdn.jsdelivr.net/npm/jquery-validation@1.21.0/dist/jquery.validate.min.js`

## Implementation

### Step 1: Create Joinery Validation Library (`/assets/js/joinery-validate.js`)

```javascript
/**
 * Joinery Validation System - Drop-in replacement for jQuery validation
 * Provides exact same API, works with all existing PHP code unchanged
 */
(function() {
    'use strict';

    // Create minimal jQuery-like wrapper if jQuery doesn't exist
    if (!window.$) {
        window.$ = window.jQuery = function(selector) {
            const element = typeof selector === 'string'
                ? document.querySelector(selector)
                : selector;

            return {
                validate: function(options) {
                    if (element && element.tagName === 'FORM') {
                        new FormValidator(element, options);
                    }
                    return this;
                },
                val: function() {
                    return element ? element.value : '';
                },
                addClass: function(className) {
                    if (element) element.classList.add(className);
                    return this;
                },
                removeClass: function(className) {
                    if (element) element.classList.remove(className);
                    return this;
                }
            };
        };
    }

    // Main validator class
    class FormValidator {
        constructor(form, options = {}) {
            this.form = form;
            this.options = options;
            this.rules = options.rules || {};
            this.messages = options.messages || {};

            // jQuery validation options we need to support
            this.errorElement = options.errorElement || 'label';
            this.errorClass = options.errorClass || 'error';
            this.validClass = options.validClass || 'valid';
            this.errorPlacement = options.errorPlacement;
            this.highlight = options.highlight;
            this.unhighlight = options.unhighlight;
            this.submitHandler = options.submitHandler;
            this.invalidHandler = options.invalidHandler;

            // Store custom validators
            this.methods = FormValidator.methods || {};

            this.init();
        }

        init() {
            // Prevent browser's default validation UI
            this.form.setAttribute('novalidate', '');

            // Submit handler
            this.form.addEventListener('submit', (e) => {
                e.preventDefault();

                const isValid = this.validateForm();

                if (isValid) {
                    if (this.submitHandler) {
                        this.submitHandler(this.form);
                    } else {
                        this.form.submit();
                    }
                } else if (this.invalidHandler) {
                    this.invalidHandler(e, this);
                }
            });

            // Blur validation for each field
            Object.keys(this.rules).forEach(fieldName => {
                // Handle quoted field names (for arrays like "products[]")
                const cleanName = fieldName.replace(/['"]/g, '');
                const field = this.form.elements[cleanName];

                if (field) {
                    // Handle NodeList (radio/checkbox groups)
                    if (field instanceof NodeList) {
                        field.forEach(f => {
                            f.addEventListener('change', () => this.validateField(cleanName));
                        });
                    } else {
                        field.addEventListener('blur', () => this.validateField(cleanName));
                        field.addEventListener('change', () => this.validateField(cleanName));
                    }
                }
            });
        }

        validateForm() {
            let isValid = true;

            Object.keys(this.rules).forEach(fieldName => {
                const cleanName = fieldName.replace(/['"]/g, '');
                if (!this.validateField(cleanName)) {
                    isValid = false;
                }
            });

            return isValid;
        }

        validateField(fieldName) {
            const field = this.form.elements[fieldName];
            const rules = this.rules[fieldName] || this.rules[`"${fieldName}"`];

            if (!field || !rules) return true;

            let isValid = true;
            let errorMessage = '';

            // Get field value
            let value = this.getFieldValue(field);

            // Check each validation rule
            for (const [ruleName, ruleValue] of Object.entries(rules)) {
                const validator = this.validators[ruleName] || FormValidator.methods[ruleName];

                if (validator) {
                    // jQuery validation format: rule can be boolean, value, or object with 'value' property
                    const param = ruleValue === true ? true
                                : ruleValue.value !== undefined ? ruleValue.value
                                : ruleValue;

                    const result = validator.call(this, value, field, param);

                    if (!result) {
                        isValid = false;
                        // Get error message
                        errorMessage = this.messages[fieldName]?.[ruleName]
                                    || (ruleValue.message)
                                    || this.defaultMessages[ruleName]
                                    || `Please check this field`;
                        break;
                    }
                }
            }

            // Update field state
            if (!isValid) {
                this.showError(field, errorMessage);
            } else {
                this.clearError(field);
            }

            return isValid;
        }

        getFieldValue(field) {
            if (field instanceof NodeList) {
                // Radio buttons or checkboxes
                if (field[0].type === 'radio') {
                    for (let f of field) {
                        if (f.checked) return f.value;
                    }
                    return '';
                } else if (field[0].type === 'checkbox') {
                    const values = [];
                    for (let f of field) {
                        if (f.checked) values.push(f.value);
                    }
                    return values.length > 0 ? values : '';
                }
            } else if (field.type === 'checkbox') {
                return field.checked ? field.value : '';
            }
            return field.value;
        }

        showError(field, message) {
            // Clear existing error
            this.clearError(field);

            // Handle field highlighting
            const element = field instanceof NodeList ? field[0] : field;

            if (this.highlight) {
                this.highlight.call(this, element, this.errorClass);
            } else {
                element.classList.add(this.errorClass);
                element.classList.remove(this.validClass);
            }

            // Create error element
            const error = document.createElement(this.errorElement);
            error.className = this.errorClass;
            error.textContent = message;

            // Place error element
            if (this.errorPlacement) {
                // jQuery-style error placement (needs jQuery wrapper simulation)
                const $error = { 0: error, appendTo: function(el) { el.appendChild(error); }};
                const $element = $(element);
                this.errorPlacement($error, $element);
            } else {
                // Default: after the element
                element.parentNode.insertBefore(error, element.nextSibling);
            }
        }

        clearError(field) {
            const element = field instanceof NodeList ? field[0] : field;

            // Remove error message
            const container = element.closest('.errorplacement') || element.parentNode;
            const error = container.querySelector(`.${this.errorClass}`);
            if (error && error.tagName === this.errorElement.toUpperCase()) {
                error.remove();
            }

            // Handle field unhighlighting
            if (this.unhighlight) {
                this.unhighlight.call(this, element, this.errorClass);
            } else {
                element.classList.remove(this.errorClass);
                element.classList.add(this.validClass);
            }
        }

        // Built-in validators (jQuery validation compatible)
        validators = {
            required: function(value, element, param) {
                if (element.type === 'checkbox') {
                    return element.checked;
                }
                return value.length > 0;
            },

            email: function(value, element) {
                return !value || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
            },

            url: function(value, element) {
                return !value || /^https?:\/\/.+/.test(value);
            },

            number: function(value, element) {
                return !value || !isNaN(value);
            },

            digits: function(value, element) {
                return !value || /^\d+$/.test(value);
            },

            minlength: function(value, element, param) {
                return !value || value.length >= param;
            },

            maxlength: function(value, element, param) {
                return !value || value.length <= param;
            },

            min: function(value, element, param) {
                return !value || Number(value) >= Number(param);
            },

            max: function(value, element, param) {
                return !value || Number(value) <= Number(param);
            },

            equalTo: function(value, element, param) {
                const other = document.querySelector(param);
                return !value || value === (other ? other.value : '');
            },

            remote: async function(value, element, param) {
                // AJAX validation compatibility
                if (!value) return true;

                const url = typeof param === 'string' ? param : param.url;
                const data = param.data || {};
                data[element.name] = value;

                try {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams(data)
                    });
                    const result = await response.text();
                    return result === 'true' || result === '1';
                } catch (e) {
                    return true; // Assume valid if request fails
                }
            }
        };

        // Default error messages
        defaultMessages = {
            required: "This field is required.",
            email: "Please enter a valid email address.",
            url: "Please enter a valid URL.",
            number: "Please enter a valid number.",
            digits: "Please enter only digits.",
            minlength: "Please enter at least {0} characters.",
            maxlength: "Please enter no more than {0} characters.",
            min: "Please enter a value greater than or equal to {0}.",
            max: "Please enter a value less than or equal to {0}.",
            equalTo: "Please enter the same value again.",
            remote: "Please fix this field."
        };
    }

    // Static methods storage
    FormValidator.methods = {};

    // jQuery.validator.addMethod compatibility
    if (window.jQuery || window.$) {
        const $ = window.jQuery || window.$;

        $.validator = {
            addMethod: function(name, method, message) {
                FormValidator.methods[name] = method;
                if (message) {
                    FormValidator.prototype.defaultMessages[name] = message;
                }
            },

            // Support for $.validator.methods access
            methods: FormValidator.methods,

            // Support for $.validator.messages access
            messages: FormValidator.prototype.defaultMessages
        };
    }

    // Expose globally for debugging
    window.FormValidator = FormValidator;

})();

// Custom validators from the codebase
jQuery.validator.addMethod("phoneUS", function(phone_number, element) {
    phone_number = phone_number.replace(/\s+/g, "");
    return this.optional(element) || phone_number.length > 9 &&
        phone_number.match(/^(1-?)?(\([2-9]\d{2}\)|[2-9]\d{2})-?[2-9]\d{2}-?\d{4}$/);
}, "Please specify a valid phone number");

jQuery.validator.addMethod("timeGreaterThan", function(value, element, param) {
    const startVal = $(param).val();
    if (!startVal || !value) {
        return true;
    }
    const parseTime = (str) => {
        const parts = str.split(":");
        return parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
    };
    return parseTime(value) > parseTime(startVal);
}, "End time must be after start time");

jQuery.validator.addMethod("regex", function(value, element, regexp) {
    const re = new RegExp(regexp);
    return this.optional(element) || re.test(value);
}, "Please check your input.");

jQuery.validator.addMethod("stripePublishableKey", function(value, element) {
    if (!value) return true;
    return /^pk_(live|test)_[a-zA-Z0-9]{24,}$/.test(value);
}, "Must be a valid publishable key starting with pk_live_ or pk_test_");

jQuery.validator.addMethod("stripeSecretKey", function(value, element) {
    if (!value) return true;
    return /^sk_(live|test)_[a-zA-Z0-9]{24,}$/.test(value);
}, "Must be a valid secret key starting with sk_live_ or sk_test_");

jQuery.validator.addMethod("stripeTestPublishableKey", function(value, element) {
    if (!value) return true;
    return /^pk_test_[a-zA-Z0-9]{24,}$/.test(value);
}, "Must be a valid test publishable key starting with pk_test_");

jQuery.validator.addMethod("stripeTestSecretKey", function(value, element) {
    if (!value) return true;
    return /^sk_test_[a-zA-Z0-9]{24,}$/.test(value);
}, "Must be a valid test secret key starting with sk_test_");
```

### Step 2: Create Comprehensive Test File

Create `/utils/forms_example_bootstrap_native.php` with ALL FormWriter field types and validation:

```php
<?php
/**
 * Complete Bootstrap Forms Test - Joinery Validation System
 *
 * Tests ALL FormWriter field types with comprehensive validation rules.
 * This matches all fields from forms_example_bootstrap_experimental.php
 *
 * FIELD TYPES INCLUDED:
 * ✓ text() - Read-only text display
 * ✓ hiddeninput() - Hidden fields
 * ✓ textinput() - All types: text, email, tel, url, number, password
 * ✓ passwordinput() - Password with confirmation
 * ✓ textbox() - Textarea with/without editor
 * ✓ checkboxinput() - Single checkbox
 * ✓ checkboxList() - Multiple checkboxes
 * ✓ radioinput() - Radio button group
 * ✓ dropinput() - Select dropdown
 * ✓ dateinput() - Date picker
 * ✓ timeinput() - Time picker
 * ✓ datetimeinput() - Separate date & time fields
 * ✓ datetimeinput2() - Combined datetime field
 * ✓ fileinput() - File upload (single and multiple)
 * ✓ All layout variations: default, horizontal, row
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('/includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$session = SessionControl::get_instance();
$session->check_permission(8);

$page = new PublicPage();
$hoptions = array(
    'is_valid_page' => true,
    'title' => 'Complete Bootstrap Forms Test - Joinery Validation System'
);
$page->public_header($hoptions, NULL);

echo PublicPage::BeginPage('Complete Bootstrap Forms Test - All Field Types with Validation');

// Use standard FormWriterBootstrap
require_once(PathHelper::getIncludePath('/includes/FormWriterBootstrap.php'));
$formwriter = new FormWriterBootstrap('form1');

// ==============================================
// COMPREHENSIVE VALIDATION RULES - ALL FIELDS
// ==============================================
$validation_rules = array();

// === STANDARD INPUT TYPES ===

// 1. Text Input - Required
$validation_rules['text_required']['required']['value'] = 'true';
$validation_rules['text_required']['minlength']['value'] = '3';
$validation_rules['text_required']['maxlength']['value'] = '50';

// 2. Email Input
$validation_rules['email_field']['required']['value'] = 'true';
$validation_rules['email_field']['email']['value'] = 'true';

// 3. URL Input
$validation_rules['url_field']['required']['value'] = 'true';
$validation_rules['url_field']['url']['value'] = 'true';

// 4. Number Input
$validation_rules['number_field']['required']['value'] = 'true';
$validation_rules['number_field']['number']['value'] = 'true';
$validation_rules['number_field']['min']['value'] = '1';
$validation_rules['number_field']['max']['value'] = '100';

// 5. Phone Number (with custom validator)
$validation_rules['phone_field']['required']['value'] = 'true';
$validation_rules['phone_field']['phoneUS']['value'] = 'true';

// 6. Password & Confirm
$validation_rules['password']['required']['value'] = 'true';
$validation_rules['password']['minlength']['value'] = '8';
$validation_rules['password_confirm']['required']['value'] = 'true';
$validation_rules['password_confirm']['equalTo']['value'] = '#password';

// 7. Textarea
$validation_rules['comments']['required']['value'] = 'true';
$validation_rules['comments']['minlength']['value'] = '10';
$validation_rules['comments']['maxlength']['value'] = '500';

// 8. Select/Dropdown
$validation_rules['country']['required']['value'] = 'true';

// 9. Radio Buttons
$validation_rules['"interval[]"']['required']['value'] = 'true';

// 10. Checkbox (single)
$validation_rules['terms']['required']['value'] = 'true';

// 11. Checkbox List (multiple)
$validation_rules['"products_list[]"']['required']['value'] = 'true';

// 12. File Upload
$validation_rules['upload']['required']['value'] = 'true';

// 13. Date Input
$validation_rules['date_field']['required']['value'] = 'true';

// 14. Time Input
$validation_rules['time_field']['required']['value'] = 'true';

// 15. DateTime Input
$validation_rules['datetime_field']['required']['value'] = 'true';

// 16. Hidden Input (usually not validated but included for completeness)
// No validation

// 17. Color Picker
$validation_rules['color_field']['required']['value'] = 'true';

// 18. Range/Slider
$validation_rules['range_field']['required']['value'] = 'true';
$validation_rules['range_field']['min']['value'] = '0';
$validation_rules['range_field']['max']['value'] = '100';

// Output validation JavaScript
echo $formwriter->set_validate($validation_rules);

// Begin form
echo $formwriter->begin_form('form1', 'POST', '/admin/admin', true);

echo '<h3>Standard Input Types</h3>';

// 1. Text Input
echo $formwriter->textinput('Text (Required)', 'text_required', NULL, 100, 'John Doe', 'Enter your full name', 50);

// 2. Email
echo $formwriter->textinput('Email Address', 'email_field', NULL, 100, '', 'your@email.com', 255, '', TRUE, FALSE, 'email');

// 3. URL
echo $formwriter->textinput('Website URL', 'url_field', NULL, 100, '', 'https://example.com', 255, '', TRUE, FALSE, 'url');

// 4. Number
echo $formwriter->textinput('Quantity (1-100)', 'number_field', NULL, 100, '', 'Enter a number', 3, '', TRUE, FALSE, 'number');

// 5. Phone
echo $formwriter->textinput('Phone Number', 'phone_field', NULL, 100, '', '(555) 123-4567', 20, '', TRUE, FALSE, 'tel');

// 6. Password Fields
echo $formwriter->textinput('Password', 'password', NULL, 100, '', 'Min 8 characters', 255, '', TRUE, FALSE, 'password');
echo $formwriter->textinput('Confirm Password', 'password_confirm', NULL, 100, '', 'Re-enter password', 255, '', TRUE, FALSE, 'password');

echo '<hr><h3>Text Areas and Selections</h3>';

// 7. Textarea
echo $formwriter->textbox('Comments', 'comments', '', 5, 80, '', 'Enter your comments (10-500 chars)', 'no');

// 8. Dropdown/Select
$countries = array(
    "United States" => "US",
    "Canada" => "CA",
    "Mexico" => "MX",
    "United Kingdom" => "UK"
);
echo $formwriter->dropinput("Country", "country", "", $countries, NULL, 'Select your country', TRUE);

// 9. Radio Buttons
$intervals = array("Daily" => "1", "Weekly" => "7", "Monthly" => "30");
echo $formwriter->radioinput("Frequency", "interval", NULL, $intervals, '', array(), array(), 'Choose one');

// 10. Single Checkbox
echo $formwriter->checkboxinput("I agree to terms", "terms", "", "left", NULL, 1, "You must agree to continue");

// 11. Checkbox List
$products = array(
    "Product A" => "1",
    "Product B" => "2",
    "Product C" => "3",
    "Product D" => "4"
);
echo $formwriter->checkboxList("Select Products", "products_list", "", $products, array(), array(), array(), 'Select at least one');

echo '<hr><h3>File and Date Inputs</h3>';

// 12. File Upload
echo $formwriter->fileinput("Upload Document", "upload", "", 30, 'PDF, DOC, DOCX only');

// 13. Date Input
echo $formwriter->dateinput("Date", "date_field", NULL, 30, date('Y-m-d'), "", 10);

// 14. Time Input
echo $formwriter->timeinput("Time", "time_field", NULL, 30, date('H:i'), "", 8);

// 15. DateTime Combined
echo $formwriter->datetimeinput("Date & Time", "datetime", NULL, 30, date('Y-m-d'), date('H:i'), "", 10, 8);

echo '<hr><h3>Special Input Types</h3>';

// 16. Hidden Input
echo $formwriter->hiddeninput('form_token', 'abc123xyz');

// 17. Color Picker
echo $formwriter->textinput('Choose Color', 'color_field', NULL, 100, '#0000ff', 'Select a color', 7, '', TRUE, FALSE, 'color');

// 18. Range/Slider
echo $formwriter->textinput('Volume (0-100)', 'range_field', NULL, 100, '50', '', 3, '', TRUE, FALSE, 'range');

echo '<hr><h3>Advanced FormWriter Methods</h3>';

// 19. Text (read-only display)
echo $formwriter->text('Information Label', 'This is:', 'Some read-only information that is displayed but not editable', NULL);

// 20. Buttons
echo $formwriter->start_buttons();
echo $formwriter->new_form_button('Submit Form', 'primary');
echo $formwriter->button('Cancel', 'window.location="/"', 'btn btn-secondary');
echo $formwriter->end_buttons();

echo $formwriter->end_form();

echo PublicPage::EndPanel();
echo PublicPage::EndPage();

// Include joinery-validate.js instead of jQuery validation
?>
<script src="/assets/js/joinery-validate.js"></script>
<?php

$page->public_footer($foptions = array('track' => TRUE));
?>
```

## Implementation Plan

### Phase 1: Test Implementation (Development/Testing)
**Goal:** Create and thoroughly test the joinery-validate.js library with a comprehensive test form before any system-wide changes.

#### Phase 1 Steps:

1. **Create Joinery Validation File**
   - Save the JavaScript code above as `/assets/js/joinery-validate.js`

2. **Create Comprehensive Test File**
   - Create `/utils/forms_example_bootstrap_native.php` with all field types (code provided above)
   - This file will explicitly include joinery-validate.js for testing
   - Test ALL validation scenarios without affecting production forms

3. **Testing Checklist**
   - Run through all validation types listed in Testing Checklist section
   - Verify error messages display correctly
   - Confirm form submission behavior matches jQuery validation
   - Test custom validators (phoneUS, Stripe keys, etc.)
   - Verify no console errors

4. **Phase 1 Completion Criteria**
   - All validation rules work correctly in test file
   - No JavaScript errors in console
   - Visual behavior matches jQuery validation
   - Form submission prevention works correctly

### Phase 2: Pure JavaScript Implementation (ACTUAL IMPLEMENTATION)
**Goal:** Create a standalone pure JavaScript validation library without jQuery compatibility layer.

**IMPORTANT:** The actual implementation differs from the original plan. Instead of providing jQuery compatibility, we created a pure JavaScript validation system that:
- Does NOT require jQuery
- Does NOT use `set_validate()` output
- Uses clean field names without bracket notation in validation rules
- Automatically detects FormWriter's bracket notation on fields

#### Phase 2 Steps:

1. **Create Pure JavaScript Validator (`/assets/js/joinery-validate.js`)**
   - Standalone validation system with NO jQuery dependencies
   - Main class: `JoineryValidator`
   - Initialization: `JoineryValidation.init(formId, options)`
   - Built-in validators: required, email, url, number, digits, minlength, maxlength, min, max, equalTo, time, date
   - Custom validator support via `JoineryValidator.addValidator(name, method, message)`
   - Automatic field name resolution: tries exact name, then appends `[]` if not found

2. **Field Name Handling - No Brackets Required**

   **Clean validation rules (no brackets):**
   ```php
   // Use clean names - system auto-detects bracket fields
   $validation_rules['interval']['required']['value'] = 'true';  // ✓ Finds interval[]
   $validation_rules['products_list']['required']['value'] = 'true';  // ✓ Finds products_list[]
   $validation_rules['terms']['required']['value'] = 'true';  // ✓ Finds terms (no brackets)
   ```

   **The system automatically:**
   - Tries to find field with exact name first
   - If not found and no brackets in rule name, tries with `[]` appended
   - Works seamlessly with FormWriter's bracket notation for radio/checkbox groups

3. **JavaScript Initialization Pattern**

   **Do NOT use set_validate() - use manual initialization:**
   ```php
   // DO NOT output jQuery validation
   // echo $formwriter->set_validate($validation_rules);

   // Instead, manually initialize in JavaScript:
   ?>
   <script src="/assets/js/joinery-validate.js"></script>
   <script>
   document.addEventListener('DOMContentLoaded', function() {
       const validationOptions = {
           debug: true,  // Optional debugging
           rules: {
               <?php
               $first = true;
               foreach ($validation_rules as $fieldName => $rules) {
                   if (!$first) echo ",\n            ";
                   $first = false;
                   echo json_encode($fieldName) . ': {';
                   $firstRule = true;
                   foreach ($rules as $ruleName => $ruleData) {
                       if (!$firstRule) echo ', ';
                       $firstRule = false;
                       echo $ruleName . ': ';
                       if (isset($ruleData['value'])) {
                           $value = $ruleData['value'];
                           echo ($value === 'true' || $value === 'false') ? $value : '"' . addslashes($value) . '"';
                       } else {
                           echo 'true';
                       }
                   }
                   echo '}';
               }
               ?>
           }
       };

       JoineryValidation.init('form1', validationOptions);
   });
   </script>
   ```

4. **Key Features Implemented**
   - **Parameter substitution in error messages**: `{0}` replaced with actual values
   - **Multiple validation on same field**: Stops at first failure
   - **Radio/checkbox group support**: Applies error class to all fields in group
   - **Error label placement**: Smart detection of `.errorplacement` containers
   - **Duplicate error prevention**: Clears existing errors before showing new ones
   - **Time validation**: Accepts both 24-hour (14:30) and 12-hour (2:30 PM) formats
   - **Date validation**: Validates YYYY-MM-DD format

5. **System-Wide Testing**
   - Test comprehensive form: `/utils/forms_example_bootstrap_native.php`
   - Verify all field types validate correctly:
     - Text inputs (with min/max length)
     - Email, URL, number validation
     - Radio button groups
     - Single checkboxes
     - Checkbox lists
     - Date/time fields
     - Custom validators (phoneUS, etc.)

6. **Phase 2 Completion Criteria**
   - All validation rules work without jQuery
   - Clean field names (no brackets) in validation rules
   - Error messages display correctly with parameter substitution
   - Radio groups, checkbox groups, and single checkboxes all validate properly
   - No duplicate error messages
   - Time/date format validation working

## How It Works (Actual Implementation)

1. **Define validation rules in PHP** using clean field names (no brackets):
   ```php
   $validation_rules['email']['required']['value'] = 'true';
   $validation_rules['email']['email']['value'] = 'true';
   $validation_rules['interval']['required']['value'] = 'true';  // Auto-finds interval[]
   ```

2. **Skip set_validate() call** - don't output jQuery validation JavaScript:
   ```php
   // DO NOT call this:
   // echo $formwriter->set_validate($validation_rules);
   ```

3. **Output validation rules as JavaScript object** and initialize Joinery validation:
   ```javascript
   const validationOptions = {
       rules: {
           email: { required: true, email: true },
           interval: { required: true }  // Finds interval[] automatically
       }
   };
   JoineryValidation.init('form1', validationOptions);
   ```

4. **System automatically handles**:
   - Finding fields with bracket notation (interval → interval[])
   - Applying error classes to all fields in radio/checkbox groups
   - Parameter substitution in error messages
   - Bootstrap-compatible error styling
   - Form submission prevention when invalid

## Testing Checklist (Phase 2 Complete)

- [x] Text inputs with minlength/maxlength (with parameter substitution)
- [x] Email validation
- [x] URL validation
- [x] Number inputs with min/max
- [x] Phone number with custom validator (phoneUS)
- [x] Password confirmation (equalTo)
- [x] Textarea with character limits
- [x] Required dropdowns
- [x] Required radio buttons (clean names, auto-finds brackets)
- [x] Required checkboxes (single and multiple, clean names)
- [x] File upload validation
- [x] Date/time inputs (with format validation)
- [x] Time format validation (24-hour and 12-hour with AM/PM)
- [x] Date format validation (YYYY-MM-DD)
- [x] Custom validators (phoneUS via addValidator)
- [x] Error message display (Bootstrap-compatible styling)
- [x] Form submission prevention when invalid
- [x] Real-time validation on blur/change
- [x] No duplicate error messages
- [x] Error class applied to all fields in radio/checkbox groups

## Benefits (Actual Implementation)

1. **Pure JavaScript** - Zero jQuery dependency, modern ES6+ code
2. **Cleaner syntax** - No bracket notation required in validation rules
3. **Smaller footprint** - Standalone library, no external dependencies
4. **Better error handling** - Smart duplicate prevention, proper group handling
5. **Enhanced validators** - Built-in time/date format validation
6. **Parameter substitution** - Dynamic error messages with actual values
7. **Future-proof** - Modern JavaScript, easy to extend
8. **Better debugging** - Optional debug mode with detailed console logging

## What This Doesn't Include

- Complex conditional validation (if field A = X, then field B is required)
- Validation groups
- Remote validation (AJAX) - included but simplified
- Some edge cases jQuery validation handles

If these are needed, they can be added to the shim as discovered.

## Implementation Summary

### Phase 1: COMPLETE
- Created `/assets/js/joinery-validate.js` - Pure JavaScript validation library
- Created comprehensive test form: `/utils/forms_example_bootstrap_native.php`
- All core validators implemented and tested
- Custom validators working (phoneUS, time, date)

### Phase 2: COMPLETE
- Pure JavaScript implementation (NO jQuery compatibility layer)
- Clean field name syntax (no brackets required in validation rules)
- Automatic bracket detection for FormWriter fields
- Parameter substitution in error messages
- Enhanced error handling (no duplicates, proper group styling)
- Time validation: 24-hour and 12-hour formats
- Date validation: YYYY-MM-DD format
- Comprehensive testing complete

### Files Created:
1. `/assets/js/joinery-validate.js` - Main validation library
2. `/utils/forms_example_bootstrap_native.php` - Comprehensive test form

### Files Deleted:
1. `/utils/forms_example_bootstrap_experimental.php` - Old test file
2. `/utils/test_joinery_validation.html` - Old HTML test file

### Next Phase: System-Wide Migration (Phase 3)
When ready to migrate all forms:
1. Update template files to include joinery-validate.js
2. Convert forms from set_validate() to JoineryValidation.init()
3. Use clean field names in validation rules
4. Test each form individually

### Migration Pattern for Existing Forms:
```php
// OLD (jQuery validation):
echo $formwriter->set_validate($validation_rules);

// NEW (Joinery validation):
// Skip set_validate() call
?>
<script src="/assets/js/joinery-validate.js"></script>
<script>
JoineryValidation.init('formId', {
    rules: { /* validation rules */ }
});
</script>
```