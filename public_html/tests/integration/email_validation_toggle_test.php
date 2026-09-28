<?php
/**
 * Integration test: email_validation_mx_check toggle
 *
 * Verifies that both validation paths (IsValidEmail and model save) honor the
 * email_validation_mx_check setting, and that syntax validation is never skipped.
 *
 * Run: php tests/integration/email_validation_toggle_test.php
 */
/** @joinery-test
 * name: email_validation_toggle
 * tier: db
 * env: dev-only
 * needs: []
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('data/settings_class.php'));

$settings = Globalvars::get_instance();

section('email_validation_mx_check toggle');

// Save original setting value for teardown, and defer the restore NOW so a
// crash mid-test cannot strand the dev site with MX validation flipped. (The
// explicit restore at the end of the body remains for the happy path.)
$original = $settings->get_setting('email_validation_mx_check');
harness_defer(function () use ($original) {
	try {
		$s = new MultiSetting(['setting_name' => 'email_validation_mx_check']);
		$s->load();
		if ($s->count_all() > 0) { $row = $s->get(0); $row->set('stg_value', $original); $row->save(); }
	} catch (\Throwable $e) { /* best effort */ }
});

function set_mx_check($value) {
	global $settings;
	$s = new MultiSetting(['setting_name' => 'email_validation_mx_check']);
	$s->load();
	if ($s->count_all() > 0) {
		$row = $s->get(0);
		$row->set('stg_value', $value);
		$row->save();
		// Refresh the Globalvars in-memory settings cache so the next
		// get_setting() reflects the value we just persisted.
		harness_set_setting_mem('email_validation_mx_check', $value);
	}
}

// --- MX check ON (default behavior) ---
set_mx_check('1');

// Syntax-only invalid address always rejected
ok(
	'MX on: malformed address rejected by IsValidEmail',
	LibraryFunctions::IsValidEmail('not-an-email') === false
);

// Valid syntax but no-MX domain should be rejected
ok(
	'MX on: example.test rejected by IsValidEmail (no MX)',
	LibraryFunctions::IsValidEmail('someone@example.test') === false
);

// withoutEmailDomainCheck(): the DNS half is off for the call only, syntax stays.
// page_probe saves its throwaway viewer at `.invalid` inside it; without it a
// site with the check on refused every member and admin probe.
ok(
	'MX on: probe.invalid rejected by IsValidEmail outside the scope',
	LibraryFunctions::IsValidEmail('probe-abc@probe.invalid') === false
);
ok(
	'MX on: probe.invalid accepted inside withoutEmailDomainCheck',
	LibraryFunctions::withoutEmailDomainCheck(function () {
		return LibraryFunctions::IsValidEmail('probe-abc@probe.invalid');
	}) === true
);
ok(
	'MX on: malformed address still rejected inside withoutEmailDomainCheck',
	LibraryFunctions::withoutEmailDomainCheck(function () {
		return LibraryFunctions::IsValidEmail('not-an-email');
	}) === false
);
$scoped_prepare_error = null;
LibraryFunctions::withoutEmailDomainCheck(function () use (&$scoped_prepare_error) {
	$u = new User(NULL);
	$u->set('usr_email', 'probe-abc@probe.invalid');
	try {
		$u->prepare();
	} catch (DisplayableUserException $e) {
		$scoped_prepare_error = $e->getMessage();
	}
});
ok(
	'MX on: model prepare() accepts probe.invalid inside withoutEmailDomainCheck',
	$scoped_prepare_error === null
);
$thrown_out = false;
try {
	LibraryFunctions::withoutEmailDomainCheck(function () { throw new RuntimeException('x'); });
} catch (RuntimeException $e) {
	$thrown_out = true;
}
ok(
	'MX on: the check is back on after the scope, even when it threw',
	$thrown_out && LibraryFunctions::emailDomainCheckApplies() === true
		&& LibraryFunctions::IsValidEmail('probe-abc@probe.invalid') === false
);

// --- MX check OFF (syntax-only mode) ---
set_mx_check('0');

// Syntax-only invalid address still rejected even in syntax-only mode
ok(
	'MX off: malformed address still rejected by IsValidEmail',
	LibraryFunctions::IsValidEmail('not-an-email') === false
);

// Valid syntax on a no-MX domain should now be accepted
ok(
	'MX off: example.test accepted by IsValidEmail (syntax-only)',
	LibraryFunctions::IsValidEmail('someone@example.test') === true
);

// Model save path: a User with a no-MX address should pass model-layer email
// validation (User::prepare() runs IsValidEmail and throws on an invalid address).
require_once(PathHelper::getIncludePath('data/users_class.php'));
$u = new User(NULL);
$u->set('usr_email', 'testuser@example.test');
$email_error = false;
try {
	$u->prepare();
} catch (DisplayableUserException $e) {
	if (stripos($e->getMessage(), 'invalid') !== false) { $email_error = true; }
}
ok(
	'MX off: model prepare() accepts example.test address',
	!$email_error
);

// --- Teardown ---
set_mx_check($original);

harness_finish();
