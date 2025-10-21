# FormWriter v2 Specification

## Overview
FormWriter v2 is a complete reimplementation that modernizes the API, adds built-in CSRF protection, and unifies validation across models, forms, frontend, and backend using the existing joinery validation infrastructure.

## Phase 1 vs Phase 2

### Phase 1: Testing & Iteration (NO BREAKING CHANGES)
- **New classes only**: FormWriterV2Base, FormWriterV2Bootstrap, FormWriterV2Tailwind
- **Direct usage**: Use `new FormWriterV2Bootstrap()` explicitly
- **Existing code unchanged**: All v1 FormWriter code continues to work
- **Non-breaking additions allowed**: Can add methods to Validator.php
- **Testing focus**: Create forms_example_bootstrapv2.php for testing
- **No theme changes**: Theme FormWriter.php files remain untouched

### Phase 2: Migration (FUTURE - After Testing)
- **Theme integration**: Themes can extend FormWriterV2 classes
- **Migration options**: Update FormWriter or add FormWriterV2
- **Breaking changes allowed**: After thorough testing in Phase 1

## Key Changes from v1

### What's the Same
- **Same method names**: `textinput()`, `dropinput()`, `textarea()`, `passwordinput()`, etc.
- **Field creation pattern**: Methods still create form fields
- **Theme inheritance pattern**: Each theme provides its own FormWriter class
- **No deprecation**: v1 continues to work alongside v2

### What's Different
- **Options arrays instead of 20+ parameters**: Clean, readable API
- **Values array for auto-filling**: Pass all field values at once via `'values' => $array`
- **Auto-detection of validation**: Fields with model prefixes (usr_, pro_, etc.) automatically get validation
- **CSRF protection built-in**: Enabled by default
- **Unified validation**: One system for frontend, backend, and models

## Key Features

### Unified Validation System
- **Single Source of Truth**: Define validation once in model `field_specifications`, use everywhere
- **Auto-Detection**: Forms automatically inherit validation from models
- **Override Capability**: Add or modify validation rules at the form level when needed
- **Existing Infrastructure**: Leverages existing `Validator`, `JoineryValidator`, and `FieldConstraints`
- **Consistent Experience**: Same validation rules for frontend JavaScript, backend PHP, and model save operations

### Clean Modern API
- Options array pattern instead of 20+ parameters
- Values array for bulk field population
- Model-aware field creation with auto-detection

### Built-in Security
- CSRF protection enabled by default
- Automatic token management
- Secure validation pipeline

## Goals
1. Replace long argument lists with a cleaner API using required parameters and options array
2. Implement automatic CSRF token generation and validation
3. Unify validation across models, forms, frontend and backend using existing validators
4. Enable auto-detection of validation rules from model field_specifications
5. Improve developer experience and eliminate duplicate validation code
6. Provide a modern alternative to v1 while maintaining v1 for existing code

## Current Problems
- Methods like `addFormElement()` have 20+ parameters, making them difficult to use and maintain
- No built-in CSRF protection - developers must implement manually
- Validation is duplicated across models, forms, and logic files
- Frontend and backend validation rules often diverge over time
- Model validation (`field_specifications`) not used by forms
- Three separate validation systems (models, forms, JavaScript) to maintain
- Complex parameter ordering leads to errors and confusion

## Proposed API Changes

### Current API Example
```php
$formwriter->addFormElement(
    'text',           // $type
    'usr_email',      // $name
    'Email',          // $label
    $user->get('usr_email'), // $value
    '',               // $id
    '',               // $class
    true,             // $required
    false,            // $readonly
    false,            // $disabled
    '',               // $placeholder
    '',               // $helptext
    100,              // $maxlength
    '',               // $pattern
    '',               // $min
    '',               // $max
    '',               // $step
    '',               // $accept
    false,            // $multiple
    '',               // $autocomplete
    '',               // $onchange
    ''                // $onclick
);
```

### New v2 API (Phase 1 - Direct Usage)
```php
// Phase 1: Use FormWriterV2Bootstrap directly
require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));
$formwriter = new FormWriterV2Bootstrap('user_form', [
    'action' => '/users/save',
    'method' => 'POST',
    'values' => $user->export_as_array()  // Or any custom array
]);

// Simple text field - manual validation
$formwriter->textinput('search', 'Search', [
    'placeholder' => 'Enter search terms...',
    'validation' => ['minlength' => 3]
]);

// Model field - value AUTO-FILLED from values array, validation AUTO-DETECTED from field name!
$formwriter->textinput('usr_email', 'Email');
// No 'value' needed - auto-filled from values['usr_email']!
// No 'validation' needed - detected from 'usr_' prefix!

// Advanced usage - override auto-filled value and add validation
$formwriter->textinput('usr_email', 'Email Address', [
    'value' => strtolower($user->get('usr_email')),  // Override with modified value
    'validation' => [
        'required' => true,
        'email' => true,
        'maxlength' => 100,
        'pattern' => '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$',
        'messages' => [
            'required' => 'Email is required',
            'email' => 'Please enter a valid email address',
            'pattern' => 'Email format is invalid'
        ]
    ],
    'placeholder' => 'user@example.com',
    'helptext' => 'We\'ll never share your email',
    'autocomplete' => 'email'
]);

// Password field with custom validation
$formwriter->passwordinput('usr_password', 'Password', [
    'validation' => [
        'required' => true,
        'minlength' => 8,
        'maxlength' => 72,
        'custom' => [
            'uppercase' => '/[A-Z]/',
            'lowercase' => '/[a-z]/',
            'number' => '/[0-9]/',
            'special' => '/[^A-Za-z0-9]/'
        ],
        'messages' => [
            'minlength' => 'Password must be at least 8 characters',
            'uppercase' => 'Password must contain at least one uppercase letter',
            'lowercase' => 'Password must contain at least one lowercase letter',
            'number' => 'Password must contain at least one number',
            'special' => 'Password must contain at least one special character'
        ]
    ],
    'strength_meter' => true
]);
```

## Value Auto-Filling

FormWriter v2 can auto-fill all field values from a single array, eliminating repetitive `'value' => $model->get('field')` calls:

### Basic Usage
```php
// Pass values array to the form constructor (Phase 1: Direct usage)
require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));
$formwriter = new FormWriterV2Bootstrap('user_form', [
    'action' => '/users/save',
    'method' => 'POST',
    'values' => $user->export_as_array()  // Uses model's export method
]);

// Fields automatically get their values from the array
$formwriter->textinput('usr_email', 'Email');        // Value from values['usr_email']
$formwriter->textinput('usr_first_name', 'First');   // Value from values['usr_first_name']
$formwriter->textinput('usr_last_name', 'Last');     // Value from values['usr_last_name']
```

### Custom Values Array
```php
// Prepare custom values when you need manipulation
$values = $user->export_as_array();
$values['usr_email'] = strtolower($values['usr_email']);  // Normalize
$values['full_name'] = $user->display_name();             // Computed field
unset($values['usr_password']);                           // Don't populate passwords

$formwriter = new FormWriterV2Bootstrap('user_form', [
    'action' => '/users/save',
    'method' => 'POST',
    'values' => $values
]);
```

### Override Individual Values
```php
// Even with auto-filling, you can override specific fields
$formwriter->textinput('usr_email', 'Email', [
    'value' => 'override@example.com'  // Overrides the auto-filled value
]);
```

## CSRF Protection

### Recommended Implementation

CSRF protection should be **automatic and transparent** with sensible defaults:

```php
// CSRF is ON by default for POST forms
$formwriter = new FormWriterV2Bootstrap('user_form', [
    'action' => '/users/save',
    'method' => 'POST'  // CSRF automatically enabled
]);

// Form-wide CSRF disable option
$formwriter = new FormWriterV2Bootstrap('api_form', [
    'action' => '/api/endpoint',
    'method' => 'POST',
    'csrf' => false  // Disable CSRF for entire form (e.g., for API endpoints)
]);

// CSRF is automatically OFF for GET forms
$formwriter = new FormWriterV2Bootstrap('search_form', [
    'action' => '/search',
    'method' => 'GET'  // CSRF automatically disabled for GET
]);
```

### How It Works

1. **Token Generation** (in constructor):
   ```php
   // Default CSRF to true for POST, false for GET
   if (!isset($this->options['csrf'])) {
       $this->options['csrf'] = ($this->options['method'] === 'POST');
   }

   // Generate token only if CSRF is enabled
   if ($this->options['csrf'] === true) {
       $this->csrf_token = bin2hex(random_bytes(32));
       $_SESSION['csrf_tokens'][$this->form_id] = [
           'token' => $this->csrf_token,
           'expires' => time() + ($this->options['csrf_lifetime'] ?? 7200)
       ];
   }
   ```

2. **Automatic Token Injection** (in begin_form()):
   ```php
   public function begin_form() {
       echo '<form id="' . $this->form_id . '" method="' . $this->options['method'] . '">';

       // Auto-inject CSRF token as hidden field
       if ($this->csrf_token) {
           $this->hiddeninput('_csrf_token', '', [
               'value' => $this->csrf_token
           ]);
       }
   }
   ```

3. **Backend Validation**:
   ```php
   // In logic file
   $formwriter = new FormWriterV2Bootstrap('user_form', $_POST);

   // Validate CSRF first (automatic for POST)
   if (!$formwriter->validateCSRF($_POST)) {
       return LogicResult::Error('Invalid security token. Please refresh and try again.');
   }

   // Then validate form data (stores errors internally)
   if (!$formwriter->validate($_POST)) {
       // Errors are available via $formwriter->getErrors()
       return LogicResult::Error('Validation failed', $formwriter->getErrors());
   }
   ```

4. **Token Cleanup** (automatic):
   ```php
   // Clean expired tokens on each request
   foreach ($_SESSION['csrf_tokens'] as $form_id => $data) {
       if ($data['expires'] < time()) {
           unset($_SESSION['csrf_tokens'][$form_id]);
       }
   }
   ```

### Configuration Options
```php
// In form options
[
    'csrf' => true,           // Enable/disable (default: true for POST)
    'csrf_lifetime' => 7200,  // Token lifetime in seconds (default: 2 hours)
    'csrf_field' => '_csrf_token',  // Field name (default: _csrf_token)
    'csrf_error' => 'Security validation failed. Please refresh and try again.'
]
```

### When to Disable CSRF

Set `'csrf' => false` in form options for:
- API endpoints that use other authentication
- Webhook receivers (Stripe, PayPal, etc.)
- Forms submitted by external services
- AJAX endpoints with token-based auth
- Development/testing scenarios

### Key Decisions Made:
1. **Automatic by default** for POST forms (principle of secure by default)
2. **Form-wide disable option** via `'csrf' => false`
3. **Token per form ID** (not per-request) to support multiple forms
4. **Hidden field injection** in begin_form() - no manual step needed
5. **Clear error messages** for users when tokens expire
6. **2-hour lifetime** balances security with usability
7. **Session storage** (simplest, no database needed)

## Unified Validation System

### Integration with Model field_specifications

FormWriter v2 integrates seamlessly with the existing model validation system by reading from `field_specifications`:

```php
// In model class (e.g., User)
public static $field_specifications = array(
    'usr_email' => array(
        'type' => 'varchar(64)',
        'required' => true,
        'unique' => true,
        'validation' => array(
            'type' => 'email',
            'maxlength' => 64,
            'messages' => array(
                'required' => 'Email is required',
                'type' => 'Please enter a valid email address',
                'unique' => 'This email is already registered'
            )
        )
    ),
    'usr_age' => array(
        'type' => 'int4',
        'validation' => array(
            'required' => true,
            'type' => 'integer',
            'min' => 18,
            'max' => 120,
            'messages' => array(
                'min' => 'You must be at least 18 years old',
                'max' => 'Please enter a valid age'
            )
        )
    )
);
```

### Intelligent Auto-Detection by Field Name

FormWriter v2 automatically detects the model from field naming conventions (usr_*, pro_*, evt_*, etc.):

#### The Smart Detection Rules:
1. **Field name matches model prefix** → Auto-loads validation from model
2. **Field name doesn't match any model** → Uses manual validation
3. **Want to disable auto-detection** → Set `'model' => false`
4. **Want NO validation at all** → Set `'validation' => false`

```php
// AUTOMATIC - Model detected from 'usr_' prefix
$formwriter->textinput('usr_email', 'Email Address', [
    'placeholder' => 'user@example.com'
    // Automatically uses User::$field_specifications['usr_email']['validation']
]);

// AUTOMATIC - Model detected from 'pro_' prefix
$formwriter->textinput('pro_name', 'Product Name', [
    'placeholder' => 'Enter product name'
    // Automatically uses Product::$field_specifications['pro_name']['validation']
]);

// MANUAL - No model prefix, no auto-detection
$formwriter->textinput('search_term', 'Search', [
    'validation' => ['minlength' => 3]
]);

// DISABLE AUTO-DETECTION - Prevent model detection but keep manual validation
$formwriter->textinput('usr_temp_field', 'Temporary Field', [
    'model' => false,  // Don't auto-detect even though it has usr_ prefix
    'validation' => ['maxlength' => 50]
]);

// NO VALIDATION AT ALL - Skip all validation for this field
$formwriter->textarea('usr_notes', 'Admin Notes', [
    'validation' => false  // No validation whatsoever - frontend or backend
    // Useful for: drafts, admin overrides, optional fields
]);

// OVERRIDE - Auto-detect but add extra rules
$formwriter->passwordinput('usr_password', 'Password', [
    // Model validation auto-detected from usr_ prefix
    'validation' => [  // These are ADDED to model rules
        'minlength' => 12,  // Override model's minlength
        'pattern' => '/(?=.*[!@#$%])/'  // Add extra requirement
    ]
]);

// MANUAL - Field without model prefix uses manual validation
$formwriter->textinput('billing_email', 'Billing Email', [
    'validation' => [
        'required' => true,
        'email' => true
    ],
    'placeholder' => 'billing@company.com'
]);
```


### Manual Fields (No Model Equivalent)

Many form fields don't have model equivalents - these use manual validation:

```php
// Fields without model equivalents - define validation manually
$formwriter->passwordinput('usr_password_confirm', 'Confirm Password', [
    'validation' => [
        'required' => true,
        'matches' => 'usr_password',
        'messages' => [
            'matches' => 'Passwords do not match'
        ]
    ]
]);

// CAPTCHA field - no model equivalent
$formwriter->textinput('captcha', 'Security Code', [
    'validation' => [
        'required' => true,
        'remote' => [
            'url' => '/api/verify-captcha',
            'message' => 'Invalid security code'
        ]
    ]
]);

// Search/filter fields - no model equivalent
$formwriter->textinput('search_term', 'Search', [
    'placeholder' => 'Enter search terms...',
    'validation' => [
        'minlength' => 3,
        'messages' => [
            'minlength' => 'Search term must be at least 3 characters'
        ]
    ]
]);

// Terms acceptance - no model equivalent
$formwriter->checkboxinput('accept_terms', 'I accept the terms and conditions', [
    'validation' => [
        'required' => true,
        'messages' => [
            'required' => 'You must accept the terms to continue'
        ]
    ]
]);
```

### Override Model Validation

When you specify validation explicitly, it **completely replaces** model validation:

```php
// Model says: ['required' => true, 'maxlength' => 100, 'type' => 'email']
$formwriter->textinput('usr_email', 'Email Address', [
    'validation' => [
        'maxlength' => 50  // ONLY this validation applies - model rules ignored
    ]
]);
// Result: Field is NOT required, NOT validated as email, only maxlength=50

// To extend model validation, you must be explicit:
$formwriter->textinput('usr_email', 'Email Address', [
    'validation' => [
        'required' => true,    // Keep from model
        'email' => true,       // Keep from model
        'maxlength' => 50,     // Override model's 100
        'remote' => [...]      // Add new validation
    ]
]);
```

**Rule: When `'validation'` is specified, it completely replaces auto-detected validation**

### Turning Off Validation

Simple ways to disable validation when needed:

```php
// FIELD LEVEL - Turn off validation for specific fields
$formwriter->textarea('admin_notes', 'Admin Notes', [
    'validation' => false  // No validation for this field
]);

// FORM LEVEL - Turn off ALL validation for the entire form
$formwriter = new FormWriterV2Bootstrap('import_form', [
    'action' => '/import',
    'method' => 'POST',
    'validation' => false  // Disables validation for ALL fields
]);

// BACKEND - Skip validation conditionally
if ($_POST) {
    if ($_POST['skip_validation'] ?? false) {
        // Process without validation
        $data = $_POST;
    } else {
        // Normal validation (errors stored internally)
        if (!$formwriter->validate($_POST)) {
            // Handle validation errors
            $errors = $formwriter->getErrors();
        }
    }
}
```

### Real-World Example - How Clean It Gets

With intelligent auto-detection and value arrays, most forms become incredibly clean:

```php
// Option 1: Direct model export (Phase 1: Direct usage)
require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));
$formwriter = new FormWriterV2Bootstrap('user_edit_form', [
    'action' => '/users/save',
    'method' => 'POST',
    'values' => $user->export_as_array()  // Auto-fill all field values!
]);

// Option 2: Custom prepared values when you need manipulation
$values = $user->export_as_array();
$values['usr_email'] = strtolower($values['usr_email']);  // Normalize email
$values['display_name'] = "{$values['usr_first_name']} {$values['usr_last_name']}";  // Computed field

$formwriter = new FormWriterV2Bootstrap('user_edit_form', [
    'action' => '/users/save',
    'method' => 'POST',
    'values' => $values  // Pass manipulated values
]);

// These ALL auto-fill values AND auto-detect validation from User model
$formwriter->textinput('usr_email', 'Email');
$formwriter->textinput('usr_first_name', 'First Name');
$formwriter->textinput('usr_last_name', 'Last Name');
$formwriter->dropinput('usr_timezone', 'Time Zone', [
    'options' => getTimezoneList()
    // Value auto-filled from values['usr_timezone']
]);

// This auto-detects from User but adds extra rules
$formwriter->passwordinput('usr_password', 'Password', [
    'validation' => [
        'minlength' => 8,  // Add to model rules
        'pattern' => '/(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])/'
    ]
]);

// These have no model prefix, so they're manual
$formwriter->passwordinput('password_confirm', 'Confirm Password', [
    'validation' => ['required' => true, 'matches' => 'usr_password']
]);

$formwriter->checkboxinput('accept_terms', 'I accept the terms', [
    'validation' => ['required' => true]
]);
```

### Before vs After Comparison

```php
// BEFORE (FormWriter v1) - verbose and repetitive
$formwriter->textinput('usr_email', 'Email', 'text', 20, $user->get('usr_email'),
    '', 255, '', '', 'user@example.com', '', true, false, false);
$validator = new Validator();
if (!$validator->validateEmail($_POST['usr_email'], 'Email')) {
    // Handle error separately
}

// AFTER (FormWriter v2) - clean and automatic
$formwriter->textinput('usr_email', 'Email');
// That's it! Validation is auto-detected from User model
// Works on both frontend (JS) and backend (PHP)
// Same rules used when calling $user->save()
```

### Validation Types and Integration

FormWriter v2 uses the existing validation infrastructure with smart type detection:

#### Validation Option Specification (Preferred)
```php
// Use the 'validation' option to specify validation rules:
$formwriter->textinput('email', 'Email', ['validation' => 'email']);       // Uses validateEmail()
$formwriter->textinput('zip', 'ZIP Code', ['validation' => 'zip']);        // Uses validateZip()
$formwriter->textinput('phone', 'Phone', ['validation' => 'phone']);       // Uses phone validation
$formwriter->dateinput('date', 'Start Date', ['validation' => 'date']);    // Uses validateDate()

// Type-to-Validator Mapping (Built-in)
$type_validators = [
    'email' => 'validateEmail',        // Uses existing Validator::validateEmail()
    'zip' => 'validateZip',            // Uses existing Validator::validateZip()
    'phone' => 'validatePhone',        // Uses phone validation pattern
    'date' => 'validateDate',          // Uses existing Validator::validateDate()
    'number' => 'validateNumber',      // Uses existing Validator::validateNumber()
    'url' => 'validateURL',            // URL validation
    'ssn' => 'validateSSN',            // SSN validation
    'ein' => 'validateEIN',            // EIN validation
    'credit_card' => 'validateCard',   // Credit card validation
];
```

#### Manual Validation Rules (Use Sparingly)
```php
// Only use these when type-based validation isn't enough:
$validation_rules = [
    'required' => boolean,
    'minlength' => integer,
    'maxlength' => integer,
    'min' => numeric,
    'max' => numeric,
    'matches' => 'field_name',          // Must match another field

    // AVOID using pattern directly - use named validators instead
    'pattern' => 'regex_pattern',       // Last resort only!

    // Maps to existing FieldConstraints functions:
    'constraints' => [
        'NoWebsite',
        'NoEmailAddress',
        'NoPhoneNumber',
        'WordLength' => [2, 64]
    ],

    // Database validation:
    'unique' => [
        'table' => 'usr_users',
        'column' => 'usr_email',
        'exclude_id' => $user_id
    ],

    // Custom error messages
    'messages' => [
        'required' => 'This field is required',
        'type' => 'Invalid format'
    ]
];
```

#### Best Practice: Use Named Validators, Not Patterns
```php
// ❌ AVOID - Don't write patterns repeatedly
$formwriter->textinput('phone', 'Phone', [
    'validation' => [
        'pattern' => '/^\(?([0-9]{3})\)?[-. ]?([0-9]{3})[-. ]?([0-9]{4})$/'
    ]
]);

// ✅ GOOD - Use predefined validator
$formwriter->textinput('phone', 'Phone', ['validation' => 'phone']);

// ✅ BETTER - Register custom validator once, reuse everywhere
FormWriterV2Base::registerValidator('us_phone', [
    'pattern' => '/^\(?([0-9]{3})\)?[-. ]?([0-9]{3})[-. ]?([0-9]{4})$/',
    'messages' => ['pattern' => 'Please enter a valid US phone number']
]);
$formwriter->textinput('phone', 'Phone', ['validation' => 'us_phone']);
```

### Error Handling

FormWriter v2 stores validation errors internally for automatic display:

```php
// Basic usage
if ($_POST) {
    $formwriter = new FormWriterV2Bootstrap('user_form', [
        'values' => $_POST  // Preserve user input
    ]);

    // validate() stores errors internally
    $formwriter->validate($_POST);

    if ($formwriter->hasErrors()) {
        // Errors will be displayed automatically next to fields
        $formwriter->begin_form();
        $formwriter->textinput('usr_email', 'Email');  // Shows any email errors
        $formwriter->textinput('usr_name', 'Name');    // Shows any name errors
        $formwriter->end_form();
    } else {
        // Success - save and redirect
    }
}
```

#### Error API Methods

```php
class FormWriterV2Base {
    protected $errors = [];

    // Core methods
    public function validate($data) {
        // Validates AND stores errors internally
        $this->errors = $this->performValidation($data);
        return !empty($this->errors);  // Returns true if errors found
    }

    // Error access methods
    public function hasErrors() {
        return !empty($this->errors);
    }

    public function getErrors() {
        return $this->errors;
    }

    public function getFieldErrors($field) {
        return $this->errors[$field] ?? [];
    }

    // Manual error manipulation (advanced use)
    public function setErrors($errors) {
        $this->errors = $errors;
    }

    public function addError($field, $message) {
        $this->errors[$field][] = $message;
    }

    public function clearErrors() {
        $this->errors = [];
    }
}
```

#### How Fields Display Errors

Each field method automatically checks for and displays its errors:

```php
public function textinput($name, $label = '', $options = []) {
    // Output field wrapper
    echo '<div class="form-group">';

    // Output label
    if ($label) {
        echo '<label>' . htmlspecialchars($label) . '</label>';
    }

    // Output input
    echo '<input type="text" name="' . htmlspecialchars($name) . '" ...>';

    // Automatically display any errors for this field
    if (isset($this->errors[$name])) {
        foreach ($this->errors[$name] as $error) {
            echo '<div class="error-message">' . htmlspecialchars($error) . '</div>';
        }
    }

    echo '</div>';
}
```

### Backend Validation (Integrated in FormWriterV2Base)

FormWriterV2Base uses the existing Validator class directly - no wrapper needed:

```php
abstract class FormWriterV2Base {
    protected $validator;  // Existing Validator instance

    public function __construct($form_id, $options = []) {
        // ... other initialization
        $this->validator = new Validator();  // Use existing Validator directly
    }

    protected function validateField($field_name, $value, $rules) {
        $errors = [];

        foreach ($rules as $rule => $param) {
            switch ($rule) {
                // Use existing Validator methods directly
                case 'required':
                    if ($param && !$this->validator->validateGeneral($value, $field_name, $rules['messages']['required'] ?? null)) {
                        $errors[] = end($this->validator->errors);
                    }
                    break;

                case 'email':
                    if ($param && !$this->validator->validateEmail($value, $field_name, $rules['messages']['email'] ?? null)) {
                        $errors[] = end($this->validator->errors);
                    }
                    break;

                case 'zip':
                    if ($param && !$this->validator->validateZip($value, $field_name, $rules['messages']['zip'] ?? null)) {
                        $errors[] = end($this->validator->errors);
                    }
                    break;

                case 'number':
                    if ($param && !$this->validator->validateNumber($value, $field_name, $rules['messages']['number'] ?? null)) {
                        $errors[] = end($this->validator->errors);
                    }
                    break;

                case 'date':
                    if ($param && !$this->validator->validateDate($value, $field_name, $rules['messages']['date'] ?? null)) {
                        $errors[] = end($this->validator->errors);
                    }
                    break;

                // Use FieldConstraints functions
                case 'constraints':
                    foreach ($param as $constraint => $args) {
                        try {
                            if (is_numeric($constraint)) {
                                // Simple constraint like 'NoWebsite'
                                call_user_func($args, $field_name, $value);
                            } else {
                                // Constraint with params like 'WordLength' => [2, 64]
                                array_unshift($args, $field_name, $value);
                                call_user_func_array($constraint, $args);
                            }
                        } catch (FieldConstraintError $e) {
                            $errors[] = $e->getMessage();
                        }
                    }
                    break;

                // New validation types for v2
                case 'minlength':
                    if (strlen($value) < $param) {
                        $errors[] = $rules['messages']['minlength'] ?? "Must be at least {$param} characters";
                    }
                    break;

                case 'maxlength':
                    if (strlen($value) > $param) {
                        $errors[] = $rules['messages']['maxlength'] ?? "Must be no more than {$param} characters";
                    }
                    break;

                case 'pattern':
                    if (!preg_match($param, $value)) {
                        $errors[] = $rules['messages']['pattern'] ?? "Invalid format";
                    }
                    break;

                case 'matches':
                    // Field comparison - $param is the field name to match
                    if ($value !== ($this->values[$param] ?? null)) {
                        $errors[] = $rules['messages']['matches'] ?? "Does not match {$param}";
                    }
                    break;

                // ... other rules
            }
        }

        return $errors;
    }
}
```

## Field Types and Options

### Method Signature Consistency

All field creation methods use a consistent signature for maximum predictability:

```php
// Standard signature for ALL field methods
public function methodname($name, $label = '', $options = [])

// Examples:
$formwriter->textinput('usr_email', 'Email Address', ['validation' => 'email']);
$formwriter->hiddeninput('user_id', '', ['value' => $user_id]);  // Empty label for hidden
$formwriter->hiddeninput('csrf_token');  // Can omit label entirely

// Submit button with optional name (defaults to 'submit')
$formwriter->submitbutton('', 'Save User');  // name="submit"
$formwriter->submitbutton('save_btn', 'Save User', ['class' => 'btn-primary']);  // name="save_btn"

// The signature allows flexibility:
public function submitbutton($name = 'submit', $label = 'Submit', $options = []) {
    // If $name is empty string, use 'submit'
    if (!$name) $name = 'submit';
    // Output button with name and label
}
```

This consistent signature:
- Makes the API predictable
- Allows label to be omitted when not needed (hidden fields)
- Keeps all options in the options array
- Maintains the same pattern across all field types

### Core Field Types and Validation Options

FormWriter v2 uses the same field creation methods as v1, but with cleaner options arrays:

```php
// Standard HTML Input Methods (like v1 but with options arrays)
$formwriter->textinput('name', 'Name', ['placeholder' => 'Enter your name']);
$formwriter->textarea('description', 'Description', ['rows' => 5]);
$formwriter->dropinput('choice', 'Choice', ['options' => [...]]);
$formwriter->passwordinput('password', 'Password', ['validation' => 'strong_password']);
$formwriter->checkboxinput('agree', 'I agree', ['validation' => ['required' => true]]);
$formwriter->radioinput('option', 'Select One', ['options' => [...]]);
$formwriter->dateinput('start_date', 'Start Date', ['min' => '2024-01-01']);
$formwriter->fileinput('document', 'Upload Document', ['accept' => '.pdf,.doc,.docx']);
$formwriter->hiddeninput('user_id', $user_id);

// Use the validation option to specify predefined validators
$formwriter->textinput('email', 'Email', ['validation' => 'email']);        // Validates email format
$formwriter->textinput('phone', 'Phone', ['validation' => 'phone']);        // Validates phone format
$formwriter->textinput('zip', 'ZIP Code', ['validation' => 'zip']);         // Validates ZIP format
$formwriter->textinput('url', 'Website', ['validation' => 'url']);          // Validates URL format
$formwriter->textinput('ssn', 'SSN', ['validation' => 'ssn']);             // Validates SSN format
$formwriter->textinput('ein', 'EIN', ['validation' => 'ein']);             // Validates EIN format
$formwriter->textinput('credit_card', 'Card Number', ['validation' => 'credit_card']); // Validates card format

// Custom Named Validators (define once, use everywhere)
FormWriterV2Base::registerValidator('us_phone', [
    'pattern' => '/^\(?([0-9]{3})\)?[-. ]?([0-9]{3})[-. ]?([0-9]{4})$/',
    'messages' => ['pattern' => 'Please enter a valid US phone number']
]);

FormWriterV2Base::registerValidator('strong_password', [
    'minlength' => 8,
    'pattern' => '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/',
    'messages' => ['pattern' => 'Password must contain uppercase, lowercase, number, and special character']
]);

// Use custom validators
$formwriter->textinput('phone', 'Phone Number', ['validation' => 'us_phone']);
$formwriter->passwordinput('password', 'Password', ['validation' => 'strong_password']);
```

### Form Output Methods

FormWriter v2 uses the same pattern as v1 - immediate output of form elements:

```php
// begin_form() outputs the opening form tag and CSRF token
$formwriter->begin_form();
// Outputs:
// <form id="user_form" method="POST" action="/users/save">
// <input type="hidden" name="_csrf_token" value="abc123...">

// Field methods output immediately
$formwriter->textinput('usr_email', 'Email');  // Outputs field HTML
$formwriter->textinput('usr_name', 'Name');    // Outputs field HTML

// end_form() outputs the closing tag
$formwriter->end_form();
// Outputs: </form>
```

#### begin_form() Output

The `begin_form()` method outputs:
1. Opening `<form>` tag with all attributes
2. CSRF hidden field (if enabled)

```php
public function begin_form() {
    // Output form tag with all attributes from options
    echo '<form';
    echo ' id="' . $this->form_id . '"';
    echo ' method="' . ($this->options['method'] ?? 'POST') . '"';
    echo ' action="' . ($this->options['action'] ?? '') . '"';

    // Additional attributes
    if (isset($this->options['class'])) {
        echo ' class="' . $this->options['class'] . '"';
    }
    if (isset($this->options['enctype'])) {
        echo ' enctype="' . $this->options['enctype'] . '"';
    }
    // Any data-* attributes
    foreach ($this->options as $key => $value) {
        if (strpos($key, 'data-') === 0) {
            echo ' ' . $key . '="' . $value . '"';
        }
    }
    echo '>';

    // Output CSRF token if enabled
    if ($this->csrf_token) {
        echo '<input type="hidden" name="_csrf_token" value="' . $this->csrf_token . '">';
    }
}
```

### How Validation Works

```php
// When you specify validation options, the appropriate validators are used:
$formwriter->textinput('usr_email', 'Email', ['validation' => 'email']);
// Uses validateEmail() from Validator class

$formwriter->textinput('usr_zip', 'ZIP Code', ['validation' => 'zip']);
// Uses validateZip() from Validator class

$formwriter->textinput('usr_phone', 'Phone', ['validation' => 'phone']);
// Uses phone validation pattern

// You can still combine or extend validation rules:
$formwriter->textinput('usr_email', 'Work Email', [
    'validation' => [
        'required' => true,
        'email' => true,  // Email validation
        'domain' => '@company.com'  // Add custom validation
    ]
]);
```

### Common Options Structure
```php
$options = [
    // Display
    'value' => mixed,
    'placeholder' => string,
    'helptext' => string,
    'class' => string,
    'id' => string,

    // State
    'readonly' => boolean,
    'disabled' => boolean,
    'autofocus' => boolean,

    // Validation (see Validation Types above)
    'validation' => array,

    // Events
    'onchange' => string,
    'onclick' => string,
    'onblur' => string,

    // Field-specific
    'autocomplete' => string,
    'multiple' => boolean,      // For select/file
    'accept' => string,         // For file
    'rows' => integer,          // For textarea
    'cols' => integer,          // For textarea
    'options' => array,         // For select/radio/checkbox
];
```

## Implementation Classes

### Phase 1: Standalone Implementation

In Phase 1, FormWriterV2 exists as a completely separate implementation:

```php
// Direct usage in Phase 1 - no theme integration yet
require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));
$formwriter = new FormWriterV2Bootstrap('form_id', $options);

// Or for Tailwind
require_once(PathHelper::getIncludePath('includes/FormWriterV2Tailwind.php'));
$formwriter = new FormWriterV2Tailwind('form_id', $options);
```

### Phase 2: Theme Integration (Future)

In Phase 2, themes can optionally provide FormWriterV2 classes or migrate their FormWriter:

```php
// Option A: Themes provide separate FormWriterV2.php
// In /theme/bootstrap/includes/FormWriterV2.php
class FormWriterV2 extends FormWriterV2Bootstrap {
    // Theme customizations
}

// Option B: Themes update their FormWriter.php to extend v2
// In /theme/bootstrap/includes/FormWriter.php
class FormWriter extends FormWriterV2Bootstrap {
    // This would be a breaking change - Phase 2 only
}
```

### Base Class Structure
```php
abstract class FormWriterV2Base {
    protected $form_id;
    protected $options;
    protected $fields = [];
    protected $csrf_token;
    protected $validation_rules = [];
    protected $validator;
    protected $values = [];  // Array of field values
    protected $errors = [];  // Validation errors

    // Static property for custom validators
    protected static $custom_validators = [];

    public function __construct($form_id, $options = []) {
        $this->form_id = $form_id;
        $this->options = array_merge($this->getDefaultOptions(), $options);
        $this->validator = new Validator();  // Use existing Validator directly

        // Store values array if provided
        if (isset($this->options['values'])) {
            $this->values = $this->options['values'];
        }

        // Initialize CSRF if needed
        if ($this->options['csrf'] ?? false) {
            $this->initializeCSRF();
        }
    }

    // Get default options for forms
    protected function getDefaultOptions() {
        return [
            'method' => 'POST',
            'action' => '',
            'csrf' => null,  // Will be set based on method if not specified
            'csrf_lifetime' => 7200,
            'csrf_field' => '_csrf_token',
            'validation' => true,  // Validation enabled by default
            'class' => '',
            'enctype' => null
        ];
    }

    // Initialize CSRF token
    protected function initializeCSRF() {
        // Default CSRF to true for POST, false for GET if not specified
        if ($this->options['csrf'] === null) {
            $this->options['csrf'] = ($this->options['method'] === 'POST');
        }

        // Generate token only if CSRF is enabled
        if ($this->options['csrf'] === true) {
            $this->csrf_token = bin2hex(random_bytes(32));
            $_SESSION['csrf_tokens'][$this->form_id] = [
                'token' => $this->csrf_token,
                'expires' => time() + $this->options['csrf_lifetime']
            ];
        }
    }

    // Validation method that respects the validation setting
    public function validate($data) {
        // Clear previous errors
        $this->errors = [];

        // Check if validation is disabled at form level
        if (isset($this->options['validation']) && $this->options['validation'] === false) {
            return true;  // Validation skipped, no errors
        }

        foreach ($this->fields as $field) {
            // Skip fields with validation disabled
            if (empty($field['validation']) || $field['validation'] === false) {
                continue;
            }

            $field_errors = $this->validator->validate(
                $field['name'],
                $data[$field['name']] ?? null,
                $field['validation']
            );

            if (!empty($field_errors)) {
                $this->errors[$field['name']] = $field_errors;
            }
        }

        // Store errors internally and return boolean
        return empty($this->errors);
    }

    // CSRF validation method
    public function validateCSRF($data) {
        // Skip if CSRF is disabled
        if ($this->options['csrf'] !== true) {
            return true;
        }

        $field_name = $this->options['csrf_field'];
        $token = $data[$field_name] ?? '';

        // Check if token exists in session
        if (!isset($_SESSION['csrf_tokens'][$this->form_id])) {
            return false;
        }

        $stored = $_SESSION['csrf_tokens'][$this->form_id];

        // Check if expired
        if ($stored['expires'] < time()) {
            unset($_SESSION['csrf_tokens'][$this->form_id]);
            return false;
        }

        // Validate token
        $valid = hash_equals($stored['token'], $token);

        // Clear token after use (one-time use)
        if ($valid) {
            unset($_SESSION['csrf_tokens'][$this->form_id]);
        }

        return $valid;
    }

    // Core methods - consistent signature with optional label
    public function textinput($name, $label = '', $options = []);
    public function passwordinput($name, $label = '', $options = []);
    public function textarea($name, $label = '', $options = []);
    public function dropinput($name, $label = '', $options = []);
    public function checkboxinput($name, $label = '', $options = []);
    public function radioinput($name, $label = '', $options = []);
    public function dateinput($name, $label = '', $options = []);
    public function fileinput($name, $label = '', $options = []);
    public function hiddeninput($name, $label = '', $options = []);  // Label ignored
    public function submitbutton($name = 'submit', $label = 'Submit', $options = []);

    // Form output methods (immediate output like v1)
    public function begin_form();
    public function end_form();

    // Helper method to get all validation rules for JavaScript output
    protected function getAllValidationRules() {
        $rules = [];
        foreach ($this->fields as $field) {
            if (!empty($field['validation']) && $field['validation'] !== false) {
                $rules[$field['name']] = $field['validation'];
            }
        }
        return $rules;
    }

    // Example method implementation with intelligent auto-detection
    public function textinput($name, $label, $options = []) {
        // Auto-fill value from values array if not explicitly provided
        if (!isset($options['value']) && isset($this->values[$name])) {
            $options['value'] = $this->values[$name];
        }

        $model_class = null;

        // Determine model class
        if (isset($options['model']) && $options['model'] === false) {
            // Explicitly disabled auto-detection
            $model_class = null;
            unset($options['model']);
        } else {
            // Auto-detect from field prefix
            $model_class = $this->detectModelFromFieldName($name);
        }

        // Handle validation options
        if (isset($options['validation']) && $options['validation'] === false) {
            // Explicitly disabled validation
            $options['validation'] = [];
        } else {
            // Handle string validation shorthand (e.g., 'validation' => 'email')
            if (isset($options['validation']) && is_string($options['validation'])) {
                $options['validation'] = $this->getTypeValidation($options['validation']);
            }

            // Start with model validation if available
            $base_validation = [];
            if ($model_class) {
                $base_validation = $this->getModelValidation($model_class, $name);
            }

            // Merge with any provided validation (provided takes precedence)
            if (isset($options['validation']) && is_array($options['validation'])) {
                $options['validation'] = array_merge($base_validation, $options['validation']);
            } else if (!isset($options['validation'])) {
                $options['validation'] = $base_validation;
            }
        }

        // Store field configuration for validation
        $this->fields[$name] = [
            'name' => $name,
            'input_type' => 'text',  // HTML input type
            'label' => $label,
            'options' => $options,
            'validation' => $options['validation'] ?? []
        ];

        // Output the field HTML immediately (like v1)
        $this->outputTextInput($name, $label, $options);
    }

    // Similar implementations for other field methods
    public function passwordinput($name, $label, $options = []) {
        // Similar to textinput but with input_type = 'password'
    }

    public function textarea($name, $label, $options = []) {
        // Similar but with input_type = 'textarea'
    }

    public function dropinput($name, $label, $options = []) {
        // Similar but with input_type = 'select'
    }

    // Get validation rules based on validation option
    protected function getTypeValidation($type) {
        // Map of types to validation rules
        static $type_validators = [
            'email' => ['email' => true],
            'url' => ['url' => true],
            'zip' => ['zip' => true],
            'phone' => ['phone' => true],
            'date' => ['date' => true],
            'number' => ['number' => true],
            'ssn' => ['ssn' => true],
            'ein' => ['ein' => true],
            'credit_card' => ['credit_card' => true],
            // Custom registered validators
        ];

        // Check if it's a registered custom validator
        if (isset(self::$custom_validators[$type])) {
            return self::$custom_validators[$type];
        }

        return $type_validators[$type] ?? [];
    }

    // Register a custom named validator
    public static function registerValidator($name, $rules) {
        self::$custom_validators[$name] = $rules;
    }

    // Auto-detect model from field naming convention
    protected function detectModelFromFieldName($field_name) {
        // Extract prefix (e.g., 'usr_' from 'usr_email')
        if (!preg_match('/^([a-z]+)_/', $field_name, $matches)) {
            return null;  // No prefix found
        }

        $prefix = $matches[1];

        // Get prefix map (cached for performance)
        $prefix_map = $this->getModelPrefixMap();

        $model_name = $prefix_map[$prefix] ?? null;

        if ($model_name && class_exists($model_name)) {
            // Verify the field actually exists in this model
            if (isset($model_name::$field_specifications[$field_name])) {
                return $model_name;
            }
        }

        return null;  // No matching model found
    }

    // Build or retrieve the prefix-to-model mapping
    protected function getModelPrefixMap() {
        static $map = null;

        if ($map === null) {
            // Option 1: Auto-discover by scanning /data directory
            $map = [];
            foreach (glob(PathHelper::getIncludePath('data/*_class.php')) as $file) {
                $class_name = basename($file, '_class.php');
                $class_name = str_replace('_', '', ucwords($class_name, '_'));

                if (class_exists($class_name) && isset($class_name::$prefix)) {
                    $map[$class_name::$prefix] = $class_name;
                }
            }

            // Option 2: Hardcoded fallback for core models
            $fallback = [
                'usr' => 'User',
                'pro' => 'Product',
                'evt' => 'Event',
                'grp' => 'Group',
            ];

            $map = array_merge($fallback, $map);
        }

        return $map;
    }

    // Model integration helper
    protected function getModelValidation($model_class, $field_name) {
        if (!class_exists($model_class)) {
            throw new Exception("Model class {$model_class} not found");
        }

        $field_specs = $model_class::$field_specifications[$field_name] ?? [];

        // Build validation array from field_specifications
        $validation = $field_specs['validation'] ?? [];

        // Add legacy properties to validation
        if (isset($field_specs['required']) && $field_specs['required']) {
            $validation['required'] = true;
        }
        if (isset($field_specs['unique']) && $field_specs['unique']) {
            $validation['unique'] = [
                'table' => $model_class::$tablename,
                'column' => $field_name
            ];
        }

        // Add field constraints if they exist
        if (isset($model_class::$field_constraints[$field_name])) {
            $validation['constraints'] = $model_class::$field_constraints[$field_name];
        }

        return $validation;
    }

    // Abstract methods for theme-specific HTML generation
    abstract protected function outputTextInput($name, $label, $options);
    abstract protected function outputPasswordInput($name, $label, $options);
    abstract protected function outputTextarea($name, $label, $options);
    abstract protected function outputDropInput($name, $label, $options);
    // ... other field-specific output methods
}

// Theme implementations
class FormWriterV2Bootstrap extends FormWriterV2Base {
    // Bootstrap-specific HTML output
    protected function outputTextInput($name, $label, $options) {
        $value = $options['value'] ?? '';
        $placeholder = $options['placeholder'] ?? '';
        $class = $options['class'] ?? 'form-control';

        // Output Bootstrap-styled form group
        echo '<div class="form-group">';
        if ($label) {
            echo '<label for="' . htmlspecialchars($name) . '">' . htmlspecialchars($label) . '</label>';
        }
        echo '<input type="text" name="' . htmlspecialchars($name) . '" ';
        echo 'id="' . htmlspecialchars($name) . '" ';
        echo 'class="' . htmlspecialchars($class) . '" ';
        echo 'value="' . htmlspecialchars($value) . '" ';
        if ($placeholder) {
            echo 'placeholder="' . htmlspecialchars($placeholder) . '" ';
        }
        if (!empty($options['readonly'])) {
            echo 'readonly ';
        }
        if (!empty($options['disabled'])) {
            echo 'disabled ';
        }
        echo '>';

        // Display any errors for this field
        if (isset($this->errors[$name])) {
            foreach ($this->errors[$name] as $error) {
                echo '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
            }
        }

        // Display help text if provided
        if (!empty($options['helptext'])) {
            echo '<small class="form-text text-muted">' . htmlspecialchars($options['helptext']) . '</small>';
        }

        echo '</div>';
    }

    // Similar implementations for other field types...
}

class FormWriterV2Tailwind extends FormWriterV2Base {
    // Tailwind-specific HTML output
    protected function outputTextInput($name, $label, $options) {
        // Tailwind-styled output
    }
}
```

## JavaScript Validation Integration

FormWriter v2 integrates directly with the existing JoineryValidator system for client-side validation. **No changes to joinery-validate.js are required** - FormWriter v2 outputs validation rules in the exact format JoineryValidator already expects.

### How It Works

1. **FormWriter outputs JoineryValidator initialization code directly**
2. **JoineryValidator handles all validation** (existing, unchanged)
3. **No bridge or wrapper needed** - direct integration

### Direct JoineryValidator Output

FormWriter v2 outputs JoineryValidator initialization code directly, just like v1's `set_validate()` but automatically:

```php
public function begin_form() {
    // Output form tag
    echo '<form id="' . $this->form_id . '" method="' . $this->options['method'] . '" action="' . $this->options['action'] . '">';

    // Output CSRF token if enabled
    if ($this->csrf_token) {
        echo '<input type="hidden" name="_csrf_token" value="' . $this->csrf_token . '">';
    }

    // Output JoineryValidator initialization directly (inline JavaScript)
    if (!empty($this->getAllValidationRules())) {
        echo '<script type="text/javascript">';
        echo 'document.addEventListener("DOMContentLoaded", function() {';
        echo '    var form = document.getElementById("' . $this->form_id . '");';
        echo '    if (form) {';

        // Build rules and messages in JoineryValidator format
        $rules = [];
        $messages = [];

        foreach ($this->getAllValidationRules() as $fieldName => $fieldRules) {
            $jsRules = [];
            $jsMessages = [];

            // Convert to JoineryValidator format
            if (isset($fieldRules['required']) && $fieldRules['required']) {
                $jsRules['required'] = true;
                if (isset($fieldRules['messages']['required'])) {
                    $jsMessages['required'] = $fieldRules['messages']['required'];
                }
            }
            if (isset($fieldRules['email']) && $fieldRules['email']) {
                $jsRules['email'] = true;
                if (isset($fieldRules['messages']['email'])) {
                    $jsMessages['email'] = $fieldRules['messages']['email'];
                }
            }
            if (isset($fieldRules['minlength'])) {
                $jsRules['minlength'] = $fieldRules['minlength'];
                if (isset($fieldRules['messages']['minlength'])) {
                    $jsMessages['minlength'] = $fieldRules['messages']['minlength'];
                }
            }
            if (isset($fieldRules['maxlength'])) {
                $jsRules['maxlength'] = $fieldRules['maxlength'];
                if (isset($fieldRules['messages']['maxlength'])) {
                    $jsMessages['maxlength'] = $fieldRules['messages']['maxlength'];
                }
            }
            // Add other rule mappings as needed

            if (!empty($jsRules)) {
                $rules[$fieldName] = $jsRules;
            }
            if (!empty($jsMessages)) {
                $messages[$fieldName] = $jsMessages;
            }
        }

        // Output JoineryValidator initialization
        echo '        var validator = new JoineryValidator(form, {';
        echo '            rules: ' . json_encode($rules) . ',';
        echo '            messages: ' . json_encode($messages);
        echo '        });';

        // Add AJAX submission handler if needed
        if (isset($this->options['data-ajax']) && $this->options['data-ajax'] === 'true') {
            echo $this->renderAjaxHandler();
        }

        echo '    }';
        echo '});';
        echo '</script>';
    }
}

// Method to render inline AJAX handler
protected function renderAjaxHandler() {
    $js = '
        // Add AJAX submission
        form.addEventListener("submit", function(e) {
            e.preventDefault();

            // Let JoineryValidator validate first
            if (!validator.isValid()) {
                return false;
            }

            // Submit via AJAX
            var formData = new FormData(form);

            fetch(form.action, {
                method: form.method,
                body: formData,
                headers: {
                    "X-Requested-With": "XMLHttpRequest"
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Call custom callback if specified
                    var callback = form.dataset.callback;
                    if (callback && window[callback]) {
                        window[callback](data);
                    } else {
                        // Default success behavior
                        alert(data.message || "Saved successfully");
                    }
                } else if (data.errors) {
                    // Display validation errors
                    Object.keys(data.errors).forEach(function(fieldName) {
                        var field = form.querySelector("[name=\'" + fieldName + "\']");
                        if (field) {
                            // Find or create error container
                            var errorDiv = field.parentElement.querySelector(".error-message");
                            if (!errorDiv) {
                                errorDiv = document.createElement("div");
                                errorDiv.className = "error-message text-danger";
                                field.parentElement.appendChild(errorDiv);
                            }
                            errorDiv.textContent = data.errors[fieldName].join(", ");
                        }
                    });
                } else if (data.error) {
                    alert(data.error);
                }
            })
            .catch(error => {
                console.error("Form submission error:", error);
                alert("An error occurred. Please try again.");
            });
        });
    ';

    return $js;
}

### Validation Rule Mapping

Backend rules map to JoineryValidator validators:

| Backend Rule | JoineryValidator | Notes |
|-------------|-----------------|-------|
| `required` | `required` | Direct mapping |
| `email` | `email` | Direct mapping |
| `minlength` | `minlength` | With parameter |
| `maxlength` | `maxlength` | With parameter |
| `pattern` | `pattern` | Regex pattern |
| `matches` | `matches` | Field comparison |
| `min` | `min` | Numeric minimum |
| `max` | `max` | Numeric maximum |
| `phone` | *Custom* | Add to JoineryValidator |
| `zip` | *Custom* | Add to JoineryValidator |
| `url` | `url` | Direct mapping |

### Adding Custom Validators to JoineryValidator

For validators that don't exist in JoineryValidator, we add them:

```javascript
// Add to joinery-validate.js (additive only, no modifications)
JoineryValidator.prototype.validators.phone = function(value, element) {
    const phoneRegex = /^\(?([0-9]{3})\)?[-. ]?([0-9]{3})[-. ]?([0-9]{4})$/;
    return phoneRegex.test(value);
};

JoineryValidator.prototype.validators.zip = function(value, element) {
    const zipRegex = /^[0-9]{5}([- ]?[0-9]{4})?$/;
    return zipRegex.test(value);
};
```

### Form Example with JavaScript Validation

```php
// PHP Side
$formwriter = new FormWriterV2Bootstrap('user_form', [
    'action' => '/save',
    'method' => 'POST'
]);

$formwriter->textinput('usr_email', 'Email', [
    'validation' => ['required' => true, 'email' => true]
]);

$formwriter->begin_form();  // Outputs JoineryValidator initialization inline
// ... fields ...
$formwriter->end_form();
```

Output includes:
```html
<form id="user_form" method="POST" action="/save">
<input type="hidden" name="_csrf_token" value="abc123...">
<script type="text/javascript">
document.addEventListener("DOMContentLoaded", function() {
    var form = document.getElementById("user_form");
    if (form) {
        var validator = new JoineryValidator(form, {
            rules: {
                "usr_email": {"required": true, "email": true}
            },
            messages: {}
        });
    }
});
</script>
<!-- Fields rendered here -->
</form>
```

No additional JavaScript files needed - JoineryValidator is initialized directly inline!

### Key Benefits

1. **Automatic**: JavaScript validation is automatically generated from PHP rules
2. **Consistent**: Same validation rules on frontend and backend
3. **No Modifications**: Uses existing JoineryValidator unchanged
4. **Extensible**: Can add new validators to JoineryValidator as needed
5. **Progressive Enhancement**: Forms work without JavaScript, enhanced when available
```

## Version Strategy

### Phase 1: Parallel Implementation
FormWriter v2 exists as a completely separate implementation alongside v1:
- FormWriter v1 classes remain unchanged and fully supported
- FormWriterV2 classes are used directly (FormWriterV2Bootstrap, FormWriterV2Tailwind)
- No changes to theme files or existing FormWriter usage
- Developers explicitly choose v2 by using FormWriterV2Bootstrap
- Perfect for testing and iteration before migration

```php
// Phase 1: v1 continues to work normally
$formwriter = new FormWriter('form_id');  // Uses v1 via theme

// Phase 1: v2 is used explicitly
require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));
$formwriter = new FormWriterV2Bootstrap('form_id', ['action' => '/save']);
$formwriter->textinput('field', 'Label', ['value' => $value]);
```

### Phase 2: Migration Options (Future)
After testing and iteration, themes can migrate:
```php
// Option A: Theme provides separate FormWriterV2 class
class FormWriterV2 extends FormWriterV2Bootstrap { }

// Option B: Theme updates FormWriter to extend v2 (breaking change)
class FormWriter extends FormWriterV2Bootstrap { }
```

## Usage Examples

### Simple Form Comparison
```php
// V1 CODE (still fully supported)
$formwriter = new FormWriterBootstrap('user_form');
$formwriter->startForm('/users/save', 'POST');
$formwriter->addFormElement('text', 'usr_name', 'Name', $user->get('usr_name'), '', '', true);
$formwriter->addFormElement('email', 'usr_email', 'Email', $user->get('usr_email'), '', '', true);
$formwriter->addSubmitButton('Save');
$formwriter->endForm();

// V2 CODE (Phase 1: Direct usage)
require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));
$formwriter = new FormWriterV2Bootstrap('user_form', [
    'action' => '/users/save',
    'method' => 'POST',
    'values' => $user->export_as_array()  // Pass all values at once!
]);
$formwriter->begin_form();
$formwriter->textinput('usr_name', 'Name');
// Auto-fills value AND auto-detects validation from User model
$formwriter->textinput('usr_email', 'Email');
// Auto-fills value AND auto-detects email validation from User model
$formwriter->submitbutton('submit', 'Save');
$formwriter->end_form();
```

### Complex Form with Model Integration
```php
// V2 CODE with model-based validation (Phase 1: Direct usage)
require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));

// Backend processing - unified validation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formwriter = new FormWriterV2Bootstrap('registration_form', [
        'action' => '/register',
        'method' => 'POST',
        'csrf' => true,
        'values' => $_POST  // Preserve user input on errors
    ]);

    if (!$formwriter->validateCSRF($_POST)) {
        die('CSRF validation failed');
    }

    // Form validation using same rules as model
    $formwriter->validate($_POST);

    if (!$formwriter->hasErrors()) {
        // Create user - model uses SAME validation rules
        $user = new User(NULL);
        $user->set('usr_email', $_POST['usr_email']);
        $user->set('usr_password', password_hash($_POST['usr_password'], PASSWORD_DEFAULT));

        try {
            $user->prepare();  // Uses same validation as form!
            $user->save();
            // Success - redirect
            header('Location: /welcome');
            exit;
        } catch (DisplayableUserException $e) {
            $formwriter->addError('usr_email', $e->getMessage());
        }
    }
} else {
    // GET request - create fresh form
    $formwriter = new FormWriterV2Bootstrap('registration_form', [
        'action' => '/register',
        'method' => 'POST',
        'csrf' => true
    ]);
}

// Display the form
$formwriter->begin_form();

// Validation auto-detected from usr_ prefix!
$formwriter->textinput('usr_email', 'Email Address', [
    'placeholder' => 'user@example.com'
    // No need to specify model - detected from 'usr_' prefix!
]);

// Auto-detects base validation, then adds extra
$formwriter->passwordinput('usr_password', 'Password', [
    'validation' => [  // These override auto-detected rules
        'required' => true,
        'minlength' => 8,
        'pattern' => '/(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])/',
        'messages' => [
            'pattern' => 'Password must contain uppercase, lowercase, and number'
        ]
    ]
]);

// Manual field not in model (confirm password)
$formwriter->passwordinput('usr_password_confirm', 'Confirm Password', [
    'validation' => [
        'required' => true,
        'matches' => 'usr_password',
        'messages' => [
            'matches' => 'Passwords do not match'
        ]
    ]
]);

$formwriter->submitbutton('register', 'Register');
$formwriter->end_form();
```

### Quick User Edit Form
```php
// Create user edit form - pass values array for auto-filling (Phase 1: Direct usage)
require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));
$formwriter = new FormWriterV2Bootstrap('user_edit_form', [
    'action' => '/users/save',
    'method' => 'POST',
    'values' => $user->export_as_array()  // All values at once!
]);

// Just add fields - values auto-filled, validation auto-detected!
$formwriter->begin_form();
$formwriter->textinput('usr_first_name', 'First Name', [
    'placeholder' => 'John'
]);
$formwriter->textinput('usr_last_name', 'Last Name', [
    'placeholder' => 'Doe'
]);
$formwriter->textinput('usr_email', 'Email', [
    'readonly' => true  // Email can't be changed
]);
$formwriter->dropinput('usr_timezone', 'Time Zone', [
    'options' => getTimezoneOptions()
    // Value auto-filled from values['usr_timezone']
]);
$formwriter->submitbutton('submit', 'Save Changes');
$formwriter->end_form();
```

## Model Updates for Unified Validation

### Adding Validation to field_specifications

Models can gradually adopt the unified validation system by adding a `validation` sub-array to their `field_specifications`:

```php
// Example: Updating User model
class User extends SystemBase {
    public static $field_specifications = array(
        'usr_email' => array(
            'type' => 'varchar(64)',
            'required' => true,  // Keep for backward compatibility
            'unique' => true,    // Keep for backward compatibility

            // NEW: Add validation sub-array for forms
            'validation' => array(
                'type' => 'email',
                'maxlength' => 64,
                'messages' => array(
                    'required' => 'Email address is required',
                    'type' => 'Please enter a valid email address',
                    'unique' => 'This email is already registered'
                )
            )
        ),

        'usr_age' => array(
            'type' => 'int4',

            // NEW: Validation rules
            'validation' => array(
                'type' => 'integer',
                'min' => 13,
                'max' => 120,
                'messages' => array(
                    'min' => 'You must be at least 13 years old',
                    'max' => 'Please enter a valid age'
                )
            )
        )
    );

    // Existing field_constraints can remain and will be auto-included
    public static $field_constraints = array(
        'usr_bio' => array(
            array('NoWebsite'),
            array('NoEmailAddress'),
            array('WordLength', 10, 500)
        )
    );
}
```

### Migration Strategy

1. **Phase 1**: Add `validation` arrays to frequently-used form fields
2. **Phase 2**: FormWriter v2 auto-detects and uses these validations
3. **Phase 3**: Gradually migrate complex validations from logic files to models
4. **No Breaking Changes**: Existing `required`, `unique`, and `field_constraints` continue to work

## File Structure

### Phase 1: New Files Only
```
/includes/
├── FormWriterV2Base.php           # NEW - Abstract base class (includes CSRF + validation)
├── FormWriterV2Bootstrap.php      # NEW - Bootstrap implementation
├── FormWriterV2Tailwind.php       # NEW - Tailwind implementation
├── Validator.php                  # EXISTING - used directly, no wrapper needed
├── FieldConstraints.php           # EXISTING - unchanged
└── SystemBase.php                 # EXISTING - unchanged (validation array already supported)

/utils/
├── forms_example_bootstrap.php    # EXISTING - v1 examples (unchanged)
└── forms_example_bootstrapv2.php  # NEW - v2 examples using FormWriterV2Bootstrap

/js/
└── joinery-validate.js            # EXISTING - may add new validators (phone, zip, etc.)

/tests/
└── formwriter-v2/                 # NEW - Unit and integration tests
```

### Phase 2: Theme Integration (Future)
```
/theme/bootstrap/includes/
├── FormWriter.php                 # EXISTING - would change to extend FormWriterV2Bootstrap
└── FormWriterV2.php               # OR NEW - separate v2 class

/theme/tailwind/includes/
├── FormWriter.php                 # EXISTING - would change to extend FormWriterV2Tailwind
└── FormWriterV2.php               # OR NEW - separate v2 class
```

## Testing Requirements

1. Unit tests for all validation rules
2. Integration tests for CSRF protection
3. Browser tests for client-side validation
4. Performance tests comparing v1 vs v2
5. End-to-end tests for complete form workflows
6. Example forms for development and testing:
   - Create `/utils/forms_example_bootstrapv2.php` as a copy of `forms_example_bootstrap.php` but using FormWriter v2
   - This will serve as both a working example and a test bed for all v2 features
   - Should demonstrate:
     - All field types (text, password, email, textarea, select, checkbox, radio, date, file, hidden)
     - Values array auto-filling using mock data
     - Validation auto-detection from field names
     - Manual validation specification
     - Disabling validation
     - CSRF protection
     - Custom validators
     - Error display
     - Before/after comparison with v1 code

## Implementation Phases

### Phase 1: Core Implementation (Week 1-2)
- Create base classes and structure
- Implement basic field types
- Add CSRF protection
- Create `/utils/forms_example_bootstrapv2.php` demonstrating all v2 features

### Phase 2: Validation Engine (Week 2-3)
- Build validation specification system
- Create client-side validation generator
- Add custom validation support

### Phase 3: Theme Implementations (Week 4)
- Implement Bootstrap version
- Implement Tailwind version
- Ensure visual compatibility

### Phase 4: Testing and Documentation (Week 5-6)
- Comprehensive testing
- Documentation
- Example forms and usage guides

## Success Metrics

1. **API Simplicity**:
   - Reduce form creation code by 70-80%
   - Most fields need just 1 line: `$formwriter->textinput('usr_email', 'Email');`
2. **Security**: 100% of v2 forms have CSRF protection by default
3. **Unified Validation**: Single source of truth for model and form validation
4. **Zero Configuration**: Auto-detection means most forms need NO validation specification
5. **Zero Discrepancies**: Frontend, backend, and model validation all use same rules
6. **Developer Experience**:
   - 90% reduction in validation-related bugs
   - No more duplicate validation code
   - Write validation once in model, use everywhere
   - Consistent validation messages across the application
7. **Performance**: Form rendering time within 5% of v1
8. **Backward Compatibility**: 100% of existing code continues to work unchanged

## Additional Features

### File Upload Validation

FormWriter v2 provides comprehensive file upload validation:

```php
$formwriter->fileinput('avatar', 'Profile Picture', [
    'validation' => [
        'required' => true,
        'max_size' => 5242880,  // 5MB in bytes
        'allowed_types' => ['image/jpeg', 'image/png', 'image/gif'],  // MIME types
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif'],  // File extensions
        'max_files' => 1,  // For multiple file uploads
        'messages' => [
            'required' => 'Please upload a profile picture',
            'max_size' => 'File must be less than 5MB',
            'allowed_types' => 'Only JPEG, PNG, and GIF images are allowed',
        ]
    ],
    'accept' => 'image/*',  // HTML accept attribute
    'multiple' => false
]);
```

Backend validation for files:
```php
public function validateFile($field_name, $files, $rules) {
    $errors = [];
    $file = $files[$field_name] ?? null;

    if ($rules['required'] && (!$file || $file['error'] === UPLOAD_ERR_NO_FILE)) {
        $errors[] = $rules['messages']['required'] ?? 'File is required';
    }

    if ($file && $file['error'] === UPLOAD_ERR_OK) {
        // Check file size
        if (isset($rules['max_size']) && $file['size'] > $rules['max_size']) {
            $errors[] = $rules['messages']['max_size'] ?? 'File is too large';
        }

        // Check MIME type
        if (isset($rules['allowed_types'])) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            if (!in_array($mime, $rules['allowed_types'])) {
                $errors[] = $rules['messages']['allowed_types'] ?? 'File type not allowed';
            }
        }

        // Check extension
        if (isset($rules['allowed_extensions'])) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $rules['allowed_extensions'])) {
                $errors[] = $rules['messages']['allowed_extensions'] ?? 'File extension not allowed';
            }
        }
    }

    return $errors;
}
```

### AJAX Submission Support

FormWriter v2 forms can handle AJAX submissions with proper CSRF and validation:

```php
// Form setup for AJAX
$formwriter = new FormWriterV2Bootstrap('ajax_form', [
    'action' => '/api/users/save',
    'method' => 'POST',
    'class' => 'ajax-form',
    'data-ajax' => 'true',
    'data-callback' => 'handleFormResponse'
]);
```

Server-side AJAX response:
```php
// In logic file
if ($request->isAjax()) {
    $formwriter = new FormWriterV2Bootstrap('ajax_form', $_POST);

    // Validate CSRF
    if (!$formwriter->validateCSRF($_POST)) {
        return json_encode([
            'success' => false,
            'error' => 'Security token expired. Please refresh the page.'
        ]);
    }

    // Validate form
    $formwriter->validate($_POST);

    if ($formwriter->hasErrors()) {
        return json_encode([
            'success' => false,
            'errors' => $formwriter->getErrors()
        ]);
    }

    // Process form
    return json_encode([
        'success' => true,
        'message' => 'Saved successfully'
    ]);
}
```

JavaScript handler (rendered inline by renderAjaxHandler() method):
```javascript
// Auto-detect AJAX forms and handle submission - rendered inline
document.querySelectorAll('form[data-ajax="true"]').forEach(form => {
    form.addEventListener('submit', function(e) {
        e.preventDefault();

        // Validate with JoineryValidator first
        if (!validator.isValid()) {
            return false;
        }

        // Submit via AJAX
        fetch(form.action, {
            method: form.method,
            body: new FormData(form),
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Call custom callback if specified
                if (form.dataset.callback && window[form.dataset.callback]) {
                    window[form.dataset.callback](data);
                }
            } else if (data.errors) {
                // Display validation errors
                formwriter.displayErrors(data.errors);
            }
        });
    });
});
```

### Multi-Step Forms

Multi-step forms are **deferred to Phase 2** due to complexity. Phase 1 focuses on single-page forms.

Phase 2 considerations for multi-step:
- Session storage of partial data
- Step validation before proceeding
- Progress indicators
- Back/forward navigation
- Conditional steps based on previous answers

### Custom Validation Functions

For complex validation beyond patterns, FormWriter v2 supports PHP callables:

```php
// Register custom validator
FormWriterV2Base::registerCustomValidator('business_email', function($value, $field_name) {
    // Custom logic - must not be from free email providers
    $freeProviders = ['gmail.com', 'yahoo.com', 'hotmail.com'];
    $domain = substr($value, strpos($value, '@') + 1);

    if (in_array($domain, $freeProviders)) {
        throw new ValidationException('Please use a business email address');
    }

    return true;
});

// Use in form
$formwriter->textinput('company_email', 'Company Email', [
    'validation' => [
        'required' => true,
        'email' => true,
        'custom' => 'business_email'  // Use registered validator
    ]
]);
```

For inline custom validation:
```php
$formwriter->textinput('username', 'Username', [
    'validation' => [
        'required' => true,
        'custom' => function($value, $field_name) {
            // Check if username is available
            $user = User::GetByColumn('usr_username', $value);
            if ($user && $user->key) {
                throw new ValidationException('Username is already taken');
            }
            return true;
        }
    ]
]);
```

## Phase 1 Scope Summary

### Included in Phase 1:
- All core field types with validation
- CSRF protection
- File upload validation
- AJAX submission support
- Custom validation functions
- JavaScript validation via JoineryValidator
- Error handling and display
- Values array auto-filling

### Deferred to Phase 2:
- Multi-step forms
- Form templates/themes
- Conditional fields
- Field dependencies
- Dynamic field addition/removal
- Form state persistence

## Appendix: Detailed Validation Rules

### Standard HTML5 Validation Attributes
- required
- minlength / maxlength
- min / max / step (numeric)
- pattern (regex)
- type (email, url, etc.)

### Extended Validation Rules
- matches (field comparison)
- unique (database uniqueness)
- custom (callable functions)
- conditional (based on other fields)
- remote (AJAX validation)

### Error Display Options
- Inline (next to field)
- Summary (top of form)
- Toast notifications
- Custom handlers