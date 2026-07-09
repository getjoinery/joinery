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

### JavaScript Validation with FormWriterV2

In FormWriterV2, client-side validation is **automatic** — no manual configuration required. Define validation rules in `$field_specifications` and `end_form()` emits the validation script automatically:

```php
<?php
// In data/example_class.php — define validation in field_specifications
public static $field_specifications = array(
    'exa_email' => array(
        'type' => 'varchar(255)',
        'required' => true,
        'validation' => array(
            'email' => true,
            'messages' => array('email' => 'Please enter a valid email address')
        )
    ),
    'exa_password' => array(
        'type' => 'varchar(255)',
        'required' => true,
        'validation' => array(
            'minlength' => 8,
            'messages' => array('minlength' => 'Password must be at least 8 characters')
        )
    ),
);

// In your view — validation JS is output automatically by end_form()
$formwriter = $page->getFormWriter('contact_form');
$formwriter->begin_form(['action' => '/contact', 'method' => 'POST']);
$formwriter->textinput('exa_email', 'Email:', ['required' => true]);
$formwriter->passwordinput('exa_password', 'Password:', ['required' => true]);
$formwriter->submitbutton('btn_submit', 'Submit');
$formwriter->end_form(); // <-- emits validation JS automatically
?>
```

> **Note:** There is no `set_validate()` method. Validation rules live in `field_specifications` (for core model fields) or are declared inline in each input's `validation` option; `end_form()` emits the client-side script automatically. A `->set_validate(...)` call will fatal.

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

For fields with array notation `[]`, use the base name without brackets in `field_specifications`:

```php
// field_specifications entry for a multi-select
'product_ids' => array(
    'type' => 'text',
    'validation' => array('minlength' => 1, 'messages' => array('minlength' => 'Select at least one item'))
)
```

### AJAX Validation (Remote Check)

For server-side validation like checking username uniqueness, add a `remote` rule in `field_specifications`:

```php
'usr_username' => array(
    'type' => 'varchar(64)',
    'validation' => array(
        'remote' => '/ajax/check_username',
        'messages' => array('remote' => 'Username is already taken')
    )
)
```

The AJAX endpoint receives the field value as `value` parameter.

### Debug Mode

Enable console logging during development by passing `debug: true` to `JoineryValidation.init()` in the Manual JavaScript Initialization section below.

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
$formwriter = new FormWriterV2HTML5('contact_form');

// No separate "rules" array and no set_validate() call. Validation rules are
// read from the model's field_specifications (for core fields) and/or declared
// inline per field. end_form() emits the matching client-side JS automatically.
$formwriter->begin_form();
$formwriter->textinput('email', 'Email', ['required' => true, 'validation' => ['email' => true]]);
$formwriter->passwordinput('password', 'Password', ['validation' => ['minlength' => 8]]);
$formwriter->end_form(); // <-- emits the validation script automatically
```

FormWriter includes model-aware validation - it extracts validation rules from a model's
`field_specifications` by matching each field's prefix to a model. Core model fields resolve
automatically. For a **plugin** model (which the core scan does not see), pass the owning model
to the form — `getFormWriter('form', ['model' => $obj])` — and its `field_specifications` drive
detection for that model's fields. You can always declare `validation` rules inline on an input
to override or supplement what is detected. **Server-side validation in `prepare()`/`save()`
runs regardless** of any of this — client-side detection is a convenience, not the gate.

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

        header('Location: /adm/admin_products');
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

// Pass the model so the form auto-fills current values and (for core model
// fields) auto-detects validation rules from field_specifications. There is no
// separate rules array and no set_validate() call.
$formwriter = $page->getFormWriter('product_form', ['model' => $product]);

$formwriter->begin_form(['action' => '/adm/admin_product_edit?pro_id=' . $product->key, 'method' => 'POST']);

// V2 inputs take ($name, $label, $options). Values come from the model above;
// validation rules come from field_specifications (or inline, as needed).
$formwriter->textinput('pro_name', 'Product Name', ['required' => true, 'placeholder' => 'Enter product name']);
$formwriter->textbox('pro_description', 'Description', ['rows' => 5, 'placeholder' => 'Enter product description']);
$formwriter->textinput('pro_price', 'Price', ['required' => true, 'placeholder' => 'e.g., 19.99']);
$formwriter->textinput('pro_sku', 'SKU', ['required' => true, 'placeholder' => 'e.g., PROD-001']);

$formwriter->submitbutton('btn_save', 'Save Product');

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

The `email` rule does two things: a format check, then a fail-open DNS check
that the domain can receive mail (an MX record, or an A record as the RFC 5321
fallback). The DNS half is shared with `LibraryFunctions::IsValidEmail()` via
`DnsResolver::domainAcceptsMail()` — see [DNS Lookups](#dns-lookups-dnsresolver)
below. A transient DNS failure never rejects an address; only a domain that
definitively has neither MX nor A is failed.

#### MX Check Toggle (`email_validation_mx_check`)

The DNS step is controlled by the `email_validation_mx_check` site setting
(Email Settings tab in admin):

- **`"1"` (default)** — syntax + MX check. Current behavior; no change for
  existing deployments.
- **`"0"`** — syntax only. The DNS round-trip is skipped entirely. Use this
  for bulk imports, internal/test domains (e.g. `example.test`), split-horizon
  DNS environments, or any context where latency matters more than deliverability
  gating.

Both validation paths honor the toggle: `SystemBase::validateField()` (model
saves) and `LibraryFunctions::IsValidEmail()` (standalone callers). Any future
call site that calls `DnsResolver::domainAcceptsMail()` directly must add the
same inline check so the toggle is always honored:

```php
$settings = Globalvars::get_instance();
if ((string)$settings->get_setting('email_validation_mx_check') !== '0') {
    // DnsResolver::domainAcceptsMail($domain) call here
}
```

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

### Product Creation Form

```php
$formwriter->textinput('product_name', 'Product Name', [
    'required' => true,
    'validation' => ['minlength' => 3, 'maxlength' => 255]
]);
$formwriter->textinput('price', 'Price', [
    'required' => true,
    'validation' => ['numeric' => true, 'min' => 0.01]
]);
$formwriter->textinput('sku', 'SKU', [
    'required' => true,
    'validation' => ['pattern' => '/^[A-Z0-9\-]+$/']
]);
```

### Contact Form with Optional Fields

```php
// Name is required
$formwriter->textinput('name', 'Name', ['required' => true]);

// Email is required and must be valid
$formwriter->textinput('email', 'Email', ['required' => true, 'validation' => ['email' => true]]);

// Message is required with minimum length
$formwriter->textbox('message', 'Message', ['required' => true, 'validation' => ['minlength' => 10]]);

// Phone is optional but if provided must match the pattern
$formwriter->textinput('phone', 'Phone', ['validation' => ['pattern' => '/^[\d\-\(\)\s]+$/']]);
```

---

## 7. Troubleshooting

### Validation not triggering

**Problem:** Form submits without validation
- **Check:** Is `end_form()` being called? (it emits the validation script)
- **Check:** Is form ID correct in JoineryValidation.init()?
- **Check:** Is joinery-validate.js loaded?
- **Check:** Are rules defined correctly (required vs optional)?

### Delete / secondary button blocked by validation

**Problem:** On a form with required fields, a Delete or Cancel button won't submit (the
validator flags the empty required fields instead of running the action).
- **Cause:** The client validator runs on every submit button by default.
- **Solution:** Mark that button to bypass client validation:
  `$formwriter->submitbutton('btn_delete', 'Delete', ['formnovalidate' => true]);`
- **Note:** This skips only the *client* check — server-side validation still applies, so
  the server action behind the button must not assume a valid model. See the "Multi-Action
  Forms" section in [FormWriter](formwriter.md) for the full pattern (and how the clicked
  button's name is preserved through submission).

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
- **Check:** Use the `matches` key with a **field name** (not a CSS selector): `'matches' => 'password'`
- **Check:** The target field must have a matching `name` attribute in the form

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

## DNS Lookups (DnsResolver)

`includes/DnsResolver.php` is the single place the platform performs raw DNS
lookups. Any code that needs DNS — email-domain validation, SPF/DKIM/DMARC
checks, SSRF guards, provisioning checks — should go through it rather than
calling `dns_get_record()` / `gethostby*()` directly.

It is a static, policy-free class. Each method returns a normalized shape and
distinguishes "no such record" from "the lookup failed":

| Method | Returns |
|---|---|
| `getMx($domain)` | `[['host'=>…,'pri'=>…], …]`, lowest priority first |
| `getA($host)` / `getAaaa($host)` | array of IP strings |
| `getTxt($name)` | array of TXT strings |
| `getCname($name)` | the target string, or `null` |
| `resolveHostIps($host)` | every A **and** AAAA address, de-duplicated |
| `domainAcceptsMail($domain)` | `bool` — MX, or A as RFC 5321 fallback |

**Error vs. empty.** A genuine "no record" returns an empty array. A *resolver*
failure (timeout, SERVFAIL, network error) throws `DnsLookupException`. This is
deliberate: the class takes no fail-open / fail-closed stance — each caller
catches `DnsLookupException` and applies its own policy. Validation, email-auth
and provisioning checks catch it and fail **open**; SSRF guards catch it and
fail **closed**.

**Testability.** `DnsResolver::setBackend($double)` swaps the raw-DNS layer for
a test double (`clearBackend()` restores it in teardown). The seam sits at the
bottom of the stack, so one `setBackend()` call also makes every consumer —
including `DnsAuthChecker` — testable. See `tests/unit/dns_resolver_test.php` and [Testing](testing.md).

```php
// Production code just calls it — no setup needed:
if (!DnsResolver::domainAcceptsMail($domain)) { /* reject */ }

// Tests inject canned records:
DnsResolver::setBackend($fakeBackend);   // $fakeBackend->getRecords($name, $type)
// … exercise code that resolves DNS …
DnsResolver::clearBackend();
```

---

## Related Documentation

- [Admin Pages Guide](/docs/admin_pages.md) - Form patterns and validation integration
- [Logic Architecture](/docs/logic_architecture.md) - Business logic validation
- [Email System](/docs/email_system.md) - `DnsAuthChecker` SPF/DKIM/DMARC checks, built on `DnsResolver`
