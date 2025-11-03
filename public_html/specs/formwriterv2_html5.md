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
- Text inputs (text, email, password, tel, etc.)
- Textareas
- Select dropdowns
- Checkboxes and radio buttons
- File inputs
- Date/time inputs
- Hidden inputs
- Submit/reset buttons
- Form containers and grouping

## Implementation Notes
- Extend FormWriterV2Base for core functionality
- Use semantic HTML5 tags (`<form>`, `<fieldset>`, `<legend>`, `<label>`, etc.)
- Implement proper form validation attributes
- Use data-* attributes for JavaScript hooks (validation, ajax, etc.)
- Consider accessibility: proper label associations, semantic markup, ARIA where needed
- Provide consistent class naming for basic styling (form-group, form-control, form-label, etc.)

## Themes That May Use This
- Themes that want pure HTML5 without CSS framework overhead
- Custom/minimal themes
- Themes preferring to write their own CSS
- Future themes that don't depend on Bootstrap or Tailwind

## Related Work
- FormWriterV2Bootstrap: Bootstrap 5 implementation (reference)
- FormWriterV2Base: Base class with common functionality
- Previous v1 FormWriterHTML5: Deleted in migration (can review git history if needed)

## Testing
- Verify all form field methods work correctly
- Test form submission and validation
- Ensure HTML5 semantic correctness
- Validate accessibility
- Test with at least one theme using HTML5 version

## Priority
Medium - Nice to have for theme flexibility, but not blocking current operations since Bootstrap version exists
