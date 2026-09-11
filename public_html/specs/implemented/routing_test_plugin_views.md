# Spec: Routing Test — Plugin View Auto-Discovery Coverage

**Status:** Pending implementation
**Area:** Tests (`tests/integration/routing_test.php`)
**Related:** [Plugin View Auto-Discovery](implemented/plugin_view_auto_discovery.md)

---

## Problem

The routing integration test (`tests/integration/routing_test.php`) has no coverage for plugin view auto-discovery. Section 5 (`testPluginFiles`) is a stub that only tested plugin *assets* — it has zero assertions. The plugin view auto-discovery feature (implemented in RouteHelper step 6) is completely untested.

This gap caused a production bug: `/profile/scrolldaddy/test` showed the wrong page because the dynamic route handler's `else { show404() }` block prevented fallthrough to step 6. The bug was only caught by manual testing on production.

---

## Goal

Add plugin view routing tests to the existing `routing_test.php` that verify:

1. Plugin-namespaced URLs resolve correctly (200 for views that exist)
2. Plugin profile-namespaced URLs resolve correctly
3. Non-existent views within real plugins return 404
4. Non-existent plugin names return 404
5. Dynamic route fallthrough to step 6 works (the exact bug that hit production)

---

## Design

### Approach: extend, don't replace

The existing `HttpRoutingTestRunner` class and `HttpTester` utility work well. Add a new `testPluginViews()` method and call it from `runAllTests()`. This is the same pattern used by all other test sections.

### New method: `testPluginViews()`

**Discovery-based, not hardcoded.** The method should discover active plugins and their view files dynamically, the same way `testThemeViews()` discovers theme views by checking the filesystem.

#### Test cases to generate

For each active plugin that has a `views/` directory:

**URL-to-file resolution rule:** When the remaining path after the plugin namespace segment is empty, it defaults to `index`. So `/{plugin}` resolves to `views/index.php`, and `/profile/{plugin}` resolves to `views/profile/index.php` — NOT `views/profile/profile.php`. These are different files.

| What to test | URL pattern | Resolves to file | Expected | Why |
|---|---|---|---|---|
| Plugin index page | `/{plugin}` | `views/index.php` | 200 if file exists | Empty remaining defaults to `index` |
| Plugin root view | `/{plugin}/{view}` | `views/{view}.php` | 200 if file exists | Direct plugin view |
| Plugin profile index | `/profile/{plugin}` | `views/profile/index.php` | 200 if file exists, else 404 | Empty remaining defaults to `index` |
| Plugin profile view | `/profile/{plugin}/{view}` | `views/profile/{view}.php` | 200 or auth redirect if file exists | Profile sub-view |
| Non-existent plugin view | `/{plugin}/definitely-fake-view-99999` | (none) | 404 | 404 within real plugin |
| Non-existent plugin profile view | `/profile/{plugin}/definitely-fake-view-99999` | (none) | 404 | 404 within real plugin profile namespace |

**Note:** `/profile/{plugin}` and `/profile/{plugin}/profile` are distinct URLs. The first looks for `index.php`, the second looks for `profile.php`. The test must check the correct file for each URL.

Static negative cases (no discovery needed):

| What to test | URL | Expected | Why |
|---|---|---|---|
| Fake plugin name | `/definitely-fake-plugin-99999/anything` | 404 | Non-existent plugin |
| Fake plugin profile | `/profile/definitely-fake-plugin-99999/anything` | 404 | Non-existent plugin in profile namespace |

#### View sampling strategy

Don't test every view — plugins can have 20+ views and many require authentication or specific parameters. Use a **whitelist** approach: only test views known to be safe without parameters or session state.

1. **Root views (no auth, no params):** Whitelist of safe view basenames to test: `index`, `pricing`, `forms_example`. For each, check if `plugins/{plugin}/views/{name}.php` exists on disk — if so, test `/{plugin}/{name}` expecting 200. Skip views that redirect (`login`, `logout`), require session state (`cart`, `cart_confirm`), or require URL/query parameters (`product`, `items`).
2. **Profile views (expect auth redirect, no params):** Whitelist of safe profile view basenames: `profile`, `devices`, `test`. For each, check if `plugins/{plugin}/views/profile/{name}.php` exists — if so, test `/profile/{plugin}/{name}` expecting `[200, 301, 302]` (redirect to login is correct when unauthenticated). Skip views that require query parameters (`device_edit`, `device_delete`, `filters_edit`, `scheduled_block_edit`, etc.).
3. **Admin views:** Skip. Already covered by `testAdminAccess()` section and requires authentication.

#### Auth handling for profile views

Profile views behind permission checks will redirect to login when the test runner is unauthenticated. This is correct behavior. The test should accept `[200, 301, 302]` for profile namespace URLs, matching the pattern already used in `testAdminAccess()`.

### Integration into runAllTests()

Add the call after `testPluginFiles()`:

```php
$this->testPluginFiles();
$this->testPluginViews();  // New
$this->testAdminAccess();
```

Assign it the next section number (currently 5 is plugin files, admin is numbered 7 — use section 6).

### Plugin discovery

Use `PluginHelper::getActivePlugins()` to get active plugins. For each, check if `plugins/{name}/views/` exists — if not, skip (plugin has no views). This mirrors how the routing system itself discovers plugin views.

---

## Scope

### In scope
- New `testPluginViews()` method in `HttpRoutingTestRunner`
- Dynamic discovery of active plugins and their view files
- Positive tests (existing views return 200 or auth redirect)
- Negative tests (non-existent views/plugins return 404)
- Calling the new method from `runAllTests()`

### Out of scope
- Theme override of plugin views (hard to test without a known fixture — would need a test-only theme override file)
- Plugin admin view testing (already partially covered by `testAdminAccess`)
- Plugin asset testing (separate concern — `testPluginFiles` stub can be improved separately)
- Plugin route testing for serve.php-registered routes (model routes, feature flags — these are dynamic route tests, not view fallback tests)
- Any changes to routing code itself

---

## Implementation notes

- The test file uses `$_SERVER['DOCUMENT_ROOT']` for filesystem checks (e.g., `$_SERVER['DOCUMENT_ROOT'] . "/views" . $path . ".php"`). Follow the same pattern for plugin view file existence checks.
- `PluginHelper` may already be loaded by the time the test runs (PathHelper loads it). If not, use `require_once(PathHelper::getIncludePath('includes/PluginHelper.php'))`.
- Some plugin views may produce errors when loaded outside their expected context (missing query params, etc.). The HTTP status code check is sufficient — we're not inspecting response bodies.
- The whitelist approach for view sampling (see "View sampling strategy") avoids views that require parameters. If a new plugin is added with different safe views, the whitelists may need updating — but false negatives (skipping a testable view) are preferable to false positives (testing a view that errors without params).
