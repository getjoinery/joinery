# FormWriter v2 Architecture Improvements Specification

## Overview

This specification outlines architectural improvements for FormWriter v2 to address consistency issues, code duplication, and maintainability concerns while preserving the solid foundation of the current Template Method pattern implementation.

## Current Architecture Analysis

### Existing Pattern (To Be Preserved)

FormWriter v2 uses a well-designed two-tier pattern:

```
PUBLIC WRAPPER METHOD          PROTECTED OUTPUT METHOD
textinput() ─────────────────> outputTextInput()
  • registerField()                • Build HTML string
  • Set up validation              • Handle theme styling
  • Deferred mode check            • Echo or store HTML
```

This pattern provides clean separation of concerns and should be maintained.

### Issues to Address

1. **Inconsistent output strategies** - Some methods build strings, others echo directly
2. **Missing abstract method declarations** - Several field types lack base class definitions
3. **Code duplication** - JavaScript and helper logic duplicated across themes
4. **Complex time handling** - Time parsing/conversion logic scattered and duplicated

## Specification

### 1. Standardize Output Method Pattern

#### Requirement
All `output*` methods in theme implementations MUST follow a consistent pattern of building HTML strings before outputting.

#### Current Problem
```php
// INCONSISTENT - Some methods build strings:
protected function outputTextInput($name, $label, $options) {
    $html = '<div class="form-group">';
    // ... build string ...
    if ($this->use_deferred_output) {
        $this->deferred_output[$name] = $html;
    } else {
        echo $html;
    }
}

// Others echo directly:
protected function outputCheckboxInput($name, $label, $options) {
    echo '<div class="form-group">';  // Direct echo - can't defer!
    echo '<input type="checkbox"...>';
    // ...
}
```

#### Required Implementation
```php
// ALL output methods MUST use this pattern:
protected function outputCheckboxInput($name, $label, $options) {
    $html = '';

    $html .= '<div class="form-group">';
    $html .= '<div class="form-check">';
    $html .= '<input type="checkbox"...>';
    $html .= '</div>';
    $html .= '</div>';

    // Standard output handling
    $this->handleOutput($name, $html);
}

// Add to FormWriterV2Base:
protected function handleOutput($field_name, $html) {
    if ($this->use_deferred_output) {
        $this->deferred_output[$field_name] = $html;
    } else {
        echo $html;
    }
}
```

#### Affected Methods

**FormWriterV2Bootstrap.php (6 methods using direct echo):**
- `outputCheckboxInput()` - Direct echo
- `outputRadioInput()` - Direct echo
- `outputDateInput()` - Direct echo
- `outputTimeInput()` - Direct echo
- `outputDateTimeInput()` - Direct echo
- `outputFileInput()` - Direct echo
- `outputCheckboxList()` - Direct echo (7 methods total)

**FormWriterV2Tailwind.php (2 methods using direct echo):**
- `outputTimeInput()` - Direct echo
- `outputDateTimeInput()` - Direct echo

Note: All other methods in Tailwind already use string building pattern. Bootstrap has good coverage but needs work on the listed methods.

### 2. Complete Abstract Method Definitions

#### Requirement
All public field methods MUST have corresponding abstract protected methods defined in `FormWriterV2Base`.

#### Current Problem

The base class declares abstract methods for most field types, but several public methods exist only in concrete classes without abstract declarations or public wrappers in the base class:

**Missing from Base Class:**
1. **`textbox()` (Rich Text Editor)** - Only in FormWriterV2Bootstrap
   - No abstract `outputTextbox()` method
   - No public wrapper in base

2. **`imageinput()` (Image Selection)**  - Only in FormWriterV2Bootstrap
   - No abstract `outputImageInput()` method
   - No public wrapper in base

3. **`checkboxlist()` appears in both Bootstrap and Tailwind but:**
   - No abstract `outputCheckboxList()` method exists in base (but the abstract IS declared)
   - Public wrapper should be consistent in base

4. **`outputTextarea()` implementation issue:**
   - Tailwind has `outputTextarea()` and a textarea method
   - Bootstrap doesn't have textarea support
   - No abstract method in base class

**Note:** The abstract method `outputCheckboxList()` DOES exist in base class, so that's correct.

#### Required Implementation

Add to `FormWriterV2Base.php`:

```php
// Add missing abstract method declarations:
abstract protected function outputTextbox($name, $label, $options);
abstract protected function outputImageInput($name, $label, $options);
abstract protected function outputTextarea($name, $label, $options);

// Add corresponding public wrapper methods:
public function textbox($name, $label = '', $options = []) {
    $this->registerField($name, 'textbox', $label, $options);
    $this->outputTextbox($name, $label, $options);
}

public function imageinput($name, $label = '', $options = []) {
    $this->registerField($name, 'image', $label, $options);
    $this->outputImageInput($name, $label, $options);
}

public function textarea($name, $label = '', $options = []) {
    $this->registerField($name, 'textarea', $label, $options);
    $this->outputTextarea($name, $label, $options);
}

// Add public wrapper for checkboxlist (for consistency in base class):
public function checkboxlist($name, $label = '', $options = []) {
    $this->registerField($name, 'checkboxlist', $label, $options);
    $this->outputCheckboxList($name, $label, $options);
}
```

#### Implementation Notes

**FormWriterV2Bootstrap.php changes:**
1. Implement `protected function outputTextbox()` - move existing logic from public textbox()
2. Implement `protected function outputImageInput()` - move existing logic from public imageinput()
3. Remove public `textbox()` method (now inherited from base)
4. Remove public `imageinput()` method (now inherited from base)
5. Implement `protected function outputTextarea()` - add textarea support to Bootstrap (copy from Tailwind and adapt to Bootstrap styling)
6. Remove or adapt public `checkboxlist()` if it exists

**FormWriterV2Tailwind.php changes:**
1. Implement `protected function outputTextbox()` - add support for textbox field
2. Implement `protected function outputImageInput()` - add support for image selection
3. Remove or adapt public `checkboxlist()` if it exists
4. Ensure `outputTextarea()` exists and is protected (not public)

### 3. Extract Shared JavaScript to Base Class

#### Requirement
Common JavaScript functionality MUST be extracted to base class methods to eliminate duplication.

#### Current Problem
Identical JavaScript code exists in both FormWriterV2Bootstrap and FormWriterV2Tailwind:

1. **Time Input Update JavaScript** - Exact same code in both implementations:
   - Located at lines 704-751 in FormWriterV2Bootstrap.php
   - Located at lines 980-1027 in FormWriterV2Tailwind.php
   - Includes `updateTimeInput()` function and DOMContentLoaded event listener

2. **AJAX Search Select Class** - Identical implementation:
   - Located at lines 245-346 in FormWriterV2Bootstrap.php
   - Located at lines 289-390 in FormWriterV2Tailwind.php
   - Complete AjaxSearchSelect class with duplicate logic

3. **Visibility Rules JavaScript** - Generated by `generateVisibilityScript()` method in base class (shared)

#### Required Implementation

Add to `FormWriterV2Base.php`:

```php
/**
 * Output shared JavaScript for time input fields
 * Exact code from both Bootstrap and Tailwind implementations
 */
protected function outputTimeInputJavaScript() {
    static $time_input_js_loaded = false;
    if (!$time_input_js_loaded) {
        echo '<script type="text/javascript">
function updateTimeInput(hourId, minuteId, ampmId, hiddenId) {
    var hour = document.getElementById(hourId).value;
    var minute = document.getElementById(minuteId).value;
    var ampm = document.getElementById(ampmId).value;

    if (hour && minute) {
        var h = parseInt(hour);
        if (ampm === "PM" && h !== 12) h += 12;
        if (ampm === "AM" && h === 12) h = 0;

        var timeValue = String(h).padStart(2, "0") + ":" + String(minute).padStart(2, "0");
        document.getElementById(hiddenId).value = timeValue;
    }
}

document.addEventListener("DOMContentLoaded", function() {
    var timeInputs = document.querySelectorAll("[data-time-hour]");
    timeInputs.forEach(function(el) {
        var hourId = el.getAttribute("data-time-hour");
        var minuteId = el.getAttribute("data-time-minute");
        var ampmId = el.getAttribute("data-time-ampm");
        var hiddenId = el.getAttribute("data-time-hidden");

        document.getElementById(hourId).addEventListener("change", function() {
            updateTimeInput(hourId, minuteId, ampmId, hiddenId);
        });
        document.getElementById(minuteId).addEventListener("change", function() {
            updateTimeInput(hourId, minuteId, ampmId, hiddenId);
        });
        document.getElementById(ampmId).addEventListener("change", function() {
            updateTimeInput(hourId, minuteId, ampmId, hiddenId);
        });
    });
});
</script>';
        $time_input_js_loaded = true;
    }
}

/**
 * Output shared JavaScript for AJAX search select functionality
 * Exact code from both Bootstrap and Tailwind implementations
 * Contains AjaxSearchSelect class definition
 */
protected function outputAjaxSearchSelectJavaScript() {
    static $ajax_search_select_js_loaded = false;
    if (!$ajax_search_select_js_loaded) {
        echo '<script type="text/javascript">
class AjaxSearchSelect {
    constructor(selectId, searchEndpoint, minChars = 2) {
        this.selectId = selectId;
        this.searchEndpoint = searchEndpoint;
        this.minChars = minChars;
        this.selectElement = document.getElementById(selectId);
        this.init();
    }

    init() {
        if (!this.selectElement) return;
        // ... [Full AjaxSearchSelect class implementation] ...
    }
}
</script>';
        $ajax_search_select_js_loaded = true;
    }
}

/**
 * Visibility rules JavaScript is already generated by generateVisibilityScript() method
 * No extraction needed - already shared via base class
 */
```

#### Implementation Notes for Phase 3

**In FormWriterV2Bootstrap.php:**
1. Remove time input JavaScript block (lines 704-751)
2. Remove AJAX search select class definition (lines 245-346)
3. In `outputTimeInput()` method, add call: `$this->outputTimeInputJavaScript();`
4. In methods that use AJAX search, add call: `$this->outputAjaxSearchSelectJavaScript();`

**In FormWriterV2Tailwind.php:**
1. Remove time input JavaScript block (lines 980-1027)
2. Remove AJAX search select class definition (lines 289-390)
3. In `outputTimeInput()` method, add call: `$this->outputTimeInputJavaScript();`
4. In methods that use AJAX search, add call: `$this->outputAjaxSearchSelectJavaScript();`

### 4. Create Time Handling Helper Methods

#### Requirement
Centralize time parsing and conversion logic into reusable helper methods.

#### Current Problem
Time handling logic is duplicated and scattered across multiple methods with complex format conversions:

1. **Identical time parsing logic in both concrete classes:**
   - FormWriterV2Bootstrap.php `outputTimeInput()` - Complex parsing logic for 24-hour and 12-hour formats
   - FormWriterV2Tailwind.php `outputTimeInput()` - Exact same parsing logic
   - Handles conversion from database 24-hour format to 12-hour with AM/PM selector

2. **Time conversion scattered:**
   - Static method `process_datetimeinput()` handles POST submission conversion
   - `LibraryFunctions::toDBTime()` utility function used for conversion
   - No centralized helpers in base class

3. **Timezone handling:**
   - `convertDateTimeFieldsToLocalTime()` method in base handles timezone conversion
   - Separate from time parsing logic

#### Required Implementation

Add to `FormWriterV2Base.php`:

```php
/**
 * Parse time value from various formats into components
 * Handles both 24-hour database format and 12-hour display format with AM/PM
 * This consolidates the duplicated parsing logic from both concrete implementations
 * @param string $value Time in any supported format (HH:MM, HH:MM:SS, or H:MM AM/PM)
 * @return array ['hour' => string, 'minute' => string, 'ampm' => string]
 */
protected function parseTimeValue($value) {
    $hour = '';
    $minute = '';
    $ampm = 'AM';

    if (!$value) {
        return ['hour' => $hour, 'minute' => $minute, 'ampm' => $ampm];
    }

    // Check if value contains AM/PM (e.g., "3:15 PM" from datetimeinput)
    if (stripos($value, 'am') !== false || stripos($value, 'pm') !== false) {
        // Extract AM/PM first
        if (stripos($value, 'pm') !== false) {
            $ampm = 'PM';
            $value = str_ireplace('pm', '', $value);
        } else {
            $ampm = 'AM';
            $value = str_ireplace('am', '', $value);
        }
        $value = trim($value);
    }

    // Parse hour and minute from remaining value
    if (strpos($value, ':') !== false) {
        list($h, $m) = explode(':', $value);
        $h = intval(trim($h));
        $m = intval(trim($m));

        // If we extracted AM/PM, the hour is already in 12-hour format
        if ($ampm === 'PM' && $h !== 12) {
            // Keep as is, conversion happens on submit
        } elseif ($ampm === 'AM' && $h === 12) {
            // Keep as 12
        } elseif ($h >= 12 && (stripos($value, 'am') === false && stripos($value, 'pm') === false)) {
            // If no AM/PM was in original value, convert from 24-hour to 12-hour
            if ($h >= 12) {
                $ampm = 'PM';
                if ($h > 12) $h -= 12;
            } else {
                $ampm = 'AM';
                if ($h == 0) $h = 12;
            }
        }

        $hour = str_pad($h, 2, '0', STR_PAD_LEFT);
        $minute = str_pad($m, 2, '0', STR_PAD_LEFT);
    }

    return ['hour' => $hour, 'minute' => $minute, 'ampm' => $ampm];
}

/**
 * Convert 12-hour time components to 24-hour database format
 * @param string $hour Hour (1-12)
 * @param string $minute Minute (00-59)
 * @param string $ampm AM or PM
 * @return string Time in HH:MM format suitable for database storage
 */
protected function convertTimeToDatabase($hour, $minute, $ampm) {
    if (empty($hour) || empty($minute)) {
        return '';
    }

    $hour24 = (int)$hour;

    if ($ampm === 'PM' && $hour24 !== 12) {
        $hour24 += 12;
    } elseif ($ampm === 'AM' && $hour24 === 12) {
        $hour24 = 0;
    }

    return str_pad($hour24, 2, '0', STR_PAD_LEFT) . ':' .
           str_pad($minute, 2, '0', STR_PAD_LEFT);
}

/**
 * Format time for display
 * @param string $value Time in database format (HH:MM)
 * @param string $format Output format ('12hour' or '24hour')
 * @return string Formatted time
 */
protected function formatTimeForDisplay($value, $format = '12hour') {
    if (empty($value)) {
        return '';
    }

    if ($format === '24hour') {
        return $value;
    }

    $components = $this->parseTimeValue($value);
    return $components['hour'] . ':' . $components['minute'] . ' ' . $components['ampm'];
}
```

#### Implementation Notes for Phase 4

**Helper Method Usage:**
The `parseTimeValue()` method should be called from `outputTimeInput()` in both concrete implementations to replace the duplicated inline parsing logic:

```php
// In FormWriterV2Bootstrap::outputTimeInput() and FormWriterV2Tailwind::outputTimeInput()
protected function outputTimeInput($name, $label, $options) {
    // Get current value from field
    $value = isset($this->values[$name]) ? $this->values[$name] : (isset($options['value']) ? $options['value'] : '');

    // Parse using centralized helper
    $time_components = $this->parseTimeValue($value);

    // Build HTML string using parsed components
    $html = '';
    // ... build HTML with $time_components['hour'], $time_components['minute'], $time_components['ampm'] ...

    // Handle output consistently
    $this->handleOutput($name, $html);
}
```

**Related Methods to Update:**
- Remove the duplicated inline parsing logic from `outputTimeInput()` in both Bootstrap and Tailwind
- The static `process_datetimeinput()` method should continue to work as-is (no changes needed)
- The existing `LibraryFunctions::toDBTime()` can be kept as a utility for external callers

## Implementation Plan

### Phase 1: Foundation (Priority: High)
1. Add `handleOutput()` method to base class
2. Add missing abstract method declarations
3. Add public wrapper methods for missing field types

### Phase 2: Standardization (Priority: High)
1. Update all output methods to use string building pattern
2. Convert all output methods to use `handleOutput()`
3. Remove duplicate public methods from concrete classes

### Phase 3: Code Extraction (Priority: Medium)
1. Extract time input JavaScript to base class
2. Extract date picker JavaScript to base class
3. Extract visibility rule JavaScript to base class
4. Update concrete implementations to use shared methods

### Phase 4: Helper Methods (Priority: Medium)
1. Implement time parsing helper methods
2. Implement time conversion helper methods
3. Update time-related methods to use helpers

## Migration Notes

### Backward Compatibility
- All existing public method signatures remain unchanged
- Existing forms will continue to work without modification
- New functionality is additive, not breaking

### Deprecations
- None - all changes are internal refactoring

### Update Instructions
1. Apply base class changes first
2. Update Bootstrap implementation
3. Update Tailwind implementation
4. Run full test suite
5. Deploy to staging for testing

## Success Criteria

1. **Consistency**: All output methods follow the same pattern
2. **No Duplication**: Shared JavaScript exists in only one place
3. **Maintainability**: Time handling logic is centralized
4. **Compatibility**: All existing forms continue to work
5. **Performance**: No measurable performance degradation

## Version

This specification targets FormWriter v2.1.0 improvements.

## Critical Implementation Note: `handleOutput()` Method

**IMPORTANT:** The specification references a `handleOutput()` method that **DOES NOT currently exist** in `FormWriterV2Base`. This method must be added as part of Phase 1 and is referenced throughout the specification.

The method should be added to `FormWriterV2Base.php`:

```php
/**
 * Handle output of HTML for a form field
 * Supports both immediate output and deferred output mode
 * @param string $field_name The name of the field
 * @param string $html The HTML to output
 */
protected function handleOutput($field_name, $html) {
    if ($this->use_deferred_output) {
        $this->deferred_output[$field_name] = $html;
    } else {
        echo $html;
    }
}
```

This method will:
1. Enable consistent output handling across all field types
2. Support deferred output mode for all methods (once all methods are converted to use it)
3. Provide a single point of control for output strategy changes

## Notes

- Preserve the existing Template Method pattern (wrapper functions)
- Maintain backward compatibility for all public APIs
- Focus on internal consistency and code reuse
- The `handleOutput()` method is a prerequisite for all other phases
- All existing tests should pass after implementation