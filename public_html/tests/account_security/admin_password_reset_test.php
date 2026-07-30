<?php
/** @joinery-test
 * name: admin_password_reset
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The recovery path of last resort: resetting a password from the server itself.
 *
 * Every other way back into an account is remote — an email, a passkey, a code.
 * A day-one site holds none of them, and on most cloud providers outbound port
 * 25 is blocked at the account level, so a fresh install cannot even mail a
 * reset link to itself. `maintenance_scripts/sysadmin_tools/reset_admin_password.php`
 * is what stands between a forgotten password and hand-editing Postgres, and it
 * is also what `_site_init.sh` uses to give every fresh site its own admin
 * password instead of a shared default. Both of those make it load-bearing.
 *
 * The tool is exercised as a real subprocess against real accounts, because the
 * thing worth checking is not that the code runs but that a password typed into
 * it actually signs in afterwards.
 *
 * One property is asserted structurally rather than executed: that it refuses to
 * run under a web SAPI. There is no CGI binary here to run it under, so what is
 * checked instead is that no route can reach it — it lives outside the web root
 * and has no twin in utils/. The guard itself is pinned by installer_contract.
 *
 * Run: php tests/account_security/admin_password_reset_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('data/users_class.php'));

$tool = dirname(PathHelper::getRootDir()) . '/maintenance_scripts/sysadmin_tools/reset_admin_password.php';

/**
 * Run the tool with a password handed over in a file, the way callers are
 * expected to (an argument would be visible in ps and in shell history).
 * Returns [exit_code, combined_output].
 */
function apr_run($tool, array $args, $password = null) {
	$pw_file = null;
	if ($password !== null) {
		$pw_file = tempnam(sys_get_temp_dir(), 'apr');
		chmod($pw_file, 0600);
		file_put_contents($pw_file, $password . "\n");
		$args[] = '--password-file=' . $pw_file;
	}
	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool);
	foreach ($args as $arg) {
		$cmd .= ' ' . escapeshellarg($arg);
	}
	$out = array();
	$rc = 0;
	exec($cmd . ' 2>&1 < /dev/null', $out, $rc);
	if ($pw_file !== null) {
		@unlink($pw_file);
	}
	return array($rc, implode("\n", $out));
}

/** Re-read a user from the database so we are asserting on stored state. */
function apr_reload($user_id) {
	return new User($user_id, TRUE);
}


section('The tool is where the installer expects it');

check(is_file($tool), 'reset_admin_password.php exists', $tool);
check(strpos(realpath($tool), realpath(PathHelper::getRootDir())) !== 0,
	'it is outside the web root',
	'a password reset under /utils/ would be one forgotten permission check from an account takeover');
check(!file_exists(PathHelper::getIncludePath('utils/reset_admin_password.php')),
	'there is no web-routable copy of it');


section('A reset produces a password that works');

$target = make_user('ResetTarget', 10);
$target_email = $target->get('usr_email');
$new_password = 'HarnessReset_' . bin2hex(random_bytes(6));

list($rc, $out) = apr_run($tool, array('--email=' . $target_email, '--yes'), $new_password);
check($rc === 0, 'the tool exits cleanly', $out);

$reloaded = apr_reload($target->key);
check($reloaded->check_password($new_password), 'the new password authenticates');
check((bool)$reloaded->get('usr_force_password_change'),
	'the account is flagged to choose a new password at next sign-in',
	'what is typed here is a way in, not a permanent credential');


section('The account is chosen deliberately');

list($rc, $out) = apr_run($tool, array('--email=nobody-' . bin2hex(random_bytes(4)) . '@invalid.test', '--yes'), 'Whatever_123');
check($rc !== 0, 'an unknown address is refused');
check(stripos($out, 'no account found') !== false, 'and says so plainly', $out);

// A second superadmin makes "the sole permission-10 account" ambiguous. Guessing
// there would reset the wrong person's password, so it must refuse.
$second_admin = make_user('ResetOther', 10);
list($rc, $out) = apr_run($tool, array('--yes'), 'Whatever_123');
check($rc !== 0, 'with no --email and several superadmins, it refuses rather than guessing');
check(stripos($out, 'permission-10 accounts') !== false, 'and lists the candidates', $out);

$untouched = apr_reload($second_admin->key);
check($untouched->check_password('TestPassword_ResetOther'),
	'the ambiguous run changed nothing');


section('The password never becomes an argument');

list($rc, $out) = apr_run($tool, array('--email=' . $target_email, '--yes', 'SomePassword_123'));
check($rc !== 0, 'a positional password is rejected outright');
check(stripos($out, 'never passed on the command line') !== false,
	'and explains why', $out);


section('The second factor survives unless you say otherwise');

$factored = make_user('ResetFactored', 10);
$factored->set('usr_totp_secret', 'JBSWY3DPEHPK3PXP');
$factored->set('usr_totp_enabled_time', gmdate('Y-m-d H:i:s'));
$factored->set('usr_second_factor_hmac_key', bin2hex(random_bytes(64)));
$factored->save();
$hmac_before = apr_reload($factored->key)->get('usr_second_factor_hmac_key');

// Someone who lost a password may still hold their authenticator. Wiping it as
// a side effect of a routine reset would be a downgrade nobody asked for.
list($rc, $out) = apr_run($tool, array('--email=' . $factored->get('usr_email'), '--yes'), 'FirstReset_' . bin2hex(random_bytes(4)));
check($rc === 0, 'a plain reset succeeds on an account with TOTP', $out);

$after_plain = apr_reload($factored->key);
check($after_plain->get('usr_totp_secret') !== null && $after_plain->get('usr_totp_secret') !== '',
	'TOTP is still enrolled');
check($after_plain->get('usr_second_factor_hmac_key') === $hmac_before,
	'trusted devices stay trusted');

// The other half of the story: same phone, same laptop, both gone. Then the
// factor has to go too, or the reset does not actually restore access.
list($rc, $out) = apr_run($tool,
	array('--email=' . $factored->get('usr_email'), '--clear-second-factor', '--yes'),
	'SecondReset_' . bin2hex(random_bytes(4)));
check($rc === 0, '--clear-second-factor succeeds', $out);

$after_clear = apr_reload($factored->key);
check($after_clear->get('usr_totp_secret') === null || $after_clear->get('usr_totp_secret') === '',
	'TOTP is cleared');
check($after_clear->get('usr_totp_enabled_time') === null,
	'and no longer counts as enrolled');
check($after_clear->get('usr_second_factor_hmac_key') !== $hmac_before,
	'every trusted device is signed out of the skip-second-factor grant');

harness_finish();
