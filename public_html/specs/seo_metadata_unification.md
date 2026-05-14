# SEO & Social Metadata Unification

**Status:** Ready for Implementation
**Priority:** High
**Impact:** Eliminates duplicate `<title>` / `<meta>` emission, removes all hardcoded SEO copy from theme and view code, makes every page's metadata admin-editable in the database

## Overview

Today, SEO and social metadata are scattered: `PublicPageBase::global_includes_top()` emits OG/Twitter tags from `$options`, but theme `PublicPage` subclasses *also* emit their own `<title>` and `<meta name="description">`, producing duplicates. Marketing views hardcode title/description strings as PHP literals, so changing what social platforms display requires a code change. Different views use different option-key spellings (`description` vs `meta_description`), causing SEO and social tags to silently diverge.

This spec replaces that with a single architecture:

1. **One emitter.** `PublicPageBase::global_includes_top()` is the only place head metadata is rendered. Theme subclasses emit nothing.
2. **DB-driven copy.** A new `seo_page_metadata` table holds title, description, OG, and image copy keyed by URL path. Admin edits SEO for any page via `/admin/admin_seo_pages` — no code change needed.
3. **No hardcoded SEO in view code.** Theme/view code is for structure only. Entity views still pass entity-derived metadata (which is already DB-backed); static views pass nothing and rely on the table.

## Current State

### Duplicate `<title>` and `<meta name="description">` emission

Each of these theme `PublicPage` subclasses emits its own `<title>` and `<meta name="description">` and *then* calls `global_includes_top()` (which already emits `<meta name="description">`):

- `theme/getjoinery/includes/PublicPage.php` (lines 56, 67-68, 71)
- `theme/phillyzouk-html5/includes/PublicPage.php`
- `theme/jeremytunnell-html5/includes/PublicPage.php`
- `theme/zoukroom-html5/includes/PublicPage.php`

The base class (`includes/PublicPageBase.php:436-520`) emits `<meta name="description">` but does **not** emit `<title>` — that's the structural gap the themes were working around.

### Split-brain on `getjoinery` (the originating bug)

`theme/getjoinery/views/*.php` (9 marketing views) pass `'description' => '...'`, which the local theme `PublicPage` reads. But `PublicPageBase::global_includes_top()` reads `'meta_description'` and falls back to `site_description` when missing. Result: `<meta name="description">` is duplicated with *different content* on every page; OG/Twitter descriptions come from a separate source than the SEO description. Social previews mix hardcoded marketing copy with admin-controlled settings.

### Hardcoded SEO copy in view/theme code

Marketing copy and SEO strings live in PHP files:

- `theme/getjoinery/views/index.php:7` — `'title' => 'Joinery — Membership software you can trust with your data'`
- `theme/getjoinery/views/{pricing,features,about,philosophy,developers,showcase,privacy,terms}.php` — same pattern
- `views/blog.php:13`, `views/events.php:9`, `views/products.php:7`, `views/pricing.php:6`, `views/lists.php:7` — only `title` set, no description

Changing the site's social preview requires a code deploy. Multi-deployment platforms (every Joinery install has different marketing copy) can't customize per-deployment without forking themes.

### Compliant views (no changes needed)

Entity views pull metadata from entity records and follow the documented pattern:
`views/post.php`, `views/event.php`, `views/product.php`, `views/page.php`, `views/location.php`, `views/video.php`, `views/list.php`, plus `theme/scrolldaddy/views/*.php`. These continue to work unchanged — entity data is already DB-driven.

## Solution

### Layer 1 — Single emitter (architectural)

**Goal:** `PublicPageBase::global_includes_top()` is the only place that emits `<title>`, `<meta name="description">`, `og:*`, `twitter:*`, `<link rel="canonical">`, and `<meta name="robots">`.

**Changes:**

1. **`includes/PublicPageBase.php`** — `global_includes_top()`:
   - Emit `<title>` from `$page_title` (the same source already used for `og:title`).
   - Accept `$options['description']` as an alias for `$options['meta_description']` (`meta_description` wins if both set). Avoids breaking existing views during the cleanup.
   - Auto-emit `<meta name="robots" content="noindex">` when `$options['is_valid_page'] === false` or `$options['noindex'] === true`.

2. **Remove duplicate emission from every theme `PublicPage.php` subclass.** Delete the local `<title>` / `<meta name="description">` lines and any local variables used only to compute them. Rely on `global_includes_top()` (already called via `public_header_common()`).
   - `theme/getjoinery/includes/PublicPage.php` (lines 53-56, 67-68)
   - `theme/phillyzouk-html5/includes/PublicPage.php`
   - `theme/jeremytunnell-html5/includes/PublicPage.php`
   - `theme/zoukroom-html5/includes/PublicPage.php`
   - Any other `theme/*/includes/PublicPage.php` found in audit

3. **Repo-wide grep** for `og:title`, `og:description`, `twitter:title`, `twitter:description`, `<meta name="description"`, `<title>` outside `global_includes_top()` and view files. Delete or convert any stragglers.

**Verification:** Visit one page per theme, view source, confirm exactly one of each tag.

### Layer 2 — DB-driven page metadata

**Goal:** SEO copy for every static page lives in the database, edited via admin UI, with no code changes required to update it.

#### 2a — New data class

Create `data/seo_page_metadata_class.php` with a single-row class extending `SystemBase` and a `Multi` class extending `SystemMultiBase`. Schema (managed via `$field_specifications`; no migration):

| Column | Type | Notes |
|---|---|---|
| `*_id` | serial PK | standard |
| `*_path` | varchar(255), unique, not null | request path, e.g. `/`, `/pricing`, `/about` |
| `*_title` | varchar(255), nullable | drives `<title>` and `og:title` |
| `*_meta_description` | text, nullable | drives `<meta name="description">` and `og:description` |
| `*_og_title` | varchar(255), nullable | optional override for `og:title` / `twitter:title` only |
| `*_og_description` | text, nullable | optional override for `og:description` / `twitter:description` only |
| `*_preview_image_url` | varchar(500), nullable | drives `og:image` / `twitter:image` |
| `*_og_type` | varchar(50), default `'website'` | `og:type` |
| `*_noindex` | boolean, default false | emit `<meta name="robots" content="noindex">` |
| `*_create_time`, `*_modify_time`, `*_delete_time` | standard SystemBase fields |

Path matching is **exact** for v1. Prefix/glob matching is an open question (see end).

The Multi class's `getMultiResults()` accepts at least: `path` (exact), `noindex`, standard time filters.

#### 2b — Lookup in `global_includes_top()`

Add a lookup pass before applying site-default fallbacks. Pseudocode:

```
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$override = SeoPageMetadata loaded by path (or null);

// Precedence per field: $options (view-passed) → $override (DB) → site setting fallback
$page_title       = $options['title']            ?? $override?->title             ?? $settings->get_setting('site_name');
$meta_description = $options['meta_description'] ?? $override?->meta_description  ?? $settings->get_setting('site_description');
$og_title         = $options['og_title']         ?? $override?->og_title          ?? $page_title;
$og_description   = $options['og_description']   ?? $override?->og_description    ?? $meta_description;
$preview_image    = $options['preview_image_url']?? $override?->preview_image_url ?? $settings->get_setting('preview_image');
$og_type          = $options['og_type']          ?? $override?->og_type           ?? 'website';
$noindex          = ($options['is_valid_page'] === false) || $options['noindex'] || $override?->noindex;
```

Cache the lookup per request (it's hit once per page).

#### 2c — Admin UI

Two new admin pages under the existing admin theme (Joinery System / vanilla HTML5):

- **`adm/admin_seo_pages.php`** — list view with table columns: path, title, meta_description (truncated), noindex flag, modified date. Add filter/search on path. Link to edit form. "Add New" button.
- **`adm/admin_seo_page_edit.php`** — FormWriter form with fields matching the schema. Help text on each field (recommended lengths, what each drives). Preview of resulting `<title>` and `<meta>` tags above the form when editing.

Add admin nav link under an existing "SEO" or "Settings" group (decide during implementation based on current nav structure).

### Layer 3 — Strip hardcoded SEO from view code

**Goal:** No view or theme file contains SEO copy strings. All such strings live in `seo_page_metadata` rows or come from entity records.

**Changes:**

1. **`theme/getjoinery/views/*.php`** — delete `title`, `description`, `meta_description` keys from `public_header()` calls in all 9 marketing views (index, pricing, features, about, philosophy, developers, showcase, privacy, terms). Leave structural options (`showheader`, etc.).

2. **`views/blog.php`, `views/events.php`, `views/products.php`, `views/pricing.php`, `views/lists.php`** — same: delete `title` keys. Index views become metadata-free; rely on the table.

3. **Entity views unchanged.** `post.php`, `event.php`, `product.php`, `page.php`, `location.php`, `video.php`, `list.php`, and `scrolldaddy/views/*` continue to pass entity data into `$options`. Entity data is already DB-driven.

4. **Audit** the rest of the codebase for any remaining hardcoded SEO strings. Use the grep results from Layer 1 step 3.

### Layer 4 — Auto-noindex for invalid pages

Already covered in Layer 1 step 1 (`is_valid_page === false` ⇒ `noindex`). Verify `views/404.php` and any view that explicitly sets `is_valid_page = false` get the tag.

## Implementation Steps

Each layer is independently shippable and testable.

1. **Layer 1, step 1** — `PublicPageBase.php`: emit `<title>`, alias `description`, auto-`noindex`. Test: every page still has exactly one `<title>` (will be temporarily duplicated until step 2 cleans theme subclasses).
2. **Layer 1, step 2** — delete duplicate emission from all theme `PublicPage.php` subclasses.
3. **Layer 1, step 3** — repo-wide grep audit; delete stragglers.
4. **Layer 2, step a** — create `data/seo_page_metadata_class.php`. Run `update_database` to create the table.
5. **Layer 2, step b** — add the lookup pass to `global_includes_top()`. Test with a manually-inserted row.
6. **Layer 2, step c** — build admin list page (`adm/admin_seo_pages.php`).
7. **Layer 2, step c** — build admin edit page (`adm/admin_seo_page_edit.php`).
8. **Layer 3** — strip hardcoded SEO from theme and view files. Verify pages still render with sensible defaults (site settings) when no DB row exists.
9. **Production data entry** — on `getjoinery.com` and any other deployment, admin uses the new UI to add rows for `/`, `/pricing`, `/features`, `/about`, etc.
10. **Docs** — update `docs/seo_metadata.md`:
    - Document the new `seo_page_metadata` table, admin path, and lookup precedence.
    - Document that `description` and `meta_description` are accepted as aliases on entity views (recommend `meta_description` for new code).
    - Document that theme/view code must not contain hardcoded SEO strings; entity views pass entity-derived data only.
    - Document `is_valid_page === false` ⇒ auto-`noindex`.
    - Document that theme `PublicPage` subclasses must **not** emit head tags themselves.

## Files Changed

**Core:**
- `includes/PublicPageBase.php` — emit `<title>`, alias `description`, auto-`noindex`, DB lookup, fallback chain

**New:**
- `data/seo_page_metadata_class.php` — model
- `adm/admin_seo_pages.php` — list UI
- `adm/admin_seo_page_edit.php` — edit UI

**Themes (delete duplicate emission):**
- `theme/getjoinery/includes/PublicPage.php`
- `theme/phillyzouk-html5/includes/PublicPage.php`
- `theme/jeremytunnell-html5/includes/PublicPage.php`
- `theme/zoukroom-html5/includes/PublicPage.php`
- Any other `theme/*/includes/PublicPage.php` found in audit

**Views (strip hardcoded SEO):**
- `theme/getjoinery/views/{index,pricing,features,about,philosophy,developers,showcase,privacy,terms}.php`
- `views/{blog,events,products,pricing,lists}.php`

**Docs:**
- `docs/seo_metadata.md` — full rewrite per step 10 above

## Out of Scope

- Sitemap and `robots.txt` generation (both already in good shape; see `views/sitemap.php`, `views/robots.php`).
- Per-entity `keywords` meta tag (low SEO value in 2026).
- Structured data / JSON-LD (`schema.org`) — separate spec if desired.
- Migrating entity views to use the table (entity data is already DB-driven — would add a layer without value).
- Prefix/glob path matching (see open questions).

## Verification Checklist

- [ ] Each theme: view source on homepage and one entity page. Exactly one `<title>`, one `<meta name="description">`, one each of the `og:` and `twitter:` tags.
- [ ] No theme `PublicPage.php` file contains `<title>` or `<meta>` emission.
- [ ] No view file contains hardcoded SEO copy strings (grep audit clean).
- [ ] Admin can add a `seo_page_metadata` row for `/` and see the homepage's `<title>` / `og:title` update on next reload.
- [ ] Admin can edit an existing row and changes propagate immediately.
- [ ] A page with no matching row falls back to `site_name` / `site_description` / `preview_image` settings cleanly.
- [ ] 404 pages emit `<meta name="robots" content="noindex">`.
- [ ] `getjoinery` homepage in Facebook Sharing Debugger and Twitter Card Validator: preview matches the DB row; no duplicate-tag warnings.
- [ ] Entity views (post, event, product, page, location, video, list) still render their entity's metadata.

## Open Design Questions

1. **Path matching strategy.** v1 spec is exact-match. Should we also support prefix (`/blog/*`) or glob patterns? Prefix matching is useful for "noindex everything under `/admin/preview/*`" or "default OG image for everything under `/blog/*`," but adds complexity (which row wins when multiple match?). Defer until needed?
2. **Seed defaults on install?** Should a fresh deployment get default rows for `/`, `/pricing`, `/about`, etc. with placeholder copy — or should the table start empty and admin populates? Empty-start is cleaner; admin sees "no overrides" and uses site settings as fallback until they need page-specific copy.
3. **Per-path `og_type` defaults.** Blog index = `website`, individual post = `article`. Should we have type-aware defaults (e.g. inferred from path patterns) or fully manual per-row?
4. **Admin nav placement.** New admin pages — under "Settings," "SEO," or top-level? Depends on existing nav structure.
5. **Caching.** Lookup runs on every public pageview. Cache the full table in memory per request (cheap, ~tens of rows) or per-path? Probably per-request global cache is fine.
6. **Soft delete on rows.** Should deleting a row noindex or just remove the override? Per platform conventions (`*_delete_time`), soft-delete = remove the override; row stays in DB but `getMultiResults` filters it out.
