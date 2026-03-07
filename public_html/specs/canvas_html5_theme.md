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
  -> PublicPageCanvasHTML5 (NEW — may need minimal overrides, or none if base defaults suffice)
```

The new theme does **not** extend Falcon. It extends `PublicPageBase` directly via a new `PublicPageCanvasHTML5` class in `/includes/`, keeping the vanilla HTML5 approach completely independent of Bootstrap.

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
    "formWriterBase": "FormWriterV2HTML5",
    "publicPageBase": "PublicPageCanvasHTML5"
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

### Phase 1: Make PublicPageBase Framework-Agnostic

**Goal:** Separate logic from rendering in `PublicPageBase` so that the base class produces clean, semantic HTML5 with no CSS framework dependencies. Bootstrap-specific markup moves into `PublicPageFalcon` as overrides.

**Design pattern:** Template Method. Each contaminated method is split into logic (stays in base) and rendering (extracted to a protected method). The base class provides plain HTML5 defaults. `PublicPageFalcon` overrides only the rendering methods with Bootstrap markup.

#### 1a. Extract rendering methods

Each method that currently contains Bootstrap markup gets its rendering extracted into a small protected method. The base class method keeps the logic and calls the rendering method.

**`endtable()` -> `renderPagination()`**

Current `endtable()` mixes pagination math with deeply Bootstrap-specific markup (inline SVGs inside `btn btn-sm btn-falcon-default` buttons, `data-list-pagination` attributes). Split into:

- `endtable()` stays in base — closes table, computes pagination data structure, calls `renderPagination()`
- `renderPagination($data)` — new protected method, receives a plain data array:

```php
// Base class computes this data structure
$pagination_data = [
    'num_records'  => $pager->num_records(),
    'current_page' => $pager->current_page(),
    'total_pages'  => $pager->total_pages(),
    'show_controls' => ($pager->num_records() > $pager->num_per_page()),
    'prev_10_url'  => $pager->is_valid_page('-10') ? $pager->get_url(...) : null,
    'next_10_url'  => $pager->is_valid_page('+10') ? $pager->get_url(...) : null,
    'pages'        => [
        // array of ['number' => N, 'url' => '...', 'is_current' => bool]
    ],
    'in_card'      => $use_card,
];

$this->renderPagination($pagination_data);
```

Base class default `renderPagination()` outputs semantic HTML5:
```html
<nav class="pagination-wrapper" aria-label="Pagination">
    <span class="pagination-info">42 records, Page 2 of 5</span>
    <ul class="pagination">
        <li><a href="...">&#8249; Prev</a></li>
        <li><a href="...">1</a></li>
        <li class="active" aria-current="page">2</li>
        <li><a href="...">3</a></li>
        <li><a href="...">Next &#8250;</a></li>
    </ul>
</nav>
```

`PublicPageFalcon` overrides with Bootstrap pagination markup.

**`alert()` -> `renderAlert()`**

Current `alert()` uses `alert-danger`, `alert-warning`, `alert-success` Bootstrap classes. Extract:

- `alert($title, $content, $type)` stays as the public API — calls `renderAlert()`
- `renderAlert($title, $content, $type)` — protected method

Base class default:
```html
<div class="alert alert-error" role="alert">
    <h4>Title</h4>
    <p>Content</p>
</div>
```

`PublicPageFalcon` overrides with Bootstrap alert markup (SVG icons, `alert-dismissible`, `btn-close`, etc.).

**`begin_box()` / `end_box()` -> `renderBoxOpen()` / `renderBoxClose()`**

Current uses `card`, `card-header bg-body-tertiary`, `card-body p-0`. Extract:

- `begin_box()` / `end_box()` stay — parse options, call rendering methods
- `renderBoxOpen($options)` / `renderBoxClose($options)` — protected methods

Base class default:
```html
<!-- With title -->
<div class="content-box">
    <div class="content-box-header"><h6>Title</h6></div>
    <div class="content-box-body">

<!-- Without title -->
<div>
```

**`dropdown_or_buttons()` -> `renderDropdown()` + `renderButtonGroup()`**

Current uses `data-bs-toggle="dropdown"`, Bootstrap dropdown structure. Extract:

- `dropdown_or_buttons()` stays — counts links, decides dropdown vs buttons
- `renderDropdown($label, $links)` — for >2 links, renders collapsible menu
- `renderButtonGroup($links)` — for 1-2 links, renders inline buttons

Base class default uses `<details>/<summary>` for dropdown (native HTML5, no JS needed):
```html
<!-- Dropdown (>2 links) -->
<div class="action-buttons">
    <details class="dropdown">
        <summary>Options</summary>
        <ul class="dropdown-menu">
            <li><a href="...">Label</a></li>
        </ul>
    </details>
</div>

<!-- Buttons (1-2 links) -->
<div class="action-buttons">
    <a href="..." class="btn btn-outline">Label</a>
</div>
```

**`tableheader()` -> `renderToolbar()`**

Current uses `row justify-content-end gx-3 gy-0 px-3`, `col-sm-auto`. Extract:

- `tableheader()` stays — parses sort/filter/search options, calls `renderToolbar()`
- `renderToolbar($sort_data, $filter_data, $search_data, $pager)` — protected method

Base class default:
```html
<div class="table-toolbar">
    <form method="get" action="...">
        <label for="sort">Sort:</label>
        <select name="sort">...</select>
        <button type="submit">Sort</button>
    </form>
</div>
```

**`tab_menu()` -> `renderTabMenu()`**

Current base class uses `filter-menu` classes. Extract:

- `tab_menu()` stays as public API — calls `renderTabMenu()`
- `renderTabMenu($tabs, $current)` — protected method

Base class default:
```html
<nav class="tab-menu" aria-label="Tabs">
    <a href="..." class="tab-link active" aria-current="page">Tab 1</a>
    <a href="..." class="tab-link">Tab 2</a>
</nav>
```

#### 1b. Fix `getFormWriter()` to use theme system

Current `getFormWriter()` in PublicPageBase hardcodes `FormWriterV2Bootstrap`:

```php
// CURRENT — broken for non-Bootstrap themes
public function getFormWriter($form_id = 'form1', $options = []) {
    require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));
    return new FormWriterV2Bootstrap($form_id, $options);
}
```

Fix to load via theme override chain:

```php
// FIXED — uses theme's FormWriter
public function getFormWriter($form_id = 'form1', $options = []) {
    require_once(PathHelper::getThemeFilePath('FormWriter.php', 'includes'));
    return new FormWriter($form_id, $options);
}
```

This uses the existing pattern — every theme already has `includes/FormWriter.php` that extends the appropriate base class. The base class just wasn't using it.

#### 1c. Move Bootstrap overrides to PublicPageFalcon

After extracting rendering methods, `PublicPageFalcon` overrides them with its current Bootstrap markup. This is a move, not a rewrite — the HTML output is identical:

```php
class PublicPageFalcon extends PublicPageBase {

    protected function renderPagination($data) {
        // Current endtable() Bootstrap markup moves here
    }

    protected function renderAlert($title, $content, $type) {
        // Current Falcon alert() markup moves here (SVG icons, dismissible, etc.)
    }

    protected function renderBoxOpen($options) {
        // Current begin_box() Bootstrap card markup moves here
    }

    protected function renderBoxClose($options) {
        // Current end_box() Bootstrap card close moves here
    }

    protected function renderDropdown($label, $links) {
        // Current dropdown Bootstrap markup moves here
    }

    protected function renderButtonGroup($links) {
        // Current button Bootstrap markup moves here
    }

    protected function renderToolbar($sort_data, $filter_data, $search_data, $pager) {
        // Current tableheader() Bootstrap grid markup moves here
    }

    protected function renderTabMenu($tabs, $current) {
        // Current Falcon tab_menu() Bootstrap nav-tabs markup moves here
    }
}
```

#### 1d. Verification

After this refactoring:
- **All existing themes produce identical output** — Falcon overrides reproduce the same HTML as before
- **PublicPageBase defaults are clean HTML5** — no Bootstrap classes, no `data-bs-*` attributes, no framework JS dependencies
- **New themes get working output for free** — any theme extending PublicPageBase gets functional alerts, tables, pagination, boxes, and dropdowns without overriding anything

**Testing:** Switch between themes and verify all public pages with tables/pagination (site directory, event lists) and alerts still render correctly.

#### Summary of methods extracted

| Base class method | Rendering method extracted | Default markup |
|---|---|---|
| `endtable()` | `renderPagination($data)` | `<nav><ul class="pagination">` |
| `alert()` | `renderAlert($title, $content, $type)` | `<div class="alert alert-{type}">` |
| `begin_box()` | `renderBoxOpen($options)` | `<div class="content-box">` |
| `end_box()` | `renderBoxClose($options)` | `</div>` |
| `dropdown_or_buttons()` | `renderDropdown($label, $links)` | `<details>/<summary>` |
| `dropdown_or_buttons()` | `renderButtonGroup($links)` | `<a class="btn btn-outline">` |
| `tableheader()` | `renderToolbar($sort, $filter, $search, $pager)` | `<div class="table-toolbar">` |
| `tab_menu()` | `renderTabMenu($tabs, $current)` | `<nav class="tab-menu">` |
| `getFormWriter()` | (fix, not extract) | Load via `PathHelper::getThemeFilePath` |

### Phase 2: Theme Scaffolding

1. Create `theme/canvas-html5/` directory structure
2. Create `theme.json` with configuration above
3. Copy `style.css` and `script.js` from `/home/user1/theme-sources/canvas-html5/` into `assets/css/` and `assets/js/`
4. Create `assets/css/custom.css` for Joinery-specific style additions (form styling to match FormWriterV2HTML5 output, alert styles, etc.)

### Phase 3: PublicPageCanvasHTML5

Create `/includes/PublicPageCanvasHTML5.php` extending `PublicPageBase`. Because Phase 1 made the base framework-agnostic, this class may need very few overrides — primarily the methods that remain abstract or theme-specific.

**Must implement:**

| Method | Purpose | Reference |
|--------|---------|-----------|
| `public_header()` | HTML head + header nav using canvas-html5 markup | `canvas-html5/about.html` header |
| `public_footer()` | Footer + closing tags using canvas-html5 markup | `canvas-html5/about.html` footer |
| `top_right_menu()` | User/cart/admin menu items in vanilla HTML5 | `PublicPageFalcon::top_right_menu()` for logic |
| `get_logo()` | Site logo output | `PublicPageFalcon::get_logo()` for logic |
| `getTableClasses()` | CSS classes for table rendering | Return vanilla class names |

**May override if base defaults don't match Canvas design:**

| Method | Override if... |
|--------|----------------|
| `BeginPage()` / `EndPage()` | Canvas needs different content wrapper |
| `BeginPageNoCard()` / `EndPageNoCard()` | Canvas uses `.page-hero` pattern |
| `renderAlert()` | Canvas notification style differs from base default |
| `renderPagination()` | Canvas pagination style differs from base default |

**Header markup pattern** (from canvas-html5 `about.html`):
```html
<header class="site-header">
    <div class="header-inner">
        <a href="/" class="logo">...</a>
        <button class="menu-toggle" aria-label="Toggle menu">...</button>
        <nav class="nav-links">
            <!-- Dynamic menu from get_public_menu() -->
        </nav>
        <div class="header-actions">
            <!-- Cart, user menu, admin link -->
        </div>
    </div>
</header>
```

**Footer markup pattern** (from canvas-html5 `about.html`):
```html
<footer class="site-footer">
    <div class="container">
        <div class="footer-widgets grid-3">...</div>
    </div>
    <div class="footer-bottom">
        <div class="container">...</div>
    </div>
</footer>
```

**Key differences from PublicPageFalcon:**
- No Bootstrap grid (`row`, `col-*`) -- use `.grid-2` through `.grid-6` from canvas-html5
- No Bootstrap components (`card`, `dropdown`, `navbar`) -- use semantic HTML5
- No jQuery or Bootstrap JS -- vanilla JS only
- No Font Awesome dependency -- use inline SVG icons or simple Unicode
- Dynamic menu uses `<nav class="nav-links"><ul>` instead of Bootstrap navbar
- User dropdown uses vanilla JS toggle instead of `data-bs-toggle="dropdown"`

### Phase 4: Theme PublicPage and FormWriter

**`theme/canvas-html5/includes/PublicPage.php`:**
```php
require_once(PathHelper::getIncludePath('includes/PublicPageCanvasHTML5.php'));

class PublicPage extends PublicPageCanvasHTML5 {
    // Theme-specific overrides if needed
}
```

**`theme/canvas-html5/includes/FormWriter.php`:**
```php
require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));

class FormWriter extends FormWriterV2HTML5 {
    // Theme-specific form styling overrides if needed
}
```

### Phase 5: View Overrides

Convert views from Bootstrap markup to vanilla HTML5. Each view currently uses Bootstrap classes that need to be replaced with canvas-html5 equivalents.

**Common class substitutions:**

| Bootstrap | Canvas HTML5 |
|-----------|-------------|
| `container` | `container` (same) |
| `row` | `grid-2`, `grid-3`, etc. |
| `col-md-6` | Grid children (auto-sized by parent grid) |
| `col-12` | Full-width block (default) |
| `card` / `card-body` | `<div class="content-section">` or semantic sections |
| `btn btn-primary` | `<button class="btn btn-primary">` (defined in canvas-html5 style.css) |
| `alert alert-danger` | `<div class="alert alert-error">` |
| `form-control` | `form-control` (FormWriterV2HTML5 uses this) |
| `form-group` | `form-group` (FormWriterV2HTML5 uses this) |
| `d-flex` | `display: flex` inline or utility class in custom.css |
| `justify-content-between` | Inline style or utility class |
| `mb-3`, `mt-4`, etc. | Inline margins or section spacing from style.css |
| `text-center` | `text-align: center` inline or utility class |
| `table table-striped` | `<table class="styled-table">` |

**Priority order for view conversion:**
1. `login.php`, `register.php` -- First user touchpoints
2. `index.php`, `404.php` -- Landing pages
3. `cart.php`, `cart_charge.php`, `cart_clear.php`, `product.php` -- Commerce flow
4. `password-reset-1.php`, `password-reset-2.php`, `password-set.php` -- Auth flow
5. `post.php`, `list.php`, `lists.php` -- Content pages
6. `site-directory.php`, `event_waiting_list.php`, `survey.php`, `survey_finish.php` -- Remaining pages

**Conversion approach per view:**
1. Read the current canvas theme view (e.g., `theme/canvas/views/login.php`)
2. Keep all PHP logic, FormWriter calls, and data access unchanged
3. Replace Bootstrap HTML wrapper markup with canvas-html5 equivalents
4. Reference the corresponding canvas-html5 static file for layout patterns (e.g., `canvas-html5/login-register.html`)
5. Test in browser

### Phase 6: Integration CSS (`custom.css`)

The canvas-html5 `style.css` covers visual components but not Joinery-specific needs. `custom.css` bridges the gap:

- **Form field styling** -- Ensure `FormWriterV2HTML5` output classes (`.form-group`, `.form-control`, `.form-label`, `.is-invalid`, `.error-message`) are styled consistently with the canvas-html5 design
- **Alert variants** -- Map Joinery alert types (`error`, `warn`, `success`, `info`) to canvas-html5 notification styles
- **Utility classes** -- Minimal set for common layout needs (`.text-center`, `.mt-1` through `.mt-4`, etc.) to avoid excessive inline styles in views
- **Table styling** -- Classes for data tables used by `getTableClasses()`
- **Pager/pagination** -- Style the pagination output from `renderPagination()` base defaults
- **Content box** -- Style `.content-box` / `.content-box-header` from `renderBoxOpen()` base defaults
- **Action buttons** -- Style `.action-buttons`, `.btn-outline`, `details.dropdown` from base defaults

### Phase 7: Testing and Polish

1. Switch test site theme to `canvas-html5`
2. Browser-test each page type (login, register, events, cart, product, etc.)
3. Verify responsive behavior at mobile (375px), tablet (768px), desktop (1200px+)
4. Test dynamic interactions: mobile menu, dropdowns, form validation, cart updates
5. Verify FormWriter output renders correctly for all field types
6. Check error log for any missing includes or PHP errors

## Scope Boundaries

### In Scope
- Refactoring `PublicPageBase` to be framework-agnostic (Phase 1)
- Public-facing pages only
- All view files that the current Canvas theme overrides
- Base views that need overrides to remove Bootstrap dependencies
- Plugin public views (controld plugin has 9 views)

### Out of Scope
- **Admin interface** -- Stays on Falcon/Bootstrap. Admin pages (`/adm/`) are unaffected.
- **Admin page FormWriter** -- Admin forms continue using Bootstrap FormWriter
- **Additional canvas-html5 static conversions** -- The 76 existing files are sufficient reference
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
