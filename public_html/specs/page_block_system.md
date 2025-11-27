# Page Block System Specification

## Overview

A flexible, database-driven block system that allows administrators to compose pages from a library of pre-built, configurable blocks. This system **extends the existing Component and PageContent tables** rather than creating new ones, maintaining backward compatibility while adding powerful new capabilities.

## Goals

1. **Reduce custom development** - Clients can build unique pages without code changes
2. **Maintain theme compatibility** - Block templates are theme-overridable
3. **Form-based editing** - No WYSIWYG; structured forms like existing admin pages
4. **Developer extensibility** - Easy to add new block types
5. **Performance** - Efficient rendering with minimal database queries
6. **Backward compatibility** - Existing PageContent placeholder system continues to work
7. **Build on existing tables** - Extend `com_components` and `pac_page_contents`

## Non-Goals

1. WYSIWYG or drag-and-drop visual editing in a live preview
2. Replacing the existing Page/PageContent placeholder system (this complements it)
3. Full CMS functionality (this is specifically for block-based layouts)

---

## Data Architecture

### Extending `com_components` (Block Type Library)

The existing `com_components` table will store block type definitions. Current structure is minimal and empty, so we add fields for the block system.

**New fields to add:**

```sql
-- Block type metadata
com_type_key VARCHAR(64) UNIQUE      -- 'hero_slider', 'feature_grid', etc.
com_description TEXT                  -- 'Full-width image slider with text overlays'
com_category VARCHAR(64)              -- 'hero', 'content', 'media', 'dynamic', 'conversion'
com_icon VARCHAR(64)                  -- 'bx-image' for admin UI

-- Configuration
com_config_schema JSON                -- Field definitions for the config form
com_default_config JSON               -- Default values for new instances
com_template_file VARCHAR(255)        -- 'blocks/hero_slider.php' (replaces com_script_filename usage)
com_logic_function VARCHAR(255)       -- 'hero_slider_block_logic' if dynamic data needed

-- Status and dependencies
com_is_active BOOLEAN DEFAULT TRUE    -- Can be disabled system-wide
com_requires_plugin VARCHAR(64)       -- Plugin dependency, e.g., 'events'
com_css_framework VARCHAR(32)         -- 'bootstrap', 'tailwind', or NULL for any
com_requires_theme VARCHAR(64)        -- Specific theme required, or NULL for any
```

**Updated field_specifications for Component class:**

```php
public static $field_specifications = array(
    'com_component_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
    'com_type_key' => array('type'=>'varchar(64)', 'unique'=>true),
    'com_title' => array('type'=>'varchar(255)', 'required'=>true),
    'com_description' => array('type'=>'text'),
    'com_category' => array('type'=>'varchar(64)'),
    'com_icon' => array('type'=>'varchar(64)'),
    'com_config_schema' => array('type'=>'json'),
    'com_default_config' => array('type'=>'json'),
    'com_template_file' => array('type'=>'varchar(255)'),
    'com_logic_function' => array('type'=>'varchar(255)'),
    'com_is_active' => array('type'=>'bool', 'default'=>true),
    'com_requires_plugin' => array('type'=>'varchar(64)'),
    'com_css_framework' => array('type'=>'varchar(32)'),  // 'bootstrap', 'tailwind', or NULL
    'com_requires_theme' => array('type'=>'varchar(64)'), // Specific theme, or NULL for any
    'com_order' => array('type'=>'int2'),
    'com_published_time' => array('type'=>'timestamp(6)'),
    'com_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
    'com_script_filename' => array('type'=>'varchar(255)'), // Legacy, keep for compatibility
    'com_delete_time' => array('type'=>'timestamp(6)'),
);

public static $json_vars = array('com_config_schema', 'com_default_config');
```

### Extending `pac_page_contents` (Block Instances)

The existing `pac_page_contents` table will store block instances. We add fields for block configuration and visibility while preserving backward compatibility with the placeholder system.

**New fields to add:**

```sql
-- Page context (for non-Page targets like homepage)
pac_page_context VARCHAR(64)          -- 'homepage', 'landing:sale', etc. (NULL = use pac_pag_page_id)

-- Block configuration
pac_config JSON                       -- Instance-specific configuration (extends pac_body for blocks)

-- Visibility controls
pac_visibility VARCHAR(32) DEFAULT 'all'  -- 'all', 'logged_in', 'logged_out', 'members_only'
pac_start_time TIMESTAMP              -- Optional: show after this time
pac_end_time TIMESTAMP                -- Optional: hide after this time
pac_theme_override VARCHAR(64)        -- Optional: only show for specific theme

-- Tracking
pac_update_time TIMESTAMP             -- Last modification time
```

**Updated field_specifications for PageContent class:**

```php
public static $field_specifications = array(
    'pac_page_content_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
    'pac_pag_page_id' => array('type'=>'int4'),
    'pac_page_context' => array('type'=>'varchar(64)'),  // NEW: for homepage, landing pages
    'pac_com_component_id' => array('type'=>'int4'),
    'pac_location_name' => array('type'=>'varchar(255)', 'required'=>true),
    'pac_title' => array('type'=>'varchar(255)'),
    'pac_link' => array('type'=>'varchar(255)'),  // Keep for placeholder system
    'pac_usr_user_id' => array('type'=>'int4'),
    'pac_body' => array('type'=>'text'),  // Keep for placeholder system / legacy
    'pac_config' => array('type'=>'json'),  // NEW: block configuration
    'pac_order' => array('type'=>'int2'),  // NEW: explicit ordering
    'pac_is_published' => array('type'=>'bool', 'default'=>false),
    'pac_published_time' => array('type'=>'timestamp(6)'),
    'pac_visibility' => array('type'=>'varchar(32)', 'default'=>'all'),  // NEW
    'pac_start_time' => array('type'=>'timestamp(6)'),  // NEW
    'pac_end_time' => array('type'=>'timestamp(6)'),  // NEW
    'pac_theme_override' => array('type'=>'varchar(64)'),  // NEW
    'pac_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
    'pac_update_time' => array('type'=>'timestamp(6)'),  // NEW
    'pac_script_filename' => array('type'=>'varchar(255)'),  // Legacy
    'pac_delete_time' => array('type'=>'timestamp(6)'),
);

public static $json_vars = array('pac_config');
```

### Index Requirements

```sql
-- Fast lookup of blocks for a page context (homepage, landing pages)
CREATE INDEX idx_pac_page_context ON pac_page_contents
    (pac_page_context, pac_is_published, pac_order)
    WHERE pac_delete_time IS NULL AND pac_page_context IS NOT NULL;

-- Fast lookup of blocks for a specific page (existing behavior)
CREATE INDEX idx_pac_page_id ON pac_page_contents
    (pac_pag_page_id, pac_is_published, pac_order)
    WHERE pac_delete_time IS NULL AND pac_pag_page_id IS NOT NULL;

-- Block type lookup
CREATE INDEX idx_com_type_key ON com_components (com_type_key)
    WHERE com_delete_time IS NULL;
```

---

## Backward Compatibility

### Existing Placeholder System

The current system where `*!**slug**!*` placeholders in page body are replaced with PageContent body **continues to work unchanged**. This is handled by `Page::get_filled_content()` which:
1. Loads PageContents linked to the page via `pac_pag_page_id`
2. Replaces `*!**pac_link**!*` with `pac_body`

**This system is preserved** - blocks with `pac_link` set and no `pac_com_component_id` use the old behavior.

### Block System (New)

PageContents with `pac_com_component_id` set are treated as blocks:
1. They render via the Component's template file
2. Configuration comes from `pac_config` (merged with Component's `com_default_config`)
3. They can be placed on pages via `pac_pag_page_id` OR on special contexts via `pac_page_context`

### Detection Logic

```php
// In PageContent class
public function is_block() {
    return !empty($this->get('pac_com_component_id'));
}

// In rendering logic
if ($page_content->is_block()) {
    // Use BlockRenderer
    echo BlockRenderer::render_block($page_content);
} else {
    // Use legacy placeholder system
    echo $page_content->get_content();
}
```

---

## Page Context System

The `pac_page_context` field allows blocks to be associated with pages that don't have Page records:

| Context Value | Description | Example |
|--------------|-------------|---------|
| `homepage` | Site homepage | Main landing |
| `landing:{slug}` | Custom landing pages | `landing:summer-sale` |
| `footer` | Footer blocks (global) | Site-wide footer content |
| `sidebar:{name}` | Named sidebar regions | `sidebar:blog` |
| `NULL` | Use `pac_pag_page_id` | Traditional page link |

**Lookup priority:**
1. If `pac_page_context` is set, use it
2. Otherwise, use `pac_pag_page_id` for page-specific content

---

## Config Schema Specification

The `com_config_schema` JSON defines what fields appear in the admin form for a block instance.

### Field Types

| Type | Description | Additional Properties |
|------|-------------|----------------------|
| `text` | Single-line text input | `maxlength`, `placeholder` |
| `textarea` | Multi-line text | `rows`, `maxlength` |
| `richtext` | HTML editor (existing) | `toolbar` options |
| `number` | Numeric input | `min`, `max`, `step` |
| `select` | Dropdown | `options` array |
| `checkbox` | Boolean toggle | - |
| `image` | Image upload/URL | `allowed_types`, `max_size` |
| `link` | URL with optional text | `show_text`, `show_target` |
| `icon` | Icon picker | `icon_set` (boxicons, fontawesome) |
| `color` | Color picker | `format` (hex, rgb) |
| `repeater` | Repeatable group of fields | `fields`, `min`, `max`, `item_label` |
| `group` | Non-repeating field group | `fields` |

### Schema Example

```json
{
  "fields": [
    {
      "key": "heading",
      "type": "text",
      "label": "Section Heading",
      "required": true,
      "placeholder": "Enter heading text"
    },
    {
      "key": "layout",
      "type": "select",
      "label": "Layout Style",
      "options": [
        {"value": "centered", "label": "Centered"},
        {"value": "left", "label": "Left Aligned"}
      ],
      "default": "centered"
    },
    {
      "key": "items",
      "type": "repeater",
      "label": "Feature Items",
      "item_label": "Feature",
      "min": 1,
      "max": 8,
      "fields": [
        {
          "key": "icon",
          "type": "icon",
          "label": "Icon"
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
        }
      ]
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
          "label": "Show CTA Button"
        },
        {
          "key": "cta_text",
          "type": "text",
          "label": "Button Text",
          "condition": {"field": "show_cta", "value": true}
        }
      ]
    }
  ]
}
```

### Conditional Fields

Fields can be conditionally shown/hidden based on other field values:

```json
{
  "key": "video_url",
  "type": "text",
  "label": "Video URL",
  "condition": {
    "field": "media_type",
    "operator": "equals",
    "value": "video"
  }
}
```

Supported operators: `equals`, `not_equals`, `in`, `not_in`, `empty`, `not_empty`

---

## Block Categories

Blocks are organized into categories for easier discovery:

| Category | Description | Examples |
|----------|-------------|----------|
| `hero` | Full-width top sections | Hero Slider, Hero Static, Video Hero |
| `content` | Text and mixed content | Text Block, Text + Image, Accordion, Tabs |
| `features` | Feature/benefit displays | Feature Grid, Icon List, Comparison Table |
| `media` | Image and video focused | Image Gallery, Video Embed, Carousel |
| `testimonials` | Social proof | Testimonial Slider, Review Grid, Logo Wall |
| `dynamic` | Database-driven content | Recent Posts, Upcoming Events, Product Grid |
| `conversion` | CTAs and lead capture | CTA Banner, Newsletter Signup, Pricing Table |
| `layout` | Structural elements | Spacer, Divider, Container |
| `custom` | Freeform | Custom HTML, Embed Code |

---

## Initial Block Library

### Phase 1 Blocks (MVP)

| Block Type Key | Category | Description |
|----------------|----------|-------------|
| `hero_static` | hero | Single hero with heading, text, image, CTA |
| `hero_slider` | hero | Multiple slides with auto-rotation |
| `text_block` | content | Heading + rich text content |
| `text_with_image` | content | Text alongside image (left or right) |
| `feature_grid` | features | Grid of icon + title + description items |
| `recent_posts` | dynamic | Grid/list of recent blog posts |
| `upcoming_events` | dynamic | List of upcoming events |
| `cta_banner` | conversion | Full-width call to action |
| `custom_html` | custom | Raw HTML for advanced users |
| `spacer` | layout | Vertical spacing |

### Phase 2 Blocks

| Block Type Key | Category | Description |
|----------------|----------|-------------|
| `testimonial_slider` | testimonials | Rotating testimonials |
| `testimonial_grid` | testimonials | Grid of testimonial cards |
| `image_gallery` | media | Grid of images with lightbox |
| `video_embed` | media | YouTube/Vimeo embed |
| `accordion` | content | Collapsible FAQ-style content |
| `tabs` | content | Tabbed content sections |
| `icon_list` | features | Vertical list with icons |
| `stats_counter` | features | Animated number counters |
| `logo_wall` | testimonials | Grid of partner/client logos |
| `newsletter_signup` | conversion | Email capture form |
| `pricing_table` | conversion | Pricing comparison |
| `team_grid` | content | Team member cards |
| `contact_info` | content | Address, phone, email display |
| `map_embed` | media | Google Maps embed |
| `divider` | layout | Horizontal line with optional icon |

### Phase 3 Blocks (Plugin-Dependent)

| Block Type Key | Requires Plugin | Description |
|----------------|-----------------|-------------|
| `product_grid` | products | Featured products |
| `product_categories` | products | Category showcase |
| `event_calendar` | events | Calendar view of events |
| `booking_cta` | bookings | Booking call to action |

---

## File Structure

```
/data/
├── components_class.php          # Extended with new fields
└── page_contents_class.php       # Extended with new fields

/includes/
├── BlockRenderer.php             # NEW: Renders blocks with data
├── BlockConfigForm.php           # NEW: Generates admin forms from schema
└── BlockLogicLoader.php          # NEW: Loads dynamic data for blocks

/logic/blocks/                    # NEW: Block-specific logic functions
├── recent_posts_logic.php
├── upcoming_events_logic.php
└── product_grid_logic.php

/views/blocks/                    # NEW: Block templates
├── hero_static.php
├── hero_slider.php
├── text_block.php
├── text_with_image.php
├── feature_grid.php
├── recent_posts.php
├── upcoming_events.php
├── cta_banner.php
├── custom_html.php
└── spacer.php

/theme/{theme}/views/blocks/      # Theme overrides
├── hero_slider.php
└── feature_grid.php

/adm/
├── admin_components.php          # NEW: Manage block type library
├── admin_component_edit.php      # NEW: Edit block type definition
├── admin_page_blocks.php         # NEW: Manage blocks on a page/context
└── admin_page_block_edit.php     # NEW: Edit block instance config

/assets/js/
├── block-config-form.js          # NEW: Dynamic form handling (repeaters)
└── block-ordering.js             # NEW: Drag-and-drop reordering

/assets/css/
└── admin-blocks.css              # NEW: Admin UI styles
```

---

## Core Classes

### Component (Extended Data Model)

```php
class Component extends SystemBase {
    public static $prefix = 'com';
    public static $tablename = 'com_components';
    public static $pkey_column = 'com_component_id';

    public static $field_specifications = array(
        'com_component_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'com_type_key' => array('type'=>'varchar(64)', 'unique'=>true),
        'com_title' => array('type'=>'varchar(255)', 'required'=>true),
        'com_description' => array('type'=>'text'),
        'com_category' => array('type'=>'varchar(64)'),
        'com_icon' => array('type'=>'varchar(64)'),
        'com_config_schema' => array('type'=>'json'),
        'com_default_config' => array('type'=>'json'),
        'com_template_file' => array('type'=>'varchar(255)'),
        'com_logic_function' => array('type'=>'varchar(255)'),
        'com_is_active' => array('type'=>'bool', 'default'=>true),
        'com_requires_plugin' => array('type'=>'varchar(64)'),
        'com_css_framework' => array('type'=>'varchar(32)'),
        'com_requires_theme' => array('type'=>'varchar(64)'),
        'com_order' => array('type'=>'int2'),
        'com_published_time' => array('type'=>'timestamp(6)'),
        'com_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'com_script_filename' => array('type'=>'varchar(255)'),
        'com_delete_time' => array('type'=>'timestamp(6)'),
    );

    public static $json_vars = array('com_config_schema', 'com_default_config');

    /**
     * Get decoded config schema
     */
    public function get_config_schema() {
        $schema = $this->get('com_config_schema');
        if (is_string($schema)) {
            return json_decode($schema, true) ?: array('fields' => array());
        }
        return $schema ?: array('fields' => array());
    }

    /**
     * Get decoded default config
     */
    public function get_default_config() {
        $config = $this->get('com_default_config');
        if (is_string($config)) {
            return json_decode($config, true) ?: array();
        }
        return $config ?: array();
    }

    /**
     * Check if block's dependencies are met (plugin, framework, theme)
     */
    public function is_available() {
        if (!$this->get('com_is_active')) {
            return false;
        }

        $settings = Globalvars::get_instance();

        // Check plugin dependency
        $required_plugin = $this->get('com_requires_plugin');
        if ($required_plugin) {
            $active_plugins = $settings->get_setting($required_plugin . '_active');
            if (empty($active_plugins)) {
                return false;
            }
        }

        // Check CSS framework compatibility
        $css_framework = $this->get('com_css_framework');
        if ($css_framework) {
            $current_theme = $settings->get_setting('theme_template');
            $theme_framework = ThemeHelper::getInstance($current_theme)->config('css_framework', 'bootstrap');
            if ($css_framework !== $theme_framework) {
                return false;
            }
        }

        // Check specific theme requirement
        $requires_theme = $this->get('com_requires_theme');
        if ($requires_theme) {
            $current_theme = $settings->get_setting('theme_template');
            if ($requires_theme !== $current_theme) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get by type key
     */
    public static function get_by_type_key($type_key) {
        return static::GetByColumn('com_type_key', $type_key);
    }
}

class MultiComponent extends SystemMultiBase {
    protected static $model_class = 'Component';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = array();

        if (isset($this->options['category'])) {
            $filters['com_category'] = array($this->options['category'], PDO::PARAM_STR);
        }

        if (isset($this->options['active'])) {
            $filters['com_is_active'] = $this->options['active'] ? "= TRUE" : "= FALSE";
        }

        if (isset($this->options['deleted'])) {
            $filters['com_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }

        if (isset($this->options['has_type_key'])) {
            $filters['com_type_key'] = "IS NOT NULL";
        }

        // Filter by CSS framework
        if (isset($this->options['css_framework'])) {
            // Include blocks for specific framework OR framework-agnostic (NULL)
            $filters['(com_css_framework'] = "= '" . $this->options['css_framework'] . "' OR com_css_framework IS NULL)";
        }

        // Filter by specific theme
        if (isset($this->options['requires_theme'])) {
            // Include blocks for specific theme OR theme-agnostic (NULL)
            $filters['(com_requires_theme'] = "= '" . $this->options['requires_theme'] . "' OR com_requires_theme IS NULL)";
        }

        return $this->_get_resultsv2('com_components', $filters, $this->order_by, $only_count, $debug);
    }
}
```

### PageContent (Extended Data Model)

```php
class PageContent extends SystemBase {
    public static $prefix = 'pac';
    public static $tablename = 'pac_page_contents';
    public static $pkey_column = 'pac_page_content_id';

    public static $field_specifications = array(
        'pac_page_content_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'pac_pag_page_id' => array('type'=>'int4'),
        'pac_page_context' => array('type'=>'varchar(64)'),
        'pac_com_component_id' => array('type'=>'int4'),
        'pac_location_name' => array('type'=>'varchar(255)', 'required'=>true),
        'pac_title' => array('type'=>'varchar(255)'),
        'pac_link' => array('type'=>'varchar(255)'),
        'pac_usr_user_id' => array('type'=>'int4'),
        'pac_body' => array('type'=>'text'),
        'pac_config' => array('type'=>'json'),
        'pac_order' => array('type'=>'int2', 'default'=>0),
        'pac_is_published' => array('type'=>'bool', 'default'=>false),
        'pac_published_time' => array('type'=>'timestamp(6)'),
        'pac_visibility' => array('type'=>'varchar(32)', 'default'=>'all'),
        'pac_start_time' => array('type'=>'timestamp(6)'),
        'pac_end_time' => array('type'=>'timestamp(6)'),
        'pac_theme_override' => array('type'=>'varchar(64)'),
        'pac_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'pac_update_time' => array('type'=>'timestamp(6)'),
        'pac_script_filename' => array('type'=>'varchar(255)'),
        'pac_delete_time' => array('type'=>'timestamp(6)'),
    );

    public static $json_vars = array('pac_config');

    /**
     * Check if this is a block (has component) vs legacy content
     */
    public function is_block() {
        return !empty($this->get('pac_com_component_id'));
    }

    /**
     * Get the component (block type) definition
     */
    public function get_component() {
        if (!$this->is_block()) {
            return null;
        }
        return new Component($this->get('pac_com_component_id'), true);
    }

    /**
     * Get merged config (component defaults + instance overrides)
     */
    public function get_merged_config() {
        $component = $this->get_component();
        if (!$component) {
            return array();
        }

        $defaults = $component->get_default_config();
        $instance_config = $this->get('pac_config');

        if (is_string($instance_config)) {
            $instance_config = json_decode($instance_config, true) ?: array();
        }
        $instance_config = $instance_config ?: array();

        return array_replace_recursive($defaults, $instance_config);
    }

    /**
     * Check if block should be visible for current context
     */
    public function is_visible($session = null) {
        // Must be published
        if (!$this->get('pac_is_published')) {
            return false;
        }

        // Check time constraints
        $now = time();
        $start_time = $this->get('pac_start_time');
        $end_time = $this->get('pac_end_time');

        if ($start_time && strtotime($start_time) > $now) {
            return false;
        }
        if ($end_time && strtotime($end_time) < $now) {
            return false;
        }

        // Check theme constraint
        $theme_override = $this->get('pac_theme_override');
        if ($theme_override) {
            $settings = Globalvars::get_instance();
            if ($settings->get_setting('theme_template') !== $theme_override) {
                return false;
            }
        }

        // Check visibility
        $visibility = $this->get('pac_visibility');
        if ($visibility && $visibility !== 'all' && $session) {
            $is_logged_in = $session->is_logged_in();

            if ($visibility === 'logged_in' && !$is_logged_in) {
                return false;
            }
            if ($visibility === 'logged_out' && $is_logged_in) {
                return false;
            }
            if ($visibility === 'members_only') {
                if (!$is_logged_in) {
                    return false;
                }
                // Additional membership check could go here
            }
        }

        return true;
    }

    /**
     * Legacy: Get content for placeholder system
     */
    public function get_content() {
        if ($this->get('pac_published_time') && !$this->get('pac_delete_time')) {
            return $this->get('pac_body');
        }
        return '';
    }

    // ... existing methods preserved ...
}

class MultiPageContent extends SystemMultiBase {
    protected static $model_class = 'PageContent';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = array();

        if (isset($this->options['user_id'])) {
            $filters['pac_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
        }

        if (isset($this->options['page_id'])) {
            $filters['pac_pag_page_id'] = array($this->options['page_id'], PDO::PARAM_INT);
        }

        // NEW: Filter by page context (homepage, landing pages, etc.)
        if (isset($this->options['page_context'])) {
            $filters['pac_page_context'] = array($this->options['page_context'], PDO::PARAM_STR);
        }

        // NEW: Filter by component (blocks only)
        if (isset($this->options['component_id'])) {
            $filters['pac_com_component_id'] = array($this->options['component_id'], PDO::PARAM_INT);
        }

        // NEW: Filter for blocks only (has component)
        if (isset($this->options['blocks_only']) && $this->options['blocks_only']) {
            $filters['pac_com_component_id'] = "IS NOT NULL";
        }

        if (isset($this->options['link'])) {
            $filters['pac_link'] = array($this->options['link'], PDO::PARAM_STR);
        }

        if (isset($this->options['has_link'])) {
            $filters['pac_link'] = "LENGTH(pac_link) > 0";
        }

        if (isset($this->options['deleted'])) {
            $filters['pac_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }

        if (isset($this->options['published'])) {
            $filters['pac_is_published'] = $this->options['published'] ? "= TRUE" : "= FALSE";
        }

        return $this->_get_resultsv2('pac_page_contents', $filters, $this->order_by, $only_count, $debug);
    }
}
```

### BlockRenderer

```php
<?php
/**
 * BlockRenderer - Renders page blocks
 *
 * /includes/BlockRenderer.php
 */

class BlockRenderer {

    /**
     * Render all blocks for a page context (homepage, landing page, etc.)
     *
     * @param string $page_context The context (e.g., 'homepage', 'landing:sale')
     * @param SessionControl|null $session For visibility checks
     * @return string Rendered HTML
     */
    public static function render_page_context($page_context, $session = null) {
        require_once(PathHelper::getIncludePath('data/page_contents_class.php'));

        $blocks = new MultiPageContent(
            array(
                'page_context' => $page_context,
                'blocks_only' => true,
                'published' => true,
                'deleted' => false
            ),
            array('pac_order' => 'ASC')
        );
        $blocks->load();

        return self::render_block_collection($blocks, $session);
    }

    /**
     * Render all blocks for a specific page
     *
     * @param int $page_id The page ID
     * @param SessionControl|null $session For visibility checks
     * @return string Rendered HTML
     */
    public static function render_page_blocks($page_id, $session = null) {
        require_once(PathHelper::getIncludePath('data/page_contents_class.php'));

        $blocks = new MultiPageContent(
            array(
                'page_id' => $page_id,
                'blocks_only' => true,
                'published' => true,
                'deleted' => false
            ),
            array('pac_order' => 'ASC')
        );
        $blocks->load();

        return self::render_block_collection($blocks, $session);
    }

    /**
     * Render a collection of blocks
     */
    protected static function render_block_collection($blocks, $session = null) {
        $output = '';
        foreach ($blocks as $block) {
            if ($block->is_visible($session)) {
                $output .= self::render_block($block);
            }
        }
        return $output;
    }

    /**
     * Render a single block instance
     *
     * @param PageContent $block The block instance
     * @return string Rendered HTML
     */
    public static function render_block($block) {
        $component = $block->get_component();

        if (!$component || !$component->is_available()) {
            return '';
        }

        $config = $block->get_merged_config();
        $data = array();

        // Load dynamic data if needed
        $logic_function = $component->get('com_logic_function');
        if ($logic_function) {
            $data = self::load_block_data($logic_function, $config);
        }

        // Render template
        $template_file = $component->get('com_template_file');
        if (!$template_file) {
            return '';
        }

        ob_start();

        // Make variables available to template
        $block_config = $config;
        $block_data = $data;
        $block_instance = $block;
        $block_component = $component;

        // Use theme-aware path resolution
        $template_path = PathHelper::getThemeFilePath($template_file, 'views');
        if (file_exists($template_path)) {
            require($template_path);
        }

        return ob_get_clean();
    }

    /**
     * Load dynamic data for a block
     */
    protected static function load_block_data($logic_function, $config) {
        $logic_file = 'logic/blocks/' . $logic_function . '.php';
        $full_path = PathHelper::getIncludePath($logic_file);

        if (file_exists($full_path)) {
            require_once($full_path);
            if (function_exists($logic_function)) {
                return call_user_func($logic_function, $config);
            }
        }

        return array();
    }
}
```

### BlockConfigForm

```php
<?php
/**
 * BlockConfigForm - Generates admin forms from config schema
 *
 * /includes/BlockConfigForm.php
 */

class BlockConfigForm {

    private $formwriter;
    private $schema;
    private $values;
    private $prefix;

    public function __construct($formwriter, $schema, $values = array(), $prefix = 'config') {
        $this->formwriter = $formwriter;
        $this->schema = $schema;
        $this->values = $values;
        $this->prefix = $prefix;
    }

    /**
     * Render the complete config form
     */
    public function render() {
        $output = '';
        $fields = isset($this->schema['fields']) ? $this->schema['fields'] : array();

        foreach ($fields as $field) {
            $output .= $this->render_field($field);
        }

        return $output;
    }

    /**
     * Render a single field based on its type
     */
    protected function render_field($field, $parent_key = '') {
        $key = $field['key'];
        $full_key = $parent_key ? $parent_key . '[' . $key . ']' : $this->prefix . '[' . $key . ']';
        $value = $this->get_nested_value($key, $parent_key);

        // Apply default if no value
        if ($value === null && isset($field['default'])) {
            $value = $field['default'];
        }

        $output = '<div class="block-field mb-3" data-field-key="' . htmlspecialchars($key) . '"';
        if (isset($field['condition'])) {
            $output .= ' data-condition="' . htmlspecialchars(json_encode($field['condition'])) . '"';
        }
        $output .= '>';

        switch ($field['type']) {
            case 'text':
                $output .= $this->formwriter->input_text(
                    $full_key,
                    $field['label'],
                    $value ?: '',
                    isset($field['required']) && $field['required'],
                    isset($field['placeholder']) ? $field['placeholder'] : ''
                );
                break;

            case 'textarea':
                $rows = isset($field['rows']) ? $field['rows'] : 4;
                $output .= $this->formwriter->textarea(
                    $full_key,
                    $field['label'],
                    $value ?: '',
                    isset($field['required']) && $field['required'],
                    $rows
                );
                break;

            case 'richtext':
                $output .= $this->formwriter->rich_text_editor(
                    $full_key,
                    $field['label'],
                    $value ?: ''
                );
                break;

            case 'number':
                $output .= $this->render_number_field($full_key, $field, $value);
                break;

            case 'select':
                $options = array();
                foreach ($field['options'] as $opt) {
                    if (is_array($opt)) {
                        $options[$opt['value']] = $opt['label'];
                    } else {
                        $options[$opt] = $opt;
                    }
                }
                $output .= $this->formwriter->select_from_list(
                    $full_key,
                    $field['label'],
                    $options,
                    $value
                );
                break;

            case 'checkbox':
                $output .= $this->formwriter->input_checkbox(
                    $full_key,
                    $field['label'],
                    $value
                );
                break;

            case 'image':
                $output .= $this->render_image_field($full_key, $field, $value);
                break;

            case 'link':
                $output .= $this->render_link_field($full_key, $field, $value);
                break;

            case 'icon':
                $output .= $this->render_icon_field($full_key, $field, $value);
                break;

            case 'color':
                $output .= $this->render_color_field($full_key, $field, $value);
                break;

            case 'repeater':
                $output .= $this->render_repeater_field($full_key, $field, $value);
                break;

            case 'group':
                $output .= $this->render_group_field($full_key, $field, $value);
                break;
        }

        if (isset($field['description'])) {
            $output .= '<small class="form-text text-muted">' . htmlspecialchars($field['description']) . '</small>';
        }

        $output .= '</div>';

        return $output;
    }

    /**
     * Get nested value from values array
     */
    protected function get_nested_value($key, $parent_key = '') {
        if ($parent_key) {
            // Handle nested keys for repeaters/groups
            $parts = explode('[', str_replace(']', '', $parent_key));
            $current = $this->values;
            foreach ($parts as $part) {
                if ($part === $this->prefix) continue;
                if (!isset($current[$part])) return null;
                $current = $current[$part];
            }
            return isset($current[$key]) ? $current[$key] : null;
        }
        return isset($this->values[$key]) ? $this->values[$key] : null;
    }

    /**
     * Render number field with min/max/step
     */
    protected function render_number_field($full_key, $field, $value) {
        $attrs = array();
        if (isset($field['min'])) $attrs[] = 'min="' . $field['min'] . '"';
        if (isset($field['max'])) $attrs[] = 'max="' . $field['max'] . '"';
        if (isset($field['step'])) $attrs[] = 'step="' . $field['step'] . '"';

        $output = '<label class="form-label">' . htmlspecialchars($field['label']) . '</label>';
        $output .= '<input type="number" class="form-control" name="' . htmlspecialchars($full_key) . '" ';
        $output .= 'value="' . htmlspecialchars($value ?: '') . '" ' . implode(' ', $attrs) . '>';
        return $output;
    }

    /**
     * Render image upload field
     */
    protected function render_image_field($full_key, $field, $value) {
        $output = '<label class="form-label">' . htmlspecialchars($field['label']) . '</label>';
        $output .= '<div class="input-group">';
        $output .= '<input type="text" class="form-control image-url-input" name="' . htmlspecialchars($full_key) . '" ';
        $output .= 'value="' . htmlspecialchars($value ?: '') . '" placeholder="Image URL or upload">';
        $output .= '<button type="button" class="btn btn-outline-secondary image-upload-btn">Browse</button>';
        $output .= '</div>';
        if ($value) {
            $output .= '<img src="' . htmlspecialchars($value) . '" class="img-thumbnail mt-2" style="max-height: 100px;">';
        }
        return $output;
    }

    /**
     * Render link field (URL + optional text)
     */
    protected function render_link_field($full_key, $field, $value) {
        $url = is_array($value) ? (isset($value['url']) ? $value['url'] : '') : $value;
        $text = is_array($value) && isset($value['text']) ? $value['text'] : '';

        $output = '<label class="form-label">' . htmlspecialchars($field['label']) . '</label>';
        $output .= '<input type="url" class="form-control mb-2" name="' . htmlspecialchars($full_key) . '[url]" ';
        $output .= 'value="' . htmlspecialchars($url) . '" placeholder="https://...">';

        if (!isset($field['show_text']) || $field['show_text']) {
            $output .= '<input type="text" class="form-control" name="' . htmlspecialchars($full_key) . '[text]" ';
            $output .= 'value="' . htmlspecialchars($text) . '" placeholder="Link text (optional)">';
        }
        return $output;
    }

    /**
     * Render icon picker field
     */
    protected function render_icon_field($full_key, $field, $value) {
        $output = '<label class="form-label">' . htmlspecialchars($field['label']) . '</label>';
        $output .= '<div class="input-group">';
        if ($value) {
            $output .= '<span class="input-group-text"><i class="bx ' . htmlspecialchars($value) . '"></i></span>';
        }
        $output .= '<input type="text" class="form-control icon-picker-input" name="' . htmlspecialchars($full_key) . '" ';
        $output .= 'value="' . htmlspecialchars($value ?: '') . '" placeholder="e.g., bx-home">';
        $output .= '<button type="button" class="btn btn-outline-secondary icon-picker-btn">Choose</button>';
        $output .= '</div>';
        return $output;
    }

    /**
     * Render color picker field
     */
    protected function render_color_field($full_key, $field, $value) {
        $default = isset($field['default']) ? $field['default'] : '#000000';
        $output = '<label class="form-label">' . htmlspecialchars($field['label']) . '</label>';
        $output .= '<div class="input-group">';
        $output .= '<input type="color" class="form-control form-control-color" name="' . htmlspecialchars($full_key) . '" ';
        $output .= 'value="' . htmlspecialchars($value ?: $default) . '">';
        $output .= '<input type="text" class="form-control color-hex-input" value="' . htmlspecialchars($value ?: $default) . '">';
        $output .= '</div>';
        return $output;
    }

    /**
     * Render repeater field with add/remove buttons
     */
    protected function render_repeater_field($full_key, $field, $values) {
        $item_label = isset($field['item_label']) ? $field['item_label'] : 'Item';
        $min = isset($field['min']) ? $field['min'] : 0;
        $max = isset($field['max']) ? $field['max'] : 99;

        $output = '<div class="repeater-field card" data-min="' . $min . '" data-max="' . $max . '">';
        $output .= '<div class="card-header">';
        $output .= '<strong>' . htmlspecialchars($field['label']) . '</strong>';
        $output .= '</div>';
        $output .= '<div class="card-body">';
        $output .= '<div class="repeater-items">';

        $values = is_array($values) ? $values : array();
        foreach ($values as $index => $item_values) {
            $output .= $this->render_repeater_item($full_key, $field, $index, $item_values, $item_label);
        }

        $output .= '</div>';

        // Template for new items (used by JS)
        $output .= '<template class="repeater-template">';
        $output .= $this->render_repeater_item($full_key, $field, '__INDEX__', array(), $item_label);
        $output .= '</template>';

        $output .= '<button type="button" class="btn btn-secondary btn-sm add-repeater-item mt-2">';
        $output .= '<i class="bx bx-plus"></i> Add ' . htmlspecialchars($item_label);
        $output .= '</button>';

        $output .= '</div></div>';

        return $output;
    }

    /**
     * Render a single repeater item
     */
    protected function render_repeater_item($parent_key, $field, $index, $values, $item_label) {
        $output = '<div class="repeater-item card mb-2" data-index="' . $index . '">';
        $output .= '<div class="card-header py-2 d-flex justify-content-between align-items-center">';
        $output .= '<span>' . htmlspecialchars($item_label) . ' <span class="item-number">' . ($index === '__INDEX__' ? '' : $index + 1) . '</span></span>';
        $output .= '<div class="btn-group btn-group-sm">';
        $output .= '<button type="button" class="btn btn-outline-secondary move-up" title="Move up"><i class="bx bx-chevron-up"></i></button>';
        $output .= '<button type="button" class="btn btn-outline-secondary move-down" title="Move down"><i class="bx bx-chevron-down"></i></button>';
        $output .= '<button type="button" class="btn btn-outline-danger remove-item" title="Remove"><i class="bx bx-trash"></i></button>';
        $output .= '</div>';
        $output .= '</div>';
        $output .= '<div class="card-body">';

        $item_key = $parent_key . '[' . $index . ']';
        $saved_values = $this->values;
        $this->values = $values;

        foreach ($field['fields'] as $subfield) {
            $output .= $this->render_field($subfield, $item_key);
        }

        $this->values = $saved_values;

        $output .= '</div></div>';

        return $output;
    }

    /**
     * Render group field (non-repeating nested fields)
     */
    protected function render_group_field($full_key, $field, $values) {
        $collapsible = isset($field['collapsible']) && $field['collapsible'];
        $collapsed = isset($field['collapsed']) && $field['collapsed'];

        $output = '<div class="group-field card mb-3">';
        $output .= '<div class="card-header' . ($collapsible ? ' cursor-pointer' : '') . '"';
        if ($collapsible) {
            $output .= ' data-bs-toggle="collapse" data-bs-target="#group-' . md5($full_key) . '"';
        }
        $output .= '>';
        $output .= '<strong>' . htmlspecialchars($field['label']) . '</strong>';
        if ($collapsible) {
            $output .= ' <i class="bx bx-chevron-down float-end"></i>';
        }
        $output .= '</div>';

        $output .= '<div id="group-' . md5($full_key) . '" class="' . ($collapsible ? 'collapse' : '') . ($collapsed ? '' : ' show') . '">';
        $output .= '<div class="card-body">';

        $values = is_array($values) ? $values : array();
        $saved_values = $this->values;
        $this->values = $values;

        foreach ($field['fields'] as $subfield) {
            $output .= $this->render_field($subfield, $full_key);
        }

        $this->values = $saved_values;

        $output .= '</div></div></div>';

        return $output;
    }
}
```

---

## Admin Interface

### Block Type Library (`/adm/admin_components.php`)

List view showing all available block types with:
- Filter by category
- Toggle active/inactive
- Edit block definition
- Shows which blocks are in use

**Access level:** Superadmin (10) - Block types are system-level

### Page Blocks Management (`/adm/admin_page_blocks.php`)

For managing blocks on a specific page or context:

**URL patterns:**
- `/adm/admin_page_blocks?context=homepage` - Homepage blocks
- `/adm/admin_page_blocks?context=landing:sale` - Landing page blocks
- `/adm/admin_page_blocks?page_id=42` - Specific page blocks

**Features:**
- Drag-and-drop reordering
- Quick toggle active/inactive
- Duplicate block
- Add new block from picker modal
- Edit block instance config
- Preview (opens in new tab)

**Access level:** Admin (5)

### Block Instance Edit (`/adm/admin_page_block_edit.php`)

Edit form for a block instance:
- Instance name (admin-only label)
- Block type (select from available components)
- Visibility settings (all/logged_in/logged_out/members_only)
- Time constraints (start/end dates)
- Theme override
- Config fields (dynamically generated from schema)

---

## Theme Requirements

### CSS Framework Declaration

Themes must declare their CSS framework in `theme.json` so blocks can check compatibility:

```json
{
  "name": "falcon",
  "display_name": "Falcon Theme",
  "css_framework": "bootstrap",
  "version": "1.0.0"
}
```

**Supported values:**
- `bootstrap` - Bootstrap 4/5
- `tailwind` - Tailwind CSS
- `custom` - Custom CSS (blocks marked `custom` or `NULL` will work)

Blocks with `com_css_framework = NULL` are framework-agnostic (e.g., custom HTML blocks).

### Block Compatibility Rules

| Block Framework | Theme Framework | Result |
|-----------------|-----------------|--------|
| NULL | any | Available |
| bootstrap | bootstrap | Available |
| bootstrap | tailwind | Hidden |
| tailwind | tailwind | Available |
| tailwind | bootstrap | Hidden |

**Admin UI:** When selecting blocks to add, only framework-compatible blocks are shown.

---

## Integration Points

### Homepage Integration

```php
// /views/index.php (updated)
<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/BlockRenderer.php'));

$session = SessionControl::get_instance();
$settings = Globalvars::get_instance();

$page = new PublicPage();
$page->public_header(array(
    'title' => $settings->get_setting('site_name'),
    'showheader' => true
));

// Render all homepage blocks
echo BlockRenderer::render_page_context('homepage', $session);

$page->public_footer();
?>
```

### Page Integration

```php
// /views/page.php - blocks can supplement page content
<?php
// ... existing page loading code ...

// Render page-specific blocks (before or after page content)
echo BlockRenderer::render_page_blocks($page_record->key, $session);

// Existing page content (placeholder system still works)
echo $page_record->get_filled_content();
?>
```

### Landing Page Route

```php
// In serve.php, add route for landing pages
'/landing/{slug}' => function($params, $settings, $session, $template_directory) {
    require_once(PathHelper::getIncludePath('includes/BlockRenderer.php'));
    require_once(PathHelper::getIncludePath('data/page_contents_class.php'));

    $context = 'landing:' . $params['slug'];

    // Check if landing page has blocks
    $blocks = new MultiPageContent(array(
        'page_context' => $context,
        'blocks_only' => true,
        'published' => true,
        'deleted' => false
    ));

    if ($blocks->count_all() === 0) {
        return false; // 404
    }

    require(PathHelper::getThemeFilePath('landing.php', 'views'));
    return true;
}
```

---

## Block Template Examples

### Hero Static (`/views/blocks/hero_static.php`)

```php
<?php
/**
 * Hero Static Block
 *
 * Available variables:
 *   $block_config - Merged configuration array
 *   $block_data - Dynamic data (empty for this block)
 *   $block_instance - PageContent object
 *   $block_component - Component object
 */

$heading = $block_config['heading'] ?? '';
$subheading = $block_config['subheading'] ?? '';
$background_image = $block_config['background_image'] ?? '';
$background_color = $block_config['background_color'] ?? '#f8f9fa';
$text_color = $block_config['text_color'] ?? '#212529';
$cta_text = $block_config['cta']['cta_text'] ?? '';
$cta_link = $block_config['cta']['cta_link']['url'] ?? '';
$height = $block_config['height'] ?? 'medium';

$height_class = array(
    'small' => 'py-5',
    'medium' => 'py-100',
    'large' => 'py-150',
    'fullscreen' => 'min-vh-100 d-flex align-items-center'
);
$height_css = isset($height_class[$height]) ? $height_class[$height] : 'py-100';

$style = 'background-color: ' . htmlspecialchars($background_color) . ';';
if ($background_image) {
    $style .= ' background-image: url(' . htmlspecialchars($background_image) . ');';
    $style .= ' background-size: cover; background-position: center;';
}
$style .= ' color: ' . htmlspecialchars($text_color) . ';';
?>

<section class="hero-static-block <?php echo $height_css; ?>" style="<?php echo $style; ?>">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8 text-center">
                <?php if ($heading): ?>
                    <h1 class="hero-heading mb-3"><?php echo htmlspecialchars($heading); ?></h1>
                <?php endif; ?>

                <?php if ($subheading): ?>
                    <p class="hero-subheading lead mb-4"><?php echo htmlspecialchars($subheading); ?></p>
                <?php endif; ?>

                <?php if ($cta_text && $cta_link): ?>
                    <a href="<?php echo htmlspecialchars($cta_link); ?>" class="btn btn-primary btn-lg">
                        <?php echo htmlspecialchars($cta_text); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
```

### Recent Posts (`/views/blocks/recent_posts.php`)

```php
<?php
/**
 * Recent Posts Block
 *
 * Available variables:
 *   $block_config - Configuration (count, layout, show_excerpt, etc.)
 *   $block_data - ['posts' => MultiPost collection]
 *   $block_instance - PageContent object
 *   $block_component - Component object
 */

$heading = $block_config['heading'] ?? 'Recent Posts';
$layout = $block_config['layout'] ?? 'grid';
$show_excerpt = $block_config['show_excerpt'] ?? true;
$show_author = $block_config['show_author'] ?? true;
$show_date = $block_config['show_date'] ?? true;
$columns = $block_config['columns'] ?? 3;

$posts = isset($block_data['posts']) ? $block_data['posts'] : null;

$col_class = array(
    2 => 'col-md-6',
    3 => 'col-md-6 col-lg-4',
    4 => 'col-md-6 col-lg-3'
);
$col_css = isset($col_class[$columns]) ? $col_class[$columns] : 'col-md-6 col-lg-4';
?>

<section class="recent-posts-block py-5">
    <div class="container">
        <?php if ($heading): ?>
            <div class="section-title text-center mb-4">
                <h2><?php echo htmlspecialchars($heading); ?></h2>
            </div>
        <?php endif; ?>

        <?php if ($posts && $posts->count() > 0): ?>
            <div class="row">
                <?php foreach ($posts as $post):
                    $author = new User($post->get('pst_usr_user_id'), TRUE);
                ?>
                    <div class="<?php echo $col_css; ?> mb-4">
                        <div class="card h-100">
                            <?php if ($post->get('pst_image_link')): ?>
                                <a href="<?php echo $post->get_url(); ?>">
                                    <img src="<?php echo htmlspecialchars($post->get('pst_image_link')); ?>"
                                         class="card-img-top"
                                         alt="<?php echo htmlspecialchars($post->get('pst_title')); ?>">
                                </a>
                            <?php endif; ?>

                            <div class="card-body">
                                <h5 class="card-title">
                                    <a href="<?php echo $post->get_url(); ?>">
                                        <?php echo htmlspecialchars($post->get('pst_title')); ?>
                                    </a>
                                </h5>

                                <?php if ($show_excerpt): ?>
                                    <p class="card-text">
                                        <?php
                                        if ($post->get('pst_short_description')) {
                                            echo htmlspecialchars($post->get('pst_short_description'));
                                        } else {
                                            echo htmlspecialchars(substr(strip_tags($post->get('pst_body')), 0, 120)) . '...';
                                        }
                                        ?>
                                    </p>
                                <?php endif; ?>
                            </div>

                            <?php if ($show_author || $show_date): ?>
                                <div class="card-footer text-muted small">
                                    <?php if ($show_author): ?>
                                        <span>By <?php echo htmlspecialchars($author->display_name()); ?></span>
                                    <?php endif; ?>
                                    <?php if ($show_author && $show_date): ?> &middot; <?php endif; ?>
                                    <?php if ($show_date): ?>
                                        <span><?php echo date('M j, Y', strtotime($post->get('pst_published_time'))); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($block_config['show_view_all'])): ?>
                <div class="text-center mt-3">
                    <a href="/blog" class="btn btn-outline-primary">
                        <?php echo htmlspecialchars($block_config['view_all_text'] ?? 'View All Posts'); ?>
                    </a>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <p class="text-center text-muted">No posts to display.</p>
        <?php endif; ?>
    </div>
</section>
```

### Recent Posts Logic (`/logic/blocks/recent_posts_logic.php`)

```php
<?php
/**
 * Load data for Recent Posts block
 *
 * @param array $config Block configuration
 * @return array Data for the template
 */
function recent_posts_logic($config) {
    require_once(PathHelper::getIncludePath('data/posts_class.php'));

    $count = isset($config['count']) ? intval($config['count']) : 3;
    $count = max(1, min(12, $count)); // Clamp between 1-12

    $posts = new MultiPost(
        array('published' => true, 'deleted' => false),
        array('pst_published_time' => 'DESC'),
        $count,
        0
    );
    $posts->load();

    return array('posts' => $posts);
}
```

---

## Migration Notes

### Existing PageContent Records

The 4 existing PageContent records use the placeholder system (no `pac_com_component_id`). They will continue to work unchanged - the block system only affects records with a component link.

### No Data Migration Required

Since we're extending tables rather than replacing them:
- Existing data is preserved
- New fields have sensible defaults
- Old code paths continue to work

---

## Security Considerations

1. **Config validation** - Validate all config values against schema before saving
2. **HTML sanitization** - Custom HTML block should use HTML Purifier or existing sanitization
3. **File paths** - Template files must be within allowed directories
4. **Permission checks** - Block management requires admin access (level 5+)
5. **XSS prevention** - All output uses htmlspecialchars() by default
6. **SQL injection** - All queries use prepared statements via SystemBase

---

## Performance Considerations

1. **Eager loading** - Load all blocks for a page in single query
2. **Component caching** - Cache component definitions (they change rarely)
3. **Minimal queries** - Block logic functions should be efficient
4. **Template caching** - Could add template caching in future if needed

---

## Future Enhancements

1. **Block previews** - Live preview in admin while editing
2. **Block templates** - Save configured blocks as reusable starting points
3. **A/B testing** - Show different blocks to different user segments
4. **Analytics** - Track which blocks users interact with
5. **Import/export** - Export page block configurations as JSON
6. **Versioning** - Track changes to block configurations over time
7. **Global blocks** - Blocks that appear on multiple pages (header/footer supplements)
8. **Nested blocks** - Container blocks that hold other blocks (Phase 3+)

---

## Implementation Phases

### Phase 1: Foundation (MVP)
- [ ] Extend Component class with new fields
- [ ] Extend PageContent class with new fields
- [ ] Create BlockRenderer class
- [ ] Create basic BlockConfigForm (text, textarea, select, checkbox, number)
- [ ] Create admin list page for page blocks (`admin_page_blocks.php`)
- [ ] Create admin edit page for block instances (`admin_page_block_edit.php`)
- [ ] Implement 5 basic blocks (hero_static, text_block, feature_grid, cta_banner, custom_html)
- [ ] Integrate with homepage (`views/index.php`)

### Phase 2: Dynamic Blocks & Advanced Fields
- [ ] Add repeater field support to BlockConfigForm
- [ ] Add image upload/picker field
- [ ] Add link field
- [ ] Add group field support
- [ ] Implement dynamic blocks (recent_posts, upcoming_events)
- [ ] Add block logic function system
- [ ] Create 5 more blocks (testimonials, image_gallery, accordion, etc.)

### Phase 3: Enhanced Admin
- [ ] Drag-and-drop block reordering (JS)
- [ ] Block picker modal with categories
- [ ] Block duplication
- [ ] Visibility settings UI (logged in/out, dates)
- [ ] Theme override setting
- [ ] Admin for managing block types (`admin_components.php`)

### Phase 4: Landing Pages & Polish
- [ ] Landing page routing
- [ ] Landing page admin creation
- [ ] Icon picker field (modal with icon grid)
- [ ] Color picker field
- [ ] Conditional field visibility (JS)
- [ ] Block previews

---

## Appendix: Full Block Type Definitions

See separate file: `specs/page_block_system_block_definitions.md`
