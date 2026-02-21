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
<!-- Renderer adds this wrapper (only when a layout value is set) -->
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

**The width fallback rule** uses `:has()` to detect templates without any Bootstrap container div. When no container exists, it constrains the outermost element directly (including backgrounds). `:has()` is supported in Chrome 105+, Firefox 121+, Safari 15.4+.

**Key properties of this approach:**
- **Zero template changes** for the default path -- existing templates work as-is
- Full-width backgrounds are preserved for templates with a container (the outer wrapper doesn't constrain them; only the inner `.container` is narrowed)
- Templates without a container are constrained directly via the fallback rule
- When both values are NULL (no restriction), **no wrapper is added at all** -- zero impact on existing pages
- CSS specificity of `.component-layout[data-maxw] .container` naturally overrides Bootstrap's `.container` media queries

### Storage Model

Layout values are stored directly as CSS values. NULL means no restriction.

| `pac_max_width` | Meaning |
|---|---|
| `NULL` | No restriction -- template's natural container behavior |
| `720px` | Max-width 720px |
| `none` | Removes container max-width (CSS `max-width: none`) |
| `80%` | Max-width 80% |

| `pac_max_height` | Meaning |
|---|---|
| `NULL` | No restriction -- template's natural height |
| `400px` | Max-height 400px, overflow hidden |
| `100vh` | Max-height 100vh, overflow hidden |

### Layout Defaults in Component Type JSON

Each component type's JSON definition can specify default layout values via `layout_defaults`. These are stored in `com_layout_defaults` (JSON column) on `com_components`, synced from JSON files during theme sync.

```json
{
  "title": "Newsletter Signup",
  "layout_defaults": {
    "container_width": "400px"
  },
  "config_schema": { ... }
}
```

**New instance pre-fill:** When creating a new component instance, the admin form pre-fills the layout text inputs from the component type's `layout_defaults`. The admin can override or clear these values. The renderer never reads JSON -- it only sees the stored CSS value or NULL.

### Developer Opt-Out (skip_wrapper)

Component types that need full control over their own layout can set `skip_wrapper: true` in their `layout_defaults`:

```json
{
  "title": "Custom Widget",
  "layout_defaults": {
    "skip_wrapper": true
  },
  "config_schema": { ... }
}
```

When `skip_wrapper` is true:
- The renderer skips the auto-wrapper entirely
- The layout text inputs are hidden in the admin edit form
- Template variables (`$container_class`, `$container_style`, `$max_height_style`) are still available

This is a per-type developer decision, not a per-instance admin decision.

---

## Admin Interface

### Text Inputs

Width and Height are plain text inputs in the advanced fields section:

| Field | Label | Placeholder/Help |
|---|---|---|
| `pac_max_width` | Max Width | CSS value, e.g. 720px, 80%. Leave empty for no restriction. |
| `pac_max_height` | Max Height | CSS value, e.g. 400px, 50vh. Leave empty for no restriction. |

Empty = no restriction (NULL stored). Any CSS value is stored directly.

### Pre-fill for New Instances

When creating a new component instance, the form pre-fills values from the component type's `layout_defaults` JSON. The admin can modify or clear them.

### Form Location

Layout fields appear in the advanced fields section alongside slug and order. They are hidden when the component type has `skip_wrapper: true`.

---

## Wrapper Logic

The renderer only adds the wrapper div when at least one value is non-NULL:

| Container Width | Max Height | Wrapper Added? |
|---|---|---|
| `NULL` | `NULL` | No -- zero impact |
| `720px` | `NULL` | Yes -- `--cl-max-width` only |
| `NULL` | `400px` | Yes -- `--cl-max-height` only |
| `720px` | `400px` | Yes -- both properties |

Component types with `skip_wrapper: true` never get wrapped.

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

---

## Database Changes

### com_components -- New Column

| Column | Type | Default | Description |
|---|---|---|---|
| `com_layout_defaults` | `json` | `NULL` | Default layout settings from JSON (container_width, max_height, skip_wrapper) |

### pac_page_contents -- New Columns

| Column | Type | Default | Description |
|---|---|---|---|
| `pac_max_width` | `varchar(50)` | `NULL` | CSS max-width value, or NULL for no restriction |
| `pac_max_height` | `varchar(50)` | `NULL` | CSS max-height value, or NULL for no restriction |

Two columns total. NULL means no restriction. Any CSS value is stored directly.

---

## File Changes

### ComponentRenderer.php

Layout variable computation:

```php
$container_width = $component_instance->get('pac_max_width');
$max_height = $component_instance->get('pac_max_height');
$layout_vars = self::get_layout_vars($container_width, $max_height);
```

Layout vars helper:

```php
protected static function get_layout_vars($width, $max_height) {
    $vars = [
        'container_class' => 'container',
        'container_style' => '',
        'max_height_style' => '',
        'cl_max_width' => null,
        'cl_max_height' => null,
    ];

    if (!empty($width)) {
        $vars['cl_max_width'] = $width;
        $vars['container_style'] = 'max-width:' . $width;
    }

    if (!empty($max_height)) {
        $vars['cl_max_height'] = $max_height;
        $vars['max_height_style'] = 'max-height:' . $max_height . ';overflow:hidden';
    }

    return $vars;
}
```

### admin_component_edit.php

Save handler stores the CSS value directly, empty = NULL:

```php
$content->set('pac_max_width', trim($_POST['pac_max_width'] ?? '') ?: null);
$content->set('pac_max_height', trim($_POST['pac_max_height'] ?? '') ?: null);
```

Pre-fill for new instances from component type defaults:

```php
if (!$content->key && $component_type) {
    $prefill_defaults = $component_type->get('com_layout_defaults') ?: [];
    if (!empty($prefill_defaults['container_width'])) {
        $content->set('pac_max_width', $prefill_defaults['container_width']);
    }
    if (!empty($prefill_defaults['max_height'])) {
        $content->set('pac_max_height', $prefill_defaults['max_height']);
    }
}
```

### CSS (injected inline by ComponentRenderer)

Injected as a `<style>` block on first render that uses layout wrapping:

```css
.component-layout[data-maxw] .container,
.component-layout[data-maxw] .container-fluid,
.component-layout[data-maxw] .container-lg,
.component-layout[data-maxw] .container-xl {
    max-width: var(--cl-max-width);
}
.component-layout[data-maxw]:not(:has(.container, .container-fluid, .container-lg, .container-xl)) > :first-child {
    max-width: var(--cl-max-width);
    margin-left: auto;
    margin-right: auto;
}
.component-layout[data-maxh] > :first-child {
    max-height: var(--cl-max-height);
    overflow: hidden;
}
```

---

## Template Variables Reference

These variables are always available in every component template:

| Variable | Type | Description |
|---|---|---|
| `$container_class` | string | CSS class for the container div (e.g., `"container"`) |
| `$container_style` | string | Inline style for container (e.g., `""`, `"max-width:720px"`) |
| `$max_height_style` | string | Inline style for max height (e.g., `""`, `"max-height:400px;overflow:hidden"`) |

**For most templates:** Ignore these variables entirely. The wrapper CSS handles everything automatically.

**For `skip_wrapper` component types:** Use these variables to apply layout where the template's structure requires it.

---

## Migration

### custom_html Instances

Existing `custom_html` instances with `container=false` in config:
- `container = true` (or missing) → no action (NULL = template's natural container)
- `container = false` → set `pac_max_width = 'none'` (removes container max-width constraint)

---

## Scope

### In Scope
- New database columns on `pac_page_contents` (2 columns)
- New database column on `com_components` (1 column: `com_layout_defaults`)
- ComponentRenderer wrapper logic
- CSS rules (3 rules, injected inline)
- Admin edit form layout text inputs with pre-fill from component type defaults
- Add `layout_defaults` to component JSON definitions as needed
- Update theme sync to read/store `layout_defaults`
- Update `custom_html.php` template to always output section+container wrapper
- Migration of existing `custom_html` `container` config values
- Documentation updates

### Out of Scope
- Changing existing component templates other than `custom_html` (they work as-is)
- Vertical padding controls (templates own their internal padding)
- Horizontal padding/gutter controls
- Margin between components (separate concern)
