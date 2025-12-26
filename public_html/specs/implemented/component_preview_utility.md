# Component Preview Utility

## Overview

A development utility that renders component types with auto-generated placeholder data, enabling rapid testing and validation of component templates without manual database setup.

## Goals

1. **Rapid Testing**: Test component templates immediately after creation
2. **Placeholder Generation**: Auto-generate realistic placeholder data based on config schema
3. **Visual Validation**: Display rendered components for visual verification
4. **Error Detection**: Surface template errors clearly during development
5. **Foundation for Theme Extraction**: Enable automated workflows for extracting components from HTML themes

## Location

```
/utils/component_preview.php
```

Accessible at: `/utils/component_preview` (no authentication required)

## Features

### 1. Component Type Selection

- **All Components View**: Render all active component types sequentially
- **Single Component View**: Render one component type via `?type=hero_static`
- **Category Filter**: Show components by category via `?category=hero`

### 2. Theme Override

Override the active theme for preview purposes:

```
/utils/component_preview?theme=flavor
/utils/component_preview?type=hero_static&theme=flavor
```

**Implementation Note**: `PathHelper::getThemeFilePath()` already supports a `$theme_name` parameter (4th argument). We just need to pass it through from ComponentRenderer.

This allows:
- Testing how components render in different themes
- Previewing theme-specific component overrides
- Testing components during theme development before activation

### 3. Placeholder Data Generation

Auto-generate placeholder content based on `config_schema` field types:

| Field Type | Placeholder Strategy |
|------------|---------------------|
| `textinput` | Lorem ipsum phrase (3-8 words) |
| `textarea` | Lorem ipsum paragraph (2-3 sentences) |
| `checkboxinput` | Random true/false |
| `dropinput` | First option from options array |
| `repeater` | 3 items with nested placeholders |
| `numberinput` | Random number 1-100 |
| `fileinput` | Placeholder image URL |

### 4. Display Format

For each component:

```
┌─────────────────────────────────────────────────────────┐
│ Component: Hero Static                                  │
│ Type Key: hero_static                                   │
│ Category: hero | Framework: bootstrap                   │
├─────────────────────────────────────────────────────────┤
│                                                         │
│   [Rendered Component Output]                           │
│                                                         │
├─────────────────────────────────────────────────────────┤
│ ▼ Config Data (expandable)                              │
│ {                                                       │
│   "heading": "Lorem ipsum dolor sit",                   │
│   "subheading": "Consectetur adipiscing elit...",       │
│   ...                                                   │
│ }                                                       │
├─────────────────────────────────────────────────────────┤
│ ▼ Template Path                                         │
│ /views/components/hero_static.php                       │
└─────────────────────────────────────────────────────────┘
```

### 5. Error Handling

If a component fails to render:

- Display error message prominently
- Show stack trace (in development mode)
- Continue rendering other components
- Log error to system log

### 6. Custom Data Override

Allow POST of custom JSON data to test specific configurations:

```
POST /utils/component_preview?type=hero_static
Content-Type: application/json

{
  "heading": "Custom Heading",
  "subheading": "Custom subheading text"
}
```

## Implementation

**Fully self-contained** - no modifications to existing code. The utility renders component types directly using `PathHelper::getThemeFilePath()`.

### Core Class: ComponentPreviewer

Located within `/utils/component_preview.php` (no separate class file needed).

```php
class ComponentPreviewer {

    /**
     * Generate placeholder data for a component type
     * @param Component $componentType
     * @return array
     */
    public function generatePlaceholderData($componentType);

    /**
     * Generate placeholder for a single field
     * @param array $field Field definition from config_schema
     * @return mixed
     */
    public function generateFieldPlaceholder($field);

    /**
     * Render a component type with placeholder data
     *
     * Renders directly via PathHelper::getThemeFilePath() - does NOT use
     * ComponentRenderer (which is designed for component instances, not types).
     *
     * @param Component $componentType
     * @param array $data Placeholder data
     * @param string|null $theme_override Theme name to use instead of active theme
     * @return array ['html' => string, 'error' => string|null]
     */
    public function renderComponent($componentType, $data, $theme_override = null) {
        $template_file = $componentType->get('com_template_file');

        // Direct call to PathHelper with optional theme override (4th param)
        $template_path = PathHelper::getThemeFilePath(
            $template_file,
            'views/components',
            'system',
            $theme_override
        );

        // ... render logic
    }

    /**
     * Get all active component types, optionally filtered
     * @param array $filters ['category' => 'hero', 'framework' => 'bootstrap']
     * @return array
     */
    public function getComponentTypes($filters = []);
}
```

### Placeholder Data Examples

**textinput:**
```php
"Lorem ipsum dolor sit amet"
```

**textarea:**
```php
"Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore."
```

**repeater (features example):**
```php
[
    ["icon" => "bx bx-check", "title" => "Feature One", "description" => "Lorem ipsum..."],
    ["icon" => "bx bx-star", "title" => "Feature Two", "description" => "Dolor sit amet..."],
    ["icon" => "bx bx-heart", "title" => "Feature Three", "description" => "Consectetur..."]
]
```

**dropinput:**
```php
// First key from options array
"center"  // from {"left": "Left", "center": "Center", "right": "Right"}
```

## UI/UX

### Page Layout

1. **Header**: Title, filter controls, "Test All" / "Clear" buttons
2. **Sidebar** (optional): List of component types with quick-jump
3. **Main Area**: Rendered components in cards

### Controls

- Dropdown: Select component type (or "All")
- Dropdown: Filter by category
- Dropdown: Theme override (lists installed themes)
- Checkbox: Show config data
- Checkbox: Show template paths
- Button: Regenerate placeholders
- Button: Copy component HTML

### Styling

Use existing admin theme (Falcon/Bootstrap) for consistency.

## Use Cases

### 1. Testing New Component

```
1. Create hero_custom.json and hero_custom.php
2. Run theme sync (or it syncs automatically)
3. Visit /utils/component_preview?type=hero_custom
4. Verify rendering
5. Adjust template, refresh, repeat
```

### 2. Testing All Components After Theme Change

```
1. Switch theme in admin
2. Visit /utils/component_preview
3. Scroll through all components
4. Identify any broken rendering
```

### 3. Theme Extraction Workflow (Future)

```
1. Analyze HTML theme section
2. Create JSON definition
3. Create PHP template
4. Test immediately in preview utility
5. Iterate until correct
6. Proceed to next section
```

## Security

- No authentication required (development utility, no sensitive data)
- Validate POST JSON data
- Escape all output in metadata display
- Consider hiding full file paths in production mode (optional)

## Dependencies

- Component, MultiComponent (existing) - to load component types
- PathHelper (existing) - for template path resolution with theme override
- No modifications to any existing files

## Future Enhancements

1. **Side-by-Side Comparison**: Show original HTML next to rendered component
2. **JSON Editor**: Edit config in-page with live preview
3. **Screenshot Capture**: Auto-generate component thumbnails
4. **Template Diff**: Compare base vs theme-overridden templates
5. **Export/Import**: Share placeholder configurations

## Success Criteria

1. Can render any active component type with placeholder data
2. Errors are caught and displayed without breaking page
3. Placeholder data looks realistic for each field type
4. Admin can test components without touching database
5. Full render cycle takes < 2 seconds for all components

## Related Documentation

- [Component System Documentation](/docs/component_system.md)
- [Creating Components from Themes](/docs/creating_components_from_themes.md)
