# FormWriter Refactoring Specification

## Executive Summary

The current FormWriter implementation has architectural inconsistencies where different themes implement different methods independently, creating duplication and making maintenance difficult. This specification outlines a refactoring to establish a proper inheritance hierarchy with FormWriterBase remaining abstract but providing utility methods and placeholder stubs, FormWriterHTML5 as a complete plain HTML implementation, and framework-specific classes implementing their own markup while maintaining compatible method signatures.

## Current State Analysis

### Problems Identified

1. **Inconsistent Method Implementation**
   - FormWriterBase is abstract but contains only utility methods (captcha, honeypot, validation)
   - No abstract method declarations for required form elements
   - Each framework implementation (Bootstrap, Tailwind, UIKit) implements all form methods independently
   - The jeremytunnell theme implements a partial plain HTML version independently

2. **Code Duplication**
   - Common logic is duplicated across all implementations
   - No shared implementation of basic HTML form elements
   - Each framework reimplements the same methods with only styling differences

3. **Missing Base Implementation**
   - FormWriterBase doesn't provide default HTML5 implementations
   - Themes that want plain HTML must implement everything from scratch
   - No fallback for missing methods

4. **Inheritance Issues**
   - FormWriterBase is abstract but doesn't define the contract (no abstract methods)
   - Framework-specific classes extend FormWriterBase but don't share implementations
   - Theme-specific FormWriters sometimes extend nothing, sometimes extend framework classes

5. **Naming Inconsistencies**
   - FormWriterBootstrap, FormWriterTailwind, FormWriterUIKit (inconsistent "Master" naming)
   - Some themes use FormWriter.php, others use FormWriterPublic.php
   - No clear naming convention

## Proposed Architecture

### Class Hierarchy

```
FormWriterBase (abstract class with utility methods and placeholder stubs)
├── FormWriterHTML5 (concrete plain HTML5 implementation)
├── FormWriterBootstrap (concrete Bootstrap implementation)
├── FormWriterTailwind (concrete Tailwind implementation)
├── FormWriterUIKit (concrete UIKit implementation)
└── Theme-specific FormWriter (extends appropriate implementation)
```

### Design Principles

1. **FormWriterBase as Abstract Foundation**
   - Remains abstract with utility methods (captcha, honeypot, validation, hidden inputs)
   - Contains placeholder method stubs that return informative error messages
   - Defines the complete interface all FormWriters must implement
   - Provides helpful debugging when methods aren't overridden

2. **FormWriterHTML5 as Plain Implementation**
   - Copy of FormWriterBootstrap fully converted to semantic HTML5
   - No CSS framework dependencies
   - Serves as reference implementation and fallback

3. **Framework Classes as Independent Implementations**
   - Each framework class implements all form methods independently
   - No attempt to share markup-generating code (it's too different)
   - All maintain compatible method signatures
   - Can extend FormWriterBase for utility methods

4. **Compatibility Through Interface**
   - All implementations have the same method signatures
   - Themes can switch between implementations easily
   - Clear error messages when methods are missing

## Implementation Plan

### Completed Items

#### file_upload_full Migration (COMPLETED)
- Successfully moved `file_upload_full()` method from FormWriterBootstrap to FormWriterBase
- Converted Bootstrap-specific markup to plain HTML5
- Added overridable `multi_upload_button()` method for framework-specific button styling
- Removed duplicate implementations from FormWriterTailwind and FormWriterUIKit
- Both Tailwind and UIkit now properly inherit from Base
- **This method is complete and should NOT be included in further refactoring efforts**

### Phase 1: FormWriterBase Enhancement

**File:** `/includes/FormWriterBase.php`

Keep FormWriterBase abstract but add placeholder method stubs for all form element methods that provide informative error messages:

```php
abstract class FormWriterBase {
    // Existing utility methods remain (captcha, honeypot, validation, hidden inputs)...

    // Add placeholder stubs for all form element methods
    protected function _not_implemented($method_name, $params = array()) {
        $class_name = get_class($this);
        $param_info = array();
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $param_info[] = "$key=[array with " . count($value) . " items]";
            } elseif (is_bool($value)) {
                $param_info[] = "$key=" . ($value ? 'true' : 'false');
            } elseif (is_null($value)) {
                $param_info[] = "$key=null";
            } else {
                $param_info[] = "$key='" . substr(strval($value), 0, 50) . "'";
            }
        }

        return '<div style="border: 2px solid red; padding: 10px; margin: 10px 0; background: #fee;">
            <strong>FormWriter Method Not Implemented</strong><br>
            Class: ' . htmlspecialchars($class_name) . '<br>
            Method: ' . htmlspecialchars($method_name) . '()<br>
            Parameters: ' . htmlspecialchars(implode(', ', $param_info)) . '<br>
            <small>This method needs to be implemented in ' . htmlspecialchars($class_name) . '</small>
        </div>';
    }

    function text($id, $label, $value, $class, $layout = 'default') {
        return $this->_not_implemented('text', compact('id', 'label', 'value', 'class', 'layout'));
    }

    function textinput($label, $id, $class, $size, $value, $hint, $maxlength=255,
                      $readonly='', $autocomplete=TRUE, $formhint=FALSE,
                      $type='text', $layout='default') {
        return $this->_not_implemented('textinput',
            compact('label', 'id', 'class', 'size', 'value', 'hint',
                   'maxlength', 'readonly', 'autocomplete', 'formhint', 'type', 'layout'));
    }

    function passwordinput($label, $id, $class, $size, $value, $hint,
                          $maxlength=255, $readonly="", $layout='default') {
        return $this->_not_implemented('passwordinput',
            compact('label', 'id', 'class', 'size', 'value', 'hint',
                   'maxlength', 'readonly', 'layout'));
    }

    function fileinput($label, $id, $class, $size, $hint, $layout='default') {
        return $this->_not_implemented('fileinput',
            compact('label', 'id', 'class', 'size', 'hint', 'layout'));
    }

    function textbox($label, $id, $class, $rows, $cols, $value, $hint, $htmlmode="no") {
        return $this->_not_implemented('textbox',
            compact('label', 'id', 'class', 'rows', 'cols', 'value', 'hint', 'htmlmode'));
    }

    function checkboxinput($label, $id, $class, $align, $value, $truevalue, $hint, $layout='default') {
        return $this->_not_implemented('checkboxinput',
            compact('label', 'id', 'class', 'align', 'value', 'truevalue', 'hint', 'layout'));
    }

    function checkboxList($label, $id, $class, $optionvals, $checkedvals=array(),
                         $disabledvals=array(), $readonlyvals=array(), $hint='', $type='checkbox') {
        return $this->_not_implemented('checkboxList',
            compact('label', 'id', 'class', 'optionvals', 'checkedvals',
                   'disabledvals', 'readonlyvals', 'hint', 'type'));
    }

    function radioinput($label, $id, $class, &$optionvals, $checkedval,
                       $disabledvals, $readonlyvals, $hint) {
        return $this->_not_implemented('radioinput',
            compact('label', 'id', 'class', 'optionvals', 'checkedval',
                   'disabledvals', 'readonlyvals', 'hint'));
    }

    function dropinput($label, $id, $class, &$optionvals, $input, $hint,
                      $showdefault=TRUE, $forcestrict=FALSE, $ajaxendpoint=FALSE,
                      $imagedropdown=FALSE, $layout='default') {
        return $this->_not_implemented('dropinput',
            compact('label', 'id', 'class', 'optionvals', 'input', 'hint',
                   'showdefault', 'forcestrict', 'ajaxendpoint', 'imagedropdown', 'layout'));
    }

    function dateinput($label, $id, $class, $size, $value, $hint, $maxlength=255,
                      $readonly='', $autocomplete=TRUE, $formhint=FALSE, $layout='default') {
        return $this->_not_implemented('dateinput',
            compact('label', 'id', 'class', 'size', 'value', 'hint',
                   'maxlength', 'readonly', 'autocomplete', 'formhint', 'layout'));
    }

    function timeinput($label, $id, $class, $value, $hint, $layout='default') {
        return $this->_not_implemented('timeinput',
            compact('label', 'id', 'class', 'value', 'hint', 'layout'));
    }

    function datetimeinput($label, $id, $class, $inputdatetime, $hint,
                          $timehint, $datehint, $layout='default') {
        return $this->_not_implemented('datetimeinput',
            compact('label', 'id', 'class', 'inputdatetime', 'hint',
                   'timehint', 'datehint', 'layout'));
    }

    function datetimeinput2($label, $id, $class, $value, $hint, $readonly=false,
                           $formhint=FALSE, $layout='default') {
        return $this->_not_implemented('datetimeinput2',
            compact('label', 'id', 'class', 'value', 'hint', 'readonly',
                   'formhint', 'layout'));
    }

    function imageinput($label, $id, $class, &$optionvals, $input, $hint,
                       $showdefault=TRUE, $forcestrict=TRUE, $ajaxendpoint=FALSE) {
        return $this->_not_implemented('imageinput',
            compact('label', 'id', 'class', 'optionvals', 'input', 'hint',
                   'showdefault', 'forcestrict', 'ajaxendpoint'));
    }

    function new_button($label='Submit', $link, $style='primary', $width='standard',
                       $class='', $id=NULL) {
        return $this->_not_implemented('new_button',
            compact('label', 'link', 'style', 'width', 'class', 'id'));
    }

    function new_form_button($label='Submit', $style='primary', $width='standard',
                            $class='', $id=NULL) {
        return $this->_not_implemented('new_form_button',
            compact('label', 'style', 'width', 'class', 'id'));
    }

    function start_buttons($class = '') {
        return $this->_not_implemented('start_buttons', compact('class'));
    }

    function end_buttons() {
        return $this->_not_implemented('end_buttons', array());
    }
}
```

### Phase 2: Create FormWriterHTML5

**File:** `/includes/FormWriterHTML5.php`

1. **Copy FormWriterBootstrap entirely** to FormWriterHTML5
2. **Extend FormWriterBase** to inherit utility methods
3. **Convert all Bootstrap markup to plain HTML5**:
   - Remove all Bootstrap CSS classes (`mb-3`, `form-control`, `form-label`, etc.)
   - Replace Bootstrap components with semantic HTML
   - Keep all functionality and parameters intact
   - Maintain all JavaScript generation

Example conversion:
```php
// Bootstrap version:
$output = '<div id="' . $id . '_container" class="mb-3 errorplacement">';
$output .= '<label for="' . $id . '" class="form-label">' . $label . '</label>';
$output .= '<input type="text" class="form-control" ...>';

// HTML5 version:
$output = '<div id="' . $id . '_container" class="form-field errorplacement">';
$output .= '<label for="' . $id . '">' . $label . '</label>';
$output .= '<input type="text" ...>';
```

### Phase 3: Rename Framework Classes

1. **Copy and rename** (don't modify originals yet):
   - Copy `FormWriterBootstrap` → `FormWriterBootstrap` **(DO NOT ALTER - authorized changes only)**
   - Copy `FormWriterTailwind` → `FormWriterTailwind`
   - Copy `FormWriterUIKit` → `FormWriterUIKit`

2. **Update each to extend FormWriterBase**:
   - Change class declaration to extend FormWriterBase
   - Remove utility methods that are now in base class
   - **FormWriterBootstrap**: Keep exactly as-is, no modifications without authorization
   - **FormWriterTailwind & FormWriterUIKit**: Add missing methods for feature parity with Bootstrap

### Phase 4: Update LibraryFunctions::get_formwriter_object()

Update the factory method to use new class names:

```php
static function get_formwriter_object($form_id = 'form1', $override_name=NULL, $override_path=NULL) {
    // ... existing override logic ...

    if($override_name == 'admin' || $override_name == 'bootstrap') {
        PathHelper::requireOnce('includes/FormWriterBootstrap.php');
        return new FormWriterBootstrap($form_id);
    }
    else if($override_name == 'tailwind') {
        PathHelper::requireOnce('includes/FormWriterTailwind.php');
        return new FormWriterTailwind($form_id);
    }
    else if($override_name == 'uikit') {
        PathHelper::requireOnce('includes/FormWriterUIKit.php');
        return new FormWriterUIKit($form_id);
    }
    else if($override_name == 'plain' || $override_name == 'html5') {
        PathHelper::requireOnce('includes/FormWriterHTML5.php');
        return new FormWriterHTML5($form_id);
    }

    // ... rest of existing logic ...
}
```

### Phase 5: Bring Tailwind and UIKit to Feature Parity

**IMPORTANT: FormWriterBootstrap/Falcon is the reference implementation and must not be modified without authorization.**

Ensure FormWriterTailwind and FormWriterUIKit have all the same methods as FormWriterBootstrap:

1. **Audit missing methods** by comparing against FormWriterBootstrap:
   - Common missing: `imageinput`, `datetimeinput2`, advanced file upload features
   - JavaScript functionality from Bootstrap version
   - Any helper methods

2. **Add missing methods to FormWriterTailwind**:
   - Copy method signatures exactly from FormWriterBootstrap
   - Implement with Tailwind CSS classes and patterns
   - Maintain identical parameters and return types

3. **Add missing methods to FormWriterUIKit**:
   - Copy method signatures exactly from FormWriterBootstrap
   - Implement with UIKit CSS classes and patterns
   - Maintain identical parameters and return types

4. **Test framework switching**:
   - Forms should work when switching between any framework
   - All parameters must be compatible across implementations

### Phase 6: Update Theme FormWriters

1. **Remove Redundant Implementations**:
   - Theme FormWriters should only override methods they need to customize
   - Most themes can just extend the appropriate framework class

2. **jeremytunnell Theme**:
   - Can extend FormWriterHTML5 for plain HTML
   - Or implement its own custom styling on top of FormWriterHTML5

3. **Falcon Theme**:
   - Should extend FormWriterBootstrap directly
   - Remove reference to non-existent FormWriterHTML5Falcon
   - **DO NOT modify any Falcon/Bootstrap functionality without authorization**


## Testing Strategy

1. **Unit Tests for FormWriterBase**:
   - Test each method produces valid HTML5
   - Test all parameters work correctly
   - Test XSS protection (htmlspecialchars)

2. **Integration Tests**:
   - Test each framework class produces correct framework-specific markup
   - Test inheritance chain works correctly
   - Test theme overrides work

3. **Regression Tests**:
   - Test all existing forms continue to work
   - Test admin interface forms
   - Test theme-specific forms

## Implementation Phases

1. **Phase 1**: Update FormWriterBase with placeholder method stubs
2. **Phase 2**: Create FormWriterHTML5 from FormWriterBootstrap
3. **Phase 3**: Create new framework classes (Bootstrap, Tailwind, UIKit)
4. **Phase 4**: Update LibraryFunctions::get_formwriter_object()
5. **Phase 5**: Bring Tailwind and UIKit to feature parity with Bootstrap
6. **Phase 6**: Update theme-specific FormWriters
7. **Phase 7**: Testing and validation
8. **Phase 8**: Remove old FormWriterHTML5* classes

**CRITICAL**: FormWriterBootstrap and Falcon theme must not be altered without explicit authorization

## Benefits

1. **Maintainability**:
   - Single source of truth for form logic
   - Easier to add new form elements
   - Consistent behavior across frameworks

2. **Extensibility**:
   - Easy to add new CSS frameworks
   - Themes can extend at any level
   - Clear inheritance hierarchy

3. **Performance**:
   - Less code duplication
   - Smaller file sizes
   - Better caching

4. **Developer Experience**:
   - Clear, predictable API
   - Better IDE support
   - Easier to understand and debug

## Implementation Checklist

- [x] Update FormWriterBase with placeholder method stubs (completed 2025-01-14)
- [x] Create FormWriterHTML5 from copy of FormWriterBootstrap (completed 2025-01-14)
- [x] Convert FormWriterHTML5 to plain HTML5 (remove Bootstrap classes) (completed 2025-01-14)
- [x] Create FormWriterBootstrap from FormWriterBootstrap (NO MODIFICATIONS) (completed 2025-01-14)
- [x] Create FormWriterTailwind from FormWriterTailwind (completed 2025-01-14)
- [x] Create FormWriterUIKit from FormWriterUIKit (completed 2025-01-14)
- [x] Update all new classes to extend FormWriterBase (completed 2025-01-14)
- [x] Add missing methods to FormWriterTailwind for feature parity (completed 2025-01-14)
- [x] Add missing methods to FormWriterUIKit for feature parity (completed 2025-01-14)
- [x] Update LibraryFunctions::get_formwriter_object() (completed 2025-01-14)
- [x] Update theme FormWriters to use new classes (completed 2025-01-14)
- [ ] Test all forms in admin interface
- [ ] Test all forms in public interface
- [ ] Test framework switching (Bootstrap ↔ Tailwind ↔ UIKit ↔ HTML5)
- [ ] Verify Falcon theme still works perfectly (NO REGRESSIONS)
- [ ] Remove old FormWriterHTML5* classes
- [ ] Update documentation

## Notes

- This refactoring should be done incrementally, testing at each phase
- Each phase should be completed and tested before moving to the next
- Document all changes thoroughly for theme developers
- The old FormWriterHTML5* classes will be completely replaced, not wrapped
- FormWriterBootstrap (Falcon's base) is the source of truth for functionality
- All methods from Bootstrap implementation must be preserved in FormWriterBase
- **FormWriterBootstrap and Falcon implementations are not to be modified without authorization**
- Tailwind and UIKit should be brought to feature parity with Bootstrap where possible

## Special Considerations

### File Upload Functionality
The advanced file upload methods (bulk upload, drag-and-drop, progress bars) contain significant JavaScript that is mostly framework-agnostic. Consider:
- The JavaScript logic for file handling, chunking, and progress could potentially move to FormWriterBase
- Only the HTML markup and CSS classes are framework-specific
- This would reduce duplication across implementations
- However, this requires careful analysis to ensure the JavaScript doesn't depend on specific DOM structures