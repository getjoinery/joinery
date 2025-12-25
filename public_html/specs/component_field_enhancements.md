# Component System - Field Enhancements

This specification covers advanced field types and features needed to fully support the component type library. The basic component system infrastructure is complete; this spec focuses on enhancing the config schema capabilities.

**Related Documentation:**
- [Component System Documentation](/docs/component_system.md) - How to use the current system
- [Component Type Library](/specs/component_type_library.md) - Planned component definitions

---

## Current State

### Implemented Field Types

| Type | Description |
|------|-------------|
| `textinput` | Single-line text |
| `textarea` / `textbox` | Multi-line text |
| `dropinput` | Dropdown select |
| `checkboxinput` | Boolean checkbox |
| `repeater` | Repeatable field groups |
| `dateinput` | Date picker |
| `timeinput` | Time picker |
| `datetimeinput` | Date and time |
| `hiddeninput` | Hidden field |

### Current Schema Format

```json
{
  "fields": [
    {
      "name": "field_name",
      "label": "Field Label",
      "type": "textinput",
      "help": "Help text for admin"
    }
  ]
}
```

---

## Phase 1: Essential Field Types

These field types are needed for basic component authoring.

### 1.1 Color Picker (`colorinput`)

**Purpose:** Select colors for backgrounds, text, accents.

**Schema:**
```json
{
  "name": "background_color",
  "label": "Background Color",
  "type": "colorinput",
  "default": "#ffffff",
  "help": "Choose a background color"
}
```

**Implementation Notes:**
- Use HTML5 `<input type="color">`
- Store as hex string (e.g., `#ff5733`)
- Consider adding preset color swatches option

**Admin UI:**
```php
// In FormWriterV2Base
public function colorinput($name, $label, $options = []) {
    $value = $options['value'] ?? '#000000';
    // Render color picker with optional presets
}
```

---

### 1.2 Number Input (`numberinput`)

**Purpose:** Numeric values with optional min/max/step.

**Schema:**
```json
{
  "name": "columns",
  "label": "Number of Columns",
  "type": "numberinput",
  "min": 1,
  "max": 6,
  "step": 1,
  "default": 3,
  "help": "How many columns to display"
}
```

**Implementation Notes:**
- Use HTML5 `<input type="number">`
- Pass min/max/step to HTML attributes
- Store as integer or float

---

### 1.3 Image Upload (`imageinput`)

**Purpose:** Upload or select images for backgrounds, galleries, etc.

**Schema:**
```json
{
  "name": "background_image",
  "label": "Background Image",
  "type": "imageinput",
  "help": "Upload or select a background image"
}
```

**Implementation Notes:**
- Integrate with existing file upload system
- Store as file path or media library ID
- Show thumbnail preview in admin
- Consider aspect ratio hints

**Dependencies:**
- May require media library integration
- Need secure file upload handling

---

### 1.4 Link Input (`linkinput`)

**Purpose:** URL input with optional page selector.

**Schema:**
```json
{
  "name": "button_url",
  "label": "Button Link",
  "type": "linkinput",
  "help": "Enter URL or select a page"
}
```

**Implementation Notes:**
- Text input for manual URL entry
- Optional: dropdown to select internal pages
- Store as URL string
- Validate URL format

---

## Phase 2: Enhanced Schema Features

### 2.1 Default Values

**Purpose:** Pre-populate fields with sensible defaults.

**Schema Addition:**
```json
{
  "name": "button_text",
  "label": "Button Text",
  "type": "textinput",
  "default": "Learn More"
}
```

**Implementation:**
- Apply defaults when creating new component instance
- In `admin_component_edit.php`, check for default in schema when field value is empty

```php
// When rendering form fields
$field_value = $current_config[$field_name] ?? $field['default'] ?? '';
```

---

### 2.2 Required Fields

**Purpose:** Validate that essential fields have values.

**Schema Addition:**
```json
{
  "name": "heading",
  "label": "Heading",
  "type": "textinput",
  "required": true
}
```

**Implementation:**
- Add `required` attribute to form fields
- Server-side validation before save
- Display validation errors in admin

---

### 2.3 Placeholder Text

**Purpose:** Show example text in empty fields.

**Schema Addition:**
```json
{
  "name": "video_url",
  "label": "Video URL",
  "type": "textinput",
  "placeholder": "https://www.youtube.com/watch?v=..."
}
```

**Implementation:**
- Pass through to HTML `placeholder` attribute
- Already supported by FormWriter, just need schema parsing

---

### 2.4 Repeater Enhancements

**Current Limitation:** Basic repeater without min/max or item labels.

**Schema Additions:**
```json
{
  "name": "features",
  "label": "Features",
  "type": "repeater",
  "item_label": "Feature",
  "min": 1,
  "max": 12,
  "fields": [...]
}
```

**Implementation:**
- `item_label`: Display in row header (e.g., "Feature 1", "Feature 2")
- `min`: Prevent removing below minimum rows
- `max`: Prevent adding above maximum rows
- Update JavaScript to enforce limits

---

## Phase 3: Advanced Features

### 3.1 Conditional Field Visibility

**Purpose:** Show/hide fields based on other field values.

**Schema:**
```json
{
  "name": "background_image",
  "label": "Background Image",
  "type": "imageinput",
  "condition": {
    "field": "background_type",
    "operator": "equals",
    "value": "image"
  }
}
```

**Operators:**
- `equals` - Exact match
- `not_equals` - Not equal
- `contains` - String contains (for multi-select)
- `empty` - Field is empty
- `not_empty` - Field has value

**Implementation:**
- Generate JavaScript visibility rules from conditions
- Use existing FormWriter visibility_rules pattern
- Handle nested conditions for grouped fields

---

### 3.2 Field Groups

**Purpose:** Organize related fields into collapsible sections.

**Schema:**
```json
{
  "name": "cta_settings",
  "label": "Call to Action",
  "type": "group",
  "collapsible": true,
  "collapsed": true,
  "fields": [
    {"name": "show_cta", "type": "checkboxinput", "label": "Show Button"},
    {"name": "cta_text", "type": "textinput", "label": "Button Text"},
    {"name": "cta_url", "type": "linkinput", "label": "Button Link"}
  ]
}
```

**Implementation:**
- Render as Bootstrap accordion or card
- Nested field names: `cta_settings.show_cta` or flatten to `cta_settings_show_cta`
- Update POST processing to handle nested structure

**Storage Options:**
1. Flatten: `{"cta_settings_show_cta": true, "cta_settings_cta_text": "Click"}`
2. Nested: `{"cta_settings": {"show_cta": true, "cta_text": "Click"}}`

Recommendation: Nested storage for cleaner templates.

---

### 3.3 Rich Text Editor (`richtextinput`)

**Purpose:** WYSIWYG editing for formatted content.

**Schema:**
```json
{
  "name": "content",
  "label": "Content",
  "type": "richtextinput",
  "help": "Enter formatted content"
}
```

**Implementation Options:**
1. TinyMCE (already in codebase?)
2. Quill
3. SimpleMDE for Markdown

**Considerations:**
- HTML sanitization on save
- Consistent toolbar options
- Image embedding support

---

### 3.4 Icon Picker (`iconinput`)

**Purpose:** Select icons from icon library (BoxIcons, FontAwesome).

**Schema:**
```json
{
  "name": "feature_icon",
  "label": "Icon",
  "type": "iconinput",
  "icon_set": "boxicons",
  "default": "bx-check"
}
```

**Implementation:**
- Modal or dropdown with icon grid
- Search/filter functionality
- Store icon class string (e.g., `bx bx-check`)

---

## Implementation Priority

### High Priority (Phase 1)
1. `colorinput` - Used in almost every component
2. `numberinput` - Essential for columns, counts, dimensions
3. Default values - Improves UX significantly
4. Required fields - Prevents broken components

### Medium Priority (Phase 2)
5. `imageinput` - Needed for heroes, galleries, backgrounds
6. `linkinput` - Needed for CTAs
7. Placeholder text - Easy win
8. Repeater min/max - Prevents misconfiguration

### Lower Priority (Phase 3)
9. Conditional visibility - Complex but powerful
10. Field groups - Organization improvement
11. `richtextinput` - Content blocks need this
12. `iconinput` - Nice to have

---

## Migration Path

When implementing new field types:

1. Add method to `FormWriterV2Base`
2. Update `admin_component_edit.php` to recognize type
3. Update POST processing if needed
4. Add to `docs/component_system.md` schema reference
5. Test with sample component type

---

## Testing Checklist

For each new field type:
- [ ] Renders correctly in admin form
- [ ] Saves value to database correctly
- [ ] Loads saved value when editing
- [ ] Available in template as `$component_config['field_name']`
- [ ] Works inside repeater fields
- [ ] Handles empty/null values gracefully
