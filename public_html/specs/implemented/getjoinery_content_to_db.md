# Spec: getjoinery Theme — Site Copy to Database

**Status:** Active  
**Created:** 2026-05-04  
**Depends on:** `specs/component_library_html5.md` (must be completed first)

---

## Goal

Move all hardcoded site-specific copy out of the getjoinery theme PHP view files into
database-backed component instances. After this work, the `getjoinery` theme contains no
brand-specific text — only structural PHP that calls `ComponentRenderer::render('slug')`. A second
deployment (e.g. getjoinery.org) points its `theme_template` at `getjoinery`, seeds its own copy
into the same slug names, and gets a fully independent site with no code changes.

---

## Prerequisite

`custom_html.php` must output raw HTML with no wrapper (per `specs/component_library_html5.md`).
All stored HTML in this spec includes its own `<section>` and container markup.

---

## Approach

Each view file becomes one `ComponentRenderer::render('slug')` call. All content is stored as
a single `custom_html` component instance per page (`PageContent` row with `pac_location_name`
as the slug and `pac_config['html']` holding the complete page body markup).

Nine slugs total — one per view file.

---

## Slug Convention

All slugs are prefixed `gj-` to namespace them away from other deployments' instances.
Format: `gj-{page}`.

---

## Instance Catalog

| Slug | View File | Contains |
|------|-----------|----------|
| `gj-home` | `index.php` | Full home page body (hero, trust badges, feature grid, comparison, audience, pricing teaser, CTA) |
| `gj-about` | `about.php` | Full about page body (hero, project, creator, why solo, contact, CTA) |
| `gj-philosophy` | `philosophy.php` | Full philosophy page body (hero, problem, why built, commitments, business model, CTA) |
| `gj-features` | `features.php` | Full features page body (hero, 9 feature sections with alternating backgrounds, CTA) |
| `gj-pricing` | `pricing.php` | Full pricing page body (hero, billing toggle, tiers, self-host, commercial license, CTA) |
| `gj-developers` | `developers.php` | Full developers page body (hero, architecture, security, REST API, plugins, themes, self-hosting, CTA) |
| `gj-showcase` | `showcase.php` | Full showcase page body (hero, ScrollDaddy card, more coming, CTA) |
| `gj-terms` | `terms.php` | Full terms of service page body |
| `gj-privacy` | `privacy.php` | Full privacy policy page body |

---

## View Structure After

Each view file shrinks to:

```php
<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));

$page = new PublicPage();
$page->public_header(['title' => '...', 'description' => '...', 'showheader' => true]);
echo ComponentRenderer::render('gj-page');
$page->public_footer();
?>
```

No content, no copy, no component config arrays. All brand-specific text lives in the database.

Note: `terms.php` and `privacy.php` already omit the ComponentRenderer require — it must be added
when the views are updated.

---

## Seeder Script

Create `utils/seed_getjoinery_content.php`. This script:

1. Looks up the `com_component_id` for `custom_html` from `com_components`
2. For each slug in the catalog above, checks whether a `PageContent` row with that
   `pac_location_name` already exists
3. If it does not exist, inserts a new row with `pac_config['html']` set to the complete
   page body HTML extracted verbatim from the current view file
4. Outputs a log line per slug: `[created]` or `[skipped]`
5. Is idempotent — safe to run multiple times

The HTML stored for each slug is self-contained: it includes all `<section>` wrappers,
inline CSS, and any inline `<script>` tags (e.g. the pricing billing toggle JS). The only
things excluded are the `public_header()` / `public_footer()` calls.

**Usage:**
```
php utils/seed_getjoinery_content.php
```

The seeder should be committed to source control so it ships with the theme and can be run on any
new deployment that uses the getjoinery theme.

---

## `public_header()` Titles

The `public_header()` call in each view has a hardcoded `title` and `description` containing
"Joinery". These are meta tags, not rendered content, so they are out of scope for the component
system. They should be handled separately by pulling the brand name from a `site_name` setting
via string substitution in each view. This is a minor change and can be deferred.

---

## Admin Experience

After seeding, all content is editable at `/admin/admin_page_content`. Each instance appears by
its slug and optional `pac_title`. Editors use the rich text editor to update copy. No code
deployment required for content changes.

---

## Deployment to a Second Site

To deploy the same theme to getjoinery.org (or any second brand):

1. Deploy the codebase (theme ships with it; no code changes needed)
2. Set `theme_template = getjoinery` in that site's settings
3. Run `php utils/seed_getjoinery_content.php` — this seeds default copy
4. Edit each instance via admin to replace getjoinery.com copy with the new brand's copy

The two sites are now fully independent at the content layer. Code changes to the theme structure
ship to both; copy changes to either site do not affect the other.
