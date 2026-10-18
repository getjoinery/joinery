<?php
/** @joinery-test
 * name: joinery_validate_one_per_form
 * tier: safe
 * parallel: true
 * env: any
 * needs: [node]
 */
/**
 * PURPOSE: Pins that a form set up twice for browser validation is still sent.
 *
 * A valid form is re-sent natively with a one-shot flag on the form, so other
 * submit listeners see the validated submission. Two validators on one form
 * each listened: the first consumed the flag, the second cancelled the
 * re-send and set it again, forever, and the form was never sent. Every
 * product page that asks the buyer something set itself up twice (FormWriter's
 * script and Product::output_javascript()), so Add to Cart did nothing.
 *
 * What is pinned, by running assets/js/joinery-validate.js under node against
 * a small stand-in form:
 *   - a second set-up of the same form returns the first validator
 *   - the second set-up's rules and messages join the first's
 *   - one click on a form set up twice sends it exactly once
 *   - a form set up once is sent once (the plain path is unchanged)
 *
 * Run:  php tests/unit/joinery_validate_one_per_form_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$validator_js = PathHelper::getIncludePath('assets/js/joinery-validate.js');

// A stand-in for the browser: one form, its submit listeners, and a native
// submission that fires them in order and is "sent" unless one cancels it.
$driver = <<<'JS'
const fs = require('fs');
const vm = require('vm');
const src = fs.readFileSync(process.argv[2], 'utf8');

function makeForm() {
	const listeners = [];
	const form = {
		tagName: 'FORM', dataset: {}, elements: {}, onsubmit: null, attrs: {}, id: '', action: '',
		classList: { contains() { return false; }, add() {}, remove() {} },
		sent: 0, dispatches: 0,
		setAttribute(k, v) { this.attrs[k] = v; },
		getAttribute(k) { return this.attrs[k] ?? null; },
		hasAttribute(k) { return k in this.attrs; },
		removeAttribute(k) { delete this.attrs[k]; },
		addEventListener(type, fn) { if (type === 'submit') listeners.push(fn); },
		querySelector() { return null; },
		querySelectorAll() { return []; },
		requestSubmit(submitter) { this.fire(submitter); },
		fire(submitter) {
			this.dispatches++;
			if (this.dispatches > 50) return;
			const e = { submitter, defaultPrevented: false,
				preventDefault() { this.defaultPrevented = true; }, stopPropagation() {} };
			for (const fn of listeners) fn(e);
			if (!e.defaultPrevented) this.sent++;
		},
	};
	return form;
}

const document = { readyState: 'complete', getElementById() { return null; },
	addEventListener() {}, createElement() { return { setAttribute() {}, appendChild() {} }; },
	querySelector() { return null; }, body: {} };
const window = {};
const context = { window, document, console: { log() {}, warn() {}, error() {} }, setTimeout, Set, Map };
vm.createContext(context);
vm.runInContext(src, context);
const JV = window.JoineryValidator;

const out = {};

const twice = makeForm();
const a = new JV(twice, { rules: { full_name_first: { required: true } }, messages: { full_name_first: { required: 'First' } } });
const b = new JV(twice, { rules: { email: { required: true } }, messages: { email: { required: 'Email' } } });
out.same_instance = (a === b);
out.rules = Object.keys(a.rules).sort();
out.messages = Object.keys(a.messages).sort();

const once = makeForm();
new JV(once, { rules: { email: { required: true } } });

twice.fire(undefined);
once.fire(undefined);
setTimeout(() => {
	out.twice_sent = twice.sent;
	out.twice_dispatches = twice.dispatches;
	out.once_sent = once.sent;
	process.stdout.write(JSON.stringify(out));
}, 300);
JS;

$dir = harness_scratch_dir('joinery_validate');
file_put_contents("$dir/driver.js", $driver);
$raw = (string)shell_exec('node ' . escapeshellarg("$dir/driver.js") . ' ' . escapeshellarg($validator_js) . ' 2>&1');
$r = json_decode($raw, true);

section('A form set up twice has one validator');
check(is_array($r), 'The validator runs under node', $raw);
$r = is_array($r) ? $r : array();
check(($r['same_instance'] ?? false) === true, 'The second set-up returns the first validator', $raw);
check(($r['rules'] ?? null) === array('email', 'full_name_first'), 'Both set-ups\' rules are checked', $raw);
check(($r['messages'] ?? null) === array('email', 'full_name_first'), 'Both set-ups\' messages are kept', $raw);

section('A valid form is sent once');
check(($r['twice_sent'] ?? null) === 1 && ($r['twice_dispatches'] ?? 99) === 2,
	'A form set up twice: one click, one re-send, sent once', $raw);
check(($r['once_sent'] ?? null) === 1, 'A form set up once: sent once', $raw);

harness_finish();
