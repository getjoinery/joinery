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
9. [Advanced Features](#9-advanced-features)

---

## 1. Overview

### What is FormWriter?

FormWriter is a PHP class system that generates HTML forms with:
- **Automatic CSRF protection** - Every form gets a security token
- **Consistent styling** - Bootstrap or Tailwind themes
- **Validation integration** - Works seamlessly with JoineryValidator
- **Auto-detection of validation** - Automatically applies model validation rules
- **Auto-filling values** - Pass data once, all fields populate automatically
- **Field visibility logic** - Show/hide fields dynamically with smooth transitions
- **Accessibility features** - Proper labels, ARIA attributes, error messaging

### Available Classes

- **`FormWriterV2Bootstrap`** - Bootstrap 4/5 themed implementation
- **`FormWriterV2Tailwind`** - Tailwind CSS themed implementation
- **`FormWriterV2HTML5`** - Pure HTML5 with semantic markup (no CSS framework dependencies)
- **Base class: `FormWriterV2Base`** - Abstract base with all core functionality

All features including visibility rules, custom scripts, CSRF protection, and validation work with all theme implementations.

---

## 2. Getting Started

### Basic Form Creation

**In a view file with PublicPage or AdminPage:**

```php
// Get FormWriter instance (automatically selects correct theme)
$formwriter = $page->getFormWriter('contact_form', 'v2');

// Start the form
$formwriter->begin_form();

// Add fields with clean options array
$formwriter->textinput('name', 'Your Name', ['required' => true]);
$formwriter->textinput('email', 'Email Address', [
    'validation' => 'email',
    'required' => true,
    'placeholder' => 'user@example.com'
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
// Bootstrap theme
require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));
$formwriter = new FormWriterV2Bootstrap('my_form');

// Tailwind theme
require_once(PathHelper::getIncludePath('includes/FormWriterV2Tailwind.php'));
$formwriter = new FormWriterV2Tailwind('my_form');

// HTML5 (framework-agnostic)
require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));
$formwriter = new FormWriterV2HTML5('my_form');

$formwriter->begin_form();
// ... add fields ...
$formwriter->end_form();
```

### Form Options

```php
// Pass options to constructor
$formwriter = new FormWriterV2Bootstrap('my_form', [
    'action' => '/process',
    'method' => 'POST',
    'enctype' => 'multipart/form-data',  // For file uploads
    'class' => 'custom-form'
]);
```

### Auto-Filling Values

FormWriter supports automatic value population:

```php
// Load model data
$user = new User($user_id, TRUE);

// Pass model directly - all fields auto-fill!
$formwriter = $page->getFormWriter('form1', 'v2', [
    'model' => $user
]);

$formwriter->begin_form();

// No need to specify 'value' - auto-filled from model!
$formwriter->textinput('usr_email', 'Email');
$formwriter->textinput('usr_first_name', 'First Name');
$formwriter->textinput('usr_last_name', 'Last Name');

$formwriter->end_form();
```

**With value overrides:**

```php
// Pass both model AND specific value overrides
$formwriter = $page->getFormWriter('form1', 'v2', [
    'model' => $user,
    'values' => [
        'usr_email' => 'override@example.com'  // This overrides model value
    ]
]);
```

### Auto-Detection of Validation

FormWriter automatically detects and applies validation rules from model `field_specifications`:

```php
// In /data/user_class.php
public static $field_specifications = array(
    'usr_email' => array(
        'type' => 'varchar(255)',
        'required' => true,
        'unique' => true,
        'validation' => array('email' => true)
    )
);

// In your form - validation is automatic!
$formwriter->textinput('usr_email', 'Email');
// ↑ Automatically validates as required email from User::$field_specifications
```

**How it works:**
1. FormWriter extracts field prefix (`usr_` from `usr_email`)
2. Maps prefix to model class (`usr` → `User`)
3. Loads `User::$field_specifications`
4. Applies validation rules automatically

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

// With prepend text (Bootstrap)
$formwriter->textinput('loc_link', 'Link', [
    'prepend' => $settings->get_setting('webDir').'/location/'
]);
// Shows as: [/location/][user types here]
```

### Password Inputs

```php
// With strength meter
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
// Standard dropdown
$formwriter->dropinput('country', 'Country', [
    'options' => [
        'United States' => 'us',
        'Canada' => 'ca',
        'United Kingdom' => 'uk'
    ],
    'value' => 'us',  // Default selected
    'empty_option' => '-- Select Country --',
    'required' => true
]);
```

**Note:** The dropdown format is: `'Display Text' => 'actual_value'`

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
$formwriter->radioinput('subscription', 'Subscription Plan', [
    'options' => [
        'free' => 'Free',
        'basic' => 'Basic ($9.99/mo)',
        'premium' => 'Premium ($19.99/mo)'
    ],
    'value' => 'free'  // Default selected
]);
```

### Checkbox List

Multiple checkboxes that submit as an array:

```php
$formwriter->checkboxList('newsletter_subscriptions', 'Select Newsletters:', [
    'options' => [
        1 => 'Weekly Updates',
        2 => 'Monthly Digest',
        3 => 'Special Announcements'
    ],
    'checked' => [1, 3],  // Pre-select these options
    'disabled' => [],     // Disable specific options
    'readonly' => [2]     // Read-only (disabled visually, submitted via hidden input)
]);
```

**Option Keys:**
- `options` (required) - Associative array of value => label pairs
- `checked` - Array of values that should be checked initially
- `disabled` - Array of values to disable (user cannot interact)
- `readonly` - Array of values that are read-only (disabled visually, but submitted via hidden input)

**Form Submission:**
When the form submits, checked values are sent as an array:
```
POST data: newsletter_subscriptions[] = [1, 3]
```

In PHP, access via:
```php
$_POST['newsletter_subscriptions']  // Array of checked values
```

### Date and Time Fields

```php
// Date input
$formwriter->dateinput('start_date', 'Start Date', [
    'min' => '2025-01-01',
    'max' => '2025-12-31',
    'required' => true
]);

// Time input (uses hour/minute/AM-PM dropdowns)
$formwriter->timeinput('meeting_time', 'Meeting Time', [
    'required' => true,
    'helptext' => 'Select preferred meeting time'
]);

// DateTime input (combines date picker with time dropdowns)
$formwriter->datetimeinput('deadline', 'Deadline', [
    'required' => true
]);
```

#### DateTime Input Format

The `datetimeinput()` method accepts DateTime values in multiple formats:

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
    'model' => $coupon  // DateTime objects in export_as_array() are auto-converted
]);

$formwriter->begin_form();

// Automatically converts DateTime to user's timezone and populates fields
$formwriter->datetimeinput('ccd_start_time', 'Start time');
$formwriter->datetimeinput('ccd_end_time', 'End time');

$formwriter->end_form();
```

**How it works:**
1. Receives value from model (DateTime object or string)
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

**Important:** Always use the three-argument form with an empty string as the second parameter (label),
even though labels are ignored for hidden fields. This maintains consistency with other FormWriter methods:

```php
// CORRECT - use three arguments
$formwriter->hiddeninput('field_name', '', ['value' => $value]);

// AVOID - two arguments (works due to backwards compatibility, but not recommended)
$formwriter->hiddeninput('field_name', ['value' => $value]);
```

### Repeater Fields

Repeater fields allow users to add multiple sets of related fields dynamically. Used primarily by the Page Component System for configurable content blocks.

```php
// Basic repeater with subfields
$formwriter->repeater('features', 'Features List', [
    'value' => [
        ['title' => 'Feature 1', 'description' => 'First feature'],
        ['title' => 'Feature 2', 'description' => 'Second feature']
    ],
    'fields' => [
        ['name' => 'title', 'label' => 'Title', 'type' => 'textinput'],
        ['name' => 'description', 'label' => 'Description', 'type' => 'textarea']
    ],
    'add_label' => '+ Add Feature',
    'help' => 'Add as many features as needed'
]);
```

**Options:**
- `value` - Array of existing data rows (each row is an associative array)
- `fields` - Array of subfield definitions with `name`, `label`, and `type`
- `add_label` - Button text for adding rows (default: '+ Add Item')
- `help` - Help text displayed below the label

**Subfield Types:**
Any FormWriter field type can be used: `textinput`, `textarea`, `dropinput`, `checkboxinput`, etc.

```php
// Repeater with dropdown subfield
$formwriter->repeater('links', 'Navigation Links', [
    'fields' => [
        ['name' => 'label', 'label' => 'Link Text', 'type' => 'textinput'],
        ['name' => 'url', 'label' => 'URL', 'type' => 'textinput'],
        [
            'name' => 'target',
            'label' => 'Open In',
            'type' => 'dropinput',
            'options' => ['_self' => 'Same Window', '_blank' => 'New Window']
        ]
    ]
]);
```

**Processing Repeater Data:**

Use the static helper method to process repeater submissions:

```php
// In logic file or form processing
require_once(PathHelper::getIncludePath('includes/FormWriterV2Base.php'));

if ($_POST) {
    // Process repeater data - cleans up array structure
    $features = FormWriterV2Base::process_repeater_data($_POST['features']);

    // $features is now a clean indexed array:
    // [
    //     ['title' => 'Feature 1', 'description' => 'First feature'],
    //     ['title' => 'Feature 2', 'description' => 'Second feature']
    // ]

    $model->set('config', json_encode(['features' => $features]));
}
```

**JavaScript:**
Repeater JavaScript is automatically included when you use a repeater field. It handles:
- Adding new rows (clones template, replaces index placeholders)
- Removing rows (via delegated click handler)
- Works with dynamically added repeaters

**See Also:** [Component System Documentation](component_system.md) for using repeaters in component configuration.

---

## 4. Model Form Helpers

### Overview

Model Form Helpers are static methods in data model classes that render complete form field sets using FormWriter. They encapsulate field definitions, validation rules, and configuration within the model itself, following the DRY principle while maintaining MVC separation.

### Using Existing Model Form Helpers

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

### Available Model Form Helpers

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

### Usage Patterns

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

### Code Efficiency

Using Model Form Helpers significantly reduces code and improves maintainability:

**Manual field definitions:**
```php
// Manually defining multiple address fields requires ~33 lines
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
// ... 8 more fields ...
```

**Using Model Form Helper:**
```php
// Single method call - 6 lines total
Address::renderFormFields($formwriter, [
    'required' => true,
    'include_country' => true,
    'include_user_id' => false,
    'model' => $address
]);
```

### Architecture Principles

Model Form Helpers follow these principles:

1. **Encapsulation** - Model knows its own field structure
2. **No Direct Output** - Methods don't echo, they use FormWriter's methods
3. **Options Array** - Flexible configuration via `$options` parameter
4. **FormWriter Agnostic** - Works with any FormWriter implementation
5. **Consistent Naming** - Standard `renderFormFields()` method name

---

## 5. Deferred Output Mode

Store form field HTML instead of echoing immediately. Essential for multiple forms in loops.

### When to Use

**Use deferred output:** Multiple forms in loops (inline action forms in listing pages)
**Use immediate output (default):** Single forms in views

### Basic Usage

```php
// Enable deferred mode
$form = $page->getFormWriter('form_' . $item->id, 'v2', [
    'deferred_output' => true,
    'action' => '/admin/process?id=' . $item->id
]);

// Add fields (stored, not echoed)
$form->hiddeninput('action', '', ['value' => 'delete']);
$form->submitbutton('btn_delete', 'Delete');

// Get HTML as string
$html = $form->getFieldsHTML();
```

### Listing Page Example

```php
foreach ($items as $item) {
    $row = [];
    // ... add columns ...

    $form = $page->getFormWriter('delete_' . $item->id, 'v2', [
        'deferred_output' => true,
        'action' => '/admin/process'
    ]);

    $form->hiddeninput('item_id', '', ['value' => $item->id]);
    $form->submitbutton('btn_delete', 'Delete');

    $row['action'] = $form->getFieldsHTML();
    array_push($rowvalues, $row);
}
```

### Compatibility

Works with all field types, validation, visibility rules, custom scripts, and all theme implementations (Bootstrap, Tailwind, HTML5).

---

## 6. Field Visibility & Custom Scripts

**Feature:** FormWriter supports dynamic field visibility with smooth fade transitions and custom JavaScript logic.

### Level 1: Convenience Rules (Auto-Generated)

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

**Notes:**
- Fields and their labels fade in/out smoothly (300ms CSS transition)
- **Automatic container detection** - Just use field IDs in rules, the system automatically targets `field_id_container` if it exists
- Works on page load and when select value changes
- No additional JavaScript needed

**How Container Detection Works:**
The visibility system automatically checks for `field_id_container` elements first. This is the standard FormWriter pattern where fields are wrapped in container divs.

### Level 2: Field-Level Custom Scripts

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

**Notes:**
- `this` refers to the select element
- Wrapped in `DOMContentLoaded` automatically
- `change` event attached automatically
- Full JavaScript access for complex logic

### Level 3: Form-Level Scripts

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

**Notes:**
- Multiple scripts can be added (they all run in order)
- Wrapped in `DOMContentLoaded` automatically
- Full control - no framework limitations
- **Container auto-detection** - When hiding/showing fields, target the `field_id_container` divs
- Runs just before form closing tag

**Pro Tip:** When hiding fields in form-level scripts, target `field_id_container` elements rather than field IDs directly. This hides the entire field wrapper (label + input) instead of just the input.

### Fade Effects

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

### Automatic Validation

FormWriter automatically generates validation rules from model `field_specifications`:

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
            'maxlength' => 255
        )
    )
);

// In your form - NO validation setup needed!
$formwriter = $page->getFormWriter('user_form', 'v2');
$formwriter->begin_form();

// Validation is AUTOMATIC from model specs!
$formwriter->textinput('usr_email', 'Email');
// ↑ Automatically validates as required, unique, email

$formwriter->end_form();
```

### Manual Validation Rules

For fields without model specs, add validation manually:

```php
$formwriter->textinput('custom_field', 'Custom Field', [
    'validation' => [
        'required' => true,
        'minlength' => 5,
        'maxlength' => 100
    ]
]);

// Or use shorthand for common types
$formwriter->textinput('email', 'Email', [
    'validation' => 'email',  // Shorthand
    'required' => true
]);
```

### Available Validation Rules

| Rule | Usage | Example |
|------|-------|---------|
| `required` | Field must have value | `'required' => true` |
| `email` | Valid email format | `'validation' => 'email'` |
| `url` | Valid URL format | `'validation' => 'url'` |
| `phone` | Valid phone number | `'validation' => 'phone'` |
| `number` | Numeric value only | `'validation' => 'number'` |
| `minlength` | Min character length | `'minlength' => 8` |
| `maxlength` | Max character length | `'maxlength' => 255` |
| `min` | Min numeric value | `'min' => 0` |
| `max` | Max numeric value | `'max' => 100` |
| `equalTo` | Must match field | `'equalTo' => 'password'` |
| `pattern` | Regex match | `'pattern' => '/^[A-Z0-9]+$/'` |

### Common Validation Patterns

**Email Signup Form:**

```php
$formwriter->textinput('email', 'Email', [
    'validation' => 'email',
    'required' => true
]);
$formwriter->passwordinput('password', 'Password', [
    'required' => true,
    'validation' => ['minlength' => 8]
]);
$formwriter->passwordinput('password_confirm', 'Confirm Password', [
    'required' => true,
    'validation' => ['equalTo' => 'password']
]);
```

**Product Form with Price:**

```php
$formwriter->textinput('product_name', 'Product Name', [
    'required' => true,
    'validation' => ['minlength' => 3]
]);
$formwriter->textinput('price', 'Price', [
    'required' => true,
    'validation' => [
        'number' => true,
        'min' => 0.01
    ]
]);
$formwriter->textinput('sku', 'SKU', [
    'required' => true,
    'validation' => ['pattern' => '/^[A-Z0-9\-]+$/']
]);
```

### Server-Side Validation

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

**For complete validation system documentation**, see **[validation.md](validation.md)**

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

## 9. Advanced Features

### CSRF Protection

CSRF (Cross-Site Request Forgery) protection is automatic for all POST forms:

```php
// CSRF automatically enabled for POST forms
$formwriter = new FormWriterV2Bootstrap('form', [
    'method' => 'POST'  // CSRF token auto-generated!
]);

// Server-side validation in logic file
require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formwriter = new FormWriterV2Bootstrap('form');

    if (!$formwriter->validateCSRF($_POST)) {
        return LogicResult::error('Security token expired. Please refresh and try again.');
    }

    // Continue processing...
}
```

**Features:**
- Session-based storage
- Per-form ID tokens
- 2-hour default lifetime
- One-time use tokens
- Automatic cleanup of expired tokens

### Automatic Local Time Conversion

FormWriter automatically converts UTC DateTime objects to the user's local timezone for display:

```php
// In view - DateTime objects auto-converted to user's timezone!
$formwriter = $page->getFormWriter('form1', 'v2', [
    'model' => $event  // DateTime fields in model are auto-converted
]);

$formwriter->begin_form();
$formwriter->datetimeinput('evt_start_time', 'Event Start Time');
$formwriter->end_form();
```

**How it works:**
1. `export_as_array()` creates DateTime objects with UTC timezone
2. FormWriter detects DateTime objects in values
3. Converts from UTC to user's timezone automatically
4. Formats as `Y-m-d H:i:s` for display

### Input Group Prepend Text (Bootstrap)

Bootstrap theme supports prepending text to input fields:

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
```

### Debug Mode

Enable console logging during development:

```php
$formwriter = $page->getFormWriter('form1', 'v2', [
    'debug' => true  // Logs validation detection to console
]);
```

**Console output:**
```javascript
=== FormWriterV2 DEBUG ===
Form ID: form1
🔍 Automatic Model Validation Detected:
  ✓ usr_email → Model: User {required: true, email: true}
  ✓ usr_username → Model: User {required: true, minlength: 3}
✓ Validation rules applied
```

### Error Handling

FormWriter stores validation errors internally:

```php
// In logic file
if (!$formwriter->validate($_POST)) {
    $errors = $formwriter->getErrors();
    // Returns:
    // [
    //     'field_name' => ['Error message 1', 'Error message 2']
    // ]

    return LogicResult::error('Validation failed', ['errors' => $errors]);
}
```

**Methods available:**
- `hasErrors()` - Check if any errors exist
- `getErrors()` - Get all errors
- `getFieldErrors($field)` - Get errors for specific field
- `setErrors($errors)` - Set errors manually
- `addError($field, $message)` - Add single error
- `clearErrors()` - Clear all errors

---

## Summary

FormWriter provides:
- Consistent, secure form generation
- Automatic CSRF protection
- Automatic validation from models
- Automatic value filling
- Automatic timezone conversion
- Dynamic field visibility with smooth transitions
- Custom JavaScript support at three levels
- Theme-aware styling
- Accessibility features
- Model Form Helpers - Reusable form field sets from data models

**Key Features:**
- **Clean API** - Options arrays for readable, maintainable code
- **Auto-detection** - Minimal boilerplate code required
- **Model Integration** - Works directly with model field specifications
- **CSRF Protection** - Automatic for all POST forms
- **Validation** - Single source of truth in model definitions

**For more information:**
- [Model Form Helpers](#4-model-form-helpers) - Encapsulated field definitions in models
- [Validation System](validation.md) - Complete validation documentation
- [Admin Pages](admin_pages.md) - Using FormWriter in admin interfaces
- Example forms: `/utils/forms_example_bootstrapv2.php`
