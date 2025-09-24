# LogicResult with Validation Specification

## Overview
Establish `LogicResult` as the new standard pattern for logic files, including built-in validation support. Start with `product_logic.php` as the first implementation, maintaining full backward compatibility.

## The LogicResult Class

Create `/includes/LogicResult.php`:

```php
<?php
/**
 * Standard result object for logic functions
 * Handles return data, redirects, errors, and validation
 */
class LogicResult {
    public $redirect = null;       // URL to redirect to (if any)
    public $data = [];             // Data to pass to view
    public $error = null;          // General error message
    public $validation_errors = []; // Field-specific validation errors

    /**
     * Check if validation errors exist
     */
    public function hasValidationErrors() {
        return !empty($this->validation_errors);
    }

    /**
     * Add a validation error for a specific field
     */
    public function addValidationError($field, $message) {
        $this->validation_errors[$field] = $message;
        if (!$this->error) {
            $this->error = 'Please correct the errors below';
        }
    }

    /**
     * Add multiple validation errors at once
     */
    public function addValidationErrors($errors) {
        foreach ($errors as $field => $message) {
            $this->addValidationError($field, $message);
        }
    }

    /**
     * Check if a specific field has an error
     */
    public function hasFieldError($field) {
        return isset($this->validation_errors[$field]);
    }

    // Convenience factory methods
    public static function redirect($url, $data = []) {
        $result = new self();
        $result->redirect = $url;
        $result->data = $data;
        return $result;
    }

    public static function render($data = []) {
        $result = new self();
        $result->data = $data;
        return $result;
    }

    public static function error($message, $data = []) {
        $result = new self();
        $result->error = $message;
        $result->data = $data;
        return $result;
    }

    public static function validationError($field, $message, $data = []) {
        $result = new self();
        $result->addValidationError($field, $message);
        $result->data = $data;
        return $result;
    }
}
```

## Router Updates for Backward Compatibility

In `serve.php` or `RouteHelper::handleDynamicRoute()`:

```php
// Call the logic function
$result = some_logic($_GET, $_POST, $page);

// Handle both new LogicResult pattern and old array pattern
if ($result instanceof LogicResult) {
    // New pattern - structured result
    if ($result->redirect) {
        LibraryFunctions::redirect($result->redirect);
        exit();
    }

    if ($result->error) {
        $session->add_message($result->error, 'error');
    }

    // Pass both data and validation errors to view
    $page_vars = $result->data;
    $page_vars['validation_errors'] = $result->validation_errors;
    $page_vars['general_error'] = $result->error;

} elseif (is_array($result)) {
    // Old pattern - backward compatibility
    $page_vars = $result;

} else {
    // No return value (some old logic files)
    $page_vars = [];
}

// Continue with view rendering...
```

## Logic File Pattern with Validation

### Example: Product Logic with Validation

```php
function product_logic($get, $post, $page) {
    $result = new LogicResult();

    // Handle add to cart
    if (isset($post['cart'])) {
        // Validate required fields
        if (empty($post['product_id'])) {
            $result->addValidationError('product_id', 'Please select a product');
        }

        if (empty($post['product_version'])) {
            $result->addValidationError('product_version', 'Please select a product version');
        }

        // Validate customer info if required
        if (empty($post['email'])) {
            $result->addValidationError('email', 'Email address is required');
        } elseif (!filter_var($post['email'], FILTER_VALIDATE_EMAIL)) {
            $result->addValidationError('email', 'Please enter a valid email address');
        }

        if (empty($post['full_name_first'])) {
            $result->addValidationError('full_name_first', 'First name is required');
        }

        // Return with errors if validation failed
        if ($result->hasValidationErrors()) {
            $result->data = $post;  // Return submitted data for form re-population
            $result->data['product'] = new Product($post['product_id'], TRUE);
            return $result;
        }

        // Validation passed - process the request
        try {
            $cart = $session->get_shopping_cart();
            $cart->add_product($post['product_id'], $post['product_version'], 1, $post);

            return LogicResult::redirect('/cart');

        } catch (ShoppingCartException $e) {
            return LogicResult::error($e->getMessage(), $post);
        }
    }

    // Display product page
    $result->data['product'] = $product;
    $result->data['cart'] = $session->get_shopping_cart();
    return $result;
}
```

### Example: Registration Logic with Validation

```php
function register_logic($get, $post, $page) {
    $result = new LogicResult();

    if (!empty($post)) {
        // Validate registration form
        if (empty($post['username'])) {
            $result->addValidationError('username', 'Username is required');
        } elseif (strlen($post['username']) < 3) {
            $result->addValidationError('username', 'Username must be at least 3 characters');
        } elseif (User::CheckForDuplicate(['usr_username' => $post['username']])) {
            $result->addValidationError('username', 'This username is already taken');
        }

        if (empty($post['email'])) {
            $result->addValidationError('email', 'Email is required');
        } elseif (!filter_var($post['email'], FILTER_VALIDATE_EMAIL)) {
            $result->addValidationError('email', 'Please enter a valid email address');
        } elseif (User::CheckForDuplicate(['usr_email' => $post['email']])) {
            $result->addValidationError('email', 'An account with this email already exists');
        }

        if (empty($post['password'])) {
            $result->addValidationError('password', 'Password is required');
        } elseif (strlen($post['password']) < 8) {
            $result->addValidationError('password', 'Password must be at least 8 characters');
        }

        if ($post['password'] !== $post['password_confirm']) {
            $result->addValidationError('password_confirm', 'Passwords do not match');
        }

        // Return with errors if validation failed
        if ($result->hasValidationErrors()) {
            $result->data = $post;
            unset($result->data['password'], $result->data['password_confirm']); // Don't return passwords
            return $result;
        }

        // Create user account
        try {
            $user = new User(NULL);
            $user->set('usr_username', $post['username']);
            $user->set('usr_email', $post['email']);
            $user->set('usr_password', password_hash($post['password'], PASSWORD_DEFAULT));
            $user->save();

            // Log them in
            $_SESSION['usr_user_id'] = $user->key;
            $_SESSION['loggedin'] = 1;

            return LogicResult::redirect('/welcome');

        } catch (Exception $e) {
            return LogicResult::error('Registration failed: ' . $e->getMessage(), $post);
        }
    }

    // Display registration form
    return $result;
}
```

## View Integration

### Displaying Validation Errors

In view files, handle validation errors:

```php
<!-- Display general error if present -->
<?php if (!empty($general_error)): ?>
    <div class="alert alert-danger">
        <?= htmlspecialchars($general_error) ?>
    </div>
<?php endif; ?>

<!-- Display field-specific errors -->
<?php if (!empty($validation_errors)): ?>
    <div class="alert alert-warning">
        <ul>
        <?php foreach ($validation_errors as $field => $error): ?>
            <li><?= htmlspecialchars($error) ?></li>
        <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Form with error highlighting and value preservation -->
<form method="post">
    <div class="form-group <?= isset($validation_errors['email']) ? 'has-error' : '' ?>">
        <label>Email Address</label>
        <input type="email" name="email"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
               class="form-control">
        <?php if (isset($validation_errors['email'])): ?>
            <span class="help-block"><?= htmlspecialchars($validation_errors['email']) ?></span>
        <?php endif; ?>
    </div>

    <div class="form-group <?= isset($validation_errors['full_name_first']) ? 'has-error' : '' ?>">
        <label>First Name</label>
        <input type="text" name="full_name_first"
               value="<?= htmlspecialchars($_POST['full_name_first'] ?? '') ?>"
               class="form-control">
        <?php if (isset($validation_errors['full_name_first'])): ?>
            <span class="help-block"><?= htmlspecialchars($validation_errors['full_name_first']) ?></span>
        <?php endif; ?>
    </div>

    <button type="submit">Submit</button>
</form>
```

## Testing with Validation

Tests can now verify validation behavior:

```php
// Test missing required field
function testProductAddMissingEmail() {
    $post = [
        'product_id' => 123,
        'product_version' => 456,
        'cart' => '1',
        'full_name_first' => 'John'
        // Missing email
    ];

    $result = product_logic([], $post, null);

    $this->assertInstanceOf(LogicResult::class, $result);
    $this->assertTrue($result->hasValidationErrors());
    $this->assertTrue($result->hasFieldError('email'));
    $this->assertEquals('Email address is required', $result->validation_errors['email']);
    $this->assertNull($result->redirect);  // Should not redirect on validation error
}

// Test invalid email format
function testProductAddInvalidEmail() {
    $post = [
        'product_id' => 123,
        'product_version' => 456,
        'cart' => '1',
        'email' => 'not-an-email',
        'full_name_first' => 'John'
    ];

    $result = product_logic([], $post, null);

    $this->assertTrue($result->hasFieldError('email'));
    $this->assertEquals('Please enter a valid email address', $result->validation_errors['email']);
    $this->assertEquals('not-an-email', $result->data['email']); // Form data preserved
}

// Test successful validation
function testProductAddSuccess() {
    $post = [
        'product_id' => 123,
        'product_version' => 456,
        'cart' => '1',
        'email' => 'valid@example.com',
        'full_name_first' => 'John',
        'full_name_last' => 'Doe'
    ];

    $result = product_logic([], $post, null);

    $this->assertFalse($result->hasValidationErrors());
    $this->assertEquals('/cart', $result->redirect);
}
```

## Benefits

1. **User-friendly errors** - Field-specific messages instead of generic errors
2. **Form preservation** - Submitted data returned for re-display
3. **Testable validation** - Can test validation logic separately
4. **Consistent pattern** - All forms validated the same way
5. **Clean separation** - Validation distinct from business logic
6. **Progressive enhancement** - Old logic files still work

## Implementation Priority

### Phase 1: Foundation
1. Create LogicResult class with validation support
2. Update router to handle LogicResult with validation
3. Convert `product_logic.php` as proof of concept

### Phase 2: Critical Forms (As Needed)
- `register_logic.php` - Registration validation
- `login_logic.php` - Login validation
- `checkout_logic.php` - Payment form validation
- Convert others only when they need updating

### Phase 3: Documentation
- Add examples to developer guide
- Document validation patterns
- Create helper snippets for common validations

## Common Validation Patterns

```php
// Required field
if (empty($post['field'])) {
    $result->addValidationError('field', 'Field is required');
}

// Email validation
if (!filter_var($post['email'], FILTER_VALIDATE_EMAIL)) {
    $result->addValidationError('email', 'Invalid email address');
}

// Length validation
if (strlen($post['field']) < 3) {
    $result->addValidationError('field', 'Must be at least 3 characters');
}

// Numeric validation
if (!is_numeric($post['amount']) || $post['amount'] <= 0) {
    $result->addValidationError('amount', 'Must be a positive number');
}

// Date validation
if (!strtotime($post['date'])) {
    $result->addValidationError('date', 'Invalid date format');
}

// Regex validation
if (!preg_match('/^[a-zA-Z0-9_]+$/', $post['username'])) {
    $result->addValidationError('username', 'Only letters, numbers, and underscores allowed');
}

// Database uniqueness check
if (User::CheckForDuplicate(['usr_email' => $post['email']])) {
    $result->addValidationError('email', 'This email is already registered');
}
```

## Summary

This specification adds simple, practical validation to the LogicResult pattern without over-engineering. It provides:
- Clear validation error handling
- Field-specific error messages
- Form data preservation
- Testable validation logic
- Full backward compatibility

The approach is pragmatic: implement it where needed, starting with `product_logic.php`, and expand only as necessary.