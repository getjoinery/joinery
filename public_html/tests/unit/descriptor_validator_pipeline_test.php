<?php
/**
 * Unit test for the DescriptorValidator extensions added for pipeline-mode
 * verdicts (specs/joinery_ai_item_pipeline.md § DescriptorValidator
 * extensions): enum, min/max, max_length, type 'array' (nested items +
 * max_items), and the generated renderOutputInstruction() text.
 *
 * Runs offline, no DB. Run:  php tests/unit/descriptor_validator_pipeline_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/DescriptorValidator.php'));

$tests = 0;
$failures = 0;
function check($label, $condition) {
    global $tests, $failures;
    $tests++;
    echo ($condition ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$condition) { $GLOBALS['failures']++; }
}
/** Returns true if calling $fn throws InvalidArgumentException. */
function throws_invalid(callable $fn) {
    try { $fn(); return false; }
    catch (InvalidArgumentException $e) { return true; }
}

// --- enum ---------------------------------------------------------------
$enum_descriptor = ['input' => [
    'verdict' => ['type' => 'string', 'required' => true, 'enum' => ['keep', 'flag']],
]];
check('enum: valid value coerces through',
    DescriptorValidator::coerce($enum_descriptor, ['verdict' => 'keep']) === ['verdict' => 'keep']);
check('enum: value outside the list throws',
    throws_invalid(fn() => DescriptorValidator::coerce($enum_descriptor, ['verdict' => 'delete'])));

// --- min / max ------------------------------------------------------------
$bounds_descriptor = ['input' => [
    'score' => ['type' => 'int', 'required' => true, 'min' => 1, 'max' => 10],
]];
check('min/max: in-range value passes',
    DescriptorValidator::coerce($bounds_descriptor, ['score' => 5]) === ['score' => 5]);
check('min/max: below minimum throws',
    throws_invalid(fn() => DescriptorValidator::coerce($bounds_descriptor, ['score' => 0])));
check('min/max: above maximum throws',
    throws_invalid(fn() => DescriptorValidator::coerce($bounds_descriptor, ['score' => 11])));

// --- max_length ------------------------------------------------------------
$length_descriptor = ['input' => [
    'reason' => ['type' => 'string', 'max_length' => 5],
]];
check('max_length: short string passes',
    DescriptorValidator::coerce($length_descriptor, ['reason' => 'ok']) === ['reason' => 'ok']);
check('max_length: over-length string throws',
    throws_invalid(fn() => DescriptorValidator::coerce($length_descriptor, ['reason' => 'way too long'])));

// --- type 'array' (nested items + max_items) ------------------------------
$array_descriptor = ['input' => [
    'flags' => [
        'type' => 'array',
        'max_items' => 2,
        'items' => [
            'code'   => ['type' => 'string', 'required' => true, 'enum' => ['a', 'b']],
            'weight' => ['type' => 'int', 'min' => 0, 'max' => 100],
        ],
    ],
]];
$valid_array_input = ['flags' => [
    ['code' => 'a', 'weight' => 50],
    ['code' => 'b'],
]];
check('array: nested objects coerce, missing optional field omitted',
    DescriptorValidator::coerce($array_descriptor, $valid_array_input) === [
        'flags' => [['code' => 'a', 'weight' => 50], ['code' => 'b']],
    ]);
check('array: a bad nested enum value throws',
    throws_invalid(fn() => DescriptorValidator::coerce($array_descriptor, [
        'flags' => [['code' => 'z']],
    ])));
check('array: exceeding max_items throws',
    throws_invalid(fn() => DescriptorValidator::coerce($array_descriptor, [
        'flags' => [['code' => 'a'], ['code' => 'a'], ['code' => 'a']],
    ])));
check('array: a non-object element throws',
    throws_invalid(fn() => DescriptorValidator::coerce($array_descriptor, [
        'flags' => ['not-an-object'],
    ])));
check('array: absent (no default) omits the field rather than erroring',
    DescriptorValidator::coerce($array_descriptor, []) === []);

// --- renderOutputInstruction() --------------------------------------------
$instruction = DescriptorValidator::renderOutputInstruction($enum_descriptor);
check('renderOutputInstruction: names the field',
    strpos($instruction, '"verdict"') !== false);
check('renderOutputInstruction: surfaces the enum values',
    strpos($instruction, 'keep') !== false && strpos($instruction, 'flag') !== false);
check('renderOutputInstruction: instructs a single JSON object',
    strpos($instruction, 'JSON object') !== false);

$array_instruction = DescriptorValidator::renderOutputInstruction($array_descriptor);
check('renderOutputInstruction: nested array field names its item fields',
    strpos($array_instruction, 'code') !== false && strpos($array_instruction, 'weight') !== false);
check('renderOutputInstruction: surfaces max_items',
    strpos($array_instruction, 'max_items 2') !== false);

echo "\n--------------------------------------------\n";
echo "Tests: $tests   Failures: $failures\n";
exit($failures === 0 ? 0 : 1);
