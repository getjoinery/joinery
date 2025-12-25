# Creating Components from Theme Templates

This guide explains how to convert existing theme HTML sections into reusable components.

---

## Overview

Many themes include pre-built sections (hero areas, feature grids, testimonials, etc.) with hardcoded content. The component system lets you:

1. Extract these sections into templates
2. Define configurable fields
3. Allow admins to customize content without code changes

---

## Step-by-Step Process

### Step 1: Identify the Section

Find a section in your theme that should be configurable. For example, a hero section:

```html
<!-- From theme's index.php or landing page -->
<section class="hero-section bg-primary text-white py-5">
    <div class="container text-center">
        <h1>Welcome to Our Site</h1>
        <p class="lead">We help businesses grow with innovative solutions.</p>
        <a href="/contact" class="btn btn-light btn-lg">Get Started</a>
    </div>
</section>
```

### Step 2: Identify Configurable Elements

List what admins should be able to change:

| Element | Field Name | Field Type |
|---------|------------|------------|
| "Welcome to Our Site" | `heading` | textinput |
| Subtitle text | `subheading` | textarea |
| "Get Started" | `button_text` | textinput |
| Button URL | `button_url` | textinput |
| Background color | `background_color` | textinput (hex) |
| Text alignment | `alignment` | dropinput |

### Step 3: Create the Config Schema

Build the JSON schema for the component type:

```json
{
  "fields": [
    {
      "name": "heading",
      "label": "Heading",
      "type": "textinput",
      "help": "Main headline text"
    },
    {
      "name": "subheading",
      "label": "Subheading",
      "type": "textarea",
      "help": "Supporting text below headline"
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
      "name": "background_color",
      "label": "Background Color",
      "type": "textinput",
      "help": "Hex color code, e.g., #007bff"
    },
    {
      "name": "alignment",
      "label": "Text Alignment",
      "type": "dropinput",
      "options": {
        "left": "Left",
        "center": "Center",
        "right": "Right"
      }
    }
  ]
}
```

### Step 4: Create the Template File

Create `/views/components/hero_simple.php`:

```php
<?php
/**
 * Simple Hero Component
 *
 * Extracted from theme landing page.
 */

// Get config values with defaults
$heading = $component_config['heading'] ?? 'Welcome';
$subheading = $component_config['subheading'] ?? '';
$button_text = $component_config['button_text'] ?? '';
$button_url = $component_config['button_url'] ?? '#';
$background_color = $component_config['background_color'] ?? '#007bff';
$alignment = $component_config['alignment'] ?? 'center';

// Build alignment class
$align_class = 'text-' . $alignment;
?>

<section class="hero-section py-5 <?= htmlspecialchars($align_class) ?>" style="background-color: <?= htmlspecialchars($background_color) ?>; color: #fff;">
    <div class="container">
        <h1><?= htmlspecialchars($heading) ?></h1>

        <?php if ($subheading): ?>
            <p class="lead"><?= htmlspecialchars($subheading) ?></p>
        <?php endif; ?>

        <?php if ($button_text): ?>
            <a href="<?= htmlspecialchars($button_url) ?>" class="btn btn-light btn-lg">
                <?= htmlspecialchars($button_text) ?>
            </a>
        <?php endif; ?>
    </div>
</section>
```

### Step 5: Register the Component Type

**Option A: JSON Definition File (Recommended)**

Create a JSON file alongside your template:

**File:** `/views/components/hero_simple.json`
```json
{
  "title": "Simple Hero",
  "description": "Extracted from theme landing page.",
  "category": "hero",
  "icon": "bx bx-image",
  "css_framework": "bootstrap",
  "config_schema": {
    "fields": [
      {"name": "heading", "label": "Heading", "type": "textinput", "help": "Main headline text"},
      {"name": "subheading", "label": "Subheading", "type": "textarea"},
      {"name": "button_text", "label": "Button Text", "type": "textinput"},
      {"name": "button_url", "label": "Button URL", "type": "textinput"},
      {"name": "background_color", "label": "Background Color", "type": "textinput", "help": "Hex color code, e.g., #007bff"},
      {
        "name": "alignment",
        "label": "Text Alignment",
        "type": "dropinput",
        "options": {"left": "Left", "center": "Center", "right": "Right"}
      }
    ]
  }
}
```

The component type is automatically discovered during theme sync.

**Option B: Admin Interface**

1. Go to `/admin/admin_component_types` (requires superadmin)
2. Click **Add Component Type**
3. Fill in:
   - **Type Key**: `hero_simple`
   - **Title**: "Simple Hero"
   - **Category**: Hero Sections
   - **Template File**: `hero_simple.php`
   - **Config Schema**: Paste your JSON
   - **Active**: Yes
4. Save

### Step 6: Create a Component Instance

1. Go to `/admin/admin_components` or a specific page's edit view
2. Click **Add Component**
3. Select "Simple Hero" as the type
4. Fill in the configuration fields
5. Set to Published
6. Save

### Step 7: Render the Component

**By slug (standalone):**
```php
echo ComponentRenderer::render('homepage-hero');
```

**Automatic (page-attached):**
Components attached to a page render automatically when using `ComponentRenderer::get_page_components()`.

---

## Best Practices

### Naming Conventions

| Item | Convention | Example |
|------|------------|---------|
| Type Key | lowercase_snake_case | `feature_grid` |
| Template File | Same as type key + .php | `feature_grid.php` |
| Field Names | lowercase_snake_case | `button_text` |

### Template Guidelines

1. **Always escape output:**
   ```php
   <?= htmlspecialchars($heading) ?>
   ```

2. **Provide sensible defaults:**
   ```php
   $columns = $component_config['columns'] ?? '3';
   ```

3. **Handle empty values gracefully:**
   ```php
   <?php if ($subtitle): ?>
       <p><?= htmlspecialchars($subtitle) ?></p>
   <?php endif; ?>
   ```

4. **Keep templates focused:**
   - One component = one visual section
   - Don't mix unrelated functionality

5. **Use semantic HTML:**
   - `<section>` for page sections
   - `<article>` for self-contained content
   - Proper heading hierarchy

### Schema Guidelines

1. **Use clear labels:**
   ```json
   {"label": "Call-to-Action Button Text"}
   ```
   Not:
   ```json
   {"label": "CTA"}
   ```

2. **Add help text for non-obvious fields:**
   ```json
   {"help": "Enter a hex color code like #ff5733"}
   ```

3. **Use dropinput for constrained choices:**
   ```json
   {
     "type": "dropinput",
     "options": {"small": "Small", "medium": "Medium", "large": "Large"}
   }
   ```

4. **Group related fields logically** in the schema order

---

## Working with Repeaters

For sections with multiple items (features, testimonials, etc.):

**Theme HTML:**
```html
<div class="row">
    <div class="col-md-4">
        <i class="bx bx-check"></i>
        <h4>Feature One</h4>
        <p>Description of feature one.</p>
    </div>
    <div class="col-md-4">
        <i class="bx bx-star"></i>
        <h4>Feature Two</h4>
        <p>Description of feature two.</p>
    </div>
    <!-- More items... -->
</div>
```

**Schema:**
```json
{
  "fields": [
    {
      "name": "features",
      "label": "Features",
      "type": "repeater",
      "fields": [
        {"name": "icon", "label": "Icon Class", "type": "textinput"},
        {"name": "title", "label": "Title", "type": "textinput"},
        {"name": "description", "label": "Description", "type": "textarea"}
      ]
    },
    {
      "name": "columns",
      "label": "Columns",
      "type": "dropinput",
      "options": {"2": "2", "3": "3", "4": "4"}
    }
  ]
}
```

**Template:**
```php
<?php
$features = $component_config['features'] ?? [];
$columns = intval($component_config['columns'] ?? 3);
$col_class = 'col-md-' . (12 / $columns);
?>

<section class="features-section py-5">
    <div class="container">
        <div class="row">
            <?php foreach ($features as $feature): ?>
                <div class="<?= $col_class ?>">
                    <?php if (!empty($feature['icon'])): ?>
                        <i class="<?= htmlspecialchars($feature['icon']) ?> fa-3x mb-3"></i>
                    <?php endif; ?>

                    <h4><?= htmlspecialchars($feature['title'] ?? '') ?></h4>
                    <p><?= htmlspecialchars($feature['description'] ?? '') ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
```

---

## Adding Dynamic Data

For components that need database data (recent posts, events, etc.):

### Step 1: Create Logic Function

Create `/logic/components/recent_posts_logic.php`:

```php
<?php
/**
 * Recent Posts Logic Function
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
            'date' => $post->get('post_date'),
            'image' => $post->get('post_image')
        ];
    }

    return ['posts' => $result];
}
```

### Step 2: Set Logic Function in Component Type

In admin, set **Logic Function** field to: `recent_posts_logic`

### Step 3: Access Data in Template

```php
<?php
$posts = $component_data['posts'] ?? [];

foreach ($posts as $post): ?>
    <article>
        <h3><a href="<?= htmlspecialchars($post['url']) ?>">
            <?= htmlspecialchars($post['title']) ?>
        </a></h3>
        <p><?= htmlspecialchars($post['excerpt']) ?></p>
    </article>
<?php endforeach; ?>
```

---

## Theme Override Chain

Component templates follow the standard theme override:

1. `/theme/{active_theme}/views/components/{template}.php` (checked first)
2. `/views/components/{template}.php` (fallback)

This allows themes to customize component appearance without modifying base templates.

---

## Theme-Specific Components

Themes can include their own exclusive components that only work with that theme.

### File Location

```
/theme/{theme_name}/views/components/theme_hero.json
/theme/{theme_name}/views/components/theme_hero.php
```

### JSON Definition for Theme Components

```json
{
  "title": "Theme Hero",
  "description": "Hero section specific to this theme",
  "category": "hero",
  "icon": "bx bx-star",
  "css_framework": "bootstrap",
  "config_schema": {
    "fields": [
      {"name": "heading", "label": "Heading", "type": "textinput"}
    ]
  }
}
```

### CSS Framework Compatibility

The `css_framework` field controls when a component is available:

| Value | Behavior |
|-------|----------|
| `"bootstrap"` | Only active when a Bootstrap theme is used |
| `"tailwind"` | Only active when a Tailwind theme is used |
| (omitted) | Universal - works with any theme |

**Example scenarios:**

1. **Base Bootstrap component** - Use `"css_framework": "bootstrap"` in `/views/components/`
2. **Theme-specific component** - Put in theme folder with matching `css_framework`
3. **Universal component** - Omit `css_framework` (e.g., Custom HTML)

### Automatic Sync Behavior

Component types are synchronized automatically when:

- A theme is synced (via admin interface)
- A theme is activated
- A theme ZIP is uploaded and installed

During sync:
- New components are created as active (if framework matches)
- Existing components are updated with new metadata
- Components without matching templates are deactivated
- Components incompatible with the active theme's framework are deactivated
- Deleted component types are restored if their JSON file still exists

**Note:** The filesystem is the source of truth. If you delete a component type in the admin interface but the JSON file still exists, the next sync will restore it. To permanently remove a component type, delete the JSON file.

---

## Checklist: Converting a Theme Section

- [ ] Identify the HTML section to convert
- [ ] List all configurable elements
- [ ] Create JSON config schema
- [ ] Create template file in `/views/components/`
- [ ] Add component type via admin
- [ ] Test with sample content
- [ ] Verify output escaping
- [ ] Test with empty/missing values
- [ ] Create logic function if dynamic data needed

---

## See Also

- [Component System Documentation](/docs/component_system.md)
- [Component Type Library](/specs/component_type_library.md)
- [FormWriter - Repeater Fields](/docs/formwriter.md#repeater-fields)
