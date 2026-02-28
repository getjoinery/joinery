# Validation System Documentation

The Joinery platform implements a three-layer validation system:
1. **Client-side JavaScript validation** - Immediate user feedback
2. **FormWriter validation** - Framework integration and HTML generation
3. **Server-side model validation** - Data integrity and security

## System Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                    VALIDATION FLOW                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  User Input → JavaScript Validation → Form Submission          │
│                    (client-side)         (with errors blocked)  │
│                          ↓                                       │
│                    Server Receives                              │
│                          ↓                                       │
│              FormWriter Processes Data                          │
│                          ↓                                       │
│       Model->prepare() → Server Validation                      │
│            (field_specifications rules)                         │
│                          ↓                                       │
│              Model->save() → Database                           │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## 1. Client-Side JavaScript Validation

Joinery uses a custom **JoineryValidation** library - pure JavaScript with no jQuery dependencies (though compatible with jQuery if present).

### Library File
- Location: `/assets/js/joinery-validate.js`
- Version: 1.0.8
- Dependencies: None (standalone)

### Built-in Validators

| Validator | Purpose | Notes |
|-----------|---------|-------|
| `required` | Field must have a value | Triggers on blur/change |
| `email` | Valid email format | Uses standard email regex |
| `url` | Valid URL format | Uses URL parsing |
| `number` | Numeric value only | Accepts integers and decimals |
| `minlength` | Minimum character length | Value is character count |
| `maxlength` | Maximum character length | Value is character count |
| `min` | Minimum numeric value | Numeric comparison |
| `max` | Maximum numeric value | Numeric comparison |
| `equalTo` | Must match another field | Value = field name (e.g., 'password') |
| `time` | Valid time format HH:MM | 24-hour format |
| `date` | Valid date format | Various formats supported |
| `pattern` | Regex pattern match | Value = regex pattern |
| `remote` | AJAX validation | Server-side unique check |
| `unique` | Unique value in database | Auto-generated from field_specifications |

### JavaScript Validation Example

```php
<?php
// In a view or admin page
$formwriter = $page->getFormWriter('contact_form');

$validation_rules = array();
// Required field
$validation_rules['email']['required']['value'] = 'true';

// Email format validation
$validation_rules['email']['email']['value'] = 'true';

// Custom error message
$validation_rules['email']['required']['message'] = '"Please enter your email address"';

// Minimum length
$validation_rules['password']['minlength']['value'] = '8';
$validation_rules['password']['minlength']['message'] = '"Password must be at least 8 characters"';

// Pattern matching (alphanumeric only)
$validation_rules['username']['pattern']['value'] = '"/^[a-zA-Z0-9_]+$/"';

// Output validation script
echo $formwriter->set_validate($validation_rules);
?>
```

### Manual JavaScript Initialization

If you're not using FormWriter, initialize JoineryValidation directly:

```javascript
// In your HTML
<script src="/assets/js/joinery-validate.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    JoineryValidation.init('myFormId', {
        debug: false,  // Set true for console logging
        rules: {
            email: {
                required: true,
                email: true
            },
            password: {
                required: true,
                minlength: 8
            },
            confirm_password: {
                required: true,
                equalTo: '#password'  // Selector to match
            }
        },
        messages: {
            email: {
                required: 'Email is required',
                email: 'Please enter a valid email'
            },
            password: {
                required: 'Password is required',
                minlength: 'Password must be at least 8 characters'
            }
        },
        submitHandler: function(form) {
            // Optional custom submit logic
            console.log('Form is valid, submitting...');
            form.submit();
        }
    });
});
</script>
```

### Styling Classes (Bootstrap 5)

JoineryValidation automatically applies Bootstrap classes:

```html
<!-- Invalid field -->
<input type="email" id="email" class="form-control is-invalid">
<div class="invalid-feedback">
    Please provide a valid email address.
</div>

<!-- Valid field -->
<input type="text" id="username" class="form-control is-valid">
<div class="valid-feedback">
    Username looks good!
</div>
```

### Array Fields (Checkboxes, Multi-select)

For fields with array notation `[]`, use the base name without brackets:

```php
// HTML field name: products_list[]
$validation_rules['products_list']['required']['value'] = 'true';

// HTML field name: event_ids[]
$validation_rules['event_ids']['minlength']['value'] = '1';
$validation_rules['event_ids']['minlength']['message'] = '"Select at least one event"';
```

### AJAX Validation (Remote Check)

For server-side validation like checking username uniqueness:

```php
// In your form
$validation_rules['username']['remote']['value'] = '"/ajax/check_username"';
$validation_rules['username']['remote']['message'] = '"Username is already taken"';

// With custom parameter name
$validation_rules['email']['remote']['value'] = '{ url: "/ajax/check_email", dataFieldName: "user_email" }';
```

The AJAX endpoint receives the field value as `value` parameter (or custom name).

### Debug Mode

Enable console logging during development:

```php
echo $formwriter->set_validate($validation_rules, NULL, true);  // true = debug mode
```

This logs:
- Form initialization
- Validation rules
- Field validation attempts
- Form submission status
- Validation results

---

## 2. Server-Side Model Validation

Model validation happens at **three points**:

1. **Field Specifications** - Declarative validation rules
2. **Model->prepare()** - Validation before save
3. **Model->save()** - Final safety checks

### Field Specifications Validation

Define validation rules in `field_specifications`:

```php
<?php
// In /data/user_class.php

class User extends SystemBase {
    public static $field_specifications = array(
        'usr_email' => array(
            'type' => 'varchar(255)',
            'required' => true,           // Must be non-empty string
            'unique' => true,             // Must be unique in table
            'validation' => array(
                'email' => true,           // Must be valid email
                'minlength' => 5,
                'maxlength' => 255,
                'messages' => array(
                    'email' => 'Email must be a valid email address',
                    'minlength' => 'Email must be at least 5 characters'
                )
            )
        ),
        'usr_first_name' => array(
            'type' => 'varchar(255)',
            'required' => true,
            'validation' => array(
                'minlength' => 2,
                'maxlength' => 255,
                'pattern' => '/^[a-zA-Z\s\'-]+$/',  // Letters, spaces, hyphens, apostrophes
                'messages' => array(
                    'minlength' => 'Name must be at least 2 characters',
                    'pattern' => 'Name contains invalid characters'
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
                'pattern' => '/^[a-zA-Z0-9_\.]+$/',  // Alphanumeric, underscore, period only
            )
        ),
        'usr_password' => array(
            'type' => 'varchar(255)',
            'required' => true,
            'validation' => array(
                'minlength' => 8
            )
        ),
        'usr_status' => array(
            'type' => 'integer',
            'required' => true,
            'default' => 1,
            'validation' => array(
                'numeric' => true
            )
        ),
    );
}
?>
```

### Supported Validation Rules

#### Basic Rules
```php
'required' => true,              // Must be non-null and non-empty string
'unique' => true,                // Single field must be unique
'unique_with' => array('field2'), // Composite unique constraint

// Numeric rules
'numeric' => true,               // Must be numeric
'min' => 0,                      // Minimum numeric value
'max' => 100,                    // Maximum numeric value

// String rules
'minlength' => 3,                // Minimum character count
'maxlength' => 255,              // Maximum character count

// Format validation
'email' => true,                 // Must be valid email (auto-detected)
'url' => true,                   // Must be valid URL
'pattern' => '/regex/',          // Regex pattern match
```

### Model->prepare() Method

Call `prepare()` to validate before saving:

```php
<?php
// In a logic file or view
$user = new User(NULL);  // Create new user
$user->set('usr_email', $_POST['email']);
$user->set('usr_username', $_POST['username']);
$user->set('usr_password', $_POST['password']);

try {
    // This triggers all validation from field_specifications
    $user->prepare();

    // If prepare() succeeds, save to database
    $user->save();

    echo "User created successfully!";
} catch (DisplayableUserException $e) {
    // User-friendly error message (from validation rules)
    echo "Error: " . htmlspecialchars($e->getMessage());
} catch (SystemBaseException $e) {
    // System error (log it, don't show to user)
    error_log($e->getMessage());
    echo "An error occurred while processing your request.";
}
?>
```

### Multiple Unique Constraints

For multi-field unique constraints:

```php
'usr_email' => array(
    'type' => 'varchar(255)',
    'unique' => true,  // Single field unique
),
'usr_code' => array(
    'type' => 'varchar(10)',
    'unique_with' => array('org_id'),  // Unique combination with org_id
),
```

This ensures (usr_code, org_id) combination is unique.

### Validation in Model Methods

Override `prepare()` for custom validation:

```php
class Product extends SystemBase {
    // ... field specifications ...

    function prepare() {
        // Call parent validation first
        parent::prepare();

        // Custom validation logic
        if ($this->get('pro_price') < 0) {
            throw new DisplayableUserException('Price cannot be negative');
        }

        if ($this->get('pro_quantity') < 0) {
            throw new DisplayableUserException('Quantity cannot be negative');
        }

        // Check business logic (example)
        if ($this->get('pro_quantity') > 1000 && $this->get('pro_price') < 1) {
            throw new DisplayableUserException(
                'High quantity items must have minimum price of $1'
            );
        }
    }
}
```

---

## 3. FormWriter Integration

FormWriter provides a convenient interface for generating validation rules alongside form HTML, with automatic validation detection from model `field_specifications`.

### Quick Example

```php
$formwriter = new FormWriterV2Bootstrap('contact_form');

// Define validation rules
$validation_rules = array();
$validation_rules['email']['required']['value'] = 'true';
$validation_rules['email']['email']['value'] = 'true';
$validation_rules['password']['minlength']['value'] = '8';

// Output validation script (generates JavaScript automatically)
echo $formwriter->set_validate($validation_rules);

// Build the form with validated fields
$formwriter->begin_form();
$formwriter->textinput('email', 'Email', ['required' => true, 'validation' => 'email']);
$formwriter->passwordinput('password', 'Password', ['validation' => ['minlength' => 8]]);
$formwriter->end_form();
```

FormWriter includes model-aware validation - it can automatically extract validation rules from model `field_specifications` for seamless integration.

**For complete FormWriter validation documentation**, including:
- Usage patterns and examples
- Model-aware validation
- Common validation patterns
- Custom error messages
- Integration examples

See **[formwriter.md - Section 5: Validation Integration](formwriter.md#5-validation-integration)**

---

## 4. Complete Validation Example

Here's a complete end-to-end validation example with all layers:

### Step 1: Define Model with Validation Rules

```php
<?php
// /data/product_class.php

class Product extends SystemBase {
    public static $tablename = 'pro_products';
    public static $pkey_column = 'pro_id';

    public static $field_specifications = array(
        'pro_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),

        'pro_name' => array(
            'type' => 'varchar(255)',
            'is_nullable' => false,
            'required' => true,
            'unique' => true,
            'validation' => array(
                'minlength' => 3,
                'maxlength' => 255,
                'messages' => array(
                    'minlength' => 'Product name must be at least 3 characters'
                )
            )
        ),

        'pro_description' => array(
            'type' => 'text',
            'is_nullable' => true,
            'validation' => array(
                'maxlength' => 5000
            )
        ),

        'pro_price' => array(
            'type' => 'numeric(10,2)',
            'is_nullable' => false,
            'required' => true,
            'validation' => array(
                'numeric' => true,
                'min' => 0,
                'messages' => array(
                    'numeric' => 'Price must be a number',
                    'min' => 'Price cannot be negative'
                )
            )
        ),

        'pro_sku' => array(
            'type' => 'varchar(50)',
            'is_nullable' => false,
            'required' => true,
            'unique' => true,
            'validation' => array(
                'pattern' => '/^[A-Z0-9\-]+$/',
                'maxlength' => 50
            )
        ),
    );

    // Custom validation
    function prepare() {
        parent::prepare();

        // Business logic validation
        if ($this->get('pro_price') < 0.01) {
            throw new DisplayableUserException('Price must be at least $0.01');
        }
    }
}
?>
```

### Step 2: Create Admin Form with FormWriter

```php
<?php
// /adm/admin_product_edit.php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('data/product_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(5);
$session->set_return();

// Handle form submission
$product = NULL;
$product_id = $_GET['pro_id'] ?? NULL;
$product = new Product($product_id ?? NULL, !empty($product_id));

if ($_POST) {
    try {
        $product->set('pro_name', $_POST['pro_name']);
        $product->set('pro_description', $_POST['pro_description']);
        $product->set('pro_price', $_POST['pro_price']);
        $product->set('pro_sku', $_POST['pro_sku']);

        // Server-side validation
        $product->prepare();
        $product->save();

        header('Location: /adm/admin_products?msg=saved');
        exit;
    } catch (DisplayableUserException $e) {
        $error_message = $e->getMessage();
    }
}

// Display form
$page = new AdminPage();
$page->admin_header(array(
    'menu-id' => 'products',
    'page_title' => 'Products',
    'readable_title' => empty($product_id) ? 'Add Product' : 'Edit Product',
    'session' => $session,
));

if (isset($error_message)) {
    echo '<div class="alert alert-danger">' . htmlspecialchars($error_message) . '</div>';
}

$formwriter = $page->getFormWriter('product_form');

// Define validation rules
$validation_rules = array();
$validation_rules['pro_name']['required']['value'] = 'true';
$validation_rules['pro_name']['minlength']['value'] = '3';

$validation_rules['pro_price']['required']['value'] = 'true';
$validation_rules['pro_price']['number']['value'] = 'true';

$validation_rules['pro_sku']['required']['value'] = 'true';
$validation_rules['pro_sku']['pattern']['value'] = '"/^[A-Z0-9\-]+$/"';

// Output validation script
echo $formwriter->set_validate($validation_rules);

// Output form
echo $formwriter->begin_form('product_form', 'POST', $_SERVER['PHP_SELF'] . '?pro_id=' . $product->key);

echo $formwriter->textinput('Product Name', 'pro_name', 'form-control', 100,
    $product->get('pro_name'), 'Enter product name', 255);

echo $formwriter->textbox('Description', 'pro_description', 'form-control', 5, 40,
    $product->get('pro_description'), 'Enter product description');

echo $formwriter->textinput('Price', 'pro_price', 'form-control', 10,
    $product->get('pro_price'), 'e.g., 19.99', 10);

echo $formwriter->textinput('SKU', 'pro_sku', 'form-control', 20,
    $product->get('pro_sku'), 'e.g., PROD-001', 50);

echo $formwriter->start_buttons();
echo $formwriter->new_form_button('Save Product', 'btn btn-primary');
echo $formwriter->end_buttons();

echo $formwriter->end_form();

$page->admin_footer();
?>
```

### Step 3: Form Submission Flow

**User enters data → JavaScript validation → Submit form → Server validation → Database save**

1. **JavaScript Validation** (instant feedback)
   - User types in "Pro" (too short)
   - JavaScript detects minlength violation
   - Red border appears, error message displays
   - Submit button stays enabled (user can fix)

2. **User fixes and submits**
   - User types "Product Name" (valid)
   - JavaScript removes error
   - User clicks Submit
   - Form data sent to server

3. **Server Validation** (final check)
   - `Model->prepare()` called
   - Validates all field_specifications rules
   - Checks unique constraints
   - Custom business logic validation

4. **Save or Reject**
   - If valid: `Model->save()` → Database
   - If invalid: Throws DisplayableUserException
   - Error message shown to user
   - Form preserved with user's data

---

## 5. Validation Rule Reference

### Required Fields

```php
// In field_specifications
'field_name' => array(
    'type' => 'varchar(255)',
    'required' => true,  // Must not be NULL or empty string
)

// JavaScript validation
$rules['field_name']['required']['value'] = 'true';
```

### Unique Fields

```php
// Single field unique
'username' => array(
    'type' => 'varchar(64)',
    'unique' => true,  // Must be unique across table
)

// Multi-field unique (composite key)
'sku' => array(
    'type' => 'varchar(50)',
    'unique_with' => array('store_id'),  // (sku, store_id) must be unique
)
```

### String Length

```php
'field_name' => array(
    'type' => 'varchar(255)',
    'validation' => array(
        'minlength' => 3,    // At least 3 characters
        'maxlength' => 255   // No more than 255 characters
    )
)
```

### Format Validation

```php
'email' => array(
    'type' => 'varchar(255)',
    'validation' => array(
        'email' => true,  // Must be valid email format
    )
)

'website' => array(
    'type' => 'varchar(255)',
    'validation' => array(
        'url' => true,  // Must be valid URL
    )
)

'age' => array(
    'type' => 'integer',
    'validation' => array(
        'numeric' => true,  // Must be numeric
    )
)
```

### Pattern Matching

```php
'username' => array(
    'type' => 'varchar(64)',
    'validation' => array(
        'pattern' => '/^[a-zA-Z0-9_]{3,64}$/',  // Alphanumeric + underscore only
    )
)

'phone' => array(
    'type' => 'varchar(20)',
    'validation' => array(
        'pattern' => '/^\d{3}-\d{3}-\d{4}$/',  // XXX-XXX-XXXX format
    )
)
```

### Numeric Range

```php
'age' => array(
    'type' => 'integer',
    'validation' => array(
        'min' => 0,
        'max' => 150
    )
)

'quantity' => array(
    'type' => 'integer',
    'validation' => array(
        'min' => 1,  // Must be at least 1
    )
)
```

### Custom Error Messages

```php
'field_name' => array(
    'type' => 'varchar(255)',
    'validation' => array(
        'required' => true,
        'minlength' => 3,
        'messages' => array(
            'required' => 'This field cannot be empty',
            'minlength' => 'Must be at least 3 characters long'
        )
    )
)
```

---

## 6. Common Validation Patterns

### Email Signup Form

**FormWriter V2 (preferred):**

```php
$formwriter->textinput('email', 'Email', ['validation' => 'email', 'required' => true]);
$formwriter->passwordinput('password', 'Password', ['required' => true, 'validation' => ['minlength' => 8]]);
$formwriter->passwordinput('password_confirm', 'Confirm Password', [
    'required' => true,
    'validation' => ['matches' => 'password']  // Field name, not selector
]);
```

**Legacy V1 `set_validate()` (still works):**

```php
$rules['email']['required']['value'] = 'true';
$rules['email']['email']['value'] = 'true';
$rules['password']['required']['value'] = 'true';
$rules['password']['minlength']['value'] = '8';
$rules['password_confirm']['required']['value'] = 'true';
$rules['password_confirm']['equalTo']['value'] = '"#password"';  // V1 uses CSS selector
```

### Product Creation Form

```php
$rules['product_name']['required']['value'] = 'true';
$rules['product_name']['minlength']['value'] = '3';
$rules['product_name']['maxlength']['value'] = '255';

$rules['price']['required']['value'] = 'true';
$rules['price']['number']['value'] = 'true';
$rules['price']['min']['value'] = '0.01';

$rules['sku']['required']['value'] = 'true';
$rules['sku']['pattern']['value'] = '"/^[A-Z0-9\-]+$/"';
```

### Contact Form with Optional Fields

```php
// Name is required
$rules['name']['required']['value'] = 'true';

// Email is required and must be valid
$rules['email']['required']['value'] = 'true';
$rules['email']['email']['value'] = 'true';

// Message is required with minimum length
$rules['message']['required']['value'] = 'true';
$rules['message']['minlength']['value'] = '10';

// Phone is optional but if provided must be valid
$rules['phone']['pattern']['value'] = '"/^[\\d\-\(\)\s]+$/"';  // Digits, dash, parens, spaces
```

---

## 7. Troubleshooting

### Validation not triggering

**Problem:** Form submits without validation
- **Check:** Is `set_validate()` being called?
- **Check:** Is form ID correct in JoineryValidation.init()?
- **Check:** Is joinery-validate.js loaded?
- **Check:** Are rules defined correctly (required vs optional)?

### Server-side validation not catching errors

**Problem:** Invalid data saved to database
- **Check:** Is `prepare()` called before `save()`?
- **Check:** Are field_specifications validation rules defined?
- **Check:** Is exception being caught and handled?

### JavaScript validation rules not matching server rules

**Problem:** Form passes JavaScript but fails server validation
- **Solution:** Mirror JavaScript rules in field_specifications
- **Solution:** Use FormWriter v2 to auto-generate from model

### "equalTo" not working

**Problem:** Password confirm field doesn't validate against password
- **Check:** In FormWriter V2, use the `matches` key with a **field name** (not a CSS selector): `'matches' => 'password'`
- **Check:** The target field must have a matching `name` attribute in the form
- **Check:** In legacy V1 `set_validate()`, the value was a quoted CSS selector: `'"#password"'`

### Pattern validation failing

**Problem:** Pattern regex not matching valid input
- **Check:** Regex must be valid JavaScript (not PHP) syntax
- **Check:** Include delimiters: `"/^[a-z]+$/"` not `"^[a-z]+$"`
- **Check:** Escape special characters: `"\\d"` not `"\d"`

### Custom messages not showing

**Problem:** Default error messages showing instead of custom ones
- **Check:** Message is properly quoted: `'"Custom message"'` (double then single quotes)
- **Check:** Message defined in correct rule
- **Check:** Check console for JavaScript errors

---

## 8. Performance Considerations

### Minimize Validation Rules
- Only validate what needs validation
- Don't validate computed fields
- Avoid complex regex patterns

### Unique Constraints
- Unique checks query the database
- Use remote AJAX for real-time checks
- Don't validate uniqueness on every change

### Server-side Validation
- Validate once in `prepare()`
- Don't repeat validation in custom methods
- Cache validation results when possible

---

## 9. Security Notes

⚠️ **IMPORTANT:** Never trust client-side validation alone!

- JavaScript validation can be bypassed
- Always validate on server (in `prepare()`)
- Always use prepared statements (model classes do this)
- Sanitize output in views with `htmlspecialchars()`
- Never display database errors to users

```php
// ✅ CORRECT - Validate on server
try {
    $user->prepare();  // Server validation
    $user->save();
} catch (DisplayableUserException $e) {
    echo htmlspecialchars($e->getMessage());  // Safe error message
}

// ❌ WRONG - Only JavaScript validation
// User can disable JavaScript and bypass validation
```

---

## Related Documentation

- [Data Model Classes](/docs/admin_page_reference.md) - Model validation details
- [Admin Pages Guide](/docs/admin_pages.md) - Form patterns
- [Logic Architecture](/docs/logic_architecture.md) - Business logic validation
