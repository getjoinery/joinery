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

**Function naming:** `[page_name]_logic(array $input): LogicResult`

Every logic file takes a single `$input` array (the merged `$_GET`/`$_POST`/route params from the caller) and returns a `LogicResult`. There are no extra positional parameters and no per-route variants — the calling convention is identical for page handlers, action surfaces, and API entry points.

### Basic Structure

```php
<?php

function page_name_logic(array $input): LogicResult {
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
function product_logic(array $input): LogicResult {
    $product = new Product($input['id'], TRUE);

    $page_vars = array();
    $page_vars['product'] = $product;
    $page_vars['title'] = $product->get('pro_name');

    return LogicResult::render($page_vars);
}
```

#### 2. Redirect Pattern
Used when the logic needs to redirect to another page:

```php
function logout_logic(array $input): LogicResult {
    $session = SessionControl::get_instance();
    $session->log_out();

    return LogicResult::redirect('/login');
}
```

#### 3. Error Pattern
Used when an error occurs that should be displayed to the user:

```php
function secure_page_logic(array $input): LogicResult {
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
$page_vars = process_logic(product_logic(array_merge($_GET, $_POST)));
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
$result = product_logic(array_merge($_GET, $_POST));
if ($result instanceof LogicResult) {
    if ($result->redirect) {
        LibraryFunctions::redirect($result->redirect);
        exit();
    }
    $page_vars = $result->data;
}

// ✅ CORRECT - One line
$page_vars = process_logic(product_logic(array_merge($_GET, $_POST)));
```

## Common Patterns

### Feature Toggle Pattern

```php
function feature_logic(array $input): LogicResult {
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
function admin_page_logic(array $input): LogicResult {
    $session = SessionControl::get_instance();

    if (!$session->is_logged_in()) {
        return LogicResult::redirect('/login');
    }

    if ($session->get_permission() < 5) {
        return LogicResult::error('You do not have permission to access this page');
    }

    // Admin logic continues...
    return LogicResult::render($page_vars);
}
```

### Form Processing Pattern

```php
function form_logic(array $input): LogicResult {
    if (LibraryFunctions::isFormSubmission()) {
        // Process form
        $user = new User(NULL);
        $user->set('usr_name', $input['name']);
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
function admin_item_edit_logic(array $input): LogicResult {
    // CRITICAL: Check edit_primary_key_value (form submission) first, fallback to GET
    if (isset($input['edit_primary_key_value'])) {
        $item = new Item($input['edit_primary_key_value'], TRUE);
    } elseif (isset($input['itm_item_id'])) {
        $item = new Item($input['itm_item_id'], TRUE);
    } else {
        $item = new Item(NULL);
    }

    if (LibraryFunctions::isFormSubmission()) {
        // Process form...
        $item->save();
        return LogicResult::redirect('/admin/admin_item?itm_item_id=' . $item->key);
    }

    return LogicResult::render(['item' => $item]);
}
```

**See [FormWriter Documentation - Edit Forms](formwriter.md#edit-forms-with-edit_primary_key_value)** for complete details on why this pattern is required.

### Descriptor functions & descriptor-driven forms

A logic file can expose a `*_logic_descriptor()` function returning a `['description', 'requires_session', 'mutates', 'ai_agent', 'input' => [...]]` array. The `input` map is one declaration of the form's fields, consumed by three things: the rendered form (via FormWriter's [`fromDescriptor()`](formwriter.md#descriptor-driven-forms-fromdescriptor)), its client-side validation, and the REST/AI surfaces. Edit-form views render the whole body with `$fw->fromDescriptor(item_edit_logic_descriptor())` instead of hand-listing fields. The [scaffolding generator](scaffolding.md) emits both the logic (with its descriptor) and the matching view automatically — see that guide for the manifest format and the derived/declared/stubbed contract.

The `ai_agent` key controls whether the [joinery_ai](../plugins/joinery_ai/docs/overview.md) agent may call this action, and is **default-deny**: omit it and the action is not callable by the AI; set `'confirm'` to make it callable with a human sign-off on a mutating call, or `'auto'` to let it run inline. It pairs with `mutates` — the confirmation tier only matters for calls that actually mutate. Sensitive flows (credentials, security) deliberately leave it absent. The scaffold's public edit-logic template emits `'ai_agent' => 'confirm'`; the admin template leaves it commented out (admin actions opt in deliberately).

### Detecting form submission — use `isFormSubmission()`, never `if($input)`

Logic functions in the current convention receive a single `$input`
(`array_merge($_GET, $_POST)`). **`$input` is never empty on a GET** — an edit
link carries the record id, so `if ($input) { … ->save(); }` runs the save
handler on mere page-open, producing redirect loops (handler redirects to self)
or **null-overwrite corruption** (fields set from undefined `$input` keys, then
saved). Guard every save/mutate/redirect handler on the HTTP method instead:

```php
function admin_item_edit_logic(array $input): LogicResult {
    // load-or-create from $input — runs on both GET and POST
    $item = isset($input['edit_primary_key_value'])
        ? new Item($input['edit_primary_key_value'], TRUE)
        : new Item(NULL);

    if (LibraryFunctions::isFormSubmission()) {   // true only on a real POST
        foreach ($editable_fields as $f) $item->set($f, $input[$f]);
        $item->save();
        return LogicResult::redirect('/admin/admin_item?itm_item_id=' . $item->key);
    }

    return LogicResult::render(['item' => $item]);   // GET → draw the form
}
```

`LibraryFunctions::isFormSubmission()` returns true only when
`$_SERVER['REQUEST_METHOD'] === 'POST'`. The meaning of "submitted" lives in one
place; never re-derive it from `if($input)` / `if(!empty($input))`.

#### GET-is-read-only invariant (enforced at the write boundary)

A GET request must never persist data. `SystemBase`'s write methods —
`save()`, `soft_delete()`, `permanent_delete()` — assert this at the single
chokepoint every mutation passes through: a GET-request write is logged
(`[GET_MUTATION]`), and once the rollout flips the dev gate on it also throws in
dev (the `debug` setting). This catches the whole bug class — including plugin,
dynamic, and non-`if($input)` code a text lint can't see.

**Intentional GET-action links** legitimately mutate on GET — a `?action=delete`
admin link or a payment-gateway return URL. Opt each one in, and **always reset
the flag in a `finally`**:

```php
SystemBase::$allow_get_mutation = true;
try { $item->soft_delete(); }
finally { SystemBase::$allow_get_mutation = false; }
return LogicResult::redirect('/admin/admin_items');
```

**Maintenance and reconciliation never belong in page logic.** Work that mutates
state as a side effect of *displaying* a page — subscription reconciliation,
audit-log writes, external-API sweeps — must run on the cron runner as a
[scheduled task](scheduled_tasks.md), never via `include()` into a logic file.
A page render is read-only; opting it into `$allow_get_mutation` to silence the
tripwire is a marker of misplaced work, not a fix.

CLI / cron / scheduled-task contexts (no `REQUEST_METHOD`) are exempt
automatically. See also [Admin Pages](admin_pages.md) and [Routing](routing.md#the-__route-parameter).

### Error Handling Pattern

When calling code that might throw exceptions (e.g., Stripe, external APIs), catch them and return `LogicResult::error()`:

```php
function checkout_logic(array $input): LogicResult {
    if (LibraryFunctions::isFormSubmission()) {
        try {
            $cart = $session->get_shopping_cart();
            $cart->process_payment($input);
            return LogicResult::redirect('/order-confirmation');

        } catch (Exception $e) {
            return LogicResult::error($e->getMessage(), $input);
        }
    }

    return LogicResult::render($page_vars);
}
```

### Missing/Invalid Parameter Pattern

```php
function event_logic(array $input): LogicResult {
    if (empty($input['event_id'])) {
        return LogicResult::error('Event ID is required');
    }

    $event = new Event($input['event_id'], TRUE);
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
$page_vars = process_logic(some_logic(array_merge($_GET, $_POST)));
```

## Testing Logic Files

Because logic files return `LogicResult` objects and never `exit()` or `throw`,
they can be called directly and asserted against with the shared test harness
(see **📖 [Testing](testing.md)** for the harness API, tiers, and how to run):

```php
/** @joinery-test
 * name: product_logic
 * tier: db
 * env: dev-only
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

section('render / redirect / error paths');

$result = product_logic(['id' => 1]);
check($result instanceof LogicResult && $result->redirect === null, 'render returns data');
check(!empty($result->data['product']), 'product is present');

$result = product_logic(['delete' => 1]);
check($result->redirect === '/products', 'delete redirects to /products');

$result = product_logic(['id' => 999999]);
check($result->error !== null, 'missing id returns an error result');

harness_finish();
```

## Plugin Logic Files

Plugins can provide their own logic files following the same patterns:

```php
// plugins/bookings/logic/booking_logic.php
function booking_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('plugins/bookings/data/bookings_class.php'));

    // Plugin-specific logic
    $booking = new Booking($input['id'], TRUE);

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
// (this theme declares "requires_plugins": ["event_manager"] in theme.json,
//  so the plugin's classes are guaranteed present)
<?php

function index_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('data/posts_class.php'));
    require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));

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

$page_vars = process_logic(index_logic(array_merge($_GET, $_POST)));

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
$page_vars = product_logic(array_merge($_GET, $_POST));
echo $page_vars['product'];

// ✅ Works correctly
$page_vars = process_logic(product_logic(array_merge($_GET, $_POST)));
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
- [Scaffolding / Code Generator](scaffolding.md) - Generate logic + view + data class from one manifest
- [Main Architecture Guide](../CLAUDE.md) - For overall system architecture

## Specifications

- `/specs/implemented/logic_result_minimal_spec.md` - Phase 1 implementation (redirect/render/error)
- `/specs/logic_result_with_validation_spec.md` - Phase 2: complete migration and validation support
