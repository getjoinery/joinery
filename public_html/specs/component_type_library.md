# Component Type Library

This document defines the planned component types for the page builder system. Each component type includes a config schema that defines admin-editable fields.

**Prerequisites:**
- [Component Field Enhancements](/specs/component_field_enhancements.md) - Some components require field types not yet implemented

**Note:** Schemas marked with `*` require field types from Phase 2/3 enhancements. Simplified versions can be created using currently available field types.

**Last Updated:** January 2026 - Updated implementation status for hero_static, feature_grid, cta_banner, custom_html, page_title. Added theme-specific components section.

---

## Implementation Status Key

| Symbol | Meaning |
|--------|---------|
| `[x]` | Component type created and seeded |
| `[ ]` | Not yet created |
| `*` | Requires enhanced field types |

---

## Hero Components

### `hero_static` [x]

**Category:** hero
**Description:** Single hero section with heading, subheading, background, and CTA

**Simplified Schema (Current Capabilities):**
```json
{
  "fields": [
    {"name": "heading", "label": "Heading", "type": "textinput"},
    {"name": "subheading", "label": "Subheading", "type": "textarea"},
    {"name": "alignment", "label": "Text Alignment", "type": "dropinput", "options": {"left": "Left", "center": "Center", "right": "Right"}},
    {"name": "background_color", "label": "Background Color (hex)", "type": "textinput", "help": "e.g., #f8f9fa"},
    {"name": "background_image_url", "label": "Background Image URL", "type": "textinput"},
    {"name": "text_color", "label": "Text Color (hex)", "type": "textinput", "help": "e.g., #212529"},
    {"name": "height", "label": "Section Height", "type": "dropinput", "options": {"small": "Small", "medium": "Medium", "large": "Large", "fullscreen": "Full Screen"}},
    {"name": "show_cta", "label": "Show CTA Button", "type": "checkboxinput"},
    {"name": "cta_text", "label": "Button Text", "type": "textinput"},
    {"name": "cta_url", "label": "Button URL", "type": "textinput"},
    {"name": "cta_style", "label": "Button Style", "type": "dropinput", "options": {"primary": "Primary", "secondary": "Secondary", "outline": "Outline"}}
  ]
}
```

---

### `hero_slider` [ ] *

**Category:** hero
**Description:** Rotating hero slides with auto-play

**Simplified Schema:**
```json
{
  "fields": [
    {
      "name": "slides",
      "label": "Slides",
      "type": "repeater",
      "fields": [
        {"name": "heading", "label": "Heading", "type": "textinput"},
        {"name": "subheading", "label": "Subheading", "type": "textarea"},
        {"name": "background_image_url", "label": "Background Image URL", "type": "textinput"},
        {"name": "cta_text", "label": "Button Text", "type": "textinput"},
        {"name": "cta_url", "label": "Button URL", "type": "textinput"}
      ]
    },
    {"name": "autoplay", "label": "Auto-play slides", "type": "checkboxinput"},
    {"name": "interval", "label": "Slide Duration (seconds)", "type": "textinput", "help": "e.g., 5"},
    {"name": "show_arrows", "label": "Show Navigation Arrows", "type": "checkboxinput"},
    {"name": "show_dots", "label": "Show Navigation Dots", "type": "checkboxinput"},
    {"name": "height", "label": "Slider Height", "type": "dropinput", "options": {"medium": "Medium", "large": "Large", "fullscreen": "Full Screen"}}
  ]
}
```

---

## Content Components

### `text_block` [ ] *

**Category:** content
**Description:** Heading with text content

**Simplified Schema:**
```json
{
  "fields": [
    {"name": "heading", "label": "Heading", "type": "textinput"},
    {"name": "heading_level", "label": "Heading Level", "type": "dropinput", "options": {"h2": "H2", "h3": "H3", "h4": "H4"}},
    {"name": "content", "label": "Content", "type": "textarea", "help": "HTML is allowed"},
    {"name": "alignment", "label": "Text Alignment", "type": "dropinput", "options": {"left": "Left", "center": "Center", "right": "Right"}},
    {"name": "max_width", "label": "Content Width", "type": "dropinput", "options": {"narrow": "Narrow", "medium": "Medium", "wide": "Wide", "full": "Full Width"}},
    {"name": "background_color", "label": "Background Color (hex)", "type": "textinput"},
    {"name": "padding", "label": "Vertical Padding", "type": "dropinput", "options": {"none": "None", "small": "Small", "medium": "Medium", "large": "Large"}}
  ]
}
```

---

### `text_with_image` [ ] *

**Category:** content
**Description:** Text content alongside an image

**Simplified Schema:**
```json
{
  "fields": [
    {"name": "heading", "label": "Heading", "type": "textinput"},
    {"name": "content", "label": "Content", "type": "textarea"},
    {"name": "image_url", "label": "Image URL", "type": "textinput"},
    {"name": "image_alt", "label": "Image Alt Text", "type": "textinput"},
    {"name": "layout", "label": "Layout", "type": "dropinput", "options": {"image_left": "Image Left", "image_right": "Image Right"}},
    {"name": "image_size", "label": "Image Size", "type": "dropinput", "options": {"small": "Small (1/3)", "medium": "Medium (1/2)", "large": "Large (2/3)"}},
    {"name": "show_cta", "label": "Show Button", "type": "checkboxinput"},
    {"name": "cta_text", "label": "Button Text", "type": "textinput"},
    {"name": "cta_url", "label": "Button URL", "type": "textinput"}
  ]
}
```

---

### `accordion` [ ]

**Category:** content
**Description:** Collapsible FAQ-style content sections

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
        {"name": "content", "label": "Content/Answer", "type": "textarea"},
        {"name": "is_open", "label": "Open by Default", "type": "checkboxinput"}
      ]
    },
    {"name": "allow_multiple", "label": "Allow Multiple Open", "type": "checkboxinput"},
    {"name": "style", "label": "Accordion Style", "type": "dropinput", "options": {"bordered": "Bordered", "minimal": "Minimal", "filled": "Filled Background"}}
  ]
}
```

---

### `tabs` [ ]

**Category:** content
**Description:** Tabbed content sections

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
        {"name": "content", "label": "Tab Content", "type": "textarea"}
      ]
    },
    {"name": "tab_style", "label": "Tab Style", "type": "dropinput", "options": {"default": "Default", "pills": "Pills", "underline": "Underline"}}
  ]
}
```

---

## Feature Components

### `feature_grid` [x]

**Category:** features
**Description:** Grid of icon + title + description items

**Schema:**
```json
{
  "fields": [
    {"name": "heading", "label": "Section Heading", "type": "textinput"},
    {"name": "subheading", "label": "Section Subheading", "type": "textarea"},
    {"name": "columns", "label": "Columns", "type": "dropinput", "options": {"2": "2 Columns", "3": "3 Columns", "4": "4 Columns"}},
    {
      "name": "features",
      "label": "Features",
      "type": "repeater",
      "fields": [
        {"name": "icon_class", "label": "Icon Class", "type": "textinput", "help": "e.g., bx bx-check"},
        {"name": "title", "label": "Title", "type": "textinput"},
        {"name": "description", "label": "Description", "type": "textarea"},
        {"name": "link_url", "label": "Link URL (optional)", "type": "textinput"}
      ]
    },
    {"name": "style", "label": "Display Style", "type": "dropinput", "options": {"centered": "Centered", "left": "Left Aligned", "card": "Cards with Shadow"}},
    {"name": "icon_color", "label": "Icon Color (hex)", "type": "textinput"}
  ]
}
```

---

### `stats_counter` [ ]

**Category:** features
**Description:** Animated number counters with labels

**Schema:**
```json
{
  "fields": [
    {"name": "heading", "label": "Section Heading", "type": "textinput"},
    {
      "name": "stats",
      "label": "Statistics",
      "type": "repeater",
      "fields": [
        {"name": "number", "label": "Number", "type": "textinput"},
        {"name": "prefix", "label": "Prefix (e.g., $)", "type": "textinput"},
        {"name": "suffix", "label": "Suffix (e.g., +, %)", "type": "textinput"},
        {"name": "label", "label": "Label", "type": "textinput"}
      ]
    },
    {"name": "animate", "label": "Animate Numbers on Scroll", "type": "checkboxinput"},
    {"name": "background_style", "label": "Background", "type": "dropinput", "options": {"light": "Light", "dark": "Dark", "primary": "Primary Color"}}
  ]
}
```

---

## Testimonial Components

### `testimonial_slider` [ ]

**Category:** testimonials
**Description:** Rotating testimonial quotes

**Schema:**
```json
{
  "fields": [
    {"name": "heading", "label": "Section Heading", "type": "textinput"},
    {
      "name": "testimonials",
      "label": "Testimonials",
      "type": "repeater",
      "fields": [
        {"name": "quote", "label": "Quote", "type": "textarea"},
        {"name": "author_name", "label": "Author Name", "type": "textinput"},
        {"name": "author_title", "label": "Author Title/Company", "type": "textinput"},
        {"name": "author_image_url", "label": "Author Photo URL", "type": "textinput"},
        {"name": "rating", "label": "Star Rating (1-5)", "type": "textinput"}
      ]
    },
    {"name": "autoplay", "label": "Auto-rotate", "type": "checkboxinput"},
    {"name": "interval", "label": "Rotation Interval (seconds)", "type": "textinput"},
    {"name": "show_rating", "label": "Show Star Rating", "type": "checkboxinput"},
    {"name": "style", "label": "Display Style", "type": "dropinput", "options": {"centered": "Centered", "card": "Card Style", "minimal": "Minimal"}}
  ]
}
```

---

### `logo_wall` [ ]

**Category:** testimonials
**Description:** Grid of client/partner logos

**Schema:**
```json
{
  "fields": [
    {"name": "heading", "label": "Section Heading", "type": "textinput"},
    {
      "name": "logos",
      "label": "Logos",
      "type": "repeater",
      "fields": [
        {"name": "image_url", "label": "Logo Image URL", "type": "textinput"},
        {"name": "name", "label": "Company Name (alt text)", "type": "textinput"},
        {"name": "link_url", "label": "Link URL (optional)", "type": "textinput"}
      ]
    },
    {"name": "columns", "label": "Logos Per Row", "type": "dropinput", "options": {"4": "4", "5": "5", "6": "6"}},
    {"name": "grayscale", "label": "Grayscale (color on hover)", "type": "checkboxinput"}
  ]
}
```

---

## Conversion Components

### `cta_banner` [x]

**Category:** conversion
**Description:** Full-width call-to-action banner

**Schema:**
```json
{
  "fields": [
    {"name": "heading", "label": "Heading", "type": "textinput"},
    {"name": "subheading", "label": "Supporting Text", "type": "textarea"},
    {"name": "cta_text", "label": "Button Text", "type": "textinput"},
    {"name": "cta_url", "label": "Button URL", "type": "textinput"},
    {"name": "background_color", "label": "Background Color (hex)", "type": "textinput"},
    {"name": "text_color", "label": "Text Color (hex)", "type": "textinput"},
    {"name": "show_secondary", "label": "Show Secondary Button", "type": "checkboxinput"},
    {"name": "secondary_text", "label": "Secondary Button Text", "type": "textinput"},
    {"name": "secondary_url", "label": "Secondary Button URL", "type": "textinput"}
  ]
}
```

---

### `pricing_table` [ ]

**Category:** conversion
**Description:** Pricing plan comparison

**Schema:**
```json
{
  "fields": [
    {"name": "heading", "label": "Section Heading", "type": "textinput"},
    {"name": "subheading", "label": "Section Subheading", "type": "textarea"},
    {
      "name": "plans",
      "label": "Plans",
      "type": "repeater",
      "fields": [
        {"name": "name", "label": "Plan Name", "type": "textinput"},
        {"name": "price", "label": "Price", "type": "textinput"},
        {"name": "period", "label": "Period (e.g., /month)", "type": "textinput"},
        {"name": "description", "label": "Short Description", "type": "textinput"},
        {"name": "features", "label": "Features (one per line)", "type": "textarea"},
        {"name": "cta_text", "label": "Button Text", "type": "textinput"},
        {"name": "cta_url", "label": "Button URL", "type": "textinput"},
        {"name": "is_featured", "label": "Featured/Recommended", "type": "checkboxinput"},
        {"name": "badge_text", "label": "Badge Text", "type": "textinput"}
      ]
    }
  ]
}
```

---

## Layout Components

### `page_title` [x]

**Category:** layout
**Description:** Page title with optional subtitle and breadcrumbs

**Schema:**
```json
{
  "fields": [
    {"name": "title", "label": "Page Title", "type": "textinput"},
    {"name": "subtitle", "label": "Subtitle", "type": "textinput"},
    {"name": "alignment", "label": "Text Alignment", "type": "dropinput", "options": {"left": "Left", "center": "Center"}},
    {"name": "show_breadcrumbs", "label": "Show Breadcrumbs", "type": "checkboxinput"},
    {"name": "background_color", "label": "Background Color (hex)", "type": "textinput"}
  ]
}
```

---

### `spacer` [ ]

**Category:** layout
**Description:** Vertical spacing

**Schema:**
```json
{
  "fields": [
    {"name": "height", "label": "Spacer Height", "type": "dropinput", "options": {"small": "Small (20px)", "medium": "Medium (40px)", "large": "Large (80px)", "xlarge": "Extra Large (120px)"}},
    {"name": "mobile_height", "label": "Mobile Height", "type": "dropinput", "options": {"same": "Same as Desktop", "half": "Half", "none": "None"}}
  ]
}
```

---

### `divider` [ ]

**Category:** layout
**Description:** Horizontal divider line

**Schema:**
```json
{
  "fields": [
    {"name": "style", "label": "Divider Style", "type": "dropinput", "options": {"solid": "Solid Line", "dashed": "Dashed Line", "dotted": "Dotted Line"}},
    {"name": "width", "label": "Width", "type": "dropinput", "options": {"full": "Full Width", "medium": "Medium (50%)", "short": "Short (20%)"}},
    {"name": "color", "label": "Line Color (hex)", "type": "textinput"},
    {"name": "margin", "label": "Vertical Margin", "type": "dropinput", "options": {"small": "Small", "medium": "Medium", "large": "Large"}}
  ]
}
```

---

## Media Components

### `image_gallery` [ ]

**Category:** media
**Description:** Grid of images with optional lightbox

**Schema:**
```json
{
  "fields": [
    {"name": "heading", "label": "Section Heading", "type": "textinput"},
    {
      "name": "images",
      "label": "Images",
      "type": "repeater",
      "fields": [
        {"name": "image_url", "label": "Image URL", "type": "textinput"},
        {"name": "caption", "label": "Caption", "type": "textinput"},
        {"name": "link_url", "label": "Link URL (optional)", "type": "textinput"}
      ]
    },
    {"name": "columns", "label": "Columns", "type": "dropinput", "options": {"2": "2", "3": "3", "4": "4", "5": "5"}},
    {"name": "gap", "label": "Gap Between Images", "type": "dropinput", "options": {"none": "None", "small": "Small", "medium": "Medium"}},
    {"name": "lightbox", "label": "Enable Lightbox", "type": "checkboxinput"},
    {"name": "show_captions", "label": "Show Captions", "type": "checkboxinput"}
  ]
}
```

---

### `video_embed` [ ]

**Category:** media
**Description:** YouTube or Vimeo video embed

**Schema:**
```json
{
  "fields": [
    {"name": "heading", "label": "Section Heading", "type": "textinput"},
    {"name": "video_url", "label": "Video URL", "type": "textinput", "help": "YouTube or Vimeo URL"},
    {"name": "aspect_ratio", "label": "Aspect Ratio", "type": "dropinput", "options": {"16:9": "16:9 (Standard)", "4:3": "4:3", "21:9": "21:9 (Cinematic)"}},
    {"name": "max_width", "label": "Maximum Width", "type": "dropinput", "options": {"small": "Small", "medium": "Medium", "large": "Large", "full": "Full Width"}}
  ]
}
```

---

## Custom Components

### `custom_html` [x]

**Category:** custom
**Description:** Raw HTML for advanced users

**Schema:**
```json
{
  "fields": [
    {"name": "html", "label": "HTML Code", "type": "textarea", "help": "Enter custom HTML. Be careful with scripts."},
    {"name": "container", "label": "Wrap in Container", "type": "checkboxinput"},
    {"name": "admin_note", "label": "Admin Notes", "type": "textarea", "help": "Notes for administrators (not displayed)"}
  ]
}
```

---

## Dynamic Components

These components require logic functions to fetch data.

### `recent_posts` [ ]

**Category:** dynamic
**Logic Function:** `recent_posts_logic`

**Schema:**
```json
{
  "fields": [
    {"name": "heading", "label": "Section Heading", "type": "textinput"},
    {"name": "post_count", "label": "Number of Posts", "type": "textinput", "help": "e.g., 3"},
    {"name": "columns", "label": "Columns", "type": "dropinput", "options": {"2": "2", "3": "3", "4": "4"}},
    {"name": "show_image", "label": "Show Featured Image", "type": "checkboxinput"},
    {"name": "show_excerpt", "label": "Show Excerpt", "type": "checkboxinput"},
    {"name": "show_date", "label": "Show Date", "type": "checkboxinput"},
    {"name": "show_view_all", "label": "Show View All Link", "type": "checkboxinput"},
    {"name": "view_all_url", "label": "View All URL", "type": "textinput"}
  ]
}
```

---

### `upcoming_events` [ ]

**Category:** dynamic
**Logic Function:** `upcoming_events_logic`
**Requires Plugin:** events

**Schema:**
```json
{
  "fields": [
    {"name": "heading", "label": "Section Heading", "type": "textinput"},
    {"name": "event_count", "label": "Number of Events", "type": "textinput"},
    {"name": "layout", "label": "Layout", "type": "dropinput", "options": {"list": "List", "grid": "Grid"}},
    {"name": "show_image", "label": "Show Event Image", "type": "checkboxinput"},
    {"name": "show_time", "label": "Show Event Time", "type": "checkboxinput"},
    {"name": "show_location", "label": "Show Location", "type": "checkboxinput"}
  ]
}
```

---

## Theme-Specific Components

Themes can define their own component types by prefixing the type key with the theme name. These components are specific to that theme and include both the database record and template file within the theme directory.

**Naming Convention:** `{theme}_{component_name}`

**Example (linka-reference theme):**
- `linka_hero` - Theme-specific hero variant
- `linka_featured_card` - Card component for blog features
- `linka_featured_grid` - Grid layout for featured content
- `linka_editor_choice` - Editor's choice showcase
- `linka_inspiration` - Inspiration section
- `linka_contact_info` - Contact information display
- `linka_newsletter` - Newsletter signup form
- `linka_social_follow` - Social media follow buttons
- `linka_page_title` - Theme-styled page title

**Template Location:**
```
/theme/{theme}/views/components/{component_template}.php
```

Theme-specific components should set `com_css_framework` to indicate compatibility (e.g., 'bootstrap', 'tailwind').

---

## Implementation Notes

### Creating a Component Type

1. Go to `/admin/admin_component_types` (superadmin required)
2. Click "Add Component Type"
3. Enter:
   - **Type Key**: e.g., `hero_static`
   - **Title**: e.g., "Static Hero"
   - **Category**: Select from dropdown
   - **Template File**: e.g., `hero_static.php`
   - **Config Schema**: Paste JSON from above
4. Create template file in `/views/components/`
5. Activate the component type

### Template File Location

```
/views/components/{template_file}.php
/theme/{theme}/views/components/{template_file}.php  (override)
```

### Available Template Variables

- `$component_config` - The configured values
- `$component_data` - Data from logic function (if any)
- `$component` - The PageContent instance
- `$component_type_record` - The Component type definition
