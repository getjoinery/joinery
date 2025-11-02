# FormWriter Documentation

The FormWriter system provides a structured, consistent way to build forms in the Joinery platform. It handles HTML generation, validation integration, CSRF protection, and field visibility logic.

---

## Table of Contents

1. [Overview](#1-overview)
2. [Getting Started](#2-getting-started)
3. [Field Types](#3-field-types)
4. [Model Form Helpers](#4-model-form-helpers)
5. [Deferred Output Mode](#5-deferred-output-mode)
6. [Field Visibility & Custom Scripts](#6-field-visibility--custom-scripts)
7. [Validation Integration](#7-validation-integration)
8. [Best Practices](#8-best-practices)
9. [V1 vs V2](#9-v1-vs-v2)

---

## 1. Overview

### What is FormWriter?

FormWriter is a PHP class system that generates HTML forms with:
- **Automatic CSRF protection** - Every form gets a security token
- **Consistent styling** - Bootstrap, Tailwind, HTML5, or UIKit themes
- **Validation integration** - Works seamlessly with JoineryValidator
- **Field visibility logic** - Show/hide fields dynamically with smooth transitions
- **Accessibility features** - Proper labels, ARIA attributes, error messaging

### Two Versions

**FormWriter V1** (Original)
- Classes: `FormWriterBootstrap`, `FormWriterHTML5`, `FormWriterUIKit`, `FormWriterTailwind`
- Base class: `FormWriterBase`
- Field method: `dropinput()`, `textinput()`, `textarea()`, etc.
- Used in: Most existing code

**FormWriter V2** (Current)
- Classes: `FormWriterV2Bootstrap`, `FormWriterV2Tailwind`
- Base class: `FormWriterV2Base`
- Field method: All fields use standardized `options` array
- Used in: New development

Both versions support all features including visibility rules and custom scripts.

---

## 2. Getting Started

### Basic Form Creation

**In a view file with PublicPage or AdminPage:**

```php
// Get FormWriter instance (automatically selects correct theme)
$formwriter = $page->getFormWriter('contact_form');

// Start the form
$formwriter->begin_form();

// Add fields
$formwriter->textinput('name', 'Your Name', ['required' => true]);
$formwriter->textinput('email', 'Email Address', [
    'validation' => 'email',
    'required' => true
]);
$formwriter->textarea('message', 'Message', [
    'rows' => 5,
    'required' => true
]);

// Submit button
$formwriter->submitbutton('submit', 'Send Message');

// End the form
$formwriter->end_form();
```

**In logic files or other contexts:**

```php
require_once(PathHelper::getIncludePath('includes/FormWriterBootstrap.php'));

$formwriter = new FormWriterBootstrap('my_form');
$formwriter->begin_form();
// ... add fields ...
$formwriter->end_form();
```

### Form Options

```php
// V1: Pass options to constructor or begin_form()
$formwriter = new FormWriterBootstrap('my_form', [
    'action' => '/process',
    'method' => 'POST',
    'class' => 'custom-form'
]);

// V2: Pass options to constructor
$formwriter = new FormWriterV2Bootstrap('my_form', [
    'action' => '/process',
    'method' => 'POST',
    'enctype' => 'multipart/form-data'  // For file uploads
]);
```

---

## 3. Field Types

### Text Inputs

```php
// Basic text input
$formwriter->textinput('username', 'Username');

// With validation and placeholder
$formwriter->textinput('email', 'Email', [
    'validation' => 'email',
    'required' => true,
    'placeholder' => 'user@example.com',
    'helptext' => 'We will never share your email'
]);

// Read-only or disabled
$formwriter->textinput('user_id', 'User ID', [
    'value' => '12345',
    'readonly' => true
]);
```

### Password Inputs

```php
// With strength meter (V2 only)
$formwriter->passwordinput('password', 'Password', [
    'show_strength' => true,
    'required' => true,
    'validation' => ['minlength' => 8]
]);

// Confirm password
$formwriter->passwordinput('password_confirm', 'Confirm Password', [
    'validation' => ['equalTo' => 'password']
]);
```

### Dropdown/Select

```php
// V1 Style
$formwriter->dropinput('country', 'Country', [
    'United States' => 'us',
    'Canada' => 'ca',
    'United Kingdom' => 'uk'
], [
    'value' => 'us',  // Default selected
    'required' => true
]);

// V2 Style - Same array format as V1!
$formwriter->dropinput('country', 'Country', [
    'options' => [
        'United States' => 'us',
        'Canada' => 'ca',
        'United Kingdom' => 'uk'
    ],
    'value' => 'us',
    'empty_option' => '-- Select Country --'
]);
```

**Note:** Both V1 and V2 use the same dropdown array format: `'Display Text' => 'actual_value'`

### Textarea

```php
$formwriter->textarea('description', 'Description', [
    'rows' => 5,
    'cols' => 80,
    'placeholder' => 'Enter detailed description',
    'validation' => ['minlength' => 10, 'maxlength' => 500]
]);
```

### Checkbox

```php
$formwriter->checkboxinput('accept_terms', 'I accept the terms and conditions', [
    'required' => true,
    'helptext' => 'You must accept to continue'
]);
```

### Radio Buttons

```php
// V1
$formwriter->radioinput('subscription', 'Subscription Plan', [
    'free' => 'Free',
    'basic' => 'Basic ($9.99/mo)',
    'premium' => 'Premium ($19.99/mo)'
]);

// V2
$formwriter->radioinput('subscription', 'Subscription Plan', [
    'options' => [
        'free' => 'Free',
        'basic' => 'Basic ($9.99/mo)',
        'premium' => 'Premium ($19.99/mo)'
    ],
    'value' => 'free'  // Default selected
]);
```

### Date and Time Fields

```php
// Date input
$formwriter->dateinput('start_date', 'Start Date', [
    'min' => '2025-01-01',
    'max' => '2025-12-31',
    'required' => true
]);

// Time input (V2 - uses hour/minute/AM-PM dropdowns)
$formwriter->timeinput('meeting_time', 'Meeting Time', [
    'required' => true,
    'helptext' => 'Select preferred meeting time'
]);

// DateTime input (V2 - combines date picker with time dropdowns)
$formwriter->datetimeinput('deadline', 'Deadline', [
    'required' => true
]);
```

#### DateTime Input Format

The `datetimeinput()` method accepts DateTime values in multiple formats for maximum compatibility:

**Accepted input formats:**
- **DateTime object** - Direct from database (preferred)
- **String** - Any format parseable by PHP's DateTime constructor
  - `'2024-09-09 18:02:00'` - MySQL DATETIME
  - `'2024-09-09T18:02:00+00:00'` - ISO 8601
  - `'September 9, 2024 6:02pm'` - Human readable

**Example with automatic form filling:**

```php
// Load model with datetime fields
$coupon = new CouponCode($coupon_id, TRUE);

// Pass to FormWriter - handles DateTime objects automatically
$formwriter = $page->getFormWriter('form1', 'v2', [
    'values' => $coupon->export_as_array()  // Returns DateTime objects for timestamp fields
]);

$formwriter->begin_form();

// Automatically converts DateTime to user's timezone and populates fields
$formwriter->datetimeinput('ccd_start_time', 'Start time');
$formwriter->datetimeinput('ccd_end_time', 'End time');

$formwriter->end_form();
```

**How it works:**
1. Receives value from `values` array (DateTime object or string)
2. Uses PHP's DateTime class to parse the value
3. Formats date as `Y-m-d` for the date picker
4. Formats time as `H:i` (24-hour) for conversion to 12-hour dropdowns
5. User sees properly formatted date and time in their timezone

**Processing submitted datetime values:**

Use the static helper method to process datetime submissions:

```php
// In logic file
require_once(PathHelper::getIncludePath('includes/FormWriterV2Base.php'));

// Process datetime - automatically converts from user's timezone to UTC
$start_time = FormWriterV2Base::process_datetimeinput($_POST, 'ccd_start_time', true);
if($start_time !== NULL){
    $model->set('ccd_start_time', $start_time);
}

// Or get local time without UTC conversion
$local_time = FormWriterV2Base::process_datetimeinput($_POST, 'meeting_time', false);
```

**FormWriterV2Base::process_datetimeinput() Parameters:**
- `$post_vars` - The `$_POST` array
- `$field_name` - Base field name (e.g., `'ccd_start_time'`)
- `$to_utc` - Convert to UTC timezone (default: `true`)

**Returns:**
- ISO 8601 datetime string if `$to_utc` is true (e.g., `'2024-09-09T18:02:00+00:00'`)
- Local datetime string if `$to_utc` is false (e.g., `'2024-09-09 18:02:00'`)
- `NULL` if required fields not present in POST data

**Complete example:**

```php
// admin_event_edit.php (view)
$event = new Event($event_id, TRUE);
$form_values = $event->export_as_array();

// Convert UTC times to user's local timezone for display
if($event->key){
    if($form_values['evt_start_time']){
        $form_values['evt_start_time'] = LibraryFunctions::convert_time(
            $form_values['evt_start_time'],
            'UTC',
            $session->get_timezone(),
            'Y-m-d H:i:s'
        );
    }
}

$formwriter = $page->getFormWriter('form1', 'v2', ['values' => $form_values]);
$formwriter->begin_form();
$formwriter->datetimeinput('evt_start_time', 'Event Start Time');
$formwriter->end_form();

// admin_event_edit_logic.php (processing)
if($_POST){
    // Process datetime from user's timezone to UTC for storage
    $start_time = FormWriterV2Base::process_datetimeinput($_POST, 'evt_start_time', true);
    if($start_time !== NULL){
        $event->set('evt_start_time', $start_time);
    }

    $event->save();
}
```

### File Upload

```php
$formwriter->fileinput('document', 'Upload Document', [
    'accept' => '.pdf,.doc,.docx',
    'helptext' => 'PDF or Word documents only'
]);

// Important: Form must have enctype
$formwriter = new FormWriterV2Bootstrap('upload_form', [
    'enctype' => 'multipart/form-data'
]);
```

### Hidden Fields

```php
$formwriter->hiddeninput('user_id', '', ['value' => $user_id]);
```

---

## 4. Model Form Helpers

### Overview

Model Form Helpers are static methods in data model classes that render complete form field sets using FormWriter. They encapsulate field definitions, validation rules, and configuration within the model itself, following the DRY principle while maintaining MVC separation.

### Why Use Model Form Helpers?

**Benefits:**
- ✅ **Single method call** replaces dozens of lines of field definitions
- ✅ **Centralized field logic** - all form definitions in one place (the model)
- ✅ **Reusable across pages** - admin, profile, public forms all use same method
- ✅ **Easy to maintain** - update field definitions in one location
- ✅ **No direct HTML output** - models don't echo, they provide methods
- ✅ **MVC compliant** - models know their own structure

### 4.1 Using Existing Model Form Helpers

Models with form helpers provide static methods like `renderFormFields()`:

**Address Form Example:**

```php
// In admin page, profile page, or any form
$formwriter = $page->getFormWriter('form1', 'v2', [
    'model' => $address,
    'edit_primary_key_value' => $address->key
]);

$formwriter->begin_form();

// Single method call renders: country, address1, address2, city, state, zip
Address::renderFormFields($formwriter, [
    'required' => true,
    'include_country' => true,
    'include_user_id' => false,
    'model' => $address
]);

$formwriter->submitbutton('btn_submit', 'Submit');
$formwriter->end_form();
```

**PhoneNumber Form Example:**

```php
$formwriter = $page->getFormWriter('form1', 'v2', [
    'model' => $phone_number,
    'edit_primary_key_value' => $phone_number->key
]);

$formwriter->begin_form();

// Single method call renders: country code, phone number
PhoneNumber::renderFormFields($formwriter, [
    'required' => true,
    'include_user_id' => false,
    'model' => $phone_number
]);

$formwriter->submitbutton('btn_submit', 'Submit');
$formwriter->end_form();
```

### 4.2 Available Model Form Helpers

**Address::renderFormFields()**

```php
Address::renderFormFields($formwriter, [
    'required' => true,          // Make all fields required (default: true)
    'include_country' => true,   // Show country dropdown (default: true)
    'include_user_id' => false,  // Add hidden user_id field (default: false)
    'user_id' => $user->key,     // User ID value if include_user_id is true
    'model' => $address          // Address object for prepopulation (default: null)
]);
```

**Renders fields:**
- Country code dropdown
- Street address (required)
- Apt/Suite (optional)
- City (required)
- State/Province (required)
- Zip/Postcode (required)

**PhoneNumber::renderFormFields()**

```php
PhoneNumber::renderFormFields($formwriter, [
    'required' => true,          // Make all fields required (default: true)
    'include_user_id' => false,  // Add hidden user_id field (default: false)
    'user_id' => $user->key,     // User ID value if include_user_id is true
    'model' => $phone_number     // PhoneNumber object for prepopulation (default: null)
]);
```

**Renders fields:**
- Country code dropdown
- Phone number (required)

### 4.3 Usage Patterns

**Admin Page (Edit Mode):**
```php
$address = new Address($address_id, TRUE);
$formwriter = $page->getFormWriter('form1', 'v2', [
    'model' => $address,
    'edit_primary_key_value' => $address->key
]);

$formwriter->begin_form();
Address::renderFormFields($formwriter, [
    'required' => true,
    'include_country' => true,
    'include_user_id' => true,
    'user_id' => $user_id,
    'model' => $address
]);
$formwriter->submitbutton('btn_submit', 'Submit');
$formwriter->end_form();
```

**Profile Page (Optional Fields):**
```php
if(!Address::GetDefaultAddressForUser($user_id)) {
    $user_address = $user->address();
    Address::renderFormFields($formwriter, [
        'required' => true,
        'include_country' => true,
        'include_user_id' => false,
        'model' => $user_address
    ]);
}
```

**Product Registration (Create New):**
```php
PhoneNumber::renderFormFields($formwriter, [
    'required' => true,
    'include_user_id' => false,
    'model' => NULL  // No prepopulation for new records
]);
```

### 4.4 Comparison: Before vs After

**Before (Manual Field Definitions):**
```php
// 33 lines of field definitions
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
// ... more fields ...
```

**After (Model Form Helper):**
```php
// 6 lines - single method call
Address::renderFormFields($formwriter, [
    'required' => true,
    'include_country' => true,
    'include_user_id' => false,
    'model' => $address
]);
```

**Benefits:**
- 80% less code
- Consistent across all pages
- Easy to update field definitions
- No duplication between pages

### 4.5 Architecture Principles

Model Form Helpers follow these principles:

1. **Encapsulation** - Model knows its own field structure
2. **No Direct Output** - Methods don't echo, they use FormWriter's methods
3. **Options Array** - Flexible configuration via `$options` parameter
4. **FormWriter Agnostic** - Works with any FormWriter implementation
5. **Consistent Naming** - Standard `renderFormFields()` method name

---

## 5. Deferred Output Mode

**V2 Feature:** Store form field HTML instead of echoing immediately. Essential for multiple forms in loops.

### 5.1 When to Use

**Use deferred output:** Multiple forms in loops (inline action forms in listing pages)
**Use immediate output (default):** Single forms in views

### 5.2 Basic Usage

```php
// Enable deferred mode
$form = $page->getFormWriter('form_' . $item->id, 'v2', [
    'deferred_output' => true,
    'action' => '/admin/process?id=' . $item->id
]);

// Add fields (stored, not echoed)
$form->hiddeninput('action', ['value' => 'delete']);
$form->submitbutton('btn_delete', 'Delete');

// Get HTML as string
$html = $form->getFieldsHTML();
```

### 5.3 Listing Page Example

```php
foreach ($items as $item) {
    $row = [];
    // ... add columns ...

    $form = $page->getFormWriter('delete_' . $item->id, 'v2', [
        'deferred_output' => true,
        'action' => '/admin/process'
    ]);

    $form->hiddeninput('item_id', ['value' => $item->id]);
    $form->submitbutton('btn_delete', 'Delete');

    $row['action'] = $form->getFieldsHTML();
    array_push($rowvalues, $row);
}
```

### 5.4 Compatibility

Works with all field types, validation, visibility rules, custom scripts, and both themes.

---

## 6. Field Visibility & Custom Scripts

**New Feature (Added 2025-10-25):** FormWriter now supports dynamic field visibility with smooth fade transitions and custom JavaScript logic.

### 6.1 Level 1: Convenience Rules (Auto-Generated)

**For simple show/hide based on select field values**, define rules and FormWriter generates JavaScript automatically:

```php
// Example: Show different fields based on question type
$formwriter->dropinput('question_type', 'Question Type', [
    'options' => [
        'text' => 'Text Answer',
        'multiple_choice' => 'Multiple Choice',
        'rating' => 'Rating Scale'
    ],
    'visibility_rules' => [
        'text' => [
            'show' => ['text_options', 'char_limit'],
            'hide' => ['choices_list', 'rating_scale']
        ],
        'multiple_choice' => [
            'show' => ['choices_list'],
            'hide' => ['text_options', 'char_limit', 'rating_scale']
        ],
        'rating' => [
            'show' => ['rating_scale'],
            'hide' => ['text_options', 'char_limit', 'choices_list']
        ]
    ]
]);

// Create the target fields (using their field IDs only)
$formwriter->textinput('text_options', 'Text Options');
$formwriter->textinput('char_limit', 'Character Limit');
$formwriter->textarea('choices_list', 'Multiple Choice Options');
$formwriter->dropinput('rating_scale', 'Rating Scale', [
    'options' => ['1-5' => '1-5 Stars', '1-10' => '1-10 Scale']
]);
```

**Features:**
- Fields and their labels fade in/out smoothly (300ms CSS transition)
- **Automatic container detection** - Just use field IDs in rules, the system automatically targets `field_id_container` if it exists, otherwise falls back to the field ID
- Works on page load and when select value changes
- No additional JavaScript needed
- No need to specify `_container` suffix - it's automatic!

**How Container Detection Works:**
The visibility system automatically checks for `field_id_container` elements first. This is the standard FormWriter pattern where fields are wrapped in container divs. If a container exists, it's targeted (hiding both label and field). If not, the field itself is targeted. You just pass the field ID and let the system handle it:

```javascript
// Automatic logic (you don't write this - it happens behind the scenes)
const el = document.getElementById(id + "_container") || document.getElementById(id);
```

### 6.2 Level 2: Field-Level Custom Scripts

**For custom logic on a specific field**, provide the event handler body - FormWriter wraps it with `addEventListener`:

```php
// Example: Update price based on size selection
$formwriter->dropinput('product_size', 'Size', [
    'options' => ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'],
    'custom_script' => '
        const size = this.value;
        const priceField = document.getElementById("price");
        const bulkWarning = document.getElementById("bulk_warning");

        if (size === "small") {
            priceField.value = "9.99";
            if (bulkWarning) bulkWarning.style.display = "none";
        } else if (size === "medium") {
            priceField.value = "19.99";
            if (bulkWarning) bulkWarning.style.display = "none";
        } else if (size === "large") {
            priceField.value = "29.99";
            if (bulkWarning) bulkWarning.style.display = "";
        }
    '
]);

$formwriter->textinput('price', 'Price', ['readonly' => true]);
$formwriter->textinput('bulk_warning', 'Bulk orders require manager approval', [
    'readonly' => true
]);
```

**Features:**
- `this` refers to the select element
- Wrapped in `DOMContentLoaded` automatically
- `change` event attached automatically
- Full JavaScript access for complex logic

### 6.3 Level 3: Form-Level Scripts

**For cross-field logic**, add raw JavaScript to run when the form loads:

```php
// Example: Country selection changes field labels and visibility
$formwriter->addReadyScript('
    const countryField = document.getElementById("country");
    if (countryField) {
        countryField.addEventListener("change", function() {
            const country = this.value;
            // Use field IDs only - container detection is automatic!
            const stateContainer = document.getElementById("state_container");
            const zipContainer = document.getElementById("zip_container");
            const customContainer = document.getElementById("custom_location_container");

            // Get input elements for setting placeholders
            const stateField = document.getElementById("state");
            const zipField = document.getElementById("zip");

            if (country === "us") {
                stateContainer.style.display = "";
                zipContainer.style.display = "";
                customContainer.style.display = "none";
                if (stateField) stateField.placeholder = "State";
                if (zipField) zipField.placeholder = "ZIP Code (5 digits)";
            } else if (country === "ca") {
                stateContainer.style.display = "";
                zipContainer.style.display = "";
                customContainer.style.display = "none";
                if (stateField) stateField.placeholder = "Province";
                if (zipField) zipField.placeholder = "Postal Code";
            } else {
                stateContainer.style.display = "none";
                zipContainer.style.display = "none";
                customContainer.style.display = "";
            }
        });

        // Trigger on load
        countryField.dispatchEvent(new Event("change"));
    }
');
```

**Features:**
- Multiple scripts can be added (they all run in order)
- Wrapped in `DOMContentLoaded` automatically
- Full control - no framework limitations
- **Container auto-detection** - When hiding/showing fields, target the `field_id_container` divs (FormWriter's standard pattern) which hide both label and field together
- Runs just before form closing tag

**Pro Tip:** When hiding fields in form-level scripts, target `field_id_container` elements rather than field IDs directly. This hides the entire field wrapper (label + input) instead of just the input. FormWriter automatically wraps form fields in these containers, so they're always available to target.

### 6.4 Fade Effects

All visibility changes include smooth fade transitions:

**CSS Classes (automatically injected):**
```css
.fw-field-hidden {
  opacity: 0 !important;
  transition: opacity 0.3s ease-out;
  pointer-events: none;
}

.fw-field-visible {
  opacity: 1;
  transition: opacity 0.3s ease-in;
}
```

**Benefits:**
- Professional, smooth UX
- No JavaScript animation loops
- Works for all visibility rules automatically
- Prevents interaction during fade with `pointer-events: none`

---

## 7. Validation Integration

FormWriter integrates with the **JoineryValidator** system for client-side validation and works seamlessly with model-based server-side validation.

### Validation Flow

```
User Input → JavaScript Validation → Form Submission
                 (client-side)         (errors blocked)
                      ↓
           Server Receives Data
                      ↓
          FormWriter Processes
                      ↓
     Model->prepare() → Server Validation
                      ↓
         Model->save() → Database
```

### 7.1 Basic Validation with set_validate()

**Using V1 FormWriter:**

```php
$formwriter = new FormWriterBootstrap('contact_form');

// Define validation rules
$validation_rules = array();
$validation_rules['name']['required']['value'] = 'true';
$validation_rules['email']['required']['value'] = 'true';
$validation_rules['email']['email']['value'] = 'true';
$validation_rules['message']['required']['value'] = 'true';
$validation_rules['message']['minlength']['value'] = '10';
$validation_rules['message']['minlength']['message'] = '"Message must be at least 10 characters"';

// Output validation script (generates JavaScript)
echo $formwriter->set_validate($validation_rules);

// Build the form
$formwriter->begin_form();
$formwriter->textinput('name', 'Name', ['required' => true]);
$formwriter->textinput('email', 'Email', ['required' => true, 'validation' => 'email']);
$formwriter->textarea('message', 'Message', ['required' => true, 'validation' => ['minlength' => 10]]);
$formwriter->submitbutton('submit', 'Send');
$formwriter->end_form();
```

**Using V2 FormWriter:**

```php
$formwriter = $page->getFormWriter('user_form');

// Define validation rules
$validation_rules = array();
$validation_rules['usr_email']['required']['value'] = 'true';
$validation_rules['usr_email']['email']['value'] = 'true';
$validation_rules['usr_password']['required']['value'] = 'true';
$validation_rules['usr_password']['minlength']['value'] = '8';

// Output validation script
echo $formwriter->set_validate($validation_rules);

// Build form (V2 uses consistent options array)
$formwriter->begin_form();
$formwriter->textinput('usr_email', 'Email', [
    'required' => true,
    'validation' => 'email'
]);
$formwriter->passwordinput('usr_password', 'Password', [
    'required' => true,
    'validation' => ['minlength' => 8],
    'show_strength' => true
]);
$formwriter->end_form();
```

### 7.2 Model-Aware Validation (V2 Feature)

FormWriter V2 can automatically generate validation rules from model `field_specifications`:

```php
require_once(PathHelper::getIncludePath('data/user_class.php'));

$user = new User($user_id ?? NULL, !empty($user_id));
$formwriter = $page->getFormWriter('user_form');

// Generate validation from model field_specifications
$validation_rules = array();
foreach (User::$field_specifications as $field_name => $spec) {
    if (isset($spec['required']) || isset($spec['validation'])) {
        $validation_rules[$field_name] = array();

        // Add required rule
        if (isset($spec['required']) && $spec['required']) {
            $validation_rules[$field_name]['required']['value'] = 'true';
        }

        // Add other validation rules
        if (isset($spec['validation'])) {
            foreach ($spec['validation'] as $rule => $value) {
                if ($rule !== 'messages') {
                    if (is_bool($value)) {
                        $validation_rules[$field_name][$rule]['value'] = $value ? 'true' : 'false';
                    } else {
                        $validation_rules[$field_name][$rule]['value'] = (string)$value;
                    }
                }
            }
        }
    }
}

echo $formwriter->set_validate($validation_rules);
```

**Model field_specifications example:**

```php
// In /data/user_class.php
public static $field_specifications = array(
    'usr_email' => array(
        'type' => 'varchar(255)',
        'required' => true,
        'unique' => true,
        'validation' => array(
            'email' => true,
            'minlength' => 5,
            'maxlength' => 255,
            'messages' => array(
                'email' => 'Email must be a valid email address',
                'minlength' => 'Email must be at least 5 characters'
            )
        )
    ),
    'usr_username' => array(
        'type' => 'varchar(64)',
        'required' => true,
        'unique' => true,
        'validation' => array(
            'minlength' => 3,
            'maxlength' => 64,
            'pattern' => '/^[a-zA-Z0-9_\.]+$/',
        )
    ),
);
```

### 7.3 Available Validation Rules

FormWriter supports all JoineryValidator rules:

| Rule | Usage | Example |
|------|-------|---------|
| `required` | Field must have value | `'required']['value'] = 'true'` |
| `email` | Valid email format | `'email']['value'] = 'true'` |
| `url` | Valid URL format | `'url']['value'] = 'true'` |
| `number` | Numeric value only | `'number']['value'] = 'true'` |
| `minlength` | Min character length | `'minlength']['value'] = '8'` |
| `maxlength` | Max character length | `'maxlength']['value'] = '255'` |
| `min` | Min numeric value | `'min']['value'] = '0'` |
| `max` | Max numeric value | `'max']['value'] = '100'` |
| `equalTo` | Must match field | `'equalTo']['value'] = '"#password"'` |
| `pattern` | Regex match | `'pattern']['value'] = '"/^[A-Z0-9]+$/"'` |
| `remote` | AJAX validation | `'remote']['value'] = '"/ajax/check_username"'` |

### 7.4 Common Validation Patterns

**Email Signup Form:**

```php
$rules['email']['required']['value'] = 'true';
$rules['email']['email']['value'] = 'true';
$rules['password']['required']['value'] = 'true';
$rules['password']['minlength']['value'] = '8';
$rules['password_confirm']['required']['value'] = 'true';
$rules['password_confirm']['equalTo']['value'] = '"#password"';  // Must match

echo $formwriter->set_validate($rules);
```

**Product Form with Price:**

```php
$rules['product_name']['required']['value'] = 'true';
$rules['product_name']['minlength']['value'] = '3';

$rules['price']['required']['value'] = 'true';
$rules['price']['number']['value'] = 'true';
$rules['price']['min']['value'] = '0.01';

$rules['sku']['required']['value'] = 'true';
$rules['sku']['pattern']['value'] = '"/^[A-Z0-9\-]+$/"';

echo $formwriter->set_validate($rules);
```

**Contact Form with Optional Phone:**

```php
$rules['name']['required']['value'] = 'true';
$rules['email']['required']['value'] = 'true';
$rules['email']['email']['value'] = 'true';
$rules['message']['required']['value'] = 'true';
$rules['message']['minlength']['value'] = '10';

// Phone is optional but must be valid if provided
$rules['phone']['pattern']['value'] = '"/^[\\d\-\(\)\s]+$/"';

echo $formwriter->set_validate($rules);
```

### 7.5 Custom Error Messages

```php
$validation_rules['email']['required']['value'] = 'true';
$validation_rules['email']['required']['message'] = '"Please enter your email address"';
$validation_rules['email']['email']['value'] = 'true';
$validation_rules['email']['email']['message'] = '"Please enter a valid email address"';

$validation_rules['password']['minlength']['value'] = '8';
$validation_rules['password']['minlength']['message'] = '"Password must be at least 8 characters"';
```

### 7.6 Debug Mode

Enable console logging during development:

```php
echo $formwriter->set_validate($validation_rules, NULL, true);  // true = debug mode
```

This logs validation initialization, rules, field validation attempts, and results.

### 7.7 Server-Side Validation

**Always validate on the server - never trust client-side validation alone!**

```php
// In logic file
require_once(PathHelper::getIncludePath('data/user_class.php'));

$user = new User(NULL);
$user->set('usr_email', $_POST['email']);
$user->set('usr_username', $_POST['username']);
$user->set('usr_password', $_POST['password']);

try {
    // Server-side validation from field_specifications
    $user->prepare();

    // Save to database
    $user->save();

    return LogicResult::success(['message' => 'User created successfully']);
} catch (DisplayableUserException $e) {
    // User-friendly error message
    return LogicResult::error($e->getMessage());
} catch (SystemBaseException $e) {
    // System error - log it
    error_log($e->getMessage());
    return LogicResult::error('An error occurred while processing your request');
}
```

**For complete validation system documentation**, including:
- JoineryValidator JavaScript library details
- Server-side model validation
- Validation rule reference
- Troubleshooting and performance tips

See **[validation.md](validation.md)**

---

## 8. Best Practices

### Security

1. **Always use FormWriter** - Never build forms manually
   - Automatic CSRF protection
   - Proper input sanitization
   - XSS prevention with `htmlspecialchars()`

2. **Always validate server-side** - Never trust client validation alone
   ```php
   // In logic file
   $result = profile_logic($_GET, $_POST);
   // Logic handles validation via model->prepare()
   ```

### Performance

1. **Use visibility_rules over custom_script** when possible
   - Less code to maintain
   - Automatic validation of rules
   - Consistent behavior

2. **Avoid complex logic in custom_script**
   - Keep event handlers simple
   - Use form-level scripts for complex interactions

### Maintainability

1. **Document complex visibility rules**
   ```php
   // Show shipping fields for physical products only
   'visibility_rules' => [
       'physical' => ['show' => ['weight', 'dimensions']],
       'digital' => ['hide' => ['weight', 'dimensions']]
   ]
   ```

2. **Test with hidden fields**
   - Ensure form submission works with hidden fields
   - Validate that required fields aren't hidden by default

3. **Use consistent field naming**
   - Prefix with model: `usr_email`, `pro_name`
   - Use underscores not hyphens: `first_name` not `first-name`

4. **Container handling**
   - **In visibility_rules:** Just use field IDs (e.g., `'user_email'`) - container detection is automatic
   - **In form-level scripts:** Target `field_id_container` elements to hide both label and field together
   - FormWriter automatically wraps fields in containers, so `_container` elements always exist
   - Example: `document.getElementById("user_email_container")` hides the field + label

---

## 9. V1 vs V2

### When to Use V1

- Maintaining existing code
- Quick forms without complex validation
- All themes (Bootstrap, HTML5, UIKit, Tailwind)

### When to Use V2

- New development
- **Automatic model validation** from field specifications
- **Automatic form filling** from model data
- Modern Bootstrap or Tailwind styling
- Time picker widgets
- Password strength meters
- Input group prepend/append text

### 9.1 V2 Automatic Features

FormWriter V2 includes powerful automatic features that reduce boilerplate code:

#### 9.1.1 Automatic Model Validation

V2 automatically detects validation rules from model `field_specifications` based on field naming conventions:

```php
// In /data/locations_class.php
public static $field_specifications = array(
    'loc_name' => array('type'=>'varchar(255)', 'required'=>true),
    'loc_link' => array('type'=>'varchar(255)', 'required'=>true),
    'loc_address' => array('type'=>'varchar(255)'),
);

// In admin form - NO manual validation setup needed!
$formwriter = $page->getFormWriter('form1', 'v2', ['debug' => true]);
$formwriter->begin_form();

// V2 auto-detects Location model from 'loc_' prefix and applies validation
$formwriter->textinput('loc_name', 'Location name');  // ← Automatically required!
$formwriter->textinput('loc_link', 'Link');           // ← Automatically required!
$formwriter->textinput('loc_address', 'Address');     // ← Not required (no rule in model)

$formwriter->end_form();
```

**How it works:**
1. V2 extracts field prefix (`loc_` from `loc_name`)
2. Maps prefix to model class (`loc` → `Location`)
3. Loads `Location::$field_specifications`
4. Applies validation rules automatically
5. Outputs console debug info (when `debug => true`)

**Console output:**
```javascript
=== FormWriterV2 DEBUG ===
Form ID: form1
🔍 Automatic Model Validation Detected:
  ✓ loc_name → Model: Location {required: true}
  ✓ loc_link → Model: Location {required: true}
✓ Validation rules: {loc_name: {required: true}, loc_link: {required: true}}
```

**IMPORTANT:** Don't override model validation unless necessary:

```php
// ❌ BAD - Disables ALL validation including model-based
$formwriter->textinput('loc_link', 'Link', [
    'validation' => false  // Overrides model validation!
]);

// ✅ GOOD - Uses automatic model validation
$formwriter->textinput('loc_link', 'Link');

// ✅ GOOD - Adds to model validation (doesn't replace it)
$formwriter->textinput('loc_link', 'Link', [
    'validation' => ['maxlength' => 100]  // Adds maxlength, keeps required from model
]);
```

#### 9.1.2 Automatic Form Filling

V2 can auto-populate all fields from model data using the `model` option:

```php
// Load location model
$location = new Location($location_id, TRUE);

// Pass model directly to FormWriter - auto-fills all fields!
$formwriter = $page->getFormWriter('form1', 'v2', [
    'model' => $location
]);

$formwriter->begin_form();

// No need to specify 'value' for each field - auto-filled from model!
$formwriter->textinput('loc_name', 'Location name');
$formwriter->textinput('loc_address', 'Address');
$formwriter->textinput('loc_website', 'Website');
$formwriter->textinput('loc_link', 'Link');

$formwriter->end_form();
```

**With field overrides:**
```php
// Pass model AND additional values that override specific fields
$override_values = [];
if($coupon->key){
    // Transform timezone-sensitive fields (overrides model value)
    $override_values['ccd_start_time'] = LibraryFunctions::convert_time(
        $coupon->get('ccd_start_time'), 'UTC', $session->get_timezone(), 'Y-m-d H:i:s'
    );
} else {
    // Set defaults for new records
    $override_values['ccd_is_active'] = 1;
}

$formwriter = $page->getFormWriter('form1', 'v2', [
    'model' => $coupon,              // Auto-fills all model fields
    'values' => $override_values     // Overrides specific fields (no conflicts!)
]);
```

**Override behavior:**
Values in the `values` array take precedence over model fields - no conflict detection needed:

```php
$formwriter = $page->getFormWriter('form1', 'v2', [
    'model' => $location,              // Has loc_name = "Old Name"
    'values' => ['loc_name' => 'New Name']  // Overrides model value
]);
// Result: loc_name will be "New Name" (values override model)
```

**Benefits:**
- ✅ Pass model directly - no need to call `export_as_array()`
- ✅ Automatic value population from model
- ✅ Simple override pattern - values take precedence
- ✅ Perfect for timezone conversions and defaults
- ✅ Works with all field types

**Old way (manual):**
```php
$formwriter = $page->getFormWriter('form1', 'v2');
$formwriter->textinput('loc_name', 'Location name', [
    'value' => $location->get('loc_name')  // Repeat for every field!
]);
$formwriter->textinput('loc_address', 'Address', [
    'value' => $location->get('loc_address')
]);
// ... etc
```

**New way (automatic):**
```php
$formwriter = $page->getFormWriter('form1', 'v2', [
    'model' => $location  // One option!
]);
$formwriter->textinput('loc_name', 'Location name');
$formwriter->textinput('loc_address', 'Address');
// ... etc - all auto-filled!
```

#### 9.1.3 Automatic Local Time Conversion

V2 automatically converts UTC DateTime objects to the user's local timezone for display. This eliminates manual timezone conversion code in views.

**How it works:**
- Automatically converts any DateTime objects found in form values with UTC timezone
- DateTime objects are created by `export_as_array()` with UTC timezone already set
- Skips conversion if DateTime already has a non-UTC timezone
- Converts from UTC to user's timezone to `Y-m-d H:i:s` format for display
- Handles errors gracefully (leaves value unchanged if conversion fails)

**Before (V1 - manual conversion):**
```php
// Must manually convert timezone in view
$form_values = $location->export_as_array();
if($location->get('loc_created_time')){
    $form_values['loc_created_time'] = LibraryFunctions::convert_time(
        $location->get('loc_created_time'),
        'UTC',
        $session->get_timezone(),
        'Y-m-d H:i:s'
    );
}
$formwriter = $page->getFormWriter('form1', 'v1');
// Then pass $form_values manually to each field
```

**After (V2 - automatic conversion):**
```php
// Just pass model - any DateTime objects automatically converted!
$formwriter = $page->getFormWriter('form1', 'v2', [
    'model' => $location  // DateTime objects from export_as_array() auto-converted
]);
```

**How it works technically:**

1. `export_as_array()` creates DateTime objects with UTC timezone:
```php
// In SystemBase::export_as_array():
$out_array[$field_name] = new DateTime($this->get($field_name), new DateTimeZone('UTC'));
```

2. FormWriter V2 converts UTC DateTime objects to user's timezone:
```php
// In FormWriterV2Base::convertDateTimeFieldsToLocalTime():
foreach ($this->values as $key => &$value) {
    if ($value instanceof DateTime) {
        // Only convert if timezone is UTC (skip if already in another timezone)
        if ($value->getTimezone()->getName() === 'UTC') {
            $value = LibraryFunctions::convert_time($value, 'UTC', $user_timezone, 'Y-m-d H:i:s');
        }
    }
}
```

**Overriding automatic conversion:**
If you need to use a non-converted value, simply pass it in the `values` array (which overrides model values):

```php
$formwriter = $page->getFormWriter('form1', 'v2', [
    'model' => $location,
    'values' => ['loc_created_time' => '2024-01-01 00:00:00']  // This won't be converted
]);
```

**Benefits:**
- ✅ Eliminates repetitive timezone conversion code
- ✅ Works automatically - no configuration needed
- ✅ Simple: converts any DateTime object found in values
- ✅ Easy to override when needed (pass `values` array)
- ✅ Handles errors gracefully
- ✅ Clean design: timezone is part of the DateTime object itself

#### 9.1.4 Input Group Prepend Text (Bootstrap)

V2 Bootstrap supports prepending text to input fields using Bootstrap's input-group:

```php
// Show URL prefix before the input field
$formwriter->textinput('loc_link', 'Link', [
    'prepend' => $settings->get_setting('webDir').'/location/'
]);

// Shows as: [/location/][user types here]

// Currency prefix
$formwriter->textinput('price', 'Price', [
    'prepend' => '$'
]);

// Shows as: [$][user types here]

// Protocol prefix
$formwriter->textinput('website', 'Website', [
    'prepend' => 'https://'
]);

// Shows as: [https://][user types here]
```

**Benefits:**
- ✅ Cleaner than putting prefix in label
- ✅ Visual indication of final format
- ✅ User only types the variable part
- ✅ Uses Bootstrap's native input-group styling

**Key Differences:**

| Feature | V1 | V2 |
|---------|----|----|
| Field options | Mixed parameters | Standardized `options` array |
| Validation | Manual `set_validate()` | **Auto from model specs** |
| Form filling | Manual per-field | **Auto from `values` option** |
| Time picker | Basic input | Hour/minute dropdowns |
| Password | Simple input | With strength meter |
| Input groups | Manual HTML | **`prepend` option** |
| Debug mode | Limited | **Console model detection** |
| Themes | 4 (Bootstrap, HTML5, UIKit, Tailwind) | 2 (Bootstrap, Tailwind) |
| Visibility rules | ✅ Supported | ✅ Supported |
| Custom scripts | ✅ Supported | ✅ Supported |

**For migration guidance**, see **[/specs/migrate_admin_forms_to_formwriter_v2.md](/specs/migrate_admin_forms_to_formwriter_v2.md)**

---

## Summary

FormWriter provides:
- ✅ Consistent, secure form generation
- ✅ Automatic CSRF protection
- ✅ Validation integration
- ✅ Dynamic field visibility with smooth transitions
- ✅ Custom JavaScript support at three levels
- ✅ Theme-aware styling
- ✅ Accessibility features
- ✅ **Model Form Helpers** - Reusable form field sets from data models

**Key Features:**
- **Model Form Helpers** (NEW) - Static methods in models render complete form field sets (Address, PhoneNumber, etc.)
- **V2 Automatic Features** - Model validation detection, form filling, timezone conversion
- **Two Versions** - V1 for compatibility, V2 for modern development
- **Complete Flexibility** - Visibility rules, custom scripts, validation patterns

**For more information:**
- [Model Form Helpers](#4-model-form-helpers) - Encapsulated field definitions in models
- [Validation System](validation.md) - Complete validation documentation
- [Admin Pages](admin_pages.md) - Using FormWriter in admin interfaces
- [Specification: Model Form Helpers](/specs/implemented/model_form_helpers.md) - Architecture and implementation details
- Example forms: `/utils/forms_example_bootstrap.php`, `/utils/forms_example_bootstrapv2.php`
