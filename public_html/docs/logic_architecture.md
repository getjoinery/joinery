# Logic File Architecture Documentation

## Overview

The logic layer (`/logic/`) provides the business logic and controller functionality in the application's MVC-like architecture. All logic files follow a standardized pattern using the `LogicResult` class for consistent return handling.

**Critical rule:** Logic files must never call `exit()`, `die()`, or `throw` exceptions. Every code path must return a `LogicResult` object. This makes logic files testable, composable, and safe to call from any context.

## Directory Structure

```
/logic/                     # Core logic files
/plugins/*/logic/          # Plugin-specific logic files
/theme/*/logic/            # Theme-specific logic overrides
```

## Logic File Pattern

Every logic file follows this naming convention and structure:

**File naming:** `[page_name]_logic.php`

**Function naming:** `[page_name]_logic($get_vars, $post_vars, ...)`

### Basic Structure

```php
<?php

function page_name_logic($get_vars, $post_vars) {
    // PathHelper, Globalvars, SessionControl are always available
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

    // Include required data classes
    require_once(PathHelper::getIncludePath('data/users_class.php'));

    // Get singletons
    $settings = Globalvars::get_instance();
    $session = SessionControl::get_instance();

    // Business logic here
    $page_vars = array();
    $page_vars['settings'] = $settings;
    $page_vars['session'] = $session;

    // Return using LogicResult
    return LogicResult::render($page_vars);
}
?>
```

## LogicResult Class

The `LogicResult` class provides a standardized return format for all logic functions, enabling consistent handling of renders, redirects, and errors.

### Class Definition

```php
class LogicResult {
    public $redirect = null;
    public $data = [];
    public $error = null;

    // Static factory methods
    public static function redirect($url, $data = []);
    public static function render($data = []);
    public static function error($message, $data = []);
}
```

### Three Return Patterns

#### 1. Render Pattern
Used when the logic prepares data for a view to render:

```php
function product_logic($get_vars, $post_vars) {
    $product = new Product($get_vars['id'], TRUE);

    $page_vars = array();
    $page_vars['product'] = $product;
    $page_vars['title'] = $product->get('pro_name');

    return LogicResult::render($page_vars);
}
```

#### 2. Redirect Pattern
Used when the logic needs to redirect to another page:

```php
function logout_logic($get_vars, $post_vars) {
    $session = SessionControl::get_instance();
    $session->log_out();

    return LogicResult::redirect('/login');
}
```

#### 3. Error Pattern
Used when an error occurs that should be displayed to the user:

```php
function secure_page_logic($get_vars, $post_vars) {
    $session = SessionControl::get_instance();

    if (!$session->is_logged_in()) {
        return LogicResult::error('You must be logged in to access this page');
    }

    // Continue with normal logic...
    return LogicResult::render($page_vars);
}
```

## View Integration

Views should always use `process_logic()` to call logic functions. This handles redirects, errors, and data extraction automatically:

```php
// ✅ CORRECT - Always use process_logic()
$page_vars = process_logic(product_logic($_GET, $_POST));
$product = $page_vars['product'];
```

`process_logic()` handles:
- `LogicResult::redirect()` — performs the redirect
- `LogicResult::error()` — adds error message to session, returns data for re-display
- `LogicResult::render()` — returns the data array
- Legacy array returns — passes through unchanged for backward compatibility

**Never manually check LogicResult in views:**

```php
// ❌ WRONG - Don't manually handle LogicResult in views
$result = product_logic($_GET, $_POST);
if ($result instanceof LogicResult) {
    if ($result->redirect) {
        LibraryFunctions::redirect($result->redirect);
        exit();
    }
    $page_vars = $result->data;
}

// ✅ CORRECT - One line
$page_vars = process_logic(product_logic($_GET, $_POST));
```

## Common Patterns

### Feature Toggle Pattern

```php
function feature_logic($get_vars, $post_vars) {
    $settings = Globalvars::get_instance();

    if (!$settings->get_setting('feature_active')) {
        return LogicResult::error('This feature is not available');
    }

    // Feature logic continues...
    return LogicResult::render($page_vars);
}
```

### Permission Check Pattern

```php
function admin_page_logic($get_vars, $post_vars) {
    $session = SessionControl::get_instance();

    if (!$session->is_logged_in()) {
        return LogicResult::redirect('/login');
    }

    if ($session->get_permission_level() < 5) {
        return LogicResult::error('You do not have permission to access this page');
    }

    // Admin logic continues...
    return LogicResult::render($page_vars);
}
```

### Form Processing Pattern

```php
function form_logic($get_vars, $post_vars) {
    if ($post_vars) {
        // Process form
        $user = new User(NULL);
        $user->set('usr_name', $post_vars['name']);
        $user->save();

        // Redirect after POST
        return LogicResult::redirect('/success');
    }

    // Display form
    return LogicResult::render($page_vars);
}
```

### Edit Form Pattern

When editing existing records with FormWriterV2, check `edit_primary_key_value` from POST first:

```php
function admin_item_edit_logic($get_vars, $post_vars) {
    // CRITICAL: Check edit_primary_key_value (form submission) first, fallback to GET
    if (isset($post_vars['edit_primary_key_value'])) {
        $item = new Item($post_vars['edit_primary_key_value'], TRUE);
    } elseif (isset($get_vars['itm_item_id'])) {
        $item = new Item($get_vars['itm_item_id'], TRUE);
    } else {
        $item = new Item(NULL);
    }

    if ($post_vars) {
        // Process form...
        $item->save();
        return LogicResult::redirect('/admin/admin_item?itm_item_id=' . $item->key);
    }

    return LogicResult::render(['item' => $item]);
}
```

**See [FormWriter Documentation - Edit Forms](formwriter.md#edit-forms-with-edit_primary_key_value)** for complete details on why this pattern is required.

### Error Handling Pattern

When calling code that might throw exceptions (e.g., Stripe, external APIs), catch them and return `LogicResult::error()`:

```php
function checkout_logic($get_vars, $post_vars) {
    if ($post_vars) {
        try {
            $cart = $session->get_shopping_cart();
            $cart->process_payment($post_vars);
            return LogicResult::redirect('/order-confirmation');

        } catch (Exception $e) {
            return LogicResult::error($e->getMessage(), $post_vars);
        }
    }

    return LogicResult::render($page_vars);
}
```

### Missing/Invalid Parameter Pattern

```php
function event_logic($get_vars, $post_vars) {
    if (empty($get_vars['event_id'])) {
        return LogicResult::error('Event ID is required');
    }

    $event = new Event($get_vars['event_id'], TRUE);
    if (!$event->get('evt_id')) {
        return LogicResult::error('Event not found');
    }

    // Continue with valid event...
    return LogicResult::render(['event' => $event]);
}
```

## Rules for Logic Files

### Never do these in logic files:

```php
// ❌ WRONG - Never call exit()
LibraryFunctions::redirect('/page');
exit();

// ❌ WRONG - Never throw exceptions
throw new SystemDisplayableError('Something went wrong');

// ❌ WRONG - Never set headers directly
header("HTTP/1.0 404 Not Found");
exit();

// ❌ WRONG - Never echo output directly
echo json_encode(['success' => true]);
exit();

// ❌ WRONG - Never return raw arrays in new code
return $page_vars;
```

### Always do these:

```php
// ✅ CORRECT - Return LogicResult for redirects
return LogicResult::redirect('/page');

// ✅ CORRECT - Return LogicResult for errors
return LogicResult::error('Something went wrong');

// ✅ CORRECT - Return LogicResult for page renders
return LogicResult::render($page_vars);

// ✅ CORRECT - Catch exceptions from services and wrap them
try {
    $stripe->charge($amount);
} catch (Exception $e) {
    return LogicResult::error($e->getMessage(), $post_vars);
}
```

## Migration from Legacy Patterns

### Converting Old Logic Files

When updating legacy logic files, convert all `throw`, `exit()`, and raw array returns:

**Redirect conversion:**
```php
// Before:
LibraryFunctions::redirect('/some-page');
exit();

// After:
return LogicResult::redirect('/some-page');
```

**Error conversion:**
```php
// Before:
throw new SystemDisplayableError('Email is required');

// After:
return LogicResult::error('Email is required');
```

**Array return conversion:**
```php
// Before:
return $page_vars;

// After:
return LogicResult::render($page_vars);
```

### Backward Compatibility

`process_logic()` handles both old and new return formats, so views don't need to change when logic files are migrated:

```php
// This works whether the logic returns LogicResult or a raw array
$page_vars = process_logic(some_logic($_GET, $_POST));
```

## Testing Logic Files

Because logic files return `LogicResult` objects and never `exit()` or `throw`, they can be tested directly:

```php
// tests/logic/test_product_logic.php
function test_product_logic() {
    // Test render case
    $result = product_logic(['id' => 1], []);
    assert($result instanceof LogicResult);
    assert($result->redirect === null);
    assert(!empty($result->data['product']));

    // Test redirect case
    $result = product_logic([], ['delete' => 1]);
    assert($result instanceof LogicResult);
    assert($result->redirect === '/products');

    // Test error case
    $result = product_logic(['id' => 999999], []);
    assert($result instanceof LogicResult);
    assert($result->error !== null);
}
```

## Plugin Logic Files

Plugins can provide their own logic files following the same patterns:

```php
// plugins/bookings/logic/booking_logic.php
function booking_logic($get_vars, $post_vars) {
    require_once(PathHelper::getIncludePath('plugins/bookings/data/bookings_class.php'));

    // Plugin-specific logic
    $booking = new Booking($get_vars['id'], TRUE);

    return LogicResult::render(['booking' => $booking]);
}
```

## Theme Override Pattern

Themes can override logic files to customize behavior:

```
/logic/product_logic.php           # Base logic
/theme/canvas/logic/product_logic.php  # Theme override

The theme version will be loaded when using:
require_once(PathHelper::getThemeFilePath('product_logic.php', 'logic'));
```

### Best Practice: Extending Base Logic Without Modifying Core

Instead of completely replacing core logic, themes can create focused logic files that provide **additional data** to core views. This approach:
- Keeps core logic untouched
- Allows multiple themes to coexist with different data needs
- Makes maintenance easier
- Follows single responsibility principle

**Example: Homepage with Dynamic Content**

```php
// /theme/phillyzouk/logic/index_logic.php
<?php

function index_logic($get_vars, $post_vars) {
    require_once(PathHelper::getIncludePath('data/posts_class.php'));
    require_once(PathHelper::getIncludePath('data/events_class.php'));

    $page_vars = array();

    // Load recent blog posts (4 posts for homepage)
    $recent_posts = new MultiPost(
        array('published' => TRUE, 'deleted' => false),
        array('pst_published_time' => 'DESC'),
        4, 0
    );
    $recent_posts->load();
    $page_vars['recent_posts'] = $recent_posts;

    // Load upcoming events (6 events for sidebar)
    $upcoming_events = new MultiEvent(
        array('deleted' => false, 'upcoming' => true),
        array('evt_start_time' => 'ASC'),
        6, 0
    );
    $upcoming_events->load();
    $page_vars['upcoming_events'] = $upcoming_events;

    return LogicResult::render($page_vars);
}
?>
```

**Using the Theme Logic in Views**

```php
// /theme/phillyzouk/views/index.php
<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('index_logic.php', 'logic'));

$page_vars = process_logic(index_logic($_GET, $_POST));

$page = new PublicPage();
$page->public_header(array(
    'title' => 'Home',
    'showheader' => true
));
?>

<!-- Use $page_vars['recent_posts'] and $page_vars['upcoming_events'] in template -->
<?php foreach ($page_vars['recent_posts'] as $post): ?>
    <!-- Render post -->
<?php endforeach; ?>
```

**Key Advantages of This Pattern**

1. **No Core Modification** - Base logic files remain unchanged
2. **Theme-Specific Data** - Each theme can load different data sets
3. **Clear Separation** - Logic layer stays independent from view layer
4. **Easy Debugging** - Can inspect `$page_vars` to see what data is available
5. **Reusable** - Other themes can use similar patterns for their needs

## Common Issues and Solutions

### Issue: "Cannot use object of type LogicResult as array"

**Cause:** View is calling a logic function directly without `process_logic()`
**Solution:** Wrap the call with `process_logic()`:

```php
// ❌ Causes error
$page_vars = product_logic($_GET, $_POST);
echo $page_vars['product'];

// ✅ Works correctly
$page_vars = process_logic(product_logic($_GET, $_POST));
echo $page_vars['product'];
```

### Issue: Logic file not found

**Cause:** Incorrect path or naming convention
**Solution:** Ensure file follows `[name]_logic.php` pattern and use correct include:

```php
require_once(PathHelper::getIncludePath('logic/product_logic.php'));  // Core
require_once(PathHelper::getThemeFilePath('product_logic.php', 'logic')); // Theme-aware
```

### Issue: Redirect not working

**Cause:** Output sent before redirect, or not using `process_logic()`
**Solution:** Ensure no echo/print before `process_logic()` call, and check for PHP errors/warnings

## Consistent Variable Naming

Always use these standard variable names:
- `$page_vars` - Array of variables to pass to view
- `$settings` - Globalvars singleton instance
- `$session` - SessionControl singleton instance

## Related Documentation

- [Plugin Developer Guide](plugin_developer_guide.md) - For plugin-specific logic patterns
- [Admin Pages Documentation](admin_pages.md) - For admin interface logic
- [Main Architecture Guide](../CLAUDE.md) - For overall system architecture

## Specifications

- `/specs/implemented/logic_result_minimal_spec.md` - Phase 1 implementation (redirect/render/error)
- `/specs/logic_result_with_validation_spec.md` - Phase 2: complete migration and validation support
