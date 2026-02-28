# Creating Components from Theme Templates

This guide explains how to convert existing theme HTML sections into reusable components.

---

## Overview

Many themes include pre-built sections (hero areas, feature grids, testimonials, etc.) with hardcoded content. The component system lets you:

1. Extract these sections into templates
2. Define configurable fields
3. Allow admins to customize content without code changes

---

## IMPORTANT: Presentation-Only Components

**Components must be presentation-only.** Do NOT create components that require backend functionality to work properly.

### Components to SKIP

Do not create components for:

- **Contact forms** - Require form submission handlers, email sending, validation
- **Comment forms** - Require database storage, moderation systems
- **Search forms** - Require search indexing and results handling
- **Login/registration forms** - Require authentication systems
- **E-commerce forms** (add to cart, checkout) - Require payment processing
- **File upload forms** - Require server-side file handling
- **Any form that submits data** - Unless it links to an existing handler

### Newsletter Components - Special Case

Newsletter/subscription sections are common in themes. Instead of embedding a form that requires a subscription handler, **convert these to call-to-action links** that point to the existing list signup page:

**Original theme HTML (with embedded form):**
```html
<section class="newsletter-area">
    <h3>Subscribe to Newsletter</h3>
    <form action="/subscribe" method="POST">
        <input type="email" name="email" placeholder="Enter email">
        <button type="submit">Subscribe</button>
    </form>
</section>
```

**Convert to presentation-only (with link):**
```php
<?php
$title = $component_config['title'] ?? 'Subscribe to Newsletter';
$signup_url = $component_config['signup_url'] ?? '/list/newsletter';
$button_text = $component_config['button_text'] ?? 'Subscribe Now';
?>

<section class="newsletter-area">
    <h3><?php echo htmlspecialchars($title); ?></h3>
    <a href="<?php echo htmlspecialchars($signup_url); ?>" class="btn btn-primary">
        <?php echo htmlspecialchars($button_text); ?>
    </a>
</section>
```

**JSON schema for newsletter CTA:**
```json
{
  "fields": [
    {"name": "title", "label": "Title", "type": "textinput", "default": "Subscribe to Newsletter"},
    {"name": "signup_url", "label": "Signup Page URL", "type": "textinput", "help": "URL to mailing list signup page (e.g., /list/newsletter)", "default": "/list/newsletter"},
    {"name": "button_text", "label": "Button Text", "type": "textinput", "default": "Subscribe Now"}
  ]
}
```

The existing `/list/{slug}` page handles all subscription logic including:
- User registration (for new visitors)
- Email validation
- Anti-spam protection
- Subscribe/unsubscribe functionality

### Safe Component Types

These are generally safe to create as presentation-only:

- **Hero sections** - Static text, images, and CTA links
- **Feature grids** - Icon + text cards
- **Testimonials** - Quote displays
- **Team/staff sections** - Photo + bio cards
- **Pricing tables** - Static pricing displays with CTA links
- **FAQ accordions** - Static Q&A content
- **Gallery sections** - Image displays
- **Contact info** - Address, phone, email display (not forms)
- **Social links** - Icon links to social profiles
- **Page titles/breadcrumbs** - Navigation displays
- **Call-to-action sections** - Text + link buttons

---

## Development Tools

### Theme Source Files

Raw HTML theme files are available at `/theme-sources/` for reference:
- Browse at: `https://[yoursite]/theme-sources/`
- Contains: canvas, falcon, linka, sassa themes
- Use these to identify sections to extract

### Component Preview Utility

Test components instantly without database setup:
```
/utils/component_preview              - All components for current theme
/utils/component_preview?type=hero    - Single component
/utils/component_preview?theme=falcon - Test in specific theme
/utils/component_preview?theme=all    - View ALL components across ALL themes
/utils/component_preview?config       - Show generated config data
/utils/component_preview?paths        - Show template file paths
```

**Note:** Requires admin login (permission level 5+).

This utility auto-generates placeholder data based on your config schema, letting you iterate quickly on templates. Each component card shows:
- Category and CSS Framework
- Theme name (which theme the component belongs to)
- "Solo" button to view just that component

### Programmatic Default Access

For utility scripts and automated testing, you can access schema defaults programmatically:

```php
require_once(PathHelper::getIncludePath('data/components_class.php'));

$component_type = new Component($type_id, TRUE);

// Get only fields with defaults defined
$defaults = $component_type->get_default_config();

// Get all fields (empty values for those without defaults)
$all_fields = $component_type->get_default_config(true);
```

This is useful for:
- Testing component templates with realistic data
- Pre-populating component instances in migration scripts
- Validating that all required fields have sensible defaults

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

Build the JSON schema for the component type. **CRITICAL: Add `default` values that exactly match the original theme HTML.** This ensures:
- The component preview utility shows the component exactly as it appears in the reference
- Admins see realistic starting values when creating new instances
- The component is immediately usable without configuration

**Extract defaults directly from the reference HTML:**

```html
<!-- Original theme HTML -->
<section class="hero-section bg-primary text-white py-5">
    <div class="container text-center">
        <h1>Welcome to Our Site</h1>
        <p class="lead">We help businesses grow with innovative solutions.</p>
        <a href="/contact" class="btn btn-light btn-lg">Get Started</a>
    </div>
</section>
```

**JSON schema with defaults matching the reference:**

```json
{
  "fields": [
    {
      "name": "heading",
      "label": "Heading",
      "type": "textinput",
      "help": "Main headline text",
      "default": "Welcome to Our Site"
    },
    {
      "name": "subheading",
      "label": "Subheading",
      "type": "textarea",
      "help": "Supporting text below headline",
      "default": "We help businesses grow with innovative solutions."
    },
    {
      "name": "button_text",
      "label": "Button Text",
      "type": "textinput",
      "default": "Get Started"
    },
    {
      "name": "button_url",
      "label": "Button URL",
      "type": "textinput",
      "default": "/contact"
    },
    {
      "name": "background_color",
      "label": "Background Color",
      "type": "textinput",
      "help": "Hex color code, e.g., #007bff",
      "default": "#007bff"
    },
    {
      "name": "alignment",
      "label": "Text Alignment",
      "type": "dropinput",
      "default": "center",
      "options": {
        "left": "Left",
        "center": "Center",
        "right": "Right"
      }
    }
  ]
}
```

**Rule: Every field should have a default that makes the component render identically to the reference HTML.** This includes:
- Text content (headings, paragraphs, button labels)
- Colors (background, text, accents)
- Layout options (alignment, columns, spacing)
- Image URLs (see Image Defaults below)

**Image Defaults - IMPORTANT:**
- Use the **exact same image paths** from the reference HTML files
- Link directly to theme assets - do NOT copy or move images
- Image paths should be relative to document root: `/theme/themename/assets/img/...`
- Check the original HTML to find the correct image path

Example from reference HTML:
```html
<img src="assets/img/home-one/main-blog-img/1.jpg" alt="...">
```

Becomes this default in JSON (with full theme path):
```json
{"name": "image", "type": "textinput", "default": "/theme/linka-reference/assets/images/home-one/main-blog-img/1.jpg"}
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

Create a JSON file alongside your template:

**File:** `/views/components/hero_simple.json`
```json
{
  "title": "Simple Hero",
  "description": "Extracted from theme landing page.",
  "category": "hero",
  "css_framework": "bootstrap",
  "config_schema": {
    "fields": [
      {"name": "heading", "label": "Heading", "type": "textinput", "help": "Main headline text", "default": "Welcome to Our Site"},
      {"name": "subheading", "label": "Subheading", "type": "textarea", "default": "We help businesses grow with innovative solutions."},
      {"name": "button_text", "label": "Button Text", "type": "textinput", "default": "Get Started"},
      {"name": "button_url", "label": "Button URL", "type": "textinput", "default": "/contact"},
      {"name": "background_color", "label": "Background Color", "type": "textinput", "help": "Hex color code, e.g., #007bff", "default": "#007bff"},
      {
        "name": "alignment",
        "label": "Text Alignment",
        "type": "dropinput",
        "default": "center",
        "options": {"left": "Left", "center": "Center", "right": "Right"}
      }
    ]
  }
}
```

**Note:** Every field has a default that matches the original theme HTML exactly. The component preview will now render identically to the reference.

The component type is automatically discovered during theme sync. JSON files are the single source of truth - component types cannot be created via the admin interface.

### Step 5b: Set File Permissions (Development Servers)

**IMPORTANT:** On development servers, newly created files may have restrictive permissions that prevent the web server from reading them. After creating component files, ensure proper permissions:

```bash
chmod 666 /path/to/theme/views/components/your_component.php
chmod 666 /path/to/theme/views/components/your_component.json
```

If the component preview shows "Template file not found" but the file exists, this is almost always a permissions issue.

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

**By slug with overrides:**
```php
echo ComponentRenderer::render('homepage-hero', null, ['heading' => 'Custom Title']);
```

**By type key (programmatic, no database instance):**
```php
// Useful for components rendered from code with runtime data
echo ComponentRenderer::render(null, 'image_gallery', [
    'photos' => $entity->get_photos(),
    'primary_file_id' => $entity->get('evt_fil_file_id'),
]);
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

6. **Container width and max height are controlled automatically by the renderer.** Templates should continue using `<div class="container">` as normal. Admins can override the width/height per component instance without template changes. If a component type needs to manage its own layout entirely, set `"skip_wrapper": true` in the type's `layout_defaults` JSON -- the renderer will skip auto-wrapping and the template can use `$container_class`, `$container_style`, and `$max_height_style` variables directly.

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

4. **Set defaults for ALL fields to match reference HTML:**
   ```json
   {"name": "heading", "type": "textinput", "default": "The City of London Wants to Have It"},
   {"name": "columns", "type": "dropinput", "default": "3", "options": {...}},
   {"name": "background_color", "type": "textinput", "default": "#1a1a2e"}
   ```
   - **REQUIRED:** Every field must have a default that matches the original theme
   - Extract exact text, colors, and values from the reference HTML
   - The component preview must render identically to the theme source
   - For images, use actual theme asset paths: `/theme/themename/assets/img/...`

5. **Group related fields logically** in the schema order

6. **Mark rarely-changed fields as advanced:**
   ```json
   {"name": "icon_color", "type": "colorpicker", "default": "#007bff", "advanced": true}
   ```

---

## Advanced Fields

Fields that users rarely need to change can be marked as `"advanced": true`. These fields are hidden behind a collapsible "Show advanced fields" link, keeping the form cleaner.

### Usage

Add `"advanced": true` to any field definition:

```json
{
  "fields": [
    {"name": "heading", "label": "Heading", "type": "textinput"},
    {"name": "subheading", "label": "Subheading", "type": "textarea"},
    {"name": "background_color", "label": "Background Color", "type": "colorpicker", "advanced": true},
    {"name": "text_alignment", "label": "Alignment", "type": "dropinput", "advanced": true, "options": {...}}
  ]
}
```

### Advanced Fields in Repeaters

Repeater sub-fields can also be marked as advanced. They appear in a nested collapsible section within each repeater row:

```json
{
  "name": "features",
  "label": "Features",
  "type": "repeater",
  "fields": [
    {"name": "title", "label": "Title", "type": "textinput"},
    {"name": "description", "label": "Description", "type": "textarea"},
    {"name": "link_url", "label": "Link URL", "type": "textinput", "advanced": true},
    {"name": "link_text", "label": "Link Text", "type": "textinput", "advanced": true}
  ]
}
```

### Common Advanced vs Regular Fields

| Typically Regular (shown by default) | Typically Advanced (hidden by default) |
|-------------------------------------|---------------------------------------|
| Headings and titles | Colors (background, text, accent) |
| Body text and descriptions | Icon classes and icon styles |
| Primary images | CSS classes and custom styles |
| Button text | Alignment and layout options |
| Main URLs/links | Animation settings |
| Repeater content items | Spacing and padding options |
| Enable/disable toggles | SEO fields (aria labels, etc.) |
| | Slugs and internal identifiers |

**Note:** The component slug field is always treated as advanced in the admin interface since most page-attached components don't need one.

### Guidelines

- **Regular fields**: Content that changes per-instance (text, images, main links)
- **Advanced fields**: Styling and configuration that usually keeps defaults (colors, icons, layout options)
- Keep the majority of fields as regular - only hide truly optional customization
- If a field has a sensible default that works 80% of the time, consider marking it advanced

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
      "default": "3",
      "options": {"2": "2", "3": "3", "4": "4"}
    }
  ]
}
```

**Note:** Repeater fields don't support defaults for their nested content, but the parent repeater will start empty. Defaults work well for simple fields like the `columns` dropdown above.

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

- [ ] Identify the HTML section to convert (browse `/theme-sources/` for reference)
- [ ] **Verify it's presentation-only** (no forms requiring backend handlers - see "Components to SKIP" above)
- [ ] List all configurable elements
- [ ] Create JSON definition file in `/views/components/` or `/theme/{theme}/views/components/`
- [ ] **Add `default` values for EVERY field matching the reference HTML exactly**
- [ ] Create PHP template file in same directory
- [ ] **Set file permissions** (`chmod 666`) if on development server
- [ ] Test with `/utils/component_preview?type=your_component`
- [ ] **Verify preview looks identical to reference HTML** (compare side-by-side)
- [ ] Verify output escaping
- [ ] Test with empty/missing values (use `?config` to see generated data)
- [ ] Create logic function if dynamic data needed
- [ ] Test in different themes with `?theme=` parameter

---

## See Also

- [Component System Documentation](/docs/component_system.md)
- [Component Preview Utility](/specs/implemented/component_preview_utility.md)
- [Component Type Library](/specs/component_type_library.md)
- [Theme Integration Instructions](/docs/theme_integration_instructions.md)
- [FormWriter - Repeater Fields](/docs/formwriter.md#repeater-fields)
