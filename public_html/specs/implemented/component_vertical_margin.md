# Component Vertical Margin

## Problem

Components currently manage their own vertical spacing via hardcoded CSS classes (`py-4`, `pt-100`, etc.) inside their templates. This creates inconsistency — different components use different amounts of spacing, and admins have no way to adjust spacing between components without editing template code.

Horizontal margins between components and surrounding content are not needed — components either go full-width or are constrained by `pac_max_width`.

## Solution

Add a single `pac_vertical_margin` field to `PageContent` that controls the top and bottom margin of a component instance. The value is applied symmetrically — same margin top and bottom. This keeps the field count to one while covering the primary use case of controlling spacing between stacked components.

**Principle:** Components own their internal padding. The layout system owns the external margin between components.

## Design

### Field

Add to `PageContent::$field_specifications`:

```php
'pac_vertical_margin' => array('type'=>'varchar(20)', 'is_nullable'=>true),
```

### Value: Preset Keywords

Use semantic size keywords rather than raw CSS values. This enforces consistency across the site and makes the admin UI intuitive.

| Keyword | CSS Value | Description |
|---------|-----------|-------------|
| `none` | `0` | No vertical margin |
| `sm` | `1rem` | Small spacing |
| `md` | `2rem` | Medium spacing (default) |
| `lg` | `3rem` | Large spacing |
| `xl` | `5rem` | Extra-large spacing |

**NULL/empty** = use the component type's default (from `layout_defaults.vertical_margin`), falling back to `md` if not specified.

This means:
- A component type can declare its preferred default margin in its JSON `layout_defaults`
- Each instance can override that default via the admin UI
- If neither specifies, `md` (2rem) is the system default

### CSS Implementation

Applied via the existing wrapper `<div>` in `ComponentRenderer::wrap_with_layout()`:

```css
.component-layout[data-vmargin="none"] { margin-top: 0; margin-bottom: 0; }
.component-layout[data-vmargin="sm"] { margin-top: 1rem; margin-bottom: 1rem; }
.component-layout[data-vmargin="md"] { margin-top: 2rem; margin-bottom: 2rem; }
.component-layout[data-vmargin="lg"] { margin-top: 3rem; margin-bottom: 3rem; }
.component-layout[data-vmargin="xl"] { margin-top: 5rem; margin-bottom: 5rem; }
```

Using a data attribute + CSS classes rather than inline styles keeps it overridable by themes.

### Wrapper Activation

Currently, the wrapper is only added when width or height is set. With this change, the wrapper is also added when vertical margin is set. The `wrap_with_layout` logic becomes:

```
wrapper needed = has_width OR has_height OR has_vertical_margin
```

### Type-Key Mode (Programmatic)

When rendering via `ComponentRenderer::render(null, 'type_key', $config)`, there is no database instance and no wrapper. The calling code controls its own margins via surrounding HTML. This is unchanged — programmatic renderers are responsible for their own layout context.

## Implementation

### 1. `data/page_contents_class.php`

Add field to `$field_specifications`:
```php
'pac_vertical_margin' => array('type'=>'varchar(20)', 'is_nullable'=>true),
```

### 2. `includes/ComponentRenderer.php`

**`render()` method** — read the margin value:
```php
// In the Layout section, after reading container_width and max_height:
$vertical_margin = $component_instance->get('pac_vertical_margin');
if (!$vertical_margin) {
    // Fall back to component type's layout_defaults
    $vertical_margin = $layout_defaults['vertical_margin'] ?? null;
}
```

Pass `$vertical_margin` to `get_layout_vars()` and `wrap_with_layout()`.

**`get_layout_vars()`** — add margin to the vars array:
```php
protected static function get_layout_vars($width, $max_height, $vertical_margin = null) {
    // ... existing code ...
    $vars['cl_vertical_margin'] = $vertical_margin;
    return $vars;
}
```

**`wrap_with_layout()`** — include margin in wrapper decision and attributes:
```php
$has_margin = ($layout_vars['cl_vertical_margin'] !== null);

// Wrapper needed when any layout property is set
if (!$has_width && !$has_height && !$has_margin) {
    return $html;
}

if ($has_margin) {
    $attrs .= ' data-vmargin="' . htmlspecialchars($layout_vars['cl_vertical_margin']) . '"';
}
```

**`get_layout_css()`** — add the margin rules to the existing `<style>` block:
```css
.component-layout[data-vmargin="none"] { margin-top: 0; margin-bottom: 0; }
.component-layout[data-vmargin="sm"] { margin-top: 1rem; margin-bottom: 1rem; }
.component-layout[data-vmargin="md"] { margin-top: 2rem; margin-bottom: 2rem; }
.component-layout[data-vmargin="lg"] { margin-top: 3rem; margin-bottom: 3rem; }
.component-layout[data-vmargin="xl"] { margin-top: 5rem; margin-bottom: 5rem; }
```

### 3. `adm/admin_component_edit.php`

Add a dropdown in the advanced fields section, next to Max Width and Max Height:

```php
$formwriter->dropinput('pac_vertical_margin', 'Vertical Margin', [
    'options' => [
        '' => 'Default',
        'none' => 'None',
        'sm' => 'Small',
        'md' => 'Medium',
        'lg' => 'Large',
        'xl' => 'Extra Large',
    ],
    'help' => 'Space above and below the component. Default uses the component type\'s preferred spacing.',
]);
```

This goes in the same advanced fields block as pac_max_width and pac_max_height (there are 3 locations in the file for different component states — all three need the dropdown added).

Save handler — add alongside the existing max_width/max_height save:
```php
$content->set('pac_vertical_margin', trim($_POST['pac_vertical_margin'] ?? '') ?: null);
```

### 4. Component Type JSON `layout_defaults`

Component types can declare a default vertical margin in their JSON:

```json
"layout_defaults": {
    "container_width": "600px",
    "vertical_margin": "md"
}
```

This is read as the fallback when `pac_vertical_margin` is NULL on the instance.

### 5. Admin component edit — prefill from layout_defaults

In the existing prefill logic (around line 184), add:
```php
if (!empty($prefill_defaults['vertical_margin'])) {
    $content->set('pac_vertical_margin', $prefill_defaults['vertical_margin']);
}
```

## Files Modified

| File | Change |
|------|--------|
| `data/page_contents_class.php` | Add `pac_vertical_margin` field |
| `includes/ComponentRenderer.php` | Read margin, pass to layout, add CSS rules |
| `adm/admin_component_edit.php` | Add dropdown in advanced fields (3 locations), add to save handler, add to prefill |

## What This Does NOT Change

- **Internal padding** — still owned by component templates
- **Type-key mode rendering** — still no wrapper, caller manages layout
- **Existing components** — NULL margin = fallback to type default or `md`, so existing components get 2rem margin by default (a minor visual change from whatever ad-hoc spacing they had)

## Verification

1. Create a component instance in admin, verify the Vertical Margin dropdown appears
2. Set different margin values and verify spacing changes in the browser
3. Verify NULL defaults to the component type's `layout_defaults.vertical_margin`
4. Verify programmatic type-key renders are unaffected
5. Verify existing component instances still render correctly
