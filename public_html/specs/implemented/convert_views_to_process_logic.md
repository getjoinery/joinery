# Spec: Convert View Files to Use process_logic()

## Problem

~21 core view files and ~17 theme view overrides manually handled `LogicResult` objects with a repeated 4-line pattern instead of using the `process_logic()` helper:

```php
// Old pattern (removed)
$page_vars = some_logic($_GET, $_POST);
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;

// New pattern (all views now use this)
$page_vars = process_logic(some_logic($_GET, $_POST));
```

## What Was Done

Fully mechanical conversion across 38 files in two passes:

1. **Wrap logic calls**: `$page_vars = xxx_logic(...)` → `$page_vars = process_logic(xxx_logic(...))`
2. **Remove redirect block**: Deleted the `if ($page_vars->redirect)` / `exit()` / `$page_vars->data` block and any `// Handle LogicResult return format` comment

No manual intervention was required — every file followed the identical pattern.

### Core views converted (21 files)

- `views/blog.php`, `views/booking.php`, `views/cart.php`
- `views/change-password-required.php`
- `views/event.php`, `views/events.php`, `views/event_waiting_list.php`
- `views/list.php`, `views/lists.php`, `views/location.php`
- `views/login.php`, `views/page.php`
- `views/password-reset-1.php`, `views/password-reset-2.php`, `views/password-set.php`
- `views/post.php`, `views/product.php`, `views/products.php`
- `views/register.php`, `views/survey.php`, `views/video.php`

### Theme view overrides converted (17 files)

- `theme/empoweredhealth/views/` — blog, page, post
- `theme/galactictribune/views/` — blog, post
- `theme/jeremytunnell/views/` — post
- `theme/phillyzouk/views/` — blog, events, index, post
- `theme/tailwind/views/` — blog, booking, events, login, password-reset-1, password-reset-2, register
- `theme/zoukroom/views/` — event

## Cleanup (not yet done)

`.backup` view files could be deleted:
- `views/cart.php.backup`, `views/event.php.backup`, `views/event_waiting_list.php.backup`
- `views/list.php.backup`, `views/lists.php.backup`, `views/post.php.backup`
- `views/product.php.backup`, `views/survey.php.backup`
