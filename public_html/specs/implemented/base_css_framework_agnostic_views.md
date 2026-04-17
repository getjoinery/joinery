# Base CSS: Framework-Agnostic View Layer

## Problem

Base view files (`/views/*.php`) used Bootstrap 5 utility classes (`row`, `col-lg-4`, `card`, `btn-primary`, `d-flex`, `mb-3`, etc.) and Canvas theme-specific classes (`content-wrap`, `grid-filter`, `portfolio-*`, `entry-*`, `widget`, etc.) throughout their HTML markup. This meant:

- Themes that included Bootstrap (like `phillyzouk`) rendered base views correctly
- Themes without Bootstrap (like `jeremytunnell` with Typology CSS) rendered base views as broken, unstyled HTML
- Theme developers were forced to override every single view file to avoid broken fallback rendering, defeating the purpose of having shared base views

### Root Cause Analysis

The base views contained a mix of:
1. **Bootstrap 5 utility classes** (~60+ unique classes): `row`, `col-md-4`, `d-flex`, `mb-3`, `card`, `btn-primary`, `text-muted`, `shadow-sm`, `rounded-4`, `justify-content-center`, etc.
2. **Canvas-specific component classes** (~30+ unique classes): `content-wrap`, `page-title`, `page-title-row`, `grid-filter`, `portfolio`, `portfolio-item`, `entry`, `entry-meta`, `widget`, `tagcloud`, `vertical-middle`, `gutter-30`, etc.
3. **Bootstrap JS data attributes**: `data-bs-toggle="pill"`, `data-bs-toggle="dropdown"`, `data-bs-toggle="tooltip"` for interactive components
4. **Bootstrap CSS variable references**: `var(--bs-primary)`, `var(--bs-dark)` in inline `<style>` blocks

20 of 33 base view files contained CSS class dependencies.

## Solution

Instead of rewriting all 20 view files, created a lightweight CSS+JS layer that implements the same class names, loaded globally so every theme gets baseline styling for fallback views.

### Files Created

**`/assets/css/base.css`** (~460 lines)
Framework-agnostic CSS implementing all Bootstrap and Canvas classes used by base views:
- CSS custom properties (`--base-primary`, `--base-dark`, etc.) that themes can override
- Grid system (`.row`, `.col-*` at all breakpoints) using flexbox
- Gutter variants (`.g-0` through `.g-4`, `.gx-*`, `.gy-*`, `.gutter-30`, `.gutter-40`)
- Flexbox utilities (`.d-flex`, `.align-items-center`, `.justify-content-*`, `.flex-wrap`, etc.)
- Spacing utilities (`.m-*`, `.p-*`, `.mt-*`, `.mb-*`, `.ms-*`, `.me-*`, `.mx-auto`, `.px-*`, `.py-*`)
- Typography (`.text-muted`, `.text-primary`, `.fw-bold`, `.lead`, `.h1`-`.h6`, `.display-*`, `.fs-*`)
- Background/border/shadow utilities
- Cards (`.card`, `.card-body`, `.card-header`, `.card-footer`)
- Buttons (`.btn`, `.btn-primary`, `.btn-outline-*`, `.btn-sm`, `.btn-lg`, plus Canvas `.button` compat)
- Forms (`.form-control`, `.form-select`, `.form-label`, `.form-group`, `.input-group`)
- Alerts, badges, tables, breadcrumbs, pagination, nav/tabs, lists
- Responsive display (`.d-none`, `.d-sm-block`, `.d-block.d-sm-none`)
- Position, width/height, overflow, opacity utilities
- Canvas component compat: `.content-wrap`, `.page-title`, `.entry-*`, `.grid-filter`, `.portfolio-*`, `.widget`, `.tagcloud`, `.vertical-middle`, `.accordion-*`, `.bg-overlay`, `.error404-*`
- Dropdown menu styles
- Icon font fallback (prevents layout breakage when icon fonts aren't loaded)

**`/assets/js/base.js`** (~70 lines)
Vanilla JS replacements for Bootstrap JS interactive features:
- Tab/pill toggle (`data-bs-toggle="pill"` and `data-bs-toggle="tab"`)
- Dropdown toggle (`data-bs-toggle="dropdown"`)
- Tooltip graceful degradation (`data-bs-toggle="tooltip"`)

### Files Modified

**`/includes/PublicPageBase.php`**
Added to `global_includes_top()`:
```php
echo '<link rel="stylesheet" href="/assets/css/base.css">' . "\n";
echo '<script defer src="/assets/js/base.js"></script>' . "\n";
```
Loads before `custom_css`, so theme CSS naturally overrides base styles.

**`/views/404.php`**
Changed `var(--bs-primary)` and `var(--bs-dark)` references to fallback chains:
```css
/* Before */
color: var(--bs-primary);
/* After */
color: var(--bs-primary, var(--base-primary, #1abc9c));
```

## How It Works

1. `base.css` and `base.js` are loaded via `global_includes_top()` in `PublicPageBase`, which every theme's `PublicPage::public_header()` calls
2. For themes WITH Bootstrap: Bootstrap CSS loads after `base.css` and overrides it (CSS cascade). Bootstrap JS handles `data-bs-toggle` before `base.js` (which harmlessly re-attaches listeners)
3. For themes WITHOUT Bootstrap: `base.css` provides all the utility classes the base views need. `base.js` handles tab/dropdown interactivity

## Testing

Verified with two themes:
- **phillyzouk** (includes Bootstrap): No visual changes, all pages render identically to before
- **jeremytunnell** (no Bootstrap, uses Typology CSS): Base views now render correctly
  - Pricing page: 3-column card grid, buttons, badges, comparison table all styled
  - Login page: Centered card, form inputs, vertically centered layout all working
  - Theme's own typography (Josefin Sans, uppercase headings) naturally layers on top

## Design Decisions

1. **Compatibility layer, not a rewrite**: Rather than rewriting 20 view files to use new semantic classes, we implemented the same Bootstrap/Canvas class names in vanilla CSS. This is zero-risk for existing themes and requires no view changes.

2. **CSS custom properties with fallbacks**: `base.css` uses `--base-primary` etc., while views that reference `--bs-primary` use fallback chains. Themes can override either set.

3. **`defer` on base.js**: Ensures it doesn't block page rendering and runs after DOM is ready.

4. **Minimal JS**: Only implements the three `data-bs-toggle` patterns actually used in base views (pill, dropdown, tooltip). No attempt to replicate full Bootstrap JS.

## Limitations

- Icon fonts (Bootstrap Icons `bi-*`, Unicons `uil-*`, FontAwesome `fa-*`) won't render actual icons without the font files. `base.css` prevents layout breakage but shows empty space where icons would be. Themes should include their preferred icon font.
- The login page references a Canvas-specific background image (`/theme/canvas/assets/images/hero/hero-login.jpg`) that won't exist in other themes. This is a content issue in the view, not a CSS issue.
- Complex Bootstrap components not used in base views (carousel, modal, collapse, offcanvas) are not implemented.
