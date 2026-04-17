# Open Graph Meta Tags

## Overview

When links to the site are shared on social media (Facebook, Twitter/X, Slack, iMessage, etc.), the preview cards are missing proper metadata. Currently only `og:image` is partially supported — no `og:title`, `og:description`, `og:url`, `og:type`, or `og:site_name` tags are output. Additionally, only the event detail page passes a page-specific preview image; other content types (posts, products, pages) fall back to the site default or show nothing.

This spec adds comprehensive Open Graph tags to all public pages and passes page-specific images from content types that have them.

## Goals

1. Every public page outputs a complete set of Open Graph tags
2. Content pages (events, posts, pages) pass their own images when available
3. All tags fall back gracefully to site-wide defaults

## Implementation

### Part 1: Add Full Open Graph Tags in PublicPageBase

**File:** `includes/PublicPageBase.php` — `global_includes_top()` method

Expand the existing og:image output to include the full set of Open Graph tags. Use data already available from the `$options` array and site settings.

**Tags to output:**

| Tag | Source | Fallback |
|---|---|---|
| `og:title` | `$options['title']` | `site_name` setting |
| `og:description` | `$options['meta_description']` | `site_description` setting |
| `og:image` | `$options['preview_image_url']` (already exists) | `preview_image` setting (already exists) |
| `og:url` | Canonical URL (already computed in this method) | — |
| `og:type` | `$options['og_type']` | `"website"` |
| `og:site_name` | `site_name` setting | — |

**Notes:**
- The `title` and `meta_description` values are already being resolved in `public_header_common()` from options or settings. They need to be passed through to `global_includes_top()` via the `$options` array. Check that `public_header_common()` populates `$options['title']` and `$options['meta_description']` with defaults before calling `global_includes_top()`.
- The canonical URL is already computed in this method — reuse it for `og:url`.
- All values must be HTML-escaped with `htmlspecialchars()`.
- `og:image` must be an absolute URL. If the value doesn't start with `http`, prepend `https://{webDir}`.
- Strip HTML tags from `og:description` and truncate to 200 characters if needed.

### Part 2: Pass Page-Specific Images and Descriptions from Content Views

For each content type that has images and descriptions, pass `preview_image_url` and `meta_description` in the `$options` array to `public_header()`.

#### Posts (`views/post.php`)

Posts have `pst_fil_file_id` (image) and `pst_short_description`. The Post class has `get_picture_link($size_key)`.

Add to the header options:
```php
if ($post->get_picture_link('hero')) {
    $hoptions['preview_image_url'] = $post->get_picture_link('hero');
}
if ($post->get('pst_short_description')) {
    $hoptions['meta_description'] = $post->get('pst_short_description');
}
```

#### Pages (`views/page.php`)

Pages have `pag_fil_file_id` (image). The Page class has `get_picture_link($size_key)`.

Add to the header options:
```php
if ($page->get_picture_link('hero')) {
    $hoptions['preview_image_url'] = $page->get_picture_link('hero');
}
```

Note: Pages don't have a short_description field, so they'll use the site-wide default description.

#### Events (`views/event.php`)

Events already pass `preview_image_url`. Also add `meta_description` from the short description:

```php
if ($evt_get('evt_short_description')) {
    $page_options['meta_description'] = $evt_get('evt_short_description');
}
```

Note: This is partially done already — `meta_description` is set in the event view options. Verify it flows through to the OG tags.

#### Products (`views/product.php`)

Products don't have a file_id image field, so they'll use the site default image. Add the short description:

```php
if ($product->get('pro_short_description')) {
    $hoptions['meta_description'] = strip_tags($product->get('pro_short_description'));
}
```

### Part 3: Theme Compatibility

The OG tags are output from `PublicPageBase::global_includes_top()`, which all themes call from their `public_header()` via `$this->global_includes_top($options)`. No theme-specific changes are needed — the tags will appear in the `<head>` section for all themes automatically.

However, verify that each theme's `public_header()` passes the full `$options` array through to `global_includes_top()`. The phillyzouk theme already does this (line 61 of its PublicPage.php).

## Testing

1. Share a link to the homepage — should show site name, site description, and default preview image
2. Share a link to an event with an image — should show event name, short description, and event image
3. Share a link to a blog post with an image — should show post title, short description, and post image
4. Share a link to a page — should show page title and default image (or page image if set)
5. Use Facebook's Sharing Debugger (https://developers.facebook.com/tools/debug/) or Twitter Card Validator to verify tags
6. View page source and confirm all OG tags are present and correctly escaped

## Out of Scope

- Twitter Card tags (`twitter:card`, `twitter:title`, etc.) — can be added later
- Per-page OG image overrides in the admin UI
- Image dimension tags (`og:image:width`, `og:image:height`)
