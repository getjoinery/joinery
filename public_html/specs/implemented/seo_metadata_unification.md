# SEO & Social Metadata Centralization

**Status:** Ready for Implementation
**Priority:** High
**Impact:** Eliminates duplicate `<title>` / `<meta>` emission, removes all hardcoded SEO copy from theme and view code, and makes every page's SEO admin-editable in a single table with one admin UI. Centralizes SEO storage; entity content stays where it belongs.

## Overview

Today, SEO and social metadata are scattered and duplicated. `PublicPageBase::global_includes_top()` emits OG/Twitter tags from `$options`, but theme `PublicPage` subclasses *also* emit their own `<title>` and `<meta name="description">`, producing duplicates. Marketing views hardcode title/description strings as PHP literals, so changing what social platforms display requires a code deploy. Views use different option-key spellings (`description` vs `meta_description`), and `global_includes_top()` only reads `meta_description` — so SEO and social tags silently diverge. There is no central place to see, audit, or edit SEO across the site.

This spec replaces that with a single architecture:

1. **One emitter.** `PublicPageBase::global_includes_top()` is the only place head metadata is rendered. Theme subclasses emit nothing.
2. **Centralized SEO storage.** A new `seo_page_metadata` table holds SEO overrides (title, meta description, OG/Twitter, image, og_type, noindex) for every public path — static views *and* entity paths. One admin UI manages all of it. DB row wins; entity content and site settings are fallback.
3. **Entity content is fallback, not SEO storage.** Entity views continue to pass their content fields (`pst_title`, etc.) as `$options`. When a row's field is null, the emitter falls back to that content; when no row exists at all, it falls back to site settings. Entity content stays on entities — it is content, not SEO.

## Audit Findings (basis for the rewrite)

The codebase was audited for existing SEO storage on entities:

- **No entity has SEO-specific columns.** Every entity view derives SEO at render time from *content* columns: `pst_title`/`pst_short_description` (post), `pro_name`/`pro_short_description` (product), `loc_name`/`loc_short_description` (location), `evt_name`/`evt_short_description` (event), `pag_title` + stripped content (page), `vid_title` + description (video), `mlt_name` + description (mailing list).
- **OG images are picture-role-attached.** Entity classes expose `get_picture_link('og_image')`; the platform's photo system stores these against the entity record via a role identifier. No image columns.
- **`og_type` is already a documented option key.** Entity views pass it explicitly (`'article'` for post/event/video, `'product'` for product).

Implication: **no entity column migration is required.** Entity content stays on entities. The new architecture adds a table that overlays SEO for every path; entity content remains the natural fallback when no override is set.

## Current State

### Duplicate `<title>` and `<meta name="description">` emission

These theme `PublicPage` subclasses emit their own `<title>` / `<meta name="description">` and then call `global_includes_top()`, which also emits `<meta name="description">`:

- `theme/getjoinery/includes/PublicPage.php` (lines 56, 67-68, 71)
- `theme/phillyzouk-html5/includes/PublicPage.php`
- `theme/jeremytunnell-html5/includes/PublicPage.php`
- `theme/zoukroom-html5/includes/PublicPage.php`

The base class (`includes/PublicPageBase.php:436-528`) emits `<meta name="description">` but not `<title>` — that's the structural gap the themes were working around.

### `description` vs `meta_description` split-brain (the originating bug)

`theme/getjoinery/views/*.php` pass `'description' => '...'`, which the local theme `PublicPage` reads. But `PublicPageBase::global_includes_top()` reads `'meta_description'` and falls back to `site_description`. Result: `<meta name="description">` is emitted twice with different content on every getjoinery page; OG/Twitter descriptions come from a different source than the SEO description.

### Hardcoded SEO copy in view/theme code

Marketing copy lives in PHP files:

- `theme/getjoinery/views/{index,pricing,features,about,philosophy,developers,showcase,privacy,terms}.php`
- `theme/scrolldaddy/views/{index,pricing}.php` (title, meta_description, og_title, og_description)
- `plugins/joinery_ai/views/index.php`
- `views/{blog,events,products,pricing,lists}.php` (title only)

Changing the site's social preview requires a code deploy. Multi-deployment platforms (every Joinery install has different marketing copy) can't customize per-deployment without forking themes.

### Compliant entity views (no SEO changes needed)

Entity views pull content from records and follow the documented pattern:
`views/post.php`, `views/event.php`, `views/product.php`, `views/page.php`, `views/location.php`, `views/video.php`, `views/list.php`. These continue to work unchanged — they supply entity content as `$options` so the emitter has a sensible fallback when no DB override is set.

## Solution

### Architecture

**Storage:** Single `seo_page_metadata` table keyed by request path. Rows are sparse overrides — fields default to NULL, and the emitter falls back to entity content (or site settings).

**Emission precedence**, per field (highest wins):

1. `seo_page_metadata` row value (if not NULL)
2. `$options` passed from the view (entity content for entity pages; nothing for static pages after cleanup)
3. **Inferred value** — derived from path, entity content, or related entity metadata. See [Layer 2e — Zero-config inference](#2e--zeroconfig-inference-rules). This is the layer that makes the system useful before any admin touches it.
4. Site setting (`site_name`, `site_description`, `preview_image`)

**Population:** Inventory is built proactively (install/upgrade enumeration, plus on-demand "Scan now" admin action) and lazily (on first valid pageview as a backstop, including admin previews). Per-entity save/delete hooks are deliberately omitted; see [Limitations](#limitations-of-the-lazyenumeration-approach).

### Layer 1 — Single emitter

**Goal:** `PublicPageBase::global_includes_top()` is the only place that emits `<title>`, `<meta name="description">`, `og:*`, `twitter:*`, `<link rel="canonical">`, and `<meta name="robots">`.

**Changes to `includes/PublicPageBase.php` :: `global_includes_top()`**:

1. Add the DB lookup pass (see Layer 2b). Apply precedence chain before emission.
2. Emit `<title>` from the resolved title (the same value that becomes `og:title` when no separate `og_title` is set).
3. Accept `$options['description']` as an alias for `$options['meta_description']` (`meta_description` wins if both set). Avoids breaking existing views during cleanup.
4. Auto-emit `<meta name="robots" content="noindex">` when any of:
   - `$options['is_valid_page'] === false` (404s and similar)
   - `$options['noindex'] === true`
   - The matched `seo_page_metadata` row has `spm_noindex = true`

**Remove duplicate emission from every theme `PublicPage.php` subclass.** Delete local `<title>` / `<meta name="description">` lines and any local variables used only to compute them. Rely on `global_includes_top()` (already called via `public_header_common()`).

- `theme/getjoinery/includes/PublicPage.php` (lines 53-56, 67-68)
- `theme/phillyzouk-html5/includes/PublicPage.php`
- `theme/jeremytunnell-html5/includes/PublicPage.php`
- `theme/zoukroom-html5/includes/PublicPage.php`
- Any other `theme/*/includes/PublicPage.php` found in audit

**Repo-wide grep** for `og:title`, `og:description`, `twitter:title`, `twitter:description`, `<meta name="description"`, `<title>` outside `global_includes_top()` and view files. Delete or convert any stragglers.

**Verification:** view source on one page per theme; confirm exactly one of each tag.

### Layer 2 — Centralized table and lookup

#### 2a — New data class

Create `data/seo_page_metadata_class.php` — single-row `SeoPageMetadata` extending `SystemBase`, plus `MultiSeoPageMetadata` extending `SystemMultiBase`. Schema (managed via `$field_specifications`; no migration):

| Column | Type | Notes |
|---|---|---|
| `spm_id` | serial PK | standard |
| `spm_path` | varchar(255), unique, not null | canonical request path: `/`, `/pricing`, `/post/my-cool-post` |
| `spm_entity_type` | varchar(50), nullable | `'post'`, `'event'`, `'product'`, `'page'`, `'location'`, `'video'`, `'mailing_list'`, or NULL for static routes |
| `spm_entity_id` | int, nullable | references the entity record; used to refresh `spm_path` on slug change and to cascade soft-delete |
| `spm_title` | varchar(255), nullable | overrides `<title>` (and `og:title`/`twitter:title` if no `spm_og_title`) |
| `spm_meta_description` | text, nullable | overrides `<meta name="description">` (and `og:description`/`twitter:description` if no `spm_og_description`) |
| `spm_og_title` | varchar(255), nullable | optional split: OG/Twitter title differs from SEO title |
| `spm_og_description` | text, nullable | optional split: OG/Twitter description differs from SEO description |
| `spm_preview_image_url` | varchar(500), nullable | overrides `og:image`/`twitter:image` |
| `spm_og_type` | varchar(50), nullable | overrides `og:type` |
| `spm_noindex` | boolean, default false | forces `<meta name="robots" content="noindex">` |
| `spm_create_time`, `spm_modify_time`, `spm_delete_time` | standard SystemBase fields |

Path matching is **exact** for v1. Prefix/glob is an open question (see end).

**Path canonicalization (applies to all four write/read paths — lookup, lazy insert, enumeration upsert, admin manual entry):** strip trailing slash except for the bare `/`; decode percent-escapes consistently; lowercase the path on case-insensitive routes (the platform default — confirm during implementation). All `spm_path` values stored or queried go through `SeoPageMetadata::canonicalize_path($path)` so the unique constraint never sees `/pricing` and `/pricing/` as different keys.

**Lookup filters soft-deleted rows.** The 2b SELECT must include `spm_delete_time IS NULL` (standard for `SystemBase` deletion semantics, but worth pinning here because a stale soft-deleted row would silently keep emitting its override otherwise).

`MultiSeoPageMetadata::getMultiResults()` accepts at least: `path` (exact), `entity_type`, `entity_id`, `noindex`, `has_overrides` (computed: any override field non-null), standard time filters.

#### 2b — Lookup in `global_includes_top()`

```
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$override = MultiSeoPageMetadata cached per-request lookup by exact path;
$inferred = SeoPageMetadata::infer_for_request($path, $options);  // see 2e — static method on the data class

// Precedence per field: DB row → $options (entity content) → inferred → site setting
$page_title       = $override?->spm_title             ?? $options['title']             ?? $inferred['title']             ?? $settings->get_setting('site_name');
$meta_description = $override?->spm_meta_description  ?? $options['meta_description']  ?? $inferred['meta_description']  ?? $settings->get_setting('site_description');
$og_title         = $override?->spm_og_title          ?? $options['og_title']          ?? $page_title;
$og_description   = $override?->spm_og_description    ?? $options['og_description']    ?? $meta_description;
$preview_image    = $override?->spm_preview_image_url ?? $options['preview_image_url'] ?? $inferred['preview_image']     ?? $settings->get_setting('preview_image');
$og_type          = $override?->spm_og_type           ?? $options['og_type']           ?? $inferred['og_type']           ?? 'website';
$twitter_card     = $preview_image ? 'summary_large_image' : 'summary';
$noindex          = ($options['is_valid_page'] === false) || $options['noindex'] || $override?->spm_noindex;

// Final title formatting: apply hardcoded "{title} | {site_name}" pattern (see 2e). Skip when title already equals site_name.
$page_title = SeoPageMetadata::apply_title_format($page_title, $settings->get_setting('site_name'));
```

Cache the per-request lookup (single row).

#### 2c — Inventory population

Inventory is maintained by two row-adding mechanisms plus a bounded cleanup pass inside enumeration. Per-entity save/delete hooks are deliberately omitted — see [Limitations](#limitations-of-the-lazyenumeration-approach) for the tradeoffs.

1. **Enumeration on install/upgrade, and on demand.** A new step in `update_database` (and run by `upgrade.php` automatically) seeds the table by calling `SeoPageMetadata::enumerate_public_paths()` (the same method `views/sitemap.php` calls — see [Sitemap rewrite](#sitemap-rewrite-single-source-of-truth)). The same logic is exposed as a **"Scan now"** admin button on the SEO list page for ad-hoc refresh between deploys.
   - `enumerate_public_paths()` scans `views/*.php`, `theme/{active}/views/*.php`, and `plugins/*/views/*.php` for static routes (matching the router's view-discovery logic, skipping parameterized files like `post.php` and admin paths), then runs thin-projection queries against `MultiPost`, `MultiEvent`, `MultiProduct`, `MultiPage`, `MultiLocation`, `MultiVideo`, `MultiList` for active records — selecting only the columns it needs (id, slug, modify_time, delete_time) rather than hydrating full entity rows. On large sites this is the difference between loading 50k full post records into memory and a thin ID/slug list. Each Multi class's `getMultiResults()` already accepts a column projection or can be extended trivially.
   - The enumeration step iterates the returned records and **upserts by `(spm_entity_type, spm_entity_id)` rather than by path.** If a row already exists for the entity at a different path (slug changed since last enumeration), update `spm_path` in place — custom SEO copy follows the entity to its new URL. If no row exists, insert one with `spm_entity_type` / `spm_entity_id` / `spm_path` set and all SEO fields NULL.
   - For static paths, upsert by `spm_path` (no entity reference exists).
   - **Bounded cleanup pass.** After upsert, soft-delete entity-linked rows whose `spm_entity_type` *was queried this run* but whose `spm_entity_id` is not in the returned set (the entity was deleted between runs). The cleanup is deliberately narrow — it only acts on rows for entity types enumeration has authoritative knowledge of. **Static-path rows (`spm_entity_type` NULL) are never auto-deleted, and rows tagged with entity types outside the enumerated set (plugin entities, custom types) are never auto-deleted.** Rationale and what handles those instead: see [Limitations](#limitations-of-the-lazyenumeration-approach).

2. **Lazy auto-create on pageview.** Backstop in `global_includes_top()`: if no row matches the current `$path` AND `$options['is_valid_page'] !== false` AND the matched route is not parameterized (`RouteHelper` exposes this), insert a row with `spm_path = $path` and all NULL fields.
   - **Runs for every valid pageview regardless of session type** — anonymous, authenticated user, or admin. This is intentional: admin can preview a new or draft page while logged in and have it appear in the SEO list immediately, ready for custom-copy editing before publication. No filtering by user agent, authentication state, or admin role.
   - Catches paths the enumeration missed: custom plugin routes, plugin-owned views, and ad-hoc paths that don't map to a known content type.
   - **Race-safe insert.** Two simultaneous first-visitors must not collide on the `spm_path` unique constraint. Use `INSERT ... ON CONFLICT (spm_path) DO NOTHING` (or equivalent at the model layer) so the loser's request continues normally instead of erroring. The insert is fire-and-forget for the current request — the request renders using inference + `$options` for this pageview and picks up the row on the next request.

Filter conditions across both: never create rows for query-string variants, admin paths (`/admin/*`), or invalid pages.

#### 2e — Zero-config inference rules

**Goal:** A fresh deployment with zero `seo_page_metadata` rows and entirely-empty entity `short_description` fields should still emit distinct, useful `<title>` / `<meta description>` / `og:*` tags for every page. Inference is the layer that makes the override system optional rather than required.

All inference logic lives as **static methods on `SeoPageMetadata`** (the data class created in 2a) — no separate helper class. Inference methods are pure: no instance state, no side effects, no DB writes. They're called from `global_includes_top()` after the `$options` fallback fails but before the site-settings fallback. Putting them on the data class keeps all SEO logic under one type — the row representation and the inference rules live together.

The main entry point is `SeoPageMetadata::infer_for_request($path, $options)`, which returns an array keyed by the same fields as the row (`title`, `meta_description`, `preview_image`, `og_type`). Internally it dispatches to the per-field static helpers below.

**Title inference (`SeoPageMetadata::infer_title`):**

1. If `$options['entity_type']` is set, use the entity's display name field (`pst_title`, `evt_name`, `pro_name`, etc. — already passed as `$options['title']`, so this case is already handled upstream).
2. For static paths, humanize the last path segment: `/pricing` → `Pricing`, `/about-us` → `About Us`, `/developers` → `Developers`. Replace hyphens/underscores with spaces, title-case each word, keep known acronyms uppercase (`API`, `FAQ`, `SEO`). Path `/` returns NULL (falls through to site_name).
3. For namespaced plugin views (`/foo/bar/baz`), humanize the last segment but prepend the plugin's display name from `plugin.json`: `/scrolldaddy/categories` → `Categories — ScrollDaddy` (only when the plugin has a distinct display name from the path segment).

**Title format (`SeoPageMetadata::apply_title_format`):**

Apply a hardcoded site-wide title pattern after inference resolves: `{title} | {site_name}`. When the resolved `$page_title` already equals `site_name` (e.g. the homepage with no inference hit), skip the format to avoid `Site Name | Site Name`. The pattern is a constant inside the method — not a setting. A deployment that wants a different separator (em-dash, colon, reversed order) patches the constant or overrides the method; nobody changes title separators often enough to warrant a setting and admin UI for it.

**Meta description inference (`SeoPageMetadata::infer_description`):**

1. Entity views already pass `short_description`-derived strings as `$options['meta_description']`. Inference only fires when that is empty.
2. If `$options['entity_body_html']` (or equivalent — the entity's main content field) is present, strip HTML, collapse whitespace, and truncate to ~160 characters at a sentence or word boundary. Helper: `SeoPageMetadata::summarize_html($html, $max_length = 160)`. **Multi-byte safe** — use `mb_substr` / `mb_strlen` so UTF-8 characters aren't split mid-byte (would produce mojibake in the meta tag). Truncate at the last whitespace inside the limit, not a hard cut; append `…` only if truncation occurred. Entity views opt into this by passing their content field as `$options['entity_body_html']`; this is a one-line addition per entity view (`post.php`, `event.php`, etc.) and is the only entity-view change needed for description inference.
3. For static paths, no description inference — falls through to `site_description`.

**Image inference (`SeoPageMetadata::infer_preview_image`):**

1. Entity og_image picture role already passes through as `$options['preview_image_url']`.
2. If absent and `$options['entity_body_html']` is set, extract the first `<img src="...">` from the stripped body content. Skip data URIs and tiny tracking pixels (heuristic: skip if URL contains `pixel`, `tracking`, `1x1`, or has obvious dimension hints `=1` in query string).
3. Falls through to `site_preview_image` setting, then site logo as the final visual fallback.

**Known limitation:** the body-image extractor handles plain `<img src="...">` only. It does not look at `<picture>`/`<source srcset>`, `<img data-src="...">` (lazy-load), or CSS background images. That's acceptable for v1 — the entity og_image picture role is the primary image source and is set on entity pages that care about social previews. If a deployment relies on lazy-loaded body images, the admin can override `spm_preview_image_url` on the row.

**All resolved URLs are absolutized before emission.** Facebook and Twitter reject relative `og:image` / `twitter:image` URLs, and the first-body-image extractor naturally produces relative paths like `/uploads/foo.jpg`. The emitter passes the resolved image URL (and the canonical URL) through `SeoPageMetadata::absolutize_url($url, $site_url)` before emitting, so relative paths become `https://site.example/uploads/foo.jpg`. URLs that are already absolute pass through unchanged.

**`og_type` inference (`SeoPageMetadata::infer_og_type`):**

Map `$options['entity_type']` to OG type when not explicitly set: `post` / `event` / `video` → `article`; `product` → `product`; `mailing_list` / `page` / `location` / any other entity type → `website`; no entity_type → `website`. Once this inference exists, entity views can drop their explicit `og_type` passing in a follow-up cleanup pass (out of scope for v1 — keep the explicit values as belt-and-suspenders).

**Twitter card type:**

Already shown in 2b: if a `$preview_image` resolves at all (override, options, or inferred), emit `twitter:card = summary_large_image`; otherwise `summary`. No separate config needed.

**Canonical URL:**

Always emit `<link rel="canonical" href="{site_url}{path}">` from the current request path. Query strings stripped. (This is inference too, but unconditional — no precedence chain needed since there's no good reason for a per-page override at v1 scope. If a deployment needs canonical overrides later, add `spm_canonical_url`.)

**Admin UI placeholders reflect inferred values, not "empty".**

The placeholder text shown in `admin_seo_page_edit.php` (Layer 2d) must render the *inferred* default for that path, not just the site setting. Admin opening the edit form for `/pricing` with no row sees `Defaults to: Pricing | Joinery` in the title placeholder, not `Defaults to: Joinery`. This makes the inference layer visible — admin can confirm at a glance that the defaults are reasonable before deciding whether to override.

**Why inference is computed at render time, not stored in rows:**

If enumeration wrote inferred values into `seo_page_metadata` columns, those values would go stale the moment site_name changed, the title format changed, or entity content was edited. Keeping inference render-time means a single site-name rename propagates to every page's `<title>` without rebuilding rows, and admin always sees the *current* default in placeholders. Rows store only true overrides; the inference layer recomputes defaults on every request.

#### 2d — Admin UI

Two new admin pages under the Joinery System theme (vanilla HTML5):

- **`adm/admin_seo_pages.php`** — list view. Columns: path, entity type (or "static"), title (truncated, with "default" indicator when null), meta description (truncated), noindex flag, modified date. Filters: entity type, has-overrides, noindex, search by path. Link to edit form. "Add path" button for manual entry. **"Scan now"** button to run enumeration on demand (re-uses the install/upgrade logic; idempotent — also runs the bounded auto-cleanup pass). **"Find orphans"** view lists the rows that auto-cleanup deliberately doesn't touch but look dead: static-path rows whose `spm_path` no longer routes anywhere, and rows tagged with entity types outside the core enumeration loop (plugin entities, etc.) whose referenced record can no longer be located. Admin reviews and bulk-soft-deletes from there.
- **`adm/admin_seo_page_edit.php`** — FormWriter form: path (read-only for entity-linked rows; editable for static), title, meta description, og_title, og_description, preview image, og_type, noindex. Each field shows the resolved fallback value as placeholder text (e.g. "Defaults to: Joinery") so admin sees what will be emitted if they leave it blank. For entity-linked rows, add an "Edit underlying content" link to the entity's admin edit page. **Computing the placeholder for entity rows requires loading the entity** (so `infer_for_request` can see `$options['title']`, `$options['entity_body_html']`, etc. exactly as the public-view render would). The form handler loads the entity by `(spm_entity_type, spm_entity_id)` and builds the equivalent `$options` array. For static rows, just call `SeoPageMetadata::infer_for_request($spm_path, [])`.

Admin nav: new "SEO" group under existing Settings (or wherever is structurally cleanest; decide during implementation).

### Layer 3 — Strip hardcoded SEO from view code

**Goal:** No view file contains SEO copy strings. All SEO either lives in `seo_page_metadata` rows or comes from entity content fields.

1. **`theme/getjoinery/views/*.php`** — delete `title`, `description`, `meta_description` keys from `public_header()` calls in all 9 marketing views (index, pricing, features, about, philosophy, developers, showcase, privacy, terms). Leave structural options (`showheader`, etc.).
2. **`views/{blog,events,products,pricing,lists}.php`** — delete `title` keys from index/static views.
3. **`theme/scrolldaddy/views/{index,pricing}.php`** — delete hardcoded `title`, `meta_description`, `og_title`, `og_description`.
4. **`plugins/joinery_ai/views/index.php`** — delete hardcoded `title` and `meta_description`.
5. **Entity views unchanged.** `post.php`, `event.php`, `product.php`, `page.php`, `location.php`, `video.php`, `list.php`, and theme variants — continue to pass entity content into `$options`. This content is the fallback when DB row fields are null; once an admin sets per-row SEO overrides, those win.
6. **Audit** the rest of the codebase via the Layer 1 grep results.

### Layer 4 — Auto-noindex for invalid pages

Already covered in Layer 1 (`is_valid_page === false` → `noindex`). Verify `views/404.php` and any view that explicitly sets `is_valid_page = false` get the tag.

## Implementation Steps

Each layer is independently shippable and testable. Order matters within a layer; layers can interleave once their prerequisites are in.

1. **Layer 2a** — create `data/seo_page_metadata_class.php` with the row schema **and** the static inference methods from 2e (`infer_for_request`, `infer_title`, `infer_description`, `infer_preview_image`, `infer_og_type`, `apply_title_format`, `summarize_html`). Inference methods are pure-function statics — unit-testable without DB or session, but live on the same class as the row so all SEO logic is in one place. Run `update_database` to create the table.
2. **Layer 1 + Layer 2b + 2e** — modify `PublicPageBase::global_includes_top()`: DB lookup, fallback chain (including inference), emit `<title>`, alias `description`, auto-noindex, auto-canonical, twitter-card auto-select. Test with manually-inserted rows AND with no rows at all (confirm inference produces distinct titles per path).
3. **Layer 1, theme cleanup** — delete duplicate emission from every theme `PublicPage.php` subclass.
4. **Layer 1, audit** — repo-wide grep; delete stragglers.
5. **Layer 2c, enumeration** — add the install/upgrade seeding step (upserting by entity reference). Run on the test site; confirm rows populate for all existing content + static views without duplicates and that slug changes reconcile on re-run.
6. **Layer 2c, lazy backstop** — add auto-create in `global_includes_top()` for paths the enumeration missed. Confirm admin pageviews seed rows the same as anonymous ones.
7. **Layer 2d, list UI** — build `adm/admin_seo_pages.php` including "Scan now" and "Find orphans" actions.
8. **Layer 2d, edit UI** — build `adm/admin_seo_page_edit.php`. Placeholders must display the *inferred* defaults (from Layer 2e) rather than just site settings.
9. **Layer 3** — strip hardcoded SEO from view code (theme/getjoinery, theme/scrolldaddy, plugins/joinery_ai, core static views). Verify pages render with **inferred** defaults when no override row exists — every static page should have a distinct `<title>` and reasonable meta description with zero config.
10. **Entity view minor update** — entity views (`post.php`, `event.php`, `product.php`, etc.) add one `$options['entity_body_html'] = $entity->get('body_field')` line so the description-inference helper can summarize body content when `short_description` is empty.
11. **Sitemap rewrite** — replace `views/sitemap.php` body with a thin XML wrapper around `SeoPageMetadata::enumerate_public_paths()`. For each record, left-join `seo_page_metadata` to read `spm_noindex` and `spm_modify_time`; skip noindex rows; emit `<lastmod>` from the entity's modify_time (or `spm_modify_time` for static rows). Verify on test site that products, videos, static pages, and plugin routes now appear in `/sitemap.xml` — none of which the current sitemap emits.
12. **Production data entry** — on each deployment, admin only needs to override pages where inference falls short. Most static and entity pages will work acceptably with no rows at all.
13. **Docs** — update `docs/seo_metadata.md`:
    - Document `seo_page_metadata` as the single SEO storage location.
    - Document precedence: DB row → entity content (via `$options`) → **inferred default** → site settings.
    - Document the static inference methods on `SeoPageMetadata` and each field's inference rule, so theme/plugin authors know what to expect when they pass nothing.
    - Document the hardcoded `{title} | {site_name}` title format and where to patch the constant if a deployment needs a different separator.
    - Document entity content fields as fallback only; entity views must continue passing them as `$options`, plus the new `entity_body_html` option for description/image inference.
    - Document that theme/static view code must not contain hardcoded SEO strings.
    - Document `is_valid_page === false` → auto-noindex.
    - Document that theme `PublicPage` subclasses must **not** emit head tags themselves.
    - Document inventory population (install/upgrade enumeration, "Scan now" admin action, lazy auto-create on any valid pageview including admin previews).
    - Document the bounded auto-cleanup pass (what it deletes, what it deliberately doesn't touch) and the "Find orphans" admin workflow for everything else.
    - Document the remaining limitations of the lazy/enumeration approach (new-entity lag, slug-change propagation gap, plugin entity caveats).

## Files Changed

**Core:**
- `includes/PublicPageBase.php` — DB lookup + fallback chain + inference call, emit `<title>` and canonical, alias `description`, auto-noindex, auto twitter-card type, lazy auto-create.

**New:**
- `data/seo_page_metadata_class.php` — `SeoPageMetadata` (row model) + `MultiSeoPageMetadata` (collection) + static inference methods (`infer_for_request`, `infer_title`, `infer_description`, `infer_preview_image`, `infer_og_type`, `apply_title_format`, `summarize_html`) + static enumeration method (`enumerate_public_paths`, shared by SEO row-population and sitemap). One class file, all SEO logic.
- `adm/admin_seo_pages.php` — list UI.
- `adm/admin_seo_page_edit.php` — edit UI (placeholders show inferred defaults).

**Install/upgrade:**
- `utils/update_database.php` (or equivalent post-step) — add seeding step. Entity data classes are **not** modified.

**Entity views (one-line addition for description/image inference):**
- `views/post.php`, `views/event.php`, `views/product.php`, `views/page.php`, `views/location.php`, `views/video.php`, `views/list.php` — pass `entity_body_html` option alongside existing entity-content options. Theme variants of these views likewise.

**Sitemap (rewritten as thin wrapper around shared enumeration):**
- `views/sitemap.php` — replace body with iteration over `SeoPageMetadata::enumerate_public_paths()`; left-join `seo_page_metadata` for `spm_noindex` filter and `spm_modify_time` lastmod fallback. Gains products, videos, static pages, plugin routes — all currently missing.

**Themes (delete duplicate emission):**
- `theme/getjoinery/includes/PublicPage.php`
- `theme/phillyzouk-html5/includes/PublicPage.php`
- `theme/jeremytunnell-html5/includes/PublicPage.php`
- `theme/zoukroom-html5/includes/PublicPage.php`
- Any other `theme/*/includes/PublicPage.php` found in audit.

**Views (strip hardcoded SEO):**
- `theme/getjoinery/views/{index,pricing,features,about,philosophy,developers,showcase,privacy,terms}.php`
- `theme/scrolldaddy/views/{index,pricing}.php`
- `plugins/joinery_ai/views/index.php`
- `views/{blog,events,products,pricing,lists}.php`

**Docs:**
- `docs/seo_metadata.md` — full rewrite per step 11.

## Sitemap rewrite (single source of truth)

The existing `views/sitemap.php` is incomplete: it omits Products entirely, loads `videos_class.php` but never iterates videos, has no static or marketing pages, no plugin routes, and every `lastmod` line is commented out. Building thorough URL discovery for SEO and leaving the sitemap to its parallel-and-incomplete logic is the wrong shape — the platform should have one source of truth for "what URLs does this site have," consumed by both systems.

**Factor enumeration into a public method on the data class.** `SeoPageMetadata::enumerate_public_paths()` returns the canonical list of public URLs as an array of records: `['path' => ..., 'entity_type' => ..., 'entity_id' => ..., 'modify_time' => ...]`. The method encapsulates:

- Filesystem scan of `views/*.php`, `theme/{active}/views/*.php`, `plugins/*/views/*.php` for static routes (matching router discovery rules).
- Thin-projection queries against `MultiPost`, `MultiEvent`, `MultiProduct`, `MultiPage`, `MultiLocation`, `MultiVideo`, `MultiList` for active records — only the columns needed (id, slug/path, modify_time, delete_time).
- Filtering of admin paths, parameterized routes for static scan, query-string variants.

**Two consumers call it:**

1. **SEO row-population step** (Layer 2c): iterates the returned records, upserts `seo_page_metadata` rows by `(spm_entity_type, spm_entity_id)` for entity rows or by `spm_path` for static rows, runs the bounded auto-cleanup pass.
2. **`views/sitemap.php`** (rewritten): iterates the same records, joins each against `seo_page_metadata` to read `spm_noindex` and `spm_modify_time`, emits XML. URLs with `spm_noindex = true` are excluded. `lastmod` uses the entity's `modify_time` for entity URLs, `spm_modify_time` for static URLs, falling back to current date if nothing is set.

**Net result:** the sitemap automatically gains every entity type the platform has (including the currently-missing Products and Videos), every static and marketing page, every plugin route — and stays in sync with the SEO table because they share their discovery code. The current sitemap's ~100 lines of partial coverage become a thin XML wrapper around the enumeration call.

**Caching note:** sitemap fetches happen a handful of times per day from crawlers, and enumeration's filesystem scan plus seven thin entity queries are cheap. Add CDN/Apache caching headers if any deployment shows pressure.

## Out of Scope

- `robots.txt` generation (already in good shape; see `views/robots.php`). `robots.txt` blocks crawling; `spm_noindex` blocks indexing. Orthogonal concerns.
- Per-entity `keywords` meta tag (low SEO value in 2026).
- Structured data / JSON-LD (`schema.org`) — separate spec if desired.
- Prefix/glob path matching (see open questions).
- Moving entity *content* into the SEO table. Content stays on entities — this spec only adds an SEO override layer on top.

## Limitations of the lazy/enumeration approach

This spec deliberately omits per-entity save/delete hooks in favor of upgrade-time enumeration plus lazy-on-pageview population. That keeps the platform surface area small (no hook to add in every entity class, no extension contract for plugin entities), but it has real tradeoffs admins should understand:

- **New entities lag the SEO list.** A freshly created post does not appear in `/admin/admin_seo_pages` until either (a) someone visits its URL — admin preview counts (Layer 2c step 2), or (b) the next enumeration run (next `update_database`, next deploy, or admin clicks "Scan now"). Admins who want to set custom SEO before publication should preview the entity's page first, or click "Scan now" after creating it.
- **Slug changes don't propagate until next enumeration.** Entity paths are excluded from lazy auto-create (parameterized routes), so changing a post's slug means the row's `spm_path` keeps the old value until the next enumeration run reconciles it. During the gap, visitors to the new path see entity content fallback (no row override applied) instead of the custom SEO copy admin previously set. The row itself isn't lost — enumeration upserts by `(spm_entity_type, spm_entity_id)`, so custom copy follows the entity to its new path once enumeration catches up.
- **Auto-cleanup is bounded to what enumeration authoritatively knows.** The cleanup pass inside enumeration only soft-deletes entity-linked rows for the entity types it actually queried this run. This is by design: enumeration has the full live set for `Post`/`Event`/`Product`/`Page`/`Location`/`Video`/`MailingList` because it just queried them, so it can confidently say "entity 42 is gone." It does *not* have that authority over static-path rows (a row at `/foo` could be a removed view, a current plugin route without a matching view file, an admin-added path, or a lazy-auto-created path that still routes) or over rows tagged with entity types outside the core loop (plugin entities). So those rows are never auto-deleted — they're surfaced in "Find orphans" (Layer 2d) for human review and manual cleanup.
- **Static-path orphans persist until admin removes them.** A static-path row whose route no longer exists (view file deleted, plugin uninstalled) stays in the table until admin reviews it via "Find orphans" and bulk-soft-deletes. The trade is: auto-cleanup never deletes a real route just because enumeration didn't find it.
- **Plugin-owned entities participate via lazy auto-create only.** The enumeration step only knows about the core entity types listed in Layer 2c step 1. Plugin entities get rows the first time their pages are visited (admin preview included), not at install/upgrade time, and they're never part of the auto-cleanup pass. If a plugin wants its entities seeded proactively *and* auto-cleaned, it can opt in by registering its `Multi` class with the enumeration loop — out of scope for this spec.

## Verification Checklist

- [ ] Each theme: view source on homepage and one entity page. Exactly one `<title>`, one `<meta name="description">`, one each of `og:` and `twitter:` tags.
- [ ] No theme `PublicPage.php` file contains `<title>` or `<meta>` emission.
- [ ] No view file (static or marketing) contains hardcoded SEO copy strings. Entity views still supply entity content as `$options`.
- [ ] Admin can add an override row for `/` and see the homepage's `<title>` / `og:title` update on next reload.
- [ ] Admin can edit an existing row and changes propagate immediately.
- [ ] A page with no matching row falls back to entity content (for entity paths) or inferred defaults / site settings (for static paths) cleanly.
- [ ] **Zero-config baseline:** with **no** `seo_page_metadata` rows at all, every static page (`/`, `/pricing`, `/about`, `/features`, etc.) emits a *distinct* `<title>` (path-segment-humanized) — not all `Joinery` / all `Site Name`.
- [ ] Title format `{title} | {site_name}` applied site-wide by `apply_title_format`; homepage (where resolved title equals site_name) emits just `Joinery` rather than `Joinery | Joinery`.
- [ ] Entity page with empty `short_description` but non-empty body field: `<meta description>` is summarized from body HTML (~160 chars, sentence-aware), not a generic site fallback.
- [ ] Entity page with no `og_image` picture role but with an image in body content: `og:image` resolves to the first body image (data URIs and tracking-pixel patterns skipped).
- [ ] Entity view that does **not** pass `og_type`: inference picks `article` for post/event/video, `product` for product, `website` otherwise.
- [ ] Pages with a resolved preview image emit `twitter:card = summary_large_image`; pages without one emit `twitter:card = summary`.
- [ ] `<link rel="canonical">` is emitted on every page from the current request path with query strings stripped.
- [ ] Admin edit form placeholders for a never-overridden path show the *inferred* defaults (e.g. `Defaults to: Pricing | Joinery`) — not the bare site setting.
- [ ] Path canonicalization: visiting `/pricing/` after `/pricing` already has a row does **not** create a second row, and the override applies on both forms.
- [ ] Soft-deleting a row via admin (or via cleanup) immediately stops emitting its override on the next pageview; the page falls back to inference/site settings.
- [ ] Resolved `og:image` and `twitter:image` are always absolute URLs (verify via Facebook Sharing Debugger on a page whose image came from body-content extraction).
- [ ] Resolved `<link rel="canonical">` is an absolute URL.
- [ ] `summarize_html` on UTF-8 body content (e.g. a post containing emoji or accented characters) truncates at a word boundary and produces valid UTF-8 in the meta tag (no mojibake, no split characters).
- [ ] Visiting a new public path *while logged in as admin* creates a row via lazy auto-create (i.e., admin pageviews are not filtered out).
- [ ] Visiting a non-parameterized path that doesn't yet have a row auto-creates one; visiting an entity path that doesn't yet have a row does **not** (entity paths only enter via enumeration).
- [ ] Running enumeration ("Scan now") twice produces no duplicates (idempotent upsert).
- [ ] Renaming a post's slug, then running enumeration, updates the row's `spm_path` and preserves any custom SEO copy set on the row.
- [ ] Hard-deleting a post and then running enumeration auto-soft-deletes the post's row (cleanup pass acts on queried entity types).
- [ ] Auto-cleanup does **not** touch static-path rows or rows tagged with entity types outside the core enumeration loop, even when enumeration runs.
- [ ] "Find orphans" surfaces static-path rows whose path no longer routes, and rows tagged with entity types outside the core loop — anything the bounded auto-cleanup deliberately leaves alone.
- [ ] 404 pages emit `<meta name="robots" content="noindex">`.
- [ ] Setting `spm_noindex = true` on a row excludes that path from `views/sitemap.php` output on the next request (no contradictory signals to Google).
- [ ] Sitemap output includes products, videos, static marketing pages (`/`, `/pricing`, `/about`, etc.), and plugin routes — every public URL `enumerate_public_paths()` returns, not just the four entity types the old sitemap covered.
- [ ] Sitemap `<lastmod>` is populated (entity modify_time for entity rows, `spm_modify_time` for static rows), not commented out as in the previous implementation.
- [ ] Install/upgrade seeding populates rows for all existing content + static views without duplicating existing rows.
- [ ] `getjoinery` homepage in Facebook Sharing Debugger / Twitter Card Validator: preview matches the DB row; no duplicate-tag warnings.
- [ ] Entity views (post, event, product, page, location, video, list) still render correctly with no override row (entity content fallback works).

## Decisions

These were open during early drafting and are now resolved. Captured here so the implementer doesn't have to re-litigate them:

- **`og_type` is inferred at render time** from `entity_type` (Layer 2e). Entity views can continue passing it explicitly as belt-and-suspenders, or drop it in a follow-up cleanup pass.
- **Enumeration runs on every `update_database`** (install and upgrade), idempotent. Fresh installs get an empty table that fills as admin adds content; upgrade-in-place sites get full population on first run.
- **Parameterized-route rows lag enumeration by design.** A freshly-created entity won't have a row in `admin_seo_pages` until the next "Scan now" or `update_database`, but the entity page still renders correct SEO via `$options` + inference. This is the intended tradeoff — render-time inference covers the gap without polluting the table with rows the admin doesn't need to see immediately.
- **Acronym list for title humanization is hardcoded in v1** (`API`, `FAQ`, `SEO`, `URL`, `RSS`, `JSON`, etc.). If a deployment needs to extend it, we add a `seo_title_acronyms` setting then.

## Open Design Questions

These are real future-decision points; defer until a deployment needs them:

1. **Path matching strategy.** v1 is exact-match. Prefix (`/blog/*`) or glob would enable "default OG image for everything under `/blog/*`" or path-pattern noindex. Adds precedence rules (which row wins when multiple match?). Defer until a real use case appears.
2. **Cross-request caching.** Per-request cache is fine for v1 (single indexed row lookup per pageview). If a high-traffic deployment shows latency from this, key a shared cache by canonical path with invalidation on row save.
3. **Scheduled enumeration cadence.** Enumeration runs at install/upgrade and on demand via "Scan now". A cron would shorten the slug-change propagation gap and keep the "Find orphans" backlog smaller, but recommend no for v1 — manual "Scan now" is enough on most deployments. Revisit if it becomes operationally annoying.
4. **Class-level `$noindex` on entity data classes** (`events_class`, `event_registrants_class`, `event_sessions_class`). Appears to be a class-level marker (not record-level) for admin/internal pages rather than public SEO. Confirm during implementation that it's unrelated to public emission and leave it alone — or fold into this architecture if it turns out to overlap. Honest implementation-time check, not a design decision.
