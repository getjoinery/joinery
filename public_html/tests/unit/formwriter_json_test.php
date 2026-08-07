<?php
/** @joinery-test
 * name: formwriter_json
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * PURPOSE: This test pins the JSON form-definition schema (schema_version 1)
 * served by GET /api/v1/form/{action_name} and consumed by the mobile apps'
 * generic form renderers.
 *
 * The schema is a cross-system contract with shipped app binaries. If it
 * drifts, every web form still renders fine — nothing on the web surfaces
 * the breakage — but installed apps misrender forms, and the fix is an App
 * Store / Play Store release. This file is the only thing that notices that
 * drift before an app does.
 *
 * A failure here means a change to FormWriterV2Base or FormWriterV2JSON
 * altered what the API serializes. Do NOT casually update the expectations
 * to match: within schema_version 1, changes must be additive-only. If a
 * breaking change is truly intended, that is a schema_version bump, and the
 * expectations here are the checklist of what the new version must still
 * support (see docs/formwriter.md, "JSON Output Mode").
 *
 * What is pinned: the field shape per supported type, the datetime
 * compound-submit contract (submit_parts keys accepted by
 * process_datetimeinput()), loud failure on non-serializable constructs
 * (custom_script/onchange, file/image/rich-text/repeater, second submit
 * button), CSRF off in JSON mode, hidden value round-trip,
 * set_values()/set_model() binding, and the single-source guarantee
 * (a builder yields identical fields through FormWriterV2HTML5 and
 * FormWriterV2JSON).
 *
 * Runs offline in under a second — no network, no DB writes.
 *
 * Run:  php tests/unit/formwriter_json_test.php
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/FormWriterV2JSON.php'));
require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));

/** Returns the exception message if $fn throws, or null if it doesn't. */
function thrown(callable $fn) {
    try { $fn(); return null; }
    catch (Exception $e) { return $e->getMessage(); }
}
/** Find a field by name in a definition. */
function field($definition, $name) {
    foreach ($definition['fields'] as $f) {
        if ($f['name'] === $name) return $f;
    }
    return null;
}

// ── Schema shape: common field types ──────────────────────────────────────

$fw = new FormWriterV2JSON('test_form');
ob_start();
$fw->begin_form();
$fw->textinput('usr_first_name', 'First Name', ['maxlength' => 32, 'required' => true]);
$fw->textinput('usr_email', 'Email', ['type' => 'email', 'maxlength' => 64]);
$fw->passwordinput('usr_password', 'Password', ['required' => true]);
$fw->numberinput('quantity', 'Quantity', ['min' => 1, 'max' => 10, 'value' => 3]);
$fw->textarea('notes', 'Notes', ['maxlength' => 500]);
$fw->dropinput('color', 'Color', [
    'options' => ['r' => 'Red', 'b' => 'Blue'],
    'empty_option' => true,
    'value' => 'b',
    'visibility_rules' => ['r' => ['show' => ['notes']], 'b' => ['hide' => ['notes']]],
]);
$fw->checkboxinput('agree', 'I agree', ['checked' => true]);
$fw->radioinput('size', 'Size', ['options' => ['s' => 'Small', 'l' => 'Large'], 'value' => 's']);
$fw->checkboxList('toppings', 'Toppings', [
    'options' => [1 => 'Cheese', 2 => 'Olives'],
    'checked' => [2],
    'disabled' => [1],
]);
// Checkbox and radio triggers carry visibility_rules into the JSON contract so
// the native renderers can apply the same per-type read semantics as the web.
$fw->checkboxinput('repeats', 'Repeats', [
    'visibility_rules' => [
        'checked'   => ['show' => ['notes']],
        'unchecked' => ['hide' => ['notes']],
    ],
]);
$fw->radioinput('ends', 'Ends', [
    'options' => ['never' => 'Never', 'date' => 'On date'],
    'value' => 'never',
    'visibility_rules' => [
        'never' => ['hide' => ['start_date']],
        'date'  => ['show' => ['start_date']],
    ],
]);
$fw->dateinput('start_date', 'Start', ['value' => '2026-06-11']);
$fw->timeinput('start_time', 'Time', ['value' => '14:30']);
$fw->datetimeinput('evt_start', 'Event Start', ['date_value' => '2026-06-11', 'time_value' => '14:30']);
$fw->hiddeninput('token_field', '', ['value' => 'abc123']);
$fw->submitbutton('btn_submit', 'Save Changes');
$fw->end_form();
$output = ob_get_clean();
$def = $fw->getDefinition();

ok('no output in JSON mode (begin_form/fields/end_form echo nothing)', $output === '');
ok('schema_version is 1', $def['schema_version'] === 1);
ok('form name and submit_to derive from form id',
      $def['form']['name'] === 'test_form' && $def['form']['submit_to'] === '/api/v1/action/test_form');
ok('submitbutton label becomes form submit_label', $def['form']['submit_label'] === 'Save Changes');

$f = field($def, 'usr_first_name');
ok('text field shape', $f && $f['type'] === 'text' && $f['label'] === 'First Name'
      && $f['required'] === true && $f['maxlength'] === 32);
ok('text validation includes required', isset($f['validation']['required']));

$f = field($def, 'usr_email');
ok('email subtype serialized as input_type hint', $f && ($f['input_type'] ?? '') === 'email');

$f = field($def, 'usr_password');
ok('password field never carries a value', $f && $f['type'] === 'password' && !array_key_exists('value', $f));

$f = field($def, 'quantity');
ok('number field min/max/value', $f && $f['type'] === 'number' && $f['min'] === 1 && $f['max'] === 10 && $f['value'] === '3');

$f = field($def, 'notes');
ok('textarea shape', $f && $f['type'] === 'textarea' && $f['maxlength'] === 500);

$f = field($def, 'color');
ok('drop options/value/empty_option', $f && $f['type'] === 'drop'
      && $f['options'] === ['r' => 'Red', 'b' => 'Blue'] && $f['value'] === 'b' && $f['empty_option'] === 'Select...');
ok('visibility_rules serialized verbatim',
      $f && $f['visibility_rules'] === ['r' => ['show' => ['notes']], 'b' => ['hide' => ['notes']]]);

$f = field($def, 'agree');
ok('checkbox checked_value + is_checked', $f && $f['type'] === 'checkbox'
      && $f['checked_value'] === '1' && $f['is_checked'] === true);

$f = field($def, 'size');
ok('radio options/value', $f && $f['type'] === 'radio' && $f['options'] === ['s' => 'Small', 'l' => 'Large'] && $f['value'] === 's');

$f = field($def, 'repeats');
ok('checkbox trigger serializes visibility_rules (checked/unchecked keys)',
      $f && $f['type'] === 'checkbox' && $f['visibility_rules'] === [
          'checked'   => ['show' => ['notes']],
          'unchecked' => ['hide' => ['notes']],
      ]);

$f = field($def, 'ends');
ok('radio trigger serializes visibility_rules (option-value keys)',
      $f && $f['type'] === 'radio' && $f['visibility_rules'] === [
          'never' => ['hide' => ['start_date']],
          'date'  => ['show' => ['start_date']],
      ]);

$f = field($def, 'toppings');
ok('checkbox_list options/checked/disabled_values', $f && $f['type'] === 'checkbox_list'
      && $f['checked'] === ['2'] && $f['disabled_values'] === ['1'] && $f['list_type'] === 'checkbox');

$f = field($def, 'start_date');
ok('date value', $f && $f['type'] === 'date' && $f['value'] === '2026-06-11');

$f = field($def, 'start_time');
ok('time value (single submit key, HH:MM)', $f && $f['type'] === 'time' && $f['value'] === '14:30');

$f = field($def, 'token_field');
ok('hidden value round-trips', $f && $f['type'] === 'hidden' && $f['value'] === 'abc123');

ok('definition is JSON-encodable', json_encode($def) !== false);

// ── Datetime compound submit contract ─────────────────────────────────────

$f = field($def, 'evt_start');
ok('datetime prefill split into date/hour/minute/ampm',
      $f && $f['date_value'] === '2026-06-11' && $f['hour'] === '02' && $f['minute'] === '30' && $f['ampm'] === 'PM');
ok('datetime submit_parts use the process_datetimeinput key names',
      $f && $f['submit_parts'] === [
          'date' => 'evt_start_dateinput',
          'hour' => 'evt_start_timeinput_hour',
          'minute' => 'evt_start_timeinput_minute',
          'ampm' => 'evt_start_timeinput_ampm',
      ]);

// A body posted under the definition's submit_parts keys is accepted by
// process_datetimeinput() unchanged (to_utc=false: no session needed).
$post = [
    $f['submit_parts']['date'] => '2026-06-11',
    $f['submit_parts']['hour'] => '2',
    $f['submit_parts']['minute'] => '30',
    $f['submit_parts']['ampm'] => 'PM',
];
ok('submit_parts body accepted by process_datetimeinput()',
      FormWriterV2Base::process_datetimeinput($post, 'evt_start', false) === '2026-06-11 14:30:00');

// ── CSRF is off ────────────────────────────────────────────────────────────

ok('no CSRF field in definition', field($def, '_csrf_token') === null);
ok('validateCSRF passes with no token (CSRF disabled)', $fw->validateCSRF([]) === true);

// ── edit_primary_key_value ─────────────────────────────────────────────────

$fw2 = new FormWriterV2JSON('edit_form', ['edit_primary_key_value' => 123]);
$fw2->textinput('name', 'Name');
$fw2->submitbutton('btn_submit', 'Save');
$def2 = $fw2->getDefinition();
ok('edit_primary_key_value emitted as first hidden field',
      $def2['fields'][0] === ['type' => 'hidden', 'name' => 'edit_primary_key_value', 'value' => '123']);

// ── set_values / set_model ─────────────────────────────────────────────────

$fw3 = new FormWriterV2JSON('values_form');
$fw3->set_values(['city' => 'Austin']);
$fw3->textinput('city', 'City');
ok('set_values prefills later fields', field($fw3->getDefinition(), 'city')['value'] === 'Austin');

$stub = new class {
    public function export_as_array() { return ['city' => 'Dallas']; }
};
$fw4 = new FormWriterV2JSON('model_form');
$fw4->set_model($stub);
$fw4->textinput('city', 'City');
ok('set_model prefills from export_as_array()', field($fw4->getDefinition(), 'city')['value'] === 'Dallas');

ok('set_model rejects objects without export_as_array()',
      thrown(function () { (new FormWriterV2JSON('bad'))->set_model(new stdClass()); }) !== null);

// ── Loud failure on unsupported constructs ─────────────────────────────────

ok('custom_script on dropinput throws',
      thrown(function () {
          $fw = new FormWriterV2JSON('f');
          $fw->dropinput('x', 'X', ['options' => ['a' => 'A'], 'custom_script' => 'alert(1);']);
      }) !== null);

ok('onchange on textinput throws',
      thrown(function () {
          $fw = new FormWriterV2JSON('f');
          $fw->textinput('x', 'X', ['onchange' => 'doThing()']);
      }) !== null);

ok('fileinput throws',
      thrown(function () { (new FormWriterV2JSON('f'))->fileinput('x', 'X'); }) !== null);
ok('imageinput throws',
      thrown(function () { (new FormWriterV2JSON('f'))->imageinput('x', 'X'); }) !== null);
ok('textbox (rich text) throws',
      thrown(function () { (new FormWriterV2JSON('f'))->textbox('x', 'X'); }) !== null);
ok('repeater throws',
      thrown(function () { (new FormWriterV2JSON('f'))->repeater('x', 'X'); }) !== null);
ok('second submit button throws',
      thrown(function () {
          $fw = new FormWriterV2JSON('f');
          $fw->submitbutton('btn_a', 'A');
          $fw->submitbutton('btn_b', 'B');
      }) !== null);
ok('web bot defences throw (keep them in web views)',
      thrown(function () { (new FormWriterV2JSON('f'))->honeypot_hidden_input(); }) !== null
      && thrown(function () { (new FormWriterV2JSON('f'))->captcha_hidden_input(); }) !== null
      && thrown(function () { (new FormWriterV2JSON('f'))->antispam_question_input(); }) !== null);

// ── Single source: account_edit builder through HTML5 and JSON ────────────

require_once(PathHelper::getThemeFilePath('account_edit_logic.php', 'logic'));

$html5 = new FormWriterV2HTML5('account_edit', ['deferred_output' => true]);
account_edit_logic_form($html5, null);

$json = new FormWriterV2JSON('account_edit');
account_edit_logic_form($json, null);

$fields_prop = new ReflectionProperty('FormWriterV2Base', 'fields');
$html5_names = array_keys($fields_prop->getValue($html5));
$json_names = array_keys($fields_prop->getValue($json));

ok('account_edit builder registers identical field names in identical order via HTML5 and JSON',
      $html5_names === $json_names && count($json_names) >= 3);

harness_finish();
