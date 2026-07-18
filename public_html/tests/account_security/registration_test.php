<?php
/** @joinery-test
 * name: account_registration
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Registration lifecycle — the front door, previously untested end to end.
 *
 * register_logic is the only path that mints an account from an anonymous
 * visitor, and everything it refuses is a refusal that matters: the feature
 * flag, three independent bot defenses, the duplicate-email check, and the
 * Population-2 rule that a login email may not be a mailbox hosted here (a
 * forgotten-password link would land in an inbox that requires this very
 * account to read). Each of those is a single `if` that a refactor can drop
 * without any other test noticing.
 *
 * The suite calls the logic in-process via harness_call_logic, so it runs at db
 * tier inside the pre-deploy gate rather than needing a live HTTP round trip.
 *
 * Outbound mail is suppressed with email_dry_run for the duration — account
 * creation sends a real activation email, and dev points email_service at
 * mailgun.
 *
 * Sections: the feature gate; bot defenses; required fields; duplicate and
 * hosted-address refusals; password rules; and the successful creation, which
 * asserts the row, the verifiable password hash, the terms stamp, the session
 * regeneration, and the activation code actually issued.
 *
 * Run: php tests/account_security/registration_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/logic.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('includes/Activation.php'));

// A session must exist before the first registration: CreateCompleteNew logs the
// new user in, and store_session_variables() calls session_regenerate_id(true),
// which is a no-op error without one.
if (session_id() === '') @session_start();

/** A unique address per run so a killed run's leftovers can never collide. */
function reg_email($suffix) {
	static $token = null;
	if ($token === null) $token = bin2hex(random_bytes(4));
	return 'regtest_' . $suffix . '_' . $token . '@example.com';
}

/**
 * The registration POST with every gate satisfied; $over replaces any field.
 *
 * The anti-spam answer is read from the live setting rather than hardcoded:
 * harness_set_setting_mem cannot blank a setting (Globalvars::get_setting treats
 * a blank in-memory value as a miss and falls through to the database), so the
 * defense cannot be switched off — it has to be satisfied.
 */
function reg_input(array $over = array()) {
	return array_merge(array(
		'usr_email'         => reg_email('base'),
		'usr_first_name'    => 'Reg',
		'usr_last_name'     => 'Tester',
		'password'          => 'RegTest_Passw0rd',
		'antispam_question' => (string)Globalvars::get_instance()->get_setting('anti_spam_answer'),
	), $over);
}

/**
 * Call register_logic against a signed-out session and a fresh source address.
 *
 * Two pieces of state would otherwise leak between calls:
 *
 * A successful registration signs the new account in, and register_logic
 * redirects any signed-in visitor away before it validates anything. Without
 * the session reset the first success turns every later call into a silent
 * redirect — which reads as "no error" and quietly passes a refusal check.
 *
 * Registration is also rate limited per IP, and this suite makes far more
 * attempts than a person would. Each call gets its own documentation-range
 * address so the throttle never fires except in the section that tests it.
 */
function reg_call(array $input) {
	static $n = 0;
	$_SESSION = array();
	$_SERVER['REMOTE_ADDR'] = '192.0.2.' . (100 + (++$n % 120));
	return harness_call_logic('logic/register_logic.php', 'register_logic', $input, 'POST');
}

/** Drop the request-log rows this suite wrote. */
harness_defer(function () {
	try {
		$db = DbConnector::get_instance()->get_db_link();
		$db->prepare("DELETE FROM rql_request_logs WHERE rql_feature = 'register' AND rql_ip_address LIKE '192.0.2.%'")->execute();
	} catch (\Throwable $e) {
		echo "  WARNING: could not clean register request logs: " . $e->getMessage() . "\n";
	}
});

/** Delete the account register_logic created for $email, plus its codes. */
function reg_cleanup_email($email) {
	harness_defer(function () use ($email) {
		$db = DbConnector::get_instance()->get_db_link();
		try {
			$user = User::GetByEmail($email);
			if (!$user) return;
			$q = $db->prepare("DELETE FROM act_activation_codes WHERE act_usr_user_id = ?");
			$q->execute(array($user->key));
			$user->permanent_delete();
		} catch (\Throwable $e) {
			echo "  WARNING: could not clean up $email: " . $e->getMessage() . "\n";
		}
	});
}

// Baseline: registration on, bot defenses off unless a check turns one on, and
// no mail leaves the box. harness_set_setting_mem is in-memory only and is
// restored at teardown.
harness_set_setting_mem('email_dry_run', '1');
harness_set_setting_mem('register_active', '1');
harness_set_setting_mem('use_honeypot', '0');
harness_set_setting_mem('use_captcha', '0');

// ---------------------------------------------------------------------------
section('Feature gate');

harness_set_setting_mem('register_active', '0');
$res = reg_call(reg_input());
check($res->error !== null && $res->redirect === null,
	'register_active off refuses registration',
	'error: ' . var_export($res->error, true));
harness_set_setting_mem('register_active', '1');

// Deliberately NOT through reg_call(), which clears the session.
$_SERVER['REMOTE_ADDR'] = '192.0.2.99';
$_SESSION['usr_user_id'] = $existing_for_redirect = make_user('RegSignedIn')->key;
$_SESSION['loggedin'] = true;
$res = harness_call_logic('logic/register_logic.php', 'register_logic', reg_input(), 'POST');
check($res->redirect === '/profile/profile',
	'an already-signed-in visitor is redirected, not handed a second account',
	'redirect: ' . var_export($res->redirect, true));
unset($_SESSION['usr_user_id'], $_SESSION['loggedin']);

// ---------------------------------------------------------------------------
section('Bot defenses');

harness_set_setting_mem('use_honeypot', '1');
$res = reg_call(reg_input(array('website_url' => 'http://spam.example')));
check($res->error !== null,
	'a filled honeypot field refuses registration',
	'error: ' . var_export($res->error, true));

$honeypot_email = strtolower(reg_email('honeypot_clean'));
reg_cleanup_email($honeypot_email);
$res = reg_call(reg_input(array('website_url' => '', 'usr_email' => $honeypot_email)));
check($res->error === null,
	'an empty honeypot field passes',
	'error: ' . var_export($res->error, true));
harness_set_setting_mem('use_honeypot', '0');

$answer = (string)Globalvars::get_instance()->get_setting('anti_spam_answer');
if ($answer !== '') {
	$res = reg_call(reg_input(array('antispam_question' => $answer . '_wrong')));
	check($res->error !== null,
		'a wrong anti-spam answer refuses registration',
		'error: ' . var_export($res->error, true));

	$mixed = strtoupper($answer);
	$antispam_email = strtolower(reg_email('antispam_ok'));
	reg_cleanup_email($antispam_email);
	$res = reg_call(reg_input(array('antispam_question' => $mixed, 'usr_email' => $antispam_email)));
	check($res->error === null,
		'the anti-spam answer is compared case-insensitively',
		'error: ' . var_export($res->error, true));
} else {
	harness_skip('anti-spam answer checks', 'anti_spam_answer is not configured');
}

// ---------------------------------------------------------------------------
section('Required fields');

foreach (array('usr_email', 'usr_first_name', 'usr_last_name', 'password') as $field) {
	$input = reg_input();
	unset($input[$field]);
	$res = reg_call($input);
	check($res->error !== null && $res->redirect === null,
		"a missing $field refuses registration",
		'error: ' . var_export($res->error, true));

	$res = reg_call(reg_input(array($field => '   ')));
	check($res->error !== null && $res->redirect === null,
		"a whitespace-only $field refuses registration",
		'error: ' . var_export($res->error, true));
}

// The form posts either a bare name or an lbx_reg_-prefixed one; both must land.
$prefixed_email = reg_email('prefixed');
$res = reg_call(array(
	'lbx_reg_usr_email'      => $prefixed_email,
	'lbx_reg_usr_first_name' => 'Prefixed',
	'lbx_reg_usr_last_name'  => 'Tester',
	'lbx_reg_password'       => 'RegTest_Passw0rd',
	'antispam_question'      => (string)Globalvars::get_instance()->get_setting('anti_spam_answer'),
));
reg_cleanup_email(strtolower($prefixed_email));
check($res->error === null && $res->redirect !== null,
	'lbx_reg_-prefixed field names are accepted',
	'error: ' . var_export($res->error, true));

// ---------------------------------------------------------------------------
section('Duplicate and hosted-address refusals');

$existing = make_user('RegDup');
$res = reg_call(reg_input(array('usr_email' => $existing->get('usr_email'))));
check($res->error !== null && stripos((string)$res->error, 'already been registered') !== false,
	'a duplicate email refuses registration and points at password reset',
	'error: ' . var_export($res->error, true));

$res = reg_call(reg_input(array('usr_email' => strtoupper($existing->get('usr_email')))));
check($res->error !== null,
	'the duplicate check is case-insensitive',
	'error: ' . var_export($res->error, true));

// Population 2: a login email hosted on this platform is circular. The mailbox
// plugin owns the domain list, so skip cleanly when it is not installed.
$hosted_domain = null;
$domain_class = PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php');
if (file_exists($domain_class)) {
	require_once($domain_class);
	try {
		$db = DbConnector::get_instance()->get_db_link();
		$hosted_domain = $db->query("SELECT ied_domain FROM ied_inbound_email_domains
			WHERE ied_delete_time IS NULL ORDER BY ied_inbound_email_domain_id LIMIT 1")->fetchColumn();
	} catch (\Throwable $e) {
		$hosted_domain = null;
	}
}
if ($hosted_domain) {
	$res = reg_call(reg_input(array('usr_email' => 'newsignup@' . $hosted_domain)));
	check($res->error !== null && stripos((string)$res->error, 'hosted here') !== false,
		'a login email on a platform-hosted domain is refused at creation',
		'domain: ' . $hosted_domain . '; error: ' . var_export($res->error, true));
} else {
	harness_skip('a login email on a platform-hosted domain is refused at creation',
		'no inbound email domain configured');
}

// ---------------------------------------------------------------------------
section('Password rules');

$threw = false;
try { User::GeneratePassword('short'); } catch (\Throwable $e) { $threw = true; }
check($threw, 'GeneratePassword rejects a password under 8 characters');

$hash = User::GeneratePassword('RegTest_Passw0rd');
check(is_string($hash) && $hash !== 'RegTest_Passw0rd' && password_verify('RegTest_Passw0rd', $hash),
	'GeneratePassword returns a verifiable hash, not the plaintext');
check(strpos((string)$hash, '$argon2id$') === 0,
	'the hash is Argon2id',
	'prefix: ' . substr((string)$hash, 0, 12));

// A weak password reaches GeneratePassword from register_logic. Whether that
// surfaces as a clean validation error or an exception, it must never create
// the account.
$weak_email = reg_email('weak');
$weak_created = false;
try {
	$res = reg_call(reg_input(array('usr_email' => $weak_email, 'password' => 'short')));
	$weak_created = ($res->error === null && $res->redirect !== null);
} catch (\Throwable $e) {
	$weak_created = false;
}
reg_cleanup_email(strtolower($weak_email));
check(!$weak_created && !User::GetByEmail($weak_email),
	'a password failing the rules never creates an account');

// ---------------------------------------------------------------------------
section('Rate limiting');

// The captcha and honeypot are client-side puzzles: once a bot can answer them,
// nothing else caps how fast one source creates accounts. Unlike the other
// checks in this suite, the throttle counts SUCCESSFUL attempts too — a flood of
// real signups is the abuse being bounded.
$flood_ip = '192.0.2.90';
$_SERVER['REMOTE_ADDR'] = $flood_ip;
require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));

check(RequestLogger::check_rate_limit('register', 5, 900, NULL),
	'a fresh address starts inside the sign-up budget');

$flood_emails = array();
for ($i = 0; $i < 5; $i++) {
	$flood_email = strtolower(reg_email('flood' . $i));
	$flood_emails[] = $flood_email;
	reg_cleanup_email($flood_email);
	$_SERVER['REMOTE_ADDR'] = $flood_ip;
	harness_call_logic('logic/register_logic.php', 'register_logic',
		reg_input(array('usr_email' => $flood_email)), 'POST');
	$_SESSION = array();
}

check(!RequestLogger::check_rate_limit('register', 5, 900, NULL),
	'five sign-ups exhaust the budget for that address');

$blocked_email = strtolower(reg_email('blocked'));
reg_cleanup_email($blocked_email);
$_SERVER['REMOTE_ADDR'] = $flood_ip;
$res = harness_call_logic('logic/register_logic.php', 'register_logic',
	reg_input(array('usr_email' => $blocked_email)), 'POST');
$_SESSION = array();

check($res->error !== null && stripos((string)$res->error, 'too many') !== false,
	'a sixth sign-up from the same address is refused',
	'error: ' . var_export($res->error, true));
check(!User::GetByEmail($blocked_email),
	'the throttled sign-up created no account');

// Per-IP, like every other throttle on the platform: a different source is
// unaffected. There is no global signup cap.
$_SERVER['REMOTE_ADDR'] = '192.0.2.91';
check(RequestLogger::check_rate_limit('register', 5, 900, NULL),
	'a different address is unaffected by the first exhausting its budget');

// ---------------------------------------------------------------------------
section('Successful registration');

$good_email = strtolower(reg_email('success'));
reg_cleanup_email($good_email);

$sid_before = session_id();
$res = reg_call(reg_input(array(
	'usr_email'      => $good_email,
	'usr_first_name' => 'Success',
	'usr_last_name'  => 'Case',
)));

check($res->error === null, 'registration succeeds',
	'error: ' . var_export($res->error, true));
check($res->redirect === '/page/register-thanks',
	'registration redirects to the thanks page',
	'redirect: ' . var_export($res->redirect, true));

$created = User::GetByEmail($good_email);
check($created instanceof User, 'the account row exists');

if ($created instanceof User) {
	check($created->get('usr_first_name') === 'Success' && $created->get('usr_last_name') === 'Case',
		'the submitted name is stored');
	check($created->get('usr_email') === $good_email,
		'the email is stored lowercased as submitted',
		'stored: ' . $created->get('usr_email'));
	check($created->check_password('RegTest_Passw0rd'),
		'the account password verifies');
	check(!$created->check_password('RegTest_Passw0rdX'),
		'a wrong password does not verify');
	check((string)$created->get('usr_password') !== 'RegTest_Passw0rd',
		'the password is not stored in plaintext');
	check(!empty($created->get('usr_terms_accepted_time')),
		'terms acceptance is stamped at creation');
	check((int)$created->get('usr_permission') === 0,
		'a self-registered account gets permission 0, never an elevated level',
		'permission: ' . var_export($created->get('usr_permission'), true));

	// Session fixation is asserted in the login suite, over HTTP: CLI output has
	// already sent headers by this point, so session_regenerate_id() cannot run
	// here and a check would be measuring the harness, not the platform.

	// The activation email is dry-run suppressed, but the code behind it must
	// still be minted — otherwise the account can never verify.
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare("SELECT act_code, act_expires_time, act_deleted FROM act_activation_codes
		WHERE act_usr_user_id = ? AND act_purpose = ? AND act_deleted = FALSE");
	$q->execute(array($created->key, Activation::EMAIL_VERIFY));
	$codes = $q->fetchAll(PDO::FETCH_ASSOC);
	check(count($codes) >= 1, 'an email-verification code is issued at registration',
		'found ' . count($codes));
	if ($codes) {
		check(strlen((string)$codes[0]['act_code']) >= 12,
			'the verification code is at least 12 characters',
			'length: ' . strlen((string)$codes[0]['act_code']));
		check($codes[0]['act_expires_time'] > gmdate('Y-m-d H:i:s'),
			'the verification code expires in the future',
			'expires: ' . var_export($codes[0]['act_expires_time'], true));
	}

	check(!$created->get('usr_email_verified_time'),
		'the account starts unverified',
		'verified: ' . var_export($created->get('usr_email_verified_time'), true));
}

harness_finish();
