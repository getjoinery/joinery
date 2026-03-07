# Canvas HTML5 Joinery Theme Spec

## Goal

Create a new Joinery theme (`theme/canvas-html5/`) that uses the vanilla HTML5+CSS component library from the canvas-html5 conversion project instead of Bootstrap/Canvas framework dependencies. This theme delivers the Canvas visual design with ~93% less CSS and ~99.9% less JS than the current Bootstrap-based Canvas theme.

As a prerequisite, refactor `PublicPageBase` to be framework-agnostic so that non-Bootstrap themes inherit clean HTML5 defaults without needing to override rendering methods.

## Background

Two pieces of prior work make this feasible:

1. **canvas-html5 static conversion** (`/home/user1/theme-sources/canvas-html5/`) -- 76 static HTML pages with a shared `style.css` (53KB) and `script.js` (7KB) that reproduce the Canvas 7 visual design using only vanilla HTML5+CSS. See `specs/canvas_html5_conversion.md` for details.

2. **FormWriterV2HTML5** (`includes/FormWriterV2HTML5.php`) -- A complete HTML5 form generation class with semantic markup and no CSS framework dependencies. Already built and tested.

## Architecture

### Theme Inheritance

```
PublicPageBase (abstract — framework-agnostic, plain HTML5 defaults)
  -> PublicPageFalcon (overrides rendering methods with Bootstrap markup)
  -> theme/canvas-html5/includes/PublicPage.php (NEW — extends PublicPageBase directly)
```

The new theme does **not** extend Falcon. Its `PublicPage.php` extends `PublicPageBase` directly, keeping the vanilla HTML5 approach completely independent of Bootstrap. There is no intermediate `PublicPageCanvasHTML5` class — since only one theme uses this base, everything lives in the theme's own `PublicPage.php`.

### Theme Configuration

```
theme/canvas-html5/theme.json
{
    "name": "canvas-html5",
    "display_name": "Canvas HTML5",
    "version": "1.0.0",
    "description": "Lightweight Canvas theme using vanilla HTML5+CSS with zero framework dependencies",
    "author": "Joinery Team",
    "is_stock": true,
    "cssFramework": "html5",
    "formWriterBase": "FormWriterV2HTML5"
}
```

Note: No `parent_theme` -- this is a standalone theme, not a child of Falcon.

### Directory Structure

```
theme/canvas-html5/
    theme.json
    includes/
        PublicPage.php          # Extends PublicPageCanvasHTML5
        FormWriter.php          # Extends FormWriterV2HTML5
    assets/
        css/
            style.css           # Copied from canvas-html5 conversion (primary stylesheet)
            custom.css          # Theme-specific overrides for Joinery integration
        js/
            script.js           # Copied from canvas-html5 conversion
        images/
            logo.png            # Theme logo
            logo-dark.png       # Dark variant
    views/
        login.php
        register.php
        cart.php
        cart_charge.php
        cart_clear.php
        product.php
        post.php
        list.php
        lists.php
        event.php               # If needed (check base view compatibility)
        events.php              # If needed
        site-directory.php
        password-reset-1.php
        password-reset-2.php
        password-set.php
        survey.php
        survey_finish.php
        event_waiting_list.php
        index.php               # Homepage
        404.php
```

## Implementation Plan

### Phase 1: Make PublicPageBase Framework-Agnostic -- COMPLETE

**See:** `specs/implemented/publicpagebase_framework_agnostic.md`

Extracted 8 rendering methods from PublicPageBase into overridable protected methods (`renderAlert`, `renderPagination`, `renderToolbar`, `renderBoxOpen`, `renderBoxClose`, `renderDropdown`, `renderButtonGroup`, `renderTabMenu`). Base class now produces clean HTML5 defaults. PublicPageFalcon overrides with Bootstrap markup. PublicPageTailwind overrides with Tailwind markup. `getFormWriter()` fixed to use theme override chain. All existing themes produce identical output.

### Phase 2: Build Theme

Create `theme/canvas-html5/` with all required files. Reference existing themes (e.g., `theme/canvas/`) for structure and `PublicPageFalcon` for logic patterns.

#### Scaffolding

- `theme.json` with configuration above
- Copy `style.css` and `script.js` from `/home/user1/theme-sources/canvas-html5/`
- Create `custom.css` for Joinery-specific additions (forms, alerts, tables, pagination, utilities)

#### PublicPage.php (extends PublicPageBase)

```php
require_once(PathHelper::getIncludePath('includes/PublicPageBase.php'));

class PublicPage extends PublicPageBase {
    // Header, footer, menu, logo, table classes, etc.
}
```

**Must implement:** `public_header()`, `public_footer()`, `top_right_menu()`, `get_logo()`, `getTableClasses()`

**May override** if base HTML5 defaults don't match Canvas design: `BeginPage()`/`EndPage()`, `renderAlert()`, `renderPagination()`

**Reference:** canvas-html5 `about.html` for header/footer markup, `PublicPageFalcon` for logic patterns.

**Key differences from PublicPageFalcon:**
- `.grid-2` through `.grid-6` instead of `row`/`col-*`
- Semantic HTML5 instead of Bootstrap components
- Vanilla JS instead of jQuery/Bootstrap JS
- Inline SVG or Unicode instead of Font Awesome
- `<nav class="nav-links">` instead of Bootstrap navbar

#### FormWriter.php (extends FormWriterV2HTML5)

```php
require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));

class FormWriter extends FormWriterV2HTML5 {
    // Theme-specific form styling overrides if needed
}
```

#### View Overrides

Convert views from Bootstrap markup to vanilla HTML5. Keep all PHP logic and FormWriter calls unchanged; replace HTML wrapper markup with canvas-html5 equivalents.

**Common class substitutions:**

| Bootstrap | Canvas HTML5 |
|-----------|-------------|
| `row` | `grid-2`, `grid-3`, etc. |
| `col-md-6` | Grid children (auto-sized by parent) |
| `card` / `card-body` | `<div class="content-section">` |
| `btn btn-primary` | `<button class="btn btn-primary">` |
| `alert alert-danger` | `<div class="alert alert-error">` |
| `d-flex`, `justify-content-between` | Inline styles or utility classes in `custom.css` |
| `mb-3`, `mt-4`, etc. | Inline margins or section spacing |
| `table table-striped` | `<table class="styled-table">` |

#### Testing

Switch test site theme to `canvas-html5` and verify all public pages, responsive behavior, form submissions, cart/checkout flow, and login/register flows.

## Scope Boundaries

### In Scope
- Refactoring `PublicPageBase` to be framework-agnostic (Phase 1)
- Public-facing pages only
- All view files in `/views/` that contain Bootstrap-specific markup and need theme overrides

### Out of Scope
- **Admin interface** -- Stays on Falcon/Bootstrap. Admin pages (`/adm/`) are unaffected.
- **Admin page FormWriter** -- Admin forms continue using Bootstrap FormWriter
- **Plugin views** -- Plugin-specific views (e.g. controld) are not part of this theme. Plugins maintain their own views independently.
- **Additional canvas-html5 static conversions** -- The existing files are sufficient reference
- **Email templates** -- Email HTML is separate from theme rendering
- **API endpoints** -- No UI involvement

## CSS/JS Assets Summary

| Asset | Source | Size |
|-------|--------|------|
| `style.css` | canvas-html5 conversion | ~53KB |
| `script.js` | canvas-html5 conversion | ~7KB |
| `custom.css` | New (Joinery integration) | ~5-10KB est. |
| Google Fonts (Inter, Playfair Display) | CDN | External |
| **Total** | | **~65KB** |

Compare to current Canvas theme: ~780KB CSS + ~8MB JS.

## Risk Assessment

| Risk | Mitigation |
|------|------------|
| Phase 1 refactoring breaks existing themes | Move-only for Falcon overrides — HTML output is identical. Test all themes after refactoring. |
| Base HTML5 defaults don't style well without CSS | `base.css` (already loaded by `global_includes_top()`) provides baseline styles; theme `custom.css` covers the rest |
| FormWriterV2HTML5 output gaps | Test all field types early; the class is already built and tested |
| Missing utility classes | Add minimal set to `custom.css` rather than pulling in a framework |
| Plugin views use Bootstrap | Override in theme or add compatibility styles in `custom.css` |

### Phase 3: Promote Theme to Base Views

Once the canvas-html5 theme is complete and verified, promote its views to become the system default:

1. **Move view files** from `theme/canvas-html5/views/` to `/views/` (replacing Bootstrap-markup base views)
2. **Move includes** — `PublicPage.php` becomes the new `PublicPageBase` (or a new concrete base class in `/includes/`)
3. **Falcon becomes an override theme** — move current Falcon public views (Bootstrap markup) into `theme/falcon/views/` so Falcon overrides the new HTML5 base
4. **Remove `theme/canvas-html5/`** — no longer needed once its files are the base

After this, the architecture is:
- `/views/` = vanilla HTML5 (canvas-html5 design)
- `theme/falcon/views/` = Bootstrap overrides
- New themes extend the HTML5 base by default

## Success Criteria

1. `PublicPageBase` contains zero Bootstrap-specific CSS classes or `data-bs-*` attributes
2. All existing themes produce identical output after Phase 1 refactoring
3. All public pages render correctly with canvas-html5 theme active
4. Zero Bootstrap, jQuery, or Canvas framework dependencies loaded on public pages
5. Total CSS+JS payload under 100KB (excluding fonts)
6. Responsive at mobile/tablet/desktop breakpoints
7. All forms submit and validate correctly
8. Cart/checkout flow works end-to-end
9. Login/register/password-reset flows work
10. Dynamic menus render from database
11. Admin interface continues to work unchanged on Falcon
