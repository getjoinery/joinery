# Homepage Route — Blank Response When `alternate_*_homepage` Points to a URL Path

## Summary

The `/` route handler in `serve.php` silently emits an empty 200 response when the `alternate_loggedin_homepage` or `alternate_homepage` setting holds a value that looks like a route path (e.g. `/profile/dns_filtering/devices`) rather than a file path. This was reproduced on scrolldaddy.app where logged-in admins saw a blank homepage; the same bug exists in the platform serve.php and applies to every site that uses these settings.

## Reported Symptoms

- `https://scrolldaddy.app/` rendered as a completely blank page in Brave (normal mode).
- Worked correctly in Brave private mode.
- "Worked correctly" after deleting cookies via `brave://settings/content/siteDetails`.
- Recurred after some time / next session.
- Response was: `HTTP/2 200`, `content-type: text/html; charset=UTF-8`, body completely empty (`view-source:` showed nothing).
- DevTools → Network: single document request, status 200, ~0.3 KB transferred.
- DevTools → Application → Cookies (on HTTPS): no cookies visible.
- DevTools → Service Workers: none registered.
- Brave's "Site Details" page showed 2 cookies grouped under `http://scrolldaddy.app/`, totaling 0 B.

## Troubleshooting Path (What Was Ruled Out)

These were investigated and ruled out before the root cause was found. Documenting them so future maintainers don't repeat the same chase:

1. **Cloudflare worker / edge behavior** — initially suspected because of the CF proxy in front; ruled out by the user.
2. **Browser cache** — ruled out (page was uncached, `cache-control: no-store, no-cache, must-revalidate`).
3. **HSTS missing → cookies stranded on HTTP origin** — plausible-sounding theory because `Strict-Transport-Security` is not sent. But Brave's UI grouping was misleading; the cookies that actually mattered were sent on HTTPS, not orphaned on HTTP. (See "Related — HSTS gap" below; still worth fixing, but unrelated to the blank page.)
4. **Cloudflare bot cookies (`__cf_bm`, `_cfuvid`)** — guessed based on the Brave UI grouping; were not the actual culprits.
5. **`StaticPageCache` poisoning** — suspected because the homepage carries a `<!-- Cached: -->` marker; ruled out by disabling local and CF caching and reproducing the blank response anyway.
6. **`PHP Warning: Undefined variable $is_valid_page`** in `theme/scrolldaddy/views/index.php` line 18 — present in error.log on every request, but a warning does not produce a blank body; this is unrelated noise.
7. **Service worker serving a stale blank** — ruled out (no SW registered).

## Root Cause

`public_html/serve.php` — the `/` route handler:

```php
'/' => function($params, $settings, $session, $template_directory) {
    $alternate_page = $settings->get_setting('alternate_loggedin_homepage');
    if($alternate_page && $session->is_logged_in()){
        $page_pieces = explode('/', $alternate_page);
        if($page_pieces[1] == 'blog'){
            ...
        } else if($page_pieces[1] == 'page'){
            ...
        } else {
            $template_file = $template_directory.$alternate_page;
            $base_file = PathHelper::getRootDir().$alternate_page;
        }
    } else if($alternate_page = $settings->get_setting('alternate_homepage')) {
        // identical structure for not-logged-in
    } else {
        $template_file = $template_directory.'/views/index.php';
        $base_file = PathHelper::getIncludePath('views/index.php');
    }

    if(file_exists($template_file)){
        require_once($template_file);
    } else if(file_exists($base_file)){
        require_once($base_file);
    }
    return true; // Handled
},
```

The fall-through `else` branches (lines 187–189 and 204–206) treat the setting value as a **file path** and try to `require_once` it. If the value is a **route path** (e.g. `/profile/dns_filtering/devices`) the file doesn't exist, both `file_exists()` checks at the bottom fail, **no output is produced, but the handler returns `true`**, so the front controller treats the request as successfully handled and sends a 200 with an empty body.

On scrolldaddy.app production:
- `alternate_loggedin_homepage` = `/profile/dns_filtering/devices` (a route)
- `alternate_homepage` = `` (empty)

Hence: logged-in users → blank, anonymous users → fine. This exactly matches the reported symptoms (Brave normal-mode with a session cookie → blank; private mode → fine; deleting cookies logs you out → fine).

The misleading clue was the Brave "Site Details" page grouping cookies under `http://`; the actual cookies in play were the standard `PHPSESSID` and `tt=` (remember-me) on the HTTPS origin. Brave's UI grouping caused the diagnosis to chase HSTS / CF cookies.

## Proposed Fix

Two issues to address; both belong in the platform `serve.php`, not in any per-site code.

### Fix 1 (primary): Route-aware homepage handling

The handler conflates two different kinds of "alternate" values:

- A **file include** under the views/theme tree (legacy use case).
- A **URL route** that should be routed by the front controller.

Resolve this at the right layer rather than guessing. Recommended behavior:

1. If `$alternate_page` starts with `/` and is **not** one of the special-case prefixes (`/blog`, `/page/...`) that the handler already understands, **redirect** to it with `302 Location: $alternate_page`. The browser then re-enters the front controller through the normal route resolution, which is exactly what was intended.
2. Keep the existing special-case branches for `/blog` and `/page/...` because they wire up specific view files and data.
3. If neither a special case nor a redirect applies and neither `$template_file` nor `$base_file` exists, fall back to the default `views/index.php` rather than emitting an empty body.

Sketch:

```php
'/' => function($params, $settings, $session, $template_directory) {
    $alternate_page = $session->is_logged_in()
        ? $settings->get_setting('alternate_loggedin_homepage')
        : '';
    if(!$alternate_page){
        $alternate_page = $settings->get_setting('alternate_homepage');
    }

    if($alternate_page){
        $page_pieces = explode('/', $alternate_page);
        if(($page_pieces[1] ?? '') === 'blog'){
            $template_file = $template_directory.'/views/blog.php';
            $base_file = PathHelper::getIncludePath('views/blog.php');
        } else if(($page_pieces[1] ?? '') === 'page'){
            if($session->is_logged_in() || $settings->get_setting('page_contents_active')){
                require_once(PathHelper::getIncludePath('data/pages_class.php'));
                $page = Page::get_by_link($page_pieces[2] ?? '', true);
                $template_file = $template_directory.'/views/page.php';
                $base_file = PathHelper::getIncludePath('views/page.php');
            }
        } else {
            // Treat any other "/something" value as a route, not a file path:
            // bounce through the front controller via redirect.
            header('Location: '.$alternate_page, true, 302);
            return true;
        }
    }

    // Fallback: default homepage view, never blank.
    if(empty($template_file) || !file_exists($template_file)){
        $template_file = $template_directory.'/views/index.php';
    }
    if(empty($base_file) || !file_exists($base_file)){
        $base_file = PathHelper::getIncludePath('views/index.php');
    }

    if(file_exists($template_file)){
        require_once($template_file);
    } else if(file_exists($base_file)){
        require_once($base_file);
    }
    return true;
},
```

Notes:
- Eliminates the silent-blank failure mode entirely: if the alternate value doesn't resolve, the default index.php is loaded.
- Treats route-shaped values as routes (redirect), which is what an admin entering `/profile/dns_filtering/devices` into the alternate-homepage setting actually meant.
- Collapses the duplicated logged-in / not-logged-in branches into one path.

### Fix 2 (defense in depth): Front controller should not return 200 with empty body

Independently of the homepage handler, the front controller should treat a route handler that returns `true` but produced no output as an error condition (log it, fall through to the default view or 500, never emit a silent blank). This catches future bugs of the same shape.

Implementation idea: wrap route-handler invocations in output buffering at the front-controller layer; if a handler returns `true` and the buffer is empty (and no `Location:` header was sent), treat as a handler bug — log and render a fallback page rather than flushing the empty buffer.

This belongs in `serve.php`'s route-dispatch loop / `RouteHelper`.

### Fix 3 (related but separate): HSTS

While investigating, two facts came up that are worth addressing independently of the blank-page bug:

- `includes/PublicPageBase.php:65` and `:674` set `Strict-Transport-Security: max-age=86400` (24 hours) — way too short to be effective. Industry standard is 6–12 months. Browsers forget the policy after one day of inactivity.
- The `enable_hsts` setting defaults to off; the admin UI description encodes the 24-hour weakness as if it were a feature.

Recommended:
- Bump max-age to `31536000` (1 year) in both call sites.
- Add `enable_hsts` to `settings.json` with default `true` so new installs are secure by default.
- Update the admin UI description to drop the "24 hours" claim.
- Operators can opt out for dev environments by setting the value to `false`.

This is a separate fix in the same touch zone; doing it here keeps the security-headers code consistent.

## Verification Plan

1. Reproduce on scrolldaddy.app: visit `/` while logged in → expect blank (current behavior).
2. Apply Fix 1 to `public_html/serve.php` (platform). Deploy.
3. Visit `/` logged in → expect a 302 redirect to `/profile/dns_filtering/devices`, which then renders normally via that route's own handler.
4. Visit `/` not logged in → expect normal `views/index.php` render (regression check).
5. Set `alternate_homepage` to a nonexistent route like `/no/such/path` → expect the default index.php to render (Fix 1 fallback) **and** an error logged (Fix 2).
6. Set `alternate_homepage` to `/blog` → expect the blog view to render (regression check on special-case branch).
7. Set `alternate_homepage` to `/page/some-slug` → expect the corresponding Page contents to render (regression check on special-case branch).
8. Confirm `Strict-Transport-Security: max-age=31536000; includeSubDomains` is present on all HTTPS responses from a site with `enable_hsts` on.

## Files Touched

- `public_html/serve.php` — `/` route handler (Fix 1); route dispatch loop (Fix 2).
- `public_html/includes/PublicPageBase.php` — two HSTS header lines (Fix 3).
- `public_html/settings.json` — add `enable_hsts: true` default (Fix 3).
- `public_html/adm/admin_settings.php` — update HSTS setting description (Fix 3).

## Out of Scope

- Per-site setting cleanup. After Fix 1 lands, `alternate_loggedin_homepage = /profile/dns_filtering/devices` on scrolldaddy will work correctly via redirect; no data change is required. Operators may still prefer to point the setting at an actual view file in some cases, which continues to work as before.
- The unrelated `Undefined variable $is_valid_page` warning in `theme/scrolldaddy/views/index.php:18`. Logged here so future log-cleanup work catches it; not part of this fix.
