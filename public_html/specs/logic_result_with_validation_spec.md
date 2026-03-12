# Spec: Complete LogicResult Migration

## Problem

Logic files are supposed to return `LogicResult` objects so they can be called directly (e.g., from tests) without side effects. Phase 1 established `LogicResult` with `redirect()`, `render()`, and `error()` factory methods, and ~122 logic files now use them for their return values.

However, many logic files still have code paths that `throw` exceptions or call `exit()` directly. This means calling a logic function can still blow up the caller — you can't safely invoke them from tests, compose them, or handle errors gracefully.

### Audit results

**82 `exit()` calls** across 41 logic files and **98 `throw` statements** across 31 logic files.

These break down into 6 distinct anti-patterns:

1. **Redirect+exit** — `LibraryFunctions::redirect('/path'); exit();` or `header('Location: /path'); exit();`
2. **Feature-disabled check** — `header("HTTP/1.0 404 Not Found"); echo 'This feature is turned off'; exit();`
3. **throw+exit (redundant)** — `throw new SomeException('msg'); exit();` (exit is dead code after throw)
4. **Simple throw** — `throw new SystemDisplayableError('msg');` on a single code path
5. **AJAX response+exit** — `echo json_encode([...]); exit();` for AJAX endpoints
6. **Page render+exit** — `PublicPage::OutputGenericPublicPage(...); exit();` mid-logic

## What Already Works (Phase 1 — complete)

- `LogicResult` class exists at `/includes/LogicResult.php` with `redirect()`, `render()`, `error()` factory methods
- `process_logic()` in `LibraryFunctions.php` handles `LogicResult` objects and backward-compatible arrays
- ~122 logic files use `LogicResult::` for their happy-path returns
- All view files use `process_logic()` to call logic functions

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

Currently `process_logic()` throws an exception when `$result->error` is set. The updated version distinguishes between two situations:

- **Error with no data** (feature disabled, not logged in, record not found) — the view can't render, so throw to the global error handler which shows a proper error page. This preserves current behavior exactly.
- **Error with data** (form validation failure, business rule violation) — the view has everything it needs to re-display the form, so add a session message and return to the view.

```php
function process_logic($result) {
    if (!($result instanceof LogicResult)) {
        return $result;
    }

    if ($result->redirect) {
        LibraryFunctions::redirect($result->redirect);
        exit();
    }

    if ($result->error) {
        if (empty($result->data) && empty($result->validation_errors)) {
            // No data to render — show error page (preserves current behavior)
            throw new SystemDisplayableError($result->error);
        }
        // Has data — add message, return to view for re-display
        $session = SessionControl::get_instance();
        $session->add_message($result->error, 'error');
    }

    $page_vars = $result->data;
    $page_vars['validation_errors'] = $result->validation_errors;
    return $page_vars;
}
```

This makes all mechanical conversions safe: feature-disabled checks and early-bail throws return `LogicResult::error('message')` with no data, so `process_logic()` throws to the error handler just like before. Form validation errors return `LogicResult::error('message', $form_data)` with data, so the view re-displays with the error banner.

### 3. Convert all logic files

#### Conversion patterns

**Before:**
```php
throw new SystemDisplayableError('Email is required');
```

**After:**
```php
return LogicResult::error('Email is required');
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

**Before:**
```php
header("HTTP/1.0 404 Not Found");
echo 'This feature is turned off';
exit();
```

**After:**
```php
return LogicResult::error('This feature is turned off');
```

---

## Mechanical Conversions (~103 occurrences)

These can be done with search-and-replace or simple scripted transforms.

### Redirect+exit (28 occurrences, 15 files)

`LibraryFunctions::redirect('...')` or `header('Location: ...')` followed by `exit()` → `return LogicResult::redirect('...')`

| File | Count | Lines |
|---|---|---|
| `logic/change-password-required_logic.php` | 2 | 39, 81 |
| `logic/change_tier_logic.php` | 1 | 23 |
| `logic/login_logic.php` | 4 | 91, 106, 118, 164 |
| `logic/post_logic.php` | 1 | 82 |
| `plugins/controld/logic/ctlddevice_delete_logic.php` | 1 | 41 |
| `plugins/controld/logic/ctlddevice_edit_logic.php` | 1 | 114 |
| `plugins/controld/logic/ctlddevice_soft_delete_logic.php` | 1 | 52 |
| `plugins/controld/logic/ctldfilters_edit_logic.php` | 1 | 92 |
| `plugins/controld/logic/ctldprofile_delete_logic.php` | 1 | 40 |
| `plugins/controld/logic/devices_logic.php` | 1 | 28 |
| `plugins/controld/logic/rules_logic.php` | 1 | 76 |

### Feature-disabled checks (22 occurrences, 19 files)

All follow identical pattern: `header("HTTP/1.0 404 Not Found"); echo 'This feature is turned off'; exit();`
→ `return LogicResult::error('This feature is turned off');`

Files: `blog_logic.php`, `booking_logic.php`, `cart_charge_logic.php`, `cart_clear_logic.php`, `event_sessions_course_logic.php`, `event_sessions_logic.php`, `event_waiting_list_logic.php`, `event_withdraw_logic.php`, `list_logic.php`, `lists_logic.php`, `location_logic.php`, `orders_recurring_action_logic.php`, `page_logic.php`, `password-reset-1_logic.php` (×2), `password-reset-2_logic.php` (×2), `password-set_logic.php`, `post_logic.php`, `pricing_logic.php`, `product_logic.php`, `products_logic.php`, `register_logic.php`, `video_logic.php`

### throw+exit — redundant exit (13 occurrences, 7 files)

`throw new SomeException('...'); exit();` → `return LogicResult::error('...');` (exit was dead code)

| File | Count | Lines |
|---|---|---|
| `logic/cart_charge_logic.php` | 5 | 112, 144, 184, 207, 218 |
| `logic/password-set_logic.php` | 1 | 25 |
| `logic/event_sessions_logic.php` | 1 | 42 |
| `adm/logic/admin_question_edit_logic.php` | 1 | 32 |
| `adm/logic/admin_users_message_logic.php` | 3 | 28, 32, 52 |
| `plugins/controld/logic/ctlddevice_edit_logic.php` | 2 | 32, 76 (also 98) |
| `plugins/controld/logic/ctlddevice_soft_delete_logic.php` | 1 | 46 |

### Simple throw (no surrounding complexity) (~40 occurrences, 16 files)

Standalone `throw new SystemDisplayableError('msg')` → `return LogicResult::error('msg')`

| File | Count |
|---|---|
| `logic/password-reset-2_logic.php` | 4 |
| `logic/password-set_logic.php` | 3 |
| `logic/password_edit_logic.php` | 3 |
| `logic/cart_logic.php` | 2 |
| `logic/event_waiting_list_logic.php` | 2 |
| `logic/list_logic.php` | 2 |
| `logic/lists_logic.php` | 2 |
| `logic/product_logic.php` | 2 |
| `logic/survey_logic.php` | 1 |
| `adm/logic/admin_address_edit_logic.php` | 1 |
| `adm/logic/admin_file_upload_process_logic.php` | 1 |
| `adm/logic/admin_phone_edit_logic.php` | 1 |
| `adm/logic/admin_product_edit_logic.php` | 2 |
| `adm/logic/admin_product_logic.php` | 1 |
| `adm/logic/admin_users_edit_logic.php` | 2 |
| `plugins/controld/logic/ctldprofile_delete_logic.php` | 1 |

---

## Manual Conversions (~62 occurrences)

These require reading and understanding context before converting.

### change_tier_logic.php (35 throws)

The largest file. Uses generic `Exception` throughout deeply nested subscription upgrade/downgrade/cancel/reactivate logic. Each throw needs context review: some are validation errors (should use `addValidationError`), some are business logic failures, some are Stripe API errors that should be caught and wrapped. Cannot be done mechanically.

### login_logic.php (4 throws + AJAX dual-path)

Has dual-path AJAX/non-AJAX patterns:
```php
if ($ajax) { throw new ValidationException(...) }
else { throw new SystemDisplayableError(...) }
```
Also renders a full page mid-logic (`PublicPage::OutputGenericPublicPage(...); exit()`). Needs rearchitecting.

### change-password-required_logic.php (6 throws + AJAX responses)

AJAX `echo json_encode(); exit()` pattern throughout. Serves both AJAX and regular requests with different response formats. Needs rearchitecting to separate concerns or add AJAX-aware LogicResult support.

### register_logic.php (5 throws + AJAX exit)

Has `if ($ajax) { exit; }` at end, mixing AJAX response handling with regular flow. Throws need context review for form data preservation.

### cart_charge_logic.php (2 page renders + exit)

Lines 265, 274: `PublicPage::OutputGenericPublicPage(...); exit;` — renders complete pages mid-logic. Needs a different approach (perhaps `LogicResult::render()` with a different template).

### contact_preferences_logic.php (1 echo+exit)

Security check: `echo "Users don't match..."; exit;` — needs to become a proper error return.

### get_subscriptions_logic.php (1 silent exit)

`if(!$settings->get_setting('products_active')) { exit; }` — silent exit with no output. Needs decision on what to return.

### blog_logic.php line 40 (1 display_404_page+exit)

`LibraryFunctions::display_404_page(); exit();` — renders a full 404 page. Needs a LogicResult pattern for 404s.

### Admin analytics files (7 PDOException handlers, 3 files)

`catch(PDOException $e) { $dbhelper->handle_query_error($e); exit(); }` — calls a helper that renders an error page. Need to understand `handle_query_error()` before converting.

Files: `admin_analytics_funnels_logic.php` (2), `admin_analytics_stats_logic.php` (3), `admin_analytics_users_logic.php` (2)

### admin_file_upload_process_logic.php (1 exit after upload)

Line 243: Final `exit()` after upload processing to prevent further output. Needs review of calling context.

---

## Summary

| Category | Occurrences | Files | Mechanical? |
|---|---|---|---|
| Redirect+exit | 28 | 15 | Yes |
| Feature-disabled check | 22 | 19 | Yes |
| throw+exit (dead exit) | 13 | 7 | Yes |
| Simple throw | ~40 | 16 | Yes |
| **Subtotal mechanical** | **~103** | | |
| change_tier_logic.php | 35 | 1 | No |
| AJAX-aware patterns | ~13 | 3 | No |
| Page render+exit | ~4 | 2 | No |
| PDOException handlers | 7 | 3 | No |
| Other edge cases | ~3 | 3 | No |
| **Subtotal manual** | **~62** | | |
| **Total** | **~165** | | |

## Execution Order

All conversions will be done in a single pass, not incrementally.

1. **Infrastructure** — LogicResult changes + process_logic() update
2. **Mechanical batch** — all ~103 occurrences via scripted transforms, verify with syntax check and browser test
3. **Manual files** — change_tier_logic.php, login_logic.php, register_logic.php, change-password-required_logic.php, cart_charge_logic.php, and remaining edge cases

## Design Decisions (resolved)

1. **Feature-disabled pattern**: Use `LogicResult::error('This feature is turned off')` with no data. No `LogicResult::notFound()` needed — these aren't real 404s (the route exists, the feature is just disabled). Since no data is passed, `process_logic()` throws to the global error handler which renders a proper error page — better UX than the current raw text dump on a white page, and the view never executes with empty data. The one actual 404 case (`blog_logic.php` calling `display_404_page()` for missing posts) is a manual conversion.

2. **AJAX-aware logic files**: Logic functions must not care about AJAX. They return `LogicResult` regardless. The caller decides the response format — view files call `process_logic()` for normal page behavior; AJAX endpoints call the logic function directly, inspect the `LogicResult`, and return JSON. The `echo json_encode(); exit()` blocks get removed from logic files and moved to thin AJAX endpoint files or handled by the view.

3. **Page render mid-logic**: Move rendering to the view layer. Logic returns `LogicResult::error('message')` or `LogicResult::render($data)` with flags in `$data` indicating what happened. The view checks those flags and renders appropriately. For example, `login_logic.php` currently renders an "account not activated" page mid-logic — instead it returns `LogicResult::error('Your account has not been activated')` and the login view displays that error with a re-send activation link.

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
