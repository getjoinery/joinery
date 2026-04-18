# SEO & Social Metadata

Every public view on the platform produces SEO and Open Graph / Twitter Card tags from a single source: the `$options` (aka `$hoptions`) array passed to `PublicPage::public_header()`. `PublicPageBase::global_includes_top()` is the canonical head emitter — themes and plugins should **not** override it to re-emit these tags.

## What the base class emits

`PublicPageBase::global_includes_top()` renders, from a single set of source values:

- `<link rel="canonical">`
- `<meta name="description">`
- `<meta property="og:title">`, `og:description`, `og:url`, `og:type`, `og:site_name`, `og:locale`, `og:image`
- `<meta name="twitter:card">` (`summary_large_image`), `twitter:title`, `twitter:description`, `twitter:image`

All values fall back to site-level settings (`site_name`, `site_description`, `preview_image`) when the view doesn't populate them.

## `$hoptions` keys to populate in entity views

| Key | Purpose | Fallback |
|---|---|---|
| `title` | Used for `<title>`, `og:title`, `twitter:title` | `site_name` |
| `meta_description` | `<meta name="description">`, `og:description`, `twitter:description` | `site_description` |
| `og_type` | `og:type` | `website` |
| `preview_image_url` | `og:image`, `twitter:image` | `preview_image` setting |
| `og_title` (optional) | Override `og:title` / `twitter:title` only | falls through to `title` |
| `og_description` (optional) | Override `og:description` / `twitter:description` only | falls through to `meta_description` |

Use `og_title` / `og_description` only when the social card copy should be distinct from the SEO copy. Otherwise omit them.

## Canonical pattern for entity-driven views

```php
$page = new PublicPage();
$header_options = [
    'is_valid_page'    => $is_valid_page,
    'title'            => $entity->get('..._title'),
    'og_type'          => 'article',  // omit for 'website'
];
if ($entity->get('..._short_description')) {
    $header_options['meta_description'] = $entity->get('..._short_description');
}
if (method_exists($entity, 'get_picture_link') && $entity->get_picture_link('og_image')) {
    $header_options['preview_image_url'] = $entity->get_picture_link('og_image');
}
$page->public_header($header_options);
```

### Notes

- Keep `meta_description` under ~160 characters. Strip HTML and truncate with `mb_substr` when using a rich-text field as the source.
- The `og_image` size variant (1200×630, cropped) is defined in `theme/joinery-system/theme.json`. `$entity->get_picture_link('og_image')` returns the correctly-sized URL. If an entity doesn't have its own image, omit the key and the base will fall back to the site default.
- Gated/transactional views (cart, login, profile, etc.) should not populate these fields — they fall through to the site defaults and shouldn't be indexed.

## What views currently populate

- `views/post.php`, `views/event.php`, `views/product.php`, `views/page.php`, `views/location.php`, `views/video.php`, `views/list.php`
- `plugins/scrolldaddy/views/index.php`, `plugins/scrolldaddy/views/pricing.php`, `plugins/scrolldaddy/views/page.php`

When adding a new public entity view, follow the pattern above.
