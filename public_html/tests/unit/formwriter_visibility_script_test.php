<?php
/**
 * PURPOSE: Pins the per-trigger-type JavaScript that FormWriter emits for
 * visibility_rules. A field can drive show/hide of other fields based on its
 * current value; how that "current value" is read out of the DOM differs by
 * trigger type, and getting it wrong silently breaks the form with no error.
 *
 * What is pinned:
 *   - select trigger reads element.value (unchanged, legacy contract)
 *   - checkbox trigger reads .checked and keys on 'checked'/'unchecked'
 *   - radio group reads the :checked option's value and wires every radio
 *   - a checkboxList radio addresses its name="{name}[]" group
 *   - validation rejects keying a checkbox on a value, and rejects a
 *     multi-select checkbox list as a trigger
 *
 * A failure here means generateVisibilityScript() / validateVisibilityRules()
 * in FormWriterV2Base changed shape. See docs/formwriter.md "Field Visibility".
 *
 * Runs offline in well under a second — no network, no DB.
 *
 * Run:  php tests/unit/formwriter_visibility_script_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));

$tests = 0;
$failures = 0;
function check($label, $condition) {
    global $tests, $failures;
    $tests++;
    echo ($condition ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$condition) { $GLOBALS['failures']++; }
}
/** Returns the message of any Throwable $fn raises, or null if it doesn't. */
function thrown(callable $fn) {
    try { $fn(); return null; }
    catch (Throwable $e) { return $e->getMessage(); }
}

/** Render one field through HTML5 and return the emitted markup+script. */
function render_field($method, array $args) {
    $fw = new FormWriterV2HTML5('vis_form');
    ob_start();
    call_user_func_array([$fw, $method], $args);
    return ob_get_clean();
}

// ── select trigger: legacy .value read, listens on the <select> ────────────

$selectHtml = render_field('dropinput', ['mode', 'Mode', [
    'options' => ['a' => 'A', 'b' => 'B'],
    'value' => 'a',
    'visibility_rules' => ['a' => ['show' => ['x']], 'b' => ['hide' => ['x']]],
]]);
check('select trigger reads element .value',
      strpos($selectHtml, 'const selected = el.value;') !== false);
check('select trigger does NOT read .checked',
      strpos($selectHtml, '.checked') === false);

// ── checkbox trigger: .checked read, checked/unchecked keys ────────────────

$checkboxHtml = render_field('checkboxinput', ['repeats', 'Repeats', [
    'visibility_rules' => [
        'checked'   => ['show' => ['interval']],
        'unchecked' => ['hide' => ['interval']],
    ],
]]);
check('checkbox trigger reads .checked into checked/unchecked key',
      strpos($checkboxHtml, 'el.checked ? "checked" : "unchecked"') !== false);
check('checkbox trigger listens on the checkbox via change',
      strpos($checkboxHtml, 'el.addEventListener("change"') !== false);

// ── radio trigger: :checked value read, listens on every radio ─────────────

$radioHtml = render_field('radioinput', ['ends', 'Ends', [
    'options' => ['never' => 'Never', 'date' => 'On date'],
    'value' => 'never',
    'visibility_rules' => [
        'never' => ['hide' => ['end_date']],
        'date'  => ['show' => ['end_date']],
    ],
]]);
check('radio trigger reads the :checked option value',
      strpos($radioHtml, "document.querySelector(\"input[name='ends']:checked\")") !== false);
check('radio trigger wires a listener on every radio in the group',
      strpos($radioHtml, "document.querySelectorAll(\"input[name='ends']\")") !== false
      && strpos($radioHtml, 'radios.forEach(') !== false);

// ── checkboxList radio: addresses the name="{name}[]" group ─────────────────

$listRadioHtml = render_field('checkboxList', ['picker', 'Picker', [
    'type' => 'radio',
    'options' => ['one' => 'One', 'two' => 'Two'],
    'visibility_rules' => [
        'one' => ['show' => ['detail']],
        'two' => ['hide' => ['detail']],
    ],
]]);
check('checkboxList radio addresses the name="{name}[]" group',
      strpos($listRadioHtml, "input[name='picker[]']:checked") !== false);

// ── validation: reject misuse ──────────────────────────────────────────────

check('checkbox keyed on a value (not checked/unchecked) is rejected', thrown(function () {
    set_error_handler(function ($n, $s) { throw new RuntimeException($s); });
    try {
        render_field('checkboxinput', ['bad', 'Bad', [
            'visibility_rules' => ['1' => ['show' => ['x']]],
        ]]);
    } finally { restore_error_handler(); }
}) !== null);

check('multi-select checkbox list cannot be a visibility trigger', thrown(function () {
    render_field('checkboxList', ['multi', 'Multi', [
        'type' => 'checkbox',
        'options' => ['a' => 'A', 'b' => 'B'],
        'visibility_rules' => ['a' => ['show' => ['x']]],
    ]]);
}) !== null);

// ── Summary ────────────────────────────────────────────────────────────────

echo "\n" . ($tests - $failures) . "/" . $tests . " passed\n";
exit($failures ? 1 : 0);
