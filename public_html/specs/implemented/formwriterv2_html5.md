# FormWriterV2 HTML5 Implementation

## Overview
Create a plain HTML5 version of FormWriterV2 that generates semantic HTML5 forms without any CSS framework dependencies. This implementation should provide a solid, accessible foundation for form generation that any theme can build upon.

## Current State
- FormWriterV2Bootstrap exists and generates Bootstrap 5-compatible form HTML
- Some themes (Canvas, Falcon) use Bootstrap styling
- Other themes need a pure HTML5 option without framework-specific classes
- Current v1 FormWriterHTML5 has been deleted as part of the v2 migration

## Objectives
1. Create FormWriterV2HTML5 class that extends FormWriterV2Base
2. Implement all required form field methods with semantic HTML5 markup
3. Provide proper form structure without framework-specific styling classes
4. Ensure accessibility (labels, ARIA attributes, proper semantic tags)
5. Support all FormWriter v2 API features (options arrays, validation, etc.)
6. Make it available as an alternative to Bootstrap for themes that prefer plain HTML5

## Scope
- Create `/var/www/html/joinerytest/public_html/includes/FormWriterV2HTML5.php`
- Implement all field generation methods from FormWriterV2Base
- Focus on semantic HTML5 with proper form structure
- Support form-level options (id, method, action, class, ajax, etc.)
- Include basic CSS classes for structural styling (form-group, form-control, etc.)
- Maintain compatibility with FormWriter v2 API

## Form Elements to Support
- Text inputs (text, email, password, tel, url, number, search, etc.)
- Textareas
- Rich text editor (outputTextbox)
- Image input (outputImageInput)
- Select dropdowns (with optional AJAX search)
- Checkboxes and radio buttons
- File inputs
- Date/time inputs (with time parsing and conversion)
- Hidden inputs
- Submit/reset buttons
- Form containers and grouping

## Implementation Notes

### Core Pattern Requirements
- Extend FormWriterV2Base for core functionality
- **All output methods MUST use string-building pattern**:
  - Build HTML in a `$html` string variable
  - Call `$this->handleOutput($field_name, $html)` for output (not direct echo)
  - This enables deferred output mode support and consistency
- Use semantic HTML5 tags (`<form>`, `<fieldset>`, `<legend>`, `<label>`, etc.)
- Consider accessibility: proper label associations, semantic markup, ARIA where needed

### Abstract Methods to Implement
FormWriterV2Base defines three abstract methods that MUST be implemented:
1. **outputTextbox($name, $label, $options)** - Rich text editor
   - Can use plain `<textarea>` for simplicity
   - Or include Trumbowyg library for advanced editing
   - Must support readonly and disabled options
2. **outputImageInput($name, $label, $options)** - Image selection with preview
   - Output hidden input for file path storage
   - Display image preview if value exists
   - Provide button/interface for image selection
3. **outputTextarea($name, $label, $options)** - Standard textarea field
   - Support rows/cols attributes
   - Support readonly and disabled options

### Deferred Output Mode Support
FormWriter v2 supports deferred output mode where field HTML is collected and rendered later:
- Fields are registered but output is collected in `$this->deferred_output` array
- All output methods MUST call `$this->handleOutput($field_name, $html)` instead of echo
- This is automatically handled by using the handleOutput method

### Time Handling
Use centralized helper methods from base class (do NOT duplicate logic):
- `$this->parseTimeValue($value)` - Parse time from 24-hour (HH:MM) or 12-hour (H:MM AM/PM) formats
- `$this->convertTimeToDatabase($hour, $minute, $ampm)` - Convert 12-hour display format to 24-hour database format
- `$this->formatTimeForDisplay($value, $format='12hour')` - Format time for display from database value

### JavaScript Support
Reuse shared JavaScript methods from base class or provide custom implementations:
- `$this->outputTimeInputJavaScript()` - Provides time input synchronization (updateTimeInput function)
- `$this->outputAjaxSearchSelectJavaScript()` - Provides AJAX search select class for dropdowns

For time inputs: Call the base class method to output shared JavaScript for syncing hour/minute/ampm to hidden field.

For AJAX dropdowns: Either reuse the shared method or implement custom AJAX solution using HTML5 `<input>` + `<datalist>`.

### Validation and Error Handling
- Support HTML5 validation attributes in options array:
  - `required` - boolean, adds 'required' attribute
  - `pattern` - regex string, adds 'pattern' attribute
  - `min`, `max` - numeric, for numeric/date inputs
  - `minlength`, `maxlength` - numeric, for text inputs
- Display server-side validation errors:
  - Check `$this->errors[$field_name]` for validation error array
  - Wrap errors in semantic markup (e.g., `<div class="form-error">` or `<ul class="error-list">`)
  - Apply 'is-invalid' class to field element itself for styling
  - Consider adding `aria-invalid="true"` and `aria-describedby` for accessibility

### CSS Classes (Minimal Framework)
Use basic class naming for structural styling (these have NO framework dependencies):
- `form-group` - Wrapper div containing label + input (for layout grouping)
- `form-control` - Applied to input, select, textarea (base input styling)
- `form-label` - Applied to labels
- `form-check` - Wrapper for checkbox/radio items
- `form-check-input` - Applied to checkbox/radio inputs
- `form-check-label` - Applied to checkbox/radio labels
- `is-invalid` - Applied to fields with validation errors (indicates invalid state)

These follow Bootstrap naming conventions for familiarity but have NO CSS dependencies. Themes can style these classes as needed or override with custom classes via options.

### AJAX Dropdowns
When `ajaxendpoint` option is provided in outputDropInput():
- Output searchable dropdown functionality
- Can use shared `outputAjaxSearchSelectJavaScript()` from base class (wraps select in search input + datalist)
- Or implement custom solution with `<input type="text">` + JavaScript fetch
- AJAX endpoint should accept query parameter 'q' and return JSON: `[{id: value, text: label}, ...]`
- Store selected ID in hidden select element for form submission

## Themes That May Use This
- Themes that want pure HTML5 without CSS framework overhead
- Custom/minimal themes
- Themes preferring to write their own CSS
- Future themes that don't depend on Bootstrap or Tailwind

## Related Work
- FormWriterV2Bootstrap: Bootstrap 5 implementation (reference)
- FormWriterV2Base: Base class with common functionality
- Previous v1 FormWriterHTML5: Deleted in migration (can review git history if needed)

## Implementation Decisions Made

### outputTextbox() Implementation
- **Decision**: Use plain `<textarea>` for HTML5 version (simpler, no dependencies)
- Alternative: Include Trumbowyg library if rich text editing is needed
- Rationale: HTML5 is meant to be minimal; themes can add rich editor if desired

### AJAX Dropdown Implementation
- **Decision**: Reuse `outputAjaxSearchSelectJavaScript()` from base class
- This provides consistent behavior across Bootstrap, Tailwind, and HTML5
- Fallback: Can implement custom solution if base class method doesn't suit

### CSS Class Naming
- **Decision**: Use Bootstrap-compatible names (form-group, form-control, is-invalid)
- Rationale: Familiar convention, but with zero framework dependencies
- Themes can override via custom class options on each field

## Testing
- Verify all form field methods work correctly
- Test form submission and validation
- Ensure HTML5 semantic correctness
- Validate accessibility (labels, ARIA attributes)
- Test deferred output mode (collect output, render later)
- Test time input/datetime input synchronization
- Test AJAX dropdown search functionality
- Test with at least one theme using HTML5 version
- Verify method existence test passes (no missing function calls)

## Priority
Medium - Nice to have for theme flexibility, but not blocking current operations since Bootstrap version exists

## Related Specifications
- `formwriter_v2_architecture_improvements.md` - Core architecture improvements (completed)
- FormWriterV2Base implementation - Base class with shared logic
- FormWriterV2Bootstrap implementation - Reference implementation
- FormWriterV2Tailwind implementation - Reference implementation
