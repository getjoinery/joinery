# Routing

## Quick Start: Adding a New Page

Create `views/foo.php` and `/foo` is a working URL. No route config needed.

If the page needs business logic, also create `logic/foo_logic.php` — it's auto-loaded by the view via `getThemeFilePath`. The two files together are a complete page.

```
views/notifications.php   → /notifications works automatically
logic/notifications_logic.php  → auto-loaded by the view (optional)
```

Both files participate in the theme override chain, so themes can override them by placing their own versions in `theme/{theme}/views/` or `theme/{theme}/logic/`.

This "view directory fallback" is the primary way simple pages are added. Most existing pages (`/login`, `/cart`, `/products`, `/pricing`, `/booking`, etc.) work this way with no serve.php entry.

## When You DO Need a serve.php Route

The view fallback handles simple pages. You need an explicit route in `serve.php` when:

| Scenario | Example | Why fallback isn't enough |
|----------|---------|--------------------------|
| **Model-based routes** | `/post/{slug}` | Needs to load a model object by slug/id before rendering |
| **Feature-flag gating** | `'check_setting' => 'events_active'` | Page should 404 when feature is disabled |
| **Permission gating** | `'min_permission' => 10` | Page requires login/role check before rendering |
| **Wildcard routes** | `/admin/*` → `adm/{path}` | Maps a URL prefix to a different directory |
| **Non-standard view path** | `/list/{slug}` → `views/list` | View file doesn't match the URL |
| **Default view fallback** | `/profile/*` with `'default_view'` | Needs a fallback when no sub-path matches |
| **Custom logic** | Homepage with logged-in redirect | Requires a closure with complex branching |

**Rule of thumb:** If the URL path matches the view filename (minus `.php`), you don't need a route.

## Route Processing Order

Requests are processed in this order — first match wins:

```
1. Static routes     → Assets (CSS, JS, images) served with cache headers
2. Plugin routes     → Plugin-registered routes (checked before main routes)
3. Custom routes     → PHP closures for complex logic
4. Dynamic routes    → View and model-based routes defined in serve.php
5. View fallback     → Automatic: /foo → views/foo.php (theme-aware)
6. 404               → No match found
```

If your route isn't matching, this order tells you what might be intercepting it first.

## View Resolution Chain

When a view is loaded (whether from a route or the fallback), the system checks for theme and plugin overrides:

```
theme/{theme}/views/foo.php     → checked first (theme override)
plugins/{plugin}/views/foo.php  → checked second (plugin override)
views/foo.php                   → base system view
404                             → none found
```

This applies to logic files too (`logic/foo_logic.php`).

## Route Configuration Reference

Routes are defined in `serve.php` in three categories:

### Static Routes

For assets only (CSS, JS, images, fonts). Never for PHP/dynamic content.

```php
'static' => [
    '/assets/*' => ['cache' => 43200],
    '/plugins/{plugin}/assets/*' => ['cache' => 43200],
    '/theme/{theme}/assets/*' => ['cache' => 43200],
    '/favicon.ico' => ['cache' => 43200],
]
```

Options: `cache` (seconds), `exclude_from_cache` (array of file extensions).

### Dynamic Routes

All view and model-based routes.

```php
'dynamic' => [
    // Simple view — explicit file path
    '/robots.txt' => ['view' => 'views/robots'],

    // Model route — loads object, auto-determines view from model name
    '/post/{slug}' => [
        'model' => 'Post',
        'model_file' => 'data/posts_class',
        'check_setting' => 'blog_active'
    ],

    // Wildcard — maps URL prefix to directory
    '/admin/*' => ['view' => 'adm/{path}'],

    // Fallback view — tries {path}, falls back to default
    '/profile/*' => [
        'view' => 'views/profile/{path}',
        'default_view' => 'views/profile/profile'
    ],
]
```

**All dynamic route options:**

| Option | Required | Description |
|--------|----------|-------------|
| `view` | Yes (unless `model` set) | View file path, no `.php`. Supports `{path}`, `{file}`, `{slug}` placeholders |
| `model` | No | Model class name. Auto-determines view as `views/{lowercase_model}` if `view` not set |
| `model_file` | When `model` set | Path to model class file, no `.php` |
| `var_name` | No | Variable name for model instance in view scope (defaults to lowercase model name) |
| `check_setting` | No | Setting name — route only serves if setting is truthy |
| `min_permission` | No | Integer permission level required (uses `SessionControl::check_permission()`) |
| `default_view` | No | Fallback view when primary view file not found |
| `valid_page` | No | Set `false` to exclude from page statistics (default: `true`) |

### Custom Routes

PHP closures for complex logic. Return `true` if handled, `false` to continue to next route.

```php
'custom' => [
    '/' => function($params, $settings, $session, $template_directory) {
        // Homepage with logged-in redirect logic
        return true;
    },
]
```

## Common Patterns

### Simple public page
```
views/notifications.php          → /notifications
logic/notifications_logic.php    → auto-loaded (optional)
```
No serve.php change needed.

### Model-based content page
```php
// In serve.php dynamic routes:
'/product/{slug}' => [
    'model' => 'Product',
    'model_file' => 'data/products_class',
    'check_setting' => 'products_active'
],
```
View auto-resolves to `views/product.php`. The `$product` variable is available in the view.

### Admin page
Admin files live in `/adm/`, not `/admin/`. The wildcard route handles mapping:
```php
'/admin/*' => ['view' => 'adm/{path}']
```
So `/admin/admin_users` loads `adm/admin_users.php`. Plugin admin pages auto-discover at `/plugins/{plugin}/admin/*`.

### AJAX endpoint
```php
'/ajax/*' => ['view' => 'ajax/{file}']
```
Plugin ajax files are checked first automatically. Create `ajax/my_endpoint.php` and it's available at `/ajax/my_endpoint`.

### Permission-protected route
```php
'/tests/*' => ['view' => 'tests/{path}', 'min_permission' => 10],
```
Redirects to login if not authenticated, shows 403 if insufficient permission.

## URL Rules

- **Never use `.php` extensions in URLs or links.** The routing system strips them.
  - Wrong: `<a href="/admin/admin_users.php?id=1">`
  - Right: `<a href="/admin/admin_users?id=1">`
- Query parameters (`?key=value`) pass through routing unchanged and are available in `$_GET`.

## Debugging

Add `?debug_routes=1` to any URL to see route matching details in HTML comments. Requires superadmin login.

Check error logs for route-related issues:
```bash
grep -i "route" /var/www/html/joinerytest/logs/error.log | tail -20
```
