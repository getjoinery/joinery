# Spec: Complete LogicResult Migration

## Problem

Logic files are supposed to return `LogicResult` objects so they can be called directly (e.g., from tests) without side effects. Phase 1 established `LogicResult` with `redirect()`, `render()`, and `error()` factory methods, and ~122 logic files now use them for their return values.

However, many logic files still have code paths that `throw` exceptions or call `exit()` directly. This means calling a logic function can still blow up the caller — you can't safely invoke them from tests, compose them, or handle errors gracefully.

### Current state of the problem

**Direct `exit()` calls in logic files (~50+ occurrences):**
Many logic files call `exit()` after manual redirects instead of returning `LogicResult::redirect()`. Examples: `post_logic.php`, `blog_logic.php`, `video_logic.php`, `pricing_logic.php`, `booking_logic.php`, `page_logic.php`, `location_logic.php`, `cart_clear_logic.php`, `event_sessions_logic.php`, `password-reset` files, etc.

**Direct `throw` in logic files (~80+ occurrences):**
- `change_tier_logic.php` — ~30 thrown exceptions for various subscription error cases
- `cart_charge_logic.php` — ~10 thrown exceptions for payment failures
- `register_logic.php` — ~5 thrown exceptions for registration validation
- `login_logic.php` — thrown `BusinessLogicException`, `ValidationException`, `AuthenticationException`
- `cart_logic.php`, `survey_logic.php`, `password_edit_logic.php`, `list_logic.php`, etc.
- Various admin logic files

## What Already Works (Phase 1 — complete)

- `LogicResult` class exists at `/includes/LogicResult.php` with `redirect()`, `render()`, `error()` factory methods
- `process_logic()` in `LibraryFunctions.php` handles `LogicResult` objects and backward-compatible arrays
- ~122 logic files use `LogicResult::` for their happy-path returns

## What Needs to Be Done

### 1. Add validation support to LogicResult

Add `$validation_errors` array and helper methods to the existing `LogicResult` class:

```php
public $validation_errors = [];

public function hasValidationErrors() {
    return !empty($this->validation_errors);
}

public function addValidationError($field, $message) {
    $this->validation_errors[$field] = $message;
    if (!$this->error) {
        $this->error = 'Please correct the errors below';
    }
}
```

### 2. Update `process_logic()` to handle errors and validation

Currently `process_logic()` throws an exception when `$result->error` is set. It should instead pass errors to the view:

```php
function process_logic($result) {
    if (!($result instanceof LogicResult)) {
        return $result;
    }

    if ($result->redirect) {
        LibraryFunctions::redirect($result->redirect);
        exit();
    }

    // Pass errors to view instead of throwing
    if ($result->error) {
        $session = SessionControl::get_instance();
        $session->add_message($result->error, 'error');
    }

    $page_vars = $result->data;
    $page_vars['validation_errors'] = $result->validation_errors;
    return $page_vars;
}
```

### 3. Convert logic files (incrementally, as touched)

When editing a logic file for any reason, convert its `throw`/`exit()` patterns:

**Before:**
```php
throw new SystemDisplayableError('Email is required');
```

**After:**
```php
return LogicResult::error('Email is required');
// or for field-specific:
$result->addValidationError('email', 'Email is required');
return $result;
```

**Before:**
```php
LibraryFunctions::redirect('/some-page');
exit();
```

**After:**
```php
return LogicResult::redirect('/some-page');
```

## Conversion Priority

Convert these first — they're high-traffic and have the most throw/exit calls:

1. `change_tier_logic.php` — ~30 throws, subscription management
2. `cart_charge_logic.php` — ~10 throws + exits, payment processing
3. `register_logic.php` — ~5 throws, user registration
4. `login_logic.php` — 3 exception types + exits
5. `change-password-required_logic.php` — mixed throws + exits
6. `password-reset-2_logic.php` — throws + exits
7. `cart_logic.php` — throws for terms/password validation
8. `product_logic.php` — throws on cart errors

Lower priority (convert when touched):
- `post_logic.php`, `blog_logic.php`, `video_logic.php`, `pricing_logic.php` — mostly just `exit()` after redirects
- Admin logic files — less critical since they're not user-facing forms
- `list_logic.php`, `lists_logic.php`, `event_waiting_list_logic.php` — throws on join errors

## Rules for Conversion

1. Every code path must return a `LogicResult` — no `throw`, no `exit()`
2. Use `LogicResult::error()` for general errors that were previously thrown exceptions
3. Use `addValidationError()` for field-specific form validation
4. Use `LogicResult::redirect()` instead of `LibraryFunctions::redirect()` + `exit()`
5. Catch exceptions from service classes (Stripe, etc.) and wrap them in `LogicResult::error()`
6. Preserve form data in `$result->data` when returning validation errors so forms can re-populate

## Out of Scope

- Converting non-logic PHP files (controllers, data classes, etc.) — they can continue to throw
- Changing how `serve.php` / `RouteHelper` invokes logic files — `process_logic()` handles the bridge
- Client-side validation — this spec is server-side only
