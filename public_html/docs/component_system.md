# Page Component System

The Page Component System enables composing pages from reusable, configurable content blocks. Components combine a template, optional logic function, and admin-configurable settings.

---

## Table of Contents

1. [Overview](#1-overview)
2. [ComponentRenderer](#2-componentrenderer)
3. [Component Types](#3-component-types)
4. [Component Instances](#4-component-instances)
5. [Creating Templates](#5-creating-templates)
6. [Logic Functions](#6-logic-functions)
7. [Config Schema Reference](#7-config-schema-reference)
8. [Admin Interface](#8-admin-interface)
9. [Legacy Content Systems](#9-legacy-content-systems)

---

## 1. Overview

### Key Concepts

- **Component Type** - A template definition with config schema (e.g., "Hero Section", "Feature Grid")
- **Component Instance** - A configured use of a component type with specific content
- **Config Schema** - JSON definition of editable fields for a component type
- **ComponentRenderer** - The class that renders component instances

### Architecture

```
Component Type (template + schema)
        ↓
Component Instance (configured content)
        ↓
ComponentRenderer (renders output)
        ↓
HTML Output
```

### Two Ways to Use Components

1. **Page-Attached** - Automatically rendered with page content via `pac_pag_page_id`
2. **Standalone (Slug-Based)** - Explicitly rendered via `ComponentRenderer::render('slug')`

---

## 2. ComponentRenderer

The `ComponentRenderer` class handles all component rendering. It's available globally (loaded by PathHelper).

### Render by Slug

```php
// In any template or view file
echo ComponentRenderer::render('homepage-hero');

// Conditional rendering
if (ComponentRenderer::exists('sidebar-promo')) {
    echo ComponentRenderer::render('sidebar-promo');
}
```

### Render by Slug with Config Overrides

```php
// Override specific config values at render time
echo ComponentRenderer::render('homepage-hero', null, ['heading' => 'Custom Title']);
```

The `$overrides` array is merged on top of the database-stored config, so overrides win.

### Render by Type Key (Programmatic)

Render a component template directly by its type key, without needing a database instance. Useful for reusable UI patterns called from PHP code with runtime data.

```php
// Render image gallery with runtime data — no database component instance needed
echo ComponentRenderer::render(null, 'image_gallery', [
    'photos' => $post->get_photos(),
    'primary_file_id' => $post->get('pst_fil_file_id'),
]);
```

**Full signature:** `render($slug, $type_key = null, $overrides = [])`

| Parameter | Type | Description |
|-----------|------|-------------|
| `$slug` | string\|null | Component slug (loads from database) |
| `$type_key` | string\|null | Component type key (renders directly, no database instance) |
| `$overrides` | array | Config values — merged with database config (slug mode) or used as full config (type_key mode) |

**Behavior:**
- `render('slug')` — Existing behavior, loads from database
- `render('slug', null, $overrides)` — Loads from database, merges overrides into config
- `render(null, 'type_key', $config)` — Renders template by type key with provided config

When rendering by type key:
- `$component_config` = the config array passed by the caller
- `$component` = `null` (no PageContent instance)
- Layout wrapper is skipped (the calling view controls layout)

### Get Page Components

```php
// Get all components for a page (excludes deleted)
$components = ComponentRenderer::get_page_components($page_id);

foreach ($components as $component) {
    echo ComponentRenderer::render($component->get('pac_location_name'));
}
```

### Render Multiple by Slug

```php
// Render several components in sequence
echo ComponentRenderer::render_multiple(['hero', 'features', 'testimonials']);
```

### Check if Renderable

```php
// Returns true only if: exists, is a component, and is not deleted
if (ComponentRenderer::exists('promo-banner')) {
    echo ComponentRenderer::render('promo-banner');
}
```

### Debug Output

When `debug` setting is enabled, ComponentRenderer outputs HTML comments explaining why a component didn't render:

```html
<!-- ComponentRenderer (slug: missing-component): Component not found -->
<!-- ComponentRenderer (slug: deleted-hero): Component exists but is deleted -->
<!-- ComponentRenderer (slug: old-widget): Component type 'legacy_widget' is inactive -->
```

---

## 3. Component Types

Component types are the library of available components. Managed by superadmins at `/admin/admin_component_types`.

### Database: `com_components`

| Field | Type | Description |
|-------|------|-------------|
| `com_component_id` | int8 | Primary key |
| `com_type_key` | varchar(64) | Unique identifier (e.g., `hero_static`) |
| `com_title` | varchar(255) | Display name |
| `com_description` | text | Description for admins |
| `com_category` | varchar(64) | Grouping category |
| `com_template_file` | varchar(255) | Template path (relative) |
| `com_config_schema` | json | Field definitions |
| `com_logic_function` | varchar(255) | Optional logic function name |
| `com_is_active` | bool | Whether type is available |
| `com_requires_plugin` | varchar(64) | Required plugin name |
| `com_css_framework` | varchar(32) | Required CSS framework (e.g., `bootstrap`) |

### Categories

Built-in categories (from `Component::get_categories()`):

| Key | Label |
|-----|-------|
| `hero` | Hero Sections |
| `content` | Content Blocks |
| `features` | Features & Benefits |
| `media` | Media & Images |
| `testimonials` | Testimonials & Social Proof |
| `dynamic` | Dynamic Content |
| `conversion` | CTAs & Conversion |
| `layout` | Layout & Spacing |
| `custom` | Custom & Freeform |

### Creating a Component Type

**Method 1: JSON Definition Files (Recommended)**

Create a JSON file paired with the PHP template:

```
/views/components/hero_static.json    # Definition file
/views/components/hero_static.php     # Template file
```

The JSON file defines the component metadata and config schema:

```json
{
  "title": "Hero Static",
  "description": "Single hero section with heading, subheading, background, and CTA",
  "category": "hero",
  "css_framework": "bootstrap",
  "config_schema": {
    "fields": [
      {"name": "heading", "label": "Heading", "type": "textinput"},
      {"name": "subheading", "label": "Subheading", "type": "textarea"}
    ]
  }
}
```

**JSON fields:**

| Field | Required | Description |
|-------|----------|-------------|
| `title` | Yes | Display name |
| `description` | No | Description for admins |
| `category` | No | Grouping category (see categories table above) |
| `css_framework` | No | Required framework: `bootstrap`, `tailwind`, or omit/`null` for universal |
| `logic_function` | No | Name of logic function (see [Logic Functions](#6-logic-functions)) |
| `layout_defaults` | No | Default layout settings (see [Layout Controls](#layout-controls)) |
| `config_schema` | Yes | Field definitions object |

Component types are automatically discovered during theme sync operations. The JSON file is the single source of truth - component types cannot be created or edited via the admin interface.

### CSS Framework Compatibility

Components can specify framework requirements:

| `css_framework` Value | Behavior |
|-----------------------|----------|
| `bootstrap` | Only active when Bootstrap theme is used |
| `tailwind` | Only active when Tailwind theme is used |
| (omitted/null) | Universal - works with any theme |

When a theme is activated, components incompatible with its framework are deactivated. Components become active again if a compatible theme is activated.

---

## 4. Component Instances

Component instances are configured uses of component types. Stored in `pac_page_contents` table.

### Database Fields (Component-Specific)

| Field | Type | Description |
|-------|------|-------------|
| `pac_page_content_id` | int8 | Primary key |
| `pac_com_component_id` | int4 | FK to component type |
| `pac_pag_page_id` | int4 | FK to page (optional) |
| `pac_location_name` | varchar(255) | Slug for explicit rendering |
| `pac_title` | varchar(255) | Admin label |
| `pac_config` | json | Configured values |
| `pac_order` | int2 | Display order on page |
| `pac_max_width` | varchar(50) | Layout: max width CSS value (e.g., `720px`) |
| `pac_max_height` | varchar(50) | Layout: max height CSS value |
| `pac_vertical_margin` | varchar(20) | Layout: vertical margin keyword (`none`, `sm`, `md`, `lg`, `xl`) |

### Loading Components

```php
require_once(PathHelper::getIncludePath('data/page_contents_class.php'));

// By slug
$component = PageContent::get_by_slug('homepage-hero');

// By ID
$component = new PageContent($id, TRUE);

// Check if it's a component (vs legacy content)
if ($component->is_component()) {
    $type = $component->get_component_type();
    $config = $component->get_config();
}
```

### Querying Components

```php
require_once(PathHelper::getIncludePath('data/page_contents_class.php'));

// All components (not legacy content)
$components = new MultiPageContent(
    ['components_only' => true, 'deleted' => false],
    ['pac_order' => 'ASC']
);

// Components for a specific page
$page_components = new MultiPageContent(
    ['page_id' => $page_id, 'components_only' => true, 'deleted' => false],
    ['pac_order' => 'ASC']
);
```

---

## 5. Creating Templates

Templates are PHP files in `views/components/` that render component output.

### File Location

```
/views/components/hero_static.php       # Base template
/theme/{theme}/views/components/hero_static.php  # Theme override
```

Templates follow the standard theme override chain via `PathHelper::getThemeFilePath()`.

### Available Variables

Inside a component template, these variables are available:

| Variable | Type | Description |
|----------|------|-------------|
| `$component_config` | array | The configured values from admin |
| `$component_data` | array | Data from logic function (if any) |
| `$component` | PageContent | The component instance object |
| `$component_type_record` | Component | The component type definition |
| `$component_slug` | string | The component's slug |
| `$container_class` | string | CSS class for layout container (e.g., `"container"`) |
| `$container_style` | string | Inline style for container width (e.g., `""`, `"max-width:720px"`) |
| `$max_height_style` | string | Inline style for max height (e.g., `""`, `"max-height:400px;overflow:hidden"`) |

> **Layout Controls:** Container width and max height are controlled automatically by the renderer via a wrapper div with CSS custom properties. Most templates don't need to use the layout variables above -- they exist for component types that opt out of auto-wrapping via `skip_wrapper: true` in their `layout_defaults`.

### Basic Template Example

```php
<?php
/**
 * Hero Static Component
 *
 * A simple hero section with heading, subheading, and CTA button.
 */

$heading = $component_config['heading'] ?? 'Welcome';
$subheading = $component_config['subheading'] ?? '';
$button_text = $component_config['button_text'] ?? '';
$button_url = $component_config['button_url'] ?? '#';
$background_image = $component_config['background_image'] ?? '';
?>

<section class="hero-section" <?php if ($background_image): ?>style="background-image: url('<?= htmlspecialchars($background_image) ?>')"<?php endif; ?>>
    <div class="container">
        <h1><?= htmlspecialchars($heading) ?></h1>

        <?php if ($subheading): ?>
            <p class="lead"><?= htmlspecialchars($subheading) ?></p>
        <?php endif; ?>

        <?php if ($button_text): ?>
            <a href="<?= htmlspecialchars($button_url) ?>" class="btn btn-primary btn-lg">
                <?= htmlspecialchars($button_text) ?>
            </a>
        <?php endif; ?>
    </div>
</section>
```

### Template with Repeater Data

```php
<?php
/**
 * Feature Grid Component
 *
 * Displays a grid of features with icons.
 */

$title = $component_config['title'] ?? 'Features';
$features = $component_config['features'] ?? [];
$columns = $component_config['columns'] ?? 3;
?>

<section class="feature-grid">
    <div class="container">
        <h2 class="text-center mb-4"><?= htmlspecialchars($title) ?></h2>

        <div class="row">
            <?php foreach ($features as $feature): ?>
                <div class="col-md-<?= 12 / intval($columns) ?>">
                    <div class="feature-item text-center">
                        <?php if (!empty($feature['icon'])): ?>
                            <i class="<?= htmlspecialchars($feature['icon']) ?> fa-3x mb-3"></i>
                        <?php endif; ?>

                        <h4><?= htmlspecialchars($feature['title'] ?? '') ?></h4>
                        <p><?= htmlspecialchars($feature['description'] ?? '') ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
```

### Template with Dynamic Data

```php
<?php
/**
 * Recent Posts Component
 *
 * Displays recent blog posts. Uses logic function for data.
 */

$title = $component_config['title'] ?? 'Recent Posts';
$posts = $component_data['posts'] ?? [];  // From logic function
?>

<section class="recent-posts">
    <div class="container">
        <h2><?= htmlspecialchars($title) ?></h2>

        <?php if (empty($posts)): ?>
            <p class="text-muted">No posts available.</p>
        <?php else: ?>
            <div class="row">
                <?php foreach ($posts as $post): ?>
                    <div class="col-md-4">
                        <article class="post-card">
                            <h3><a href="<?= htmlspecialchars($post['url']) ?>">
                                <?= htmlspecialchars($post['title']) ?>
                            </a></h3>
                            <p><?= htmlspecialchars($post['excerpt']) ?></p>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
```

---

## 6. Logic Functions

Logic functions provide dynamic data to component templates. They're optional - static components don't need them.

### File Location

```
/logic/components/{function_name}.php
```

The file must define a function with the same name as the file.

### Function Signature

```php
<?php
/**
 * Recent Posts Logic Function
 *
 * @param array $config The component's configuration values
 * @return array Data to pass to template as $component_data
 */
function recent_posts_logic($config) {
    require_once(PathHelper::getIncludePath('data/posts_class.php'));

    $limit = intval($config['post_count'] ?? 3);

    $posts = new MultiPost(
        ['published' => true, 'deleted' => false],
        ['post_date' => 'DESC'],
        $limit
    );
    $posts->load();

    $result = [];
    foreach ($posts as $post) {
        $result[] = [
            'title' => $post->get('post_title'),
            'excerpt' => $post->get('post_excerpt'),
            'url' => $post->get_url(),
            'date' => $post->get('post_date')
        ];
    }

    return ['posts' => $result];
}
```

### Configuring Logic Function

In the component type's JSON definition, set the `logic_function` field:

```json
{
  "title": "Newsletter Signup",
  "logic_function": "newsletter_signup_logic",
  "config_schema": { ... }
}
```

ThemeManager syncs this value to `com_logic_function` during theme sync. The corresponding file `logic/components/newsletter_signup_logic.php` must exist.

### Real-World Example: Newsletter Signup

The `newsletter_signup` component demonstrates a logic function that loads data from existing models, checks user state, and returns everything the template needs:

```php
function newsletter_signup_logic($config) {
    // Check if feature is enabled
    $settings = Globalvars::get_instance();
    if (!$settings->get_setting('mailing_lists_active')) {
        return ['is_active' => false];
    }

    // Load mailing list(s) based on config
    require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));
    $list_mode = $config['list_mode'] ?? 'default';

    // ... resolve lists, check subscriptions, build form action ...

    return [
        'is_active' => true,
        'mailing_lists' => $mailing_lists,
        'form_action' => $form_action,
        'session' => $session,
        'user_subscribed_list' => $user_subscribed_list,
        // ...
    ];
}
```

Key pattern: the logic function returns data — it does **not** handle form submission. The newsletter form posts to the existing `/list/{slug}` or `/lists` endpoints, reusing all existing subscription logic.

### Error Handling

If a logic function throws an exception, ComponentRenderer catches it and returns debug output (when debug mode is enabled). The component will not render.

---

## 7. Config Schema Reference

The config schema is a JSON object defining the admin form fields for a component type.

### Basic Structure

```json
{
    "fields": [
        {
            "name": "field_name",
            "label": "Field Label",
            "type": "textinput",
            "help": "Optional help text",
            "default": "Default value"
        }
    ]
}
```

### Field Properties

| Property | Required | Description |
|----------|----------|-------------|
| `name` | Yes | Unique field identifier (lowercase_snake_case) |
| `label` | Yes | Display label in admin form |
| `type` | Yes | Field type (see table below) |
| `help` | No | Help text shown below field |
| `default` | No | Default value for new component instances |
| `options` | For dropinput/radioinput | Key-value pairs for selection fields |
| `fields` | For repeater | Nested field definitions |
| `advanced` | No | If `true`, field is hidden behind "Show advanced fields" toggle |

### Default Values

The `default` property pre-populates fields when creating new component instances in the admin interface. This improves the admin experience by showing sensible starting values.

```json
{
  "fields": [
    {"name": "columns", "label": "Columns", "type": "dropinput", "default": "3",
     "options": {"2": "2 Columns", "3": "3 Columns", "4": "4 Columns"}},
    {"name": "alignment", "label": "Alignment", "type": "dropinput", "default": "center",
     "options": {"left": "Left", "center": "Center", "right": "Right"}},
    {"name": "show_button", "label": "Show Button", "type": "checkboxinput", "default": true}
  ]
}
```

**Important:** Default values are applied in the admin form when creating new components. They are NOT applied at render time - templates should still use `??` fallbacks for robustness.

### Advanced Fields

Fields that users rarely need to change can be marked as `"advanced": true`. These fields are hidden behind a collapsible "Show advanced fields" link in the admin form, keeping the interface cleaner.

```json
{
  "fields": [
    {"name": "heading", "label": "Heading", "type": "textinput"},
    {"name": "subheading", "label": "Subheading", "type": "textarea"},
    {"name": "background_color", "label": "Background", "type": "colorpicker", "advanced": true},
    {"name": "text_alignment", "label": "Alignment", "type": "dropinput", "advanced": true,
     "options": {"left": "Left", "center": "Center", "right": "Right"}}
  ]
}
```

**Repeater sub-fields** can also be marked as advanced. They appear in a nested collapsible section within each repeater row:

```json
{
  "name": "features",
  "type": "repeater",
  "fields": [
    {"name": "title", "label": "Title", "type": "textinput"},
    {"name": "description", "label": "Description", "type": "textarea"},
    {"name": "link_url", "label": "Link URL", "type": "textinput", "advanced": true}
  ]
}
```

**Guidelines for advanced fields:**
- Mark styling options (colors, alignment, spacing) as advanced
- Keep content fields (text, images, main links) as regular
- If a field has a sensible default that works 80%+ of the time, consider marking it advanced

### Accessing Defaults Programmatically

For testing and utility scripts, the `Component` class provides a method to extract defaults:

```php
$component_type = new Component($type_id, TRUE);

// Get only fields that have defaults defined
$defaults = $component_type->get_default_config();
// Returns: ['columns' => '3', 'alignment' => 'center', 'show_button' => true]

// Get all fields (empty values for fields without defaults)
$all_fields = $component_type->get_default_config(true);
// Returns: ['columns' => '3', 'alignment' => 'center', 'show_button' => true, 'heading' => '', 'features' => []]
```

### Available Field Types

| Type | Description | Extra Options |
|------|-------------|---------------|
| `textinput` | Single-line text | - |
| `textarea` | Multi-line text | - |
| `textbox` | Alias for textarea | - |
| `richtext` | WYSIWYG editor (Trumbowyg) | - |
| `checkboxinput` | Boolean checkbox | - |
| `dropinput` | Dropdown select | `options` |
| `radioinput` | Radio buttons | `options` |
| `dateinput` | Date picker | - |
| `timeinput` | Time picker | - |
| `datetimeinput` | Date and time | - |
| `fileinput` | File upload | - |
| `imageinput` | Image upload | - |
| `imageselector` | Image picker with gallery | `button_text`, `grid_columns`, etc. |
| `colorpicker` | Color picker with theme swatches | `max_swatches`, `sort`, etc. |
| `hiddeninput` | Hidden field | - |
| `repeater` | Repeatable field group | `fields` |

### Field Options

```json
{
    "name": "button_style",
    "label": "Button Style",
    "type": "dropinput",
    "help": "Choose the button appearance",
    "options": {
        "primary": "Primary (Blue)",
        "secondary": "Secondary (Gray)",
        "success": "Success (Green)",
        "danger": "Danger (Red)"
    }
}
```

### Repeater Fields

```json
{
    "name": "features",
    "label": "Features",
    "type": "repeater",
    "help": "Add feature items",
    "fields": [
        {"name": "icon", "label": "Icon Class", "type": "textinput"},
        {"name": "title", "label": "Title", "type": "textinput"},
        {"name": "description", "label": "Description", "type": "textarea"}
    ]
}
```

### Complete Schema Example

```json
{
    "fields": [
        {
            "name": "heading",
            "label": "Heading",
            "type": "textinput",
            "help": "Main heading text"
        },
        {
            "name": "subheading",
            "label": "Subheading",
            "type": "textarea",
            "help": "Supporting text below heading"
        },
        {
            "name": "show_button",
            "label": "Show CTA Button",
            "type": "checkboxinput"
        },
        {
            "name": "button_text",
            "label": "Button Text",
            "type": "textinput"
        },
        {
            "name": "button_url",
            "label": "Button URL",
            "type": "textinput"
        },
        {
            "name": "button_style",
            "label": "Button Style",
            "type": "dropinput",
            "options": {
                "primary": "Primary",
                "secondary": "Secondary",
                "outline-primary": "Outline"
            }
        },
        {
            "name": "features",
            "label": "Feature List",
            "type": "repeater",
            "fields": [
                {"name": "title", "label": "Title", "type": "textinput"},
                {"name": "description", "label": "Description", "type": "textarea"}
            ]
        }
    ]
}
```

---

## 8. Admin Interface

### Component Types (Superadmin)

**URL:** `/admin/admin_component_types`

- List all active component types (read-only)
- View component type details
- Component types are defined by JSON files and synced automatically

**Permission Level:** 10 (Superadmin)

**Note:** Component types cannot be created, edited, or deleted via the admin interface. The JSON definition files are the single source of truth. To add or modify component types, edit the JSON files in `/views/components/` or `/theme/{theme}/views/components/`.

### Component Instances (Admin)

**URL:** `/admin/admin_components`

- List all component instances
- Add, edit, delete instances

**Permission Level:** 5 (Admin)

### Components on Page Edit

**URL:** `/admin/admin_page?pag_page_id=X`

The page edit view includes a Components card showing:
- Components attached to this page
- Quick add/edit/delete actions
- Ordered by `pac_order`

### Component Instance Edit

**URL:** `/admin/admin_component_edit?pac_page_content_id=X`

Dynamic form based on component type:
1. Select component type (triggers page reload to show fields)
2. Configure component-specific fields from schema
3. Set page assignment (order is in advanced fields)
4. Save returns to appropriate list (page or components)

---

## Layout Controls

Component instances have per-instance layout controls: **Width**, **Height**, and **Vertical Margin**. These appear in the advanced fields section of the admin component edit form.

### Spacing Principle

Components and the layout system have distinct responsibilities:

- **Components own padding** — Internal spacing within the component (e.g., `py-4`, `p-3`) is the template's responsibility
- **The layout system owns margin** — External spacing between components is controlled by the layout wrapper via the Vertical Margin field

This separation keeps templates focused on their content while giving admins consistent control over how components are spaced on a page.

### How It Works

Layout values are stored on the component instance (`pac_max_width`, `pac_max_height`, `pac_vertical_margin`). When any layout value is set, `ComponentRenderer` wraps the template output in a lightweight `<div class="component-layout">` with CSS custom properties and data attributes:

```html
<div class="component-layout" data-maxw data-vmargin="md" style="--cl-max-width: 720px">
    <!-- Template output unchanged -->
    <section class="hero-static">
        <div class="container">...</div>
    </section>
</div>
```

When all values are NULL/default, **no wrapper is added** — zero impact on existing pages.

### Width and Height

Width and Height are plain text inputs in the advanced fields section. Empty = no restriction (NULL). Any CSS value (e.g., `720px`, `80%`) is stored directly.

### Vertical Margin

Vertical Margin is a dropdown that controls the space above and below the component. It applies the same margin to both top and bottom. There are no side margin controls — side margins are handled by the component template's container.

| Keyword | Value | Typical Use |
|---------|-------|-------------|
| Default | (none) | Uses the component type's preferred spacing from `layout_defaults` |
| None | `0` | No vertical margin |
| Small | `1rem` (16px) | Tight spacing between related components |
| Medium | `2rem` (32px) | Standard spacing between sections |
| Large | `3rem` (48px) | Generous spacing (matches Bootstrap `py-5`) |
| Extra Large | `5rem` (80px) | Maximum spacing for visual separation |

These values use `rem` units, which are standard CSS and work with any framework or plain HTML5.

### Layout Defaults

Component types can specify default layout values in their JSON definition. These pre-fill the admin fields when creating a new component instance:

```json
{
  "title": "Newsletter Signup",
  "layout_defaults": {
    "container_width": "600px",
    "vertical_margin": "md"
  },
  "config_schema": { ... }
}
```

| `layout_defaults` Key | Description |
|------------------------|-------------|
| `container_width` | Pre-fills Width field |
| `container_height` | Pre-fills Height field |
| `vertical_margin` | Pre-fills Vertical Margin dropdown (keyword) |
| `skip_wrapper` | Opts out of the layout wrapper entirely (see below) |

### Developer Opt-Out (skip_wrapper)

Component types that need full control over their own layout can set `skip_wrapper: true` in their `layout_defaults`. This skips the auto-wrapper and hides the layout fields in the admin form:

```json
{
  "title": "Custom Widget",
  "layout_defaults": {
    "skip_wrapper": true
  },
  "config_schema": { ... }
}
```

---

## Quick Reference

### Render a Component

```php
// By slug (from database)
echo ComponentRenderer::render('my-component-slug');

// By slug with config overrides
echo ComponentRenderer::render('my-component-slug', null, ['heading' => 'Override']);

// By type key (programmatic, no database instance)
echo ComponentRenderer::render(null, 'image_gallery', ['photos' => $photos]);
```

### Check Before Rendering

```php
if (ComponentRenderer::exists('optional-component')) {
    echo ComponentRenderer::render('optional-component');
}
```

### Get Config in Template

```php
$title = $component_config['title'] ?? 'Default Title';
$items = $component_config['items'] ?? [];
```

### Access Dynamic Data

```php
$posts = $component_data['posts'] ?? [];
```

### Create Logic Function

File: `/logic/components/my_logic.php`

```php
function my_logic($config) {
    // Fetch/compute data
    return ['key' => $value];
}
```

---

## 9. Legacy Content Systems

The component system replaces two older content approaches. Both are preserved for backward compatibility but are considered legacy features.

### 9.1 Legacy Body Content (`pag_body`)

Pages can store HTML directly in the `pag_body` field. This is now a legacy feature.

**Admin UI Behavior (`/admin/admin_page_edit`):**
- Content editor **only appears** when the page already has body content
- New pages do not show the content editor (use components instead)
- Field is labeled "Content (Legacy)" with migration guidance

**Admin UI Behavior (`/admin/admin_page`):**
- Warning displays when page has legacy body content: "This page has legacy body content. To use components, edit this page and remove the content there."
- "Add Component" button is **hidden** when legacy body content exists
- Warning appears even if components already exist on the page

**Migration:** To switch a page to components, edit the page and remove all body content.

### 9.2 Legacy Placeholder System

Before components, pages used placeholder markers in `pag_body` that were replaced with content from `pac_page_contents` records.

**How It Works:**
1. Page body contains placeholder: `*!**about**!*`
2. System finds `PageContent` record with matching `pac_link` = "about"
3. Placeholder is replaced with `pac_body` content

**Admin UI Behavior:**
The "Page Content (Legacy)" section in `/admin/admin_page` is **hidden** when:
- The page body contains no placeholders (`*!**slug**!*`), OR
- The page is already using the component system

### 9.3 Content Rendering Priority

The rendering logic in `Page::get_filled_content()`:

```php
// Check for components first
$components = new MultiPageContent(['page_id' => $this->key, 'components_only' => true, ...]);

if ($components->count_all() > 0) {
    // Has components - render them, ignore page body entirely
    foreach ($components as $component) {
        $output .= ComponentRenderer::render($component->get('pac_location_name'));
    }
    return $output;
}

// No components - fall back to body content (with placeholder substitution)
return $this->get_body_content();
```

**Priority order:**
1. **Components** - If any components are attached, they render and body content is ignored
2. **Body content with placeholders** - Placeholders are substituted
3. **Plain body content** - Rendered as-is

### 9.4 Migration Path

For sites using legacy content:

1. **Keep using legacy content** - It continues to work indefinitely
2. **Migrate to components:**
   - Create component instances and attach to the page
   - Remove legacy body content from the page
   - Once body content is removed, the "Add Component" button appears

---

## See Also

- [FormWriter Documentation](formwriter.md) - Repeater field details
- [Admin Pages Documentation](admin_pages.md) - Admin interface patterns
- [Implemented Spec](/specs/implemented/page_component_system.md) - Full specification
- [Vertical Margin Spec](/specs/implemented/component_vertical_margin.md) - Vertical margin system
- [Newsletter Signup Spec](/specs/implemented/newsletter_signup_component.md) - Newsletter component (first logic function example)
