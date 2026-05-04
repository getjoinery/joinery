# Spec: Component Library — HTML5 Conversion

**Status:** Active  
**Created:** 2026-05-04

---

## Goal

All base components in `views/components/` must be framework-agnostic HTML5. Bootstrap and Tailwind
classes have no place in the base component library — those frameworks are not guaranteed to be
present, and the platform's default is zero-dependency HTML5. Remove Bootstrap-specific templates
and replace them with HTML5 equivalents in-place (same type keys, same file names).

---

## Scope

### Already HTML5 — no changes needed

These components are already framework-agnostic and should not be touched:

| File | Notes |
|------|-------|
| `accordion.php` | `<details>/<summary>`, scoped inline CSS |
| `divider.php` | Inline styles only |
| `image_gallery.php` | Inline styles, vanilla JS |
| `list_signup.php` | Inline styles, theme-adaptive FormWriter |
| `spacer.php` | Single inline style div |
| `tabs.php` | Scoped inline CSS, vanilla JS |
| `text_block.php` | Inline styles only |
| `text_with_image.php` | Scoped inline CSS |
| `video_embed.php` | Inline styles, iframe |

**Minor cleanup in `list_signup.php`:** The compact-mode scoped CSS block references `.form-group`
and `.mb-3` (Bootstrap FormWriter class names). These selectors are harmless but dead in HTML5
themes. Remove them and replace with the HTML5 FormWriter equivalents, or use generic attribute
selectors.

---

### `custom_html.php` — strip the wrapper

**Current behavior:** Wraps stored HTML in `<section class="custom-html py-4"><div class="jy-container">`.  
**Required behavior:** Output the stored HTML directly, nothing else.

The entire point of "custom HTML" is that the caller controls the markup. The wrapper is
counterproductive and uses Bootstrap utility classes. Callers store full section markup (including
their own `<section>` and container) in the config field.

**New template (complete replacement):**
```php
<?php
$html = $component_config['html'] ?? '';
if (empty($html)) { return; }
echo $html;
```

Update `custom_html.json`: remove `layout_defaults` block (container width and max height are
now irrelevant — the stored HTML controls its own layout).

**Migration risk:** Any existing `PageContent` instances using `custom_html` that relied on
`jy-container` for width-constraining will lose that wrapper. Before deploying, query:
```sql
SELECT pac_location_name, pac_title FROM pac_page_contents
WHERE pac_com_component_id = (SELECT com_component_id FROM com_components WHERE com_type_key = 'custom_html')
AND pac_delete_time IS NULL;
```
Audit each instance. If any stored HTML lacks its own container, add one before deploying the
template change.

---

### `cta_banner.php` — full HTML5 rewrite

**Bootstrap dependencies:** `py-5`, `jy-container`, Bootstrap grid (`row`, `justify-content-center`,
`col-lg-10`), `text-center`, `display-5`, `mb-3`, `lead`, `mb-4`, `btn btn-light btn-lg`, `px-4`,
`me-2`, `btn-outline-light btn-lg`.

**HTML5 replacement approach:**
- Use a `<section>` with inline background styles (same config fields, same logic)
- Use a centered `<div>` with `max-width` and `margin: 0 auto` for the content area
- Use semantic markup for heading and paragraph; no `display-5` or `lead` classes
- Style buttons with inline styles — neutral colors (white text on dark background by default,
  outline variant for secondary)
- Follow the same pattern as `text_with_image.php` for scoped layout

**Config schema:** unchanged — all existing fields (`heading`, `subheading`, `cta_text`, `cta_link`,
`background_type`, `background_color`, `gradient_start`, `gradient_end`, `background_image`,
`text_color`, `secondary_cta`) remain valid.

Update `cta_banner.json`: set `"css_framework": null`.

---

### `feature_grid.php` — full HTML5 rewrite

**Bootstrap dependencies:** `py-5`, `jy-container`, `row`, `mb-5`, `col-lg-8`, `mx-auto`,
`text-center`, `mb-3`, `lead`, `text-muted`, `row g-4`, Bootstrap column classes (`col-md-6`,
`col-lg-3`, etc.), `card`, `h-100`, `shadow-sm`, `card-body`, `stretched-link`.

**HTML5 replacement approach:**
- Replace Bootstrap grid with CSS Grid (`display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr))`)
  using the `columns` config value to set `grid-template-columns` explicitly when needed
- Drop the Bootstrap `card` style variant; replace with a plain bordered div using inline styles
- Icon rendering: drop `<i class="...">` Font Awesome approach (requires Font Awesome CDN) —
  replace with a simple placeholder `<div>` or SVG that the caller can override. Or keep the `<i>`
  tag but remove the Bootstrap sizing/color classes; raw icon font classes still work in any theme
  that loads an icon font
- Use a scoped `<style>` block (as per `tabs.php` and `text_with_image.php` pattern) or inline
  styles for the container and grid

**Config schema:** drop the `style` field's `card` option (Bootstrap-only); keep `centered` and
`plain`. Remove `icon_style` options that depend on Bootstrap (`circle`, `square` still work with
inline styles). Keep all other fields.

Update `feature_grid.json`: set `"css_framework": null`; update `style` field options to remove
`card` option.

---

### `hero_static.php` — full HTML5 rewrite

**Bootstrap dependencies:** `jy-container`, `row`, `justify-content-center`, `col-lg-8`,
`display-4`, `mb-3`, `lead`, `mb-4`, `btn btn-primary btn-lg`, `py-5`, `min-vh-100`, `d-flex`,
`align-items-center`, `text-center`.

**HTML5 replacement approach:**
- `<section>` with inline background style (same config logic for image/color/text-color)
- Centered content block: `max-width: 720px; margin: 0 auto; padding: 4rem 1.5rem; text-align: center`
- `height` config: map `small`/`medium`/`large` to padding values; map `fullscreen` to
  `min-height: 100vh; display: flex; align-items: center` using inline styles
- CTA button: styled with inline styles; `cta_style` config maps `primary`/`secondary` to
  appropriate color/border combinations (neutral dark primary, outlined secondary)
- `alignment` config: map `left`/`center`/`right` to `text-align` inline style

**Config schema:** unchanged.

Update `hero_static.json`: set `"css_framework": null`.

---

### `page_title.php` — full HTML5 rewrite

**Bootstrap dependencies:** `py-4`, `jy-container`, `mb-2`, breadcrumb CSS classes
(`breadcrumb`, `breadcrumb-item`, `active`), `text-center`, `text-end`, `mb-1`, `lead`, `mb-0`,
`text-muted`.

**HTML5 replacement approach:**
- `<section>` with inline background/text-color styles
- Container: `max-width: 1100px; margin: 0 auto; padding: 1.5rem 1rem`
- Breadcrumbs: use a plain `<nav>` with `<ol>` and inline flex styles; no Bootstrap breadcrumb
  classes needed
- Alignment: map `left`/`center`/`right` to inline `text-align` on the content wrapper
- Drop `text-muted` and `lead` classes; use inline `color` and `font-size` styles for subtitle

**Config schema:** unchanged.

Update `page_title.json`: set `"css_framework": null`.

---

## com_components Table

After replacing the templates, update the `com_css_framework` column for the four affected
component types to `NULL`:

```sql
UPDATE com_components
SET com_css_framework = NULL
WHERE com_type_key IN ('cta_banner', 'feature_grid', 'hero_static', 'page_title');
```

Add to a migration so deployed sites are updated automatically.

---

## Testing

For each rewritten component:
1. Create a test `PageContent` instance via admin and verify it renders correctly in the default theme
2. Verify all config fields still take effect (background colors, alignment, CTA styles, etc.)
3. Verify `custom_html` instances display their stored HTML without any wrapper in both HTML5 and Bootstrap themes

---

## What This Enables

Once complete, `custom_html` instances store self-contained HTML with no framework assumptions.
This is the prerequisite for the getjoinery content-to-DB work
(see `specs/getjoinery_content_to_db.md`).
