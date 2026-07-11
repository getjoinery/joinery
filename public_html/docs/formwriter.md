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
10. [Architecture: Base Class vs. Renderers](#10-architecture-base-class-vs-renderers)
11. [JSON Output Mode (Server-Driven Forms)](#11-json-output-mode-server-driven-forms)

---

## 1. Overview

### What is FormWriter?

FormWriter is a PHP class system that generates HTML forms with:
- **CSRF token emission** - Every POST form gets a security token; verification is opt-in (call `validateCSRF()`), not framework-enforced
- **Consistent styling** - Semantic HTML5 markup that themes style with their own CSS
- **Validation integration** - Works seamlessly with JoineryValidator
- **Auto-detection of validation** - Automatically applies model validation rules
- **Auto-filling values** - Pass data once, all fields populate automatically
- **Field visibility logic** - Show/hide fields dynamically with smooth transitions
- **Accessibility features** - Proper labels, ARIA attributes, error messaging

**Styling through the `.jy-ui` kit.** FormWriter emits bare kit classes — `.btn` / `.btn-primary` on buttons, `.form-control` / `.form-group` / `.form-label` on fields — exactly what the platform CSS kit styles under a `.jy-ui` ancestor. So there is nothing to configure: render a form **inside a `.jy-ui` scope** and the kit styles it on every theme; outside that scope a branded theme's own form CSS applies, so nothing breaks before a page adopts the kit. See [Default Theme CSS Kit & `.jy-ui` Namespace](theme_integration_instructions.md#default-theme-css-kit--jy-ui-namespace) for the scoping rules and the no-inline-`style=` policy.

### Available Classes

- **`FormWriterV2HTML5`** - The HTML renderer. Emits semantic HTML5 markup and is used by every theme.
- **`FormWriterV2JSON`** - JSON form definitions for native-app renderers (a different output format, not a CSS concern — see [JSON Output Mode](#11-json-output-mode-server-driven-forms))
- **Base class: `FormWriterV2Base`** - Abstract base with all core functionality

All features including visibility rules, custom scripts, CSRF protection, and validation work in both the HTML and JSON renderers.

---

## 2. Getting Started

### Basic Form Creation

**In a view file with PublicPage or AdminPage:**

```php
// Get FormWriter instance (automatically selects correct theme)
$formwriter = $page->getFormWriter('contact_form');

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
require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));
$formwriter = new FormWriterV2HTML5('my_form');

$formwriter->begin_form();
// ... add fields ...
$formwriter->end_form();
```

### Form Options

```php
// Pass options to constructor
$formwriter = new FormWriterV2HTML5('my_form', [
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
$formwriter = $page->getFormWriter('form1', [
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
$formwriter = $page->getFormWriter('form1', [
    'model' => $user,
    'values' => [
        'usr_email' => 'override@example.com'  // This overrides model value
    ]
]);
```

### Edit Forms with edit_primary_key_value

When editing existing records, use `edit_primary_key_value` to pass the record's primary key:

```php
// View file - editing an existing event
$formwriter = $page->getFormWriter('form1', [
    'model' => $event,
    'edit_primary_key_value' => $event->key
]);

$formwriter->begin_form();
$formwriter->textinput('evt_name', 'Event Name');
// ... other fields ...
$formwriter->submitbutton('btn_submit', 'Save');
$formwriter->end_form();
```

**What FormWriter outputs:**

When `edit_primary_key_value` is provided, `begin_form()` automatically outputs a hidden field:

```html
<input type="hidden" name="edit_primary_key_value" value="123">
```

**CRITICAL: Logic file must check for this field**

The hidden field is named `edit_primary_key_value` (not the model's column name like `evt_event_id`). Your logic file must check for this field when loading records:

```php
// Logic file - CORRECT pattern
function admin_event_edit_logic(array $input): LogicResult {
    // CRITICAL: Check edit_primary_key_value (form submission) first, fallback to GET
    if (isset($input['edit_primary_key_value'])) {
        $event = new Event($input['edit_primary_key_value'], TRUE);
    } elseif (isset($input['evt_event_id'])) {
        $event = new Event($input['evt_event_id'], TRUE);
    } else {
        $event = new Event(NULL);
    }

    // Process form submission
    if (LibraryFunctions::isFormSubmission()) {
        $event->set('evt_name', $input['evt_name']);
        // ... set other fields ...
        $event->prepare();
        $event->save();
        return LogicResult::redirect('/plugins/event_manager/admin/admin_event?evt_event_id=' . $event->key);
    }

    return LogicResult::render(['event' => $event]);
}
```

**Why this pattern matters:**

1. Initial page load: Record ID comes from GET vars (`?evt_event_id=123`)
2. Form submission: Record ID comes from POST as `edit_primary_key_value`
3. If you only check GET vars, form submissions will create NEW records instead of updating existing ones

**Common bug - checking wrong field:**

```php
// ❌ WRONG - Will create new records on form submission!
if (isset($get_vars['evt_event_id'])) {
    $event = new Event($get_vars['evt_event_id'], TRUE);
} else {
    $event = new Event(NULL);  // Form submission hits this branch!
}

// ✅ CORRECT - Check edit_primary_key_value first
if (isset($post_vars['edit_primary_key_value'])) {
    $event = new Event($post_vars['edit_primary_key_value'], TRUE);
} elseif (isset($get_vars['evt_event_id'])) {
    $event = new Event($get_vars['evt_event_id'], TRUE);
} else {
    $event = new Event(NULL);
}
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
        'us' => 'United States',
        'ca' => 'Canada',
        'uk' => 'United Kingdom'
    ],
    'value' => 'us',  // Default selected
    'empty_option' => '-- Select Country --',
    'required' => true
]);
```

**Note:** The dropdown options format is: `'actual_value' => 'Display Text'` (value => label)

### AJAX-backed fields — the API-action contract

Three FormWriter surfaces call a server endpoint from the browser. Each speaks
the `/api/v1` action contract: the field's JS POSTs JSON, sends the
browser-session CSRF token from the `joinery_api_csrf` mirror cookie, and reads
the response envelope's `data`.

- **Remote validation** (`'validation' => ['remote' => ['url' => '/api/v1/action/{name}', ...]]`,
  and the `'custom' => ['url' => ...]` shape): POSTs `{field: value}` (`dataFieldName`
  and a `data` map override/extend the body) and reads `data.valid` (bool). The
  action returns `LogicResult` success with `['valid' => bool]`.
- **AJAX autocomplete select** (`dropinput` with `'ajaxendpoint' => '/api/v1/action/{name}'`):
  POSTs `{q, ...}` (a query string on the configured URL folds into the body)
  and reads `data.items` (`[{id, text}]`).
- **Image selector** (`imageselector`, default endpoint `/api/v1/action/image_list`):
  POSTs `{q, offset, limit}` and reads `data.{images, total, hasMore}`.

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
$formwriter = $page->getFormWriter('form1', [
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

// Get local time (preferred — caller stores local; model's save() derives UTC)
$start_time = FormWriterV2Base::process_datetimeinput($_POST, 'ccd_start_time', false);
if($start_time !== NULL){
    $model->set('ccd_start_time_local', $start_time);
}

// Or convert to UTC using the session timezone (for server-side validity windows
// where there is no separate "event timezone" — e.g. coupon codes, API keys)
$expires = FormWriterV2Base::process_datetimeinput($_POST, 'ccd_end_time', true);
```

**FormWriterV2Base::process_datetimeinput() Parameters:**
- `$post_vars` - The `$_POST` array
- `$field_name` - Base field name (e.g., `'evs_start_time'`)
- `$to_utc` - When `true`, converts to UTC using the **session timezone** (suitable only when session TZ == intended TZ, e.g. server-side validity windows). Pass `false` to get the raw local string; the model's `save()` then derives UTC using the correct field-specific timezone.

**Returns:**
- Local datetime string `'Y-m-d H:i:s'` if `$to_utc` is false (e.g., `'2024-09-09 18:02:00'`)
- ISO 8601 UTC string if `$to_utc` is true (e.g., `'2024-09-09T18:02:00+00:00'`)
- `NULL` if required fields not present in POST data

**Complete example — event with its own timezone:**

```php
// admin_event_edit_logic.php
if($_POST){
    // Store local time; Event::save() derives UTC from local + evt_timezone.
    // Do NOT use to_utc=true here — the event's timezone, not the session
    // timezone, is the correct basis for conversion.
    $start_time = FormWriterV2Base::process_datetimeinput($input, 'evt_start_time', false);
    if($start_time !== NULL){
        $event->set('evt_start_time_local', $start_time);
    }
    $event->prepare();
    $event->save();   // save() converts local+evt_timezone → UTC
}
```

### File Upload

```php
$formwriter->fileinput('document', 'Upload Document', [
    'accept' => '.pdf,.doc,.docx',
    'helptext' => 'PDF or Word documents only'
]);

// Important: Form must have enctype
$formwriter = new FormWriterV2HTML5('upload_form', [
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

**Warning — duplicate IDs when multiple forms share a field name:** FormWriter generates an `id` attribute for every field using the field name as the default. When two or more FormWriter forms are rendered on the same page and both declare a field with the same name (most commonly `hiddeninput('action', ...)`), the page ends up with duplicate `id` attributes, which is invalid HTML.

Fix: pass an explicit `'id'` option to any shared-name hidden inputs so each gets a unique ID:

```php
// ✅ CORRECT — unique IDs across forms on the same page
$form_a->hiddeninput('action', '', ['value' => 'save',   'id' => 'save_action']);
$form_b->hiddeninput('action', '', ['value' => 'delete', 'id' => 'delete_action']);

// ❌ PROBLEM — both produce id="action" in the DOM
$form_a->hiddeninput('action', '', ['value' => 'save']);
$form_b->hiddeninput('action', '', ['value' => 'delete']);
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
    'helptext' => 'Add as many features as needed'
]);
```

**Options:**
- `value` - Array of existing data rows (each row is an associative array)
- `fields` - Array of subfield definitions with `name`, `label`, and `type`
- `add_label` - Button text for adding rows (default: '+ Add Item')
- `helptext` - Help text displayed below the label (plain text; HTML is escaped — see [Labels, Help Text, and Option Values Are Always HTML-Escaped](#labels-help-text-and-option-values-are-always-html-escaped))

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
$formwriter = $page->getFormWriter('form1', [
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
$formwriter = $page->getFormWriter('form1', [
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
$formwriter = $page->getFormWriter('form1', [
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

### Descriptor-Driven Forms (`fromDescriptor()`)

`fromDescriptor()` renders an entire form body from a single field declaration — the `input` map of a logic descriptor (`*_logic_descriptor()`). One declaration drives the rendered form, its client-side validation attributes, and the REST/AI surfaces, so the field list lives in exactly one place. This is what the [scaffolding generator](scaffolding.md) emits, and any logic file can adopt it.

```php
$formwriter = $page->getFormWriter('product_edit', [
    'model' => $product,
    'edit_primary_key_value' => $product->key,
]);

$formwriter->begin_form();
$formwriter->fromDescriptor(product_edit_logic_descriptor());  // every field, from one declaration
// Hand-added fields interleave freely — call order controls field order:
$formwriter->fileinput('prd_image', 'Image');
$formwriter->submitbutton('btn_submit', 'Save');
$formwriter->end_form();
```

It lives on `FormWriterV2Base`, so the `FormWriterV2HTML5` renderer inherits it — it is pure loop-and-dispatch over field methods the base already owns, with nothing renderer-specific to override.

**Descriptor entry shape** (keyed by field name, under the descriptor's `input`):

```php
'prd_name' => ['type' => 'string', 'required' => true, 'label' => 'Name'],
'prd_status' => ['type' => 'select', 'label' => 'Status', 'options' => [0 => 'Draft', 1 => 'Published']],
'prd_body' => ['type' => 'text', 'label' => 'Body', 'help' => 'Shown on the product page.'],
```

**Type → field dispatch:**

| Descriptor `type` | FormWriter field |
|---|---|
| `string` | text input |
| `email` | text input + email validation |
| `password` | password input |
| `int` | number input |
| `bool` | checkbox |
| `select` | select (`options` from the entry) |
| `text` | textbox (plain multiline; pass `htmlmode` for rich text) |
| `date` | date input |

Per-entry extras pass straight through: `required` (toggles the required attribute), `label`, `placeholder`, and `help` (rendered as helptext). API/AI consumers ignore the FormWriter-only hints.

Two structural rules keep `fromDescriptor()` composable:

- **`edit_primary_key_value` is skipped** — `begin_form()` already emits it as a hidden field when you pass the `edit_primary_key_value` option, so it never renders as a visible input.
- **Unknown types are skipped silently** — a field with no descriptor type (file upload, rich-text widget, custom control) is simply not rendered, leaving you to hand-add it before or after the `fromDescriptor()` call.

---

## 5. Deferred Output Mode

Store form field HTML instead of echoing immediately. Essential for multiple forms in loops.

### When to Use

**Use deferred output:** Multiple forms in loops (inline action forms in listing pages)
**Use immediate output (default):** Single forms in views

### Basic Usage

```php
// Enable deferred mode
$form = $page->getFormWriter('form_' . $item->id, [
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

    $form = $page->getFormWriter('delete_' . $item->id, [
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

Works with all field types, validation, visibility rules, and custom scripts in both the HTML (`FormWriterV2HTML5`) and JSON (`FormWriterV2JSON`) renderers.

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

#### Trigger types: select, checkbox, and radio

Any of three field types can be the **trigger** that drives show/hide. The only difference is how the current rule key is read; the `show`/`hide` target lists, container detection, and on-load evaluation are identical for all three.

| Trigger | Rule keys | Reads |
|---|---|---|
| `dropinput` (select) | the option values | the selected value |
| `checkboxinput` | `checked` / `unchecked` (plus optional `default`) | whether it's ticked |
| `radioinput` (or a `checkboxList` with `type='radio'`) | the option values | the chosen option's value |

A **checkbox** keys on its state, not its value:

```php
$formwriter->checkboxinput('entry_repeats', 'Repeats', [
    'visibility_rules' => [
        'checked'   => ['show' => ['rec_frequency', 'rec_interval', 'rec_ends']],
        'unchecked' => ['hide' => ['rec_frequency', 'rec_interval', 'rec_ends']],
    ],
]);
```

A **radio group** keys on the selected option value, exactly like a select:

```php
$formwriter->radioinput('rec_ends', 'Ends', [
    'options' => ['never' => 'Never', 'date' => 'On date', 'count' => 'After N occurrences'],
    'visibility_rules' => [
        'never' => ['hide' => ['rec_end_date', 'rec_count']],
        'date'  => ['show' => ['rec_end_date'], 'hide' => ['rec_count']],
        'count' => ['show' => ['rec_count'], 'hide' => ['rec_end_date']],
    ],
]);
```

**A multi-select checkbox list (`checkboxList` with `type='checkbox'`) cannot be a trigger** — it has no single current value. Attaching `visibility_rules` to one throws at generation time. It works fine as a show/hide *target*, and a single-select `type='radio'` list is a valid trigger. Likewise, keying a checkbox on anything other than `checked`/`unchecked`/`default` is rejected immediately rather than failing silently in the browser.

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

### Multi-Action Forms (multiple submit buttons)

A form may carry more than one submit button (e.g. **Save**, **Save & Write to disk**, **Delete**). Two behaviors make these work the same whether or not the form has validation rules:

**The clicked button's name/value reaches the server.** The validator submits programmatically after validating, and the name of whichever button was clicked is preserved and posted exactly as native HTML submission would. Branch on it server-side as usual:

```php
$formwriter->submitbutton('btn_save', 'Save');
$formwriter->submitbutton('btn_save_and_write', 'Save & Write to disk');

// Server side:
if (isset($input['btn_save_and_write'])) { /* write to disk */ }
elseif (isset($input['btn_save']))       { /* plain save */ }
```

**A button can bypass client-side validation** with the `formnovalidate` option (alias `skip_validation`). Use it for Delete/Cancel actions that must fire even when required fields are empty — matching native HTML's per-button `formnovalidate`:

```php
$formwriter->submitbutton('btn_delete', 'Delete', ['formnovalidate' => true]);
```

Without this, every submit button triggers full form validation, so a Delete on a form with empty required fields would be blocked client-side. Server-side validation still applies — `formnovalidate` only skips the client check, so the server logic for that action must not assume the model is valid.

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
$formwriter = $page->getFormWriter('user_form');
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

| PHP Rule Key | JS Rule | Usage | Example |
|------|------|-------|---------|
| `required` | `required` | Field must have value | `'required' => true` |
| `email` | `email` | Valid email format | `'validation' => 'email'` |
| `url` | `url` | Valid URL format | `'validation' => 'url'` |
| `phone` | `phone` | Valid phone number | `'validation' => 'phone'` |
| `number` | `number` | Numeric value only | `'validation' => 'number'` |
| `minlength` | `minlength` | Min character length | `'minlength' => 8` |
| `maxlength` | `maxlength` | Max character length | `'maxlength' => 255` |
| `min` | `min` | Min numeric value | `'min' => 0` |
| `max` | `max` | Max numeric value | `'max' => 100` |
| `matches` | `equalTo` | Must match another field | `'matches' => 'password'` |
| `pattern` | `pattern` | Regex match | `'pattern' => '/^[A-Z0-9]+$/'` |

**Note:** The `matches` rule value is a **field name** (e.g., `'password'`), not a CSS selector. FormWriter outputs it as `equalTo` in JavaScript, where `form.elements[name]` looks up the target field.

### Custom Error Messages

Add a `messages` sub-array alongside your validation rules:

```php
$formwriter->textinput('antispam_question', 'Verification', [
    'required' => true,
    'validation' => [
        'required' => true,
        'matches' => 'antispam_question_answer',
        'messages' => [
            'required' => 'This field is required.',
            'matches' => 'You must type the correct word here',
        ],
    ],
]);
```

Message keys correspond to the PHP rule keys (e.g., `'matches'` not `'equalTo'`). FormWriter maps them to the correct JS rule names automatically.

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
    'validation' => ['matches' => 'password']
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

### Anti-Spam & Bot Protection

FormWriter provides three built-in methods for protecting public forms from bots. These are typically used together on forms accessible to non-logged-in users.

```php
if (!$is_logged_in) {
    $formwriter->antispam_question_input();  // Human verification question
    $formwriter->honeypot_hidden_input();    // Hidden field trap for bots
    $formwriter->captcha_hidden_input();     // CAPTCHA integration
}
```

**`antispam_question_input($type)`** — Renders a text field asking the user to type a specific word (configured in Settings as `anti_spam_answer`). Automatically registers `required` and `matches` validation rules so `end_form()` outputs the JS validation. Pass `'blog'` for comment forms (uses `anti_spam_answer_comments` setting).

**`honeypot_hidden_input()`** — Renders a hidden field that bots tend to fill in. Server-side logic rejects submissions where this field has a value.

**`captcha_hidden_input()`** — Renders CAPTCHA integration if configured in settings.

**Skip for logged-in users:** These protections are unnecessary for authenticated users — wrap them in a `!$is_logged_in` check.

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

    return LogicResult::render(['message' => 'User created successfully']);
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
   - CSRF token emission (opt-in verification)
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

### Labels, Help Text, and Option Values Are Always HTML-Escaped

FormWriter passes every label, every `helptext`, and every select/radio option value through `htmlspecialchars()`. HTML tags inside any of these — `<strong>`, `<em>`, `<code>`, `<span>`, `<a>`, etc. — render as literal escaped text, not as markup. This is intentional and safe-by-default: any caller may interpolate dynamic or user-supplied data into these strings, and escaping prevents that from becoming an injection vector. Use **plain text only** in labels, help text, and option arrays.

```php
// ✅ CORRECT — plain text
$formwriter->radioinput('install_mode', 'Install Type', [
    'options' => [
        'fresh'       => 'Fresh install — empty site with default schema',
        'from_backup' => 'Install from backup — clone an existing node',
    ]
]);

// ❌ WRONG — renders as "&lt;strong&gt;Fresh install&lt;/strong&gt; — ..."
$formwriter->radioinput('install_mode', 'Install Type', [
    'options' => [
        'fresh' => '<strong>Fresh install</strong> — empty site',
    ]
]);

// ❌ WRONG — helptext shows the literal "<span ...>set</span>" tags as text
$formwriter->passwordinput('client_secret', 'Client Secret', [
    'helptext' => '<span style="color:green;">set</span>',
]);

// ✅ CORRECT — plain text help
$formwriter->passwordinput('client_secret', 'Client Secret', [
    'helptext' => 'Currently set — leave blank to keep',
]);
```

There is no HTML opt-in for these fields. If a field genuinely needs rich help (a link, emphasis), render that markup yourself in the view outside the FormWriter call — do not try to smuggle it through `helptext`.

### Section Dividers Within a Form

To visually group related fields inside a long form, output a `<label>` element as a section heading. Do not use `<p>` tags (wrong spacing in Bootstrap form context) or `<h*>` tags (wrong semantic weight).

```php
echo '<label class="form-label fw-semibold d-block mt-4">Server Settings</label>';
$formwriter->textinput('mgn_hostname', 'Hostname');
$formwriter->numberinput('mgn_port', 'Port');

echo '<label class="form-label fw-semibold d-block mt-4">Credentials</label>';
$formwriter->textinput('mgn_user', 'Username');
$formwriter->passwordinput('mgn_pass', 'Password');
```

The first section heading omits `mt-4` if it appears at the very top of the form (no preceding fields to separate from).

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

### CSRF Protection (opt-in)

A CSRF token is **emitted** automatically into every POST form, but the platform does
**not** verify it for you. Verification is opt-in: a token is only checked when a handler
calls `validateCSRF()`. There is no framework-wide enforcement in the dispatch path, and CSRF
is generally **unnecessary for authenticated/admin forms** (anything behind a login) — reach
for it only on the rarer cases where you specifically want it (e.g. a sensitive unauthenticated
POST).

```php
// The token is rendered into the form automatically — nothing to enable.
$formwriter = new FormWriterV2HTML5('form', ['method' => 'POST']);

// To actually enforce it, opt in by calling validateCSRF() in your handler:
require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));

if (LibraryFunctions::isFormSubmission()) {
    $formwriter = new FormWriterV2HTML5('form');

    if (!$formwriter->validateCSRF($input)) {
        return LogicResult::error('Security token expired. Please refresh and try again.');
    }

    // Continue processing...
}
```

**Token behaviour (when you opt in):**
- Session-based storage
- Per-form ID tokens
- 2-hour default lifetime
- One-time use tokens
- Automatic cleanup of expired tokens

> CSRF is forced **off** in JSON mode regardless — API requests authenticate via key headers,
> which browsers never attach cross-origin.

### Automatic Local Time Conversion

FormWriter automatically converts UTC DateTime objects to the user's local timezone for display:

```php
// In view - DateTime objects auto-converted to user's timezone!
$formwriter = $page->getFormWriter('form1', [
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
$formwriter = $page->getFormWriter('form1', [
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
- CSRF token emission (opt-in verification)
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
- **CSRF Protection** - Token emitted automatically on POST forms; verification is opt-in via `validateCSRF()`
- **Validation** - Single source of truth in model definitions

**For more information:**
- [Model Form Helpers](#4-model-form-helpers) - Encapsulated field definitions in models
- [Validation System](validation.md) - Complete validation documentation
- [Admin Pages](admin_pages.md) - Using FormWriter in admin interfaces

---

## 10. Architecture: Base Class vs. Renderers

The FormWriter v2 system uses a **prepare/render split** to ensure behavioral consistency across all themes.

### How It Works

All behavioral logic (value resolution, state determination, option normalization) lives in `FormWriterV2Base`. Subclasses are responsible **only** for generating themed HTML.

```
FormWriterV2Base (concrete output methods)
  └── outputCheckboxInput($name, $label, $options)
        ├── prepareCheckboxData(...)  →  $data array  [ALL logic here]
        ├── renderCheckboxInput($data)  ←  abstract, subclass implements
        └── handleOutput(...)

FormWriterV2HTML5::renderCheckboxInput($data)     ── HTML only
```

### Creating a New Theme

Implement only `render*()` methods. Never implement `output*()` methods. The base class handles all data preparation.

```php
class FormWriterV2MyTheme extends FormWriterV2Base {
    protected function renderTextInput($data) {
        $class = $data['class'] ?: 'my-input-class';
        $html = '<div class="my-wrapper">';
        $html .= '<label>' . htmlspecialchars($data['label']) . '</label>';
        $html .= '<input type="' . htmlspecialchars($data['type']) . '"';
        $html .= ' name="' . htmlspecialchars($data['name']) . '"';
        $html .= ' value="' . htmlspecialchars($data['value']) . '"';
        if ($data['required']) $html .= ' required';
        if ($data['disabled']) $html .= ' disabled';
        $html .= '>';
        $html .= '</div>';
        return $html;
    }
    // ... implement all other render*() methods
}
```

### $data Array Keys per Field Type

| Method | Key fields in `$data` |
|--------|----------------------|
| `renderTextInput` | `name, label, id, value, type, placeholder, class, readonly, disabled, autofocus, required, autocomplete, onchange, pattern, min, max, step, minlength, maxlength, prepend, has_errors, errors, helptext` |
| `renderPasswordInput` | Same as textInput + `strength_meter` |
| `renderNumberInput` | Same as textInput (type='number') |
| `renderDropInput` | `name, label, id, value, options_list ([value=>label]), empty_option, class, multiple, disabled, required, onchange, ajaxendpoint, has_errors, errors, helptext, visibility_rules, custom_script` |
| `renderCheckboxInput` | `name, label, id, checked_value, is_checked, class, disabled, required, onchange, has_errors, errors, helptext, visibility_rules, custom_script` |
| `renderRadioInput` | `name, label, value, options_list, class, disabled, required, onchange, has_errors, errors, helptext` |
| `renderDateInput` | `name, label, id, value (YYYY-MM-DD), class, min, max, readonly, disabled, required, onchange, has_errors, errors, helptext` |
| `renderTimeInput` | `name, label, id, value, hour, minute, ampm, class, readonly, disabled, has_errors, errors, helptext` |
| `renderDateTimeInput` | `name, label, date_name, time_name, date_value, time_value, hour, minute, ampm, class, readonly, disabled, date_errors, time_errors, helptext` |
| `renderFileInput` | `name, label, id, class, accept, multiple, disabled, required, onchange, has_errors, errors, helptext` |
| `renderHiddenInput` | `name, id, value` |
| `renderSubmitButton` | `name, label, id, class, disabled, onclick` |
| `renderTextarea` | `name, label, id, value, placeholder, class, rows, cols, readonly, disabled, required, minlength, maxlength, onchange, has_errors, errors, helptext` |
| `renderCheckboxList` | `name, label, id, options_list, checked (array), disabled (array), readonly (array), type, has_errors, errors, helptext` |
| `renderTextbox` | `name, label, id, value, class, rows, htmlmode, readonly, disabled, has_errors, errors, helptext` |
| `renderImageInput` | `name, label, id, value, images, preview_size, class, disabled, has_errors, errors, helptext` |

### Adding a New Option

To add a new option (e.g., `'autocapitalize'`), change only one place — the `prepare*Data()` method in `FormWriterV2Base`. The renderer automatically receives it in `$data` and can use it.

```php
// In FormWriterV2Base::prepareTextData():
'autocapitalize' => $options['autocapitalize'] ?? '',

// In any renderer:
if ($data['autocapitalize']) {
    $html .= ' autocapitalize="' . htmlspecialchars($data['autocapitalize']) . '"';
}
```

---

## 11. JSON Output Mode (Server-Driven Forms)

**The rule: a form is defined in one function in its logic file; the view and the API both call it — views contribute only layout and web-only widgets.**

Everything else in this section is mechanics that follow from that sentence. The builder function can only use what's passed in, so the acting user and request input are parameters; computed option lists must be reachable from it, so they live in helpers in the logic file; things that can't serialize (JavaScript hooks, bot defences, file inputs) can't go inside it — and `FormWriterV2JSON` throws if they do, so violations fail loudly rather than quietly. New forms follow the rule from the start; a view that still declares fields inline is brought under the rule the next time it's modified. Following it is what makes serving any form to a native app a five-minute change instead of a refactor.

`FormWriterV2JSON` is the renderer that makes the rule pay: it serializes a form as a JSON-encodable **definition** — fields, labels, prefilled values, validation rules, visibility rules — instead of HTML. Native apps fetch definitions from `GET /api/v1/form/{action_name}` and render every form with one generic renderer, so a form changes once, server-side, and the web page and the apps all pick it up. (Endpoint details: [docs/api.md](api.md#form-definition-endpoint).)

Because of the prepare/render split, JSON mode inherits all behavioral logic — model autofill, validation auto-detection from `$field_specifications`, value resolution, visibility rules — for free. Each `render*($data)` appends the prepared data to the definition; `getDefinition()` returns the result.

### Form Builder Companions

The form's one function is its **builder**, named `{action}_logic_form()` in the action's logic file (mirroring the `_api()` companion convention):

```php
// In logic/account_edit_logic.php
function account_edit_logic_form($formwriter, $user = null, $input = []) {
    if ($user) {
        $formwriter->set_model($user);   // builder owns prefill
    }
    $formwriter->textinput('usr_first_name', 'First Name', ['maxlength' => 255]);
    // ... fields ...
    $formwriter->submitbutton('btn_submit', 'Submit');
}
```

- `$formwriter` — any FormWriter implementation. The web view calls the builder with its theme FormWriter (between `begin_form()`/`end_form()`); the API calls it with `FormWriterV2JSON`.
- `$user` — the acting user for prefill and conditional fields (null for sessionless forms like `register`).
- `$input` — request parameters (the web view passes `array_merge($_GET, $_POST)`, the API endpoint passes `$_GET`), for forms that carry request context such as `password_reset_2`'s `act_code`.

Keep web-only concerns **out of the builder** and in the web view: bot defences (`antispam_question_input()`, `honeypot_hidden_input()`, `captcha_hidden_input()`), layout markup, and links. `FormWriterV2JSON` throws if a builder uses them.

The exposure rule: `GET /api/v1/form/{action}` serves the form iff both a metadata companion (`{action}_logic_descriptor()` or legacy `{action}_logic_api()`) and `{action}_logic_form()` exist.

### Post-Construction Value Binding

Builders own prefill, so `FormWriterV2Base` supports binding values after construction (all renderers, web included):

```php
$formwriter->set_values(['usr_city' => 'Austin']);  // merges over existing values
$formwriter->set_model($user);                       // binds export_as_array()
```

Call before adding fields — fields capture their value when created. Both apply the same UTC→local timestamp conversion construction-time values receive.

### Definition Schema (v1)

```json
{
  "schema_version": 1,
  "form": {
    "name": "account_edit",
    "submit_to": "/api/v1/action/account_edit",
    "submit_label": "Submit"
  },
  "fields": [
    {"type": "hidden", "name": "edit_primary_key_value", "value": "123"},
    {"type": "text", "name": "usr_first_name", "label": "First Name",
     "value": "Jeremy", "required": true,
     "validation": {"required": true, "maxlength": 64}},
    {"type": "drop", "name": "usr_timezone", "label": "Your Time Zone",
     "value": "America/Chicago", "options": {"America/Chicago": "..."}}
  ]
}
```

- `schema_version` is an integer; changes within a version are additive-only. Renderers seeing a higher version than they support fall back per form ("update the app or use the website").
- Keys whose value is empty/false are omitted — absence of a flag means false.
- Common keys: `type`, `name`, `label`, `value`, `required`, `readonly`, `disabled`, `helptext`, `placeholder`, `validation` (rule-name object, same rules the web JS receives; the server-only `unique` rule is never serialized), `visibility_rules` (verbatim — see [Field Visibility](#6-field-visibility--custom-scripts)).
- Purely cosmetic HTML keys (`class`, `id`, `autofocus`) are not serialized.
- Submissions go to the normal action endpoint (`POST /api/v1/action/{action}`) as a JSON body whose keys and value shapes are identical to the web form's POST. Validation failures come back as the action API's 422 `validation_errors` map, keyed by field name.

### Field Types in JSON Mode

| FormWriter method | JSON `type` | Notes |
|---|---|---|
| `textinput` | `text` | HTML subtypes (`email`, `url`, ...) serialize as `input_type`; `prepend`, `pattern`, `min`/`max`/`step`, `minlength`/`maxlength` included |
| `passwordinput` | `password` | `strength_meter` flag; the value is **never** serialized |
| `numberinput` | `number` | `min`/`max`/`step` |
| `textarea` | `textarea` | |
| `dropinput` | `drop` | `options`, `empty_option`, `multiple`; `ajaxendpoint` serializes as `search_endpoint` |
| `checkboxinput` | `checkbox` | `checked_value`, `is_checked`, `visibility_rules` |
| `radioinput` | `radio` | `options`, `visibility_rules` |
| `checkboxList` | `checkbox_list` | `options`, `checked`, `disabled_values`, `readonly_values`, `list_type`, `visibility_rules` (`list_type='radio'` only); submits an array under the field name |
| `dateinput` | `date` | submits `name` => `YYYY-MM-DD` |
| `timeinput` | `time` | submits `name` => `HH:MM` (24-hour), same as the web widget's hidden input |
| `datetimeinput` | `datetime` | compound submit contract via `submit_parts` (below) |
| `hiddeninput` | `hidden` | round-trips values, including the automatic `edit_primary_key_value` |
| `submitbutton` | — | becomes form-level `submit_label`; one submit per form (a second throws) |

**Datetime submit contract.** A `datetime` field lists its `submit_parts` — the same multi-part POST keys the web form produces, so `FormWriterV2Base::process_datetimeinput()` and logic files work unchanged:

```json
"submit_parts": {
  "date":   "evt_start_dateinput",        // YYYY-MM-DD
  "hour":   "evt_start_timeinput_hour",   // 1-12
  "minute": "evt_start_timeinput_minute", // 0-59
  "ampm":   "evt_start_timeinput_ampm"    // AM | PM
}
```

Values are in the user's timezone, exactly as on the web.

**Visibility-trigger read semantics (native parity).** `visibility_rules` serialize verbatim on `drop`, `checkbox`, `radio`, and radio `checkbox_list` fields. The generic native renderer must read the current rule key the same way the web does, by the trigger's type: a `drop`/`radio` keys on the selected option value, and a `checkbox` keys on `checked` / `unchecked`. Targets resolve by field name, exactly as in [Field Visibility](#6-field-visibility--custom-scripts). This keeps web and native forms in lockstep with no schema change.

**Unsupported — throws at definition time.** `fileinput`, `imageinput`, `textbox` (rich text), `repeater`, `imageselector`, `colorpicker`, and anything carrying JavaScript (`custom_script`, `onchange`) throw in JSON mode, so a non-serializable builder is caught in development rather than silently degraded in production. `visibility_rules` are fine — they are declarative data and serialize verbatim.

**CSRF is forced off** in JSON mode: API requests authenticate via key headers, which browsers never attach cross-origin, and the CSRF token is bound to a web session API clients do not have.

### Forms Currently Exposed

`register`, `account_edit`, `password_edit`, `contact_preferences`, `password_reset_1`, `password_reset_2`. Login is intentionally **not** server-driven — it is the fixed two-field bootstrap contract of the auth endpoint, rendered natively.

Any other action opts in by adding its `_logic_form()` builder and updating its web view to call the same builder — no core changes needed.
