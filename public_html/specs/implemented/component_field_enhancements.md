# Component System - Field Enhancements

This specification covers the remaining field type and schema enhancements needed to round out the component system.

**Related Documentation:**
- [Component System Documentation](/docs/component_system.md) - Current system reference
- [Component Type Library](/specs/component_type_library.md) - Planned component definitions

---

## Current State

### Implemented Field Types

| Type | Description |
|------|-------------|
| `textinput` | Single-line text |
| `textarea` / `textbox` | Multi-line text |
| `richtext` | WYSIWYG editor (Trumbowyg) |
| `dropinput` | Dropdown select |
| `checkboxinput` | Boolean checkbox |
| `radioinput` | Radio buttons |
| `checkboxList` | Multiple checkbox selection |
| `colorpicker` | Color picker with theme swatches |
| `imageinput` | Image upload |
| `imageselector` | Image picker with gallery |
| `fileinput` | File upload |
| `repeater` | Repeatable field groups |
| `dateinput` | Date picker |
| `timeinput` | Time picker |
| `datetimeinput` | Date and time |
| `hiddeninput` | Hidden field |
| `passwordinput` | Password field |

### Implemented Schema Properties

| Property | Description |
|----------|-------------|
| `default` | Pre-populate fields with default values |
| `help` | Help text displayed below fields |
| `options` | Key-value pairs for dropinput/radioinput |
| `advanced` | Collapse field into advanced section |

---

## Bug Fix: help Text Not Rendering for Basic Field Types

**Problem:** `admin_component_edit.php` passes `'help' => $field_help` in the field options (line 299), but `FormWriterV2Bootstrap::outputTextInput()` checks `$options['helptext']` (line 94). These are different keys, so **help text does not render for textinput, textarea, or any field type that delegates to `outputTextInput`**.

Help text DOES render for `repeater` and `colorpicker` because those methods check `$options['help']` directly.

**Fix:** In `admin_component_edit.php`, the `$render_field` closure should pass help text under both keys:

```php
$field_options = [
    'value' => $current_config[$field_name] ?? $field_default,
    'help' => $field_help,
    'helptext' => $field_help,  // FormWriterV2Bootstrap uses this key
    'model' => false,
    'validation' => false
];
```

**Files:** `adm/admin_component_edit.php` (line ~297-302)

---

## Bug Fix: colorpicker Missing from get_field_types()

**Problem:** `Component::get_field_types()` in `data/components_class.php` (line ~187) does not include `colorpicker`. The type works in practice because `admin_component_edit.php` has explicit handling for it, but it won't appear in any reference listing of available types.

**Fix:** Add to the `get_field_types()` return array:

```php
'colorpicker' => 'Color Picker',
```

**Files:** `data/components_class.php`

---

## 1. Placeholder Text

**Purpose:** Show example text in empty fields so admins know the expected format.

**Schema property:**
```json
{
  "name": "video_url",
  "label": "Video URL",
  "type": "textinput",
  "placeholder": "https://www.youtube.com/watch?v=..."
}
```

**Implementation:** `FormWriterV2Bootstrap::outputTextInput()` already reads `$options['placeholder']` (line 28) and applies it to the HTML input. The only change needed is in `admin_component_edit.php` to pass the schema property through.

### Changes

**`adm/admin_component_edit.php`** — In the `$render_field` closure, after building `$field_options`, pass `placeholder` from schema:

```php
// After $field_options is built (line ~302)
if (isset($field['placeholder'])) {
    $field_options['placeholder'] = $field['placeholder'];
}
```

### Notes

- No POST processing changes needed — placeholder is display-only.
- `outputTextInput` line 60 conditionally applies placeholder: `if ($placeholder && !$value)`. This is correct — placeholder disappears when field has a value.
- Works for `textinput` and `passwordinput` (both delegate to methods that read `placeholder`). Does NOT apply to `textarea`, `dropinput`, `checkboxinput`, `colorpicker`, or `repeater` — these field types don't have a placeholder concept.

---

## 2. Number Input (`numberinput`)

**Purpose:** HTML5 number input with min/max/step. Replaces textinput workarounds for numeric fields like column counts, intervals, and dimensions.

**Schema:**
```json
{
  "name": "columns",
  "label": "Number of Columns",
  "type": "numberinput",
  "min": 1,
  "max": 6,
  "step": 1,
  "default": 3
}
```

### Changes

**`includes/FormWriterV2Base.php`** — Add new public method (near `textinput` at line ~777):

```php
public function numberinput($name, $label = '', $options = []) {
    $this->registerField($name, 'number', $label, $options);
    $this->outputNumberInput($name, $label, $options);
}
```

**`includes/FormWriterV2Bootstrap.php`** — Add new protected method modeled on `outputTextInput` (after `outputTextInput`):

Same structure as `outputTextInput` with these differences:
- `type="number"` instead of `type="text"`
- Add `min`, `max`, `step` attributes from `$options`
- Add `inputmode="numeric"`
- Support `required` attribute (for section 3)
- Support `placeholder` and `helptext` (same as textinput)

Min/max/step attributes:
```php
if (isset($options['min'])) {
    $html .= ' min="' . htmlspecialchars($options['min']) . '"';
}
if (isset($options['max'])) {
    $html .= ' max="' . htmlspecialchars($options['max']) . '"';
}
if (isset($options['step'])) {
    $html .= ' step="' . htmlspecialchars($options['step']) . '"';
}
```

**`data/components_class.php`** — Add to `get_field_types()`:

```php
'numberinput' => 'Number Input',
```

**`adm/admin_component_edit.php`** — In the `$render_field` closure, add a case before the fallback `method_exists` check (before line ~340):

```php
} elseif ($field_type === 'numberinput') {
    $number_options = ['min', 'max', 'step'];
    foreach ($number_options as $opt) {
        if (isset($field[$opt])) {
            $field_options[$opt] = $field[$opt];
        }
    }
    $formwriter->numberinput($field_name, $field_label, $field_options);
}
```

### Notes

- **POST processing:** The existing generic `else` branch (`$config[$field_name] = $_POST[$field_name]`) stores the value as a string in JSON. This is fine — HTML5 number inputs submit as strings. Templates should cast if needed: `intval($component_config['columns'])`.
- **Browser validation:** HTML5 number inputs provide client-side validation (won't submit out-of-range values). No server-side range validation needed — the HTML constraints are sufficient since this is admin-only.
- **Empty value:** If the field is not required and left empty, the POST value will be an empty string `""`. Templates should handle this: `$columns = $component_config['columns'] ?? 3;`

---

## 3. Required Fields

**Purpose:** Prevent saving components with empty essential fields.

**Schema property:**
```json
{
  "name": "heading",
  "label": "Heading",
  "type": "textinput",
  "required": true
}
```

### Changes

**`adm/admin_component_edit.php`** — Three changes:

1. In the `$render_field` closure, pass `required` to FormWriter options and add visual indicator to the label:

```php
// After building $field_options, before calling FormWriter
if (!empty($field['required'])) {
    $field_options['required'] = true;
    $field_label .= ' *';
}
```

2. In the POST handler (between `$content->set_config($config)` at line 119 and the `try` block at line 121), add validation:

```php
$content->set_config($config);

// Validate required fields
$validation_errors = [];
foreach ($schema_fields as $field) {
    if (!empty($field['required'])) {
        $field_name = $field['name'];
        $field_type = $field['type'] ?? 'textinput';
        $value = $config[$field_name] ?? '';

        if ($field_type === 'repeater') {
            if (empty($value) || !is_array($value) || count($value) === 0) {
                $validation_errors[] = ($field['label'] ?? $field_name) . ' is required';
            }
        } elseif ($field_type === 'checkboxinput') {
            // Checkboxes always valid (false is a valid value)
        } else {
            if (is_string($value) && trim($value) === '') {
                $validation_errors[] = ($field['label'] ?? $field_name) . ' is required';
            }
        }
    }
}

if (!empty($validation_errors)) {
    $error_message = implode('; ', $validation_errors);
} else {
    try {
        $content->prepare();
        $content->save();
        // ... existing redirect logic ...
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}
```

The existing error display at line 164 uses `htmlspecialchars()`, so error messages are joined with `'; '` (no HTML).

The form re-renders with submitted values because `$content->set_config($config)` is called before validation, and the form reads from `$content->get_config()` at line 276.

3. **`includes/FormWriterV2Bootstrap.php`** — In `outputTextInput()`, add `required` attribute support (after the `autocomplete` check, around line ~75):

```php
if (!empty($options['required'])) {
    $html .= ' required';
}
```

Also add to `outputNumberInput()` and `outputPasswordInput()` (same pattern).

### Notes

- **Client + server validation:** HTML `required` attribute provides immediate browser feedback. Server-side validation is the safety net.
- **Checkbox fields:** `required` on checkboxinput is skipped — `false` is a valid state.
- **Repeater required:** Means "at least one item must exist." Checked server-side only. The repeater container doesn't get an HTML `required` attribute.
- **Required in repeater sub-fields:** Handled by the sub-field passthrough (section 6). The HTML `required` attribute on sub-fields within repeater rows provides client-side validation. No server-side validation of individual sub-fields — HTML5 form validation catches it.

---

## 4. Repeater Enhancements (item_label, min, max)

**Purpose:** Better repeater UX with custom row labels, minimum/maximum row counts.

**Schema properties:**
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

### Changes

**`adm/admin_component_edit.php`** — In the repeater case of `$render_field` (line ~305-308), pass the new properties:

```php
if ($field_type === 'repeater') {
    $field_options['fields'] = $field['fields'] ?? [];
    $field_options['add_label'] = '+ Add ' . ($field['item_label'] ?? $field_label);
    if (isset($field['item_label'])) $field_options['item_label'] = $field['item_label'];
    if (isset($field['min'])) $field_options['min'] = $field['min'];
    if (isset($field['max'])) $field_options['max'] = $field['max'];
    $formwriter->repeater($field_name, $field_label, $field_options);
}
```

**`includes/FormWriterV2Base.php`** — In the `repeater()` method (line ~4077):

1. **Read new options:**

```php
$item_label = $options['item_label'] ?? null;
$min = isset($options['min']) ? intval($options['min']) : null;
$max = isset($options['max']) ? intval($options['max']) : null;
```

2. **Add `data-min`/`data-max` to container div:**

```php
echo '<div class="repeater mb-4" data-name="' . htmlspecialchars($name) . '"';
if ($min !== null) echo ' data-min="' . $min . '"';
if ($max !== null) echo ' data-max="' . $max . '"';
echo '>';
```

3. **Pre-populate minimum rows** (after `if (!is_array($items)) { $items = []; }` at line ~4083):

```php
if (empty($items) && $min !== null && $min > 0) {
    for ($i = 0; $i < $min; $i++) {
        $items[] = [];
    }
}
```

4. **Pass `item_label` to `repeater_row()`** — Add as a parameter or pass via a class property so the row can display it.

**`includes/FormWriterV2Base.php`** — In `repeater_row()` method (line ~4173):

Add an `item_label` display at the top of the card body when set. This is non-breaking — existing repeaters without `item_label` render exactly as before:

```php
echo '<div class="repeater-row card card-body mb-2" data-index="' . htmlspecialchars($index) . '">';

// Show item label if provided (e.g., "Feature 1", "Feature 2")
if ($item_label) {
    $display_number = ($index === '__INDEX__') ? '' : ' ' . ($index + 1);
    echo '<div class="d-flex justify-content-between align-items-center mb-2">';
    echo '<small class="fw-semibold text-muted repeater-row-label">'
        . htmlspecialchars($item_label)
        . '<span class="repeater-row-number">' . $display_number . '</span>'
        . '</small>';
    echo '</div>';
}

echo '<div class="row align-items-end">';
// ... existing field rendering + remove button unchanged ...
```

**`includes/FormWriterV2Base.php`** — Update `outputRepeaterJavaScript()` (line ~4125):

Replace the current add/remove handlers with min/max-aware versions:

```javascript
document.addEventListener("DOMContentLoaded", function() {
    // Add row - with max enforcement
    document.addEventListener("click", function(e) {
        if (e.target.classList.contains("repeater-add")) {
            var repeater = e.target.closest(".repeater");
            var items = repeater.querySelector(".repeater-items");
            var max = repeater.dataset.max;
            var currentCount = items.querySelectorAll(".repeater-row").length;

            if (max && currentCount >= parseInt(max)) {
                return;
            }

            var template = repeater.querySelector(".repeater-template");
            var nextIndex = currentCount;
            var clone = template.content.cloneNode(true);
            var row = clone.querySelector(".repeater-row");
            var html = row.outerHTML.replace(/__INDEX__/g, nextIndex);
            items.insertAdjacentHTML("beforeend", html);

            updateRepeaterState(repeater);
        }
    });

    // Remove row - with min enforcement
    document.addEventListener("click", function(e) {
        if (e.target.classList.contains("repeater-remove")) {
            var repeater = e.target.closest(".repeater");
            var items = repeater.querySelector(".repeater-items");
            var min = repeater.dataset.min;
            var currentCount = items.querySelectorAll(".repeater-row").length;

            if (min && currentCount <= parseInt(min)) {
                return;
            }

            e.target.closest(".repeater-row").remove();
            updateRepeaterState(repeater);
        }
    });

    // Update button disabled states and row numbering
    function updateRepeaterState(repeater) {
        var items = repeater.querySelector(".repeater-items");
        var count = items.querySelectorAll(".repeater-row").length;
        var min = repeater.dataset.min ? parseInt(repeater.dataset.min) : null;
        var max = repeater.dataset.max ? parseInt(repeater.dataset.max) : null;

        // Disable add button at max
        var addBtn = repeater.querySelector(".repeater-add");
        if (addBtn) {
            addBtn.disabled = (max !== null && count >= max);
        }

        // Disable remove buttons at min
        var removeBtns = items.querySelectorAll(".repeater-remove");
        removeBtns.forEach(function(btn) {
            btn.disabled = (min !== null && count <= min);
        });

        // Re-number row labels (if item_label is used)
        var labels = items.querySelectorAll(".repeater-row-number");
        labels.forEach(function(label, i) {
            label.textContent = " " + (i + 1);
        });
    }

    // Initialize button states on page load for all repeaters
    document.querySelectorAll(".repeater[data-min], .repeater[data-max]").forEach(updateRepeaterState);
});
```

### Notes

- **Non-breaking layout:** The `item_label` display is added above the existing field row only when `item_label` is set. Existing repeaters without it render identically to today.
- **min pre-population:** Empty rows are created only when the component instance has no existing data AND `min > 0`. On subsequent edits, saved data determines row count.
- **Row numbering after remove:** `updateRepeaterState` re-numbers all visible `.repeater-row-number` spans. Numbers are display-only, not tied to POST array indices.
- **Index gaps after remove:** When removing rows from the middle, POST array has index gaps (e.g., `features[0]`, `features[2]`). Already handled by `process_repeater_data()` which re-indexes.
- **Page load init:** `updateRepeaterState` runs on load for repeaters with `data-min` or `data-max` to set initial button disabled states (e.g., when editing an existing component at min count).
- **Template row in `<template>` tag:** The `__INDEX__` template row is inside a `<template>` element, so it's not counted by `querySelectorAll(".repeater-row")`. No special handling needed.

---

## 5. Repeater Sub-Field Schema Passthrough

**Problem:** The `repeater_row()` method's `$render_subfield` closure (FormWriterV2Base line ~4189-4221) only passes `value`, `model`, `validation`, and `options` to FormWriter methods. It does NOT pass `help`, `placeholder`, `default`, or `required` from the sub-field schema. This means the new schema properties from this spec won't work inside repeater sub-fields without this fix.

### Changes

**`includes/FormWriterV2Base.php`** — In `repeater_row()`'s `$render_subfield` closure, after building `$field_options` (line ~4201):

1. Pass through common schema properties:

```php
// Pass through schema properties to FormWriter options
$passthrough_props = ['placeholder', 'required', 'min', 'max', 'step'];
foreach ($passthrough_props as $prop) {
    if (isset($subfield[$prop])) {
        $field_options[$prop] = $subfield[$prop];
    }
}

// Map help to helptext for FormWriter compatibility
if (isset($subfield['help'])) {
    $field_options['help'] = $subfield['help'];
    $field_options['helptext'] = $subfield['help'];
}
```

2. Apply sub-field defaults for new/template rows (line ~4191):

```php
$field_value = $values[$subfield['name']] ?? ($subfield['default'] ?? '');
```

### Notes

- The `options` merge at line 4203-4206 already handles dropinput options. The new passthrough is for properties that live at the top level of the sub-field schema, not inside `options`.
- The `help`/`helptext` dual mapping matches the fix in section "Bug Fix" above.

---

## Documentation Updates

### `docs/component_system.md`

**1. Field Properties table (line 552-563)** — Add new rows:

```
| `placeholder` | No | Example text shown in empty text/number fields |
| `required` | No | If `true`, field must have a value to save. Adds `*` to label. |
```

**2. Available Field Types table (line 634-653)** — Add new row:

```
| `numberinput` | Numeric input with constraints | `min`, `max`, `step` |
```

**3. Repeater Fields section (near line 672)** — Add documentation for repeater-specific properties. After the existing repeater example, add:

**Repeater Options:**

| Property | Description |
|----------|-------------|
| `item_label` | Label for each row (e.g., "Feature 1", "Feature 2"). Omit for no label. |
| `min` | Minimum number of rows. Pre-populates empty rows on new instances. Disables remove at limit. |
| `max` | Maximum number of rows. Disables add button at limit. |

Example with all repeater options:

```json
{
  "name": "features",
  "label": "Features",
  "type": "repeater",
  "item_label": "Feature",
  "min": 1,
  "max": 12,
  "fields": [
    {"name": "title", "label": "Title", "type": "textinput", "required": true, "placeholder": "Feature name"},
    {"name": "count", "label": "Count", "type": "numberinput", "min": 0, "max": 100}
  ]
}
```

**4. Add a "Field Validation" subsection** after the Field Properties section, explaining:

- `required` adds client-side HTML `required` attribute plus server-side validation before save
- `numberinput` `min`/`max`/`step` provide browser-native range validation
- Validation errors prevent save and re-display the form with entered values preserved
- `required` on a repeater means at least one item must exist

---

## Implementation Order

1. **Bug fix: help/helptext key mismatch** — `adm/admin_component_edit.php`
2. **Bug fix: colorpicker in get_field_types()** — `data/components_class.php`
3. **Placeholder text** — `adm/admin_component_edit.php`
4. **numberinput** — `FormWriterV2Base.php`, `FormWriterV2Bootstrap.php`, `admin_component_edit.php`, `components_class.php`
5. **Required fields** — `admin_component_edit.php`, `FormWriterV2Bootstrap.php`
6. **Repeater min/max/item_label** — `FormWriterV2Base.php`, `admin_component_edit.php`
7. **Repeater sub-field passthrough** — `FormWriterV2Base.php`
8. **Documentation** — `docs/component_system.md`

Items 1-4 are independent. Items 5-7 can be done together. Item 8 after all code changes.

---

## Files Changed

| File | Changes |
|------|---------|
| `adm/admin_component_edit.php` | help/helptext fix, placeholder passthrough, numberinput case, required label + validation, repeater property passthrough |
| `includes/FormWriterV2Base.php` | `numberinput()` method, repeater min/max/item_label, repeater sub-field passthrough, updated repeater JavaScript |
| `includes/FormWriterV2Bootstrap.php` | `outputNumberInput()` method, `required` attribute in `outputTextInput()` and `outputNumberInput()` |
| `data/components_class.php` | Add `numberinput` and `colorpicker` to `get_field_types()` |
| `docs/component_system.md` | Field Properties table, Available Field Types table, Repeater section, Field Validation section |

---

## Testing Checklist

- [ ] Help text renders for textinput fields in component admin form
- [ ] Placeholder text shows in empty text fields, disappears when field has value
- [ ] Placeholder works in repeater sub-fields
- [ ] numberinput renders with spin buttons and respects min/max/step
- [ ] numberinput value saves and loads correctly (stored as string in JSON)
- [ ] numberinput works inside repeater sub-fields
- [ ] Required field shows `*` in label
- [ ] Saving with empty required field shows error and preserves entered values
- [ ] Required repeater field requires at least one item
- [ ] Repeater rows show item_label with numbering ("Feature 1", "Feature 2")
- [ ] Row numbers update after removing a row from the middle
- [ ] Repeater add button is disabled at max count
- [ ] Repeater remove button is disabled at min count
- [ ] New component with min repeater pre-populates empty rows
- [ ] Repeater button states initialize correctly on page load (editing existing component at min/max)
- [ ] Repeaters without item_label/min/max render identically to current behavior
- [ ] colorpicker appears in get_field_types() list

---

## Removed from Original Spec

| Item | Reason |
|------|--------|
| `colorinput` | Already implemented as `colorpicker` |
| `imageinput` | Already implemented |
| `richtextinput` | Already implemented as `richtext` |
| Default values | Already implemented |
| `linkinput` | `textinput` with help/placeholder is sufficient |
| `iconinput` | Complex picker UI for low usage — use `textinput` with help text |
| Conditional field visibility | High complexity, low practical need — use `advanced` flag instead |
| Field groups | High complexity — use `advanced` flag for organization |
