# Empowered Health Sidebar Components

## Overview

Add sidebar support to the empoweredhealth theme's page template using the component system. This allows pages to optionally display sidebar content (like the "Highlights" timeline and "Values" list on the reference About page).

## Reference

- **Reference site:** https://empoweredhealthtn.com/about
- **Current implementation:** `theme/empoweredhealth/views/page.php` (basic, no sidebar)

## Goals

1. Enable two-column layout when sidebar components exist
2. Create reusable sidebar component types
3. Allow admins to configure sidebar content per-page or globally
4. Match the visual style of the reference empoweredhealth site

## Component Types to Create

### 1. `sidebar_timeline`

A timeline/history list showing dates and achievements (like "Highlights").

**Template:** `theme/empoweredhealth/views/components/sidebar_timeline.php`

**JSON Definition:** `theme/empoweredhealth/views/components/sidebar_timeline.json`
```json
{
  "title": "Sidebar Timeline",
  "description": "Timeline of dates and achievements for sidebar display",
  "category": "content",
  "css_framework": "bootstrap",
  "config_schema": {
    "fields": [
      {"name": "heading", "label": "Heading", "type": "textinput", "default": "Highlights"},
      {
        "name": "items",
        "label": "Timeline Items",
        "type": "repeater",
        "fields": [
          {"name": "date", "label": "Date/Year", "type": "textinput"},
          {"name": "title", "label": "Title", "type": "textinput"},
          {"name": "subtitle", "label": "Subtitle (optional)", "type": "textinput"}
        ]
      }
    ]
  }
}
```

**Template Structure:**
```php
<div class="appointment-left sidebar-service-hr">
    <h3 class="pb-20"><?= htmlspecialchars($heading) ?></h3>
    <ul class="time-list">
        <?php foreach ($items as $item): ?>
        <li class="d-flex justify-content-between">
            <span><?= htmlspecialchars($item['date']) ?></span>
            <span><?= htmlspecialchars($item['title']) ?>
                <?php if (!empty($item['subtitle'])): ?><br><?= htmlspecialchars($item['subtitle']) ?><?php endif; ?>
            </span>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
```

### 2. `sidebar_values`

A styled list with overlay background (like "Empowered Health Values").

**Template:** `theme/empoweredhealth/views/components/sidebar_values.php`

**JSON Definition:** `theme/empoweredhealth/views/components/sidebar_values.json`
```json
{
  "title": "Sidebar Values List",
  "description": "Styled list with background overlay for sidebar",
  "category": "content",
  "css_framework": "bootstrap",
  "config_schema": {
    "fields": [
      {"name": "heading", "label": "Heading", "type": "textinput", "default": "Our Values"},
      {
        "name": "items",
        "label": "List Items",
        "type": "repeater",
        "fields": [
          {"name": "text", "label": "Item Text", "type": "textinput"}
        ]
      }
    ]
  }
}
```

**Template Structure:**
```php
<div class="offered-right relative sidebar-offered-service">
    <div class="overlay overlay-bg"></div>
    <h3 class="relative text-white"><?= htmlspecialchars($heading) ?></h3>
    <ul class="relative dep-list">
        <?php foreach ($items as $item): ?>
        <li><?= htmlspecialchars($item['text']) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
```

## Page Template Updates

### Updated `theme/empoweredhealth/views/page.php`

```php
<?php
// Theme-specific page template with empoweredhealth styling and optional sidebar

require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('page_logic.php', 'logic'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));

$page_vars = page_logic($_GET, $_POST, $page, $params);
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;
$page = $page_vars['page'];

// Check for sidebar components for this page
// Convention: sidebar components use slug pattern "sidebar-{page_slug}-{component}"
// or generic slugs like "sidebar-highlights", "sidebar-values"
$page_slug = $page->get('pag_link');
$sidebar_components = [];

// Try page-specific sidebar components first
$page_specific_slugs = [
    "sidebar-{$page_slug}-timeline",
    "sidebar-{$page_slug}-values"
];
foreach ($page_specific_slugs as $slug) {
    if (ComponentRenderer::exists($slug)) {
        $sidebar_components[] = $slug;
    }
}

// Fall back to generic sidebar components if no page-specific ones
if (empty($sidebar_components)) {
    $generic_slugs = ['sidebar-highlights', 'sidebar-values'];
    foreach ($generic_slugs as $slug) {
        if (ComponentRenderer::exists($slug)) {
            $sidebar_components[] = $slug;
        }
    }
}

$has_sidebar = !empty($sidebar_components);

$paget = new PublicPage();
$paget->public_header(array(
    'is_valid_page' => $is_valid_page,
    'title' => $page->get('pag_title')
));
?>

<!-- Banner Section -->
<section class="banner-area relative about-banner" id="home">
    <div class="overlay overlay-bg"></div>
    <div class="container">
        <div class="row d-flex align-items-center justify-content-center">
            <div class="about-content col-lg-12">
                <h1 class="text-white"><?php echo htmlspecialchars($page->get('pag_title')); ?></h1>
                <p class="text-white link-nav">
                    <a href="/">Home </a><span class="lnr lnr-arrow-right"></span>
                    <?php echo htmlspecialchars($page->get('pag_title')); ?>
                </p>
            </div>
        </div>
    </div>
</section>

<!-- Content Section -->
<section class="offered-service-area dep-offred-service">
    <div class="container">
        <div class="row offred-wrap section-gap">
            <div class="col-lg-<?= $has_sidebar ? '8' : '12' ?> offered-left">
                <?php echo $page->get_filled_content(); ?>
            </div>
            <?php if ($has_sidebar): ?>
            <div class="col-lg-4">
                <?php foreach ($sidebar_components as $slug): ?>
                    <?= ComponentRenderer::render($slug) ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php
$paget->public_footer(array('track' => TRUE));
?>
```

## Implementation Steps

1. **Create component JSON definitions**
   - `theme/empoweredhealth/views/components/sidebar_timeline.json`
   - `theme/empoweredhealth/views/components/sidebar_values.json`

2. **Create component PHP templates**
   - `theme/empoweredhealth/views/components/sidebar_timeline.php`
   - `theme/empoweredhealth/views/components/sidebar_values.php`

3. **Run component sync** to register new component types
   - Visit `/admin/admin_component_types` or run sync utility

4. **Update page.php template** with sidebar detection and rendering

5. **Create component instances** via admin
   - `/admin/admin_component_edit` - Create "sidebar-highlights" instance
   - `/admin/admin_component_edit` - Create "sidebar-values" instance
   - Configure with the actual content from the reference site

6. **Deploy to Docker server**
   - Copy new files to container
   - Verify theme syncs component types

## Content for Initial Component Instances

### sidebar-highlights (Highlights Timeline)

| Date | Title | Subtitle |
|------|-------|----------|
| 2017 | WOCN Wound Certified | |
| 2016-2019 | Home Visit Nurse Practitioner | |
| 2014-2016 | Nurse Practitioner | Blount Memorial Hospital |
| 2014 | Family Nurse Practitioner Certified | |
| 2007-2014 | Registered Nurse Certified | |

### sidebar-values (Empowered Health Values)

- Affordable and consistent healthcare
- No wait
- Easy access to the provider
- Meaningful, unrushed visits
- Treating people not diagnoses
- Expert partners in diverse practices
- Consistent contact and follow-up
- Deep conversation
- Stress relief for optimal health

## Alternative Approaches Considered

### Page-Level Sidebar Field
Add a `pag_sidebar_content` field to pages table. Rejected because:
- Less reusable
- Requires schema change
- Doesn't leverage existing component system

### Single Combined Sidebar Component
One component with multiple section types. Rejected because:
- More complex config schema
- Less flexible for different sidebar layouts
- Harder to reorder/hide individual sections

## Testing

1. Verify page renders with full-width when no sidebar components exist
2. Verify two-column layout when sidebar components are present
3. Verify page-specific sidebar slugs take precedence over generic ones
4. Verify visual match with reference site styling
5. Test on mobile - sidebar should stack below main content

## Dependencies

- Component system must be functional
- empoweredhealth theme CSS must include sidebar styles (`.sidebar-service-hr`, `.sidebar-offered-service`, etc.)
