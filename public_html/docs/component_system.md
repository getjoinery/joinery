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

### Render Component Object

```php
// When you already have a PageContent object
echo ComponentRenderer::render_component($component_instance);
```

### Get Page Components

```php
// Get all published components for a page
$components = ComponentRenderer::get_page_components($page_id);

foreach ($components as $component) {
    echo ComponentRenderer::render_component($component);
}

// Include unpublished (for admin preview)
$all_components = ComponentRenderer::get_page_components($page_id, false);
```

### Render Multiple by Slug

```php
// Render several components in sequence
echo ComponentRenderer::render_multiple(['hero', 'features', 'testimonials']);
```

### Check if Renderable

```php
// Returns true only if: exists, is a component, and is published
if (ComponentRenderer::exists('promo-banner')) {
    echo ComponentRenderer::render('promo-banner');
}
```

### Debug Output

When `debug` setting is enabled, ComponentRenderer outputs HTML comments explaining why a component didn't render:

```html
<!-- ComponentRenderer (slug: missing-component): Component not found -->
<!-- ComponentRenderer (slug: draft-hero): Component exists but is not published -->
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
| `com_icon` | varchar(64) | CSS icon class |
| `com_template_file` | varchar(255) | Template filename |
| `com_config_schema` | json | Field definitions |
| `com_logic_function` | varchar(255) | Optional logic function name |
| `com_is_active` | bool | Whether type is available |
| `com_requires_plugin` | varchar(64) | Required plugin name |
| `com_css_framework` | varchar(32) | Required CSS framework (e.g., `bootstrap`) |
| `com_order` | int2 | Sort order |

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
  "icon": "bx bx-image",
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
| `icon` | No | CSS icon class (e.g., `bx bx-image`) |
| `css_framework` | No | Required framework: `bootstrap`, `tailwind`, or omit for universal |
| `config_schema` | No | Field definitions object |

Component types are automatically discovered during theme sync operations.

**Method 2: Admin Interface**

1. Go to `/admin/admin_component_types`
2. Click "Add Component Type"
3. Fill in:
   - **Type Key** - Unique identifier (lowercase, underscores)
   - **Title** - Human-readable name
   - **Template File** - Filename in `views/components/`
   - **Config Schema** - JSON defining editable fields
4. Save and activate

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
| `pac_is_published` | bool | Whether to render |

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

// Published components only
$published = new MultiPageContent(
    ['components_only' => true, 'published' => true, 'deleted' => false],
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

In the component type's config:
- Set **Logic Function** field to the function name (e.g., `recent_posts_logic`)
- The corresponding file `logic/components/recent_posts_logic.php` must exist

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
            "help": "Optional help text"
        }
    ]
}
```

### Available Field Types

| Type | Description | Extra Options |
|------|-------------|---------------|
| `textinput` | Single-line text | - |
| `textarea` | Multi-line text | - |
| `textbox` | Alias for textarea | - |
| `checkboxinput` | Boolean checkbox | - |
| `dropinput` | Dropdown select | `options` |
| `radioinput` | Radio buttons | `options` |
| `dateinput` | Date picker | - |
| `timeinput` | Time picker | - |
| `datetimeinput` | Date and time | - |
| `fileinput` | File upload | - |
| `imageinput` | Image upload | - |
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

- List all component types
- Filter by active/all
- Add, edit, delete component types
- Edit config schema JSON

**Permission Level:** 10 (Superadmin)

### Component Instances (Admin)

**URL:** `/admin/admin_components`

- List all component instances
- Filter by published/all
- Toggle published status inline
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
3. Set publishing options (published, page assignment, order)
4. Save returns to appropriate list (page or components)

---

## Quick Reference

### Render a Component

```php
echo ComponentRenderer::render('my-component-slug');
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

## See Also

- [FormWriter Documentation](formwriter.md) - Repeater field details
- [Admin Pages Documentation](admin_pages.md) - Admin interface patterns
- [Implemented Spec](/specs/implemented/page_component_system.md) - Full specification
