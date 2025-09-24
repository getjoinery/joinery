# Logic File Architecture Documentation

## Overview

The logic layer (`/logic/`) provides the business logic and controller functionality in the application's MVC-like architecture. All logic files follow a standardized pattern using the `LogicResult` class for consistent return handling.

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
require_once(__DIR__ . '/../includes/PathHelper.php');

function page_name_logic($get_vars, $post_vars) {
    // PathHelper, Globalvars, SessionControl are always available
    PathHelper::requireOnce('includes/LogicResult.php');
    PathHelper::requireOnce('includes/LibraryFunctions.php');

    // Include required data classes
    PathHelper::requireOnce('data/users_class.php');

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
Used when an error occurs that should be handled by the error system:

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

## Integration Points

### Router Integration

The router (`serve.php` and `RouteHelper.php`) automatically handles LogicResult returns:

```php
// In RouteHelper::handleDynamicRoute()
$result = $logic_function($_GET, $_POST);

if ($result instanceof LogicResult) {
    if ($result->redirect) {
        header("Location: " . $result->redirect);
        exit();
    } elseif ($result->error) {
        // Handle error
        throw new Exception($result->error);
    } else {
        // Pass data to view
        $page_vars = $result->data;
    }
}
```

### View Integration

Views that directly call logic functions must handle LogicResult:

```php
// In a view file
$page_vars = product_logic($_GET, $_POST, $product);

// Handle LogicResult return format
if ($page_vars instanceof LogicResult) {
    if ($page_vars->redirect) {
        LibraryFunctions::redirect($page_vars->redirect);
        exit();
    }
    $page_vars = $page_vars->data;
}

// Now use $page_vars normally
$product = $page_vars['product'];
```

## Common Patterns

### Feature Toggle Pattern

```php
function feature_logic($get_vars, $post_vars) {
    $settings = Globalvars::get_instance();

    if (!$settings->get_setting('feature_active')) {
        header("HTTP/1.0 404 Not Found");
        echo 'This feature is turned off';
        exit();
    }

    // Feature logic continues...
    return LogicResult::render($page_vars);
}
```

### Permission Check Pattern

```php
function admin_page_logic($get_vars, $post_vars) {
    $session = SessionControl::get_instance();

    $session->check_permission(5); // Requires admin

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

### AJAX Response Pattern

```php
function ajax_logic($get_vars, $post_vars) {
    $ajax = !(empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
              $_SERVER['HTTP_X_REQUESTED_WITH'] != 'XMLHttpRequest');

    if ($ajax) {
        // Return JSON for AJAX requests
        echo json_encode(array('success' => true));
        exit();
    }

    // Regular request handling
    return LogicResult::render($page_vars);
}
```

## Best Practices

### 1. Always Use LogicResult

Never return raw arrays or use direct redirects in new code:

```php
// ❌ WRONG - Old pattern
return $page_vars;

// ❌ WRONG - Direct redirect
header("Location: /page");
exit();

// ✅ CORRECT - Use LogicResult
return LogicResult::render($page_vars);
return LogicResult::redirect('/page');
```

### 2. Include Required Files Early

```php
function logic($get_vars, $post_vars) {
    // Include all requirements at the top
    PathHelper::requireOnce('includes/LogicResult.php');
    PathHelper::requireOnce('includes/LibraryFunctions.php');
    PathHelper::requireOnce('data/users_class.php');

    // Then proceed with logic
    // ...
}
```

### 3. Handle Missing Parameters Gracefully

```php
function event_logic($get_vars, $post_vars) {
    if (empty($get_vars['event_id'])) {
        return LogicResult::error('Event ID is required');
    }

    $event = new Event($get_vars['event_id'], TRUE);
    if (!$event->get('evt_id')) {
        header("HTTP/1.0 404 Not Found");
        require_once(LibraryFunctions::display_404_page());
        exit();
    }

    // Continue with valid event...
}
```

### 4. Use Early Returns for Clarity

```php
function protected_logic($get_vars, $post_vars) {
    $session = SessionControl::get_instance();

    // Early return for unauthorized
    if (!$session->is_logged_in()) {
        return LogicResult::redirect('/login');
    }

    // Early return for missing data
    if (empty($get_vars['id'])) {
        return LogicResult::error('ID is required');
    }

    // Main logic with clean flow
    $data = process_request($get_vars['id']);
    return LogicResult::render(['data' => $data]);
}
```

### 5. Consistent Variable Naming

Always use these standard variable names:
- `$page_vars` - Array of variables to pass to view
- `$settings` - Globalvars singleton instance
- `$session` - SessionControl singleton instance

## Migration from Legacy Patterns

### Converting Old Logic Files

When updating legacy logic files that return arrays directly:

**Before:**
```php
function old_logic($get_vars, $post_vars) {
    $page_vars = array();
    $page_vars['data'] = 'value';
    return $page_vars;
}
```

**After:**
```php
function old_logic($get_vars, $post_vars) {
    PathHelper::requireOnce('includes/LogicResult.php');

    $page_vars = array();
    $page_vars['data'] = 'value';
    return LogicResult::render($page_vars);
}
```

### Backward Compatibility

The system maintains backward compatibility by checking return types in views:

```php
$result = logic_function($_GET, $_POST);

// Handle both old and new formats
if ($result instanceof LogicResult) {
    // New format
    $page_vars = $result->data;
} else {
    // Legacy array format
    $page_vars = $result;
}
```

## Testing Logic Files

### Unit Testing Pattern

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
    PathHelper::requireOnce('includes/LogicResult.php');
    PathHelper::requireOnce('plugins/bookings/data/bookings_class.php');

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

## Common Issues and Solutions

### Issue: "Cannot use object of type LogicResult as array"

**Cause:** View is not handling LogicResult return
**Solution:** Add LogicResult handling in view:

```php
if ($page_vars instanceof LogicResult) {
    if ($page_vars->redirect) {
        LibraryFunctions::redirect($page_vars->redirect);
        exit();
    }
    $page_vars = $page_vars->data;
}
```

### Issue: Logic file not found

**Cause:** Incorrect path or naming convention
**Solution:** Ensure file follows `[name]_logic.php` pattern and use correct include:

```php
PathHelper::requireOnce('logic/product_logic.php');  // Core
require_once(PathHelper::getThemeFilePath('product_logic.php', 'logic')); // Theme-aware
```

### Issue: Redirect not working

**Cause:** Output sent before redirect
**Solution:** Ensure no echo/print before LogicResult::redirect() and check for PHP errors/warnings

## Related Documentation

- [Plugin Developer Guide](plugin_developer_guide.md) - For plugin-specific logic patterns
- [Admin Pages Documentation](CLAUDE_admin_pages.md) - For admin interface logic
- [Main Architecture Guide](../../CLAUDE.md) - For overall system architecture

## Specifications

For detailed implementation specifications, see:
- `/specs/logic_result_minimal_spec.md` - Current minimal implementation
- `/specs/logic_result_with_validation_spec.md` - Future validation features