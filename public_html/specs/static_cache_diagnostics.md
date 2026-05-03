# Spec: Static Page Cache Diagnostics

## Problem

When a page is cached and the underlying content changes (e.g. `alternate_homepage` is cleared
after being set), the stale cache is silently served to all visitors. There is no signal in the
HTTP response, in the rendered HTML, or in the admin UI that a cached version is being served,
making the root cause nearly impossible to diagnose without reading the source code.

This was discovered when `getjoinery.com/` served an "About" CMS page despite the homepage route
being set up to render a marketing view — a stale `cache/static_pages/index.html` was cached
while `alternate_homepage` pointed to `/page/about`, and that setting was later cleared without
invalidating the cache.

---

## Goals

1. Make cache hits visible in browser devtools via HTTP response headers.
2. Make cache creation time visible in page source via an enriched HTML comment.
3. Automatically invalidate the homepage cache when homepage-routing settings change.
4. Flush the entire cache when the active theme changes.

---

## Change 1: HTTP Headers on Cache Hit

**File:** `includes/RouteHelper.php` — the block around line 1107 that serves a cached file.

**Current:**
```php
header('Content-Type: text/html; charset=utf-8');
header('Content-Length: ' . filesize($cache_result));
header('X-Cache: HIT');
readfile($cache_result);
exit();
```

**Change to:**
```php
header('Content-Type: text/html; charset=utf-8');
header('Content-Length: ' . filesize($cache_result));
header('X-Cache: HIT');
$cache_meta = StaticPageCache::getCacheMetadata($request_path, $cache_params);
if ($cache_meta) {
    header('X-Cache-Created: ' . gmdate('c', $cache_meta['time']));
    header('X-Cache-Age: ' . (time() - $cache_meta['time']));
}
readfile($cache_result);
exit();
```

**New method on `StaticPageCache`:** Add `getCacheMetadata($url, $params)` that returns the index
entry array (with `time`, `url`, `status`, `extension`) for a given URL, or `null` if not in the
index. This is a pure read — no file I/O beyond what `loadIndex()` already does.

**Result:** Any developer opening devtools will immediately see:
```
X-Cache: HIT
X-Cache-Created: 2026-05-03T01:29:07+00:00
X-Cache-Age: 43206
```

---

## Change 2: Enriched HTML Comment in Cached Files

**File:** `includes/StaticPageCache.php` — `createCache()` method, line ~243.

**Current:**
```php
$comment = "\n    <!-- Cached: {$url_with_params} -->";
```

**Change to:**
```php
$created_iso = gmdate('c');
$comment = "\n    <!-- Cached: {$url_with_params} | Created: {$created_iso} -->";
```

**Result:** Anyone doing View Source sees:
```html
<!-- Cached: / | Created: 2026-05-03T01:29:07+00:00 -->
```

No external dependency. Zero overhead. Survives CDN stripping of custom headers.

---

## Change 3: Invalidate Homepage Cache on Settings Change

**File:** `adm/logic/admin_settings_logic.php` — after the settings save loop, before the redirect
(currently around line 128).

**Change:** After all settings are saved, check whether any homepage-routing setting was in the
submitted POST. If so, invalidate `/`.

```php
// Invalidate homepage cache if routing-affecting settings changed
$homepage_settings = ['alternate_homepage', 'alternate_loggedin_homepage'];
foreach ($homepage_settings as $key) {
    if (isset($post[$key])) {
        require_once(PathHelper::getIncludePath('includes/StaticPageCache.php'));
        StaticPageCache::invalidateUrl('/');
        break;
    }
}

return LogicResult::redirect('/admin/admin_settings');
```

`StaticPageCache::invalidateUrl()` already exists — it removes the cache file and clears the
index entry for that URL.

---

## Change 4: Flush All Cache on Theme Change

**File:** `adm/logic/admin_settings_logic.php` — same location as Change 3.

When `active_theme` is saved, every cached page is potentially stale (different CSS, different
header/footer markup). Flush the entire cache.

```php
if (isset($post['active_theme'])) {
    require_once(PathHelper::getIncludePath('includes/StaticPageCache.php'));
    StaticPageCache::clearAll();
}
```

**New method on `StaticPageCache`:** Add `invalidateAll()` that deletes every `.html`, `.xml`,
`.txt`, and `.json` file in the cache directory and resets the index to `['_config' =>
['enabled' => true]]`. Log a summary line (count of files removed).

---

## Out of Scope

- Cache TTL / max-age expiry (the 1%-chance random invalidation already handles staleness over
  time).
- Invalidating on every setting save (too broad — most settings don't affect rendered HTML).

---

## Files Changed

| File | Change |
|------|--------|
| `includes/StaticPageCache.php` | Add `getCacheMetadata()` method; enrich HTML comment with timestamp |
| `includes/RouteHelper.php` | Add `X-Cache-Created` and `X-Cache-Age` headers on cache hit |
| `adm/logic/admin_settings_logic.php` | Invalidate `/` on homepage-setting change; flush all on theme change |

---

## Testing

1. Load any public page as an anonymous user. Check devtools Network tab — verify `X-Cache: HIT`,
   `X-Cache-Created`, and `X-Cache-Age` headers are present on the second request.
2. View source of any cached page — verify `<!-- Cached: / | Created: ... -->` comment.
3. Change `alternate_homepage` in admin settings. Reload `/` — verify homepage re-renders
   correctly (not from cache).
4. Change `active_theme` in admin settings. Reload any previously-cached page — verify it
   re-renders with the new theme.
