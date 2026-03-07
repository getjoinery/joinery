# Promote Canvas-HTML5 Views to Base Views

## Goal

Replace the Bootstrap-markup base views in `/views/` with the vanilla HTML5 views from `theme/canvas-html5/views/`. After this, the HTML5 design is the system default and `theme/canvas-html5/` can be removed.

## Background

The `canvas-html5` theme contains 37 view overrides that replace Bootstrap/Tailwind markup with clean vanilla HTML5+CSS. These views have been built, tested, and verified. The goal now is to promote them to the system default so that:

- `/views/` = vanilla HTML5 (canvas-html5 design)
- `theme/canvas-html5/` is removed (its files are now the base)
- New themes extend the HTML5 base by default

## Scope

### In Scope
- All 37 canvas-html5 view files (26 top-level + 11 profile/ subdirectory)
- `theme/canvas-html5/includes/PublicPage.php` → promote to `/includes/`
- `theme/canvas-html5/assets/` → promote to a shared asset location

### Out of Scope
- Admin interface
- Non-HTML base views: `cart_charge.php`, `cart_clear.php`, `logout.php`, `robots.php`, `rss20_feed.php`, `sitemap.php` — pure PHP redirects or text/XML output, no changes needed
- Plugin views
- Email templates

## Current State

### Canvas-HTML5 Views (37 files — ready to promote)
All tested and verified:

**Top-level views (26):**
- `404.php`, `blog.php`, `booking.php`, `cart.php`, `cart_confirm.php`
- `change-password-required.php`, `event.php`, `events.php`, `event_waiting_list.php`
- `index.php`, `list.php`, `lists.php`, `location.php`, `login.php`
- `password-reset-1.php`, `password-reset-2.php`, `password-set.php`
- `post.php`, `pricing.php`, `product.php`, `products.php`
- `register.php`, `site-directory.php`, `survey.php`, `survey_finish.php`, `video.php`

**Profile subdirectory views (11):**
- `profile/profile.php`, `profile/account_edit.php`, `profile/password_edit.php`
- `profile/address_edit.php`, `profile/phone_numbers_edit.php`, `profile/contact_preferences.php`
- `profile/subscriptions.php`, `profile/event_withdraw.php`, `profile/change-tier.php`
- `profile/event_sessions.php`, `profile/event_sessions_course.php`
- *(Note: `profile/orders_recurring_action.php` is pure PHP logic — no override needed)*

### Base Views Requiring No Changes (leave in place)
- `page.php` — already uses canvas-compatible classes, no Bootstrap dependency
- `cart_charge.php`, `cart_clear.php`, `logout.php` — pure PHP redirects, no HTML
- `robots.php`, `rss20_feed.php`, `sitemap.php` — text/XML output

## Implementation Plan

### Phase A: Promote PublicPage.php

The canvas-html5 `PublicPage.php` (extends `PublicPageBase`) becomes the default concrete `PublicPage` in `/includes/`:

```
theme/canvas-html5/includes/PublicPage.php → /includes/PublicPage.php
```

**Note on the theme resolution chain:** `PathHelper::getThemeFilePath('PublicPage.php', 'includes')` checks `theme/{theme}/includes/` first, then falls back to `/includes/`. Themes with their own `PublicPage.php` override are unaffected. Themes without one will get the new HTML5 default.

### Phase B: Promote CSS/JS Assets

Move canvas-html5 assets to a shared location so the default base can reference them:

```
theme/canvas-html5/assets/css/style.css   → /assets/css/style.css
theme/canvas-html5/assets/css/custom.css  → /assets/css/custom.css
theme/canvas-html5/assets/js/script.js    → /assets/js/script.js
```

Update the new `/includes/PublicPage.php` to reference these paths instead of the old theme path.

### Phase C: Promote Views

For each of the 26 top-level canvas-html5 views:
```
cp theme/canvas-html5/views/{view}.php → /views/{view}.php
```

For the 11 profile subdirectory views:
```
cp theme/canvas-html5/views/profile/{view}.php → /views/profile/{view}.php
```

### Phase D: Remove Obsolete Theme Directories

Once all files are promoted:
1. Delete `theme/canvas-html5/` entirely (its files are now the base)
2. Delete `theme/canvas/` — the original Bootstrap/Canvas framework theme; no sites use it

### Phase E: Testing

1. Verify all major public pages render correctly
2. Verify login/register/password-reset flows
3. Verify cart/checkout end-to-end
4. Verify admin interface unchanged

## File Movement Summary

| File | Action |
|------|--------|
| `theme/canvas-html5/views/*.php` (26 files) | Copy → `/views/` (overwrite existing) |
| `theme/canvas-html5/views/profile/*.php` (11 files) | Copy → `/views/profile/` (overwrite existing) |
| `theme/canvas-html5/includes/PublicPage.php` | Copy → `/includes/PublicPage.php` |
| `theme/canvas-html5/assets/css/style.css`, `custom.css` | Copy → `/assets/css/` |
| `theme/canvas-html5/assets/js/script.js` | Copy → `/assets/js/` |
| `theme/canvas-html5/` | Delete entirely |
| `theme/canvas/` | Delete entirely |

## Risk Assessment

| Risk | Mitigation |
|------|------------|
| Asset paths break | Update `PublicPage.php` asset references before removing theme directory |
| `page.php` regression | Verify it still renders correctly after change |
| Views reference theme-specific paths directly | Grep for hardcoded `theme/canvas-html5` paths in views before promoting |

## Success Criteria

1. `/views/` contains vanilla HTML5 markup with no Bootstrap dependencies
2. All public pages render correctly with no theme set
3. Admin interface continues to work unchanged
4. No Bootstrap, jQuery, or Canvas framework JS/CSS loaded on the default public pages
