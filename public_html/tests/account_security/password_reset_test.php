<?php
/** @joinery-test
 * name: account_password_reset
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Password reset and activation codes — the bearer tokens that hand out account
 * access without a password.
 *
 * A reset code IS the account for as long as it is valid, so the properties that
 * matter are the boring ones: it expires, it works once, it cannot be guessed,
 * it belongs to exactly one account, and consuming it kills it everywhere — not
 * merely on the one code path that remembered to check.
 *
 * That last property is the reason this suite exists. Validity is expressed in
 * three lookups: checkTempCode filters act_deleted, while getIdFromTempCode and
 * getTempCodeInfo resolve a code for callers that grant real access
 * (Activation::ActivateUser, reached straight from login_logic). Any lookup that
 * ignores act_deleted turns a spent code back into a live one for the remainder
 * of its lifetime, so the replay checks below exercise the resolvers directly
 * rather than trusting one flow's ordering to save the others.
 *
 * Sections: code issue properties; expiry; single-use across all three lookups;
 * ownership; the reset completion flow; and the credential-event consequences a
 * completed reset must trigger.
 *
 * Run: php tests/account_security/password_reset_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/logic.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('includes/Activation.php'));
require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));

if (session_id() === '') @session_start();

harness_set_setting_mem('email_dry_run', '1');
harness_set_setting_mem('register_active', '1');

// Reset flows are rate limited per IP; pin a documentation address so the suite
// never consumes a real client's budget.
$_SERVER['REMOTE_ADDR'] = '192.0.2.60';
harness_defer(function () {
	try {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("DELETE FROM rql_request_logs WHERE rql_ip_address LIKE '192.0.2.6%'");
		$q->execute();
	} catch (\Throwable $e) {
		echo "  WARNING: could not clean reset request logs: " . $e->getMessage() . "\n";
	}
});

/** Mint an email-verification code for $user and register it for teardown. */
function pr_code($user, $interval = '30 days') {
	$code = Activation::getTempCode($user->key, $interval, Activation::EMAIL_VERIFY);
	harness_defer(function () use ($code) {
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->prepare("DELETE FROM act_activation_codes WHERE act_code = ?");
			$q->execute(array(strtolower($code)));
		} catch (\Throwable $e) {
			echo "  WARNING: could not delete activation code: " . $e->getMessage() . "\n";
		}
	});
	return $code;
}

$user = make_user('ResetOwner');
$other = make_user('ResetOther');

// ---------------------------------------------------------------------------
section('Code issue properties');

$code = pr_code($user);

check(is_string($code) && $code !== '', 'a code is issued');
check(strlen($code) >= 12, 'the code is at least 12 characters',
	'length: ' . strlen($code));
check(preg_match('/^[A-Za-z0-9]+$/', $code) === 1,
	'the code is alphanumeric (URL-safe in an emailed link)',
	'code shape: ' . preg_replace('/[A-Za-z0-9]/', 'x', $code));

// Codes must not be predictable from each other: a run of issues has to be
// distinct, or one user's link narrows the search for another's.
$batch = array();
for ($i = 0; $i < 25; $i++) { $batch[] = strtolower(pr_code($user)); }
check(count(array_unique($batch)) === count($batch),
	'25 consecutively issued codes are all distinct',
	'unique: ' . count(array_unique($batch)) . '/' . count($batch));

check(Activation::getIdFromTempCode($code, Activation::EMAIL_VERIFY) == $user->key,
	'a fresh code resolves to the account it was issued for');
check(Activation::checkTempCode($code, Activation::EMAIL_VERIFY),
	'a fresh code passes the validity check');

// Codes are stored and compared lowercased, so a link mangled to upper case by
// a mail client still works.
check(Activation::checkTempCode(strtoupper($code), Activation::EMAIL_VERIFY),
	'code matching is case-insensitive');

// ---------------------------------------------------------------------------
section('Expiry');

$expired = pr_code($user, '30 days');
$db = DbConnector::get_instance()->get_db_link();
$q = $db->prepare("UPDATE act_activation_codes SET act_expires_time = NOW() - INTERVAL '1 minute'
	WHERE act_code = ?");
$q->execute(array(strtolower($expired)));

check(!Activation::checkTempCode($expired, Activation::EMAIL_VERIFY),
	'an expired code fails the validity check');
check(Activation::getIdFromTempCode($expired, Activation::EMAIL_VERIFY) === FALSE,
	'an expired code resolves to nothing');
check(Activation::getTempCodeInfo($expired, Activation::EMAIL_VERIFY) === FALSE,
	'an expired code returns no record');
check(Activation::ActivateUser($expired) === FALSE,
	'an expired code cannot activate an account');

// ---------------------------------------------------------------------------
section('Single use — every lookup, not just the guarded one');

$spent = pr_code($user);
check(Activation::checkTempCode($spent, Activation::EMAIL_VERIFY),
	'the code is valid before being consumed');

Activation::deleteTempCode($spent);

check(!Activation::checkTempCode($spent, Activation::EMAIL_VERIFY),
	'a consumed code fails the validity check');
check(Activation::getIdFromTempCode($spent, Activation::EMAIL_VERIFY) === FALSE,
	'a consumed code resolves to nothing (replay through getIdFromTempCode)',
	'returned: ' . var_export(Activation::getIdFromTempCode($spent, Activation::EMAIL_VERIFY), true));
check(Activation::getTempCodeInfo($spent, Activation::EMAIL_VERIFY) === FALSE,
	'a consumed code returns no record (replay through getTempCodeInfo)');

// The path that matters: ActivateUser resolves through getIdFromTempCode and is
// reached straight from login_logic with no prior checkTempCode. For an account
// with no password set, that flow SIGNS THE VISITOR IN. A spent code reaching it
// is account takeover from an old email, so this is the load-bearing check.
check(Activation::ActivateUser($spent) === FALSE,
	'a consumed code cannot activate an account (replay through ActivateUser)',
	'returned: ' . var_export(Activation::ActivateUser($spent), true));

// And through the front door it is reached by.
$passwordless = make_user('ResetPasswordless');
$passwordless->set('usr_password', '');
$passwordless->set('usr_is_activated', FALSE);
$passwordless->save();

$pw_code = pr_code($passwordless);
Activation::deleteTempCode($pw_code);

$_SESSION = array();
$res = harness_call_logic('logic/login_logic.php', 'login_logic',
	array('act_code' => $pw_code), 'POST');
check(empty($_SESSION['loggedin']) && empty($_SESSION['usr_user_id']),
	'a consumed activation code replayed at /login does not sign anyone in',
	'session user: ' . var_export($_SESSION['usr_user_id'] ?? null, true));
check($res->redirect !== '/password-set',
	'a consumed activation code does not reach the set-a-password screen',
	'redirect: ' . var_export($res->redirect, true));

// ---------------------------------------------------------------------------
section('Ownership');

$owned = pr_code($user);

check(Activation::getIdFromTempCode($owned, Activation::EMAIL_VERIFY) == $user->key,
	'a code resolves to its own account');
check(Activation::ActivateUser($owned, $other->key) === FALSE,
	'a code cannot be redeemed against a different account');
check(Activation::checkTempCode($owned, Activation::EMAIL_VERIFY),
	'the failed cross-account attempt leaves the code intact for its owner');

// Purpose is part of identity: an email-verification code must not act as a
// recovery or email-change code.
check(!Activation::checkTempCode($owned, Activation::EMAIL_CHANGE),
	'a code issued for one purpose does not validate for another');
check(Activation::getIdFromTempCode($owned, Activation::RECOVERY_VERIFY) === FALSE,
	'a code issued for one purpose does not resolve under another');

// A code nobody issued resolves to nobody.
check(Activation::getIdFromTempCode('nosuchcode123456', Activation::EMAIL_VERIFY) === FALSE,
	'an unissued code resolves to nothing');
check(!Activation::checkTempCode('', Activation::EMAIL_VERIFY),
	'an empty code is not valid');

// ---------------------------------------------------------------------------
section('Reset completion');

$resetter = make_user('ResetComplete');
$resetter->set('usr_terms_accepted_time', gmdate('Y-m-d H:i:s'));
$resetter->save();
$old_hash = $resetter->get('usr_password');

$reset_code = pr_code($resetter);
$new_password = 'BrandNew_Passw0rd';

$res = harness_call_logic('logic/password_reset_2_logic.php', 'password_reset_2_logic', array(
	'act_code'           => $reset_code,
	'usr_password'       => $new_password,
	'usr_password_again' => $new_password,
), 'POST');

check($res->error === null, 'the reset completes',
	'error: ' . var_export($res->error, true));

$resetter->load();
check($resetter->check_password($new_password), 'the new password verifies');
check((string)$resetter->get('usr_password') !== (string)$old_hash,
	'the stored hash changed');
check(!$resetter->check_password('TestPassword_ResetComplete'),
	'the previous password no longer works');

// Single use, through the flow rather than the resolver.
check(!Activation::checkTempCode($reset_code, Activation::EMAIL_VERIFY),
	'completing a reset consumes the code');

$res = harness_call_logic('logic/password_reset_2_logic.php', 'password_reset_2_logic', array(
	'act_code'           => $reset_code,
	'usr_password'       => 'SecondTry_Passw0rd',
	'usr_password_again' => 'SecondTry_Passw0rd',
), 'POST');
check($res->error !== null, 'the same reset code cannot be used twice',
	'error: ' . var_export($res->error, true));
$resetter->load();
check($resetter->check_password($new_password),
	'the replayed attempt did not change the password again');

// Mismatched confirmation must not touch the account.
$mismatch_code = pr_code($resetter);
$res = harness_call_logic('logic/password_reset_2_logic.php', 'password_reset_2_logic', array(
	'act_code'           => $mismatch_code,
	'usr_password'       => 'Mismatch_Passw0rd_A',
	'usr_password_again' => 'Mismatch_Passw0rd_B',
), 'POST');
check($res->error !== null && stripos((string)$res->error, 'did not match') !== false,
	'mismatched password fields are refused',
	'error: ' . var_export($res->error, true));
$resetter->load();
check($resetter->check_password($new_password),
	'a refused reset leaves the existing password in place');
check(Activation::checkTempCode($mismatch_code, Activation::EMAIL_VERIFY),
	'a refused reset does not burn the code');

// An account with recovery disabled cannot be reset by this path at all.
$disabled = make_user('ResetDisabled');
$disabled->set('usr_password_recovery_disabled', TRUE);
$disabled->set('usr_terms_accepted_time', gmdate('Y-m-d H:i:s'));
$disabled->save();
$disabled_code = pr_code($disabled);
$res = harness_call_logic('logic/password_reset_2_logic.php', 'password_reset_2_logic', array(
	'act_code'           => $disabled_code,
	'usr_password'       => 'Blocked_Passw0rd',
	'usr_password_again' => 'Blocked_Passw0rd',
), 'POST');
check($res->error !== null, 'an account with recovery disabled cannot be reset',
	'error: ' . var_export($res->error, true));
$disabled->load();
check(!$disabled->check_password('Blocked_Passw0rd'),
	'the recovery-disabled account keeps its password');

// ---------------------------------------------------------------------------
section('Reset request throttling');

// password_reset_1 counts ALL attempts, not just failures, so a flood of reset
// emails at one address is capped whether or not the addresses exist.
$_SERVER['REMOTE_ADDR'] = '192.0.2.61';
check(RequestLogger::check_rate_limit('password_reset', 5, 900, null),
	'a fresh address starts inside the reset-request budget');

for ($i = 0; $i < 5; $i++) {
	harness_call_logic('logic/password_reset_1_logic.php', 'password_reset_1_logic',
		array('usr_email' => $user->get('usr_email')), 'POST');
}
check(!RequestLogger::check_rate_limit('password_reset', 5, 900, null),
	'five reset requests exhaust the budget for that address');

$res = harness_call_logic('logic/password_reset_1_logic.php', 'password_reset_1_logic',
	array('usr_email' => $user->get('usr_email')), 'POST');
check($res->error !== null,
	'a sixth reset request from the same address is refused',
	'error: ' . var_export($res->error, true));

harness_finish();
