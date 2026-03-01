# Component Type Library

This document defines the planned component types for the page builder system. Each component type includes a config schema that defines admin-editable fields.

**Related:**
- [Component System Documentation](/docs/component_system.md)
- [Component Field Enhancements](/specs/implemented/component_field_enhancements.md)

**Last Updated:** March 2026 - Updated for pure HTML5 templates (no framework dependencies). Trimmed to high-value types.

---

## Design Principles

All new components use **pure HTML5 with inline styles** — no Bootstrap or other framework classes. This ensures components render correctly regardless of the active theme's CSS framework.

- Use semantic HTML5 elements (`<section>`, `<details>`, `<figure>`, etc.)
- Use inline `style` attributes for configurable properties (colors, alignment, sizing)
- Use a scoped `<style>` block per template for layout rules (flexbox, responsive breakpoints)
- Use `max-width` + `margin: 0 auto` for content centering instead of `.container`
- All text output through `htmlspecialchars()` except richtext fields (already sanitized by Trumbowyg)
- Existing components (hero_static, feature_grid, etc.) remain Bootstrap-based; no changes needed

---

## Implementation Status

| Type | Category | Status |
|------|----------|--------|
| `hero_static` | hero | [x] Done (Bootstrap) |
| `feature_grid` | features | [x] Done (Bootstrap) |
| `cta_banner` | conversion | [x] Done (Bootstrap) |
| `custom_html` | custom | [x] Done |
| `page_title` | layout | [x] Done (Bootstrap) |
| `image_gallery` | media | [x] Done (programmatic) |
| `list_signup` | conversion | [x] Done (Bootstrap) |
| `text_block` | content | [x] Done (HTML5) |
| `text_with_image` | content | [x] Done (HTML5) |
| `accordion` | content | [x] Done (HTML5) |
| `tabs` | content | [x] Done (HTML5) |
| `video_embed` | media | [x] Done (HTML5) |
| `spacer` | layout | [x] Done (HTML5) |
| `divider` | layout | [x] Done (HTML5) |

---

## Components To Build

### `text_block`

**Category:** content
**Description:** Heading with rich text content. The most basic content component.

**Schema:**
```json
{
  "fields": [
    {"name": "heading", "label": "Heading", "type": "textinput"},
    {"name": "heading_level", "label": "Heading Level", "type": "dropinput", "options": {"h2": "H2", "h3": "H3", "h4": "H4"}, "default": "h2"},
    {"name": "content", "label": "Content", "type": "richtext"},
    {"name": "alignment", "label": "Text Alignment", "type": "dropinput", "options": {"left": "Left", "center": "Center", "right": "Right"}, "default": "left"},
    {"name": "background_color", "label": "Background Color", "type": "colorpicker", "advanced": true},
    {"name": "text_color", "label": "Text Color", "type": "colorpicker", "advanced": true}
  ]
}
```

**Template Notes:**
- Heading is optional — if empty, just show content
- Render heading using the selected level (`<h2>`, `<h3>`, or `<h4>`) via a variable
- Use `pac_max_width` for width control instead of a per-component width field
- Background color applied via `style` on the `<section>`; content centered with `max-width` + `margin: 0 auto`
- `text-align` set via inline style from `$alignment`
- Content is richtext — output with `echo` (not `htmlspecialchars`), already sanitized by editor

---

### `text_with_image`

**Category:** content
**Description:** Text content alongside an image, side by side.

**Schema:**
```json
{
  "fields": [
    {"name": "heading", "label": "Heading", "type": "textinput"},
    {"name": "content", "label": "Content", "type": "richtext"},
    {"name": "image_url", "label": "Image", "type": "imageselector"},
    {"name": "image_alt", "label": "Image Alt Text", "type": "textinput"},
    {"name": "layout", "label": "Layout", "type": "dropinput", "options": {"image_right": "Image Right", "image_left": "Image Left"}, "default": "image_right"},
    {"name": "image_size", "label": "Image Size", "type": "dropinput", "options": {"small": "Small (1/3)", "medium": "Medium (1/2)", "large": "Large (2/3)"}, "default": "medium"},
    {"name": "show_cta", "label": "Show Button", "type": "checkboxinput", "advanced": true},
    {"name": "cta_text", "label": "Button Text", "type": "textinput", "advanced": true},
    {"name": "cta_url", "label": "Button URL", "type": "textinput", "advanced": true},
    {"name": "background_color", "label": "Background Color", "type": "colorpicker", "advanced": true}
  ]
}
```

**Template Notes:**
- CSS flexbox layout: `display: flex; gap: 2rem; align-items: center`
- `flex-direction` controls image position: default `row` for image_right, `row-reverse` for image_left
- Image sizing via `flex` shorthand: small = `0 0 33%`, medium = `0 0 50%`, large = `0 0 66%`
- Responsive stacking: scoped `<style>` with `@media (max-width: 768px) { flex-direction: column }` and `flex: 0 0 100%` on image
- Image rendered as `<img>` with `width: 100%; height: auto; object-fit: cover`
- CTA button styled with inline styles (padding, background-color, border, etc.) — simple `<a>` tag
- Content centered with `max-width: 1100px; margin: 0 auto; padding: 0 1rem`

---

### `accordion`

**Category:** content
**Description:** Collapsible FAQ-style content sections using native HTML5 `<details>`/`<summary>`.

**Schema:**
```json
{
  "fields": [
    {"name": "heading", "label": "Section Heading", "type": "textinput"},
    {"name": "subheading", "label": "Section Subheading", "type": "textarea"},
    {
      "name": "items",
      "label": "Accordion Items",
      "type": "repeater",
      "fields": [
        {"name": "title", "label": "Title/Question", "type": "textinput"},
        {"name": "content", "label": "Content/Answer", "type": "richtext"},
        {"name": "is_open", "label": "Open by Default", "type": "checkboxinput"}
      ]
    },
    {"name": "allow_multiple", "label": "Allow Multiple Open", "type": "checkboxinput", "default": true},
    {"name": "style", "label": "Style", "type": "dropinput", "options": {"default": "Default (bordered)", "flush": "Flush (no borders)"}, "default": "default"}
  ]
}
```

**Template Notes:**
- Use native `<details>` / `<summary>` elements — no JavaScript needed for basic open/close
- `is_open` adds the `open` attribute to `<details>`
- `allow_multiple` defaults to true (native `<details>` behavior). When false, a small inline `<script>` closes other `<details>` siblings on `toggle` event
- Scoped `<style>` block for `<summary>` styling: cursor pointer, padding, font-weight, border-bottom
- "Flush" style removes the outer border; "Default" adds a 1px border around each item
- Content div gets padding inside `<details>` below the `<summary>`
- Content is richtext — output with `echo` (already sanitized)
- Give each `<details>` a `name` attribute matching a group identifier — this is the HTML5-native way to create exclusive accordions (single-open). `name` attribute on `<details>` is supported in modern browsers. When `allow_multiple` is false, set `name="accordion-{slug}"`; when true, omit `name`

---

### `tabs`

**Category:** content
**Description:** Tabbed content sections with accessible ARIA markup.

**Schema:**
```json
{
  "fields": [
    {"name": "heading", "label": "Section Heading", "type": "textinput"},
    {
      "name": "tabs",
      "label": "Tabs",
      "type": "repeater",
      "fields": [
        {"name": "title", "label": "Tab Title", "type": "textinput"},
        {"name": "content", "label": "Tab Content", "type": "richtext"}
      ]
    },
    {"name": "tab_style", "label": "Tab Style", "type": "dropinput", "options": {"underline": "Underline", "pills": "Pills"}, "default": "underline"},
    {"name": "alignment", "label": "Tab Alignment", "type": "dropinput", "options": {"start": "Left", "center": "Center"}, "default": "start", "advanced": true}
  ]
}
```

**Template Notes:**
- Tab buttons rendered as `<button>` elements inside a `<div role="tablist">`
- Tab panels rendered as `<div role="tabpanel">` with `id` and `aria-labelledby` attributes
- First tab active by default; active tab button gets `aria-selected="true"`
- Inline `<script>` handles tab switching: hides all panels, shows selected, updates `aria-selected`
- Scoped `<style>` for tab button styling: underline style uses `border-bottom` on active; pills style uses `border-radius` + background-color on active
- Unique IDs from `$component_slug` + index (e.g., `tab-{slug}-0`, `panel-{slug}-0`)
- Tab content is richtext — output with `echo`

---

### `video_embed`

**Category:** media
**Description:** Responsive YouTube or Vimeo video embed.

**Schema:**
```json
{
  "fields": [
    {"name": "heading", "label": "Heading", "type": "textinput"},
    {"name": "video_url", "label": "Video URL", "type": "textinput", "help": "YouTube or Vimeo URL"},
    {"name": "aspect_ratio", "label": "Aspect Ratio", "type": "dropinput", "options": {"16x9": "16:9 (Standard)", "4x3": "4:3", "21x9": "21:9 (Cinematic)"}, "default": "16x9"},
    {"name": "caption", "label": "Caption", "type": "textinput", "advanced": true}
  ]
}
```

**Template Notes:**
- PHP parses the URL to extract video ID:
  - YouTube: match `youtube.com/watch?v=ID`, `youtu.be/ID`, `youtube.com/embed/ID`
  - Vimeo: match `vimeo.com/ID`
  - If no match, render nothing (with optional admin message)
- Use CSS `aspect-ratio` property on the wrapper div: `aspect-ratio: 16/9` (or `4/3`, `21/9`)
- Iframe set to `width: 100%; height: 100%; border: 0`
- Add `loading="lazy"`, `allowfullscreen`, and `allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"` on iframe
- Sanitize: only allow `youtube.com/embed/` and `player.vimeo.com/video/` as iframe src domains
- Caption rendered as `<p>` below the video if set
- Content centered with `max-width: 1100px; margin: 0 auto`

---

### `spacer`

**Category:** layout
**Description:** Vertical spacing between components.

**Schema:**
```json
{
  "fields": [
    {"name": "height", "label": "Height", "type": "dropinput", "options": {"sm": "Small (1rem)", "md": "Medium (2rem)", "lg": "Large (4rem)", "xl": "Extra Large (6rem)"}, "default": "md"}
  ]
}
```

**Layout Defaults:**
```json
{
  "layout_defaults": {
    "skip_wrapper": true,
    "vertical_margin": "none"
  }
}
```

**Template Notes:**
- Single `<div>` with inline `style="height: Xrem"` based on selected option
- Height map: sm=1rem, md=2rem, lg=4rem, xl=6rem
- `aria-hidden="true"` since it's purely decorative
- No container needed — `skip_wrapper` is true
- Vertical margin set to "none" since the spacer IS the spacing

---

### `divider`

**Category:** layout
**Description:** Horizontal divider line.

**Schema:**
```json
{
  "fields": [
    {"name": "style", "label": "Line Style", "type": "dropinput", "options": {"solid": "Solid", "dashed": "Dashed", "dotted": "Dotted"}, "default": "solid"},
    {"name": "width", "label": "Width", "type": "dropinput", "options": {"full": "Full Width", "medium": "Medium (50%)", "short": "Short (25%)"}, "default": "full"},
    {"name": "color", "label": "Line Color", "type": "colorpicker", "advanced": true}
  ]
}
```

**Template Notes:**
- Use `<hr>` element with all styling via inline `style` attribute
- `border-style` from the style field; `border-width: 1px 0 0 0`; `border-color` from the color field (default: `#dee2e6`)
- `width` from the width field: full=100%, medium=50%, short=25%
- Center with `margin-left: auto; margin-right: auto` when not full width
- Render inside a container div with `max-width: 1100px; margin: 0 auto; padding: 0 1rem`

---

## Already Implemented

These types are complete and their JSON + PHP template files exist in `/views/components/`:

- **`hero_static`** — Hero with heading, subheading, background, CTA (Bootstrap)
- **`feature_grid`** — Grid of icon + title + description items (Bootstrap)
- **`cta_banner`** — Full-width call-to-action banner (Bootstrap)
- **`custom_html`** — Raw HTML for advanced users
- **`page_title`** — Page title with optional breadcrumbs (Bootstrap)
- **`image_gallery`** — Image gallery (programmatic rendering mode)
- **`list_signup`** — Newsletter/mailing list signup with logic function (Bootstrap)

---

## Theme-Specific Components

Themes define their own component types prefixed with the theme name. These are discovered automatically during theme sync.

**Naming Convention:** `{theme}_{component_name}`

**Example (linka theme):** `linka_hero`, `linka_featured_card`, `linka_featured_grid`, `linka_editor_choice`, `linka_page_title`, etc.

**Template Location:** `/theme/{theme}/views/components/{type_key}.php`

---

## Implementation Guide

Each new component type requires two files:

### 1. JSON Definition (`/views/components/{type_key}.json`)

```json
{
  "title": "Component Title",
  "description": "What this component does",
  "category": "content",
  "config_schema": {
    "fields": [...]
  },
  "layout_defaults": {}
}
```

Note: `css_framework` is omitted for framework-independent components. Only set it when a template requires a specific framework.

### 2. PHP Template (`/views/components/{type_key}.php`)

```php
<?php
// Available variables:
// $component_config - configured field values
// $component_data - data from logic function (if any)
// $component - PageContent instance
// $component_type_record - Component type definition
// $component_slug - component location name

$heading = $component_config['heading'] ?? '';
?>
<style>
.text-block-<?php echo htmlspecialchars($component_slug); ?> { max-width: 1100px; margin: 0 auto; padding: 3rem 1rem; }
</style>
<section class="text-block-<?php echo htmlspecialchars($component_slug); ?>">
  <?php if (!empty($heading)): ?>
    <h2><?php echo htmlspecialchars($heading); ?></h2>
  <?php endif; ?>
  <!-- component content -->
</section>
```

After creating both files, run theme sync to register the component type in the database.

---

## Removed from Original Spec

| Type | Reason |
|------|--------|
| `hero_slider` | Complex carousel JavaScript; `hero_static` covers most needs |
| `stats_counter` | Animated scroll counters require significant custom JS |
| `testimonial_slider` | Already have `testimonials_carousel` component |
| `logo_wall` | Niche use case; achievable with `custom_html` |
| `pricing_table` | Already have `pricing_section` component |
| `image_gallery` (admin) | Already have programmatic `image_gallery`; admin-configurable version adds complexity for low usage |
| `recent_posts` | Requires logic function + ties to specific content model |
| `upcoming_events` | Requires logic function + events plugin dependency |
