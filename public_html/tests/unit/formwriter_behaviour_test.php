<?php
/** @joinery-test
 * name: formwriter_behaviour
 * tier: safe
 * parallel: true
 * env: dev-only
 * needs: [chrome]
 * covers: [public_html/assets/js/base.js, public_html/assets/js/joinery-validate.js, public_html/assets/js/joinery-forms.js, public_html/assets/js/joinery-image-selector.js, public_html/assets/js/joinery-file-upload.js]
 * timeout: 120
 */
/**
 * PURPOSE: What FormWriter's scripted fields do in a browser, pinned before
 * their scripts move out of the page (specs/strict_script_policy.md WP3).
 *
 * This renders one real form through FormWriterV2HTML5 with every field that
 * carries script, writes it into a page beside tests/fixtures/formwriter_behaviour/driver.js,
 * opens that page in headless Chrome and reads back one <li data-result> per
 * check. Pinned:
 *   - validation: a bad email and a short name are refused with the field
 *     marked, nothing is submitted; fixed, the form goes to its submitHandler;
 *   - visibility_rules: a select, a checkbox, a radio group and a radio
 *     checkboxList each show and hide their targets, on load and on change;
 *   - a repeater adds and removes rows within its min and max, numbering
 *     the new row's field names;
 *   - a time input fills its hidden field in 24-hour form;
 *   - an AJAX select searches a plain endpoint (GET ?q=) and an /api/v1/
 *     endpoint (joineryApi.post), lists what comes back, and picking an
 *     entry sets the select;
 *   - a submit button with 'confirm' checks the fields, asks with its label,
 *     and only on yes submits as itself (its name and value posted);
 *   - navigate_on_change does not leave the site.
 *
 * The page loads the scripts in the order real pages do: base.js in the head
 * (not deferred), joinery-validate.js from the footer.
 *
 * The page loads the site's own assets from the tree (file:// paths), and
 * stubs fetch() and joineryApi, so nothing reaches a server.
 *
 * Run: php tests/run.php --only=tests/unit/formwriter_behaviour_test.php
 *
 * @version 1.3 - covers the JS files Chrome loads, so a change to them runs this suite
 * @version 1.2 - a confirm button on a validated form with no submitHandler posts its name and value; a refused press drops them (reviewer2 B15)
 * @version 1.1 - the real load order; the confirm button and navigate_on_change (reviewer2 B5)
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$root = rtrim(PathHelper::getIncludePath(''), '/');
$driver = realpath(__DIR__ . '/../fixtures/formwriter_behaviour/driver.js');
check($driver !== false && is_file($driver), 'driver.js is present', (string)$driver);

$chrome = trim((string)shell_exec('command -v google-chrome 2>/dev/null'));
if ($chrome === '') {
	check(false, 'google-chrome is on PATH (declare/enforce needs:[chrome])');
	harness_finish();
}

// ── The form ────────────────────────────────────────────────────────────────
$fw = new FormWriterV2HTML5('fwb_form', array('action' => '/fwb-nowhere', 'csrf' => false));
ob_start();
$fw->begin_form();
$fw->textinput('fwb_email', 'Email', array('validation' => array('required' => true, 'email' => true)));
$fw->textinput('fwb_name', 'Name', array('validation' => array('required' => true, 'minlength' => 3)));

$fw->dropinput('fwb_mode', 'Mode', array(
	'options' => array('a' => 'A', 'b' => 'B'),
	'value' => 'a',
	'visibility_rules' => array('a' => array('show' => array('fwb_sel_a'), 'hide' => array('fwb_sel_b')),
		'b' => array('show' => array('fwb_sel_b'), 'hide' => array('fwb_sel_a'))),
));
$fw->textinput('fwb_sel_a', 'Shown for A');
$fw->textinput('fwb_sel_b', 'Shown for B');

$fw->checkboxinput('fwb_flag', 'Flag', array(
	'visibility_rules' => array('checked' => array('show' => array('fwb_chk_target')), 'unchecked' => array('hide' => array('fwb_chk_target'))),
));
$fw->textinput('fwb_chk_target', 'Shown when flagged');

$fw->radioinput('fwb_kind', 'Kind', array(
	'options' => array('p' => 'P', 'q' => 'Q'),
	'value' => 'p',
	'visibility_rules' => array('p' => array('hide' => array('fwb_radio_target')), 'q' => array('show' => array('fwb_radio_target'))),
));
$fw->textinput('fwb_radio_target', 'Shown for Q');

$fw->checkboxList('fwb_plan', 'Plan', array(
	'type' => 'radio',
	'options' => array('m' => 'Monthly', 'y' => 'Yearly'),
	'checked' => array('m'),
	'visibility_rules' => array('m' => array('hide' => array('fwb_list_target')), 'y' => array('show' => array('fwb_list_target'))),
));
$fw->textinput('fwb_list_target', 'Shown for yearly');

$fw->repeater('fwb_rows', 'Rows', array(
	'value' => array(array('title' => 'First')),
	'fields' => array(array('name' => 'title', 'label' => 'Title', 'type' => 'textinput')),
	'min' => 1,
	'max' => 3,
));

$fw->timeinput('fwb_time', 'Time', array('value' => '09:15'));

$fw->dropinput('fwb_plain', 'Plain search', array('options' => array(), 'ajaxendpoint' => '/ajax/fwb_stub_search?type=u'));
$fw->dropinput('fwb_api', 'API search', array('options' => array(), 'ajaxendpoint' => '/api/v1/action/fwb_stub_search'));

$fw->submitbutton('fwb_submit', 'Send');
$fw->end_form();

// A second form: a button that asks first, and a select that navigates.
$fw2 = new FormWriterV2HTML5('fwb_form2', array('action' => '/fwb-nowhere-2', 'csrf' => false));
$fw2->begin_form();
$fw2->textinput('fwb_need', 'Needed', array('required' => true));
$fw2->dropinput('fwb_go', 'Go to', array('options' => array('' => '-', '//evil.example/' => 'Off site'), 'navigate_on_change' => true));
$fw2->submitbutton('fwb_ask', 'Do it', array('confirm' => 'Really do it?', 'value' => 'yes', 'confirm_label' => 'Go', 'confirm_style' => 'warning'));
$fw2->end_form();

// A third: a confirm button on a form with validation rules and no
// submitHandler, so the validator's own re-submit is the path tested
// (reviewer2 B15). The email rule is one the browser's own check does not
// make on a text field, so only the validator can refuse it.
$fw3 = new FormWriterV2HTML5('fwb_form3', array('action' => '/fwb-nowhere-3', 'csrf' => false));
$fw3->begin_form();
$fw3->textinput('fwb_mail3', 'Email', array('validation' => array('required' => true, 'email' => true)));
$fw3->submitbutton('fwb_ask3', 'Do it', array('confirm' => 'Sure?', 'value' => 'yes'));
$fw3->end_form();
$form_html = (string)ob_get_clean();

check(strpos($form_html, '<form') !== false && strpos($form_html, 'fwb_api') !== false, 'the form rendered', strlen($form_html) . ' bytes');

// Site-absolute asset paths point into the tree, so the page loads the real files.
$form_html = preg_replace('#(src|href)="/(assets|plugins|theme)/#', '$1="file://' . $root . '/$2/', $form_html);

$asset = function ($rel) use ($root) {
	return '<script src="file://' . htmlspecialchars($root . '/' . $rel) . '"></script>';
};
$page = "<!doctype html>\n<html><head><meta charset=\"utf-8\"><title>FormWriter behaviour</title>\n"
	// The stubs go first: nothing may reach a server, and the AJAX select's
	// requests are recorded for the driver to read.
	. "<script src=\"file://" . htmlspecialchars(dirname($driver)) . "/stubs.js\"></script>\n"
	// As real pages load them: base.js in the head, not deferred, the validator
	// from the footer (reviewer2 B5), so the order the field scripts rely on
	// is the one tested.
	. $asset('assets/js/base.js') . "\n"
	. "</head><body>\n<ul id=\"results\"></ul>\n"
	. $form_html
	. "\n" . $asset('assets/js/joinery-validate.js')
	. "\n<script src=\"file://" . htmlspecialchars($driver) . "\"></script>\n</body></html>\n";

$dir = harness_scratch_dir('formwriter_behaviour');
$runner = $dir . '/runner.html';
file_put_contents($runner, $page);

section('Headless Chrome runs the fixtures to completion');
$cmd = 'timeout 90 ' . escapeshellarg($chrome)
	. ' --headless=new --disable-gpu --no-sandbox --disable-dev-shm-usage --allow-file-access-from-files'
	. ' --virtual-time-budget=20000 --dump-dom ' . escapeshellarg('file://' . $runner) . ' 2>/dev/null';
$dom = (string)shell_exec($cmd);
check($dom !== '', 'Chrome returned a DOM', strlen($dom) . ' bytes');
check(strpos($dom, 'data-done="1"') !== false, 'the driver ran to its last line',
	'a missing marker means it threw before reporting; the page is at ' . $runner);

section('Every browser-side check passed');
$rows = 0;
if (preg_match_all('/<li data-result="(pass|fail)" data-name="([^"]*)">(.*?)<\/li>/s', $dom, $m, PREG_SET_ORDER)) {
	foreach ($m as $row) {
		$rows++;
		check($row[1] === 'pass', html_entity_decode($row[2], ENT_QUOTES | ENT_HTML5), html_entity_decode(strip_tags($row[3]), ENT_QUOTES | ENT_HTML5));
	}
}
check($rows >= 36, 'the full set of checks ran, not a page that half-loaded', $rows . ' rows');

harness_finish();
