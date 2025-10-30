# Investigation: Survey and Question Pages Migration Complexity

**Date:** 2025-10-29
**Status:** Comprehensive Analysis Complete

---

## Executive Summary

The survey and question pages have **significant custom form generation code** that will require substantial refactoring to migrate to FormWriter V2. This is **NOT a simple FormWriter conversion** - it's a complete architectural redesign of how forms are generated dynamically.

**Complexity Level:** 🔴 HIGH - Estimated 40-50 hours of development work

---


## 2. Question Pages Analysis

### 2.1 admin_question.php (Question Display)

**Purpose:** Display question and allow testing

**Current Architecture:**
- **Type:** Display page with custom form rendering
- **Lines 11-15:** Uses logic file (already separated)
- **Lines 55-67:** Custom form rendering via `Question::output_question()`

**Key Code:**
```php
$formwriter = $page->getFormWriter('form1');  // V1
echo $formwriter->begin_form('form1', 'POST', '/admin/admin_question');

$validation_rules = array();
$validation_rules = $question->output_js_validation($validation_rules);
echo $formwriter->set_validate($validation_rules);

echo $question->output_question($formwriter);  // CUSTOM METHOD
echo $formwriter->start_buttons();
echo $formwriter->new_form_button('Test');
echo $formwriter->end_buttons();
```

**Custom Form Generation:**
- Calls `$question->output_js_validation()` (lines 133-194 in questions_class.php)
- Calls `$question->output_question($formwriter)` (lines 133-194 in questions_class.php)
- **Problem:** The Question class generates form HTML based on question type

**What `output_question()` does:**
```php
function output_question($formwriter, $value=NULL, $append_text=NULL){
    $field_name = 'question_'.$this->key;

    // Dynamically generates form fields based on question type:
    if ($this->get('qst_type') == Question::TYPE_SHORT_TEXT) {
        // Generates textinput
    }
    else if ($this->get('qst_type') == Question::TYPE_LONG_TEXT) {
        // Generates textbox
    }
    else if ($this->get('qst_type') == Question::TYPE_DROPDOWN) {
        // Loads question options and generates dropinput
    }
    else if ($this->get('qst_type') == Question::TYPE_RADIO) {
        // Generates radioinput
    }
    else if ($this->get('qst_type') == Question::TYPE_CHECKBOX) {
        // Generates checkboxinput
    }
    else if ($this->get('qst_type') == Question::TYPE_CHECKBOX_LIST) {
        // Generates checkboxlist
    }
}
```

**Issues:**
1. Uses FormWriter V1
2. Question class tightly coupled to FormWriter
3. Echo statements inside method calls
4. Question type constants used for logic

**Migration Complexity:** 🔴 VERY HIGH - Requires refactoring Question class itself

---

### 2.2 admin_question_edit.php (Question Create/Edit)

**Purpose:** Create/edit question and manage question options

**Current Architecture:**
- **Type:** Complex create/edit with jQuery visibility logic
- **Uses:** Logic file (`admin_question_edit_logic.php`)
- **Multiple Forms:** Main question form + inline question option form
- **jQuery Integration:** Heavy use of visibility rules

**jQuery Visibility Logic (lines 26-97):**
```javascript
function set_validation_choices(){
    var value = $("#qst_type").val();
    if(value == 1){  //SHORT TEXT
        $("#validation_optionsinteger").prop('disabled', false);
        $("#max_length_container").show();
        // ... shows/hides validation fields
    }
    // ... complex logic for each question type
}

$("#qst_type").change(function() {
    set_validation_choices();
});
```

**Also jQuery for answers section (lines 154-173):**
```javascript
if ($('#qst_type option:selected').val() == <?php echo Question::TYPE_SHORT_TEXT; ?>) {
    $('#answersbox').hide();
}
// ... shows/hides answer options section based on question type
```

**FormWriter V1 Usage:**
```php
// Main question form (lines 100-148)
$formwriter = $page->getFormWriter('form1');
echo $formwriter->set_validate($validation_rules);
echo $formwriter->begin_form('form', 'POST', '/admin/admin_question_edit');
echo $formwriter->textinput('Question', 'qst_question', NULL, 100, $question->get('qst_question'), '', 255, '');
echo $formwriter->dropinput("Type", "qst_type", "ctrlHolder", $optionvals, $question->get('qst_type'), '', FALSE);
echo $formwriter->checkboxList("Validation options", 'validation_options', "ctrlHolder", ...);

// Inline question option form (lines 198-214)
$formwriter = $page->getFormWriter('form2');
echo $formwriter->begin_form('form2', 'POST', '/admin/admin_question_edit');
echo $formwriter->hiddeninput('qst_question_id', $question->key);
echo $formwriter->hiddeninput('action', 'add_question_option');
echo $formwriter->textinput('Label', 'qop_question_option_label', ...);
```

**Special Data Handling (lines 121-143):**
- Unserializes validation data: `$checkedvals = unserialize($question->get('qst_validate'));`
- Extracts specific validation fields: `max_length`, `min_length`, `max_value`, `min_value`
- These become form fields with separate container divs

**Issues:**
1. Multiple FormWriter V1 forms
2. Heavy jQuery validation/visibility logic (will need to migrate to FormWriter V2 visibility rules)
3. Serialized data unpacking and repacking
4. Inline question option management
5. Action-based POST routing

**Migration Complexity:** 🔴 VERY HIGH - Serialized data handling + jQuery refactoring

---

## 3. Data Model Complications

### Question Type Constants
```php
Question::TYPE_SHORT_TEXT = 1
Question::TYPE_LONG_TEXT = 2
Question::TYPE_DROPDOWN = 3
Question::TYPE_RADIO = 4
Question::TYPE_CHECKBOX = 5
Question::TYPE_CHECKBOX_LIST = 6
```

### Serialized Validation Data
The `qst_validate` field stores a serialized PHP array:
```php
// Current storage: serialized array with keys
[
    'required' => true,
    'integer' => true,
    'decimal' => false,
    'max_length' => 100,
    'min_length' => 5,
    'max_value' => 1000,
    'min_value' => 10
]

// Stored in database as: a:8:{s:8:"required";b:1;s:7:"integer";b:1;...}
```

---

## 4. Migration Path & Requirements

### 4.1 admin_question.php (High)

**Steps:**
1. **CRITICAL:** Refactor Question class `output_question()` method
   - Instead of echoing FormWriter calls
   - Return field configuration
   - Create new method that returns FormWriter field definitions
2. Update display page to use new approach
3. Convert from V1 to V2

**New Question Method Needed:**
```php
// NEW: Return field definition instead of echoing
function get_form_field_definition($formwriter, $value=NULL){
    return [
        'type' => $this->get('qst_type'),
        'field_name' => 'question_'.$this->key,
        'label' => $this->get('qst_question'),
        'value' => $value,
        'options' => $this->get_question_options() // for dropdowns, radio, etc.
    ];
}
```

**Effort:** 6-8 hours
**Risk:** HIGH - Affects Question class public API

---

### 4.2 admin_question_edit.php (Very High)

**Steps:**
1. Create/update logic file with proper action routing
2. Refactor validation data handling
   - Current: Serialized array in single column
   - Options:
     - A) Keep serialization, improve handling
     - B) Migrate to JSON storage
     - C) Use multiple columns
3. Convert jQuery visibility rules to FormWriter V2
   - Lines 26-97: Validation field visibility
   - Lines 154-173: Answer options visibility
4. Convert multiple forms (main question + option form)
5. Handle question option inline management

**FormWriter V2 Visibility Rules Needed:**
```php
$formwriter->dropinput('qst_type', 'Question Type', [
    'options' => [...],
    'change_handler' => 'updateValidationFields'  // Trigger visibility update
]);

// Define visibility rules for validation fields
$formwriter->textinput('max_length', 'Max Length', [
    'visibility_rules' => [
        [1, 2] => ['show' => true]  // Show for SHORT_TEXT, LONG_TEXT
    ]
]);
```

**Effort:** 12-16 hours
**Risk:** VERY HIGH - Serialization + complex logic + jQuery refactoring

---

## 5. Dependency Analysis

### Question Class Changes
Any refactoring to `Question::output_question()` will affect:
- `/views/survey_display.php` - Public survey display (if it uses this method)
- `/adm/admin_question.php` - Display page
- Potentially other survey/question display pages

### ValidationOptions Serialization
Any changes to validation storage format will require:
- Database migration script
- Data conversion logic
- Backward compatibility handling

---

## 6. Recommendation

### Option A: Defer These Pages (Recommended for Phase 1)

These pages should be **deferred to Phase 2** because:
1. High complexity and refactoring required
2. Affect core data model classes (Question)
3. Require significant time investment (40-50 hours)
4. Lower business impact (not critical operations)
5. Can prioritize remaining simpler pages first

**Remaining simpler pages to complete Phase 1:**
- admin_phone_edit.php (pending testing)
- admin_settings.php
- admin_settings_email.php
- admin_settings_payments.php

---

### Option B: Migrate Question Pages (If Time Permits)

**Phase 2 (Question Pages Only):**
1. admin_question.php - 6-8 hours (HIGH RISK)
2. admin_question_edit.php - 12-16 hours (VERY HIGH RISK)

**Note:** Survey pages (admin_survey.php and admin_survey_edit.php) have already been completed.

---

## 7. Files Involved

### Primary Files
- `/adm/admin_survey.php` - Survey listing/management
- `/adm/admin_survey_edit.php` - Survey create/edit
- `/adm/admin_question.php` - Question display
- `/adm/admin_question_edit.php` - Question create/edit
- `/data/questions_class.php` - Question class with custom form generation
- `/data/question_options_class.php` - Question options

### Logic Files (May exist)
- `/adm/logic/admin_question_logic.php` - Already referenced in admin_question.php
- `/adm/logic/admin_question_edit_logic.php` - Already referenced in admin_question_edit.php

### Database Schema
- `qst_questions` table with `qst_validate` (serialized)
- `qop_question_options` table for options
- `svy_surveys` table
- `srq_survey_questions` table

---

## 8. Summary

| Page | Type | Complexity | Effort | Risk | Status |
|------|------|-----------|--------|------|--------|
| admin_question.php | Display | High | 6-8h | HIGH | Requires class refactor |
| admin_question_edit.php | Create/Edit | Very High | 12-16h | VERY HIGH | Requires serialization refactor |

**Note:** admin_survey.php and admin_survey_edit.php have been COMPLETED and migrated to FormWriter V2 (turned out to be much simpler than initially estimated - only took 1-2 hours total instead of 5-7 hours).

**Question Pages Remaining:** 18-24 hours (requires Question class refactoring)
**Phase 1 Remaining:** Just admin_phone_edit and 3 settings pages

---

## Conclusion

The survey pages have been **COMPLETED** and migrated to FormWriter V2. They turned out to be much simpler than initially estimated.

The question pages still represent **significant complexity** and should be addressed in Phase 2 of the FormWriter V2 migration, allowing Phase 1 to focus on completing the remaining simpler pages (admin_phone_edit and settings pages).

---
## 9. SIMPLIFIED REFACTORING SPECIFICATION (NO BACKWARD COMPATIBILITY)

### 9.1 Direct Question Class Refactoring

Since backward compatibility is not required, we can directly replace the old methods with clean V2 implementations.

#### Updated Question Class Methods

```php
// MODIFIED FILE: /data/questions_class.php

/**
 * Render question field using FormWriter V2
 * Replaces old output_question() method completely
 */
public function render_question_v2($formwriter, $value = null, $append_text = null) {
    $field_name = 'question_' . $this->key;
    $label = $this->get('qst_question') . $append_text;
    $validation = $this->get_validation_rules_v2();

    switch ($this->get('qst_type')) {
        case self::TYPE_SHORT_TEXT:
            $max_length = $this->get_validation_value('max_length') ?: 255;
            $formwriter->textinput($field_name, $label, [
                'value' => $value,
                'maxlength' => $max_length,
                'validation' => $validation
            ]);
            break;

        case self::TYPE_LONG_TEXT:
            $formwriter->textbox($field_name, $label, [
                'value' => $value,
                'rows' => 5,
                'htmlmode' => 'no',
                'validation' => $validation
            ]);
            break;

        case self::TYPE_DROPDOWN:
            $options = $this->load_question_options();
            $formwriter->dropinput($field_name, $label, [
                'options' => $options,
                'value' => $value,
                'validation' => $validation
            ]);
            break;

        case self::TYPE_RADIO:
            $options = $this->load_question_options();
            $formwriter->radioinput($field_name, $label, [
                'options' => $options,
                'value' => $value,
                'validation' => $validation
            ]);
            break;

        case self::TYPE_CHECKBOX:
            $options = $this->load_question_options();
            if (count($options) > 0) {
                $true_value = array_keys($options)[0];
                $formwriter->checkboxinput($field_name, $label, [
                    'checked' => ($value == $true_value),
                    'value' => $true_value,
                    'validation' => $validation
                ]);
            }
            break;

        case self::TYPE_CHECKBOX_LIST:
            $options = $this->load_question_options();
            $formwriter->checkboxlist($field_name . '[]', $label, [
                'options' => $options,
                'checked' => $value,
                'validation' => $validation
            ]);
            break;
    }
}

/**
 * Get V2 validation rules
 * Replaces old output_js_validation() method
 */
public function get_validation_rules_v2() {
    $validation_data = unserialize($this->get('qst_validate')) ?: [];
    $rules = [];

    if (!empty($validation_data['required'])) {
        $rules['required'] = true;
    }

    if (!empty($validation_data['integer'])) {
        $rules['pattern'] = '^[0-9]+$';
        $rules['pattern_message'] = 'Please enter a whole number';
    } elseif (!empty($validation_data['decimal'])) {
        $rules['pattern'] = '^[0-9]+(\.[0-9]+)?$';
        $rules['pattern_message'] = 'Please enter a valid number';
    }

    if (isset($validation_data['max_length'])) {
        $rules['maxlength'] = $validation_data['max_length'];
    }

    if (isset($validation_data['min_length'])) {
        $rules['minlength'] = $validation_data['min_length'];
    }

    if (isset($validation_data['max_value'])) {
        $rules['max'] = $validation_data['max_value'];
    }

    if (isset($validation_data['min_value'])) {
        $rules['min'] = $validation_data['min_value'];
    }

    return $rules;
}

/**
 * Helper to get validation value
 */
private function get_validation_value($key) {
    $validation_data = unserialize($this->get('qst_validate')) ?: [];
    return isset($validation_data[$key]) ? $validation_data[$key] : null;
}

/**
 * Load question options
 */
private function load_question_options() {
    $options = new MultiQuestionOption(
        ['deleted' => false, 'question_id' => $this->key],
        null, null, null
    );
    $options->load();
    return $options->get_dropdown_array();
}

// DELETE these old methods completely:
// - output_question()
// - output_js_validation()
```

---

### 9.2 Simplified admin_question.php

```php
<?php
// /adm/admin_question.php - SIMPLIFIED V2 VERSION
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_question_logic.php'));

$page_vars = process_logic(admin_question_logic($_GET, $_POST));
extract($page_vars);

$page = new AdminPage();
$page->admin_header([
    'menu-id' => 'survey-questions',
    'breadcrumbs' => [
        'Surveys' => '/admin/admin_surveys',
        'Questions' => '/admin/admin_questions',
        'Question ' . $question->key => '',
    ],
    'session' => $session,
]);

// Page header with actions
$options['title'] = 'Question ' . $question->key;
$options['altlinks'] = [
    'Edit Question' => '/admin/admin_question_edit?qst_question_id=' . $question->key,
    'Delete Question' => '/admin/admin_question_permanent_delete?qst_question_id=' . $question->key
];

if (!$question->get('qst_delete_time') && $_SESSION['permission'] >= 8) {
    $options['altlinks']['Soft Delete'] = '/admin/admin_question?action=delete&qst_question_id=' . $question->key;
}

$page->begin_box($options);

// Status display
if ($question->get('qst_delete_time')) {
    echo 'Status: Deleted at ' . LibraryFunctions::convert_time($question->get('qst_delete_time'), 'UTC', $session->get_timezone()) . '<br />';
} else if ($question->get('qst_is_published')) {
    echo '<strong>Published:</strong> ' . LibraryFunctions::convert_time($question->get('qst_published_time'), 'UTC', $session->get_timezone()) . '<br />';
} else {
    echo '<strong>UNPUBLISHED</strong><br />';
}

echo '<strong>Created:</strong> ' . LibraryFunctions::convert_time($question->get('qst_create_time'), 'UTC', $session->get_timezone()) . '<br />';

if ($_POST && isset($valid)) {
    echo '<b>' . $valid . '</b>';
}

// Initialize FormWriter V2
$formwriter = $page->getFormWriter('form1', 'v2', [
    'edit_primary_key_value' => $question->key
]);

$formwriter->begin_form();

// Render the question using new V2 method
$question->render_question_v2($formwriter, $test_value);

$formwriter->submitbutton('test_button', 'Test');
$formwriter->end_form();

$page->end_box();
$page->admin_footer();
?>
```

---

### 9.3 Simplified admin_question_edit.php with Native V2 Features

```php
<?php
// /adm/admin_question_edit.php - SIMPLIFIED V2 VERSION
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_question_edit_logic.php'));

$page_vars = process_logic(admin_question_edit_logic($_GET, $_POST));
extract($page_vars);

$page = new AdminPage();
$page->admin_header([
    'menu-id' => 'survey-questions',
    'breadcrumbs' => [
        'Surveys' => '/admin/admin_surveys',
        'Questions' => '/admin/admin_questions',
        'Edit Question' => '',
    ],
    'session' => $session,
]);

$pageoptions['title'] = "Edit Question";
$page->begin_box($pageoptions);

// Initialize FormWriter V2
$formwriter = $page->getFormWriter('form1', 'v2', [
    'model' => $question,
    'edit_primary_key_value' => $question->key
]);

// Add JavaScript for visibility rules (can be converted to V2 visibility rules if supported)
?>
<script>
function updateValidationVisibility() {
    var type = $('#qst_type').val();

    // Hide all validation fields first
    $('.validation-field').hide();
    $('#answersbox').hide();

    if (type == <?php echo Question::TYPE_SHORT_TEXT; ?>) {
        $('#max_length_container, #min_length_container').show();
        $('#max_value_container, #min_value_container').show();
        $('#validation_integer_container, #validation_decimal_container').show();
    } else if (type == <?php echo Question::TYPE_LONG_TEXT; ?>) {
        $('#max_length_container, #min_length_container').show();
    } else {
        $('#answersbox').show();
    }
}

$(document).ready(function() {
    updateValidationVisibility();
    $('#qst_type').change(updateValidationVisibility);
});
</script>
<?php

$formwriter->begin_form();

$formwriter->textinput('qst_question', 'Question', [
    'validation' => ['required' => true, 'maxlength' => 255]
]);

// Question type dropdown
$formwriter->dropinput('qst_type', 'Type', [
    'options' => [
        'Short text' => Question::TYPE_SHORT_TEXT,
        'Long Text' => Question::TYPE_LONG_TEXT,
        'Dropdown' => Question::TYPE_DROPDOWN,
        'Radio' => Question::TYPE_RADIO,
        'Checkbox' => Question::TYPE_CHECKBOX,
        'Checkbox List' => Question::TYPE_CHECKBOX_LIST
    ]
]);

// Unpack validation data
$validation_data = unserialize($question->get('qst_validate')) ?: [];
$validation_checks = [];
if (!empty($validation_data['required'])) $validation_checks[] = 'required';
if (!empty($validation_data['integer'])) $validation_checks[] = 'integer';
if (!empty($validation_data['decimal'])) $validation_checks[] = 'decimal';

$formwriter->checkboxlist('validation_options', 'Validation options', [
    'options' => [
        'Required' => 'required',
        'Integer (Example: 5)' => 'integer',
        'Decimal (Example: 5.5)' => 'decimal'
    ],
    'checked' => $validation_checks
]);

// Validation fields with containers for visibility
echo '<div id="max_length_container" class="validation-field">';
$formwriter->textinput('max_length', 'Validation Maximum Length', [
    'value' => $validation_data['max_length'] ?? '',
    'maxlength' => 3
]);
echo '</div>';

echo '<div id="min_length_container" class="validation-field">';
$formwriter->textinput('min_length', 'Validation Minimum Length', [
    'value' => $validation_data['min_length'] ?? '',
    'maxlength' => 3
]);
echo '</div>';

echo '<div id="max_value_container" class="validation-field">';
$formwriter->textinput('max_value', 'Validation Maximum Value', [
    'value' => $validation_data['max_value'] ?? '',
    'maxlength' => 10
]);
echo '</div>';

echo '<div id="min_value_container" class="validation-field">';
$formwriter->textinput('min_value', 'Validation Minimum Value', [
    'value' => $validation_data['min_value'] ?? '',
    'maxlength' => 10
]);
echo '</div>';

$formwriter->submitbutton('submit_button', 'Submit');
$formwriter->end_form();

$page->end_box();

// Answer Options Section
echo '<div id="answersbox" style="display:none;">';
$pageoptions['title'] = "Edit Answers";
$page->begin_box($pageoptions);

$question_options = $question->get_question_options();
if (!count($question_options)) {
    echo 'None';
}

echo '<ul>';
foreach ($question_options as $question_option) {
    echo '<li>';
    echo htmlspecialchars($question_option->get('qop_question_option_label')) . ' - ';
    echo htmlspecialchars($question_option->get('qop_question_option_value'));
    echo ' (<a href="/admin/admin_question_edit?qop_question_option_id=' . $question_option->key;
    echo '&qst_question_id=' . $question->key . '&action=remove_question_option">delete</a>)';
    echo '</li>';
}
echo '</ul>';

// Add option form
if (!($question->key && count($question_options) >= 1 && $question->get('qst_type') == Question::TYPE_CHECKBOX)) {
    echo '<h4>Add New Question Option</h4>';

    $formwriter2 = $page->getFormWriter('form2', 'v2', [
        'action' => '/admin/admin_question_edit?action=add_question_option&qst_question_id=' . $question->key
    ]);

    $formwriter2->begin_form();

    $formwriter2->textinput('qop_question_option_label', 'Label', [
        'validation' => ['required' => true, 'maxlength' => 255]
    ]);

    $formwriter2->textinput('qop_question_option_value', 'Value', [
        'validation' => ['required' => true, 'maxlength' => 255]
    ]);

    $formwriter2->submitbutton('add_option', 'Add Option');
    $formwriter2->end_form();
}

$page->end_box();
echo '</div>';

$page->admin_footer();
?>
```

---

### 9.4 Clean Logic File (No Changes Needed)

The existing `admin_question_edit_logic.php` can be used as-is with minor updates to handle V2 patterns (already shown in original investigation).

---

### 9.5 Front-end Survey Display Update

```php
// MODIFIED: /views/survey.php
// Change from:
echo $question->output_question($formwriter, $answer_fill);

// To:
$question->render_question_v2($formwriter, $answer_fill);
```

---

### 9.6 Simplified Migration Path

#### Phase 1: Update Question Class (2 hours)
1. Add new methods: `render_question_v2()`, `get_validation_rules_v2()`
2. Add helper methods: `get_validation_value()`, `load_question_options()`
3. Delete old methods: `output_question()`, `output_js_validation()`

#### Phase 2: Migrate Admin Pages (4 hours)
1. Update `admin_question.php` to use new method
2. Update `admin_question_edit.php` with simplified jQuery (or V2 visibility if available)
3. Test all 6 question types

#### Phase 3: Update Front-end (1 hour)
1. Update `/views/survey.php` to use new method
2. Test public survey display

**Total Time: ~7 hours** for question pages only (vs 40-50 hours with backward compatibility)

**Note:** Survey pages have already been completed.

---

### 9.7 Testing Checklist

| Component | Test |
|-----------|------|
| Short Text | ✓ Renders, ✓ Validates (required, length, numeric) |
| Long Text | ✓ Renders, ✓ Validates (required, length) |
| Dropdown | ✓ Renders, ✓ Shows options, ✓ Validates |
| Radio | ✓ Renders, ✓ Shows options, ✓ Validates |
| Checkbox | ✓ Renders, ✓ Single option only, ✓ Validates |
| Checkbox List | ✓ Renders, ✓ Multiple options, ✓ Array submission |
| Add Option | ✓ Adds to database, ✓ Shows in list |
| Remove Option | ✓ Deletes from database, ✓ Updates display |
| Validation Visibility | ✓ Shows/hides based on type |

---

### 9.8 Benefits of Direct Refactoring

1. **Cleaner Code** - No compatibility layers or adapters
2. **Faster Development** - 10 hours vs 40-50 hours
3. **Easier Maintenance** - Single code path to maintain
4. **Better Performance** - No adapter overhead
5. **Simpler Testing** - Only test V2 functionality

This approach is recommended since backward compatibility is not required.