# Page Component System Specification

## Overview

A flexible, database-driven component system that allows administrators to compose pages from a library of pre-built, configurable components. This system **extends the existing Component and PageContent tables** rather than creating new ones, maintaining backward compatibility while adding powerful new capabilities.

## Goals

1. **Reduce custom development** - Clients can build unique pages without code changes
2. **Maintain theme compatibility** - Component templates are theme-overridable
3. **Form-based editing** - No WYSIWYG; structured forms like existing admin pages
4. **Developer extensibility** - Easy to add new component types
5. **Performance** - Efficient rendering with minimal database queries
6. **Backward compatibility** - Existing PageContent placeholder system continues to work
7. **Build on existing tables** - Extend `com_components` and `pac_page_contents`

## Non-Goals

1. WYSIWYG or drag-and-drop visual editing in a live preview
2. Replacing the existing Page/PageContent placeholder system (this complements it)
3. Full CMS functionality (this is specifically for component-based layouts)

---

## Data Architecture

### Extending `com_components` (Component Type Library)

The existing `com_components` table will store component type definitions. Current structure is minimal and empty, so we add fields for the component system.

**New fields to add:**

```sql
-- Component type metadata
com_type_key VARCHAR(64) UNIQUE      -- 'hero_slider', 'feature_grid', etc.
com_description TEXT                  -- 'Full-width image slider with text overlays'
com_category VARCHAR(64)              -- 'hero', 'content', 'media', 'dynamic', 'conversion'
com_icon VARCHAR(64)                  -- 'bx-image' for admin UI

-- Configuration
com_template_file VARCHAR(255)        -- 'hero_slider.php' (filename only, rendered from views/components/)
com_config_schema JSON                -- Field definitions for admin form (see Config Schema Format section)
com_logic_function VARCHAR(255)       -- 'hero_slider_component_logic' if dynamic data needed

-- Status
com_is_active BOOLEAN DEFAULT TRUE    -- Can be disabled system-wide
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
    'com_template_file' => array('type'=>'varchar(255)'),
    'com_config_schema' => array('type'=>'json'),  // Field definitions for admin form
    'com_logic_function' => array('type'=>'varchar(255)'),
    'com_is_active' => array('type'=>'bool', 'default'=>true),
    'com_requires_plugin' => array('type'=>'varchar(64)'),
    'com_order' => array('type'=>'int2'),
    'com_published_time' => array('type'=>'timestamp(6)'),
    'com_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
    'com_script_filename' => array('type'=>'varchar(255)'), // Legacy, keep for compatibility
    'com_delete_time' => array('type'=>'timestamp(6)'),
);
```

### Extending `pac_page_contents` (Component Instances)

The existing `pac_page_contents` table will store component instances. We add fields for component configuration while preserving backward compatibility with the placeholder system.

**New fields to add:**

```sql
-- Component configuration
pac_config JSON                       -- Instance-specific configuration (extends pac_body for components)
```

**Updated field_specifications for PageContent class:**

```php
public static $field_specifications = array(
    'pac_page_content_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
    'pac_pag_page_id' => array('type'=>'int4'),
    'pac_com_component_id' => array('type'=>'int4'),
    'pac_location_name' => array('type'=>'varchar(255)', 'required'=>true, 'unique'=>true),  // Component slug
    'pac_title' => array('type'=>'varchar(255)'),
    'pac_link' => array('type'=>'varchar(255)'),  // Optional, used by placeholder system
    'pac_usr_user_id' => array('type'=>'int4'),
    'pac_body' => array('type'=>'text'),  // Keep for placeholder system / legacy
    'pac_config' => array('type'=>'json'),  // NEW: component configuration
    'pac_order' => array('type'=>'int2'),  // Render order within a page (used by get_filled_content)
    'pac_is_published' => array('type'=>'bool', 'default'=>false),
    'pac_published_time' => array('type'=>'timestamp(6)'),
    'pac_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
    'pac_script_filename' => array('type'=>'varchar(255)'),  // Legacy
    'pac_delete_time' => array('type'=>'timestamp(6)'),
);

public static $json_vars = array('pac_config');
```

**Component Identification:** Components are identified by their `pac_location_name` (slug). Templates explicitly request components by slug, e.g., `ComponentRenderer::render('homepage-hero')`.

**Slug Uniqueness:** Enforced via standard unique constraint on `pac_location_name`. Existing records with empty slugs will be populated with unique values during implementation.

---

## Backward Compatibility

### Existing Placeholder System

The current system where `*!**slug**!*` placeholders in page body are replaced with PageContent body **continues to work unchanged**. This is handled by `Page::get_filled_content()` which:
1. Loads PageContents linked to the page via `pac_pag_page_id`
2. Replaces `*!**pac_link**!*` with `pac_body`

**This system is preserved** - PageContents with `pac_link` set and no `pac_com_component_id` use the old behavior.

### Component System (New)

PageContents with `pac_com_component_id` set are treated as components:
1. They render via the Component's template file
2. Configuration comes from `pac_config`
3. Components are identified by their slug (`pac_location_name`) and rendered explicitly by templates

### Detection Logic

```php
// In PageContent class
public function is_component() {
    return !empty($this->get('pac_com_component_id'));
}

// In rendering logic - template explicitly requests component by slug
echo ComponentRenderer::render('homepage-hero');
echo ComponentRenderer::render('homepage-features');
```

---

## Component Configuration

Component configuration uses a JSON schema approach that defines field structure for the admin form:

1. **Component templates use variables** like `$config['heading']`, `$config['features']`
2. **Admin form generates inputs** based on `com_config_schema` (JSON)
3. **`pac_config` stores JSON** with the actual values

### Config Schema Format

The `com_config_schema` field stores a JSON object defining the fields:

```json
{
  "fields": [
    {"name": "fieldname", "label": "Display Label", "type": "text"},
    {"name": "fieldname", "label": "Display Label", "type": "textarea"},
    {"name": "fieldname", "label": "Display Label", "type": "repeater", "fields": [...]}
  ]
}
```

**Field Properties:**

| Property | Required | Description |
|----------|----------|-------------|
| `name` | Yes | Field key used in templates (e.g., `heading`) |
| `label` | Yes | Display label in admin form (e.g., `"Heading"`) |
| `type` | No | Field type: `text`, `textarea`, `repeater` (default: `text`) |
| `help` | No | Help text shown below the field |
| `fields` | For repeater | Array of sub-fields for repeater type |

**MVP Field Types:**
- `text` - Single-line text input (default)
- `textarea` - Multi-line text input
- `repeater` - Repeating group of fields with Add/Remove UI

**Future Field Types (Phase 2+):**
- `image` - Image picker integration
- `select` - Dropdown with predefined options
- `checkbox` - Boolean toggle
- `number` - Numeric input
- `html` - Rich text editor

### Example: Simple Component

**Component type schema:**
```json
{
  "fields": [
    {"name": "heading", "label": "Heading", "type": "text"},
    {"name": "subheading", "label": "Subheading", "type": "textarea"},
    {"name": "background_image", "label": "Background Image", "type": "text", "help": "Path to image file, e.g. /images/hero.jpg"},
    {"name": "cta_text", "label": "Button Text", "type": "text"},
    {"name": "cta_link", "label": "Button Link", "type": "text"}
  ]
}
```

**Admin form rendered:**
```
Heading:          [____________________]
Subheading:       [____________________]
                  [                    ]
Background Image: [____________________]
                  Path to image file, e.g. /images/hero.jpg
Button Text:      [____________________]
Button Link:      [____________________]
```

**Stored instance config (`pac_config`):**
```json
{
  "heading": "Welcome to Our Site",
  "subheading": "We help you succeed",
  "background_image": "/images/hero-bg.jpg",
  "cta_text": "Get Started",
  "cta_link": "/signup"
}
```

### Example: Repeater Fields Component

**Component type schema:**
```json
{
  "fields": [
    {"name": "heading", "label": "Heading", "type": "text"},
    {"name": "subheading", "label": "Subheading", "type": "textarea"},
    {
      "name": "features",
      "label": "Features",
      "type": "repeater",
      "fields": [
        {"name": "icon", "label": "Icon", "type": "text"},
        {"name": "title", "label": "Title", "type": "text"},
        {"name": "description", "label": "Description", "type": "textarea"}
      ]
    }
  ]
}
```

**Admin form rendered:**
```
Heading:    [____________________]
Subheading: [____________________]

Features:                              [+ Add Feature]
┌─────────────────────────────────────────────────────┐
│ Icon: [________]  Title: [________________________] │
│ Description: [__________________________________]   │
│                                           [Remove]  │
├─────────────────────────────────────────────────────┤
│ Icon: [________]  Title: [________________________] │
│ Description: [__________________________________]   │
│                                           [Remove]  │
└─────────────────────────────────────────────────────┘
```

**Stored instance config (`pac_config`):**
```json
{
  "heading": "Why Choose Us",
  "subheading": "Everything you need to succeed",
  "features": [
    {"icon": "rocket", "title": "Fast", "description": "Lightning quick performance"},
    {"icon": "lock", "title": "Secure", "description": "Bank-grade encryption"}
  ]
}
```

**Template usage:**
```php
<?php foreach ($component_config['features'] as $feature): ?>
    <div class="feature">
        <i class="<?php echo htmlspecialchars($feature['icon']); ?>"></i>
        <h3><?php echo htmlspecialchars($feature['title']); ?></h3>
        <p><?php echo htmlspecialchars($feature['description']); ?></p>
    </div>
<?php endforeach; ?>
```

### Schema Helper Functions

```php
/**
 * Get config schema as parsed array
 * Returns the fields array from the schema, or empty array if invalid
 */
public function get_config_schema() {
    $schema = $this->get('com_config_schema');
    if (is_string($schema)) {
        $schema = json_decode($schema, true);
    }
    if (!is_array($schema) || !isset($schema['fields'])) {
        return array();
    }
    return $schema['fields'];
}

/**
 * Get simple field names (non-repeater) for quick access
 */
public function get_simple_field_names() {
    $names = array();
    foreach ($this->get_config_schema() as $field) {
        if (($field['type'] ?? 'text') !== 'repeater') {
            $names[] = $field['name'];
        }
    }
    return $names;
}

/**
 * Get repeater field definitions
 */
public function get_repeater_fields() {
    $repeaters = array();
    foreach ($this->get_config_schema() as $field) {
        if (($field['type'] ?? 'text') === 'repeater') {
            $repeaters[$field['name']] = $field;
        }
    }
    return $repeaters;
}
```

---

## Component Categories

Components are organized into categories for easier discovery:

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

## Component Library

### Phase 1: Existing Code to Wrap (MVP)

These components already exist as view code and can be extracted into configurable components:

| Component Type Key | Source Location | Description |
|-------------------|-----------------|-------------|
| `hero_slider` | `/views/index.php` (swiper_wrapper section) | Full-width slider with multiple slides, title, description, background |
| `feature_grid` | `/views/index.php` (feature-box sections) | Grid of icon + title + description items (12+ instances) |
| `cta_banner` | `/views/index.php` (promo-full section) | Full-width promotional call to action |
| `event_grid` | `/views/events.php` | Portfolio-style event cards with filtering, dates, instructors |
| `product_grid` | `/views/products.php` | Shop-style product cards with images, descriptions, hover effects |
| `pricing_table` | `/views/pricing.php` | Pricing cards with plan name, price, features list, CTA |
| `recent_posts` | `/views/index.php` (posts-sm sidebar) | Recent blog post entries with thumbnails |
| `video_section` | `/views/index.php` (video.php container) | Video embed section with poster/background |
| `page_title` | `/views/events.php`, `/views/event.php` | Page title with subtitle and breadcrumb navigation |
| `breadcrumbs` | Multiple views | Navigation breadcrumb trail |
| `alert` | `PublicPage::alert()` | Bootstrap alert messages |
| `tab_menu` | `PublicPage::tab_menu()` | Tab navigation for content sections |

**Implementation approach:** Extract the HTML/CSS patterns from existing views into component templates, make key values configurable.

### Phase 2: New Components to Build

These are commonly needed but don't currently exist in the codebase:

| Component Type Key | Category | Description |
|-------------------|----------|-------------|
| `hero_static` | hero | Single hero with heading, text, background, CTA (simpler than slider) |
| `text_block` | content | Heading + rich text content |
| `text_with_image` | content | Text alongside image (left or right layout) |
| `testimonial_slider` | testimonials | Rotating customer testimonials |
| `testimonial_grid` | testimonials | Grid of testimonial cards |
| `image_gallery` | media | Grid of images with lightbox |
| `accordion` | content | Collapsible FAQ-style content |
| `tabs` | content | Tabbed content sections |
| `stats_counter` | features | Animated number counters |
| `logo_wall` | testimonials | Grid of partner/client logos |
| `team_grid` | content | Team member cards |
| `contact_info` | content | Address, phone, email display |
| `map_embed` | media | Google Maps embed |
| `spacer` | layout | Vertical spacing |
| `divider` | layout | Horizontal line with optional text/icon |
| `custom_html` | custom | Raw HTML for advanced users |

---

## File Structure

**Template File Convention:** Component templates are stored in `views/components/` and referenced by filename only (e.g., `'hero_static.php'`). This follows `PathHelper::getThemeFilePath()` conventions where the subdirectory is a separate parameter. The theme override chain is: `theme/{theme}/views/components/` → `plugins/{plugin}/views/components/` → `views/components/`.

```
/data/
├── components_class.php          # Extended with new fields
└── page_contents_class.php       # Extended with new fields

/includes/
└── ComponentRenderer.php         # NEW: Renders components with data

/logic/components/                # NEW: Component-specific logic functions (for dynamic components)
├── recent_posts_logic.php
└── upcoming_events_logic.php

/views/components/                # NEW: Component templates
├── hero_static.php
├── text_block.php
├── feature_grid.php
├── cta_banner.php
└── custom_html.php

/theme/{theme}/views/components/  # Theme overrides
└── hero_static.php

/adm/
├── admin_component_types.php     # NEW: List all component types (superadmin)
├── admin_component_type_edit.php # NEW: Edit component type definition
├── admin_components.php          # NEW: List all component instances
└── admin_component_edit.php      # NEW: Edit component instance config
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
        'com_template_file' => array('type'=>'varchar(255)'),
        'com_config_schema' => array('type'=>'json'),  // Field definitions for admin form
        'com_logic_function' => array('type'=>'varchar(255)'),
        'com_is_active' => array('type'=>'bool', 'default'=>true),
        'com_order' => array('type'=>'int2'),
        'com_published_time' => array('type'=>'timestamp(6)'),
        'com_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'com_script_filename' => array('type'=>'varchar(255)'),
        'com_delete_time' => array('type'=>'timestamp(6)'),
    );

    public static $json_vars = array('com_config_schema');

    /**
     * Get config schema as parsed array
     * Returns the fields array from the schema, or empty array if invalid
     */
    public function get_config_schema() {
        $schema = $this->get('com_config_schema');
        if (is_string($schema)) {
            $schema = json_decode($schema, true);
        }
        if (!is_array($schema) || !isset($schema['fields'])) {
            return array();
        }
        return $schema['fields'];
    }

    /**
     * Get simple field names (non-repeater) for quick access
     */
    public function get_simple_field_names() {
        $names = array();
        foreach ($this->get_config_schema() as $field) {
            if (($field['type'] ?? 'text') !== 'repeater') {
                $names[] = $field['name'];
            }
        }
        return $names;
    }

    /**
     * Get repeater field definitions
     */
    public function get_repeater_fields() {
        $repeaters = array();
        foreach ($this->get_config_schema() as $field) {
            if (($field['type'] ?? 'text') === 'repeater') {
                $repeaters[$field['name']] = $field;
            }
        }
        return $repeaters;
    }

    /**
     * Check if component type is available
     */
    public function is_available() {
        return $this->get('com_is_active');
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
        'pac_com_component_id' => array('type'=>'int4'),
        'pac_location_name' => array('type'=>'varchar(255)', 'required'=>true, 'unique'=>true),  // Component slug
        'pac_title' => array('type'=>'varchar(255)'),
        'pac_link' => array('type'=>'varchar(255)'),  // Optional, used by placeholder system
        'pac_usr_user_id' => array('type'=>'int4'),
        'pac_body' => array('type'=>'text'),
        'pac_config' => array('type'=>'json'),
        'pac_order' => array('type'=>'int2', 'default'=>0),  // Render order within a page (used by get_filled_content)
        'pac_is_published' => array('type'=>'bool', 'default'=>false),
        'pac_published_time' => array('type'=>'timestamp(6)'),
        'pac_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'pac_script_filename' => array('type'=>'varchar(255)'),
        'pac_delete_time' => array('type'=>'timestamp(6)'),
    );

    public static $json_vars = array('pac_config');


    /**
     * Get component by slug (location_name)
     */
    public static function get_by_slug($slug) {
        return static::GetByColumn('pac_location_name', $slug);
    }

    /**
     * Check if this is a component (has component type) vs legacy content
     */
    public function is_component() {
        return !empty($this->get('pac_com_component_id'));
    }

    /**
     * Get the component type definition
     */
    public function get_component_type() {
        if (!$this->is_component()) {
            return null;
        }
        return new Component($this->get('pac_com_component_id'), true);
    }

    /**
     * Get config as array
     */
    public function get_config() {
        $config = $this->get('pac_config');
        if (is_string($config)) {
            return json_decode($config, true) ?: array();
        }
        return $config ?: array();
    }

    /**
     * Check if component is published
     */
    public function is_visible() {
        return $this->get('pac_is_published');
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

    /**
     * Override save() to update validation logic
     */
    public function save($debug = false) {
        // Only check for duplicate pac_link if it's not empty (legacy placeholder system)
        $pac_link = $this->get('pac_link');
        if (!empty($pac_link) && $this->check_for_duplicate('pac_link')) {
            throw new SystemAuthenticationError('This page link is a duplicate.');
        }

        if ($this->key) {
            // Save old version in content_version table
            ContentVersion::NewVersion(
                ContentVersion::TYPE_PAGE_CONTENT,
                $this->key,
                $this->get('pac_body'),
                $this->get('pac_title'),
                $this->get('pac_title')
            );
        }

        parent::save($debug);
    }

    // ... existing methods preserved (get_filled_content, authenticate_write) ...
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

        // Filter by component type
        if (isset($this->options['component_id'])) {
            $filters['pac_com_component_id'] = array($this->options['component_id'], PDO::PARAM_INT);
        }

        // Filter for components only (has component type)
        if (isset($this->options['components_only']) && $this->options['components_only']) {
            $filters['pac_com_component_id'] = "IS NOT NULL";
        }

        // Filter by slug
        if (isset($this->options['slug'])) {
            $filters['pac_location_name'] = array($this->options['slug'], PDO::PARAM_STR);
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

### ComponentRenderer

```php
<?php
/**
 * ComponentRenderer - Renders page components by slug
 *
 * /includes/ComponentRenderer.php
 */

class ComponentRenderer {

    /**
     * Output debug message as HTML comment
     * Uses Globalvars 'debug' setting to determine visibility
     */
    protected static function debug_output($message, $slug = '') {
        $settings = Globalvars::get_instance();
        if (!$settings->get_setting('debug')) {
            return '';
        }
        $slug_info = $slug ? " (slug: {$slug})" : '';
        return "<!-- ComponentRenderer{$slug_info}: {$message} -->\n";
    }

    /**
     * Render a component by its slug
     *
     * @param string $slug The component's slug (pac_location_name)
     * @return string Rendered HTML (includes debug comments for admins on errors)
     */
    public static function render($slug) {
        require_once(PathHelper::getIncludePath('data/page_contents_class.php'));

        $component_instance = PageContent::get_by_slug($slug);

        if (!$component_instance) {
            return self::debug_output("Component not found", $slug);
        }

        if (!$component_instance->is_component()) {
            return self::debug_output("Record exists but is not a component (no pac_com_component_id)", $slug);
        }

        if (!$component_instance->is_visible()) {
            return self::debug_output("Component exists but is not published", $slug);
        }

        return self::render_component($component_instance, $slug);
    }

    /**
     * Render a single component instance
     *
     * @param PageContent $component_instance The component instance
     * @param string $slug For debug output
     * @return string Rendered HTML
     */
    public static function render_component($component_instance, $slug = '') {
        $component_type = $component_instance->get_component_type();

        if (!$component_type) {
            return self::debug_output("Component type not found (pac_com_component_id may reference deleted type)", $slug);
        }

        if (!$component_type->is_available()) {
            $type_key = $component_type->get('com_type_key');
            return self::debug_output("Component type '{$type_key}' is inactive", $slug);
        }

        $config = $component_instance->get_config();
        $data = array();

        // Load dynamic data if needed
        $logic_function = $component_type->get('com_logic_function');
        if ($logic_function) {
            try {
                $data = self::load_component_data($logic_function, $config, $slug);
            } catch (Exception $e) {
                return self::debug_output("Logic function '{$logic_function}' threw exception: " . $e->getMessage(), $slug);
            }
        }

        // Render template
        $template_file = $component_type->get('com_template_file');
        if (!$template_file) {
            return self::debug_output("Component type has no template file configured", $slug);
        }

        // Use theme-aware path resolution
        // Template files are stored as filename only (e.g., 'hero_static.php')
        // and always located in views/components/ subdirectory
        $template_path = PathHelper::getThemeFilePath($template_file, 'views/components');
        if (!file_exists($template_path)) {
            return self::debug_output("Template file not found: {$template_file} (resolved to: {$template_path})", $slug);
        }

        ob_start();

        // Make variables available to template
        $component_config = $config;
        $component_data = $data;
        $component = $component_instance;
        $component_type_record = $component_type;

        try {
            require($template_path);
        } catch (Exception $e) {
            ob_end_clean();
            return self::debug_output("Template threw exception: " . $e->getMessage(), $slug);
        }

        return ob_get_clean();
    }

    /**
     * Load dynamic data for a component
     *
     * @param string $logic_function Function name to call
     * @param array $config Component configuration
     * @param string $slug For debug output
     * @return array Data for the template
     */
    protected static function load_component_data($logic_function, $config, $slug = '') {
        $logic_file = 'logic/components/' . $logic_function . '.php';
        $full_path = PathHelper::getIncludePath($logic_file);

        if (!file_exists($full_path)) {
            // Return empty but log debug - not a fatal error
            error_log("ComponentRenderer: Logic file not found: {$logic_file}");
            return array();
        }

        require_once($full_path);

        if (!function_exists($logic_function)) {
            error_log("ComponentRenderer: Logic function '{$logic_function}' not defined in {$logic_file}");
            return array();
        }

        return call_user_func($logic_function, $config);
    }
}
```

---

## Admin Interface

### Admin Menu Integration

Component management pages appear under the existing **Pages** menu as a new submenu:

```
Pages
├── All Pages
├── Add New Page
├── Page Contents (existing)
├── ─────────────
├── Components          → admin_components.php (list component instances)
└── Component Types     → admin_component_types.php (superadmin only)
```

**Menu configuration** (in admin menu system):
- "Components" - visible to Admin (5+)
- "Component Types" - visible to Superadmin (10) only

### Component Type Management (`/adm/admin_component_types.php`)

List all component types (the library of available components):
- Filter by category
- Search by name/type_key
- Quick toggle active/inactive
- Edit component type

**Access level:** Superadmin (10) - component types are system-level definitions

### Component Type Edit (`/adm/admin_component_type_edit.php`)

Edit form for a component type definition:
- Type Key (`com_type_key`) - unique identifier like 'hero_static', 'feature_grid'
- Title (`com_title`) - display name like "Hero Static", "Feature Grid"
- Description (`com_description`) - explains what this component does
- Category (`com_category`) - dropdown: hero, content, features, media, etc.
- Icon (`com_icon`) - icon class for admin UI
- Template File (`com_template_file`) - filename only like 'hero_static.php' (templates live in `views/components/`)
- Config Schema (`com_config_schema`) - JSON field definitions (see Config Schema Format)
- Logic Function (`com_logic_function`) - optional, for dynamic components
- Active checkbox (`com_is_active`)

**Validation:**
- Type key must be unique and URL-safe (lowercase, underscores)
- Template file must exist in views/components/ or theme override location

### Component Instance Management (`/adm/admin_components.php`)

List all component instances in the system:
- Filter by component type
- Search by slug
- Quick toggle published/unpublished
- Edit component instance

**Access level:** Admin (5)

### Component Instance Edit (`/adm/admin_component_edit.php`)

Edit form for a component instance:
- Slug (`pac_location_name`) - unique identifier for rendering (validated unique)
- Admin title (`pac_title`) - for admin display only
- Component type (select from available component types)
- Published checkbox
- Config fields - dynamically generated from `com_config_schema` (text inputs, textareas, repeaters)

**Validation:**
- Slug must be unique across all component instances

---

## Integration Points

### Two Rendering Approaches

The component system supports two complementary approaches:

| Approach | Method | Use Case |
|----------|--------|----------|
| **Explicit** | `ComponentRenderer::render('slug')` | Developer controls layout in view file |
| **Automatic** | `$page->get_filled_content()` | No-code page building; components auto-render |

**Key distinction:**
- Components with `pac_pag_page_id` set → auto-rendered by `get_filled_content()`
- Components called by slug → work regardless of page assignment
- Both approaches can be mixed in the same view

### Automatic Rendering via `Page::get_filled_content()`

The existing `get_filled_content()` method is extended to automatically render components assigned to the page. **Components fully replace page body content** - they don't mix.

**Rendering logic:**
- **Has components?** → Render components only (ordered by `pac_order`)
- **No components?** → Fall back to traditional page body (`pag_body`) with placeholder substitution
- **Need custom HTML in a component page?** → Use a `custom_html` component type

```php
// In Page class (extended)
public function get_filled_content() {
    require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));

    // Check for components assigned to this page
    $components = new MultiPageContent(
        ['page_id' => $this->key, 'components_only' => true, 'published' => true, 'deleted' => false],
        ['pac_order' => 'ASC']
    );

    if ($components->count_all() > 0) {
        // Has components - render them, ignore page body
        $output = '';
        $components->load();
        foreach ($components as $component) {
            $output .= ComponentRenderer::render_component($component);
        }
        return $output;
    }

    // No components - fall back to traditional page body + placeholders
    return $this->get_body_content();
}

/**
 * Get page body with placeholder substitution (extracted from original get_filled_content)
 */
protected function get_body_content() {
    // ... existing placeholder logic moved here ...
}
```

**Result:** Templates that already call `$page->get_filled_content()` automatically gain component support with zero changes. Pages transition cleanly from body-based to component-based content.

### Explicit Rendering via `ComponentRenderer::render()`

For custom layouts, developers can explicitly render components by slug:

```php
// /views/index.php - developer-controlled layout
<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));

$page = new PublicPage();
$page->public_header(array('title' => $settings->get_setting('site_name')));

// Explicit component calls - developer controls order and placement
echo ComponentRenderer::render('homepage-hero');
echo ComponentRenderer::render('homepage-features');
echo ComponentRenderer::render('homepage-testimonials');
echo ComponentRenderer::render('homepage-cta');

$page->public_footer();
?>
```

### Mixed Approach

Views can combine explicit components with automatic page content:

```php
// /views/page.php - mixed approach
<?php
// Explicit component at top
echo ComponentRenderer::render('site-wide-announcement');

// Automatic: renders page-assigned components + body content
echo $page_record->get_filled_content();

// Explicit component at bottom
echo ComponentRenderer::render('footer-cta');
?>
```

### No-Code Page Building (Future)

With automatic rendering, admins can build pages without view files:

1. Create a Page record (e.g., "About Us" with link `/about`)
2. Assign components to the page via admin UI (setting `pac_pag_page_id`)
3. Set `pac_order` to control component order
4. Page renders automatically via `get_filled_content()` - no PHP required

The routing system serves the page, calls `get_filled_content()`, and components render in order.

---

## Component Template Example

### Hero Static (`/views/components/hero_static.php`)

```php
<?php
/**
 * Hero Static Component
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 */

$heading = $component_config['heading'] ?? '';
$subheading = $component_config['subheading'] ?? '';
$background_image = $component_config['background_image'] ?? '';
$cta_text = $component_config['cta_text'] ?? '';
$cta_link = $component_config['cta_link'] ?? '';
?>

<section class="hero-static py-5" style="<?php if ($background_image): ?>background-image: url(<?php echo htmlspecialchars($background_image); ?>); background-size: cover;<?php endif; ?>">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8 text-center">
                <?php if ($heading): ?>
                    <h1 class="mb-3"><?php echo htmlspecialchars($heading); ?></h1>
                <?php endif; ?>

                <?php if ($subheading): ?>
                    <p class="lead mb-4"><?php echo htmlspecialchars($subheading); ?></p>
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

**Component type record:**
```
com_type_key: 'hero_static'
com_title: 'Hero Static'
com_config_schema: {"fields": [
  {"name": "heading", "label": "Heading", "type": "text"},
  {"name": "subheading", "label": "Subheading", "type": "textarea"},
  {"name": "background_image", "label": "Background Image", "type": "text"},
  {"name": "cta_text", "label": "Button Text", "type": "text"},
  {"name": "cta_link", "label": "Button Link", "type": "text"}
]}
com_template_file: 'hero_static.php'
```

---

## Migration Notes

### Existing PageContent Records

The 4 existing PageContent records use the placeholder system (no `pac_com_component_id`). They will continue to work unchanged - the component system only affects records with a component type link.

### Data Migration Required

Before the unique constraint can be added to `pac_location_name`, existing records with empty slugs must be populated:

| ID | Current pac_location_name | New pac_location_name |
|----|---------------------------|----------------------|
| 1  | "Front Page Banner" | `front-page-banner` |
| 2  | "Front Page Left" | `front-page-left` |
| 3  | "Front Page Right" | `front-page-right` |
| 23 | "test content" | `test-content` |

**Migration SQL:**
```sql
UPDATE pac_page_contents SET pac_location_name = 'front-page-banner' WHERE pac_page_content_id = 1;
UPDATE pac_page_contents SET pac_location_name = 'front-page-left' WHERE pac_page_content_id = 2;
UPDATE pac_page_contents SET pac_location_name = 'front-page-right' WHERE pac_page_content_id = 3;
UPDATE pac_page_contents SET pac_location_name = 'test-content' WHERE pac_page_content_id = 23;
```

### Backward Compatibility

Since we're extending tables rather than replacing them:
- Existing data is preserved (after slug migration)
- New fields have sensible defaults
- Old code paths continue to work

### Component Type Seeding

Component types are seeded from definitions in themes and plugins. See **`/specs/page_block_system_block_definitions.md`** for the complete field schema definitions.

**Seeding approach:**
1. Component type definitions live in theme/plugin directories as PHP files
2. A seeder script scans for definition files and inserts/updates `com_components` records
3. Each definition specifies: `type_key`, `title`, `description`, `category`, `template_file`, `config_fields`, `logic_function` (if dynamic)

**Definition file locations:**
```
/views/components/definitions/          # Core component definitions
/theme/{theme}/components/definitions/  # Theme-specific components
/plugins/{plugin}/components/definitions/ # Plugin-provided components
```

**Example definition file** (`/views/components/definitions/hero_static.php`):
```php
<?php
return [
    'type_key' => 'hero_static',
    'title' => 'Hero Static',
    'description' => 'Single hero section with heading, subheading, background, and CTA',
    'category' => 'hero',
    'icon' => 'bx-image',
    'template_file' => 'hero_static.php',  // Filename only, lives in views/components/
    'config_schema' => [
        'fields' => [
            ['name' => 'heading', 'label' => 'Heading', 'type' => 'text'],
            ['name' => 'subheading', 'label' => 'Subheading', 'type' => 'textarea'],
            ['name' => 'background_image', 'label' => 'Background Image', 'type' => 'text', 'help' => 'Path to image file'],
            ['name' => 'cta_text', 'label' => 'Button Text', 'type' => 'text'],
            ['name' => 'cta_link', 'label' => 'Button Link', 'type' => 'text'],
        ]
    ],
    'logic_function' => null,
    'requires_plugin' => null,
];
```

**Seeder script** (`/utils/seed_component_types.php`):
- Scans definition directories
- For each definition file, creates or updates the `com_components` record
- Matches on `com_type_key` for updates
- Can be run manually or as part of deployment

---

## Security Considerations

1. **Config validation** - Validate all config values before saving
2. **HTML sanitization** - Custom HTML component should use HTML Purifier or existing sanitization
3. **File paths** - Template files must be within allowed directories
4. **Permission checks** - Component management requires admin access (level 5+)
5. **XSS prevention** - All output uses htmlspecialchars() by default
6. **SQL injection** - All queries use prepared statements via SystemBase

---

## Performance Considerations

1. **Eager loading** - Load all components for a page in single query
2. **Component type caching** - Cache component type definitions (they change rarely)
3. **Minimal queries** - Component logic functions should be efficient
4. **Template caching** - Could add template caching in future if needed

---

## Future Enhancements

1. **No-code page builder** - Full admin UI for building pages from components without view files (foundation already in place via `get_filled_content()` integration)
2. **Component previews** - Live preview in admin while editing
3. **Component templates** - Save configured components as reusable starting points
4. **A/B testing** - Show different components to different user segments
5. **Analytics** - Track which components users interact with
6. **Import/export** - Export page component configurations as JSON
7. **Versioning** - Track changes to component configurations over time
8. **Shared components** - Components rendered on multiple pages via explicit slug calls (already supported)
9. **Nested components** - Container components that hold other components (Phase 3+)

---

## Implementation Phases

### Phase 1: Foundation (MVP)
- [ ] Extend Component class with new fields (`com_type_key`, `com_config_schema`, `com_template_file`, etc.)
- [ ] Extend PageContent class with new fields (`pac_config`, `pac_is_published`) with unique constraint on slug
- [ ] Create ComponentRenderer class with `render($slug)` and `render_component($instance)` methods
- [ ] Extend `Page::get_filled_content()` to auto-render page-assigned components (enables future no-code page building)
- [ ] Create admin for component types (`admin_component_types.php`, `admin_component_type_edit.php`)
- [ ] Create admin for component instances with repeater UI (`admin_components.php`, `admin_component_edit.php`)
  - Fields render based on `type` in schema: `text` (input), `textarea`, `repeater`
  - Repeater fields render as grouped rows with Add/Remove buttons
  - Page assignment dropdown (optional `pac_pag_page_id`) for automatic rendering
  - Order field (`pac_order`) for controlling render sequence
- [ ] Extract 3 existing patterns as components (`cta_banner`, `feature_grid`, `page_title`)
- [ ] Create `custom_html` component (essential escape hatch for freeform content in component-based pages)
- [ ] Integrate with homepage (`views/index.php`) using explicit `ComponentRenderer::render()` calls

### Pre-Completion: Documentation Required

Before moving this spec to `/specs/implemented/`, the following documentation must be created:

- [ ] Update `/docs/plugin_developer_guide.md` with component creation instructions
- [ ] Document `ComponentRenderer` class usage and methods
- [ ] Document `com_config_schema` JSON format with examples
- [ ] Document the two rendering approaches (explicit vs automatic)
- [ ] Document `Page::get_filled_content()` behavior (components replace body)
- [ ] Add component development section to `CLAUDE.md` if needed

### Phase 2: More Components
- [ ] Extract remaining existing patterns (`hero_slider`, `event_grid`, `product_grid`, etc.)
- [ ] Add component logic function system for dynamic data
- [ ] Build new static components (`hero_static`, `text_block`, `text_with_image`)

### Phase 3: Enhanced Admin (Future)
- [ ] Typed field schema system (select dropdowns, image pickers, repeaters)
- [ ] Visibility settings (logged in/out, dates)
- [ ] Component previews

