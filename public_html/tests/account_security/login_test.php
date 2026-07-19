<?php
/** @joinery-test
 * name: account_login
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Sign-in lifecycle — password verification and every gate standing between a
 * correct password and a session.
 *
 * login_logic refuses in five distinct ways, each a single branch no other test
 * covers: the per-IP failure throttle, missing credentials, a bad email or
 * password, an IP outside the account's allowlist, and an unactivated account
 * when the site demands activation. Past all of those, an account holding a
 * second factor is diverted to /verify-totp with a pending state rather than
 * being signed in.
 *
 * Throttle isolation: RequestLogger keys the counter on $_SERVER['REMOTE_ADDR'],
 * which the CLI leaves unset. Each throttle section pins a distinct synthetic
 * address from TEST-NET-1 (192.0.2.0/24, reserved for documentation), so the
 * suite can never consume — or be blocked by — a real client's budget, and the
 * rows it writes are unambiguously its own at teardown.
 *
 * Worth naming because the tests below pin it: throttling is per-IP and
 * per-feature ONLY. There is no per-account failure counter and no lockout
 * column anywhere on usr_users. An attacker rotating source addresses is not
 * slowed at all, and conversely every user behind one NAT shares a single
 * budget. That is the current design, so it is what these checks assert; the
 * gap is recorded in specs/test_estate_audit.md rather than failed here.
 *
 * Sections: credentials; the per-IP throttle; the IP allowlist; the activation
 * gate; and the second-factor divert.
 *
 * Run: php tests/account_security/login_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/logic.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));

if (session_id() === '') @session_start();

/** Pin the source address every later RequestLogger call is keyed on. */
function login_set_ip($ip) {
	$_SERVER['REMOTE_ADDR'] = $ip;
}

/** Drop every request-log row this suite wrote for $ip. */
function login_cleanup_ip($ip) {
	harness_defer(function () use ($ip) {
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->prepare("DELETE FROM rql_request_logs WHERE rql_ip_address = ?");
			$q->execute(array($ip));
		} catch (\Throwable $e) {
			echo "  WARNING: could not clean request logs for $ip: " . $e->getMessage() . "\n";
		}
	});
}

/** Call login_logic from a signed-out session. */
function login_call(array $input) {
	$_SESSION = array();
	return harness_call_logic('logic/login_logic.php', 'login_logic', $input, 'POST');
}

/** The redirect login_logic issues for a rejected credential. */
function login_is_retry($res) {
	return $res->redirect !== null && strpos((string)$res->redirect, '/login?retry=1') === 0;
}

// The site requires activation before sign-in; the activation gate section turns
// it back on deliberately. Everywhere else it would mask the branch under test.
harness_set_setting_mem('email_dry_run', '1');
harness_set_setting_mem('activation_required_login', '0');

$password = 'TestPassword_LoginOk';
$user = make_user('LoginOk');
$email = $user->get('usr_email');

// ---------------------------------------------------------------------------
section('Credentials');

login_set_ip('192.0.2.10');
login_cleanup_ip('192.0.2.10');

$res = login_call(array('email' => $email, 'password' => $password));
check($res->error === null && !login_is_retry($res) && $res->redirect !== null,
	'a correct email and password signs in',
	'redirect: ' . var_export($res->redirect, true) . ' error: ' . var_export($res->error, true));

$res = login_call(array('email' => $email, 'password' => $password . 'X'));
check(login_is_retry($res), 'a wrong password is refused',
	'redirect: ' . var_export($res->redirect, true));

$res = login_call(array('email' => 'nobody_' . bin2hex(random_bytes(4)) . '@example.com', 'password' => $password));
check(login_is_retry($res), 'an unknown email is refused',
	'redirect: ' . var_export($res->redirect, true));

// The refusal must not distinguish the two cases, or it becomes an account
// enumeration oracle: "no such user" vs "wrong password" tells an attacker
// which addresses are registered.
$bad_pass = login_call(array('email' => $email, 'password' => $password . 'X'));
$bad_user = login_call(array('email' => 'nobody_' . bin2hex(random_bytes(4)) . '@example.com', 'password' => $password));
$strip = function ($url) { return preg_replace('/&e=[^&]*/', '&e=REDACTED', (string)$url); };
check($strip($bad_pass->redirect) === $strip($bad_user->redirect),
	'a wrong password and an unknown email are refused identically (no enumeration oracle)',
	'password: ' . $strip($bad_pass->redirect) . ' / unknown: ' . $strip($bad_user->redirect));

$res = login_call(array('email' => $email));
check($res->redirect === '/login?retry=1', 'a missing password is refused',
	'redirect: ' . var_export($res->redirect, true));

$res = login_call(array('password' => $password));
check($res->redirect === '/login?retry=1', 'a missing email is refused',
	'redirect: ' . var_export($res->redirect, true));

// The form posts either bare or lbx_-prefixed names.
$res = login_call(array('lbx_email' => $email, 'lbx_password' => $password));
check($res->error === null && !login_is_retry($res),
	'lbx_-prefixed credential fields are accepted',
	'redirect: ' . var_export($res->redirect, true));

// ---------------------------------------------------------------------------
section('Per-IP failure throttle');

// A fresh address, so the budget starts full regardless of run order.
$throttle_ip = '192.0.2.20';
login_set_ip($throttle_ip);
login_cleanup_ip($throttle_ip);

check(RequestLogger::check_rate_limit('login', 10, 900, false),
	'a fresh address starts inside the login budget');

// Ten failures is the documented limit; the tenth must still be a credential
// refusal rather than a throttle refusal.
for ($i = 0; $i < 10; $i++) {
	$res = login_call(array('email' => $email, 'password' => 'wrong_' . $i));
}
check(login_is_retry($res), 'the tenth failure is still a credential refusal, not a throttle refusal',
	'redirect: ' . var_export($res->redirect, true));

$res = login_call(array('email' => $email, 'password' => 'wrong_11'));
check($res->error !== null && stripos((string)$res->error, 'too many failed') !== false,
	'the eleventh failure from one address is throttled',
	'error: ' . var_export($res->error, true));

// The throttle outranks a correct password: once the budget is gone, the right
// credential does not buy a way past it.
$res = login_call(array('email' => $email, 'password' => $password));
check($res->error !== null && stripos((string)$res->error, 'too many failed') !== false,
	'a throttled address is refused even with the correct password',
	'error: ' . var_export($res->error, true));

// Per-IP keying, asserted both ways.
$clean_ip = '192.0.2.21';
login_set_ip($clean_ip);
login_cleanup_ip($clean_ip);
check(RequestLogger::check_rate_limit('login', 10, 900, false),
	'a different address is unaffected by the first address exhausting its budget');

$res = login_call(array('email' => $email, 'password' => $password));
check($res->error === null && !login_is_retry($res),
	'the same account still signs in from an unthrottled address (no per-account lockout)',
	'redirect: ' . var_export($res->redirect, true));

// Successes are excluded from the counter, so ordinary use cannot self-throttle.
$success_ip = '192.0.2.22';
login_set_ip($success_ip);
login_cleanup_ip($success_ip);
for ($i = 0; $i < 12; $i++) {
	login_call(array('email' => $email, 'password' => $password));
}
check(RequestLogger::check_rate_limit('login', 10, 900, false),
	'twelve successful sign-ins do not consume the failure budget');

// The window is scoped: rows older than it stop counting.
$window_ip = '192.0.2.23';
login_set_ip($window_ip);
login_cleanup_ip($window_ip);
$db = DbConnector::get_instance()->get_db_link();
$q = $db->prepare("INSERT INTO rql_request_logs
	(rql_feature, rql_action, rql_ip_address, rql_was_success, rql_create_time)
	VALUES ('login', 'login_attempt', ?, false, NOW() - INTERVAL '16 minutes')");
for ($i = 0; $i < 15; $i++) { $q->execute(array($window_ip)); }
check(RequestLogger::check_rate_limit('login', 10, 900, false),
	'failures older than the 900-second window no longer count');

// ---------------------------------------------------------------------------
section('IP allowlist');

$ip_ip = '192.0.2.30';
login_set_ip($ip_ip);
login_cleanup_ip($ip_ip);

$restricted = make_user('LoginIpRestricted');
$restricted_email = $restricted->get('usr_email');
$restricted_pass = 'TestPassword_LoginIpRestricted';

$restricted->set('usr_allowed_ips', json_encode(array('198.51.100.7')));
$restricted->save();

$res = login_call(array('email' => $restricted_email, 'password' => $restricted_pass));
check($res->redirect !== null && strpos((string)$res->redirect, 'ip_blocked=1') !== false,
	'a correct password from an address outside the allowlist is refused',
	'redirect: ' . var_export($res->redirect, true));

login_set_ip('198.51.100.7');
login_cleanup_ip('198.51.100.7');
$res = login_call(array('email' => $restricted_email, 'password' => $restricted_pass));
check($res->error === null && !login_is_retry($res),
	'the same account signs in from an allowlisted address',
	'redirect: ' . var_export($res->redirect, true));

// A CIDR entry covers its range.
$restricted->set('usr_allowed_ips', json_encode(array('203.0.113.0/24')));
$restricted->save();
login_set_ip('203.0.113.55');
login_cleanup_ip('203.0.113.55');
$res = login_call(array('email' => $restricted_email, 'password' => $restricted_pass));
check($res->error === null && !login_is_retry($res),
	'a CIDR allowlist entry admits an address inside the range',
	'redirect: ' . var_export($res->redirect, true));

login_set_ip('203.0.114.55');
login_cleanup_ip('203.0.114.55');
$res = login_call(array('email' => $restricted_email, 'password' => $restricted_pass));
check($res->redirect !== null && strpos((string)$res->redirect, 'ip_blocked=1') !== false,
	'a CIDR allowlist entry refuses an address outside the range',
	'redirect: ' . var_export($res->redirect, true));

// An empty allowlist means unrestricted, not locked out.
$restricted->set('usr_allowed_ips', null);
$restricted->save();
login_set_ip('192.0.2.31');
login_cleanup_ip('192.0.2.31');
$res = login_call(array('email' => $restricted_email, 'password' => $restricted_pass));
check($res->error === null && !login_is_retry($res),
	'an empty allowlist admits every address rather than none',
	'redirect: ' . var_export($res->redirect, true));

// ---------------------------------------------------------------------------
section('Activation gate');

$act_ip = '192.0.2.40';
login_set_ip($act_ip);
login_cleanup_ip($act_ip);

$unactivated = make_user('LoginUnactivated');
$unactivated_email = $unactivated->get('usr_email');
$unactivated_pass = 'TestPassword_LoginUnactivated';
$unactivated->set('usr_is_activated', FALSE);
$unactivated->save();

harness_set_setting_mem('activation_required_login', '1');
$res = login_call(array('email' => $unactivated_email, 'password' => $unactivated_pass));
check($res->error !== null && stripos((string)$res->error, 'activation') !== false,
	'an unactivated account is refused when the site requires activation',
	'error: ' . var_export($res->error, true));

$unactivated->set('usr_is_activated', TRUE);
$unactivated->save();
$res = login_call(array('email' => $unactivated_email, 'password' => $unactivated_pass));
check($res->error === null && !login_is_retry($res),
	'the same account signs in once activated',
	'redirect: ' . var_export($res->redirect, true));

// With the requirement off, activation state stops mattering.
$unactivated->set('usr_is_activated', FALSE);
$unactivated->save();
harness_set_setting_mem('activation_required_login', '0');
$res = login_call(array('email' => $unactivated_email, 'password' => $unactivated_pass));
check($res->error === null && !login_is_retry($res),
	'an unactivated account signs in when the site does not require activation',
	'redirect: ' . var_export($res->redirect, true));

// ---------------------------------------------------------------------------
section('Post-login destination (return-to)');

// A protected page bounce stores the requested URL (SessionControl::
// check_permission -> set_return); login must send the user back there, and
// only there. The slot is server-written, but the redirect refuses anything
// that is not a local path so it can never become an open redirect — and
// /login itself is never a destination (an authenticated user landing on
// /login reads as a 404).
$dest_user = make_user('LoginReturn');
$dest_email = $dest_user->get('usr_email');
$dest_pass = 'TestPassword_LoginReturn';

function login_call_with_return($email, $pass, $returnurl) {
	$_SESSION = array();
	if ($returnurl !== null) { $_SESSION['returnurl'] = $returnurl; }
	return harness_call_logic('logic/login_logic.php', 'login_logic',
		array('email' => $email, 'password' => $pass), 'POST');
}

$res = login_call_with_return($dest_email, $dest_pass, '/admin/admin_users?');
check($res->redirect === '/admin/admin_users?',
	'login returns the user to the page that bounced them',
	'redirect: ' . var_export($res->redirect, true));

$res = login_call_with_return($dest_email, $dest_pass, null);
check($res->redirect !== null && strpos($res->redirect, '/login') !== 0,
	'with no stored destination, login falls back to a real page, never /login',
	'redirect: ' . var_export($res->redirect, true));

$res = login_call_with_return($dest_email, $dest_pass, 'https://evil.example/');
check($res->redirect !== null && strpos($res->redirect, 'evil.example') === false,
	'an absolute URL in the return slot is not followed',
	'redirect: ' . var_export($res->redirect, true));

$res = login_call_with_return($dest_email, $dest_pass, '//evil.example/');
check($res->redirect !== null && strpos($res->redirect, 'evil.example') === false,
	'a protocol-relative URL in the return slot is not followed',
	'redirect: ' . var_export($res->redirect, true));

$res = login_call_with_return($dest_email, $dest_pass, '/login?retry=1');
check($res->redirect !== null && strpos($res->redirect, '/login') !== 0,
	'/login itself is never the post-login destination',
	'redirect: ' . var_export($res->redirect, true));

// ---------------------------------------------------------------------------
section('Second-factor divert');

$totp_ip = '192.0.2.50';
login_set_ip($totp_ip);
login_cleanup_ip($totp_ip);

$totp_user = make_user('LoginTotp');
$totp_email = $totp_user->get('usr_email');
$totp_pass = 'TestPassword_LoginTotp';

// A correct password alone must not complete sign-in for a 2FA account.
$totp_user->enable_totp('JBSWY3DPEHPK3PXP');
$totp_user->set('usr_2fa_cadence', 'every_login');
$totp_user->save();
$totp_user->load();

check($totp_user->has_totp_enabled(), 'the fixture account holds a second factor');
check($totp_user->two_factor_cadence() === 'every_login',
	'the fixture account asks its factor at every sign-in',
	'cadence: ' . $totp_user->two_factor_cadence());

$_SESSION = array();
$res = harness_call_logic('logic/login_logic.php', 'login_logic',
	array('email' => $totp_email, 'password' => $totp_pass), 'POST');

check($res->redirect === '/verify-totp',
	'a correct password diverts to the second-factor ceremony instead of signing in',
	'redirect: ' . var_export($res->redirect, true));
check(empty($_SESSION['loggedin']) && empty($_SESSION['usr_user_id']),
	'the diverted session is NOT signed in — the password alone opens nothing',
	'loggedin: ' . var_export($_SESSION['loggedin'] ?? null, true));
check(isset($_SESSION['totp_pending_user_id']) && (int)$_SESSION['totp_pending_user_id'] === (int)$totp_user->key,
	'the pending state names the account awaiting its second factor');
check(isset($_SESSION['totp_pending_expires']) && $_SESSION['totp_pending_expires'] > time()
	&& $_SESSION['totp_pending_expires'] <= time() + 600,
	'the pending state expires within ten minutes',
	'expires in: ' . (($_SESSION['totp_pending_expires'] ?? 0) - time()) . 's');

// A wrong password must not create a pending state — otherwise the divert
// itself would confirm the account exists and holds a factor.
$_SESSION = array();
$res = harness_call_logic('logic/login_logic.php', 'login_logic',
	array('email' => $totp_email, 'password' => $totp_pass . 'X'), 'POST');
check(login_is_retry($res) && empty($_SESSION['totp_pending_user_id']),
	'a wrong password on a 2FA account is refused without minting a pending state',
	'redirect: ' . var_export($res->redirect, true));

// Cadence sensitive_only signs in on the password alone, deferring the factor
// to sensitive actions (each independently step-up gated).
$totp_user->set('usr_2fa_cadence', 'sensitive_only');
$totp_user->save();
$totp_user->load();
$_SESSION = array();
$res = harness_call_logic('logic/login_logic.php', 'login_logic',
	array('email' => $totp_email, 'password' => $totp_pass), 'POST');
check($res->redirect !== '/verify-totp' && !login_is_retry($res),
	'cadence sensitive_only signs in without asking the factor at the door',
	'redirect: ' . var_export($res->redirect, true));

harness_finish();
