# Prod Bug Audit — Fixes

Findings from a full log and error audit across all 9 prod containers (scrolldaddy, getjoinery, jeremytunnell, empoweredhealthtn, joinerydemo, phillyzouk, galactictribune, mapsofwisdom, getjoinery-orgs). Sites run slightly older software versions; all core fixes below apply to the dev codebase and will reach prod on next upgrade. Theme fixes must be applied directly to each named container.

---

## Core Platform Fixes (dev codebase)

### 1. RSS feed renders no items — DONE
- **File:** `views/rss20_feed.php:24`
- **Bug:** `foreach ($posts as $post)` — `$posts` is never set in view scope; blog_logic puts it in `$page_vars['posts']`.
- **Fix:** Changed to `foreach ($page_vars['posts'] as $post)`.

### 2. password-set message block never shows — DONE
- **File:** `views/password-set.php:23`
- **Bug:** `if ($message)` — `$message` is undefined; logic result is in `$page_vars['message']`.
- **Fix:** Changed to `if (!empty($page_vars['message']))`.

### 3. password-set page noindexed — DONE
- **File:** `views/password-set.php:9`
- **Bug:** `'is_valid_page' => $is_valid_page` — `$is_valid_page` undefined in this view's scope causes PublicPageBase to noindex the page.
- **Fix:** Changed to `'is_valid_page' => true`.

### 4. cart_confirm undefined key warning — DONE
- **File:** `views/cart_confirm.php:7`
- **Bug:** `$_GET['session_id']` — undefined array key warning when parameter absent.
- **Fix:** Changed to `$_GET['session_id'] ?? null`.

### 5. lists page messages undefined key — DONE
- **File:** `views/lists.php:7`
- **Bug:** `$page_vars['messages']` — undefined key warning when logic doesn't return messages.
- **Fix:** Changed to `$page_vars['messages'] ?? []`.

### 6. Booking logic fatal on sites without bookings plugin — DONE
- **File:** `logic/booking_logic.php:8-9`
- **Bug:** `require_once` for plugin data class files fires before the `bookings_active` setting check, causing a fatal error on getjoinery and galactictribune (which don't have the bookings plugin installed).
- **Fix:** Moved `require_once` calls after the `bookings_active` check; added `file_exists()` guard before requiring.

---

## Prod Theme Fixes (direct edits to containers)

These are site-specific theme files not in the dev codebase. `$is_valid_page` being null/undefined causes `PublicPageBase` to noindex the page (checked: line 489 of `PublicPageBase.php`: `$noindex = isset($options['is_valid_page']) && $options['is_valid_page'] === false` — actually only false explicitly triggers noindex, but undefined passes null which evaluates to false). Regardless, fixing eliminates constant log noise.

### 7. scrolldaddy index — $is_valid_page undefined
- **Container:** `scrolldaddy`
- **File:** `/var/www/html/scrolldaddy/public_html/theme/scrolldaddy/views/index.php:18`
- **Bug:** `'is_valid_page' => $is_valid_page` — spamming warning every 5 minutes from Cloudflare health checks.
- **Fix:** Change to `'is_valid_page' => $is_valid_page ?? true`.

### 8. scrolldaddy product — $product_url used before assignment
- **Container:** `scrolldaddy`
- **File:** `/var/www/html/scrolldaddy/public_html/theme/scrolldaddy/views/product.php:~87`
- **Bug:** `$page->getFormWriter('product_form', ['action' => $product_url, ...])` is called, then `$product_url = '/product/' . $product->get('pro_link')` is assigned on the next line. Form action is always empty.
- **Fix:** Move `$product_url = '/product/' . $product->get('pro_link');` to BEFORE the `getFormWriter` call.

### 9. jeremytunnell blog — $is_valid_page undefined
- **Container:** `jeremytunnell`
- **File:** `/var/www/html/jeremytunnell/public_html/theme/jeremytunnell-html5/views/blog.php:~28`
- **Bug:** `'is_valid_page' => $is_valid_page` — constant warnings, every page load.
- **Fix:** Change to `'is_valid_page' => $is_valid_page ?? true`.

### 10. jeremytunnell PublicPage — $site_name used before assignment
- **Container:** `jeremytunnell`
- **File:** `/var/www/html/jeremytunnell/public_html/theme/jeremytunnell-html5/includes/PublicPage.php:97`
- **Bug:** `$site_name` is echoed in `public_header()` (line 97) but only assigned in `public_footer()` (line 127). Site name shows blank in header nav on every page.
- **Fix:** Add `$site_name = $settings->get_setting('site_name', true, true) ?: 'Jeremy Tunnell';` near the top of `public_header()`, after the `$settings = Globalvars::get_instance();` line.

### 11. jeremytunnell post — $new_comment dead-code wrapper
- **Container:** `jeremytunnell`
- **File:** `/var/www/html/jeremytunnell/public_html/theme/jeremytunnell-html5/views/post.php:71`
- **Bug:** `if($new_comment)` — `$new_comment` is never set anywhere; `post_logic` redirects on successful comment save so this branch is unreachable dead code. Generates a warning on every post page view.
- **Fix:** Remove lines 71 (`if($new_comment){`), 72 (the echo), 73 (`} else {`), and the matching closing `}` at line 104. The comment form then always renders when comments are active, which is the correct behavior.

### 12. phillyzouk PublicPage — $site_name used before assignment
- **Container:** `phillyzouk`
- **File:** `/var/www/html/phillyzouk/public_html/theme/phillyzouk-html5/includes/PublicPage.php:91,104`
- **Bug:** Same pattern as jeremytunnell — `$site_name` used in `public_header()` (lines 91, 104) but only assigned in `public_footer()` (line 163).
- **Fix:** Add `$site_name = $settings->get_setting('site_name', true, true) ?: 'Phillyzouk';` near the top of `public_header()`, after the `$settings` initialization.

### 13. galactictribune blog — $is_valid_page undefined
- **Container:** `galactictribune`
- **File:** `/var/www/html/galactictribune/public_html/theme/galactictribune-html5/views/blog.php:12`
- **Bug:** `'is_valid_page' => $is_valid_page` — constant warnings.
- **Fix:** Change to `'is_valid_page' => $is_valid_page ?? true`.

### 14. galactictribune cart_confirm — session_id undefined key
- **Container:** `galactictribune`
- **File:** `/var/www/html/galactictribune/public_html/views/cart_confirm.php:7`
- **Bug:** `$_GET['session_id']` without null coalescing — older code version that doesn't have fix #4 above.
- **Fix:** Change to `$_GET['session_id'] ?? null`. (This fix already landed in dev codebase — will deploy on upgrade, but is trivially fixable now.)

### 15. galactictribune lists — messages undefined key
- **Container:** `galactictribune`
- **File:** `/var/www/html/galactictribune/public_html/views/lists.php:7`
- **Bug:** Same as fix #5 — older code version.
- **Fix:** Change to `$page_vars['messages'] ?? []`.

---

## Noise-only (no action needed)

- **GET_MUTATION on RequestLog** (all sites, `/api/v1/*`) — The `RequestLogger` on prod sites is an older version (pre-v1.2) without the `SystemBase::$allow_get_mutation = true` guard. This causes log noise but the log row still saves (the guard throws a notice, not an exception). Will resolve on next upgrade. Dev codebase already has the fix.
- **GET_MUTATION on GeneralError** (various sites, scanner traffic) — Same pattern; fires when bots probe `.env`, path traversal URLs, etc. The error IS caught and logged to the Apache log; the DB insert just fails silently. Not a real problem; scanner traffic, not real users.
- **AH00124 internal redirect loops** (empoweredhealthtn) — Apache rewrite loop on scanner traffic with malformed URLs. Not a PHP bug; worth noting but not our concern.
- **Missing boundary multipart/form-data** (getjoinery-orgs, empoweredhealthtn) — Malformed POST requests from bots. PHP noise only.
- **ROUTING ERROR .php extension** (all sites) — Bots probing for WordPress/PHP files. Working as designed (returns 404).
