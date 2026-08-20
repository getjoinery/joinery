# Routing: a render-time exception is an error, not a 404

**Status:** BUILT and live-verified 2026-08-20 — all five proof gates pass (results under Proof)
**Date:** 2026-08-20
**Origin:** A user pressed the setup wizard's test-send button; the send path
threw, and the site answered with its 404 page. The wizard now guards its own
send (setup_logic 1.3), but the mask that turned the throw into a 404 is
platform-wide and remains.

## Problem

`RouteHelper::processRoutes()` step 6 — the view-directory fallback that serves
every auto-discovered page — wraps the view render in a catch that answers any
`Exception` with the themed 404 page:

```php
try {
    if ($view_full_path) {
        require_once($view_full_path);
        // ... static-cache save ...
        exit();
    }
} catch (Exception $e) {
    error_log("Asset/view not found: " . $request_path . " - " . $e->getMessage());
    LibraryFunctions::display_404_page();
}
```

(RouteHelper.php:1431–1450 as of this writing.)

Three things are wrong with it:

1. **It catches only the wrong thing.** `$view_full_path` is set only when the
   file exists (`getThemeFilePath` / `file_exists` checks above it), so "view
   file missing" never reaches the `require_once` — that case falls through to
   the genuine 404 at step 8. The only thing this catch can ever catch is an
   exception thrown *by the view or the logic it calls while executing*. The
   log label "Asset/view not found" is a lie about every line it ever writes.

2. **It masks errors as 404s and defeats the error-handler contract.** The
   most common victim is `process_logic()`, which throws
   `SystemDisplayableError` for an error-only `LogicResult` — an exception
   whose entire purpose is to carry a message the user should read.
   `ErrorManager` is registered earlier in the same function and its
   `WebErrorHandler` / `AjaxErrorHandler` honor `BaseException::shouldDisplay()`
   and the four `Displayable*` marker interfaces — but this catch fires first,
   so on every fallback-routed page the handler never runs and the user gets
   "page not found" for a page that plainly exists.

3. **It is inconsistent with everything around it.** Configured routes
   (`handleDynamicRoute`, admin routes, plugin routes) have no such catch —
   an exception there reaches `ErrorManager` and renders the proper error
   page. So does an `Error` (TypeError, undefined function) thrown inside a
   fallback view, since the catch names `Exception` only. The same defect
   produces a correct error page on `/admin/anything` and a fake 404 on any
   auto-discovered view.

## Behavior after the fix

One rule, every route type: **an exception thrown while a page executes
reaches the registered error handler; a 404 is answered only when the routing
layer could not resolve the request or the page explicitly says "not found".**

Concretely, on a fallback-routed page:

| Situation | Today | After |
|---|---|---|
| View file does not exist | 404 (step 8) | 404 — unchanged |
| View/logic throws a `Displayable*` exception (incl. `SystemDisplayableError` from `process_logic`) | themed 404 page | error page showing the exception's message, generic title, non-404 status — identical to a configured route |
| View/logic throws any other `Exception` | themed 404 page | generic error page via `WebErrorHandler` (debug detail when debug is on), logged with the real message — identical to a configured route |
| View/logic throws an `Error` | error page (already propagates) | unchanged |
| View calls `LibraryFunctions::display_404_page()` itself | 404 | 404 — unchanged; this stays the sanctioned way for a page to declare not-found (bad slug, missing record) |

## Implementation

1. **Narrow the catch to buffer hygiene and re-throw.** Replace the
   catch-and-404 with:

   ```php
   if ($view_full_path) {
       try {
           require_once($view_full_path);
       } catch (\Throwable $e) {
           // A throw mid-render leaves partial page output in the static-cache
           // buffer. Discard it — an error response must never be cached, and
           // the URL must not be marked nostatic for having errored once.
           if ($cache_buffer_started) {
               ob_end_clean();
           }
           throw $e;   // one behavior for every route type: ErrorManager
       }
       // ... existing cache save + exit(), unchanged, success path only ...
   }
   ```

   `Throwable`, not `Exception`: the buffer cleanup is owed to an `Error` too.
   Re-throwing hands the exception to `set_exception_handler` →
   `ErrorManager::handleException`, the same path configured routes use.

2. **Cache safety is part of the contract.** Whatever mechanism saves the
   static cache (the inline save in this block, and any shutdown-registered
   save — the comment at the end of `processRoutes` claims one exists; verify
   whether it still does), an errored request must produce **no cache entry
   and no nostatic mark**. The nostatic 1%-retry comment already admits error
   states were being marked; stop creating them from throws.

3. **Fix nothing else in the block.** The `$is_valid_page` flag, plugin
   namespace resolution, and step 7/8 fallthrough stay as they are.

## Inventory before landing (the risk)

Any fallback-routed view that today *relies* on throwing to get a 404 —
loading a record from a slug and letting the failure escape — will start
rendering an error page instead. Before the change lands:

- Sweep `views/`, `theme/*/views/`, and `plugins/*/views/` for pages that can
  throw on missing/invalid input without catching, and confirm each either
  already calls `display_404_page()` for its not-found case or is changed to.
  The sweep is bounded: only pages served by fallback routing (no serve.php
  entry) and only throw sites reachable from request input.
- `SystemBase` does not throw for a missing record load (an unloaded object
  comes back empty), so the common slug-page pattern is not implicated;
  the sweep is looking for the exceptions to that.

If the sweep finds pages that genuinely want "throw means 404", introduce a
dedicated `PageNotFoundException` (a `SystemBaseException` subclass) that
`WebErrorHandler` maps to the themed 404 page with status 404, and convert
those pages to throw it explicitly. Do not add the class speculatively — only
if the sweep produces a customer for it.

## Proof

1. **Displayable reaches the user.** A fallback-routed view whose logic
   returns an error-only `LogicResult` (driving `process_logic`'s
   `SystemDisplayableError` throw) renders the error message with a non-404
   status. Exercised over HTTP against dev — the wizard's email step before
   setup_logic 1.3 was the live reproduction; any equivalent page works.
2. **Generic exception gets the error page.** A view that throws a plain
   `Exception` answers with the `WebErrorHandler` page (debug detail on dev),
   not the 404 page, and the error log carries the real message with no
   "Asset/view not found" mislabel.
3. **Missing view still 404s.** An unrouted URL with no view file answers 404
   exactly as before.
4. **No cache poisoning.** After forcing a throw on a cacheable (logged-out
   GET) fallback page: no cache entry exists for the URL, it is not marked
   nostatic, and the next healthy request renders and caches normally.
5. **Suite.** Add a unit test pinning the catch's shape (buffer-clean +
   re-throw, no `display_404_page` call in the fallback catch), and a
   functional test for 1–3 if a reasonable harness exists; otherwise 1–4 are
   verified live on dev and recorded here.

### Results (2026-08-20, dev, over HTTPS, logged out)

Probe views placed in `views/` for the run and removed after.

1. A view throwing `SystemDisplayableError` answered **500** with the
   exception's message in the body. PASS.
2. A view throwing a plain `Exception` answered **500** with the
   `WebErrorHandler` page (message visible because dev runs debug). PASS.
3. An unrouted URL with no view file answered **404** with the themed 404
   page. PASS.
4. After both erroring logged-out GETs, `cache/static_pages/index.json`
   carried zero entries for the probe URLs — no cache entry and no nostatic
   mark; `/` and `/events` still answered 200. PASS.
5. `tests/unit/routing_render_exceptions_test.php` (safe tier, 10 checks)
   pins the block's shape: Throwable catch, buffer discard, re-throw, no
   `display_404_page` and no cache write on the error path, cache save intact
   on the success path. PASS.

The sweep found no fallback view relying on throw-means-404 (five of six
`throw new` hits in view files are JavaScript; the sixth throws
`SystemDisplayableError` wanting its message shown), so `PageNotFoundException`
was not built — it has no customer.
