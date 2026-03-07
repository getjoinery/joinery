# Promote Canvas-HTML5 Views to Base Views

## Goal

Replace the Bootstrap-markup base views in `/views/` with the vanilla HTML5 views from `theme/canvas-html5/views/`. Make the Falcon theme override with Bootstrap markup where needed. After this, the HTML5 design is the system default for all themes, and Bootstrap is opt-in via Falcon.

## Background

The `canvas-html5` theme contains 22 view overrides that replace Bootstrap markup with clean vanilla HTML5+CSS. These views have been built, tested, and verified. The goal now is to promote them to the system default so that:

- `/views/` = vanilla HTML5 (canvas-html5 design)
- `theme/falcon/views/` = Bootstrap overrides (restores current Falcon behavior)
- `theme/canvas-html5/` is removed (its files are now the base)
- New themes extend the HTML5 base by default

## Scope

### In Scope
- All 22 canvas-html5 view files
- 4 base views not yet covered by canvas-html5 (`booking.php`, `change-password-required.php`, `location.php`, `video.php`) — convert during this work
- `theme/canvas-html5/includes/PublicPage.php` → promote to `/includes/`
- `theme/canvas-html5/assets/` → promote to a shared asset location
- Creating `theme/falcon/views/` overrides for all views that use Bootstrap markup

### Out of Scope
- Admin interface (stays on Falcon/Bootstrap)
- Non-HTML base views: `cart_charge.php`, `cart_clear.php`, `logout.php`, `robots.php`, `rss20_feed.php`, `sitemap.php` — pure PHP redirects or text/XML output, no markup changes needed
- Plugin views
- Email templates

## Current State

### Canvas-HTML5 Views (22 files — ready to promote)
All tested and verified:
- `404.php`, `blog.php`, `cart.php`, `cart_confirm.php`
- `event.php`, `events.php`, `event_waiting_list.php`
- `index.php`, `list.php`, `lists.php`, `login.php`
- `password-reset-1.php`, `password-reset-2.php`, `password-set.php`
- `post.php`, `pricing.php`, `product.php`, `products.php`
- `register.php`, `site-directory.php`, `survey.php`, `survey_finish.php`

### Base Views NOT Yet Converted (4 files — convert during this work)
- `booking.php` — event booking form, has Bootstrap markup
- `change-password-required.php` — password change form, has Bootstrap markup
- `location.php` — location detail page, has Bootstrap markup
- `video.php` — video page, has Bootstrap markup

### Base Views Requiring No Changes (leave in place)
- `page.php` — already uses canvas-compatible classes, no Bootstrap dependency
- `cart_charge.php`, `cart_clear.php`, `logout.php` — pure PHP redirects, no HTML
- `robots.php`, `rss20_feed.php`, `sitemap.php` — text/XML output

## Implementation Plan

### Phase A: Convert the 4 Remaining Base Views

For each of `booking.php`, `change-password-required.php`, `location.php`, `video.php`:
1. Read the base view
2. Create `theme/canvas-html5/views/{view}.php` (or convert in place if simpler)
3. Replace Bootstrap classes with inline styles / canvas-html5 equivalents
4. Test in browser with canvas-html5 theme active

### Phase B: Create Falcon Theme View Overrides

Create `theme/falcon/views/` directory. For each view that the canvas-html5 theme overrides, the **current base view** (Bootstrap markup) becomes a Falcon override:

```
cp /views/{view}.php → /theme/falcon/views/{view}.php
```

For each of the 22 canvas-html5 views plus the 4 newly converted ones, copy the **current Bootstrap base view** to `theme/falcon/views/` before overwriting the base.

Views to copy to Falcon (current base views with Bootstrap markup):
- All 22 canvas-html5-covered views (copy originals to `theme/falcon/views/`)
- `booking.php`, `change-password-required.php`, `location.php`, `video.php` (convert for canvas-html5, keep Bootstrap version for Falcon)

**Important:** `page.php` does NOT need a Falcon override — it already works with both.

### Phase C: Promote PublicPage.php

The canvas-html5 `PublicPage.php` (extends `PublicPageBase`) becomes the default concrete `PublicPage` in `/includes/`:

```
theme/canvas-html5/includes/PublicPage.php → /includes/PublicPage.php
```

Update `/includes/PublicPage.php` to be the system default (non-Bootstrap). Falcon theme keeps its own `theme/falcon/includes/PublicPage.php` which extends `PublicPageFalcon`.

**Note on the theme resolution chain:** `PathHelper::getThemeFilePath('PublicPage.php', 'includes')` already checks `theme/{theme}/includes/` first, then falls back to `/includes/`. So Falcon will continue to use `PublicPageFalcon` via its theme override. Other themes without a `PublicPage.php` override will get the new HTML5 default.

### Phase D: Promote CSS/JS Assets

Move canvas-html5 assets to a shared location so the default base can reference them:

```
theme/canvas-html5/assets/css/style.css   → /assets/css/html5-theme/style.css
theme/canvas-html5/assets/css/custom.css  → /assets/css/html5-theme/custom.css
theme/canvas-html5/assets/js/script.js    → /assets/js/html5-theme/script.js
```

Update the new `/includes/PublicPage.php` to reference assets from `/assets/css/html5-theme/` instead of the old theme path.

**Alternative (simpler):** Keep `theme/canvas-html5/` assets in place but have `/includes/PublicPage.php` load them via `ThemeHelper::asset()` with a fallback path. This avoids moving asset files.

### Phase E: Promote Views

For each of the 22 canvas-html5 views:
```
cp theme/canvas-html5/views/{view}.php → /views/{view}.php
```

This replaces the Bootstrap base view. Since Falcon already has its copy (Phase B), Falcon users continue to see Bootstrap markup via `theme/falcon/views/{view}.php`.

### Phase F: Remove Canvas-HTML5 Theme Directory

Once all files are promoted:
1. Delete `theme/canvas-html5/views/` (now redundant — same as base)
2. Delete `theme/canvas-html5/includes/PublicPage.php` (now in `/includes/`)
3. Optionally keep `theme/canvas-html5/` as a minimal stub (just `theme.json`) if any sites still reference the theme name
4. Or remove entirely and switch any sites using `canvas-html5` theme to use `default`/`html5` theme setting

### Phase G: Testing

For each theme (Falcon, canvas-html5/default, any others):
1. Switch test site to that theme
2. Verify all major public pages render correctly
3. Verify login/register/password-reset flows
4. Verify cart/checkout end-to-end
5. Verify admin interface unchanged on Falcon

## File Movement Summary

| File | Action |
|------|--------|
| `theme/canvas-html5/views/*.php` (22 files) | Copy → `/views/` (overwrite Bootstrap originals) |
| `/views/*.php` (Bootstrap originals, 22 files) | Copy → `theme/falcon/views/` before overwriting |
| `booking.php`, `change-password-required.php`, `location.php`, `video.php` | Convert for HTML5; Bootstrap versions → `theme/falcon/views/` |
| `theme/canvas-html5/includes/PublicPage.php` | Copy → `/includes/PublicPage.php` |
| `theme/canvas-html5/assets/` | Keep in place OR copy to `/assets/css/html5-theme/` |
| `theme/canvas-html5/` | Remove (or keep as empty stub with theme.json) |

## Risk Assessment

| Risk | Mitigation |
|------|------------|
| Falcon breaks after promotion | Copy current Bootstrap views to `theme/falcon/views/` BEFORE overwriting base |
| Other themes break | Check all active themes; most only override admin-facing includes |
| Asset paths break | Update `PublicPage.php` asset references before removing theme directory |
| `page.php` regression | Verify it still renders under both Falcon and default theme after change |
| Views reference `PublicPageFalcon` directly | Search and verify no views import Falcon classes directly |

## Success Criteria

1. `/views/` contains vanilla HTML5 markup with no Bootstrap dependencies
2. Falcon theme renders identically to today via `theme/falcon/views/` overrides
3. A site with no theme set (or `theme=default`) renders the HTML5 design
4. Admin interface continues to work unchanged
5. All public pages tested under both Falcon and default/HTML5 themes
6. No Bootstrap, jQuery, or Canvas framework JS/CSS loaded on the default public pages
