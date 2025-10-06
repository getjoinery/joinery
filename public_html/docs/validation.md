# Form Validation

The system uses Joinery Validation, a pure JavaScript validation library with no jQuery dependencies.

## Basic Usage

Validation is handled automatically through FormWriter. Define rules in PHP, and the validation JavaScript is generated for you:

```php
$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');

$validation_rules = array();
$validation_rules['email']['required']['value'] = 'true';
$validation_rules['email']['email']['value'] = 'true';
$validation_rules['password']['minlength']['value'] = '8';

echo $formwriter->set_validate($validation_rules);
```

## Built-in Validators

- **required** - Field must have a value
- **email** - Must be valid email format
- **url** - Must be valid URL format
- **number** - Must be numeric
- **minlength** - Minimum character length
- **maxlength** - Maximum character length
- **min** - Minimum numeric value
- **max** - Maximum numeric value
- **equalTo** - Must match another field (value = selector, e.g., '#password')
- **time** - Must be valid time format (HH:MM)
- **date** - Must be valid date
- **remote** - AJAX validation (see below)

## Custom Error Messages

```php
$validation_rules['email']['required']['value'] = 'true';
$validation_rules['email']['required']['message'] = '"Please enter your email address"';
```

## Array Fields (Checkboxes, Multi-selects)

For fields with `[]` notation, use the base name without brackets or quotes:

```php
// Field name in HTML: products_list[]
$validation_rules['products_list']['required']['value'] = 'true';

// Field name in HTML: event_list[]
$validation_rules['event_list']['required']['value'] = 'true';
```

The validator automatically detects and validates array fields.

## AJAX Validation

```php
$validation_rules['username']['remote']['value'] = '"/ajax/check_username"';
$validation_rules['username']['remote']['message'] = '"Username already taken"';
```

AJAX endpoints receive the field value as `value` parameter by default. To customize the parameter name, add `dataFieldName`:

```php
$validation_rules['email']['remote']['value'] = '{ url: "/ajax/check_email", dataFieldName: "email_address" }';
```

## Debug Mode

Enable detailed console logging during development:

```php
echo $formwriter->set_validate($validation_rules, NULL, true); // true = debug mode
```

## Styling

Validation automatically applies Bootstrap 5 classes:
- `.is-invalid` - Applied to invalid fields
- `.is-valid` - Applied to valid fields
- `.invalid-feedback` - Error message container (must exist in HTML)

## Manual JavaScript Initialization

If not using FormWriter's `set_validate()`, you can initialize manually:

```javascript
JoineryValidation.init('formId', {
    rules: {
        email: { required: true, email: true },
        password: { required: true, minlength: 8 }
    },
    messages: {
        email: { required: "Email is required" }
    }
});
```
