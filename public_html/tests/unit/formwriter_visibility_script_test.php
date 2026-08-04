<?php
/** @joinery-test
 * name: formwriter_visibility_script
 * tier: safe
 * env: any
 * needs: []
 */
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
 *   - validation rejects keying a checkbox on a value, a field both shown and
 *     hidden for one value, a non-string field reference, and a multi-select
 *     checkbox list as a trigger — each as a thrown InvalidArgumentException
 *
 * A failure here means generateVisibilityScript() / validateVisibilityRules()
 * in FormWriterV2Base changed shape. See docs/formwriter.md "Field Visibility".
 *
 * Runs offline in well under a second — no network, no DB.
 *
 * Run:  php tests/unit/formwriter_visibility_script_test.php
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));

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
ok('select trigger reads element .value',
      strpos($selectHtml, 'const selected = el.value;') !== false);
ok('select trigger does NOT read .checked',
      strpos($selectHtml, '.checked') === false);

// ── checkbox trigger: .checked read, checked/unchecked keys ────────────────

$checkboxHtml = render_field('checkboxinput', ['repeats', 'Repeats', [
    'visibility_rules' => [
        'checked'   => ['show' => ['interval']],
        'unchecked' => ['hide' => ['interval']],
    ],
]]);
ok('checkbox trigger reads .checked into checked/unchecked key',
      strpos($checkboxHtml, 'el.checked ? "checked" : "unchecked"') !== false);
ok('checkbox trigger listens on the checkbox via change',
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
ok('radio trigger reads the :checked option value',
      strpos($radioHtml, "document.querySelector(\"input[name='ends']:checked\")") !== false);
ok('radio trigger wires a listener on every radio in the group',
      strpos($radioHtml, "document.querySelectorAll(\"input[name='ends']\")") !== false
      && strpos($radioHtml, 'radios.forEach(') !== false);

// A card radio is the same trigger wearing different markup. The mailbox
// domain editor picks a security level this way and hides the AI consent
// checkboxes on Standard, so if 'card' ever stopped emitting the script the
// consents would sit visible on a level where they mean nothing.
$cardHtml = render_field('radioinput', ['level', 'Security level', [
    'card' => true,
    'options' => ['standard' => 'Standard', 'private' => 'Private'],
    'value' => 'standard',
    'visibility_rules' => [
        'standard' => ['hide' => ['ai_on']],
        'private'  => ['show' => ['ai_on']],
    ],
]]);
ok('a card radio emits the same trigger script as a plain one',
      strpos($cardHtml, "document.querySelector(\"input[name='level']:checked\")") !== false
      && strpos($cardHtml, 'radios.forEach(') !== false);

// The other half of the contract, and the half nothing else pins: the script
// hides `{id}_container`, so a target that renders no container element is
// addressed by a selector that matches nothing and never hides.
$targetHtml = render_field('checkboxinput', ['ai_on', 'Let AI read it', ['value' => false]]);
ok('a checkbox target renders the {id}_container the script looks for',
      strpos($targetHtml, 'id="ai_on_container"') !== false);
ok('and the show/hide lookup asks for that container first',
      strpos($radioHtml, 'document.getElementById(id + "_container") || document.getElementById(id)') !== false);

// ── checkboxList radio: addresses the name="{name}[]" group ─────────────────

$listRadioHtml = render_field('checkboxList', ['picker', 'Picker', [
    'type' => 'radio',
    'options' => ['one' => 'One', 'two' => 'Two'],
    'visibility_rules' => [
        'one' => ['show' => ['detail']],
        'two' => ['hide' => ['detail']],
    ],
]]);
ok('checkboxList radio addresses the name="{name}[]" group',
      strpos($listRadioHtml, "input[name='picker[]']:checked") !== false);

// ── validation: reject misuse ──────────────────────────────────────────────

// Every rejection below is a mistake in the calling code, so each throws rather
// than warning. It is an exception and not a fatal on purpose: a malformed rules
// array must still stop the render, but a caller that wants to probe a form
// definition can catch it, and the trace names the offending call.
/** Returns the Throwable $fn raises, or null if it doesn't. */
function raised(callable $fn) {
    try { $fn(); return null; }
    catch (Throwable $e) { return $e; }
}

$badKey = raised(function () {
    render_field('checkboxinput', ['bad', 'Bad', [
        'visibility_rules' => ['1' => ['show' => ['x']]],
    ]]);
});
ok('checkbox keyed on a value (not checked/unchecked) is rejected', $badKey !== null);
ok('and it throws InvalidArgumentException, not a deprecated E_USER_ERROR',
      $badKey instanceof InvalidArgumentException);
ok('the message names the keys that are valid',
      $badKey !== null && strpos($badKey->getMessage(), "'checked', 'unchecked', or 'default'") !== false);

// Showing and hiding the same field for one value is contradictory; whichever
// the emitted script applied last would win, silently.
$conflict = raised(function () {
    render_field('dropinput', ['mode', 'Mode', [
        'options' => ['a' => 'A'],
        'visibility_rules' => ['a' => ['show' => ['x', 'y'], 'hide' => ['y']]],
    ]]);
});
ok('a field both shown and hidden for one value is rejected',
      $conflict instanceof InvalidArgumentException);
ok('and the message names the conflicting field',
      $conflict !== null && strpos($conflict->getMessage(), 'y') !== false);

// Field references become DOM ids in the emitted script; a non-string would be
// interpolated into a selector that matches nothing.
$badRef = raised(function () {
    render_field('dropinput', ['mode', 'Mode', [
        'options' => ['a' => 'A'],
        'visibility_rules' => ['a' => ['show' => [['nested']]]],
    ]]);
});
ok('a non-string field reference is rejected',
      $badRef instanceof InvalidArgumentException);

ok('multi-select checkbox list cannot be a visibility trigger', thrown(function () {
    render_field('checkboxList', ['multi', 'Multi', [
        'type' => 'checkbox',
        'options' => ['a' => 'A', 'b' => 'B'],
        'visibility_rules' => ['a' => ['show' => ['x']]],
    ]]);
}) !== null);

harness_finish();
