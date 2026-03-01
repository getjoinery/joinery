# Component Type Library

This document defines the planned component types for the page builder system. Each component type includes a config schema that defines admin-editable fields.

**Related:**
- [Component System Documentation](/docs/component_system.md)
- [Component Field Enhancements](/specs/component_field_enhancements.md)

**Last Updated:** March 2026 - Trimmed to high-value types. Removed hero_slider, stats_counter, testimonial_slider, logo_wall, pricing_table, image_gallery (admin), recent_posts, upcoming_events.

---

## Implementation Status

| Type | Category | Status |
|------|----------|--------|
| `hero_static` | hero | [x] Done |
| `feature_grid` | features | [x] Done |
| `cta_banner` | conversion | [x] Done |
| `custom_html` | custom | [x] Done |
| `page_title` | layout | [x] Done |
| `image_gallery` | media | [x] Done (programmatic) |
| `list_signup` | conversion | [x] Done |
| `text_block` | content | [ ] **To build** |
| `text_with_image` | content | [ ] **To build** |
| `accordion` | content | [ ] **To build** |
| `tabs` | content | [ ] **To build** |
| `video_embed` | media | [ ] **To build** |
| `spacer` | layout | [ ] **To build** |
| `divider` | layout | [ ] **To build** |

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
- Use `pac_max_width` for width control instead of a per-component width field
- Background color wraps the full section; text sits in a container

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
- Bootstrap row/col layout with responsive stacking on mobile
- Image column width based on `image_size` (col-4/col-6/col-8)
- Column order flip via `order-` classes for `image_left`

---

### `accordion`

**Category:** content
**Description:** Collapsible FAQ-style content sections. Uses Bootstrap 5 accordion.

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
    {"name": "allow_multiple", "label": "Allow Multiple Open", "type": "checkboxinput"},
    {"name": "style", "label": "Style", "type": "dropinput", "options": {"default": "Default", "flush": "Flush (No Borders)"}, "default": "default"}
  ]
}
```

**Template Notes:**
- Use Bootstrap 5 `accordion` component with `data-bs-parent` for single-open mode
- Skip `data-bs-parent` when `allow_multiple` is true
- Each item gets a unique ID based on component slug + index
- `is_open` adds `show` class and removes `collapsed` from button

---

### `tabs`

**Category:** content
**Description:** Tabbed content sections. Uses Bootstrap 5 tabs.

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
    {"name": "tab_style", "label": "Tab Style", "type": "dropinput", "options": {"tabs": "Tabs", "pills": "Pills"}, "default": "tabs"},
    {"name": "alignment", "label": "Tab Alignment", "type": "dropinput", "options": {"start": "Left", "center": "Center"}, "default": "start", "advanced": true}
  ]
}
```

**Template Notes:**
- Use Bootstrap 5 `nav-tabs` / `nav-pills` with `tab-content` / `tab-pane`
- First tab is active by default
- Unique IDs from component slug + index

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
- Parse YouTube URLs (`youtube.com/watch?v=`, `youtu.be/`) and Vimeo URLs (`vimeo.com/`) to extract video IDs
- Use Bootstrap `ratio` component: `<div class="ratio ratio-16x9"><iframe ...></iframe></div>`
- Add `loading="lazy"` and `allowfullscreen` to iframe
- Sanitize: only allow youtube.com and vimeo.com embed domains

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
- Single empty `<div>` with height from CSS class or inline style
- No container needed — skip_wrapper is true
- Vertical margin set to none since the spacer IS the spacing

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
- Use `<hr>` element with inline styles for border-style, width, and color
- Center the `<hr>` with `margin-left: auto; margin-right: auto` when not full width
- Render inside a container div

---

## Already Implemented

These types are complete and their JSON + PHP template files exist in `/views/components/`:

- **`hero_static`** — Hero with heading, subheading, background, CTA
- **`feature_grid`** — Grid of icon + title + description items
- **`cta_banner`** — Full-width call-to-action banner
- **`custom_html`** — Raw HTML for advanced users
- **`page_title`** — Page title with optional breadcrumbs
- **`image_gallery`** — Image gallery (programmatic rendering mode)
- **`list_signup`** — Newsletter/mailing list signup with logic function

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
  "css_framework": "bootstrap",
  "config_schema": {
    "fields": [...]
  },
  "layout_defaults": {}
}
```

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
<section class="component-name py-5">
  <div class="container">
    <?php if (!empty($heading)): ?>
      <h2><?= htmlspecialchars($heading) ?></h2>
    <?php endif; ?>
    <!-- component content -->
  </div>
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
