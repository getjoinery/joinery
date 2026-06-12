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
| **URL placeholders** | `/post/{slug}` | URL captures a variable into `$params` for the view/logic |
| **Feature-flag gating** | `'check_setting' => 'events_active'` | Page should 404 when feature is disabled |
| **Permission gating** | `'min_permission' => 10` | Page requires login/role check before rendering |
| **Wildcard routes** | `/admin/*` → `adm/{path}` | Maps a URL prefix to a different directory |
| **Non-standard view path** | `/list/{slug}` → `views/list` | View file doesn't match the URL |
| **Custom logic** | Homepage with logged-in redirect | Requires a closure with complex branching |

**Rule of thumb:** If the URL path matches the view filename (minus `.php`), you don't need a route.

## Route Processing Order

Requests are processed in this order — first match wins:

```
1. Static routes     → Assets (CSS, JS, images) served with cache headers
2. Plugin routes     → Plugin-registered routes (checked before main routes)
3. Custom routes     → PHP closures for complex logic
4. Dynamic routes    → View routes defined in serve.php
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

## Dynamic content pages: `pag_template`

Most Pages render through `views/page.php` (and its themed overrides), which reads `pag_title`, `pag_body`, and `pag_component_layout` and produces markup. For Pages whose content depends on runtime state (e.g. a "thanks for signing up" page that formats the new user's name), set `pag_template` on the Page row:

```
pag_template = 'page_register_thanks'
```

`views/page.php` then delegates to `theme/{theme}/views/page_register_thanks.php`. That template is a normal view with `$page` available and no framework magic — write whatever PHP you need inline.

This replaces the legacy `pag_script_filename` + `{{var}}` placeholder mechanism, which has been removed.

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

View-based routes, with optional URL placeholders captured into `$params`.

```php
'dynamic' => [
    // Simple view — explicit file path
    '/robots.txt' => ['view' => 'views/robots'],

    // Slug-based content route — {slug} captured into $params['slug']
    '/post/{slug}' => [
        'view' => 'views/post',
        'check_setting' => 'blog_active'
    ],

    // Wildcard — maps URL prefix to directory
    '/admin/*' => ['view' => 'adm/{path}'],

    '/profile/*' => ['view' => 'views/profile/{path}'],
]
```

URL placeholders (`{slug}`, `{id}`, etc.) are extracted into a `$params` array that's available in the view and any logic file it calls. Logic files load their own model objects from `$params['slug']` (or whatever value is captured).

**All dynamic route options:**

| Option | Required | Description |
|--------|----------|-------------|
| `view` | Yes | View file path, no `.php`. Supports `{path}`, `{file}`, `{slug}` placeholders |
| `check_setting` | No | Setting name — route only serves if setting is truthy |
| `min_permission` | No | Minimum permission level required (uses `SessionControl::check_permission()`). **See the ladder below — note `0` requires login; *omit* the key for a truly public page.** |
| `valid_page` | No | Set `false` to exclude from page statistics (default: `true`) |

#### Permission levels and the `min_permission => 0` gotcha

The integer passed to `min_permission` is matched against the session's permission level:

| Level | Who |
|-------|-----|
| `0`   | Any logged-in user (no rank requirement) |
| `1`–`4` | Member tiers |
| `5` / `7` / `9` | Admin tiers (5 = basic admin) |
| `10`  | Superadmin / full system admin |

**The counterintuitive part — `0` is not "public":**

- **`'min_permission' => 0'` = "must be logged in, any rank."** The router gates on
  `isset($config['min_permission'])`, and `isset` is **true even when the value is `0`**.
  `check_permission()` then redirects any not-logged-in visitor to `/login` *before* it ever
  compares the level — so a `0` floor still forces a login.
- **`'min_permission' => 5'` = "logged in **and** admin."**
- **A truly public page omits the key entirely.** With no `min_permission`, `isset` is false
  and no check runs.

If you misread `0` as "open" you get the *safe* failure — the page demands a login rather than
exposing itself — but it is still wrong. Use `0` for "any member," omit the key for "anyone."

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

### Slug-based content page
```php
// In serve.php dynamic routes:
'/product/{slug}' => [
    'view' => 'views/product',
    'check_setting' => 'products_active'
],
```
The view at `views/product.php` calls `product_logic(array_merge($_GET, $_POST, $params ?? []))`, and the logic file loads the Product by `$input['slug']`.

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

## The `__route` parameter

The Apache rewrite sends every request through the front controller as
`serve.php?__route=<path>`, so `$_GET['__route']` is set on every request. It is
**routing metadata, not page input** — the route value is passed to dispatch as
`$request_path`. `RouteHelper::processRoutes()` therefore **unsets**
`__route` from `$_GET` / `$_POST` / `$_REQUEST` at dispatch entry, so page logic
never sees it.

This matters because logic functions receive `array_merge($_GET, $_POST)`: if
`__route` leaked through, `$input` would be non-empty on every request and
`if($input)`-style submission guards would misfire (the bug fixed in
`specs/implemented/admin_logic_get_submission_guard_fix.md`). Don't reintroduce a
read of `$_GET['__route']` in page code — use `$request_path` (or
`$_SERVER['REQUEST_URI']`) instead.

## Static Page Cache

Anonymous GET requests are served from a static HTML cache stored in `cache/static_pages/`.
The cache is managed by `includes/StaticPageCache.php` and checked inside `RouteHelper::processRoutes()`.

**How it works:**
- On a cache miss, PHP renders the page normally and the output is written to a `.html` file.
- On a cache hit, the file is served directly with `readfile()` — PHP does almost no work.
- 1% of cache hits are randomly invalidated so pages stay fresh without a TTL.
- Pages that cannot be cached (login-required, POST responses, error pages) are marked `nostatic`
  in the index and skipped on future checks.

**Cache location:** `{site_root}/cache/static_pages/`
- `index.json` — maps URL keys to `{status, url, time, extension}` entries
- `index.html` — cached homepage
- `about_us.html` — cached `/about-us`, etc.

**Diagnosing stale cache:**
On cache hits, three signals are available (after `specs/static_cache_diagnostics.md` is
implemented):
- HTTP header `X-Cache: HIT` — present on every cache hit
- HTTP header `X-Cache-Created: <ISO 8601>` — when the cached file was written
- HTTP header `X-Cache-Age: <seconds>` — age of the cached file
- HTML source comment `<!-- Cached: / | Created: 2026-05-03T01:29:07+00:00 -->`

**Clearing the cache:**
Go to Admin → Static Cache (`/admin/admin_static_cache`) and click "Clear All Cache". Automatic
invalidation covers the common cases (see below), so manual clearing should rarely be needed.

**Automatic invalidation:**
- `alternate_homepage` or `alternate_loggedin_homepage` settings saved → `/` invalidated
- `active_theme` setting saved → entire cache flushed

## Debugging

Add `?debug_routes=1` to any URL to see route matching details in HTML comments. Requires superadmin login.

Check error logs for route-related issues:
```bash
grep -i "route" /var/www/html/joinerytest/logs/error.log | tail -20
```
