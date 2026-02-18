# Component Layout Controls (Container Width & Max Height)

## Summary

Replace the inconsistent per-component "Wrap in Container" checkbox with universal layout controls on all component instances: container width (max-width) and max height. Both are implemented via a single lightweight wrapper div with CSS custom properties, requiring zero changes to existing templates by default.

## Problem

The current container behavior is broken and inconsistent:

- Only `custom_html` has a configurable "Wrap in Container" checkbox (binary on/off)
- All other components (`hero_static`, `cta_banner`, `feature_grid`, `page_title`) hardcode `<div class="container">` with no user control
- There is no way to make a component narrower (e.g., for reading-focused text) or wider
- There is no way to constrain a component's height per instance
- Theme-specific components each handle containers and spacing independently with no consistency

## Solution

### How It Works

ComponentRenderer wraps each component's output in a lightweight div that uses CSS custom properties to override the inner template's container width and max height:

```html
<!-- Renderer adds this wrapper (only when non-default settings are used) -->
<div class="component-layout" data-maxw data-maxh style="--cl-max-width: 720px; --cl-max-height: 400px">

    <!-- Template output is completely unchanged -->
    <section class="hero-static" style="background: blue;">
        <div class="container">
            ...content...
        </div>
    </section>

</div>
```

Three CSS rules make the inner elements respect these properties:

```css
/* Width: override inner container (preserves full-width backgrounds) */
.component-layout[data-maxw] .container,
.component-layout[data-maxw] .container-fluid,
.component-layout[data-maxw] .container-lg,
.component-layout[data-maxw] .container-xl {
    max-width: var(--cl-max-width);
}

/* Width fallback: constrain outermost element when no container exists */
.component-layout[data-maxw]:not(:has(.container, .container-fluid, .container-lg, .container-xl)) > :first-child {
    max-width: var(--cl-max-width);
    margin-left: auto;
    margin-right: auto;
}

/* Height: constrain outermost template element */
.component-layout[data-maxh] > :first-child {
    max-height: var(--cl-max-height);
    overflow: hidden;
}
```

**Both width and height use `data-` attributes** (`data-maxw`, `data-maxh`) to scope their CSS rules. This prevents a critical bug: without scoping, `var(--cl-max-width)` resolves to `unset` when the property isn't defined (e.g., when only height is set), which would reset `max-width` to `none` and break the container.

**The width fallback rule** uses `:has()` to detect templates without any Bootstrap container div. When no container exists, it constrains the outermost element directly (including backgrounds). This covers widget/card components like `linka_featured_card` that don't use the container pattern. `:has()` is supported in Chrome 105+, Firefox 121+, Safari 15.4+.

**Key properties of this approach:**
- **Zero template changes** for the default path -- existing templates work as-is
- Full-width backgrounds are preserved for templates with a container (the outer wrapper doesn't constrain them; only the inner `.container` is narrowed)
- Templates without a container are constrained directly via the fallback rule
- The wrapper targets `.container` inside the template via CSS, not HTML parsing
- When both settings are "default", **no wrapper is added at all** -- zero impact on existing pages
- CSS specificity of `.component-layout[data-maxw] .container` naturally overrides Bootstrap's `.container` media queries
- `overflow: hidden` on max-height cleanly crops content that exceeds the limit (appropriate for heroes, banners, media sections)

### Layout Defaults in Component Type JSON

Each component type's JSON definition specifies default layout settings. When an admin creates a new instance, the layout dropdowns prefill from these defaults.

```json
{
  "title": "Hero Static",
  "category": "hero",
  "css_framework": "bootstrap",
  "layout_defaults": {
    "container_width": "standard",
    "max_height": "default"
  },
  "config_schema": { ... }
}
```

**All component types must include `layout_defaults`.** This makes the intended layout explicit and ensures the admin form always shows sensible starting values. The defaults are stored in `com_layout_defaults` (JSON column) on the `com_components` table, synced from the JSON file during theme sync.

When creating a new component instance:
1. Admin selects a component type
2. The layout dropdowns prefill from the type's `layout_defaults`
3. Admin can override per-instance as needed

When editing an existing instance, the saved values are shown (not the type defaults).

### No Size Restriction (Per-Instance Opt-Out)

Each component instance has a "No Size Restriction" checkbox in the admin edit form. When checked, the renderer skips the auto-wrapper entirely and passes the layout values to the template as variables instead.

This lets an admin decide per-instance: "this particular hero should manage its own container placement." A template that supports this uses the provided variables:

```php
<?php if ($container_class): ?>
<div class="<?= $container_class ?>" <?php if ($container_style): ?>style="<?= $container_style ?>"<?php endif; ?>>
<?php endif; ?>
    ...content...
<?php if ($container_class): ?>
</div>
<?php endif; ?>
```

When `no_size_restriction` is off (the default), the template doesn't need to do anything -- the wrapper handles it automatically.

When `no_size_restriction` is on, the Container Width and Max Height dropdowns still appear and still set the template variables. But the auto-wrapper is skipped, so the settings only take effect if the template uses the variables. Templates that ignore them simply render with no layout constraints at all.

---

## Container Width

### Admin Dropdown

A dropdown on every component instance in the admin edit form. This is a core field on `pac_page_contents` (like "Page" and "Order"), not a per-component-type config schema field.

### Width Options

| Value | Label | `--cl-max-width` |
|---|---|---|
| `standard` | Standard | _(no wrapper -- existing `.container` behavior)_ |
| `narrow` | Narrow (Reading Width) | `720px` |
| `wide` | Wide | `1320px` |
| `full` | Full Size | `none` |
| `custom` | Custom Width | _(user-specified CSS value)_ |

When "Custom Width" is selected, a text input appears where the user enters a CSS value (e.g., `600px`, `80%`, `50rem`).

**"Standard"** adds no wrapper and changes nothing -- existing behavior is preserved exactly.

**"Full Size"** sets `max-width: none` on the inner `.container`, which removes Bootstrap's width constraint. The `.container` still provides its default horizontal padding (gutters), so content isn't jammed against viewport edges.

---

## Max Height

### Admin Dropdown

A second dropdown alongside container width.

### Height Options

| Value | Label | `--cl-max-height` |
|---|---|---|
| `default` | Default | _(no constraint -- template's natural height)_ |
| `short` | Short | `200px` |
| `medium` | Medium | `400px` |
| `tall` | Tall | `600px` |
| `viewport` | Full Viewport | `100vh` |
| `custom` | Custom Height | _(user-specified CSS value)_ |

When "Custom Height" is selected, a text input appears where the user enters a CSS value (e.g., `300px`, `50vh`).

**"Default"** adds no height constraint. The component renders at its natural height.

**Other values** set `max-height` and `overflow: hidden` on the template's outermost element (typically the `<section>` tag). Content that exceeds the limit is cropped. This is most useful for hero sections, banners, and media blocks where you want a fixed visual height regardless of content.

---

## Wrapper Logic

The renderer only adds the wrapper div when at least one setting is non-default:

| Container Width | Max Height | Wrapper Added? |
|---|---|---|
| `standard` | `default` | No -- zero impact |
| `narrow` | `default` | Yes -- `--cl-max-width` only |
| `standard` | `medium` | Yes -- `--cl-max-height` only |
| `narrow` | `tall` | Yes -- both properties |
| any | any (`pac_no_size_restriction = true`) | No -- template handles it |

### Wrapper HTML Examples

**Width only:**
```html
<div class="component-layout" data-maxw style="--cl-max-width: 720px">
    ...template output...
</div>
```

**Height only:**
```html
<div class="component-layout" data-maxh style="--cl-max-height: 400px">
    ...template output...
</div>
```

**Both:**
```html
<div class="component-layout" data-maxw data-maxh style="--cl-max-width: 720px; --cl-max-height: 400px">
    ...template output...
</div>
```

The `data-maxw` and `data-maxh` attributes scope the CSS rules so they only fire when their respective property is defined. Without this, `var(--cl-max-width)` would resolve to `unset` when only height is set, breaking the container's default width.

---

## Database Changes

### com_components -- New Column

Add to `Component` class `$field_specifications`:

| Column | Type | Default | Description |
|---|---|---|---|
| `com_layout_defaults` | `json` | `NULL` | Default layout settings from JSON definition (container_width, max_height) |

Synced automatically from the `layout_defaults` property in JSON definition files during theme sync.

### pac_page_contents -- New Columns

Add to `PageContent` class `$field_specifications`:

| Column | Type | Default | Description |
|---|---|---|---|
| `pac_container_width` | `varchar(20)` | `'standard'` | Container width preset |
| `pac_container_custom_width` | `varchar(50)` | `NULL` | Custom CSS max-width value |
| `pac_max_height` | `varchar(20)` | `'default'` | Max height preset |
| `pac_max_height_custom` | `varchar(50)` | `NULL` | Custom CSS max-height value |
| `pac_no_size_restriction` | `boolean` | `false` | When true, renderer skips auto-wrapping; layout values passed as template variables instead |

---

## File Changes

### ComponentRenderer.php

**1. Add layout variable computation before template rendering:**

In `render_component()`, before `require($template_path)`, compute and expose layout variables:

```php
// Layout variables for templates that opt out of auto-wrapping
$container_width_setting = $component_instance->get('pac_container_width') ?: 'standard';
$container_custom = $component_instance->get('pac_container_custom_width') ?: '';
$max_height_setting = $component_instance->get('pac_max_height') ?: 'default';
$max_height_custom = $component_instance->get('pac_max_height_custom') ?: '';

$layout_vars = self::get_layout_vars($container_width_setting, $container_custom, $max_height_setting, $max_height_custom);
$container_class = $layout_vars['container_class'];
$container_style = $layout_vars['container_style'];
$max_height_style = $layout_vars['max_height_style'];

// Check if this instance opts out of auto-wrapping
$no_size_restriction = (bool) $component_instance->get('pac_no_size_restriction');
```

**2. After template rendering, conditionally wrap output:**

```php
$html = ob_get_clean();

if (!$no_size_restriction && trim($html) !== '') {
    $html = self::wrap_with_layout($html, $layout_vars);
}

return $html;
```

**3. New static helper methods:**

```php
protected static function get_layout_vars($width, $custom_width, $max_height, $custom_max_height) {
    $vars = [
        'container_class' => 'container',
        'container_style' => '',
        'max_height_style' => '',
        'cl_max_width' => null,
        'cl_max_height' => null,
    ];

    // Container width
    switch ($width) {
        case 'narrow':
            $vars['container_class'] = 'container';
            $vars['container_style'] = 'max-width:720px';
            $vars['cl_max_width'] = '720px';
            break;
        case 'wide':
            $vars['container_class'] = 'container-xl';
            $vars['container_style'] = '';
            $vars['cl_max_width'] = '1320px';
            break;
        case 'full':
            $vars['container_class'] = '';
            $vars['container_style'] = '';
            $vars['cl_max_width'] = 'none';
            break;
        case 'custom':
            $vars['container_class'] = 'container';
            $vars['container_style'] = 'max-width:' . $custom_width;
            $vars['cl_max_width'] = $custom_width;
            break;
        // 'standard' -- defaults are correct
    }

    // Max height
    $height_map = [
        'short' => '200px',
        'medium' => '400px',
        'tall' => '600px',
        'viewport' => '100vh',
    ];
    if (isset($height_map[$max_height])) {
        $vars['cl_max_height'] = $height_map[$max_height];
        $vars['max_height_style'] = 'max-height:' . $height_map[$max_height] . ';overflow:hidden';
    } elseif ($max_height === 'custom' && $custom_max_height) {
        $vars['cl_max_height'] = $custom_max_height;
        $vars['max_height_style'] = 'max-height:' . $custom_max_height . ';overflow:hidden';
    }
    // 'default' -- cl_max_height stays null

    return $vars;
}

protected static function wrap_with_layout($html, $layout_vars) {
    $has_width = ($layout_vars['cl_max_width'] !== null);
    $has_height = ($layout_vars['cl_max_height'] !== null);

    // No wrapper needed when both are default
    if (!$has_width && !$has_height) {
        return $html;
    }

    // Build wrapper attributes
    $styles = [];
    $attrs = 'class="component-layout"';

    if ($has_width) {
        $attrs .= ' data-maxw';
        $styles[] = '--cl-max-width: ' . $layout_vars['cl_max_width'];
    }
    if ($has_height) {
        $attrs .= ' data-maxh';
        $styles[] = '--cl-max-height: ' . $layout_vars['cl_max_height'];
    }

    if (!empty($styles)) {
        $attrs .= ' style="' . implode('; ', $styles) . '"';
    }

    return '<div ' . $attrs . '>' . "\n" . $html . '</div>' . "\n";
}
```

### CSS (theme stylesheet or global)

Add these rules to the theme's CSS or a global component stylesheet:

```css
/* Component Layout Controls */

/* Width: override inner container (preserves full-width backgrounds) */
.component-layout[data-maxw] .container,
.component-layout[data-maxw] .container-fluid,
.component-layout[data-maxw] .container-lg,
.component-layout[data-maxw] .container-xl {
    max-width: var(--cl-max-width);
}

/* Width fallback: constrain outermost element when no container exists */
.component-layout[data-maxw]:not(:has(.container, .container-fluid, .container-lg, .container-xl)) > :first-child {
    max-width: var(--cl-max-width);
    margin-left: auto;
    margin-right: auto;
}

/* Height: constrain outermost template element */
.component-layout[data-maxh] > :first-child {
    max-height: var(--cl-max-height);
    overflow: hidden;
}
```

These three rules are the entire CSS footprint of this feature.

### admin_component_edit.php

Add layout fields to the core fields section (near Page and Order). These appear for ALL component types, not in the dynamic config schema section.

When creating a new instance, prefill from the component type's layout defaults:

```php
// --- Layout section ---

// Get layout defaults from component type (for new instances)
$layout_defaults = [];
if ($component_type) {
    $layout_defaults = json_decode($component_type->get('com_layout_defaults') ?: '{}', true) ?: [];
}
$is_new = !$page_content->key;

// For new instances, use type defaults; for existing, use saved values
if ($is_new) {
    $page_content->set('pac_container_width', $layout_defaults['container_width'] ?? 'standard');
    $page_content->set('pac_max_height', $layout_defaults['max_height'] ?? 'default');
}

// Container width
$width_options = [
    'standard' => 'Standard',
    'narrow'   => 'Narrow (Reading Width)',
    'wide'     => 'Wide',
    'full'     => 'Full Size',
    'custom'   => 'Custom Width'
];
$formwriter->dropinput('pac_container_width', 'Container Width', $width_options);
$formwriter->textinput('pac_container_custom_width', 'Custom Max Width',
    ['help' => 'CSS value, e.g. 600px, 80%, 50rem']);

// Max height
$height_options = [
    'default'   => 'Default',
    'short'     => 'Short',
    'medium'    => 'Medium',
    'tall'      => 'Tall',
    'viewport'  => 'Full Viewport',
    'custom'    => 'Custom Height'
];
$formwriter->dropinput('pac_max_height', 'Max Height', $height_options);
$formwriter->textinput('pac_max_height_custom', 'Custom Max Height',
    ['help' => 'CSS value, e.g. 300px, 50vh']);

// Opt-out
$formwriter->checkboxinput('pac_no_size_restriction', 'No Size Restriction',
    ['help' => 'Skip automatic layout wrapping. The template manages its own container and padding.']);
```

JavaScript to show/hide custom fields:

```javascript
function toggleCustomField(selectId, customId) {
    var select = document.getElementById(selectId);
    var customRow = document.getElementById(customId).closest('.mb-3');
    function update() {
        customRow.style.display = select.value === 'custom' ? '' : 'none';
    }
    select.addEventListener('change', update);
    update(); // initial state
}
toggleCustomField('pac_container_width', 'pac_container_custom_width');
toggleCustomField('pac_max_height', 'pac_max_height_custom');
```

These layout fields should be in an "advanced" or collapsible section of the form so they don't clutter the main editing experience.

### PageContent Data Class (page_contents_class.php)

Add four new fields to `$field_specifications`, `$fields`, and `$initial_default_values`:

```php
'pac_container_width' => array('type' => 'varchar(20)', 'is_nullable' => false, 'default' => 'standard'),
'pac_container_custom_width' => array('type' => 'varchar(50)', 'is_nullable' => true),
'pac_max_height' => array('type' => 'varchar(20)', 'is_nullable' => false, 'default' => 'default'),
'pac_max_height_custom' => array('type' => 'varchar(50)', 'is_nullable' => true),
'pac_no_size_restriction' => array('type' => 'boolean', 'is_nullable' => false, 'default' => false),
```

### custom_html.json

Remove the `container` field from config_schema. Container width is now handled universally by the layout system. Add `layout_defaults`.

### All Component Type JSON Files

Add `layout_defaults` to every JSON definition. These are the defaults for all existing components:

**Base components (`views/components/`):**

| File | `container_width` | `max_height` | Notes |
|---|---|---|---|
| `hero_static.json` | `standard` | `default` | Height controlled by its own `height` config field |
| `cta_banner.json` | `standard` | `default` | |
| `feature_grid.json` | `standard` | `default` | |
| `page_title.json` | `standard` | `default` | |
| `custom_html.json` | `standard` | `default` | |

**Linka-reference (`theme/linka-reference/views/components/`):**

| File | `container_width` | `max_height` | Notes |
|---|---|---|---|
| `linka_hero.json` | `standard` | `default` | Uses container-fluid internally |
| `linka_featured_card.json` | `standard` | `default` | Widget/card fragment |
| `linka_featured_grid.json` | `standard` | `default` | |
| `linka_editor_choice.json` | `standard` | `default` | |
| `linka_inspiration.json` | `standard` | `default` | |
| `linka_contact_info.json` | `standard` | `default` | |
| `linka_social_follow.json` | `standard` | `default` | Widget fragment |
| `linka_page_title.json` | `standard` | `default` | |
| `linka_newsletter.json` | `standard` | `default` | |

**Empowered Health (`theme/empoweredhealth/views/components/`):**

| File | `container_width` | `max_height` | Notes |
|---|---|---|---|
| `hero_banner.json` | `standard` | `default` | |
| `about_section.json` | `standard` | `default` | |
| `pricing_section.json` | `standard` | `default` | |
| `specialties_section.json` | `standard` | `default` | |
| `testimonials_carousel.json` | `standard` | `default` | |

### Component Preview Utility (utils/component_preview.php)

Update to provide `$container_class`/`$container_style`/`$max_height_style` variables when rendering previews. No wrapper needed for previews (all use default settings).

---

## Template Variables Reference

These variables are always available in every component template:

| Variable | Type | Description |
|---|---|---|
| `$container_class` | string | CSS class for the container div (e.g., `"container"`, `"container-xl"`, `""`) |
| `$container_style` | string | Inline style for container (e.g., `""`, `"max-width:720px"`) |
| `$max_height_style` | string | Inline style for max height (e.g., `""`, `"max-height:400px;overflow:hidden"`) |

**For most templates:** Ignore these variables entirely. The wrapper CSS handles everything automatically.

**For `no_size_restriction` instances:** Use `$container_class`, `$container_style`, and `$max_height_style` to apply layout where the template's structure requires it.

---

## Migration

### custom_html Instances

Existing `custom_html` component instances that use the `container` config field:

- `container = true` (or missing) -> no action needed (`pac_container_width` defaults to `'standard'`)
- `container = false` -> set `pac_container_width = 'full'`

This is a database migration that reads `pac_config` JSON for custom_html instances and sets `pac_container_width` accordingly.

### Theme-Specific Components

Theme-specific components that hardcode `<div class="container">` work automatically with the wrapper approach -- no changes needed. The CSS custom property overrides their inner `.container` just like the base templates.

---

## Documentation Updates

### component_system.md

- Add "Layout Controls" section documenting container width and max height
- Add `$container_class`, `$container_style`, `$max_height_style` to the "Available Variables" table
- Note that these variables exist but most templates don't need to use them

### creating_components_from_themes.md

- Add note: "Container width and max height are controlled automatically by the renderer. Templates should continue using `<div class="container">` as normal. Admins can override the width/height per component instance without template changes."
- Document "No Size Restriction" checkbox for per-instance layout opt-out

---

## Implementation Notes

### Empty Output

If a template produces empty or whitespace-only output (e.g., custom_html with no HTML content), the renderer should skip wrapping. Check `trim($html)` before calling `wrap_with_layout()`.

### custom_html Template Update

The custom_html template must be updated to always output its `<section><div class="container">` wrapper. Currently it conditionally skips the wrapper when `$component_config['container']` is false. After migration:

1. Remove the `$container` conditional from the template
2. Always output `<section class="custom-html py-4"><div class="container">...</div></section>`
3. The layout system handles width control (migrated `container=false` instances get `pac_container_width='full'`)

This is the only template that requires a change.

### Max-Height Content Cropping

`overflow: hidden` crops content cleanly but can cut text or cards mid-line on content-heavy components. The admin help text should note: "Content exceeding this height will be hidden."

---

## Scope

### In Scope
- New database columns on `pac_page_contents` (5 columns)
- New database column on `com_components` (1 column: `com_layout_defaults`)
- ComponentRenderer wrapper logic (~60 lines)
- CSS rules (3 rules, ~15 lines)
- Admin edit form layout dropdowns with type-based prefill
- Add `layout_defaults` to all 19 existing component JSON definitions
- Update theme sync to read/store `layout_defaults`
- Update `custom_html.php` template to always output section+container wrapper
- Migration of existing `custom_html` `container` config values
- Documentation updates

### Out of Scope
- Changing existing component templates other than `custom_html` (they work as-is)
- Per-component-type default layout settings (all default to standard/default)
- Vertical padding controls (templates own their internal padding)
- Horizontal padding/gutter controls
- Margin between components (separate concern)
