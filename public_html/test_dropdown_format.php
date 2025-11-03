<?php
// Quick test to determine what format FormWriter V2 actually expects
require_once(__DIR__ . '/includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));

// Test 1: [id => label] format
$formwriter = new FormWriterV2Bootstrap('test_form');

echo "<h2>Test 1: [id => label] format (numeric keys)</h2>";
$options = [
    1 => 'Option One',
    2 => 'Option Two',
    3 => 'Option Three'
];
$formwriter->checkboxList('test_numeric', 'Test with numeric keys:', [
    'options' => $options,
    'checked' => [1, 3]
]);

echo "<h2>Test 2: [label => id] format (string keys)</h2>";
$options2 = [
    'Option One' => 1,
    'Option Two' => 2,
    'Option Three' => 3
];
$formwriter->checkboxList('test_string', 'Test with string keys:', [
    'options' => $options2,
    'checked' => [1, 3]
]);

echo "<h2>Test 3: Real data from database</h2>";
require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));
$mailing_lists = new MultiMailingList();
$mailing_lists->load();
$real_options = $mailing_lists->get_dropdown_array();
$formwriter->checkboxList('test_real', 'Real mailing lists:', [
    'options' => $real_options,
    'checked' => []
]);
?>
