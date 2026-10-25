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
| `view` | Yes* | View file path, no `.php`. Supports `{path}`, `{file}`, `{slug}` placeholders |
| `handler` | Yes* | Alternative to `view`: a handler file (no `.php`) that fully serves the response itself (e.g. ICS downloads) — no view/theme resolution runs |
| `plugin` | No | Plugin name that owns this route. View/handler and auto-loaded logic resolve from the plugin's directory; the route hard-404s while the plugin is inactive. See below |
| `check_setting` | No | Setting name — route only serves if setting is truthy |
| `static_cache` | No | `false` — the response is never written to the static page cache and leaves no index entry (a search page, where every query string is its own page, or a link made for one recipient). A view can do the same with `StaticPageCache::skipThisRequest()` |
| `min_permission` | No | Minimum permission level required (uses `SessionControl::check_permission()`). **See the ladder below — note `0` requires login; *omit* the key for a truly public page.** |
| `valid_page` | No | Set `false` to exclude from page statistics (default: `true`) |
| `admin_page_check` | No | `false` — a route inside an admin URL family that is public by its own declaration (`/admin/server_manager/publish_theme`, which other sites fetch with no session). Every other admin path needs its page's `@joinery-admin` level (below) |

*Exactly one of `view` or `handler`.

#### Admin pages

Before custom, dynamic and fallback routes, the router checks every admin
path (`/admin/{name}`, `/admin/{plugin}/{name}`, `/plugins/{plugin}/admin/{name}`,
and a plugin's dynamic route inside `/admin/`, checked against its `view`) against
its page file's `@joinery-admin` header (`AdminPageAccess`): signed out, the sign-in
page; below the level, a 401. A page without a header needs 10. See
[Permissions](permissions.md#pages).

#### Plugin-delegated routes

A core-declared route can be owned by a plugin: the URL stays a first-class core URL (`/cart`, `/event/{slug}`), but its view (or handler) and auto-loaded logic resolve from the plugin's directory.

```php
'/cart'             => ['view' => 'views/cart', 'plugin' => 'store', 'check_setting' => 'products_active'],
'/event/{slug}.ics' => ['handler' => 'includes/ics_event_route', 'plugin' => 'event_manager', 'check_setting' => 'events_active'],
```

Two guarantees:

- **Inactive plugin → hard 404.** A plugin-delegated route is exclusively owned by its plugin. When the plugin is inactive or absent, the URL 404s outright — it never falls through to a theme or base view of the same name.
- **The plugin gate runs before `check_setting`.** The gating setting row is seeded at plugin activation, so on a never-activated install it does not exist; checking the plugin first is what keeps that case a 404 instead of a silent fall-through.

Use plugin delegation for top-level URLs a plugin owns. Plugin pages under the plugin's own namespace (`/plugins/{plugin}/...`, `/profile/{plugin}/...`) need no serve.php entry — they auto-discover.

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

A handler that returns `true` and writes nothing is answered with a 500 and a `ROUTING ERROR` line in the error log, since a page that renders nothing is a bug nobody sees. An empty body the handler means is served: a redirect, a status it set itself (304, 204, 416), a `Content-Length` it declared (a zero-byte file is `200` with `Content-Length: 0`), or an answer to HEAD. What a handler writes is buffered; one that streams a file body leaves those buffers first, as `File::serve_from_path()` does, so the download never sits in memory.

```php
'custom' => [
    '/' => function($params, $settings, $session, $template_directory) {
        // Homepage with logged-in redirect logic
        return true;
    },
]
```

## Common Patterns

### The site root
`/` is a custom route driven by two settings. `alternate_homepage` (factory `/login`) is where a visitor who is not signed in lands; `alternate_loggedin_homepage` (factory `/profile`) is where a member lands, and falls back to the public one when blank. A value of `/blog` or `/page/{slug}` renders that content at the root; any other path is a 302 to it. Both blank renders the built-in `views/index.php` through the theme chain, which is the right state only for a theme that ships its own homepage. A signed-in member who reaches `/login` is sent to the signed-in homepage.

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

### Flat `/ajax/*` namespace (external webhooks + retained endpoints)
```php
'/ajax/*' => ['view' => 'ajax/{file}']
```
This route survives for **external webhooks** — the store's Stripe/PayPal
handlers (`plugins/store/ajax/`) and the mailbox inbound-email webhook
(`plugins/mailbox/ajax/inbound_email_webhook.php`) — and for a small set of
**retained page endpoints** that predate the API surface. The one browser-facing
holdover is the entity-photo manager (`ajax/entity_photos_ajax`, driven by
`PhotoHelper`'s upload/reorder/delete UI); it is documented in
[Photo System](photo_system.md).

All *new* browser-facing endpoints are `/api/v1` actions (`_logic_descriptor()`
opt-in, browser-session credential — see [API](api.md#authentication)). Do not
add new `/ajax/` endpoints.

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
- **A malformed path is refused at the door.** `RouteHelper::processRoutes()` runs
  the decoded request path through `RouteHelper::validatePath()` before any route is
  matched or any file is looked up: a `..` segment, a backslash, a NUL byte or an
  encoded traversal at any decoding depth answers a plain 404. Apache decodes the URL
  once and PHP decodes `__route` again, so `%252e%252e` arrives as `..`, past Apache's
  own dot-segment removal; the door is where that is caught. Every later name check
  (`PathHelper::getThemeFilePath()`, the static handler's own `validatePath()` call)
  only ever catches programmer error.

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
- Some paths are never cached nor served from the cache, whatever the index holds
  (`StaticPageCache::isExcludedPath()`): the sign-in and password-reset steps, the cart and
  checkout, file downloads, and `/copy-key`, which shows what this machine's agent is waiting for.

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
- An upgrade (`utils/upgrade.php`) or a restore (`reconcile_site.sh`) → every saved page deleted;
  `index.json`, which holds whether the cache is on and which URLs are `nostatic`, is kept, and an
  entry whose page is gone is dropped the next time it is asked for
- A forum change → that topic's pages, its category, `/forum` and `/sitemap` (see the forum plugin's docs)

**Invalidating from code:** `StaticPageCache::invalidateUrl($url, $params)` removes one exact URL.
`StaticPageCache::invalidatePath($path, $with_children = false)` removes every cached copy of a
path whatever its query string (`?page=2` goes with the first page), and with `$with_children`
everything under it too. Content whose change reaches every page of it — a thread, a list —
uses `invalidatePath()`.

## Debugging

Add `?debug_routes=1` to any URL to see route matching details in HTML comments. Requires superadmin login.

Check error logs for route-related issues:
```bash
grep -i "route" /var/www/html/joinerytest/logs/error.log | tail -20
```
