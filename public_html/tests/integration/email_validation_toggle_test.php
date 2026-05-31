<?php
/**
 * Integration test: email_validation_mx_check toggle
 *
 * Verifies that both validation paths (IsValidEmail and model save) honor the
 * email_validation_mx_check setting, and that syntax validation is never skipped.
 *
 * Run: php tests/integration/email_validation_toggle_test.php
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/settings_class.php'));

$settings = Globalvars::get_instance();
$pass = 0;
$fail = 0;

function assert_true($label, $value) {
	global $pass, $fail;
	if ($value) {
		echo "PASS: $label\n";
		$pass++;
	} else {
		echo "FAIL: $label\n";
		$fail++;
	}
}

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
		$settings->reload();
	}
}

// --- MX check ON (default behavior) ---
set_mx_check('1');

// Syntax-only invalid address always rejected
assert_true(
	'MX on: malformed address rejected by IsValidEmail',
	LibraryFunctions::IsValidEmail('not-an-email') === false
);

// Valid syntax but no-MX domain should be rejected
assert_true(
	'MX on: example.test rejected by IsValidEmail (no MX)',
	LibraryFunctions::IsValidEmail('someone@example.test') === false
);

// --- MX check OFF (syntax-only mode) ---
set_mx_check('0');
$settings->reload();

// Syntax-only invalid address still rejected even in syntax-only mode
assert_true(
	'MX off: malformed address still rejected by IsValidEmail',
	LibraryFunctions::IsValidEmail('not-an-email') === false
);

// Valid syntax on a no-MX domain should now be accepted
assert_true(
	'MX off: example.test accepted by IsValidEmail (syntax-only)',
	LibraryFunctions::IsValidEmail('someone@example.test') === true
);

// Model save path: a User with a no-MX address should save without error
require_once(PathHelper::getIncludePath('data/users_class.php'));
$u = new User(NULL);
$u->set('usr_email', 'testuser@example.test');
$errors = $u->validate_fields();
assert_true(
	'MX off: model validateField accepts example.test address',
	!isset($errors['usr_email'])
);

// --- Teardown ---
set_mx_check($original);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
