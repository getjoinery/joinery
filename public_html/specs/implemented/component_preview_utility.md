# Component Preview Utility

## Overview

A development utility that renders component types with auto-generated placeholder data, enabling rapid testing and validation of component templates without manual database setup.

## Goals

1. **Rapid Testing**: Test component templates immediately after creation
2. **Placeholder Generation**: Auto-generate realistic placeholder data based on config schema
3. **Visual Validation**: Display rendered components within the active theme for accurate preview
4. **Error Detection**: Surface template errors clearly during development
5. **Foundation for Theme Extraction**: Enable automated workflows for extracting components from HTML themes

## Location

```
/utils/component_preview.php
```

Accessible at: `/utils/component_preview` (no authentication required)

## URL Parameters

| Parameter | Description | Example |
|-----------|-------------|---------|
| `type` | Filter to single component type | `?type=hero_static` |
| `category` | Filter by category | `?category=hero` |
| `theme` | Override active theme | `?theme=falcon` |
| `config` | Show config JSON data | `?config` |
| `paths` | Show template file paths | `?paths` |

Parameters can be combined: `?type=hero_static&theme=falcon&config&paths`

## Features

### 1. Component Type Filtering

- **All Components View**: Render all active component types sequentially (default)
- **Single Component View**: Render one component type via `?type=hero_static`
- **Category Filter**: Show components by category via `?category=hero`

### 2. Theme Override

Override the active theme for preview purposes:

```
/utils/component_preview?theme=falcon
/utils/component_preview?type=hero_static&theme=falcon
```

Uses `PathHelper::getThemeFilePath()` with the 4th parameter for theme override.

This allows:
- Testing how components render in different themes
- Previewing theme-specific component overrides
- Testing components during theme development before activation

### 3. Placeholder Data Generation

Auto-generates placeholder content based on `config_schema` field types with smart detection for common field name patterns:

| Field Type | Placeholder Strategy |
|------------|---------------------|
| `textinput` | Context-aware based on field name (see below) |
| `textarea` | Lorem ipsum paragraph (2-3 sentences) |
| `checkboxinput` | Random true/false |
| `dropinput` | First option key from options array |
| `repeater` | 3 items with nested placeholders |
| `numberinput` | Random number 1-100 |
| `fileinput` | Placeholder image URL (800x400) |

**Smart textinput detection:**
- Fields containing `url` or `link`: Returns `#`
- Fields containing `color`: Returns `#007bff`
- Fields containing `icon`: Returns random Boxicon class (e.g., `bx bx-check`)
- Fields containing `button` + `text`: Returns `Learn More`
- Fields containing `heading` or `title`: Returns lorem phrase (4-8 words)
- Default: Lorem phrase (3-6 words)

**Repeater variety:** Titles become "Feature 1", "Feature 2", etc. Icons cycle through available icons.

### 4. Display Format

Components render within the active theme (or overridden theme) using `PublicPage` for accurate preview. Each component shows:

- **Card header**: Component title, type key, category, and CSS framework
- **Solo button**: Link to view just that component
- **Config data** (optional): Expandable JSON of placeholder data
- **Template path** (optional): Full file path to the template
- **Rendered output**: Full component rendering in dashed border container

### 5. Filter Bar

Uses FormWriter for themed form elements:
- Component Type dropdown (all types listed)
- Category dropdown (auto-populated from active components)
- Theme dropdown (lists all installed themes)
- Show Config checkbox
- Show Paths checkbox
- Apply button and Reset link

### 6. Error Handling

If a component fails to render:
- Displays error message prominently in red card body
- Shows specific error (template not found, render exception, etc.)
- Continues rendering other components
- Does not break the page

## Implementation

**Fully self-contained** - single file, no modifications to existing code.

### Core Class: ComponentPreviewer

Located within `/utils/component_preview.php`.

```php
class ComponentPreviewer {
    /**
     * Generate placeholder data for a component type based on its config schema
     * @param Component $componentType
     * @return array
     */
    public function generatePlaceholderData($componentType);

    /**
     * Generate placeholder value for a single field based on its type
     * Includes smart detection for common field name patterns
     * @param array $field Field definition from config_schema
     * @return mixed
     */
    public function generateFieldPlaceholder($field);

    /**
     * Render a component type with provided data
     * Uses PathHelper::getThemeFilePath() directly with optional theme override
     * @param Component $componentType
     * @param array $data Config data for the template
     * @param string|null $theme_override Theme name to use instead of active theme
     * @return array ['html' => string, 'error' => string|null, 'template_path' => string]
     */
    public function renderComponent($componentType, $data, $theme_override = null);

    /**
     * Get all active component types, optionally filtered
     * @param array $filters ['type' => 'hero_static', 'category' => 'hero']
     * @return array Array of Component objects
     */
    public function getComponentTypes($filters = []);

    /**
     * Get list of available themes
     * @return array Theme names
     */
    public function getAvailableThemes();

    /**
     * Get unique categories from active components
     * @return array Category names
     */
    public function getCategories();
}
```

### Technical Notes

**Theme wrapper**: Uses `PublicPage` from the target theme with `public_header()`/`public_footer()` for accurate theme preview.

**z-index handling**: Filter bar has `position: relative; z-index: 100` to stay above `stretched-link` overlays from rendered components. Component preview containers have `position: relative` to contain stretched-link effects.

**Empty parameter handling**: Empty string theme parameter (`?theme=`) is converted to `null` to use active theme.

**Schema parsing**: `com_config_schema` is stored as JSON string in database; parsed with `json_decode()`.

**Template variables**: Templates receive `$component_config`, `$component_data` (empty array), and `$component_slug` matching ComponentRenderer conventions.

## Use Cases

### 1. Testing New Component

```
1. Create hero_custom.json and hero_custom.php
2. Run database update (or it syncs automatically)
3. Visit /utils/component_preview?type=hero_custom
4. Verify rendering
5. Adjust template, refresh, repeat
```

### 2. Testing All Components After Theme Change

```
1. Visit /utils/component_preview?theme=newtheme
2. Scroll through all components
3. Identify any broken rendering
4. Fix theme overrides as needed
```

### 3. Theme Extraction Workflow

```
1. Analyze HTML theme section
2. Create JSON definition with config_schema
3. Create PHP template
4. Test immediately at /utils/component_preview?type=new_component
5. Check with config flag to verify placeholder data
6. Iterate until correct
7. Proceed to next section
```

### 4. Debugging Component Issues

```
1. Visit /utils/component_preview?type=problem_component&config&paths
2. Review the generated config data
3. Verify template path is correct
4. Check for error messages
```

## Security

- No authentication required (development utility, no sensitive data)
- All output properly escaped with `htmlspecialchars()`
- Template paths only shown with explicit `?paths` parameter

## Dependencies

- `Component`, `MultiComponent` - Load component types from database
- `PathHelper` - Template path resolution with theme override support
- `PublicPage` - Theme wrapper for accurate preview
- `FormWriter` - Themed form elements
- `Globalvars` - Active theme detection

No modifications to any existing files.

## Future Enhancements

1. **Custom Data Override**: POST JSON to test specific configurations
2. **Side-by-Side Comparison**: Show original HTML next to rendered component
3. **JSON Editor**: Edit config in-page with live preview
4. **Screenshot Capture**: Auto-generate component thumbnails
5. **Template Diff**: Compare base vs theme-overridden templates

## Version History

- **1.1.0** - Added FormWriter for themed form elements, z-index fixes for stretched-link overlays
- **1.0.0** - Initial implementation

## Related Documentation

- [Page Component System Spec](/specs/implemented/page_component_system.md)
- [Component Type Discovery](/specs/implemented/component_type_discovery.md)
