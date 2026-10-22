<?php
/** @joinery-test
 * name: joinery_validate_blur
 * tier: safe
 * parallel: true
 * env: any
 * needs: [node]
 */
/**
 * PURPOSE: Pins that an empty field nobody has typed in is not judged as it
 * loses focus.
 *
 * /verify-totp opens with its code field focused. Pressing "Use a passkey"
 * blurred the field, the blur check drew "This field is required" under it,
 * the button moved down between mousedown and mouseup, and the browser fired
 * no click: the first press did nothing (found in the final copy test's C4,
 * B29). Any autofocused required field beside another button had the same trap.
 *
 * What is pinned, by running assets/js/joinery-validate.js under node against
 * a small stand-in form (the field check itself is counted, not drawn):
 *   - a blur on an empty field nobody typed in checks nothing
 *   - a blur after typing checks the field, even when it was emptied again
 *   - a blur on a field that holds a value checks it, typed in or not
 *   - submit checks every field, the untyped empty one among them
 *
 * Run:  php tests/unit/joinery_validate_blur_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$validator_js = PathHelper::getIncludePath('assets/js/joinery-validate.js');

// A stand-in for the browser: one form whose fields keep their listeners, so
// the test can fire input and blur at them as a person would.
$driver = <<<'JS'
const fs = require('fs');
const vm = require('vm');
const src = fs.readFileSync(process.argv[2], 'utf8');

function makeField(name, value) {
	const listeners = {};
	return {
		name, value, type: 'text', tagName: 'INPUT',
		addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
		async fire(type) { for (const fn of listeners[type] || []) await fn({ target: this }); },
	};
}

function makeForm(fields) {
	const submitListeners = [];
	return {
		tagName: 'FORM', dataset: {}, elements: {}, onsubmit: null, attrs: {}, id: '', action: '',
		classList: { contains() { return false; }, add() {}, remove() {} },
		setAttribute(k, v) { this.attrs[k] = v; },
		getAttribute(k) { return this.attrs[k] ?? null; },
		hasAttribute(k) { return k in this.attrs; },
		removeAttribute(k) { delete this.attrs[k]; },
		addEventListener(type, fn) { if (type === 'submit') submitListeners.push(fn); },
		querySelector() { return null; },
		querySelectorAll(sel) {
			const m = /\[name="([^"]+)"\]/.exec(sel);
			return m && fields[m[1]] ? [fields[m[1]]] : [];
		},
		requestSubmit() {},
	};
}

const document = { readyState: 'complete', getElementById() { return null; },
	addEventListener() {}, createElement() { return { setAttribute() {}, appendChild() {} }; },
	querySelector() { return null; }, body: {} };
const window = {};
const context = { window, document, console: { log() {}, warn() {}, error() {} }, setTimeout, Set, Map };
vm.createContext(context);
vm.runInContext(src, context);
const JV = window.JoineryValidator;

(async () => {
	const fields = { totp_code: makeField('totp_code', ''), full_name: makeField('full_name', 'Ada') };
	const form = makeForm(fields);
	const v = new JV(form, { rules: { totp_code: { required: true }, full_name: { required: true } } });
	const checked = [];
	v.validateField = async (name) => { checked.push(name); return true; };
	const out = {};

	await fields.totp_code.fire('blur');
	out.untyped_empty = checked.splice(0);

	await fields.full_name.fire('blur');
	out.holds_value = checked.splice(0);

	fields.totp_code.value = '12';
	await fields.totp_code.fire('input');
	fields.totp_code.value = '';
	await fields.totp_code.fire('input');
	await fields.totp_code.fire('blur');
	out.typed_then_emptied = checked.splice(0);

	const fresh = { code: makeField('code', '') };
	const v2 = new JV(makeForm(fresh), { rules: { code: { required: true } } });
	const checked2 = [];
	v2.validateField = async (name) => { checked2.push(name); return true; };
	await v2.validateForm();
	out.submit = checked2;

	process.stdout.write(JSON.stringify(out));
})();
JS;

$dir = harness_scratch_dir('joinery_validate_blur');
file_put_contents("$dir/driver.js", $driver);
$raw = (string)shell_exec('node ' . escapeshellarg("$dir/driver.js") . ' ' . escapeshellarg($validator_js) . ' 2>&1');
$r = json_decode($raw, true);

section('A blur judges only what the person has touched');
check(is_array($r), 'The validator runs under node', $raw);
$r = is_array($r) ? $r : array();
check(($r['untyped_empty'] ?? null) === array(), 'An empty field nobody typed in is not checked as it loses focus', $raw);
check(($r['typed_then_emptied'] ?? null) === array('totp_code'), 'Typed in and emptied again, it is checked as it loses focus', $raw);
check(($r['holds_value'] ?? null) === array('full_name'), 'A field that holds a value is checked as it loses focus', $raw);

section('Submit still checks every field');
check(($r['submit'] ?? null) === array('code'), 'The untyped empty field is checked on submit', $raw);

harness_finish();
