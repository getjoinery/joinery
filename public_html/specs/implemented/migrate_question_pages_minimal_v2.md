# Minimal FormWriter V2 Migration: Question Pages

**Date:** 2025-10-31
**Status:** Ready for Implementation
**Priority:** Phase 2
**Effort:** ~8-10 hours

---

## Objective

Perform the absolute minimum changes necessary to migrate the question pages from FormWriter V1 to FormWriter V2, preserving all existing functionality and architecture while avoiding any unnecessary refactoring.

---

## Scope

### Pages to Migrate
1. `/adm/admin_question.php` - Question display/test page
2. `/adm/admin_question_edit.php` - Question create/edit page

### Out of Scope
- Refactoring the Question class methods
- Changing the database schema
- Modifying validation serialization format
- Rewriting jQuery visibility logic
- Changing the way questions are rendered in surveys

---

## Migration Approach

### Key Principle
**Keep all existing business logic intact.** Only change FormWriter method calls from V1 to V2 syntax. The Question class methods (`output_question()` and `output_js_validation()`) will continue to work by passing them a V2 FormWriter instance instead of V1.

---

## Implementation Details

### 1. admin_question.php (2 hours)

#### Current V1 Usage
```php
$formwriter = $page->getFormWriter('form1');  // V1
echo $formwriter->begin_form('form1', 'POST', '/admin/admin_question');
$validation_rules = array();
$validation_rules = $question->output_js_validation($validation_rules);
echo $formwriter->set_validate($validation_rules);
echo $question->output_question($formwriter);
echo $formwriter->start_buttons();
echo $formwriter->new_form_button('Test');
echo $formwriter->end_buttons();
```

#### Minimal V2 Migration
```php
// Get V2 instance
$formwriter = $page->getFormWriter('form1', 'v2', [
    'action' => '/admin/admin_question',
    'method' => 'POST'
]);

// Start form (V2 doesn't echo)
$formwriter->begin_form();

// The Question class output_question() method has been updated to V2 syntax
// No echo needed - V2 methods output directly
$question->output_question($formwriter);

// Submit button (V2 syntax)
$formwriter->submitbutton('test_button', 'Test');
$formwriter->end_form();
```

**Note:** The `output_question()` method will now be directly updated to use V2 syntax - see changes detailed below.

---

### 2. admin_question_edit.php (6-8 hours)

This page is more complex due to:
- Multiple forms (main question form + option add form)
- jQuery visibility logic
- Serialized validation data handling

#### Part A: Main Question Form

**Current V1:**
```php
$formwriter = $page->getFormWriter('form1');
echo $formwriter->set_validate($validation_rules);
echo $formwriter->begin_form('form', 'POST', '/admin/admin_question_edit');
echo $formwriter->textinput('Question', 'qst_question', NULL, 100, $question->get('qst_question'), '', 255, '');
echo $formwriter->dropinput("Type", "qst_type", "ctrlHolder", $optionvals, $question->get('qst_type'), '', FALSE);
// ... more fields
```

**Minimal V2 Migration:**
```php
$formwriter = $page->getFormWriter('form1', 'v2', [
    'action' => '/admin/admin_question_edit',
    'method' => 'POST',
    'model' => $question
]);

$formwriter->begin_form();

// Convert each field to V2 syntax
$formwriter->textinput('qst_question', 'Question', [
    'value' => $question->get('qst_question'),
    'maxlength' => 255,
    'validation' => ['required' => true]
]);

$formwriter->dropinput('qst_type', 'Type', [
    'options' => $optionvals,
    'value' => $question->get('qst_type')
]);

// Validation checkboxes - unserialize and convert
$validation_data = unserialize($question->get('qst_validate')) ?: [];
$checked_vals = [];
if (!empty($validation_data['required'])) $checked_vals[] = 'required';
if (!empty($validation_data['integer'])) $checked_vals[] = 'integer';
if (!empty($validation_data['decimal'])) $checked_vals[] = 'decimal';

$formwriter->checkboxlist('validation_options[]', 'Validation options', [
    'options' => [
        'Required' => 'required',
        'Integer (Example: 5)' => 'integer',
        'Decimal (Example: 5.5)' => 'decimal'
    ],
    'checked' => $checked_vals
]);

// Validation parameter fields (keep existing HTML containers for jQuery)
echo '<div id="max_length_container" style="display:none;">';
$formwriter->textinput('max_length', 'Validation Maximum Length', [
    'value' => $validation_data['max_length'] ?? '',
    'maxlength' => 3
]);
echo '</div>';

echo '<div id="min_length_container" style="display:none;">';
$formwriter->textinput('min_length', 'Validation Minimum Length', [
    'value' => $validation_data['min_length'] ?? '',
    'maxlength' => 3
]);
echo '</div>';

// ... similar for max_value, min_value

$formwriter->submitbutton('submit', 'Submit');
$formwriter->end_form();
```

#### Part B: Replace jQuery with Plain JavaScript

**Minimal change** - Convert jQuery to vanilla JavaScript, keeping the exact same logic and behavior:

**Current jQuery (lines 26-97 and 154-173):**
```javascript
function set_validation_choices(){
    var value = $("#qst_type").val();
    if(value == 1){  //SHORT TEXT
        $("#validation_optionsinteger").prop('disabled', false);
        $("#max_length_container").show();
        // ... more jQuery show/hide
    }
}

$("#qst_type").change(function() {
    set_validation_choices();
});
```

**Plain JavaScript Replacement:**
```javascript
function set_validation_choices(){
    var value = document.getElementById("qst_type").value;
    if(value == 1){  //SHORT TEXT
        document.getElementById("validation_optionsinteger").disabled = false;
        document.getElementById("max_length_container").style.display = "block";
        document.getElementById("min_length_container").style.display = "block";
        document.getElementById("max_value_container").style.display = "block";
        document.getElementById("min_value_container").style.display = "block";
        document.getElementById("validation_optionsinteger_container").style.display = "block";
        document.getElementById("validation_optionsdecimal_container").style.display = "block";
        document.getElementById("answersbox").style.display = "none";
    }
    else if(value == 2){  //LONG TEXT
        document.getElementById("validation_optionsinteger").disabled = true;
        document.getElementById("validation_optionsdecimal").disabled = true;
        document.getElementById("validation_optionsinteger").checked = false;
        document.getElementById("validation_optionsdecimal").checked = false;
        document.getElementById("max_length_container").style.display = "block";
        document.getElementById("min_length_container").style.display = "block";
        document.getElementById("max_value_container").style.display = "none";
        document.getElementById("min_value_container").style.display = "none";
        document.getElementById("validation_optionsinteger_container").style.display = "none";
        document.getElementById("validation_optionsdecimal_container").style.display = "none";
        document.getElementById("answersbox").style.display = "none";
    }
    else {  //DROPDOWN, RADIO, CHECKBOX, CHECKBOX_LIST
        document.getElementById("validation_optionsinteger").disabled = true;
        document.getElementById("validation_optionsdecimal").disabled = true;
        document.getElementById("validation_optionsinteger").checked = false;
        document.getElementById("validation_optionsdecimal").checked = false;
        document.getElementById("max_length_container").style.display = "none";
        document.getElementById("min_length_container").style.display = "none";
        document.getElementById("max_value_container").style.display = "none";
        document.getElementById("min_value_container").style.display = "none";
        document.getElementById("validation_optionsinteger_container").style.display = "none";
        document.getElementById("validation_optionsdecimal_container").style.display = "none";
        document.getElementById("answersbox").style.display = "block";
    }

    // Additional checkbox-specific logic
    if(value == 5){  //CHECKBOX - limit to one option
        var existingOptions = document.querySelectorAll('.question-option-item');
        if(existingOptions.length >= 1){
            document.getElementById("add-option-form").style.display = "none";
        }
    } else {
        document.getElementById("add-option-form").style.display = "block";
    }
}

// Replace jQuery document ready and change handler
document.addEventListener('DOMContentLoaded', function() {
    set_validation_choices();
    document.getElementById("qst_type").addEventListener('change', set_validation_choices);
});
```

**Key Translation Rules Applied:**
- `$("#id")` → `document.getElementById("id")`
- `.val()` → `.value`
- `.prop('disabled', true/false)` → `.disabled = true/false`
- `.prop('checked', true/false)` → `.checked = true/false`
- `.show()` → `.style.display = "block"`
- `.hide()` → `.style.display = "none"`
- `$(document).ready()` → `document.addEventListener('DOMContentLoaded', ...)`
- `.change()` → `.addEventListener('change', ...)`

#### Part C: Question Option Form

**Current V1:**
```php
$formwriter = $page->getFormWriter('form2');
echo $formwriter->begin_form('form2', 'POST', '/admin/admin_question_edit');
echo $formwriter->hiddeninput('qst_question_id', $question->key);
echo $formwriter->hiddeninput('action', 'add_question_option');
echo $formwriter->textinput('Label', 'qop_question_option_label', ...);
```

**Minimal V2 Migration:**
```php
$formwriter2 = $page->getFormWriter('form2', 'v2', [
    'action' => '/admin/admin_question_edit',
    'method' => 'POST'
]);

$formwriter2->begin_form();
$formwriter2->hiddeninput('qst_question_id', $question->key);
$formwriter2->hiddeninput('action', 'add_question_option');

$formwriter2->textinput('qop_question_option_label', 'Label', [
    'maxlength' => 255,
    'validation' => ['required' => true]
]);

$formwriter2->textinput('qop_question_option_value', 'Value', [
    'maxlength' => 255,
    'validation' => ['required' => true]
]);

$formwriter2->submitbutton('add_option', 'Add Option');
$formwriter2->end_form();
```

---

## Logic Files

### admin_question_logic.php
- **No changes needed** - Logic file remains the same

### admin_question_edit_logic.php
- **Minimal changes** - May need to adjust how validation data is packed/unpacked
- The serialization format stays the same
- Just ensure the logic handles the V2 POST data structure correctly

### Known Issue in Question Class
**Note:** The `output_js_validation()` method (lines 58-90 in questions_class.php) is incorrectly defined **outside** the Question class body. This is a pre-existing bug that should be fixed separately:
```php
// Current (WRONG - outside class):
}  // End of Question class

function output_js_validation($validation_rules){
    $validation_options = unserialize($this->get('qst_validate'));
    // ...
}

// Should be (inside class):
class Question extends SystemBase {
    // ... other methods ...

    public function output_js_validation($validation_rules){
        $validation_options = unserialize($this->get('qst_validate'));
        // ...
    }
}

---

## Testing Requirements

### admin_question.php Testing
1. Load a question of each type
2. Verify the question renders correctly
3. Submit test answer and verify POST handling
4. Check that validation works

### admin_question_edit.php Testing
1. Create new question of each type
2. Edit existing question
3. Add/remove question options
4. Verify jQuery visibility logic still works
5. Test all validation options
6. Verify serialized data is saved correctly

---

## Risk Assessment

### Low Risk
- Basic FormWriter methods are similar between V1 and V2
- jQuery visibility logic is independent of FormWriter version
- Logic files need minimal changes

### Medium Risk
- The `output_question()` method in Question class **WILL require adjustments** - see detailed analysis below
- POST data structure might be slightly different requiring logic file adjustments

### Required Adjustments for output_question() Method

**Investigation Results:** The `output_question()` method needs syntax updates for V2 compatibility:

#### Simple Syntax Changes Required

The changes are straightforward - just convert from positional parameters to the V2 format. Since V2 methods echo directly (don't return strings), we need to remove the `return` statements and let V2 handle the output.

**Location:** `/data/questions_class.php` lines 133-194

#### Specific Changes in output_question() method:

```php
// Line 142 - TYPE_SHORT_TEXT
// OLD V1:
return $formwriter->textinput($question_text, $field_name, 'sm:col-span-6', 100, $value, '', $field_max_length, '');

// NEW V2:
$formwriter->textinput($field_name, $question_text, [
    'class' => 'sm:col-span-6',
    'size' => 100,
    'value' => $value,
    'maxlength' => $field_max_length
]);

// Line 145 - TYPE_LONG_TEXT
// OLD V1:
return $formwriter->textbox($question_text, $field_name, 'sm:col-span-6', 5, 80, $value, '', 'no');

// NEW V2:
$formwriter->textbox($field_name, $question_text, [
    'class' => 'sm:col-span-6',
    'rows' => 5,
    'cols' => 80,
    'value' => $value,
    'htmlmode' => 'no'
]);

// Line 156 - TYPE_DROPDOWN
// OLD V1:
echo $formwriter->dropinput($question_text, $field_name, 'sm:col-span-6', $optionvals, $value, '', TRUE);

// NEW V2:
$formwriter->dropinput($field_name, $question_text, [
    'class' => 'sm:col-span-6',
    'options' => $optionvals,
    'value' => $value,
    'showdefault' => true
]);

// Line 168 - TYPE_RADIO
// OLD V1:
echo $formwriter->radioinput($question_text, $field_name, "radioinput sm:col-span-6", $optionvals, $value, NULL, "", NULL);

// NEW V2:
$formwriter->radioinput($field_name, $question_text, [
    'class' => 'radioinput sm:col-span-6',
    'options' => $optionvals,
    'value' => $value
]);

// Line 181 - TYPE_CHECKBOX
// OLD V1:
echo $formwriter->checkboxinput($question_text, $field_name, '', NULL, $truevalue, $value, '');

// NEW V2:
$formwriter->checkboxinput($field_name, $question_text, [
    'checked' => ($value == $truevalue),
    'value' => $truevalue
]);

// Line 192 - TYPE_CHECKBOX_LIST
// OLD V1:
echo $formwriter->checkboxlist($question_text, $field_name, 'sm:col-span-6', $optionvals, $value, '', TRUE);

// NEW V2:
$formwriter->checkboxlist($field_name, $question_text, [
    'class' => 'sm:col-span-6',
    'options' => $optionvals,
    'checked' => $value
]);
```

**Note:** Remove all `return` and `echo` statements - V2 handles output internally


---

## Deployment Steps

1. **Backup current files**
   ```bash
   cp /data/questions_class.php /data/questions_class.php.v1.backup
   cp /adm/admin_question.php /adm/admin_question.php.v1.backup
   cp /adm/admin_question_edit.php /adm/admin_question_edit.php.v1.backup
   ```

2. **Update Question class first** (/data/questions_class.php)
   - Update `output_question()` method to V2 syntax (lines 133-194)
   - Convert all FormWriter method calls as shown above
   - Remove `return` and `echo` statements
   - Test with a simple script to verify changes

3. **Migrate admin_question.php** (simpler page)
   - Update FormWriter initialization to V2
   - Update to call `output_question()` without echo
   - Convert submit button to V2 syntax
   - Test thoroughly

4. **Migrate admin_question_edit.php**
   - Update both forms to V2
   - Replace jQuery with plain JavaScript
   - Test all question types and validation options

5. **Update logic files if needed**
   - Adjust POST data handling if necessary
   - Maintain serialization format

6. **Full regression test**
   - Create questions of all types
   - Edit questions
   - Test in survey context
   - Verify all question types render correctly

---

## Success Criteria

✅ Both pages load without errors
✅ All 6 question types work correctly
✅ Validation options save and load properly
✅ Plain JavaScript visibility logic functions (jQuery removed)
✅ Question options can be added/removed
✅ Questions still work in survey display (updated to V2 syntax)
✅ Question class output_question() method updated to V2 syntax
✅ No database schema changes
✅ No data migration needed

---

## Notes

- This is truly the **minimum viable migration** - only syntax changes
- Direct method conversion in Question class - no adapters or wrappers
- Technical debt remains but functionality is preserved
- Can be completed in 8-10 hours vs 40-50 for full refactoring
- jQuery replaced with plain JavaScript for maintainability
- Sets foundation for future improvements if needed
- Survey pages already completed, so this completes the survey module migration