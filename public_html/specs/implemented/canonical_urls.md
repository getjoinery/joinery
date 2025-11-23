# Canonical URLs Feature Specification

**Status**: Implemented
**Date Implemented**: November 23, 2025
**Priority**: Low (automatic, requires no user action)

## Overview

Automatic generation and output of canonical URL tags (`<link rel="canonical">`) on all public pages to prevent Google from flagging duplicate content issues. The feature works transparently without requiring any configuration.

## Problem Statement

Google Search Console was reporting "duplicate without user-selected canonical" warnings for paginated pages and parameter variations (e.g., `/blog?offset=10`, `/blog/tag/urbit?offset=20`, protocol/www variations). Without canonical tags, Google treats each variation as potentially unique content, fragmenting page authority and creating false duplicates.

## Solution

Automatically inject a `<link rel="canonical">` tag into the `<head>` of every public page. The canonical URL:
- Uses the configured `webDir` setting as the domain (exactly as entered by admin)
- Always uses HTTPS protocol
- Strips pagination parameters (`offset`, `page`, `page_offset`, `p`)
- Preserves content-defining query parameters (like `tag=urbit`)
- Is output early in the page head before other meta tags

## Implementation Details

### Files Modified

1. **`/includes/PublicPageBase.php`** (lines 423-459)
   - Added private method: `get_canonical_url()`
   - Updated method: `global_includes_top()` to output the canonical tag

### Architecture

**Inheritance Chain** (all public pages automatically get canonical tags):
```
PublicPageBase (implements get_canonical_url)
    ↓
PublicPageTailwind
    ↓
PublicPage (theme-specific)
    ↓
Individual page controllers/views
```

### Method: `get_canonical_url()`

**Location**: `PublicPageBase::get_canonical_url()` (private)

**Logic Flow**:
1. Extract path from `$_SERVER['REQUEST_URI']` using `parse_url()`
2. Define pagination parameters: `['offset', 'page', 'page_offset', 'p']`
3. Filter `$_GET` to exclude pagination parameters, keep all others
4. Get domain from `Globalvars::get_setting('webDir')`
5. Build URL: `https://` + webDir + path + filtered query params
6. Return canonical URL string

**Code**:
```php
private function get_canonical_url() {
    $settings = Globalvars::get_instance();
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    $pagination_params = ['offset', 'page', 'page_offset', 'p'];
    $filtered_params = [];
    foreach ($_GET as $key => $value) {
        if (!in_array($key, $pagination_params)) {
            $filtered_params[$key] = $value;
        }
    }

    $webDir = $settings->get_setting('webDir');
    $canonical_domain = 'https://' . $webDir;
    $canonical = $canonical_domain . $path;

    if (!empty($filtered_params)) {
        $canonical .= '?' . http_build_query($filtered_params);
    }

    return $canonical;
}
```

### Output Location

**Method**: `PublicPageBase::global_includes_top()` (line 475-476)

Outputs in the page `<head>` section:
```html
<link rel="canonical" href="https://example.com/path">
```

The tag is HTML-escaped using `htmlspecialchars()` with `ENT_QUOTES` and UTF-8 encoding.

## Behavior Examples

| Request URL | Canonical Output |
|-------------|------------------|
| `/blog?offset=10` | `https://example.com/blog` |
| `/blog?offset=20&offset=10` | `https://example.com/blog` |
| `/blog/tag/urbit?offset=5` | `https://example.com/blog/tag/urbit` |
| `/blog/tag/urbit?offset=5&filter=news` | `https://example.com/blog/tag/urbit?filter=news` |
| `/post/my-article` | `https://example.com/post/my-article` |
| `/login` | `https://example.com/login` |

## Configuration

**No configuration required.** The system uses:
- **Domain**: `webDir` setting from system Settings (already validated)
- **Protocol**: Always HTTPS
- **Pagination parameters**: Hard-coded list in the method

### Validation

The `webDir` setting is already validated in `/adm/admin_settings.php` (line 368):
- Rejects entries with protocol prefix (`http://`, `https://`)
- Rejects entries with trailing slashes
- Shows validation error if invalid format detected

Example error message: "webDir should contain domain only (e.g. 'example.com' or 'localhost:8080'). Protocol is set by Protocol Mode."

## Potential Issues and Mitigation

### Issue 1: Empty or Missing `webDir` Setting
- **Symptom**: Canonical URL would be `https://` with no domain
- **Mitigation**: Settings validation prevents saving invalid `webDir`, and all deployments should have this set

### Issue 2: Domain Mismatch
- **Symptom**: If user accesses via `www.example.com` but `webDir` is `example.com` (or vice versa), canonical consolidates to the configured domain, not the accessed domain
- **Mitigation**: Administrator must ensure `webDir` matches the actual primary domain. This is by design - the canonical tells Google which version is authoritative
- **Recommendation**: Pair with server-level 301 redirect to ensure all traffic arrives at the canonical domain

### Issue 3: Localhost/Development Environments
- **Symptom**: Canonical might be `https://localhost:8000/path` which isn't accessible externally
- **Mitigation**: Development environments don't matter for SEO; this only affects production

### Issue 4: Port Numbers
- **Symptom**: If `webDir` includes a port (e.g., `example.com:8080`), canonical will include it
- **Mitigation**: This is correct behavior and actually needed for non-standard ports

## Impact on Google Search Console

**Expected Results**:
1. "Duplicate without user-selected canonical" warnings should decrease
2. Consolidated duplicate pages under the canonical URL
3. Improved crawl efficiency as Google understands page relationships

**Timeline**:
- Canonical tags are immediately active
- Google may take 1-2 weeks to fully reprocess and consolidate duplicates
- Manual request for re-crawl in GSC speeds up the process

## Documentation

Brief reference added to `/docs/theme_integration_instructions.md` (line 1160-1162):
> The system automatically adds canonical URL tags to all public pages. It uses your `webDir` setting as configured and strips pagination parameters (`offset`, `page`, `page_offset`, `p`). No additional configuration needed. See the implementation in `PublicPageBase::get_canonical_url()` (includes/PublicPageBase.php:429-459).

## Related Documentation

- Implementation: `PublicPageBase::get_canonical_url()` and `global_includes_top()`
- Settings: `webDir` (validated in `/adm/admin_settings.php`)
- Google's Canonical Tags Guide: https://developers.google.com/search/docs/advanced/crawling/consolidate-duplicate-urls
