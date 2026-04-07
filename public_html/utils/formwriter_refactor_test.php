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
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
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
