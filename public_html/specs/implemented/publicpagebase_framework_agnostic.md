# PublicPageBase Framework-Agnostic Refactoring

## Status: Implemented

This was Phase 1 of the Canvas HTML5 Theme spec (`specs/canvas_html5_theme.md`).

## Goal

Separate logic from rendering in `PublicPageBase` so that the base class produces clean, semantic HTML5 with no CSS framework dependencies. Bootstrap-specific markup moves into `PublicPageFalcon` as overrides.

## Design Pattern

**Template Method.** Each contaminated method is split into logic (stays in base) and rendering (extracted to a protected method). The base class provides plain HTML5 defaults. `PublicPageFalcon` overrides only the rendering methods with Bootstrap markup. `PublicPageTailwind` overrides with Tailwind markup where it previously had overrides.

## Changes Made

### PublicPageBase.php

Extracted rendering into overridable protected methods. Each public method retains its original signature and delegates to the new rendering method:

| Public method | Rendering method extracted | Default markup |
|---|---|---|
| `endtable()` | `renderPagination($data)` | `<nav class="pagination-wrapper">` with `<ul class="pagination">` |
| `alert()` | `renderAlert($title, $content, $type)` | `<div class="alert alert-{type}" role="alert">` |
| `begin_box()` | `renderBoxOpen($options)` | `<div class="content-box">` with optional header |
| `end_box()` | `renderBoxClose($options)` | `</div>` closers |
| `dropdown_or_buttons()` | `renderDropdown($label, $links)` | `<details class="dropdown">/<summary>` (native HTML5) |
| `dropdown_or_buttons()` | `renderButtonGroup($links)` | `<a class="btn btn-outline">` |
| `tableheader()` | `renderToolbar($sort, $filter, $search, $pager)` | `<div class="table-toolbar">` with forms |
| `tab_menu()` | `renderTabMenu($tabs, $current)` | `<nav class="tab-menu">` with `<a class="tab-link">` |

Additionally, `getFormWriter()` was fixed to use the theme override chain:

```php
// Before (hardcoded Bootstrap)
public function getFormWriter($form_id = 'form1', $options = []) {
    require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));
    return new FormWriterV2Bootstrap($form_id, $options);
}

// After (theme-aware)
public function getFormWriter($form_id = 'form1', $options = []) {
    require_once(PathHelper::getThemeFilePath('FormWriter.php', 'includes'));
    return new FormWriter($form_id, $options);
}
```

### PublicPageFalcon.php

Added Bootstrap rendering overrides for all 8 extracted methods. The HTML output is identical to the pre-refactoring output — this was a move, not a rewrite:

- `renderAlert()` — Bootstrap alert markup with SVG icons, `alert-dismissible`, etc.
- `renderTabMenu()` — Bootstrap `nav-tabs` markup
- `renderToolbar()` — Bootstrap grid layout for sort/filter/search controls
- `renderPagination()` — Bootstrap pagination with SVG arrow buttons
- `renderBoxOpen()` / `renderBoxClose()` — Bootstrap `card` / `card-header` / `card-body` markup
- `renderDropdown()` — Bootstrap `data-bs-toggle="dropdown"` markup
- `renderButtonGroup()` — Bootstrap `btn btn-falcon-default` buttons

### PublicPageTailwind.php

Renamed existing overrides to match the new method names:

- `alert()` renamed to `renderAlert()` (same Tailwind CSS markup preserved)
- `tab_menu()` renamed to `renderTabMenu()` (same Tailwind CSS markup preserved)

## Pagination Data Structure

`endtable()` now computes a data array and passes it to `renderPagination()`:

```php
$pagination_data = [
    'num_records'    => int,
    'current_page'   => int,
    'total_pages'    => int,
    'show_controls'  => bool,
    'in_card'        => bool,
    'prev_10_url'    => string|null,
    'next_10_url'    => string|null,
    'pages'          => [
        ['number' => N, 'url' => '...', 'is_current' => bool],
        ...
    ],
];
```

## Compatibility

- **All existing themes produce identical output** — verified by browser testing
- **Public API unchanged** — `alert()`, `tab_menu()`, `tableheader()`, `endtable()`, `begin_box()`, `end_box()`, `dropdown_or_buttons()` all retain their original signatures
- **New themes extending PublicPageBase get functional HTML5 defaults** without needing to override rendering methods

## Files Modified

- `includes/PublicPageBase.php`
- `includes/PublicPageFalcon.php`
- `includes/PublicPageTailwind.php`
