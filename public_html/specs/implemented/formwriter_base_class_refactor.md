# Specification: FormWriter Base Class Refactor

## Overview

Refactor the FormWriter v2 system to move all behavioral logic (value resolution, state determination, attribute computation) into `FormWriterV2Base`, leaving subclasses responsible only for themed HTML rendering. This eliminates the recurring class of bugs where FormWriter implementations drift apart in behavior.

## Problem Statement

The current architecture declares 15 abstract `output*()` methods in the base class. Each subclass (HTML5, Bootstrap, Tailwind) implements these methods with **both** behavioral logic and HTML generation mixed together. This has caused:

1. **The checkbox bug (April 2026):** HTML5 didn't support the `'checked'` option that Bootstrap/Tailwind supported. All checkboxes on scrolldaddy filters_edit rendered as always-on.
2. **Tailwind dropdown reversal:** `outputDropInput` iterated options as `[label => value]` instead of `[value => label]`, producing wrong option values on every Tailwind dropdown.
3. **Tailwind checkboxList reversal:** Same key/value swap, submitting labels as values.
4. **Missing value fallbacks:** Tailwind's text, password, date, hidden, radio, and textarea methods didn't fall back to `$this->values[$name]`, causing empty fields when model data was available.
5. **Missing methods:** Tailwind had no `outputNumberInput` at all.
6. **Inconsistent attribute support:** Bootstrap hardcoded `type="text"` ignoring the `type` option. Bootstrap used `!empty()` for date min/max (skipping `"0"`). Placeholder behavior varied.

These bugs happen because each subclass reimplements the same logic independently, and nothing enforces that they stay in sync.

## Current Architecture

```
FormWriterV2Base (abstract)
  ├── public checkboxinput($name, $label, $options)
  │     ├── registerField(...)
  │     └── outputCheckboxInput(...)  ←── abstract, delegated entirely to subclass
  │
  └── abstract outputCheckboxInput($name, $label, $options)
        ↓
FormWriterV2HTML5::outputCheckboxInput()     ── resolves values + determines state + generates HTML
FormWriterV2Bootstrap::outputCheckboxInput() ── resolves values + determines state + generates HTML  (differently!)
FormWriterV2Tailwind::outputCheckboxInput()  ── resolves values + determines state + generates HTML  (differently!)
```

Each subclass owns ALL of the logic. The base class provides no behavioral guarantees.

## Proposed Architecture

Split each method into **data preparation** (base class, concrete) and **rendering** (subclass, abstract):

```
FormWriterV2Base (concrete output methods)
  ├── public checkboxinput($name, $label, $options)
  │     ├── registerField(...)
  │     └── outputCheckboxInput(...)  ←── NOW CONCRETE in base class
  │           ├── prepareCheckboxData($name, $label, $options)  →  $data array
  │           ├── renderCheckboxInput($data)  ←── abstract, subclass implements
  │           └── handleOutput(...)
  │
  └── abstract renderCheckboxInput($data)
        ↓
FormWriterV2HTML5::renderCheckboxInput($data)     ── generates HTML only (data already resolved)
FormWriterV2Bootstrap::renderCheckboxInput($data) ── generates HTML only (same data, different CSS)
FormWriterV2Tailwind::renderCheckboxInput($data)  ── generates HTML only (same data, different CSS)
```

The base class owns ALL behavioral logic. Subclasses receive a pre-computed `$data` array and produce themed HTML — nothing else.

## Implementation

**Files modified:** `FormWriterV2Base.php`, `FormWriterV2HTML5.php`, `FormWriterV2Bootstrap.php`, `FormWriterV2Tailwind.php`

All 15 abstract `output*()` methods are converted in a single pass. Each becomes a concrete method in the base class that calls a `prepare*Data()` method, then delegates to a new abstract `render*()` method.

### Pattern for every method

```php
// Base class — concrete output, abstract render

protected function outputFoo($name, $label, $options) {
    $data = $this->prepareFooData($name, $label, $options);
    $html = $this->renderFoo($data);
    $this->handleOutput($name, $html);
}

protected function prepareFooData($name, $label, $options) {
    // ALL behavioral logic lives here:
    // - value resolution with $this->values fallback
    // - state determination (checked, selected, etc.)
    // - option normalization (boolean conversion, defaults)
    // - error detection
    return [ /* standardized $data array */ ];
}

abstract protected function renderFoo($data);
// Subclasses implement renderFoo() — pure HTML, reads only from $data
```

### Per-method specifications

#### checkboxInput

**`prepareCheckboxData()` returns:**
```php
[
    'name'             => $name,
    'label'            => $label,
    'id'               => $options['id'] ?? $name,
    'checked_value'    => $options['checked_value'] ?? '1',
    'is_checked'       => /* bool: checked option if present, else value === checked_value */,
    'class'            => $options['class'] ?? '',
    'disabled'         => !empty($options['disabled']),
    'required'         => !empty($options['required']),
    'onchange'         => $options['onchange'] ?? '',
    'has_errors'       => isset($this->errors[$name]),
    'errors'           => $this->errors[$name] ?? [],
    'helptext'         => $options['helptext'] ?? '',
    'visibility_rules' => $options['visibility_rules'] ?? null,
    'custom_script'    => $options['custom_script'] ?? null,
]
```

**Checked-state logic (centralized):**
```php
if (isset($options['checked'])) {
    $is_checked = !empty($options['checked']);
} else {
    $current_value = $options['value'] ?? ($this->values[$name] ?? '');
    $is_checked = ((string)$current_value === (string)$checked_value);
}
```

**`outputCheckboxInput()` also handles visibility/custom scripts after `handleOutput()`.**

#### textInput

**`prepareTextData()` returns:**
```php
[
    'name'        => $name,
    'label'       => $label,
    'id'          => $options['id'] ?? $name,
    'value'       => $options['value'] ?? ($this->values[$name] ?? ''),
    'type'        => $options['type'] ?? 'text',
    'placeholder' => /* string, only set when value is empty */,
    'class'       => $options['class'] ?? '',  // subclass overrides default in render
    'prepend'     => $options['prepend'] ?? '',
    'readonly'    => !empty($options['readonly']),
    'disabled'    => !empty($options['disabled']),
    'autofocus'   => !empty($options['autofocus']),
    'required'    => !empty($options['required']),
    'autocomplete'=> $options['autocomplete'] ?? '',
    'onchange'    => $options['onchange'] ?? '',
    'pattern'     => $options['pattern'] ?? '',
    'min'         => $options['min'] ?? null,
    'max'         => $options['max'] ?? null,
    'step'        => $options['step'] ?? null,
    'minlength'   => $options['minlength'] ?? null,
    'maxlength'   => $options['maxlength'] ?? null,
    'has_errors'  => isset($this->errors[$name]),
    'errors'      => $this->errors[$name] ?? [],
    'helptext'    => $options['helptext'] ?? '',
]
```

**Placeholder logic:** `$placeholder = ($options['placeholder'] ?? '') && !$value ? $options['placeholder'] : ''`

**Note on `class` default:** The base class sets `class` to `$options['class'] ?? ''` (empty string). If the subclass renderer receives an empty class, it applies its own theme-specific default (e.g., `'form-control'` for Bootstrap, Tailwind utility classes for Tailwind). This keeps the default CSS out of the data layer.

#### passwordInput

**`preparePasswordData()` returns:** Same structure as textInput, with `type` forced to `'password'`. Adds:
```php
'strength_meter' => !empty($options['strength_meter']),
```

#### numberInput

**`prepareNumberData()` returns:** Same structure as textInput, with `type` forced to `'number'`. Subclass renderers may add `inputmode="numeric"` if desired.

#### dropInput

**`prepareDropData()` returns:**
```php
[
    'name'           => $name,
    'label'          => $label,
    'id'             => $options['id'] ?? $name,
    'value'          => /* with boolean-to-int conversion */,
    'options_list'   => $options['options'] ?? [],  // always [value => label]
    'empty_option'   => /* normalized: false, or string label */,
    'class'          => $options['class'] ?? '',
    'multiple'       => !empty($options['multiple']),
    'disabled'       => !empty($options['disabled']),
    'required'       => !empty($options['required']),
    'onchange'       => $options['onchange'] ?? '',
    'ajaxendpoint'   => $options['ajaxendpoint'] ?? '',
    'has_errors'     => isset($this->errors[$name]),
    'errors'         => $this->errors[$name] ?? [],
    'helptext'       => $options['helptext'] ?? '',
    'visibility_rules' => $options['visibility_rules'] ?? null,
    'custom_script'    => $options['custom_script'] ?? null,
]
```

**Value logic:** `$value = $options['value'] ?? ($this->values[$name] ?? '')`. Boolean values converted: `is_bool($value) ? ($value ? 1 : 0) : $value`.

**empty_option normalization:** `true` becomes `'Select...'`; strings pass through; falsy becomes `null`.

**Option iteration is `[value => label]` — enforced in the base class prepare method. The render method receives `options_list` and iterates `foreach ($data['options_list'] as $opt_value => $opt_label)`.**

**AJAX dropdown script:** The inline JS for ajax search-select is identical across all three implementations. Move it to a base class method `buildAjaxSelectScript($id, $endpoint)` that renderers call.

#### radioInput

**`prepareRadioData()` returns:**
```php
[
    'name'         => $name,
    'label'        => $label,
    'value'        => $options['value'] ?? ($this->values[$name] ?? ''),
    'options_list' => $options['options'] ?? [],  // [value => label]
    'class'        => $options['class'] ?? '',
    'disabled'     => !empty($options['disabled']),
    'required'     => !empty($options['required']),
    'onchange'     => $options['onchange'] ?? '',
    'has_errors'   => isset($this->errors[$name]),
    'errors'       => $this->errors[$name] ?? [],
    'helptext'     => $options['helptext'] ?? '',
]
```

**Selection logic:** Renderers compare `(string)$data['value'] === (string)$opt_value` for each option. This comparison is simple enough to leave in the renderer since it happens per-option during iteration.

#### dateInput

**`prepareDateData()` returns:**
```php
[
    'name'      => $name,
    'label'     => $label,
    'id'        => $options['id'] ?? $name,
    'value'     => /* date-parsed to YYYY-MM-DD if needed */,
    'class'     => $options['class'] ?? '',
    'min'       => $options['min'] ?? null,     // uses isset(), not !empty()
    'max'       => $options['max'] ?? null,
    'readonly'  => !empty($options['readonly']),
    'disabled'  => !empty($options['disabled']),
    'required'  => !empty($options['required']),
    'onchange'  => $options['onchange'] ?? '',
    'has_errors'=> isset($this->errors[$name]),
    'errors'    => $this->errors[$name] ?? [],
    'helptext'  => $options['helptext'] ?? '',
]
```

**Date parsing (centralized):** If value is not already `YYYY-MM-DD`, attempt `new DateTime($value)->format('Y-m-d')`. On failure, pass through as-is.

#### timeInput

**`prepareTimeData()` returns:**
```php
[
    'name'       => $name,
    'label'      => $label,
    'id'         => $options['id'] ?? $name,
    'value'      => $options['value'] ?? ($this->values[$name] ?? ''),
    'hour'       => /* from parseTimeValue() */,
    'minute'     => /* from parseTimeValue() */,
    'ampm'       => /* from parseTimeValue() */,
    'class'      => $options['class'] ?? '',
    'readonly'   => !empty($options['readonly']),
    'disabled'   => !empty($options['disabled']),
    'has_errors' => isset($this->errors[$name]),
    'errors'     => $this->errors[$name] ?? [],
    'helptext'   => $options['helptext'] ?? '',
]
```

**`outputTimeInput()` also calls `outputTimeInputJavaScript()` after rendering (for Bootstrap/Tailwind sync scripts).**

#### dateTimeInput

**`prepareDateTimeData()` returns:**
```php
[
    'name'        => $name,
    'label'       => $label,
    'date_name'   => $name . '_dateinput',
    'time_name'   => $name . '_timeinput',
    'date_value'  => /* extracted date part */,
    'time_value'  => /* extracted time part */,
    'hour'        => /* from parseTimeValue() */,
    'minute'      => /* from parseTimeValue() */,
    'ampm'        => /* from parseTimeValue() */,
    'class'       => $options['class'] ?? '',
    'readonly'    => !empty($options['readonly']),
    'disabled'    => !empty($options['disabled']),
    'date_errors' => $this->errors[$name . '_dateinput'] ?? [],
    'time_errors' => $this->errors[$name . '_timeinput'] ?? [],
    'helptext'    => $options['helptext'] ?? '',
]
```

**DateTime parsing:** If value contains a space, split into date/time parts. Also accept separate `date_value`/`time_value` options.

#### fileInput

**`prepareFileData()` returns:**
```php
[
    'name'      => $name,
    'label'     => $label,
    'id'        => $options['id'] ?? $name,
    'class'     => $options['class'] ?? '',
    'accept'    => $options['accept'] ?? '',
    'multiple'  => !empty($options['multiple']),
    'disabled'  => !empty($options['disabled']),
    'required'  => !empty($options['required']),
    'onchange'  => $options['onchange'] ?? '',
    'has_errors'=> isset($this->errors[$name]),
    'errors'    => $this->errors[$name] ?? [],
    'helptext'  => $options['helptext'] ?? '',
]
```

#### hiddenInput

**`prepareHiddenData()` returns:**
```php
[
    'name'  => $name,
    'id'    => $options['id'] ?? $name,
    'value' => $options['value'] ?? ($this->values[$name] ?? ''),
]
```

#### submitButton

**`prepareSubmitData()` returns:**
```php
[
    'name'     => $name,
    'label'    => $label,
    'id'       => $options['id'] ?? $name,
    'class'    => $options['class'] ?? '',
    'disabled' => !empty($options['disabled']),
    'onclick'  => $options['onclick'] ?? '',
]
```

#### textarea

**`prepareTextareaData()` returns:**
```php
[
    'name'        => $name,
    'label'       => $label,
    'id'          => $options['id'] ?? $name,
    'value'       => $options['value'] ?? ($this->values[$name] ?? ''),
    'placeholder' => /* conditional: only when value is empty */,
    'class'       => $options['class'] ?? '',
    'rows'        => $options['rows'] ?? 5,
    'cols'        => $options['cols'] ?? 80,
    'readonly'    => !empty($options['readonly']),
    'disabled'    => !empty($options['disabled']),
    'required'    => !empty($options['required']),
    'minlength'   => $options['minlength'] ?? null,
    'maxlength'   => $options['maxlength'] ?? null,
    'onchange'    => $options['onchange'] ?? '',
    'has_errors'  => isset($this->errors[$name]),
    'errors'      => $this->errors[$name] ?? [],
    'helptext'    => $options['helptext'] ?? '',
]
```

#### checkboxList

**`prepareCheckboxListData()` returns:**
```php
[
    'name'         => $name,
    'label'        => $label,
    'id'           => $options['id'] ?? $name,
    'options_list' => $options['options'] ?? [],  // always [value => label]
    'checked'      => /* normalized array */,
    'disabled'     => $options['disabled'] ?? [],  // per-item array
    'readonly'     => $options['readonly'] ?? [],  // per-item array
    'type'         => $options['type'] ?? 'checkbox',  // 'checkbox' or 'radio'
    'has_errors'   => isset($this->errors[$name]),
    'errors'       => $this->errors[$name] ?? [],
    'helptext'     => $options['helptext'] ?? '',
]
```

**Checked normalization:** Accept `$options['checked']` (array) or fall back to `$options['value'] ?? ($this->values[$name] ?? [])`. Ensure result is always an array.

**Validation (centralized):** If `type === 'radio'` and `count(checked) > 1`, throw `DisplayableUserException`. If `type === 'radio'` and `readonly` is non-empty, throw. If `type` is not `'checkbox'` or `'radio'`, throw.

**Option iteration is `[value => label]` — base class enforces this. The `checked` and `disabled` arrays contain values (not labels).**

#### textbox (WYSIWYG)

**`prepareTextboxData()` returns:**
```php
[
    'name'      => $name,
    'label'     => $label,
    'id'        => $options['id'] ?? $name,
    'value'     => $options['value'] ?? ($this->values[$name] ?? ''),
    'class'     => $options['class'] ?? '',
    'rows'      => $options['rows'] ?? 10,
    'htmlmode'  => !empty($options['htmlmode']),
    'readonly'  => !empty($options['readonly']),
    'disabled'  => !empty($options['disabled']),
    'has_errors'=> isset($this->errors[$name]),
    'errors'    => $this->errors[$name] ?? [],
    'helptext'  => $options['helptext'] ?? '',
]
```

**Trumbowyg script loading:** The CDN script/link injection and initialization JS is identical across implementations. Move it to a base class method `buildTrumbowygScript($id)` that renderers call.

#### imageInput

**`prepareImageData()` returns:**
```php
[
    'name'         => $name,
    'label'        => $label,
    'id'           => $options['id'] ?? $name,
    'value'        => $options['value'] ?? ($this->values[$name] ?? ''),
    'images'       => $options['images'] ?? [],
    'preview_size' => $options['preview_size'] ?? '100px',
    'class'        => $options['class'] ?? '',
    'disabled'     => !empty($options['disabled']),
    'has_errors'   => isset($this->errors[$name]),
    'errors'       => $this->errors[$name] ?? [],
    'helptext'     => $options['helptext'] ?? '',
]
```

**Modal picker JS:** The image picker modal and selection logic is identical across implementations. Move to a base class method `buildImagePickerScript($data)`.

## Shared Helper Methods

Add to the base class for use by renderers:

```php
/**
 * Build common HTML attributes string from a resolved data array.
 * Renderers call this to avoid repeating boilerplate attribute generation.
 */
protected function buildCommonAttributes($data, $extra_keys = []) {
    $attrs = '';
    if (!empty($data['disabled'])) $attrs .= ' disabled';
    if (!empty($data['required'])) $attrs .= ' required';
    if (!empty($data['readonly'])) $attrs .= ' readonly';
    if (!empty($data['autofocus'])) $attrs .= ' autofocus';
    if (!empty($data['onchange'])) $attrs .= ' onchange="' . htmlspecialchars($data['onchange']) . '"';
    if (!empty($data['autocomplete'])) $attrs .= ' autocomplete="' . htmlspecialchars($data['autocomplete']) . '"';
    foreach ($extra_keys as $key => $attr_name) {
        if (isset($data[$key])) $attrs .= ' ' . $attr_name . '="' . htmlspecialchars($data[$key]) . '"';
    }
    return $attrs;
}

/**
 * Build ARIA error attributes for accessibility.
 */
protected function buildErrorAttributes($data) {
    if (!$data['has_errors']) return '';
    return ' aria-invalid="true" aria-describedby="' . htmlspecialchars($data['name']) . '_error"';
}

/**
 * Build the inline AJAX search-select script (shared by all dropdown renderers).
 */
protected function buildAjaxSelectScript($id, $endpoint) { /* ... */ }

/**
 * Build the Trumbowyg WYSIWYG initialization (shared by all textbox renderers).
 */
protected function buildTrumbowygScript($id) { /* ... */ }

/**
 * Build the image picker modal script (shared by all image input renderers).
 */
protected function buildImagePickerScript($data) { /* ... */ }
```

## CSS Class Defaults

The `prepare*Data()` methods set `class` to `$options['class'] ?? ''`. If the caller did not specify a class, the renderers apply their own theme-specific default:

```php
// In renderTextInput():
$class = $data['class'] ?: 'form-control';  // Bootstrap default
$class = $data['class'] ?: 'mt-1 block w-full rounded-md ...';  // Tailwind default
$class = $data['class'] ?: 'form-control';  // HTML5 default
```

This keeps CSS framework knowledge out of the base class.

## Visibility Rules and Custom Scripts

Several field types support `visibility_rules` and `custom_script` options. These generate JS that must be output alongside (or after) the field HTML.

**Approach:** The base class `output*()` method handles this uniformly after the render call:

```php
protected function outputCheckboxInput($name, $label, $options) {
    $data = $this->prepareCheckboxData($name, $label, $options);
    $html = $this->renderCheckboxInput($data);
    $this->handleOutput($name, $html);

    // Visibility/script handling — same for all themes
    if ($data['visibility_rules']) {
        echo $this->generateVisibilityScript($name, $data['id'], $data['visibility_rules']);
    } elseif ($data['custom_script']) {
        echo $this->generateFieldScript($data['id'], $data['custom_script']);
    }
}
```

This removes the need for each renderer to remember to handle scripts.

## Testing

### Automated output comparison (primary verification)

Create a CLI test harness at `utils/formwriter_refactor_test.php`. **This file is temporary — delete it after the refactor is verified and deployed.**

The harness instantiates all three FormWriter classes, calls every field method with identical inputs, captures the HTML output, and hashes it. The refactor is correct if and only if every hash matches the pre-refactor baseline.

**Workflow:**
1. Before refactoring: `php utils/formwriter_refactor_test.php --baseline` — saves hashes to `utils/formwriter_refactor_baseline.json`
2. After refactoring: `php utils/formwriter_refactor_test.php` — compares against baseline, reports any mismatches
3. After verification: delete both files

**Test harness script:**

```php
<?php
/**
 * FormWriter Refactor Output Comparison Test
 *
 * TEMPORARY — delete after formwriter_base_class_refactor spec is complete.
 *
 * Captures the HTML output of every FormWriter field method across all three
 * implementations and compares MD5 hashes against a saved baseline.
 * If every hash matches, the refactor introduced zero output changes.
 *
 * Usage:
 *   php utils/formwriter_refactor_test.php --baseline   # Save baseline hashes (run BEFORE refactor)
 *   php utils/formwriter_refactor_test.php              # Compare against baseline (run AFTER refactor)
 */

// Bootstrap the application without a web request
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));
require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));
require_once(PathHelper::getIncludePath('includes/FormWriterV2Tailwind.php'));

$baseline_file = __DIR__ . '/formwriter_refactor_baseline.json';
$is_baseline = in_array('--baseline', $argv ?? []);

// ── FormWriter classes to test ──────────────────────────────────────────────

$classes = [
    'HTML5'     => 'FormWriterV2HTML5',
    'Bootstrap' => 'FormWriterV2Bootstrap',
    'Tailwind'  => 'FormWriterV2Tailwind',
];

// ── Mock values (simulates model pre-population via constructor) ─────────────

$mock_values = [
    'prefilled_text'     => 'Hello World',
    'prefilled_email'    => 'test@example.com',
    'prefilled_password' => 'secret123',
    'prefilled_date'     => '2026-06-15',
    'prefilled_time'     => '14:30',
    'prefilled_textarea' => 'Some long text content',
    'prefilled_drop'     => 'option_b',
    'prefilled_radio'    => 'choice2',
    'prefilled_checkbox' => '1',
    'prefilled_hidden'   => 'hidden_val',
    'prefilled_number'   => '42',
];

// ── Mock errors (simulates validation failure) ──────────────────────────────

$mock_errors = [
    'error_text'     => ['This field is required', 'Must be at least 3 characters'],
    'error_checkbox' => ['You must accept the terms'],
    'error_drop'     => ['Please select an option'],
    'error_radio'    => ['Please choose one'],
    'error_date'     => ['Invalid date'],
    'error_textarea' => ['Too short'],
    'error_file'     => ['File too large'],
];

// ── Shared option sets ──────────────────────────────────────────────────────

$dropdown_options = [
    'option_a' => 'Option A',
    'option_b' => 'Option B',
    'option_c' => 'Option C',
];

$radio_options = [
    'choice1' => 'Choice One',
    'choice2' => 'Choice Two',
    'choice3' => 'Choice Three',
];

$checkbox_list_options = [
    'apple'  => 'Apple',
    'banana' => 'Banana',
    'cherry' => 'Cherry',
];

// ── Test case definitions ───────────────────────────────────────────────────
// Each entry: [method, name, label, options, use_values, use_errors]
//   use_values: bool — construct FormWriter with $mock_values
//   use_errors: bool — inject $mock_errors into FormWriter

$test_cases = [

    // ── textinput ───────────────────────────────────────────────────────────
    ['textinput', 'text_basic', 'Basic Text', [], false, false],
    ['textinput', 'text_with_value', 'With Value', ['value' => 'explicit'], false, false],
    ['textinput', 'prefilled_text', 'Prefilled Text', [], true, false],
    ['textinput', 'text_placeholder_empty', 'Placeholder Empty', ['placeholder' => 'Type here'], false, false],
    ['textinput', 'text_placeholder_filled', 'Placeholder Filled',
        ['value' => 'filled', 'placeholder' => 'Should not show'], false, false],
    ['textinput', 'text_type_email', 'Email Type', ['type' => 'email', 'value' => 'a@b.com'], false, false],
    ['textinput', 'text_type_tel', 'Tel Type', ['type' => 'tel'], false, false],
    ['textinput', 'text_attrs', 'All Attributes', [
        'value' => 'v', 'required' => true, 'disabled' => true, 'readonly' => true,
        'autofocus' => true, 'autocomplete' => 'off', 'onchange' => 'doStuff()',
        'pattern' => '[A-Z]+', 'min' => '0', 'max' => '100', 'step' => '5',
        'minlength' => 2, 'maxlength' => 50, 'helptext' => 'Help text here',
    ], false, false],
    ['textinput', 'error_text', 'With Errors', [], false, true],
    ['textinput', 'text_prepend', 'With Prepend', ['prepend' => '$', 'value' => '99'], false, false],
    ['textinput', 'text_custom_id', 'Custom ID', ['id' => 'my_custom_id'], false, false],
    ['textinput', 'text_custom_class', 'Custom Class', ['class' => 'special-input'], false, false],

    // ── passwordinput ───────────────────────────────────────────────────────
    ['passwordinput', 'pass_basic', 'Password', [], false, false],
    ['passwordinput', 'prefilled_password', 'Prefilled Password', [], true, false],
    ['passwordinput', 'pass_strength', 'With Strength Meter', ['strength_meter' => true], false, false],
    ['passwordinput', 'pass_placeholder', 'With Placeholder',
        ['placeholder' => 'Enter password'], false, false],
    ['passwordinput', 'pass_autocomplete', 'Autocomplete Off',
        ['autocomplete' => 'new-password'], false, false],

    // ── numberinput ─────────────────────────────────────────────────────────
    ['numberinput', 'num_basic', 'Basic Number', [], false, false],
    ['numberinput', 'prefilled_number', 'Prefilled Number', [], true, false],
    ['numberinput', 'num_range', 'With Range',
        ['value' => '10', 'min' => '0', 'max' => '100', 'step' => '5'], false, false],

    // ── dropinput ───────────────────────────────────────────────────────────
    ['dropinput', 'drop_basic', 'Basic Dropdown', ['options' => $dropdown_options], false, false],
    ['dropinput', 'drop_selected', 'With Selection',
        ['options' => $dropdown_options, 'value' => 'option_b'], false, false],
    ['dropinput', 'prefilled_drop', 'Prefilled Drop',
        ['options' => $dropdown_options], true, false],
    ['dropinput', 'drop_empty_string', 'Empty Option String',
        ['options' => $dropdown_options, 'empty_option' => '-- Pick one --'], false, false],
    ['dropinput', 'drop_empty_bool', 'Empty Option Bool',
        ['options' => $dropdown_options, 'empty_option' => true], false, false],
    ['dropinput', 'drop_bool_value', 'Boolean Value',
        ['options' => [0 => 'No', 1 => 'Yes'], 'value' => true], false, false],
    ['dropinput', 'drop_disabled', 'Disabled',
        ['options' => $dropdown_options, 'disabled' => true], false, false],
    ['dropinput', 'drop_onchange', 'With Onchange',
        ['options' => $dropdown_options, 'onchange' => 'alert(1)'], false, false],
    ['dropinput', 'error_drop', 'With Errors',
        ['options' => $dropdown_options], false, true],
    ['dropinput', 'drop_ajax', 'With AJAX',
        ['options' => [], 'ajaxendpoint' => '/ajax/search'], false, false],
    ['dropinput', 'drop_multiple', 'Multiple Select',
        ['options' => $dropdown_options, 'multiple' => true], false, false],
    ['dropinput', 'drop_helptext', 'With Helptext',
        ['options' => $dropdown_options, 'helptext' => 'Pick wisely'], false, false],

    // ── checkboxinput ───────────────────────────────────────────────────────
    ['checkboxinput', 'cb_basic', 'Basic Checkbox', [], false, false],
    ['checkboxinput', 'cb_checked_true', 'Checked True', ['checked' => true], false, false],
    ['checkboxinput', 'cb_checked_false', 'Checked False', ['checked' => false], false, false],
    ['checkboxinput', 'cb_checked_1', 'Checked 1', ['checked' => 1], false, false],
    ['checkboxinput', 'cb_checked_0', 'Checked 0', ['checked' => 0], false, false],
    ['checkboxinput', 'cb_value_match', 'Value Match', ['value' => 1], false, false],
    ['checkboxinput', 'cb_value_nomatch', 'Value No Match', ['value' => 0], false, false],
    ['checkboxinput', 'cb_value_and_checked', 'Value+Checked',
        ['value' => 1, 'checked' => false], false, false],
    ['checkboxinput', 'cb_custom_checked_value', 'Custom Checked Value',
        ['value' => 'yes', 'checked_value' => 'yes'], false, false],
    ['checkboxinput', 'prefilled_checkbox', 'Prefilled Checkbox', [], true, false],
    ['checkboxinput', 'cb_disabled', 'Disabled', ['checked' => true, 'disabled' => true], false, false],
    ['checkboxinput', 'cb_required', 'Required', ['required' => true], false, false],
    ['checkboxinput', 'cb_onchange', 'With Onchange',
        ['onchange' => 'toggle()', 'checked' => true], false, false],
    ['checkboxinput', 'error_checkbox', 'With Errors', ['checked' => true], false, true],
    ['checkboxinput', 'cb_helptext', 'With Helptext',
        ['helptext' => 'Check this box', 'checked' => true], false, false],

    // ── radioinput ──────────────────────────────────────────────────────────
    ['radioinput', 'radio_basic', 'Basic Radio', ['options' => $radio_options], false, false],
    ['radioinput', 'radio_selected', 'With Selection',
        ['options' => $radio_options, 'value' => 'choice2'], false, false],
    ['radioinput', 'prefilled_radio', 'Prefilled Radio',
        ['options' => $radio_options], true, false],
    ['radioinput', 'radio_disabled', 'Disabled',
        ['options' => $radio_options, 'disabled' => true], false, false],
    ['radioinput', 'error_radio', 'With Errors',
        ['options' => $radio_options], false, true],
    ['radioinput', 'radio_helptext', 'With Helptext',
        ['options' => $radio_options, 'helptext' => 'Choose one'], false, false],

    // ── dateinput ───────────────────────────────────────────────────────────
    ['dateinput', 'date_basic', 'Basic Date', [], false, false],
    ['dateinput', 'prefilled_date', 'Prefilled Date', [], true, false],
    ['dateinput', 'date_minmax', 'With Min/Max',
        ['value' => '2026-06-15', 'min' => '2026-01-01', 'max' => '2026-12-31'], false, false],
    ['dateinput', 'date_disabled', 'Disabled', ['disabled' => true], false, false],
    ['dateinput', 'error_date', 'With Errors', [], false, true],

    // ── timeinput ───────────────────────────────────────────────────────────
    ['timeinput', 'time_basic', 'Basic Time', [], false, false],
    ['timeinput', 'prefilled_time', 'Prefilled Time', [], true, false],
    ['timeinput', 'time_value', 'Explicit Time', ['value' => '09:30'], false, false],

    // ── datetimeinput ───────────────────────────────────────────────────────
    ['datetimeinput', 'dt_basic', 'Basic DateTime', [], false, false],
    ['datetimeinput', 'dt_value', 'With Value',
        ['value' => '2026-06-15 14:30'], false, false],

    // ── textarea / textbox ──────────────────────────────────────────────────
    ['textarea', 'ta_basic', 'Basic Textarea', [], false, false],
    ['textarea', 'prefilled_textarea', 'Prefilled Textarea', [], true, false],
    ['textarea', 'ta_rows_cols', 'Custom Rows/Cols', ['rows' => 10, 'cols' => 40], false, false],
    ['textarea', 'ta_placeholder', 'With Placeholder',
        ['placeholder' => 'Enter text'], false, false],
    ['textarea', 'ta_attrs', 'All Attributes', [
        'value' => 'content', 'required' => true, 'disabled' => true,
        'readonly' => true, 'minlength' => 5, 'maxlength' => 500,
        'onchange' => 'update()', 'helptext' => 'Write something',
    ], false, false],
    ['textarea', 'error_textarea', 'With Errors', [], false, true],

    ['textbox', 'tb_basic', 'Basic Textbox', [], false, false],
    ['textbox', 'tb_html', 'HTML Mode', ['htmlmode' => true, 'value' => '<p>Hello</p>'], false, false],

    // ── fileinput ───────────────────────────────────────────────────────────
    ['fileinput', 'file_basic', 'Basic File', [], false, false],
    ['fileinput', 'file_accept', 'With Accept', ['accept' => '.pdf,.doc'], false, false],
    ['fileinput', 'file_multiple', 'Multiple', ['multiple' => true], false, false],
    ['fileinput', 'file_disabled', 'Disabled', ['disabled' => true], false, false],
    ['fileinput', 'error_file', 'With Errors', [], false, true],

    // ── hiddeninput ─────────────────────────────────────────────────────────
    ['hiddeninput', 'hidden_basic', 'Hidden', ['value' => 'secret'], false, false],
    ['hiddeninput', 'prefilled_hidden', '', [], true, false],
    ['hiddeninput', 'hidden_empty', '', [], false, false],

    // ── submitbutton ────────────────────────────────────────────────────────
    ['submitbutton', 'btn_basic', 'Submit', [], false, false],
    ['submitbutton', 'btn_class', 'Save', ['class' => 'btn btn-success'], false, false],
    ['submitbutton', 'btn_disabled', 'Wait', ['disabled' => true], false, false],
    ['submitbutton', 'btn_onclick', 'Confirm', ['onclick' => 'return confirm()'], false, false],

    // ── checkboxlist ────────────────────────────────────────────────────────
    ['checkboxlist', 'cbl_basic', 'Basic List',
        ['options' => $checkbox_list_options], false, false],
    ['checkboxlist', 'cbl_checked', 'With Checked',
        ['options' => $checkbox_list_options, 'checked' => ['apple', 'cherry']], false, false],
    ['checkboxlist', 'cbl_disabled', 'With Disabled Items',
        ['options' => $checkbox_list_options, 'checked' => ['banana'],
         'disabled' => ['cherry']], false, false],
    ['checkboxlist', 'cbl_readonly', 'With Readonly Items',
        ['options' => $checkbox_list_options, 'checked' => ['apple'],
         'readonly' => ['apple']], false, false],
    ['checkboxlist', 'cbl_radio', 'Radio Mode',
        ['options' => $checkbox_list_options, 'checked' => ['banana'],
         'type' => 'radio'], false, false],
];

// ── Test runner ─────────────────────────────────────────────────────────────

function capture_output($formwriter, $method, $name, $label, $options) {
    ob_start();
    $formwriter->$method($name, $label, $options);
    return ob_get_clean();
}

$results = [];
$test_count = 0;

foreach ($classes as $class_label => $class_name) {
    foreach ($test_cases as $tc) {
        list($method, $name, $label, $options, $use_values, $use_errors) = $tc;

        $constructor_opts = ['method' => 'GET', 'csrf' => false];
        if ($use_values) {
            $constructor_opts['values'] = $mock_values;
        }

        $fw = new $class_name('test_form', $constructor_opts);

        if ($use_errors) {
            // Inject errors via reflection (errors is protected)
            $ref = new ReflectionProperty($class_name, 'errors');
            $ref->setAccessible(true);
            $ref->setValue($fw, $mock_errors);
        }

        $output = capture_output($fw, $method, $name, $label, $options);
        $hash = md5($output);

        $key = "{$class_label}::{$method}::{$name}";
        $results[$key] = $hash;
        $test_count++;
    }
}

// ── Baseline save or comparison ─────────────────────────────────────────────

if ($is_baseline) {
    file_put_contents($baseline_file, json_encode($results, JSON_PRETTY_PRINT));
    echo "Baseline saved: {$test_count} hashes written to {$baseline_file}\n";
    exit(0);
}

if (!file_exists($baseline_file)) {
    echo "ERROR: No baseline file found. Run with --baseline first.\n";
    exit(1);
}

$baseline = json_decode(file_get_contents($baseline_file), true);
$pass = 0;
$fail = 0;
$new_keys = 0;

foreach ($results as $key => $hash) {
    if (!isset($baseline[$key])) {
        echo "  NEW   {$key}\n";
        $new_keys++;
    } elseif ($baseline[$key] !== $hash) {
        echo "  FAIL  {$key}  (expected {$baseline[$key]}, got {$hash})\n";
        $fail++;
    } else {
        $pass++;
    }
}

// Check for removed keys
foreach ($baseline as $key => $hash) {
    if (!isset($results[$key])) {
        echo "  GONE  {$key}\n";
        $fail++;
    }
}

echo "\nResults: {$pass} passed, {$fail} failed, {$new_keys} new\n";
exit($fail > 0 ? 1 : 0);
```

### Additional verification

After the automated comparison passes:

1. **Syntax validation:** `php -l` on all 4 FormWriter files
2. **Method validator:** Run `validate_php_file.php` on all 4 files
3. **Visual spot-check (test site):**
   - Load an admin page with forms (Bootstrap)
   - Load a public page with forms (HTML5)
   - Verify rendering looks the same

## Documentation Updates

Update `/docs/formwriter.md` with:

1. A new section **"Architecture: Base Class vs. Renderers"** explaining the prepare/render split
2. Updated guidance for developers creating new FormWriter themes: implement `render*()` methods, not `output*()`
3. Document the `$data` array keys for each field type so theme developers know what's available

## Success Criteria

- All 15 `output*()` methods converted to concrete base methods + abstract `render*()` methods
- Zero behavioral logic in any subclass `render*()` method
- All existing forms render identically before and after the refactor
- Adding a new option (like `'checked'`) requires changes in only one place (the base class `prepare*Data()` method)
- A new theme can be created by implementing only `render*()` methods with no behavioral decisions

## Phase 2: Cleanup and DRY Completion

The initial refactor (Phase 1) correctly moved behavioral logic into `prepare*Data()` methods. Phase 2 addresses leftover duplication and dead code that prevents the renderers from being truly minimal.

### Fix 1: Move visibility_rules/custom_script to base output methods

**Problem:** The spec says visibility/custom_script handling should be in the base `output*()` methods (§ Visibility Rules and Custom Scripts), but the implementation left them inside `renderDropInput()` (all 3 subclasses) and `renderCheckboxInput()` (Tailwind). This means each renderer must remember to include them, which is exactly the drift the refactor was designed to prevent.

**Fix:** Remove visibility/custom_script blocks from all render methods. Add centralized handling in base `outputDropInput()` and `outputCheckboxInput()`, appending the script HTML before `handleOutput()` so deferred output mode still works.

### Fix 2: Extract AJAX search-select script to base class

**Problem:** The spec calls for `buildAjaxSelectScript($id, $endpoint)` in the base class, but it was never created. The identical ~100-line `AjaxSearchSelect` JS class is duplicated in `renderDropInput()` across all three subclasses (only comments differ).

**Fix:** Create `buildAjaxSelectScript($id, $endpoint)` in `FormWriterV2Base`. Replace the inline script blocks in all three renderers with `$this->buildAjaxSelectScript($id, $data['ajaxendpoint'])`.

### Fix 3: Activate buildCommonAttributes / buildErrorAttributes

**Problem:** Both helper methods were added to the base class per spec but are never called. Every renderer manually writes 6-18 lines of `if ($data['foo']) $html .= ' foo';` boilerplate.

**Fix:** Use `buildCommonAttributes()` (with `extra_keys` for nullable attributes like min/max/step) and `buildErrorAttributes()` in `renderTextInput()` across all three subclasses, since that method has the most attribute boilerplate. Other methods left as-is since the reduction is marginal.

### Fix 4: Fix outputTextbox to use handleOutput

**Problem:** `outputTextbox()` calls `$this->renderTextbox($data)` without capturing the return value or calling `handleOutput()`. This breaks deferred output mode for textbox fields.

**Fix:**
- Base `outputTextbox()`: capture return value and pass to `handleOutput()`.
- Tailwind `renderTextbox()`: return the HTML string instead of calling `handleOutput()` internally.
- HTML5/Bootstrap `renderTextbox()`: wrap existing `$this->textbox()` delegation in `ob_start()`/`ob_get_clean()` to capture the echoed output and return it as a string.

### Deferred: Trumbowyg and ImagePicker script extraction

The spec calls for `buildTrumbowygScript()` and `buildImagePickerScript()`, but the implementations differ too much across subclasses for simple extraction:

- **Trumbowyg:** HTML5 initializes per-element by ID; Bootstrap uses a class selector with a static dedup flag. Both override the public `textbox()` method directly (bypassing the render pattern), containing ~100-150 lines of CDN loading and initialization JS. Proper extraction requires first converting these public overrides to use the render pattern, which is a separate task.
- **ImagePicker:** HTML5 uses a custom CSS radio-button dropdown; Bootstrap/Tailwind use a hidden input with preview thumbnail and placeholder button. These are fundamentally different UI patterns, not candidates for a shared method.

### Testing

After Phase 2, regenerate the test baseline (`php utils/formwriter_refactor_test.php --baseline`) since attribute order changes from `buildCommonAttributes` will alter output hashes. Verify all 264 test cases pass against the new baseline, then visually spot-check admin and public pages.
