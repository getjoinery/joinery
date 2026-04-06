# Spec: Plugin View Auto-Discovery and Route Namespacing

**Status:** Pending implementation
**Area:** Core routing (RouteHelper, PathHelper)
**Related:** [Theme-to-Plugin Dependencies](theme_plugin_dependencies.md) (separate spec)

---

## Problem

Plugin views require explicit route entries in serve.php for every page, even simple view-only pages. Core views don't — creating `views/foo.php` makes `/foo` work automatically. Plugins should have the same convenience.

Additionally, there's no protection against route collisions between plugins. Two plugins defining the same route results in silent, non-deterministic behavior where one plugin's pages simply don't work.

---

## Background

### How plugin views work today

`PathHelper::getThemeFilePath()` resolves views in this order:
1. **Active theme** — `{theme_dir}/{path}`
2. **Current plugin** — `plugins/{plugin}/{path}` (only if `RouteHelper::getCurrentPlugin()` is set)
3. **Base** — `{path}`

`getCurrentPlugin()` is only set when the URL starts with `/plugins/{name}/`, which almost no user-facing URL does. So the plugin check is effectively dead for normal pages.

### Why scrolldaddy works in production

On the scrolldaddy production site, `theme_template = 'plugin'` and `active_theme_plugin = 'scrolldaddy'`. This makes `getActiveThemeDirectory()` return `plugins/scrolldaddy`, so scrolldaddy's views are found through the **theme** resolution (step 1). The explicit routes in serve.php handle route matching, but the view files are only found because the plugin IS the theme.

On any site where a different theme is active, the scrolldaddy views would not be found even when the route matches, because `plugin_specify` is not used for view resolution and `getCurrentPlugin()` is null.

### What `plugin_specify` does today

The `plugin_specify` field in route configs is metadata-only — used for debug logging but never passed to the view resolver. It has no effect on which plugin directory is searched for the view file. This spec removes it entirely (see Change 4).

### What `routes_prefix` was

`routes_prefix` was proposed in a deleted spec (`controld_to_theme.md`) for the ControlD plugin. A plugin would declare `"routes_prefix": "/controld"` in `plugin.json`, and URLs starting with that prefix would set the plugin context via `detectPluginByRoute()`. The detection code was added to `detectPluginByRoute()` (RouteHelper.php, lines 822-841), but:
- No plugin has ever declared it
- The spec was deleted (commit 5bcd4f74)
- It scans all plugin directories on every request — unnecessary disk I/O
- The `plugin_developer_guide.md` does not document it

It was an attempt at solving the same problem this spec addresses, but was abandoned without cleanup.

---

## Design

### Principle: plugin name as namespace

Every plugin gets a collision-free URL namespace enforced by including the plugin name in the URL. Plugin views auto-discover at predictable paths with zero configuration:

| URL pattern | View file | Use case |
|-------------|-----------|----------|
| `/{plugin}` | `plugins/{plugin}/views/index.php` | Plugin landing page |
| `/{plugin}/*` | `plugins/{plugin}/views/*.php` | Plugin's own pages |
| `/profile/{plugin}` | `plugins/{plugin}/views/profile/index.php` | Plugin profile index |
| `/profile/{plugin}/*` | `plugins/{plugin}/views/profile/*.php` | User-facing profile pages |
| `/admin/{plugin}` | `plugins/{plugin}/views/admin/index.php` | Plugin admin index |
| `/admin/{plugin}/*` | `plugins/{plugin}/views/admin/*.php` | Admin pages |

Examples for scrolldaddy:
- `/scrolldaddy` → `plugins/scrolldaddy/views/index.php`
- `/scrolldaddy/pricing` → `plugins/scrolldaddy/views/pricing.php`
- `/profile/scrolldaddy` → `plugins/scrolldaddy/views/profile/index.php`
- `/profile/scrolldaddy/devices` → `plugins/scrolldaddy/views/profile/devices.php`
- `/admin/scrolldaddy` → `plugins/scrolldaddy/views/admin/index.php`
- `/admin/scrolldaddy/settings` → `plugins/scrolldaddy/views/admin/settings.php`

When the URL has no trailing path after the plugin name (e.g. `/scrolldaddy`, `/profile/scrolldaddy`), the convention is to load `index.php` from the corresponding views subdirectory.

Collisions are structurally impossible — the plugin name uniquely identifies the target directory. No collision detection, warnings, or fallback logic needed.

### View resolution order within a namespace match

When a URL matches a plugin namespace pattern, views are resolved in this order:

1. **Theme** — `{theme_dir}/views/{full_url_path}.php` (theme can always override)
2. **Plugin** — `plugins/{plugin}/views/{remaining_path}.php`
3. **Base** — `views/{full_url_path}.php`

The theme always wins. To override a plugin's namespaced view, the theme places a file at the full URL path (e.g. `theme/getjoinery/views/profile/scrolldaddy/devices.php`).

When no plugin namespace is detected, the existing resolution applies: theme → base.

### Plugin-as-theme shortcut

When a plugin IS the active theme (`theme_template = 'plugin'`, `active_theme_plugin = 'scrolldaddy'`), its views are found through theme resolution, which has the highest priority. This means URLs without the plugin name (e.g. `/profile/devices`) work naturally — the theme resolution finds `plugins/scrolldaddy/views/profile/devices.php` directly.

This is the existing behavior and requires no changes. It means plugin-as-theme sites get clean URLs automatically, while multi-plugin sites get safe namespaced URLs. Both the namespaced URL (`/profile/scrolldaddy/devices`) and the clean URL (`/profile/devices`) resolve to the same view file on these sites.

### Plugin serve.php routes are namespaced

Plugin serve.php files can define routes for features that need more than a simple view (model binding, permission gates, feature flags, etc.), but only within the plugin's namespace:

```php
// OK — within scrolldaddy's namespace
'/profile/scrolldaddy/querylog' => [
    'view' => 'views/profile/querylog',
    'min_permission' => 0
],

// NOT OK — outside the namespace, will be dropped with a warning
'/profile/querylog' => [
    'view' => 'views/profile/querylog',
],
```

This is enforced at plugin route loading time. Routes outside the plugin's namespace patterns are dropped with a logged warning.

For explicit routes, the system extracts the plugin name from the URL pattern (same logic as the view fallback) to resolve the view file. No `plugin_specify` field needed.

### Reserved plugin names

Plugin names that conflict with system URL segments are rejected at activation time. Reserved names include any name where `views/{name}.php` or `views/profile/{name}.php` exists as a base system view, plus these structural names:

`profile`, `admin`, `login`, `ajax`, `api`, `assets`, `theme`, `plugins`, `views`, `uploads`, `utils`, `tests`, `docs`, `specs`, `migrations`, `data`, `includes`, `logic`, `adm`

The activation check also scans `views/profile/` for base view filenames. If `views/profile/billing.php` exists, a plugin named `billing` is rejected: "Plugin name 'billing' conflicts with system view at /profile/billing."

### URL resolution priority

| Priority | Source | Collisions |
|----------|--------|------------|
| 1 | Theme routes (theme serve.php) | Theme always wins |
| 2 | Main serve.php routes | Site-level decisions |
| 3 | Plugin explicit routes (namespaced) | Collision-free by construction |
| 4 | View fallback: theme → plugin namespace → base | Collision-free by construction |

---

## Edge case analysis

### Setup

- **Site A:** Theme `getjoinery`, active plugins: `scrolldaddy`, `bookings`
- **Site B:** Plugin-as-theme `scrolldaddy`, active plugin: `bookings`

### Site A: Regular theme + multiple plugins

| URL | What happens | Result |
|-----|-------------|--------|
| `/profile/scrolldaddy/devices` | Namespace match → `plugins/scrolldaddy/views/profile/devices.php` | Plugin view |
| `/profile/bookings/my_bookings` | Namespace match → `plugins/bookings/views/profile/my_bookings.php` | Plugin view |
| `/profile/scrolldaddy` | Namespace match, no trailing → `plugins/scrolldaddy/views/profile/index.php` | Plugin index |
| `/scrolldaddy/pricing` | Namespace match → `plugins/scrolldaddy/views/pricing.php` | Plugin view |
| `/admin/scrolldaddy/settings` | Namespace match → `plugins/scrolldaddy/views/admin/settings.php` | Plugin view |
| `/profile/billing` | "billing" not a plugin → theme check → base `views/profile/billing.php` | Base system view |
| `/profile` | "profile" alone → no namespace match → base resolution | Base system view |
| `/login` | Not a plugin → base resolution | Base system view |
| `/about` | Not a plugin → theme `theme/getjoinery/views/about.php` | Theme view |
| `/scrolldaddy/nonexistent` | Namespace match → file not found → 404 | Clean 404 |
| `/profile/fakeplugin/thing` | "fakeplugin" not active → base resolution → 404 | Clean 404 |

### Site B: Plugin-as-theme + second plugin

| URL | What happens | Result |
|-----|-------------|--------|
| `/profile/devices` | "devices" not a plugin → theme check → `plugins/scrolldaddy/views/profile/devices.php` (scrolldaddy IS theme) | Clean URL works |
| `/profile/scrolldaddy/devices` | Namespace match → same file | Also works |
| `/profile/bookings/my_bookings` | Namespace match → `plugins/bookings/views/profile/my_bookings.php` | Bookings namespaced |
| `/profile/calendar` | "calendar" not a plugin → theme check (scrolldaddy) → not found → base → 404 | Bookings can't get clean URL (correct) |
| `/pricing` | Not a plugin → theme check → `plugins/scrolldaddy/views/pricing.php` (is theme) | Clean URL for theme plugin |

### Cross-cutting concerns

| Concern | Resolution |
|---------|-----------|
| **Theme overrides plugin view** | Theme places `theme/{theme}/views/profile/scrolldaddy/devices.php` — theme check runs first, wins |
| **Plugin tries to override `/login`** | Route outside namespace → dropped with warning. Core views are safe from plugins |
| **Static assets** (`/scrolldaddy/assets/css/style.css`) | Static routes processed before view fallback — served as file, never reaches auto-discovery |
| **AJAX routes** (`/ajax/purge_querylog`) | Handled by existing AJAX plugin iteration — separate mechanism, unaffected |
| **Logic files** | Views load logic via `getThemeFilePath('logic.php', 'logic', ..., 'scrolldaddy')` with explicit plugin name — unaffected |
| **Inactive plugin in URL** | Not in `getActivePlugins()` → no namespace match → falls through to base → 404 |
| **Deeply nested paths** (`/profile/scrolldaddy/settings/notifications`) | Namespace match, remaining = `settings/notifications` → `plugins/scrolldaddy/views/profile/settings/notifications.php` |
| **Plugin explicit route with permissions** | Route matches at dynamic route step (before fallback), plugin name extracted from URL pattern for view resolution |

---

## Changes

### Change 1: Namespaced plugin auto-discovery in view fallback

**File:** `includes/RouteHelper.php`, view directory fallback (around line 1393)

Replace the current single `getThemeFilePath` call with logic that detects plugin-namespaced URLs:

```
Given request_path, split into segments.

Determine if URL matches a plugin namespace pattern:

  Case A: /{first_segment}/... where first_segment is an active plugin
    plugin_name = first_segment
    remaining = everything after first_segment (may be empty)
    view_subdir = ""

  Case B: /profile/{second_segment}/... where second_segment is active plugin
    plugin_name = second_segment
    remaining = everything after second_segment (may be empty)
    view_subdir = "profile"

  Case C: /admin/{second_segment}/... where second_segment is active plugin
    plugin_name = second_segment
    remaining = everything after second_segment (may be empty)
    view_subdir = "admin"

If namespace match found:
  If remaining is empty, remaining = "index" (index.php convention)
  full_url_path = original URL path (for theme/base checks)

  1. Check theme: {theme_dir}/views/{full_url_path}.php
  2. Check plugin: plugins/{plugin_name}/views/{view_subdir}/{remaining}.php
  3. Check base: views/{full_url_path}.php

  Serve first found, or 404.

If no namespace match:
  Existing behavior:
  1. Check theme: {theme_dir}/views/{path}.php
  2. Check base: views/{path}.php
```

The active plugin check is a hash lookup against the keys of `PluginHelper::getActivePlugins()` — fast and deterministic.

### Change 2: Extract plugin name from URL in `handleDynamicRoute`

**File:** `includes/RouteHelper.php`, `handleDynamicRoute()` (around line 585)

Currently the view resolver has no plugin context for matched routes. Apply the same namespace extraction logic from Change 1 to the matched route's URL pattern. If the URL pattern is `/{pluginname}/*`, `/profile/{pluginname}/*`, or `/admin/{pluginname}/*`, extract the plugin name and pass it to `PathHelper::getThemeFilePath` as the `plugin_name` parameter.

```php
$plugin_name = self::extractPluginFromPattern($route_pattern);
$full_path = PathHelper::getThemeFilePath(
    basename($view_path), dirname($view_path),
    'system', null, $plugin_name, false, false
);
```

This replaces the need for `plugin_specify` on plugin routes — the plugin name is derived from the URL itself.

### Change 3: Enforce plugin route namespacing in `loadPluginRoutes()`

**File:** `includes/RouteHelper.php`, `loadPluginRoutes()` (around line 1482)

When loading routes from a plugin's serve.php, validate that each route pattern is within the plugin's namespace. A route is valid if its pattern matches one of:

- `/{pluginName}` or `/{pluginName}/*`
- `/profile/{pluginName}` or `/profile/{pluginName}/*`
- `/admin/{pluginName}` or `/admin/{pluginName}/*`

Routes outside the namespace are dropped with a logged warning:
```
"Plugin 'scrolldaddy' route '/profile/querylog' is outside its namespace. 
Use '/profile/scrolldaddy/querylog' instead. Route ignored."
```

### Change 4: Remove `plugin_specify`

**File:** `includes/RouteHelper.php`

Remove all references to `plugin_specify` in route processing. It is no longer needed — the plugin name is extracted from the URL pattern for plugin routes, and themes/main serve.php do not reference plugin views directly.

Existing route configs in plugin serve.php files that include `plugin_specify` should have the field removed during migration. The field is ignored if present (no error), but it serves no purpose.

### Change 5: Reserved plugin name enforcement

**File:** `includes/PluginManager.php` (or equivalent activation logic)

At plugin activation time, reject plugins whose directory name:
- Matches a hardcoded reserved name: `profile`, `admin`, `login`, `ajax`, `api`, `assets`, `theme`, `plugins`, `views`, `uploads`, `utils`, `tests`, `docs`, `specs`, `migrations`, `data`, `includes`, `logic`, `adm`
- Matches the filename (without `.php`) of any file in `views/` or `views/profile/` (e.g. if `views/profile/billing.php` exists, plugin name `billing` is rejected)

Error message: "Plugin name '{name}' is reserved and conflicts with system URLs. Choose a distinctive plugin name."

### Change 6: Remove `routes_prefix` dead code

**File:** `includes/RouteHelper.php`, `detectPluginByRoute()` (lines 822-841)

Remove the `routes_prefix` directory scanning block. Keep only the `/plugins/{name}/` pattern match (lines 814-820).

**File:** `specs/implemented/allow_theme_plugins.md` (line 36)

Update the "Plugin Detection" section to remove the reference to "Plugin-declared route prefixes in plugin.json".

---

## Migration for existing plugins

Scrolldaddy is the only plugin with a serve.php. No other plugins require migration.

### URL patterns to migrate

| Before | After |
|--------|-------|
| `/profile/device_edit` | `/profile/scrolldaddy/device_edit` |
| `/profile/device_delete` | `/profile/scrolldaddy/device_delete` |
| `/profile/filters_edit` | `/profile/scrolldaddy/filters_edit` |
| `/profile/devices` | `/profile/scrolldaddy/devices` |
| `/profile/rules` | `/profile/scrolldaddy/rules` |
| `/profile/activation` | `/profile/scrolldaddy/activation` |
| `/profile/mobileconfig` | `/profile/scrolldaddy/mobileconfig` |
| `/profile/test` | `/profile/scrolldaddy/test` |
| `/profile/querylog` | `/profile/scrolldaddy/querylog` |
| `/profile/scheduled_block_edit` | `/profile/scrolldaddy/scheduled_block_edit` |
| `/pricing` | `/scrolldaddy/pricing` |
| `/scrolldaddy` | `/scrolldaddy` (already namespaced) |

All of scrolldaddy's current serve.php routes are view-only with `plugin_specify`. Under auto-discovery, the entire serve.php can be reduced to just the static asset route (or deleted if assets are served via the standard `/plugins/scrolldaddy/assets/*` path in main serve.php).

Note: On the scrolldaddy production site where the plugin IS the theme, the old URLs (`/profile/devices`) continue to work through theme resolution. The namespaced URLs (`/profile/scrolldaddy/devices`) also work. Both resolve to the same view file.

### URLs that must NOT change

These are base system URLs referenced in scrolldaddy views but owned by the core system:

- `/profile/change-tier` — base profile page
- `/profile/contact_preferences` — base profile page
- `/login`, `/logout`, `/register`, `/cart` — base system pages
- External URLs (`https://scrolldaddy.app/*`)

### File-by-file migration guide

**Route definition:**

| File | Changes |
|------|---------|
| `serve.php` | Rename all route patterns to namespaced versions. Remove `plugin_specify` from all routes. Remove view-only routes that auto-discovery handles (all current routes). Keep static asset route if custom path is needed. |

**Navigation and includes (PublicPage):**

| File | Line(s) | URL | Type |
|------|---------|-----|------|
| `includes/PublicPage.php` | 341 | `/profile/devices` | Nav link (mobile menu) |
| `includes/PublicPage.php` | 347 | `/pricing` | Nav link (mobile sign up) |
| `includes/PublicPage.php` | 443 | `/profile/devices` | Nav link (header icon) |

**Views — device management:**

| File | Line(s) | URL | Type |
|------|---------|-----|------|
| `views/profile/device_edit.php` | 33 | `/profile/device_edit` | Form action |
| `views/profile/device_edit.php` | 45 | `/profile/device_delete?device_id=...` | Delete link |
| `views/profile/device_delete.php` | 26 | `/profile/device_delete` | Form action |

**Views — devices list (most links in the codebase):**

| File | Line(s) | URL | Type |
|------|---------|-----|------|
| `views/profile/devices.php` | 42 | `/pricing` | No-plan link |
| `views/profile/devices.php` | 55 | `/profile/devices?showdeleted=1` | Show deleted link |
| `views/profile/devices.php` | 115 | `/profile/activation?device_id=...` | Dropdown: Connection Details |
| `views/profile/devices.php` | 115 | `/profile/device_edit?device_id=...` | Dropdown: Edit Device |
| `views/profile/devices.php` | 115 | `/profile/test?device_id=...` | Dropdown: Test Domain |
| `views/profile/devices.php` | 115 | `/profile/querylog?device_id=...` | Dropdown: Query Log |
| `views/profile/devices.php` | 129 | `/profile/filters_edit?device_id=...` | Edit filters button |
| `views/profile/devices.php` | 156 | `/profile/scheduled_block_edit?device_id=...&block_id=...` | Edit scheduled block |
| `views/profile/devices.php` | 157 | `/profile/scheduled_block_edit` | Delete block form action |
| `views/profile/devices.php` | 179 | `/profile/scheduled_block_edit?device_id=...` | Add scheduled block |
| `views/profile/devices.php` | 191 | `/profile/activation?device_id=...` | Activation link |
| `views/profile/devices.php` | 192 | `/profile/device_edit?device_id=...` | Edit device link |
| `views/profile/devices.php` | 207 | `/profile/activation?device_id=...` | Activate button |
| `views/profile/devices.php` | 225 | `/profile/device_edit` | Add device link |
| `views/profile/devices.php` | 240 | `/profile/change-tier` | **NO CHANGE** (base system URL) |

**Views — filters and rules:**

| File | Line(s) | URL | Type |
|------|---------|-----|------|
| `views/profile/filters_edit.php` | 24 | `/profile/devices` | Breadcrumb |
| `views/profile/filters_edit.php` | 37 | `/profile/filters_edit` | Form action |
| `views/profile/filters_edit.php` | 46 | `/profile/rules?device_id=...` | Custom rules link |
| `views/profile/rules.php` | 26 | `/profile/devices` | Breadcrumb |
| `views/profile/rules.php` | 78 | `/profile/rules` | Delete rule form action |
| `views/profile/rules.php` | 89 | `/profile/rules` | Add rule form action |

**Views — activation and testing:**

| File | Line(s) | URL | Type |
|------|---------|-----|------|
| `views/profile/activation.php` | 101 | `/profile/mobileconfig?device_id=...` | Download profile (iOS) |
| `views/profile/activation.php` | 125 | `/profile/mobileconfig?device_id=...` | Download profile (macOS) |
| `views/profile/activation.php` | 246 | `/profile/devices` | Back button |
| `views/profile/activation.php` | 260 | `/profile/devices` | Back link (not active) |
| `views/profile/test.php` | 18 | `/profile/devices` | Breadcrumb |
| `views/profile/test.php` | 32 | `/profile/activation?device_id=...` | Activate device link |
| `views/profile/test.php` | 123 | `/ajax/test_domain` | AJAX GET |
| `views/profile/test.php` | 138 | `/ajax/scan_url` | AJAX POST |

**Views — scheduled blocks and query log:**

| File | Line(s) | URL | Type |
|------|---------|-----|------|
| `views/profile/scheduled_block_edit.php` | 27 | `/profile/devices` | Breadcrumb |
| `views/profile/scheduled_block_edit.php` | 40 | `/profile/scheduled_block_edit` | Form action |
| `views/profile/scheduled_block_edit.php` | 49 | `/profile/devices?device_id=...` | Back link |
| `views/profile/querylog.php` | 21 | `/profile/devices` | Breadcrumb |
| `views/profile/querylog.php` | 221 | `/ajax/purge_querylog` | AJAX POST |

**Views — other pages:**

| File | Line(s) | URL | Type |
|------|---------|-----|------|
| `views/cart_confirm.php` | 8 | `/profile/devices` | PHP redirect |
| `views/cart_confirm.php` | 41 | `/profile/devices` | Button link |
| `views/profile/profile.php` | 64 | `/pricing` | Choose plan link |
| `views/profile/profile.php` | 92 | `/profile/contact_preferences` | **NO CHANGE** (base system URL) |
| `views/profile/profile.php` | 108 | `/profile/change-tier` | **NO CHANGE** (base system URL) |
| `views/index.php` | 39 | `/pricing` | Get started button |
| `views/login.php` | 70 | `/pricing` | Sign up link |
| `views/pricing.php` | 64 | `/pricing?page=year` | JS toggle (yearly) |
| `views/pricing.php` | 68 | `/pricing` | JS toggle (monthly) |

**Logic files — redirects:**

| File | Line(s) | URL | Type |
|------|---------|-----|------|
| `logic/device_edit_logic.php` | 66, 91 | `/profile/devices` | LogicResult::redirect() |
| `logic/device_delete_logic.php` | 38 | `/profile/devices` | LogicResult::redirect() |
| `logic/device_soft_delete_logic.php` | 40 | `/profile/devices` | LogicResult::redirect() |
| `logic/profile_delete_logic.php` | 37 | `/profile/devices` | LogicResult::redirect() |
| `logic/filters_edit_logic.php` | 58 | `/profile/devices` | LogicResult::redirect() |
| `logic/test_logic.php` | 20, 30, 34 | `/profile/devices` | LogicResult::redirect() |
| `logic/querylog_logic.php` | 17, 27 | `/profile/devices` | LogicResult::redirect() |
| `logic/rules_logic.php` | 48, 56, 66, 74 | `/profile/rules?...` | LogicResult::redirect() |
| `logic/scheduled_block_edit_logic.php` | 38, 83 | `/profile/devices` | LogicResult::redirect() |
| `logic/profile_logic.php` | 50 | `/profile/change-tier` | **NO CHANGE** (base system URL) |

### AJAX endpoint note

The three AJAX endpoints (`/ajax/test_domain`, `/ajax/scan_url`, `/ajax/purge_querylog`) are handled by the existing AJAX plugin iteration mechanism, which already searches active plugin directories. These URLs still work without changes. AJAX route namespacing is a separate concern (see Out of Scope).

### Total: 25 files, ~80 URL references to update

The migration is mechanical — find and replace within the scrolldaddy plugin directory. Base system URLs (`/profile/change-tier`, `/profile/contact_preferences`, `/login`) must not be changed.

---

## Theme and plugin serve.php cleanup

With auto-discovery, view-only routes in serve.php are redundant. As part of this implementation, remove them.

### Plugin: scrolldaddy

**File:** `plugins/scrolldaddy/serve.php`

All 9 dynamic routes are view-only with `plugin_specify` — all redundant. The static asset route (`/scrolldaddy/assets/*`) is also redundant since main serve.php handles `/plugins/{plugin}/assets/*`.

**Action:** Delete the entire file, or reduce to an empty routes array if the file is expected to exist.

### Theme cleanup

| Theme | File | View-only routes to remove | Model/param routes to keep | Action |
|-------|------|---------------------------|---------------------------|--------|
| `galactictribune` | `theme/galactictribune/serve.php` | `/explorer`, `/get-spawned`, `/get-unspawned-children`, `/point-info` (all 4) | None | Delete file or empty it |
| `jeremytunnell` | `theme/jeremytunnell/serve.php` | `/blog` | `/post/{slug}` (model-based) | Remove `/blog` route, keep `/post/{slug}` |
| `jeremytunnell-html5` | `theme/jeremytunnell-html5/serve.php` | `/blog` | `/post/{slug}` (model-based) | Remove `/blog` route, keep `/post/{slug}` |
| `phillyzouk-html5` | `theme/phillyzouk-html5/serve.php` | `/blog`, `/events` | `/post/{slug}` (model-based), `/blog/tag/{tag}` (parameterized) | Remove `/blog` and `/events`, keep the other two |
| `zoukroom` | `theme/zoukroom/serve.php` | `/events` | `/event/{slug}` (model-based) | Remove `/events` route, keep `/event/{slug}` |
| `falcon` | `theme/falcon/serve.php` | None (empty) | None | Delete file |
| `zoukphilly` | `theme/zoukphilly/serve.php` | None (empty) | None | Delete file |
| `plugin` | `theme/plugin/serve.php` | None (empty) | None | Delete file |
| `default` | `theme/default/serve.php` | None (empty) | None | Delete file |
| `devonandjerry` | `theme/devonandjerry/serve.php` | None (empty) | None | Delete file |

---

## Developer experience

**Creating a new plugin page:**
1. Create `plugins/myplugin/views/dashboard.php`
2. `/myplugin/dashboard` works immediately

**Creating a plugin landing page:**
1. Create `plugins/myplugin/views/index.php`
2. `/myplugin` works immediately

**Creating a profile page:**
1. Create `plugins/myplugin/views/profile/settings.php`
2. `/profile/myplugin/settings` works immediately

**Creating a profile index:**
1. Create `plugins/myplugin/views/profile/index.php`
2. `/profile/myplugin` works immediately

**Creating an admin page:**
1. Create `plugins/myplugin/views/admin/config.php`
2. `/admin/myplugin/config` works immediately

**Adding permissions or model binding:**
Add a route in plugin serve.php (must be namespaced):
```php
'/profile/myplugin/settings' => [
    'view' => 'views/profile/settings',
    'min_permission' => 0
],
```

---

## Documentation changes

### `docs/plugin_developer_guide.md`

The current guide states plugins "NO LONGER provide" user-facing routes, views, and assets. This was true before the hybrid system, but is no longer accurate. The following sections need updating:

**1. Replace the "Plugins NO LONGER provide" section** (around line 22) with a description of the namespaced URL system. Plugins DO provide user-facing views, served under the plugin's URL namespace.

**2. Add a "Plugin Naming" section** after Plugin.json Requirements (around line 162):

Content to cover:
- Plugin directory names must be distinctive and descriptive (e.g. `scrolldaddy`, `email_forwarding`, not `billing` or `events`)
- The directory name appears in all plugin URLs (`/profile/myplugin/...`), so it should be short, lowercase, and use underscores for multi-word names
- Reserved names that cannot be used (the hardcoded list from Change 5)
- Names matching existing base view filenames are also rejected at activation
- A good plugin name is unique enough that it won't collide with system paths or other plugins

**3. Add a "Plugin URL Namespace" section** after the naming section:

Content to cover:
- Every plugin owns `/{pluginname}/*`, `/profile/{pluginname}/*`, and `/admin/{pluginname}/*`
- Views auto-discover: create a file, the URL works immediately
- The `index.php` convention for landing pages (`/pluginname` loads `views/index.php`, `/profile/pluginname` loads `views/profile/index.php`)
- Internal links must always use namespaced URLs (`/profile/myplugin/devices`, not `/profile/devices`)
- Plugin-as-theme sites get clean URLs without the plugin name automatically via theme resolution
- Explicit routes in serve.php are only needed for model binding, permissions, feature flags, or other route config — and must stay within the namespace
- `plugin_specify` is not used — the plugin name is extracted from the URL automatically

**4. Update the "Required Plugin Structure"** (around line 109) to show the views directory organized by URL context:

```
/plugins/my-plugin/
├── plugin.json
├── serve.php                    # Only for routes needing model/permission config
├── views/
│   ├── index.php                # /myplugin (landing page)
│   ├── pricing.php              # /myplugin/pricing
│   ├── profile/
│   │   ├── index.php            # /profile/myplugin
│   │   ├── devices.php          # /profile/myplugin/devices
│   │   └── settings.php         # /profile/myplugin/settings
│   └── admin/
│       ├── index.php            # /admin/myplugin
│       └── config.php           # /admin/myplugin/config
├── logic/
├── data/
├── ajax/
├── assets/
├── includes/
└── migrations/
```

**5. Remove references to `plugin_specify`** from the "Plugin-Integrated Theme Routing" example (around line 526) and anywhere else it appears.

### `specs/implemented/allow_theme_plugins.md`

Remove the reference to "Plugin-declared route prefixes in plugin.json" from the Plugin Detection section (line 36).

### `CLAUDE.md`

Update the "Adding New Features" workflow to mention that plugin views auto-discover under namespaced URLs. Remove any references to `plugin_specify`.

---

## Files to modify

| File | Change |
|------|--------|
| `includes/RouteHelper.php` | View fallback: namespaced plugin auto-discovery (Change 1) |
| `includes/RouteHelper.php` | `handleDynamicRoute`: extract plugin name from URL pattern (Change 2) |
| `includes/RouteHelper.php` | `loadPluginRoutes`: enforce namespace, drop out-of-namespace routes (Change 3) |
| `includes/RouteHelper.php` | Remove all `plugin_specify` references (Change 4) |
| `includes/PluginManager.php` | Reserved plugin name check at activation (Change 5) |
| `includes/RouteHelper.php` | `detectPluginByRoute`: remove `routes_prefix` block (Change 6) |
| `specs/implemented/allow_theme_plugins.md` | Remove `routes_prefix` reference |
| `docs/plugin_developer_guide.md` | Plugin naming, URL namespace, remove `plugin_specify`, updated examples |
| `CLAUDE.md` | Update plugin development workflow notes |

---

## Out of scope

- Changing `PathHelper::getThemeFilePath()` internals
- Auto-discovery for logic files (views load their own logic via `getThemeFilePath` with explicit plugin name)
- AJAX route namespacing (existing mechanism, separate concern)
- Theme-to-plugin dependency declarations (see [separate spec](theme_plugin_dependencies.md))
- Redirects from old non-namespaced URLs to new namespaced URLs (handled naturally by plugin-as-theme sites; other sites can add redirects in main serve.php if needed)
