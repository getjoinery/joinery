# SEO & Social Metadata

SEO and Open Graph / Twitter Card tags are emitted from a single point — `PublicPageBase::global_includes_top()`. Every public view on every theme funnels through this method. Themes and plugin views **must not** emit `<title>`, `<meta name="description">`, `og:*`, `twitter:*`, or `<link rel="canonical">` themselves; doing so produces duplicate tags.

## Storage and precedence

SEO values are resolved per-request through a four-level fallback chain:

1. **`seo_page_metadata` row** (admin override, keyed by canonical request path)
2. **`$options` passed to `public_header()`** (entity content for entity pages; nothing for static pages)
3. **Inferred value** (computed by `SeoPageMetadata::infer_for_request()`)
4. **Site setting** (`site_name`, `site_description`, `preview_image`)

A row in `spm_seo_page_metadata` with sparse, all-NULL fields contributes nothing — each field falls through individually. Only when a column is non-NULL does the override win for that field.

### Single emitter

`PublicPageBase::global_includes_top()` is the sole emission point for:

- `<title>`
- `<meta name="description">`
- `<meta name="robots">` (auto-emits `noindex` when `is_valid_page === false`, the `noindex` option is set, or `spm_noindex` is true)
- `<link rel="canonical">`
- `<meta property="og:title">`, `og:description`, `og:url`, `og:type`, `og:site_name`, `og:locale`, `og:image`
- `<meta name="twitter:card">` (auto-selects `summary_large_image` when a preview image is present, otherwise `summary`), `twitter:title`, `twitter:description`, `twitter:image`

The legacy `description` option key is accepted as an alias for `meta_description`. Both old and new views work; new code should use `meta_description`.

## Zero-config inference

All inference logic lives as static methods on `SeoPageMetadata` (`data/seo_page_metadata_class.php`). A fresh deployment with zero override rows still emits distinct, useful SEO tags:

| Field | Inference rule |
|---|---|
| `title` | Static path: humanize the last path segment (`/about-us` → `About Us`). Known acronyms preserved (`API`, `FAQ`, `SEO`, `URL`, `RSS`, `JSON`, etc.). Namespaced plugin views prepend the plugin display name when distinct. Path `/` returns NULL (falls through to `site_name`). |
| `meta_description` | If `$options['entity_body_html']` is present, strip HTML, collapse whitespace, multi-byte-safe truncate at a word boundary to ~160 chars. Static paths fall through to `site_description`. |
| `preview_image` | If `$options['entity_body_html']` is present, extract the first `<img src="...">` (data URIs and tracking-pixel patterns skipped). Falls through to `site_preview_image`. |
| `og_type` | Maps `$options['entity_type']`: `post` / `event` / `video` → `article`; `product` → `product`; everything else → `website`. |

All resolved image URLs and the canonical URL are absolutized via `SeoPageMetadata::absolutize_url()` before emission — Facebook and Twitter reject relative `og:image` URLs.

### Title format

Site-wide title format is hardcoded as `{title} | {site_name}` inside `SeoPageMetadata::apply_title_format()`. When the resolved title equals `site_name` (e.g. the homepage with no inference hit), the format is skipped to avoid `Joinery | Joinery`. A deployment that wants a different separator should patch the `TITLE_FORMAT` constant on `SeoPageMetadata` or override `apply_title_format()` — this is not a setting.

## `$options` keys recognised by `public_header()`

| Key | Purpose |
|---|---|
| `title` | Page title override (still subject to DB row override) |
| `meta_description` | Page description override |
| `og_title` / `og_description` | Separate social-card copy (rarely needed) |
| `og_type` | OG type override (else inferred from `entity_type`) |
| `preview_image_url` | OG/Twitter image override |
| `entity_type` | One of `post`, `event`, `product`, `page`, `location`, `video`, `mailing_list` — drives `og_type` inference and surfaces in admin UI |
| `entity_body_html` | Body content (HTML) for description/image inference when `meta_description` and `preview_image_url` aren't already set |
| `is_valid_page` | When `false`, auto-emits `<meta name="robots" content="noindex">` |
| `noindex` | Explicit noindex flag |

Entity views should pass `entity_type` and `entity_body_html` so the inference layer can produce sensible descriptions / preview images when `short_description` / `og_image` aren't set.

### Entity view pattern

```php
$page = new PublicPage();
$header_options = [
    'is_valid_page'    => $is_valid_page,
    'title'            => $entity->get('..._title'),
    'og_type'          => 'article',
    'entity_type'      => 'post',  // matches SeoPageMetadata::ENTITY_CLASSES key
    'entity_body_html' => $entity->get('..._body'),
];
if ($entity->get('..._short_description')) {
    $header_options['meta_description'] = $entity->get('..._short_description');
}
if (method_exists($entity, 'get_picture_link') && $entity->get_picture_link('og_image')) {
    $header_options['preview_image_url'] = $entity->get_picture_link('og_image');
}
$page->public_header($header_options);
```

The `og_image` size variant (1200×630, cropped) is defined in `theme/joinery-system/theme.json`. Gated/transactional views (cart, login, profile, etc.) should not populate SEO fields — they fall through to site defaults and shouldn't be indexed.

## Inventory population

Rows in `spm_seo_page_metadata` are maintained through three mechanisms:

1. **`update_database` and `upgrade.php`** run `SeoPageMetadata::sync_inventory()` after core seeding. Idempotent — upserts entity rows by `(spm_entity_type, spm_entity_id)` so custom SEO copy follows the entity across slug changes; upserts static rows by `spm_path`. Runs the bounded auto-cleanup pass.
2. **Admin "Scan now"** button at `/admin/admin_seo_pages` runs the same logic on demand.
3. **Lazy auto-create on pageview.** First pageview of an eligible path inserts a sparse row via `INSERT ... ON CONFLICT DO NOTHING` (race-safe). Eligible = valid page, non-admin/ajax/api path, non-entity-parameterized route. Fires for all session types including authenticated admins (so admin previews of new pages seed rows immediately).

### Bounded auto-cleanup

Inside each enumeration run, after upsert, any entity-linked row whose `spm_entity_id` is *not* in the live set for `spm_entity_type` (and that type was queried this run) is soft-deleted. **Auto-cleanup does not touch:**

- Static-path rows (`spm_entity_type IS NULL`) — a missing path could be a removed view, a plugin route, an admin-added path, etc.
- Rows tagged with entity types outside the core enumeration loop (plugin entities, custom types).

Both categories show up in the **Find orphans** view on the admin SEO list page so a human can review and bulk-soft-delete.

## Sitemap

`views/sitemap.php` is a thin XML wrapper around `SeoPageMetadata::enumerate_public_paths()`. Both the sitemap and the SEO row-population step share their discovery code — when a new entity type is added to enumeration, both systems pick it up. Paths with `spm_noindex = true` are excluded. `<lastmod>` uses the entity's `modify_time` for entity URLs, `spm_modify_time` for static rows, and current date as the final fallback.

## Limitations of the lazy/enumeration approach

- **New entities lag the SEO list** until the next pageview (admin preview counts) or the next enumeration run (`update_database`, `upgrade.php`, or "Scan now").
- **Slug changes propagate at enumeration time.** Between an entity's slug change and the next enumeration run, visitors to the new path see entity-content fallback (no row override) instead of the previously-set custom SEO. The row itself isn't lost — it follows the entity to its new path on the next sync.
- **Static-path orphans persist** until admin removes them via "Find orphans". The trade is: auto-cleanup never deletes a real route just because enumeration missed it.
- **Plugin-owned entities participate via lazy auto-create only.** They're never part of the auto-cleanup pass. A plugin wanting proactive seeding + auto-cleanup can opt in by extending the enumeration loop (out of scope for v1).

## Admin UI

- `/admin/admin_seo_pages` — list view with filter (all / has overrides / noindex only / static only), search, "Scan now" action, "Find orphans" view, "+ Add path" button.
- `/admin/admin_seo_page_edit` — edit form for a single row. Placeholders render *inferred* defaults (e.g. `Defaults to: Pricing | Joinery`) so admins see what the public emitter will produce when fields are left blank.

## What views currently populate

- Entity views (`views/post.php`, `event.php`, `product.php`, `page.php`, `location.php`, `video.php`, `list.php`) — pass entity content fields as `$options`. Theme variants of these views likewise.
- Marketing / static views — pass only structural options (`is_valid_page`, `showheader`). All SEO copy lives in `spm_seo_page_metadata` rows or is inferred from the path.
