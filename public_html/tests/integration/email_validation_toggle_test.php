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

// Save original setting value for teardown
$original = $settings->get_setting('email_validation_mx_check');

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
