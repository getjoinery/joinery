# Page Block System - Block Type Definitions

This document contains the complete config schema definitions for all planned block types.

---

## Hero Blocks

### `hero_static`

**Category:** hero
**Description:** Single hero section with heading, subheading, background, and CTA

```json
{
  "fields": [
    {
      "key": "heading",
      "type": "text",
      "label": "Heading",
      "required": true,
      "placeholder": "Welcome to Our Site"
    },
    {
      "key": "subheading",
      "type": "textarea",
      "label": "Subheading",
      "rows": 2,
      "placeholder": "A brief description of what you offer"
    },
    {
      "key": "alignment",
      "type": "select",
      "label": "Text Alignment",
      "options": [
        {"value": "left", "label": "Left"},
        {"value": "center", "label": "Center"},
        {"value": "right", "label": "Right"}
      ],
      "default": "center"
    },
    {
      "key": "background_type",
      "type": "select",
      "label": "Background Type",
      "options": [
        {"value": "color", "label": "Solid Color"},
        {"value": "image", "label": "Image"},
        {"value": "gradient", "label": "Gradient"}
      ],
      "default": "color"
    },
    {
      "key": "background_color",
      "type": "color",
      "label": "Background Color",
      "default": "#f8f9fa",
      "condition": {"field": "background_type", "operator": "equals", "value": "color"}
    },
    {
      "key": "background_image",
      "type": "image",
      "label": "Background Image",
      "condition": {"field": "background_type", "operator": "equals", "value": "image"}
    },
    {
      "key": "overlay_opacity",
      "type": "number",
      "label": "Image Overlay Opacity (%)",
      "min": 0,
      "max": 100,
      "default": 50,
      "condition": {"field": "background_type", "operator": "equals", "value": "image"}
    },
    {
      "key": "gradient_start",
      "type": "color",
      "label": "Gradient Start Color",
      "default": "#667eea",
      "condition": {"field": "background_type", "operator": "equals", "value": "gradient"}
    },
    {
      "key": "gradient_end",
      "type": "color",
      "label": "Gradient End Color",
      "default": "#764ba2",
      "condition": {"field": "background_type", "operator": "equals", "value": "gradient"}
    },
    {
      "key": "text_color",
      "type": "color",
      "label": "Text Color",
      "default": "#212529"
    },
    {
      "key": "height",
      "type": "select",
      "label": "Section Height",
      "options": [
        {"value": "small", "label": "Small (200px)"},
        {"value": "medium", "label": "Medium (400px)"},
        {"value": "large", "label": "Large (600px)"},
        {"value": "fullscreen", "label": "Full Screen"}
      ],
      "default": "medium"
    },
    {
      "key": "cta",
      "type": "group",
      "label": "Call to Action",
      "collapsible": true,
      "fields": [
        {
          "key": "show_cta",
          "type": "checkbox",
          "label": "Show CTA Button",
          "default": true
        },
        {
          "key": "cta_text",
          "type": "text",
          "label": "Button Text",
          "default": "Learn More",
          "condition": {"field": "cta.show_cta", "operator": "equals", "value": true}
        },
        {
          "key": "cta_link",
          "type": "link",
          "label": "Button Link",
          "condition": {"field": "cta.show_cta", "operator": "equals", "value": true}
        },
        {
          "key": "cta_style",
          "type": "select",
          "label": "Button Style",
          "options": [
            {"value": "primary", "label": "Primary"},
            {"value": "secondary", "label": "Secondary"},
            {"value": "outline", "label": "Outline"},
            {"value": "white", "label": "White"}
          ],
          "default": "primary",
          "condition": {"field": "cta.show_cta", "operator": "equals", "value": true}
        }
      ]
    },
    {
      "key": "secondary_cta",
      "type": "group",
      "label": "Secondary CTA (Optional)",
      "collapsible": true,
      "collapsed": true,
      "fields": [
        {
          "key": "show_secondary",
          "type": "checkbox",
          "label": "Show Secondary Button"
        },
        {
          "key": "secondary_text",
          "type": "text",
          "label": "Button Text",
          "condition": {"field": "secondary_cta.show_secondary", "operator": "equals", "value": true}
        },
        {
          "key": "secondary_link",
          "type": "link",
          "label": "Button Link",
          "condition": {"field": "secondary_cta.show_secondary", "operator": "equals", "value": true}
        }
      ]
    }
  ]
}
```

### `hero_slider`

**Category:** hero
**Description:** Rotating hero slides with auto-play

```json
{
  "fields": [
    {
      "key": "slides",
      "type": "repeater",
      "label": "Slides",
      "item_label": "Slide",
      "min": 1,
      "max": 10,
      "fields": [
        {
          "key": "heading",
          "type": "text",
          "label": "Heading",
          "required": true
        },
        {
          "key": "subheading",
          "type": "textarea",
          "label": "Subheading",
          "rows": 2
        },
        {
          "key": "background_image",
          "type": "image",
          "label": "Background Image"
        },
        {
          "key": "cta_text",
          "type": "text",
          "label": "Button Text"
        },
        {
          "key": "cta_link",
          "type": "link",
          "label": "Button Link"
        }
      ]
    },
    {
      "key": "settings",
      "type": "group",
      "label": "Slider Settings",
      "collapsible": true,
      "fields": [
        {
          "key": "autoplay",
          "type": "checkbox",
          "label": "Auto-play slides",
          "default": true
        },
        {
          "key": "interval",
          "type": "number",
          "label": "Slide Duration (seconds)",
          "min": 2,
          "max": 30,
          "default": 5,
          "condition": {"field": "settings.autoplay", "operator": "equals", "value": true}
        },
        {
          "key": "show_arrows",
          "type": "checkbox",
          "label": "Show Navigation Arrows",
          "default": true
        },
        {
          "key": "show_dots",
          "type": "checkbox",
          "label": "Show Navigation Dots",
          "default": true
        },
        {
          "key": "transition",
          "type": "select",
          "label": "Transition Effect",
          "options": [
            {"value": "slide", "label": "Slide"},
            {"value": "fade", "label": "Fade"}
          ],
          "default": "slide"
        }
      ]
    },
    {
      "key": "height",
      "type": "select",
      "label": "Slider Height",
      "options": [
        {"value": "medium", "label": "Medium (400px)"},
        {"value": "large", "label": "Large (600px)"},
        {"value": "fullscreen", "label": "Full Screen"}
      ],
      "default": "large"
    },
    {
      "key": "overlay_opacity",
      "type": "number",
      "label": "Image Overlay Opacity (%)",
      "min": 0,
      "max": 100,
      "default": 40
    },
    {
      "key": "text_color",
      "type": "color",
      "label": "Text Color",
      "default": "#ffffff"
    }
  ]
}
```

### `hero_video`

**Category:** hero
**Description:** Video background hero section

```json
{
  "fields": [
    {
      "key": "heading",
      "type": "text",
      "label": "Heading",
      "required": true
    },
    {
      "key": "subheading",
      "type": "textarea",
      "label": "Subheading",
      "rows": 2
    },
    {
      "key": "video_type",
      "type": "select",
      "label": "Video Source",
      "options": [
        {"value": "upload", "label": "Uploaded Video"},
        {"value": "youtube", "label": "YouTube"},
        {"value": "vimeo", "label": "Vimeo"}
      ],
      "default": "youtube"
    },
    {
      "key": "video_url",
      "type": "text",
      "label": "Video URL",
      "placeholder": "https://www.youtube.com/watch?v=..."
    },
    {
      "key": "fallback_image",
      "type": "image",
      "label": "Fallback Image (for mobile)"
    },
    {
      "key": "overlay_opacity",
      "type": "number",
      "label": "Overlay Opacity (%)",
      "min": 0,
      "max": 100,
      "default": 50
    },
    {
      "key": "text_color",
      "type": "color",
      "label": "Text Color",
      "default": "#ffffff"
    },
    {
      "key": "cta",
      "type": "group",
      "label": "Call to Action",
      "fields": [
        {"key": "cta_text", "type": "text", "label": "Button Text"},
        {"key": "cta_link", "type": "link", "label": "Button Link"}
      ]
    }
  ]
}
```

---

## Content Blocks

### `text_block`

**Category:** content
**Description:** Simple heading and rich text content

```json
{
  "fields": [
    {
      "key": "heading",
      "type": "text",
      "label": "Heading"
    },
    {
      "key": "heading_level",
      "type": "select",
      "label": "Heading Level",
      "options": [
        {"value": "h2", "label": "H2"},
        {"value": "h3", "label": "H3"},
        {"value": "h4", "label": "H4"}
      ],
      "default": "h2"
    },
    {
      "key": "content",
      "type": "richtext",
      "label": "Content",
      "required": true
    },
    {
      "key": "alignment",
      "type": "select",
      "label": "Text Alignment",
      "options": [
        {"value": "left", "label": "Left"},
        {"value": "center", "label": "Center"},
        {"value": "right", "label": "Right"}
      ],
      "default": "left"
    },
    {
      "key": "max_width",
      "type": "select",
      "label": "Content Width",
      "options": [
        {"value": "narrow", "label": "Narrow (600px)"},
        {"value": "medium", "label": "Medium (800px)"},
        {"value": "wide", "label": "Wide (1000px)"},
        {"value": "full", "label": "Full Width"}
      ],
      "default": "medium"
    },
    {
      "key": "background_color",
      "type": "color",
      "label": "Background Color",
      "default": "#ffffff"
    },
    {
      "key": "padding",
      "type": "select",
      "label": "Vertical Padding",
      "options": [
        {"value": "none", "label": "None"},
        {"value": "small", "label": "Small"},
        {"value": "medium", "label": "Medium"},
        {"value": "large", "label": "Large"}
      ],
      "default": "medium"
    }
  ]
}
```

### `text_with_image`

**Category:** content
**Description:** Text content alongside an image

```json
{
  "fields": [
    {
      "key": "heading",
      "type": "text",
      "label": "Heading"
    },
    {
      "key": "content",
      "type": "richtext",
      "label": "Content",
      "required": true
    },
    {
      "key": "image",
      "type": "image",
      "label": "Image",
      "required": true
    },
    {
      "key": "image_alt",
      "type": "text",
      "label": "Image Alt Text"
    },
    {
      "key": "layout",
      "type": "select",
      "label": "Layout",
      "options": [
        {"value": "image_left", "label": "Image Left"},
        {"value": "image_right", "label": "Image Right"}
      ],
      "default": "image_right"
    },
    {
      "key": "image_size",
      "type": "select",
      "label": "Image Size",
      "options": [
        {"value": "small", "label": "Small (1/3)"},
        {"value": "medium", "label": "Medium (1/2)"},
        {"value": "large", "label": "Large (2/3)"}
      ],
      "default": "medium"
    },
    {
      "key": "vertical_align",
      "type": "select",
      "label": "Vertical Alignment",
      "options": [
        {"value": "top", "label": "Top"},
        {"value": "center", "label": "Center"},
        {"value": "bottom", "label": "Bottom"}
      ],
      "default": "center"
    },
    {
      "key": "cta",
      "type": "group",
      "label": "Call to Action",
      "collapsible": true,
      "fields": [
        {"key": "show_cta", "type": "checkbox", "label": "Show Button"},
        {"key": "cta_text", "type": "text", "label": "Button Text", "condition": {"field": "cta.show_cta", "operator": "equals", "value": true}},
        {"key": "cta_link", "type": "link", "label": "Button Link", "condition": {"field": "cta.show_cta", "operator": "equals", "value": true}}
      ]
    },
    {
      "key": "background_color",
      "type": "color",
      "label": "Background Color",
      "default": "#ffffff"
    }
  ]
}
```

### `accordion`

**Category:** content
**Description:** Collapsible FAQ-style content sections

```json
{
  "fields": [
    {
      "key": "heading",
      "type": "text",
      "label": "Section Heading"
    },
    {
      "key": "subheading",
      "type": "textarea",
      "label": "Section Subheading",
      "rows": 2
    },
    {
      "key": "items",
      "type": "repeater",
      "label": "Accordion Items",
      "item_label": "Item",
      "min": 1,
      "max": 20,
      "fields": [
        {
          "key": "title",
          "type": "text",
          "label": "Title/Question",
          "required": true
        },
        {
          "key": "content",
          "type": "richtext",
          "label": "Content/Answer",
          "required": true
        },
        {
          "key": "is_open",
          "type": "checkbox",
          "label": "Open by Default"
        }
      ]
    },
    {
      "key": "allow_multiple",
      "type": "checkbox",
      "label": "Allow Multiple Open",
      "default": false,
      "description": "If unchecked, only one item can be open at a time"
    },
    {
      "key": "style",
      "type": "select",
      "label": "Accordion Style",
      "options": [
        {"value": "bordered", "label": "Bordered"},
        {"value": "minimal", "label": "Minimal"},
        {"value": "filled", "label": "Filled Background"}
      ],
      "default": "bordered"
    }
  ]
}
```

### `tabs`

**Category:** content
**Description:** Tabbed content sections

```json
{
  "fields": [
    {
      "key": "heading",
      "type": "text",
      "label": "Section Heading"
    },
    {
      "key": "tabs",
      "type": "repeater",
      "label": "Tabs",
      "item_label": "Tab",
      "min": 2,
      "max": 8,
      "fields": [
        {
          "key": "title",
          "type": "text",
          "label": "Tab Title",
          "required": true
        },
        {
          "key": "icon",
          "type": "icon",
          "label": "Tab Icon (optional)"
        },
        {
          "key": "content",
          "type": "richtext",
          "label": "Tab Content",
          "required": true
        }
      ]
    },
    {
      "key": "tab_position",
      "type": "select",
      "label": "Tab Position",
      "options": [
        {"value": "top", "label": "Top"},
        {"value": "left", "label": "Left Side"}
      ],
      "default": "top"
    },
    {
      "key": "tab_style",
      "type": "select",
      "label": "Tab Style",
      "options": [
        {"value": "default", "label": "Default"},
        {"value": "pills", "label": "Pills"},
        {"value": "underline", "label": "Underline"}
      ],
      "default": "default"
    }
  ]
}
```

---

## Feature Blocks

### `feature_grid`

**Category:** features
**Description:** Grid of icon + title + description items

```json
{
  "fields": [
    {
      "key": "heading",
      "type": "text",
      "label": "Section Heading"
    },
    {
      "key": "subheading",
      "type": "textarea",
      "label": "Section Subheading",
      "rows": 2
    },
    {
      "key": "columns",
      "type": "select",
      "label": "Columns",
      "options": [
        {"value": 2, "label": "2 Columns"},
        {"value": 3, "label": "3 Columns"},
        {"value": 4, "label": "4 Columns"}
      ],
      "default": 4
    },
    {
      "key": "items",
      "type": "repeater",
      "label": "Features",
      "item_label": "Feature",
      "min": 1,
      "max": 12,
      "fields": [
        {
          "key": "icon",
          "type": "icon",
          "label": "Icon",
          "icon_set": "boxicons"
        },
        {
          "key": "title",
          "type": "text",
          "label": "Title",
          "required": true
        },
        {
          "key": "description",
          "type": "textarea",
          "label": "Description",
          "rows": 3
        },
        {
          "key": "link",
          "type": "link",
          "label": "Link (optional)"
        }
      ]
    },
    {
      "key": "style",
      "type": "select",
      "label": "Display Style",
      "options": [
        {"value": "centered", "label": "Centered (icon above)"},
        {"value": "left", "label": "Left Aligned (icon left)"},
        {"value": "card", "label": "Cards with Shadow"}
      ],
      "default": "centered"
    },
    {
      "key": "icon_style",
      "type": "select",
      "label": "Icon Style",
      "options": [
        {"value": "plain", "label": "Plain Icon"},
        {"value": "circle", "label": "Circle Background"},
        {"value": "square", "label": "Square Background"}
      ],
      "default": "plain"
    },
    {
      "key": "icon_color",
      "type": "color",
      "label": "Icon Color",
      "default": "#007bff"
    },
    {
      "key": "background_color",
      "type": "color",
      "label": "Section Background",
      "default": "#ffffff"
    }
  ]
}
```

### `icon_list`

**Category:** features
**Description:** Vertical list with icons

```json
{
  "fields": [
    {
      "key": "heading",
      "type": "text",
      "label": "Section Heading"
    },
    {
      "key": "items",
      "type": "repeater",
      "label": "List Items",
      "item_label": "Item",
      "min": 1,
      "max": 20,
      "fields": [
        {
          "key": "icon",
          "type": "icon",
          "label": "Icon",
          "default": "bx-check"
        },
        {
          "key": "text",
          "type": "text",
          "label": "Text",
          "required": true
        },
        {
          "key": "link",
          "type": "link",
          "label": "Link (optional)"
        }
      ]
    },
    {
      "key": "layout",
      "type": "select",
      "label": "Layout",
      "options": [
        {"value": "single", "label": "Single Column"},
        {"value": "two-column", "label": "Two Columns"}
      ],
      "default": "single"
    },
    {
      "key": "icon_color",
      "type": "color",
      "label": "Icon Color",
      "default": "#28a745"
    }
  ]
}
```

### `stats_counter`

**Category:** features
**Description:** Animated number counters with labels

```json
{
  "fields": [
    {
      "key": "heading",
      "type": "text",
      "label": "Section Heading"
    },
    {
      "key": "stats",
      "type": "repeater",
      "label": "Statistics",
      "item_label": "Stat",
      "min": 2,
      "max": 6,
      "fields": [
        {
          "key": "number",
          "type": "number",
          "label": "Number",
          "required": true
        },
        {
          "key": "prefix",
          "type": "text",
          "label": "Prefix (e.g., $)",
          "maxlength": 5
        },
        {
          "key": "suffix",
          "type": "text",
          "label": "Suffix (e.g., +, %)",
          "maxlength": 5
        },
        {
          "key": "label",
          "type": "text",
          "label": "Label",
          "required": true
        },
        {
          "key": "icon",
          "type": "icon",
          "label": "Icon (optional)"
        }
      ]
    },
    {
      "key": "animate",
      "type": "checkbox",
      "label": "Animate Numbers on Scroll",
      "default": true
    },
    {
      "key": "background_type",
      "type": "select",
      "label": "Background",
      "options": [
        {"value": "light", "label": "Light"},
        {"value": "dark", "label": "Dark"},
        {"value": "primary", "label": "Primary Color"},
        {"value": "image", "label": "Background Image"}
      ],
      "default": "dark"
    },
    {
      "key": "background_image",
      "type": "image",
      "label": "Background Image",
      "condition": {"field": "background_type", "operator": "equals", "value": "image"}
    }
  ]
}
```

---

## Dynamic Blocks

### `recent_posts`

**Category:** dynamic
**Logic Function:** `recent_posts_logic`
**Description:** Display recent blog posts

```json
{
  "fields": [
    {
      "key": "heading",
      "type": "text",
      "label": "Section Heading",
      "default": "Recent Posts"
    },
    {
      "key": "subheading",
      "type": "textarea",
      "label": "Section Subheading",
      "rows": 2
    },
    {
      "key": "count",
      "type": "number",
      "label": "Number of Posts",
      "min": 1,
      "max": 12,
      "default": 3
    },
    {
      "key": "columns",
      "type": "select",
      "label": "Columns",
      "options": [
        {"value": 2, "label": "2 Columns"},
        {"value": 3, "label": "3 Columns"},
        {"value": 4, "label": "4 Columns"}
      ],
      "default": 3
    },
    {
      "key": "layout",
      "type": "select",
      "label": "Layout Style",
      "options": [
        {"value": "grid", "label": "Grid"},
        {"value": "list", "label": "List"},
        {"value": "featured", "label": "Featured (1 large + smaller)"}
      ],
      "default": "grid"
    },
    {
      "key": "show_image",
      "type": "checkbox",
      "label": "Show Featured Image",
      "default": true
    },
    {
      "key": "show_excerpt",
      "type": "checkbox",
      "label": "Show Excerpt",
      "default": true
    },
    {
      "key": "excerpt_length",
      "type": "number",
      "label": "Excerpt Length (characters)",
      "min": 50,
      "max": 300,
      "default": 120,
      "condition": {"field": "show_excerpt", "operator": "equals", "value": true}
    },
    {
      "key": "show_author",
      "type": "checkbox",
      "label": "Show Author",
      "default": true
    },
    {
      "key": "show_date",
      "type": "checkbox",
      "label": "Show Date",
      "default": true
    },
    {
      "key": "show_categories",
      "type": "checkbox",
      "label": "Show Categories",
      "default": false
    },
    {
      "key": "show_view_all",
      "type": "checkbox",
      "label": "Show 'View All' Link",
      "default": true
    },
    {
      "key": "view_all_text",
      "type": "text",
      "label": "View All Button Text",
      "default": "View All Posts",
      "condition": {"field": "show_view_all", "operator": "equals", "value": true}
    }
  ]
}
```

### `upcoming_events`

**Category:** dynamic
**Logic Function:** `upcoming_events_logic`
**Requires Plugin:** events
**Description:** Display upcoming events

```json
{
  "fields": [
    {
      "key": "heading",
      "type": "text",
      "label": "Section Heading",
      "default": "Upcoming Events"
    },
    {
      "key": "count",
      "type": "number",
      "label": "Number of Events",
      "min": 1,
      "max": 12,
      "default": 4
    },
    {
      "key": "layout",
      "type": "select",
      "label": "Layout",
      "options": [
        {"value": "list", "label": "List"},
        {"value": "grid", "label": "Grid"},
        {"value": "calendar", "label": "Mini Calendar"}
      ],
      "default": "list"
    },
    {
      "key": "show_image",
      "type": "checkbox",
      "label": "Show Event Image",
      "default": true
    },
    {
      "key": "show_time",
      "type": "checkbox",
      "label": "Show Event Time",
      "default": true
    },
    {
      "key": "show_location",
      "type": "checkbox",
      "label": "Show Location",
      "default": true
    },
    {
      "key": "show_description",
      "type": "checkbox",
      "label": "Show Description",
      "default": false
    },
    {
      "key": "event_type",
      "type": "select",
      "label": "Event Type Filter",
      "options": [
        {"value": "all", "label": "All Types"},
        {"value": "public", "label": "Public Only"},
        {"value": "members", "label": "Members Only"}
      ],
      "default": "all"
    },
    {
      "key": "show_view_all",
      "type": "checkbox",
      "label": "Show 'View All' Link",
      "default": true
    }
  ]
}
```

### `product_grid`

**Category:** dynamic
**Logic Function:** `product_grid_logic`
**Requires Plugin:** products
**Description:** Display products

```json
{
  "fields": [
    {
      "key": "heading",
      "type": "text",
      "label": "Section Heading",
      "default": "Featured Products"
    },
    {
      "key": "count",
      "type": "number",
      "label": "Number of Products",
      "min": 1,
      "max": 12,
      "default": 4
    },
    {
      "key": "columns",
      "type": "select",
      "label": "Columns",
      "options": [
        {"value": 2, "label": "2 Columns"},
        {"value": 3, "label": "3 Columns"},
        {"value": 4, "label": "4 Columns"}
      ],
      "default": 4
    },
    {
      "key": "filter",
      "type": "select",
      "label": "Products to Show",
      "options": [
        {"value": "featured", "label": "Featured Products"},
        {"value": "newest", "label": "Newest Products"},
        {"value": "popular", "label": "Most Popular"},
        {"value": "category", "label": "Specific Category"}
      ],
      "default": "featured"
    },
    {
      "key": "category_id",
      "type": "number",
      "label": "Category ID",
      "condition": {"field": "filter", "operator": "equals", "value": "category"}
    },
    {
      "key": "show_price",
      "type": "checkbox",
      "label": "Show Price",
      "default": true
    },
    {
      "key": "show_add_to_cart",
      "type": "checkbox",
      "label": "Show Add to Cart Button",
      "default": true
    }
  ]
}
```

---

## Testimonial Blocks

### `testimonial_slider`

**Category:** testimonials
**Description:** Rotating testimonial quotes

```json
{
  "fields": [
    {
      "key": "heading",
      "type": "text",
      "label": "Section Heading",
      "default": "What Our Customers Say"
    },
    {
      "key": "testimonials",
      "type": "repeater",
      "label": "Testimonials",
      "item_label": "Testimonial",
      "min": 1,
      "max": 10,
      "fields": [
        {
          "key": "quote",
          "type": "textarea",
          "label": "Quote",
          "required": true,
          "rows": 4
        },
        {
          "key": "author_name",
          "type": "text",
          "label": "Author Name",
          "required": true
        },
        {
          "key": "author_title",
          "type": "text",
          "label": "Author Title/Company"
        },
        {
          "key": "author_image",
          "type": "image",
          "label": "Author Photo"
        },
        {
          "key": "rating",
          "type": "number",
          "label": "Star Rating (1-5)",
          "min": 1,
          "max": 5
        }
      ]
    },
    {
      "key": "autoplay",
      "type": "checkbox",
      "label": "Auto-rotate",
      "default": true
    },
    {
      "key": "interval",
      "type": "number",
      "label": "Rotation Interval (seconds)",
      "min": 3,
      "max": 15,
      "default": 6
    },
    {
      "key": "show_rating",
      "type": "checkbox",
      "label": "Show Star Rating",
      "default": true
    },
    {
      "key": "style",
      "type": "select",
      "label": "Display Style",
      "options": [
        {"value": "centered", "label": "Centered"},
        {"value": "card", "label": "Card Style"},
        {"value": "minimal", "label": "Minimal"}
      ],
      "default": "centered"
    },
    {
      "key": "background_color",
      "type": "color",
      "label": "Background Color",
      "default": "#f8f9fa"
    }
  ]
}
```

### `logo_wall`

**Category:** testimonials
**Description:** Grid of client/partner logos

```json
{
  "fields": [
    {
      "key": "heading",
      "type": "text",
      "label": "Section Heading",
      "default": "Trusted By"
    },
    {
      "key": "logos",
      "type": "repeater",
      "label": "Logos",
      "item_label": "Logo",
      "min": 2,
      "max": 20,
      "fields": [
        {
          "key": "image",
          "type": "image",
          "label": "Logo Image",
          "required": true
        },
        {
          "key": "name",
          "type": "text",
          "label": "Company Name (alt text)"
        },
        {
          "key": "link",
          "type": "link",
          "label": "Link (optional)"
        }
      ]
    },
    {
      "key": "columns",
      "type": "select",
      "label": "Logos Per Row",
      "options": [
        {"value": 4, "label": "4"},
        {"value": 5, "label": "5"},
        {"value": 6, "label": "6"}
      ],
      "default": 5
    },
    {
      "key": "grayscale",
      "type": "checkbox",
      "label": "Grayscale Logos (color on hover)",
      "default": true
    },
    {
      "key": "animate",
      "type": "checkbox",
      "label": "Scrolling Animation",
      "default": false
    }
  ]
}
```

---

## Conversion Blocks

### `cta_banner`

**Category:** conversion
**Description:** Full-width call-to-action banner

```json
{
  "fields": [
    {
      "key": "heading",
      "type": "text",
      "label": "Heading",
      "required": true
    },
    {
      "key": "subheading",
      "type": "textarea",
      "label": "Supporting Text",
      "rows": 2
    },
    {
      "key": "cta_text",
      "type": "text",
      "label": "Button Text",
      "required": true,
      "default": "Get Started"
    },
    {
      "key": "cta_link",
      "type": "link",
      "label": "Button Link",
      "required": true
    },
    {
      "key": "secondary_cta",
      "type": "group",
      "label": "Secondary Button",
      "collapsible": true,
      "fields": [
        {"key": "show", "type": "checkbox", "label": "Show Secondary Button"},
        {"key": "text", "type": "text", "label": "Button Text", "condition": {"field": "secondary_cta.show", "operator": "equals", "value": true}},
        {"key": "link", "type": "link", "label": "Button Link", "condition": {"field": "secondary_cta.show", "operator": "equals", "value": true}}
      ]
    },
    {
      "key": "background_type",
      "type": "select",
      "label": "Background",
      "options": [
        {"value": "color", "label": "Solid Color"},
        {"value": "gradient", "label": "Gradient"},
        {"value": "image", "label": "Image"}
      ],
      "default": "gradient"
    },
    {
      "key": "background_color",
      "type": "color",
      "label": "Background Color",
      "default": "#007bff",
      "condition": {"field": "background_type", "operator": "equals", "value": "color"}
    },
    {
      "key": "gradient_start",
      "type": "color",
      "label": "Gradient Start",
      "default": "#667eea",
      "condition": {"field": "background_type", "operator": "equals", "value": "gradient"}
    },
    {
      "key": "gradient_end",
      "type": "color",
      "label": "Gradient End",
      "default": "#764ba2",
      "condition": {"field": "background_type", "operator": "equals", "value": "gradient"}
    },
    {
      "key": "background_image",
      "type": "image",
      "label": "Background Image",
      "condition": {"field": "background_type", "operator": "equals", "value": "image"}
    },
    {
      "key": "text_color",
      "type": "color",
      "label": "Text Color",
      "default": "#ffffff"
    }
  ]
}
```

### `newsletter_signup`

**Category:** conversion
**Description:** Email newsletter signup form

```json
{
  "fields": [
    {
      "key": "heading",
      "type": "text",
      "label": "Heading",
      "default": "Subscribe to Our Newsletter"
    },
    {
      "key": "subheading",
      "type": "textarea",
      "label": "Description",
      "rows": 2,
      "default": "Get the latest updates delivered to your inbox."
    },
    {
      "key": "placeholder",
      "type": "text",
      "label": "Email Placeholder",
      "default": "Enter your email address"
    },
    {
      "key": "button_text",
      "type": "text",
      "label": "Button Text",
      "default": "Subscribe"
    },
    {
      "key": "success_message",
      "type": "text",
      "label": "Success Message",
      "default": "Thanks for subscribing!"
    },
    {
      "key": "collect_name",
      "type": "checkbox",
      "label": "Also Collect Name",
      "default": false
    },
    {
      "key": "privacy_text",
      "type": "text",
      "label": "Privacy Notice",
      "default": "We respect your privacy. Unsubscribe at any time."
    },
    {
      "key": "layout",
      "type": "select",
      "label": "Layout",
      "options": [
        {"value": "inline", "label": "Inline (single row)"},
        {"value": "stacked", "label": "Stacked"}
      ],
      "default": "inline"
    },
    {
      "key": "background_color",
      "type": "color",
      "label": "Background Color",
      "default": "#f8f9fa"
    }
  ]
}
```

### `pricing_table`

**Category:** conversion
**Description:** Pricing plan comparison

```json
{
  "fields": [
    {
      "key": "heading",
      "type": "text",
      "label": "Section Heading",
      "default": "Pricing Plans"
    },
    {
      "key": "subheading",
      "type": "textarea",
      "label": "Section Subheading",
      "rows": 2
    },
    {
      "key": "plans",
      "type": "repeater",
      "label": "Plans",
      "item_label": "Plan",
      "min": 1,
      "max": 4,
      "fields": [
        {
          "key": "name",
          "type": "text",
          "label": "Plan Name",
          "required": true
        },
        {
          "key": "price",
          "type": "text",
          "label": "Price",
          "required": true,
          "placeholder": "$29"
        },
        {
          "key": "period",
          "type": "text",
          "label": "Period",
          "default": "/month"
        },
        {
          "key": "description",
          "type": "text",
          "label": "Short Description"
        },
        {
          "key": "features",
          "type": "textarea",
          "label": "Features (one per line)",
          "rows": 6,
          "required": true
        },
        {
          "key": "cta_text",
          "type": "text",
          "label": "Button Text",
          "default": "Choose Plan"
        },
        {
          "key": "cta_link",
          "type": "link",
          "label": "Button Link"
        },
        {
          "key": "is_featured",
          "type": "checkbox",
          "label": "Featured/Recommended"
        },
        {
          "key": "badge_text",
          "type": "text",
          "label": "Badge Text (e.g., 'Most Popular')",
          "condition": {"field": "is_featured", "operator": "equals", "value": true}
        }
      ]
    },
    {
      "key": "show_annual_toggle",
      "type": "checkbox",
      "label": "Show Monthly/Annual Toggle",
      "default": false
    }
  ]
}
```

---

## Layout Blocks

### `spacer`

**Category:** layout
**Description:** Vertical spacing

```json
{
  "fields": [
    {
      "key": "height",
      "type": "select",
      "label": "Spacer Height",
      "options": [
        {"value": "small", "label": "Small (20px)"},
        {"value": "medium", "label": "Medium (40px)"},
        {"value": "large", "label": "Large (80px)"},
        {"value": "xlarge", "label": "Extra Large (120px)"}
      ],
      "default": "medium"
    },
    {
      "key": "mobile_height",
      "type": "select",
      "label": "Mobile Height",
      "options": [
        {"value": "same", "label": "Same as Desktop"},
        {"value": "half", "label": "Half"},
        {"value": "none", "label": "None"}
      ],
      "default": "half"
    }
  ]
}
```

### `divider`

**Category:** layout
**Description:** Horizontal divider line

```json
{
  "fields": [
    {
      "key": "style",
      "type": "select",
      "label": "Divider Style",
      "options": [
        {"value": "solid", "label": "Solid Line"},
        {"value": "dashed", "label": "Dashed Line"},
        {"value": "dotted", "label": "Dotted Line"},
        {"value": "gradient", "label": "Gradient"}
      ],
      "default": "solid"
    },
    {
      "key": "width",
      "type": "select",
      "label": "Width",
      "options": [
        {"value": "full", "label": "Full Width"},
        {"value": "medium", "label": "Medium (50%)"},
        {"value": "short", "label": "Short (20%)"}
      ],
      "default": "full"
    },
    {
      "key": "color",
      "type": "color",
      "label": "Line Color",
      "default": "#dee2e6"
    },
    {
      "key": "icon",
      "type": "icon",
      "label": "Center Icon (optional)"
    },
    {
      "key": "margin",
      "type": "select",
      "label": "Vertical Margin",
      "options": [
        {"value": "small", "label": "Small"},
        {"value": "medium", "label": "Medium"},
        {"value": "large", "label": "Large"}
      ],
      "default": "medium"
    }
  ]
}
```

---

## Custom Blocks

### `custom_html`

**Category:** custom
**Description:** Raw HTML for advanced users

```json
{
  "fields": [
    {
      "key": "html",
      "type": "textarea",
      "label": "HTML Code",
      "required": true,
      "rows": 15,
      "description": "Enter custom HTML. Be careful with scripts and styles."
    },
    {
      "key": "container",
      "type": "checkbox",
      "label": "Wrap in Container",
      "default": true,
      "description": "Wraps content in a standard container for consistent width"
    },
    {
      "key": "admin_note",
      "type": "textarea",
      "label": "Admin Notes",
      "rows": 3,
      "description": "Notes for administrators (not displayed on site)"
    }
  ]
}
```

### `embed`

**Category:** custom
**Description:** Embed external content (iframe)

```json
{
  "fields": [
    {
      "key": "embed_type",
      "type": "select",
      "label": "Embed Type",
      "options": [
        {"value": "iframe", "label": "iFrame URL"},
        {"value": "code", "label": "Embed Code"}
      ],
      "default": "iframe"
    },
    {
      "key": "url",
      "type": "text",
      "label": "URL to Embed",
      "placeholder": "https://...",
      "condition": {"field": "embed_type", "operator": "equals", "value": "iframe"}
    },
    {
      "key": "code",
      "type": "textarea",
      "label": "Embed Code",
      "rows": 8,
      "condition": {"field": "embed_type", "operator": "equals", "value": "code"}
    },
    {
      "key": "height",
      "type": "number",
      "label": "Height (pixels)",
      "default": 400
    },
    {
      "key": "responsive",
      "type": "checkbox",
      "label": "Responsive (maintain aspect ratio)",
      "default": true
    },
    {
      "key": "aspect_ratio",
      "type": "select",
      "label": "Aspect Ratio",
      "options": [
        {"value": "16:9", "label": "16:9 (Widescreen)"},
        {"value": "4:3", "label": "4:3 (Standard)"},
        {"value": "1:1", "label": "1:1 (Square)"},
        {"value": "21:9", "label": "21:9 (Ultrawide)"}
      ],
      "default": "16:9",
      "condition": {"field": "responsive", "operator": "equals", "value": true}
    }
  ]
}
```

---

## Media Blocks

### `image_gallery`

**Category:** media
**Description:** Grid of images with lightbox

```json
{
  "fields": [
    {
      "key": "heading",
      "type": "text",
      "label": "Section Heading"
    },
    {
      "key": "images",
      "type": "repeater",
      "label": "Images",
      "item_label": "Image",
      "min": 1,
      "max": 30,
      "fields": [
        {
          "key": "image",
          "type": "image",
          "label": "Image",
          "required": true
        },
        {
          "key": "caption",
          "type": "text",
          "label": "Caption"
        },
        {
          "key": "link",
          "type": "link",
          "label": "Link (overrides lightbox)"
        }
      ]
    },
    {
      "key": "columns",
      "type": "select",
      "label": "Columns",
      "options": [
        {"value": 2, "label": "2 Columns"},
        {"value": 3, "label": "3 Columns"},
        {"value": 4, "label": "4 Columns"},
        {"value": 5, "label": "5 Columns"}
      ],
      "default": 4
    },
    {
      "key": "gap",
      "type": "select",
      "label": "Gap Between Images",
      "options": [
        {"value": "none", "label": "None"},
        {"value": "small", "label": "Small"},
        {"value": "medium", "label": "Medium"}
      ],
      "default": "small"
    },
    {
      "key": "lightbox",
      "type": "checkbox",
      "label": "Enable Lightbox",
      "default": true
    },
    {
      "key": "show_captions",
      "type": "checkbox",
      "label": "Show Captions",
      "default": false
    }
  ]
}
```

### `video_embed`

**Category:** media
**Description:** YouTube or Vimeo video embed

```json
{
  "fields": [
    {
      "key": "heading",
      "type": "text",
      "label": "Section Heading"
    },
    {
      "key": "video_url",
      "type": "text",
      "label": "Video URL",
      "required": true,
      "placeholder": "https://www.youtube.com/watch?v=... or https://vimeo.com/..."
    },
    {
      "key": "aspect_ratio",
      "type": "select",
      "label": "Aspect Ratio",
      "options": [
        {"value": "16:9", "label": "16:9 (Standard)"},
        {"value": "4:3", "label": "4:3"},
        {"value": "21:9", "label": "21:9 (Cinematic)"}
      ],
      "default": "16:9"
    },
    {
      "key": "max_width",
      "type": "select",
      "label": "Maximum Width",
      "options": [
        {"value": "small", "label": "Small (600px)"},
        {"value": "medium", "label": "Medium (800px)"},
        {"value": "large", "label": "Large (1000px)"},
        {"value": "full", "label": "Full Width"}
      ],
      "default": "large"
    },
    {
      "key": "autoplay",
      "type": "checkbox",
      "label": "Autoplay (muted)",
      "default": false
    },
    {
      "key": "show_controls",
      "type": "checkbox",
      "label": "Show Controls",
      "default": true
    }
  ]
}
```

---

## Team & Contact Blocks

### `team_grid`

**Category:** content
**Description:** Team member showcase

```json
{
  "fields": [
    {
      "key": "heading",
      "type": "text",
      "label": "Section Heading",
      "default": "Meet Our Team"
    },
    {
      "key": "subheading",
      "type": "textarea",
      "label": "Section Subheading",
      "rows": 2
    },
    {
      "key": "members",
      "type": "repeater",
      "label": "Team Members",
      "item_label": "Member",
      "min": 1,
      "max": 12,
      "fields": [
        {
          "key": "photo",
          "type": "image",
          "label": "Photo"
        },
        {
          "key": "name",
          "type": "text",
          "label": "Name",
          "required": true
        },
        {
          "key": "title",
          "type": "text",
          "label": "Job Title"
        },
        {
          "key": "bio",
          "type": "textarea",
          "label": "Short Bio",
          "rows": 3
        },
        {
          "key": "social",
          "type": "group",
          "label": "Social Links",
          "fields": [
            {"key": "linkedin", "type": "text", "label": "LinkedIn URL"},
            {"key": "twitter", "type": "text", "label": "Twitter URL"},
            {"key": "email", "type": "text", "label": "Email Address"}
          ]
        }
      ]
    },
    {
      "key": "columns",
      "type": "select",
      "label": "Columns",
      "options": [
        {"value": 2, "label": "2 Columns"},
        {"value": 3, "label": "3 Columns"},
        {"value": 4, "label": "4 Columns"}
      ],
      "default": 4
    },
    {
      "key": "style",
      "type": "select",
      "label": "Card Style",
      "options": [
        {"value": "card", "label": "Card with Shadow"},
        {"value": "minimal", "label": "Minimal"},
        {"value": "overlay", "label": "Image Overlay"}
      ],
      "default": "card"
    }
  ]
}
```

### `contact_info`

**Category:** content
**Description:** Contact information display

```json
{
  "fields": [
    {
      "key": "heading",
      "type": "text",
      "label": "Section Heading",
      "default": "Contact Us"
    },
    {
      "key": "layout",
      "type": "select",
      "label": "Layout",
      "options": [
        {"value": "horizontal", "label": "Horizontal (side by side)"},
        {"value": "vertical", "label": "Vertical (stacked)"}
      ],
      "default": "horizontal"
    },
    {
      "key": "items",
      "type": "repeater",
      "label": "Contact Items",
      "item_label": "Item",
      "min": 1,
      "max": 6,
      "fields": [
        {
          "key": "icon",
          "type": "icon",
          "label": "Icon",
          "default": "bx-envelope"
        },
        {
          "key": "label",
          "type": "text",
          "label": "Label",
          "placeholder": "Email"
        },
        {
          "key": "value",
          "type": "text",
          "label": "Value",
          "required": true,
          "placeholder": "contact@example.com"
        },
        {
          "key": "link",
          "type": "text",
          "label": "Link (optional)",
          "placeholder": "mailto:contact@example.com"
        }
      ]
    },
    {
      "key": "background_color",
      "type": "color",
      "label": "Background Color",
      "default": "#f8f9fa"
    }
  ]
}
```

### `map_embed`

**Category:** media
**Description:** Google Maps embed

```json
{
  "fields": [
    {
      "key": "heading",
      "type": "text",
      "label": "Section Heading"
    },
    {
      "key": "embed_url",
      "type": "textarea",
      "label": "Google Maps Embed URL",
      "required": true,
      "rows": 3,
      "description": "Go to Google Maps, click Share, select Embed, and paste the src URL here"
    },
    {
      "key": "height",
      "type": "number",
      "label": "Map Height (pixels)",
      "min": 200,
      "max": 800,
      "default": 400
    },
    {
      "key": "full_width",
      "type": "checkbox",
      "label": "Full Width (edge to edge)",
      "default": false
    }
  ]
}
```
