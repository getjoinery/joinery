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
 * covers: the failure throttle (per network and per account), missing credentials, a bad email or
 * password, an IP outside the account's allowlist, and an unactivated account
 * when the site demands activation. Past all of those, an account holding a
 * second factor is diverted to /verify-totp with a pending state rather than
 * being signed in.
 *
 * Throttle isolation: RequestLogger keys the network counter on
 * $_SERVER['REMOTE_ADDR'], which the CLI leaves unset. Each throttle section
 * pins distinct synthetic addresses from the documentation ranges (192.0.2.0/24,
 * 2001:db8::/32), so the suite can never consume — or be blocked by — a real
 * client's budget, and the rows it writes are unambiguously its own at
 * teardown. Each throttle check uses its own fresh account, so one section's
 * failures never count against another's.
 *
 * The throttle counts failures two ways, 10 in 15 minutes each
 * (specs/ipv6_address_handling.md D3): from the caller's network (an IPv4
 * address, an IPv6 /64), and for the email typed, so a caller with many
 * networks cannot aim them all at one account.
 *
 * Sections: credentials; the failure throttle; the IP allowlist; the activation
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

// The same refusal must also take the same time: an unknown email still pays
// for a password check, or the answer's speed says which emails exist.
$t0 = microtime(true);
User::spend_password_check('anything');
$spent = microtime(true) - $t0;
$known = new User($user->key, TRUE);
$t0 = microtime(true);
$known->check_password('wrong password');
$real = microtime(true) - $t0;
check($spent > 0.25 * $real, 'an unknown email spends a password check\'s time',
	sprintf('dummy %.0f ms vs real %.0f ms', $spent * 1000, $real * 1000));
$login_src = file_get_contents(PathHelper::getIncludePath('logic/login_logic.php'));
check(preg_match('/if \(!\$user\) \{\s*User::spend_password_check\(\$password\);/', $login_src) === 1,
	'sign-in spends it whenever no account has the email');

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
section('Failure throttle: per network');

/** A fresh email no account has, so a failure counts against no real account. */
function login_unknown_email() {
	return 'nobody_' . bin2hex(random_bytes(6)) . '@example.com';
}

// A fresh address, so the budget starts full regardless of run order.
$throttle_ip = '192.0.2.20';
login_set_ip($throttle_ip);
login_cleanup_ip($throttle_ip);

check(RequestLogger::check_attempt_limit('login', 10, 900, false),
	'a fresh address starts inside the login budget');

// Ten failures is the documented limit, each for a different email so only
// the network count fills; the tenth must still be a credential refusal.
for ($i = 0; $i < 10; $i++) {
	$res = login_call(array('email' => login_unknown_email(), 'password' => 'wrong_' . $i));
}
check(login_is_retry($res), 'the tenth failure is still a credential refusal, not a throttle refusal',
	'redirect: ' . var_export($res->redirect, true));

// Every refusal is a redirect back to the form carrying its message: an error
// result with no page data would land on the exception page instead.
$res = login_call(array('email' => login_unknown_email(), 'password' => 'wrong_11'));
check($res->redirect === '/login?msgtext=too_many_attempts',
	'the eleventh failure from one address is throttled',
	'redirect: ' . var_export($res->redirect, true) . ' error: ' . var_export($res->error, true));

// The throttle outranks a correct password: once the budget is gone, the right
// credential does not buy a way past it.
$res = login_call(array('email' => $email, 'password' => $password));
check($res->redirect === '/login?msgtext=too_many_attempts',
	'a throttled address is refused even with the correct password',
	'redirect: ' . var_export($res->redirect, true) . ' error: ' . var_export($res->error, true));

$clean_ip = '192.0.2.21';
login_set_ip($clean_ip);
login_cleanup_ip($clean_ip);
check(RequestLogger::check_attempt_limit('login', 10, 900, false),
	'a different address is unaffected by the first address exhausting its budget');

$res = login_call(array('email' => $email, 'password' => $password));
check($res->error === null && !login_is_retry($res),
	'the same account still signs in from an unthrottled address',
	'redirect: ' . var_export($res->redirect, true));

// An IPv6 caller's network is its /64: ten addresses inside one /64 share one
// budget, so rotating through it buys nothing.
for ($i = 1; $i <= 10; $i++) {
	$v6 = '2001:db8:4e:1::' . dechex(0x100 + $i);
	login_set_ip($v6);
	login_cleanup_ip($v6);
	$res = login_call(array('email' => login_unknown_email(), 'password' => 'wrong_v6_' . $i));
}
$v6_next = '2001:db8:4e:1:ffff::1';
login_set_ip($v6_next);
login_cleanup_ip($v6_next);
$res = login_call(array('email' => login_unknown_email(), 'password' => 'wrong_v6_11'));
check($res->redirect === '/login?msgtext=too_many_attempts',
	'ten failures from ten addresses in one IPv6 /64: the eleventh, from an eleventh address in it, is throttled',
	'redirect: ' . var_export($res->redirect, true));
$v6_other = '2001:db8:4e:2::1';
login_set_ip($v6_other);
login_cleanup_ip($v6_other);
check(RequestLogger::check_attempt_limit('login', 10, 900, false), 'the next /64 is another network, with its own budget');

// Successes are excluded from the counter, so ordinary use cannot self-throttle.
$success_ip = '192.0.2.22';
login_set_ip($success_ip);
login_cleanup_ip($success_ip);
for ($i = 0; $i < 12; $i++) {
	login_call(array('email' => $email, 'password' => $password));
}
check(RequestLogger::check_attempt_limit('login', 10, 900, false),
	'twelve successful sign-ins do not consume the failure budget');

// The window is scoped: rows older than it stop counting.
$window_ip = '192.0.2.23';
login_set_ip($window_ip);
login_cleanup_ip($window_ip);
$db = DbConnector::get_instance()->get_db_link();
$q = $db->prepare("INSERT INTO rql_request_logs
	(rql_feature, rql_action, rql_ip_address, rql_ip_network, rql_was_success, rql_create_time)
	VALUES ('login', 'login_attempt', ?, ?, false, NOW() - INTERVAL '16 minutes')");
for ($i = 0; $i < 15; $i++) { $q->execute(array($window_ip, $window_ip)); }
check(RequestLogger::check_attempt_limit('login', 10, 900, false),
	'failures older than the 900-second window no longer count');

// ---------------------------------------------------------------------------
section('Failure throttle: per account');

$target_pass = 'TestPassword_LoginTarget';
$target = make_user('LoginTarget');
$target_email = $target->get('usr_email');

// Ten failures for one account, each from a different network.
for ($i = 1; $i <= 10; $i++) {
	$ip = '192.0.2.' . (100 + $i);
	login_set_ip($ip);
	login_cleanup_ip($ip);
	$res = login_call(array('email' => $target_email, 'password' => 'wrong_' . $i));
}
check(login_is_retry($res), 'the tenth failure for the account is still a credential refusal',
	'redirect: ' . var_export($res->redirect, true));

$fresh_ip = '192.0.2.120';
login_set_ip($fresh_ip);
login_cleanup_ip($fresh_ip);
$res = login_call(array('email' => strtoupper($target_email), 'password' => $target_pass));
check($res->redirect === '/login?msgtext=too_many_attempts',
	'from an eleventh network, the account is refused even with the correct password (and the email in another case)',
	'redirect: ' . var_export($res->redirect, true));

$res = login_call(array('email' => $email, 'password' => $password));
check($res->error === null && !login_is_retry($res),
	'another account signs in from that same network',
	'redirect: ' . var_export($res->redirect, true));

check(RequestLogger::check_attempt_limit('passkey_login', 20, 900, false),
	'passkey sign-in keeps its own count, untouched by the password failures');

// An email no account has is counted and refused the same way, so the limit
// says nothing about which emails have accounts.
$ghost = login_unknown_email();
for ($i = 1; $i <= 10; $i++) {
	$ip = '192.0.2.' . (130 + $i);
	login_set_ip($ip);
	login_cleanup_ip($ip);
	login_call(array('email' => $ghost, 'password' => 'wrong_' . $i));
}
$ghost_ip = '192.0.2.150';
login_set_ip($ghost_ip);
login_cleanup_ip($ghost_ip);
$res = login_call(array('email' => $ghost, 'password' => 'wrong_11'));
check($res->redirect === '/login?msgtext=too_many_attempts',
	'an email with no account is throttled with the same refusal',
	'redirect: ' . var_export($res->redirect, true));

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

// IPv6 entries compare as addresses, and a prefix admits exactly its bits.
$restricted->set('usr_allowed_ips', json_encode(array('2001:DB8::1', '2001:db8:abcd:1230::/60')));
$restricted->save();
foreach (array(
	'2001:db8:0:0::1'       => true,   // the address entry, spelled another way
	'2001:db8:abcd:123f::1' => true,   // inside the /60
	'2001:db8:abcd:1240::1' => false,  // just past it
	'203.0.113.55'          => false,  // the other family matches no IPv6 entry
) as $v6_ip => $admitted) {
	login_set_ip($v6_ip);
	login_cleanup_ip($v6_ip);
	$res = login_call(array('email' => $restricted_email, 'password' => $restricted_pass));
	$blocked = $res->redirect !== null && strpos((string)$res->redirect, 'ip_blocked=1') !== false;
	check($admitted ? ($res->error === null && !login_is_retry($res) && !$blocked) : $blocked,
		($admitted ? 'admitted: ' : 'refused: ') . $v6_ip, 'redirect: ' . var_export($res->redirect, true));
}

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
check($res->error === null
		&& $res->redirect === '/login?msgtext=activation_sent&e=' . rawurlencode($unactivated_email),
	'an unactivated account is sent back to the form with the activation message, never a thrown error',
	'redirect: ' . var_export($res->redirect, true) . ' error: ' . var_export($res->error, true));

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

// A page that needs a member links to /login?return=<page>. The sign-in form
// keeps a local path in the slot — so a sign-in, or a registration started
// from the form's own link, lands back on that page — and drops anything else
// at the door.
$_SESSION = array();
$res = harness_call_logic('logic/login_logic.php', 'login_logic',
	array('return' => '/profile/server_manager/configure'), 'GET');
check($res->redirect === null && ($_SESSION['returnurl'] ?? '') === '/profile/server_manager/configure',
	'a ?return= handed to the sign-in form is kept for after the sign-in',
	'slot: ' . var_export($_SESSION['returnurl'] ?? null, true));
$res = harness_call_logic('logic/login_logic.php', 'login_logic',
	array('email' => $dest_email, 'password' => $dest_pass), 'POST');
check($res->redirect === '/profile/server_manager/configure',
	'and the sign-in goes there',
	'redirect: ' . var_export($res->redirect, true));
foreach (array('https://evil.example/', '//evil.example/', '/login?retry=1', 'profile') as $bad) {
	$_SESSION = array();
	harness_call_logic('logic/login_logic.php', 'login_logic', array('return' => $bad), 'GET');
	check(empty($_SESSION['returnurl']), 'a handed return that is not a local page is not kept: ' . $bad,
		'slot: ' . var_export($_SESSION['returnurl'] ?? null, true));
}

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

// ---------------------------------------------------------------------------
section('Already signed in');

// The session above is signed in. The factory public homepage is /login, and
// the site root falls back to it when the signed-in homepage is blank, so a
// member arriving here is sent where a successful login would have gone
// rather than shown a login form.
harness_set_setting_mem('alternate_loggedin_homepage', '');
$res = harness_call_logic('logic/login_logic.php', 'login_logic', array(), 'GET');
check($res->redirect === '/profile',
	'a signed-in visitor to the sign-in page is sent to /profile',
	'redirect: ' . var_export($res->redirect, true));

harness_set_setting_mem('alternate_loggedin_homepage', '/profile/mailbox');
$res = harness_call_logic('logic/login_logic.php', 'login_logic', array(), 'GET');
check($res->redirect === '/profile/mailbox',
	'the signed-in homepage setting decides where they go',
	'redirect: ' . var_export($res->redirect, true));

$res = harness_call_logic('logic/login_logic.php', 'login_logic',
	array('return' => '/profile/server_manager/configure'), 'GET');
check($res->redirect === '/profile/server_manager/configure' && empty($_SESSION['returnurl']),
	'a signed-in visitor handed a return goes straight there, and the slot is not left behind',
	'redirect: ' . var_export($res->redirect, true));

$_SESSION = array();
$res = harness_call_logic('logic/login_logic.php', 'login_logic', array(), 'GET');
check($res->redirect === null,
	'a signed-out visitor still gets the form',
	'redirect: ' . var_export($res->redirect, true));

harness_finish();
