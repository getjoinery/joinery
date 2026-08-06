<?php
/** @joinery-test
 * name: formwriter_error_summary
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * PURPOSE: Every FormWriter form carries a .jy-error-summary container so a
 * failed validation is never silent (the jeremytunnell settings page could
 * not be saved for weeks because the one invalid field was off screen).
 *
 * Pins: the container is emitted exactly once, immediately before the FIRST
 * submit button; it exists even on forms with no validation rules (server
 * errors can still occur); a form with no submit button still gets one at
 * the end; FormWriterV2JSON emits none; setErrors() renders it unhidden with
 * one linked item per failing field, each pointing at that field's resolved
 * target id; the error_summary => false option suppresses it.
 *
 * Runs offline in under a second — no network, no DB writes.
 *
 * Run:  php tests/unit/formwriter_error_summary_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));
require_once(PathHelper::getIncludePath('includes/FormWriterV2JSON.php'));

// FormWriter construction starts a session; do it before the harness prints.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** Render a whole form and return the HTML. */
function render_form(callable $build, $options = []) {
    $fw = new FormWriterV2HTML5('summary_test_form', array_merge(['csrf' => false], $options));
    ob_start();
    $fw->begin_form();
    $build($fw);
    $fw->end_form();
    return ob_get_clean();
}

section('Container emission and placement');

$html = render_form(function ($fw) {
    $fw->textinput('usr_email', 'Email', ['required' => true]);
    $fw->submitbutton('btn_submit', 'Save');
    $fw->submitbutton('btn_delete', 'Delete');
});

check(substr_count($html, 'class="jy-error-summary"') === 1,
    'container is emitted exactly once on a two-button form');
check(strpos($html, 'id="summary_test_form_error_summary"') !== false,
    'container id derives from the form id');
$summary_pos = strpos($html, 'class="jy-error-summary"');
$first_submit = strpos($html, '<button type="submit"');
$second_submit = strpos($html, '<button type="submit"', $first_submit + 1);
check($summary_pos !== false && $first_submit !== false && $summary_pos < $first_submit,
    'container sits before the first submit button');
check($second_submit !== false && strpos($html, 'class="jy-error-summary"', $first_submit) === false,
    'the second submit button gets no second container');
check(preg_match('/class="jy-error-summary"[^>]*\bhidden\b/', $html) === 1,
    'container ships hidden when there are no errors');
check(strpos($html, 'role="alert"') !== false && strpos($html, 'tabindex="-1"') !== false,
    'container is announceable and focusable');
check(strpos($html, 'errorSummary: true') !== false,
    'validator init enables the client summary');

$html = render_form(function ($fw) {
    $fw->textinput('plain_field', 'Plain');
    $fw->submitbutton('btn_submit', 'Go');
});
check(substr_count($html, 'class="jy-error-summary"') === 1,
    'a form with no validation rules still gets the container');

$html = render_form(function ($fw) {
    $fw->textinput('lonely_field', 'Lonely');
});
check(substr_count($html, 'class="jy-error-summary"') === 1,
    'a form with no submit button still gets exactly one container');
check(strpos($html, '</form>') > strpos($html, 'class="jy-error-summary"'),
    'buttonless form: container lands inside the form');

section('Server-side fill from setErrors()');

$fw = new FormWriterV2HTML5('summary_test_form', ['csrf' => false]);
$fw->setErrors([
    'link_to_logo' => ['Must start with / and file must exist'],
    'usr_email' => ['Enter a valid email address', 'Required'],
    'size' => ['Pick one'],
]);
ob_start();
$fw->begin_form();
$fw->textinput('link_to_logo', 'Link to Logo');
$fw->textinput('usr_email', 'Email', ['id' => 'custom_email_id']);
$fw->radioinput('size', 'Size', ['options' => ['s' => 'Small', 'l' => 'Large']]);
$fw->submitbutton('btn_submit', 'Save');
$fw->end_form();
$html = ob_get_clean();

check(preg_match('/class="jy-error-summary"[^>]*\bhidden\b/', $html) === 0,
    'container renders unhidden when errors exist');
check(strpos($html, '3 fields need attention:') !== false,
    'title carries the failing-field count');
check(strpos($html, '<a href="#link_to_logo" data-field="link_to_logo">Link to Logo</a>') !== false,
    'item links the field id and shows the human label');
check(strpos($html, 'Must start with / and file must exist') !== false,
    'item carries the same message shown inline');
check(strpos($html, '<a href="#custom_email_id"') !== false,
    'an explicit field id wins as the link target');
check(strpos($html, 'Enter a valid email address; Required') !== false,
    'multiple messages for one field join into one item');
check(strpos($html, '<a href="#size_container"') !== false,
    'a radio group links its container, not any single input');

$fw = new FormWriterV2HTML5('summary_test_form', ['csrf' => false]);
$fw->setErrors(['only_field' => ['Broken']]);
ob_start();
$fw->begin_form();
$fw->textinput('only_field', 'Only Field');
$fw->submitbutton('btn_submit', 'Save');
$fw->end_form();
$html = ob_get_clean();
check(strpos($html, '1 field needs attention:') !== false,
    'singular title for a single failing field');

$fw = new FormWriterV2HTML5('summary_test_form', [
    'csrf' => false,
    'error_summary_title' => 'Fix {n} things:',
]);
$fw->setErrors(['a_field' => ['Bad'], 'b_field' => ['Bad']]);
ob_start();
$fw->begin_form();
$fw->textinput('a_field', 'A');
$fw->textinput('b_field', 'B');
$fw->submitbutton('btn_submit', 'Save');
$fw->end_form();
$html = ob_get_clean();
check(strpos($html, 'Fix 2 things:') !== false,
    'error_summary_title override with {n} placeholder is honoured');

section('Opt-outs');

$html = render_form(function ($fw) {
    $fw->textinput('usr_email', 'Email', ['required' => true]);
    $fw->submitbutton('btn_submit', 'Save');
}, ['error_summary' => false]);
check(strpos($html, 'jy-error-summary') === false,
    'error_summary => false emits no container');
check(strpos($html, 'errorSummary: false') !== false,
    'error_summary => false also disables the client summary');

$fw = new FormWriterV2JSON('summary_test_form');
ob_start();
$fw->begin_form();
$fw->textinput('usr_email', 'Email', ['required' => true]);
$fw->submitbutton('btn_submit', 'Save');
$fw->end_form();
$json_output = ob_get_clean();
$def = $fw->getDefinition();
check($json_output === '',
    'FormWriterV2JSON emits no summary markup');
check(strpos(json_encode($def), 'jy-error-summary') === false,
    'the JSON definition carries no summary artifacts');

harness_finish();
