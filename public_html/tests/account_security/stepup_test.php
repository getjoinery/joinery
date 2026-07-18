<?php
/** @joinery-test
 * name: account_stepup
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Step-up confirmation — the gate a sensitive administration action calls to
 * demand "prove you are still at this keyboard".
 *
 * A session proves identity; a step-up proves presence. The distinction is what
 * makes a stolen session cookie less than a total loss: changing a password, a
 * login email, or a domain's security level all route through
 * require_recent_second_factor first, and a thief holding only the cookie cannot
 * mint the marker those actions demand.
 *
 * The marker is deliberately session-bound and short-lived, so three properties
 * carry the whole design: it must belong to exactly one session, it must go
 * stale on a clock rather than lasting the session, and the return URL the gate
 * redirects through must never leave the site — that parameter is attacker-
 * supplied on every call.
 *
 * A step-up is also a no-op for an account with no second factor, which is not
 * an oversight: 2FA is optional below Fortress, and a gate that cannot be
 * satisfied would lock such an account out of its own settings. Enrollment
 * rules, not this gate, decide whether a factor must exist.
 *
 * Sections: factor detection; stamping and TTL; session binding; the gate's
 * decisions; and the open-redirect guard.
 *
 * Run: php tests/account_security/stepup_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/passkey_ceremonies_class.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));

if (session_id() === '') @session_start();
if (session_id() === '') {
	harness_skip('step-up suite', 'no session could be started on the CLI');
	harness_finish();
}

harness_set_setting_mem('email_dry_run', '1');

$session = SessionControl::get_instance();

/** Drop every step-up marker written for $sid. */
function su_cleanup_session($sid) {
	harness_defer(function () use ($sid) {
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->prepare("DELETE FROM pks_passkey_ceremonies WHERE pks_session_id = ?");
			$q->execute(array($sid));
		} catch (\Throwable $e) {
			echo "  WARNING: could not clean step-up markers for $sid: " . $e->getMessage() . "\n";
		}
	});
}

/** Backdate this session's markers so they read as $age seconds old. */
function su_age_markers($sid, $age) {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare("UPDATE pks_passkey_ceremonies
		SET pks_created_time = NOW() AT TIME ZONE 'UTC' - (? || ' seconds')::interval
		WHERE pks_session_id = ? AND pks_kind = 'stepup'");
	$q->execute(array((int)$age, $sid));
}

/** Write a step-up marker directly against an arbitrary session id. */
function su_stamp_for_session($sid, $purpose = 'stepup_verified') {
	$marker = new PasskeyCeremony(NULL);
	$marker->set('pks_session_id', $sid);
	$marker->set('pks_kind', 'stepup');
	$marker->set('pks_purpose', $purpose);
	$marker->set('pks_expires_time', gmdate('Y-m-d H:i:s', time() + 3600));
	$marker->save();
	return $marker;
}

$sid = session_id();
su_cleanup_session($sid);

$plain = make_user('StepupPlain');
$totp_user = make_user('StepupTotp');
$totp_user->enable_totp('JBSWY3DPEHPK3PXP');
$totp_user->save();
$totp_user->load();

// ---------------------------------------------------------------------------
section('Factor detection');

check(!$session->user_has_second_factor($plain),
	'an account with no factor holds no second factor');
check($session->user_has_second_factor($totp_user),
	'an account with TOTP holds a second factor');
check($session->user_has_independent_second_factor($totp_user),
	'TOTP counts as a factor independent of any single passkey');
check(!$session->user_has_second_factor(null),
	'a null user holds no second factor (an anonymous visitor cannot step up)');

$unsaved = new User(NULL);
check(!$session->user_has_second_factor($unsaved),
	'an unsaved user object holds no second factor');

// ---------------------------------------------------------------------------
section('Stamping and TTL');

$db = DbConnector::get_instance()->get_db_link();
$q = $db->prepare("DELETE FROM pks_passkey_ceremonies WHERE pks_session_id = ?");
$q->execute(array($sid));

check(!$session->has_recent_second_factor(300),
	'a session with no marker has no recent confirmation');

$session->stamp_second_factor();
check($session->has_recent_second_factor(300),
	'stamping a confirmation makes it recent');

// The marker goes stale on a clock. Both sides of the boundary, so a test that
// only ever backdates far past the TTL cannot hide an always-false comparison.
su_age_markers($sid, 100);
check($session->has_recent_second_factor(300),
	'a 100-second-old confirmation is still recent under a 300-second TTL');

su_age_markers($sid, 400);
check(!$session->has_recent_second_factor(300),
	'a 400-second-old confirmation is stale under a 300-second TTL');
check($session->has_recent_second_factor(600),
	'the same 400-second-old confirmation is recent under a 600-second TTL');

// A marker of another kind is not a step-up.
$q->execute(array($sid));
$other_kind = new PasskeyCeremony(NULL);
$other_kind->set('pks_session_id', $sid);
$other_kind->set('pks_kind', 'auth');
$other_kind->set('pks_purpose', 'login');
$other_kind->set('pks_expires_time', gmdate('Y-m-d H:i:s', time() + 3600));
$other_kind->save();
check(!$session->has_recent_second_factor(300),
	'a ceremony of another kind does not satisfy the step-up gate');

// ---------------------------------------------------------------------------
section('Session binding');

$q->execute(array($sid));

// The marker belongs to the session that earned it. A step-up confirmed in one
// browser must not silently authorize a sensitive action in another — including
// a session an attacker holds a stolen cookie for.
$foreign_sid = 'stepuptest_foreign_' . bin2hex(random_bytes(8));
su_cleanup_session($foreign_sid);
su_stamp_for_session($foreign_sid);

check(!$session->has_recent_second_factor(300),
	'a confirmation stamped for another session does not satisfy this one');

$session->stamp_second_factor();
check($session->has_recent_second_factor(300),
	'this session is satisfied by its own confirmation');

$markers = new MultiPasskeyCeremony(array('session_id' => $foreign_sid, 'kind' => 'stepup'));
$markers->load();
$foreign_count = 0;
foreach ($markers as $m) { $foreign_count++; }
check($foreign_count === 1,
	'stamping this session did not touch the other session\'s marker',
	'foreign markers: ' . $foreign_count);

// ---------------------------------------------------------------------------
section('The gate');

$q->execute(array($sid));

// No factor enrolled: the gate stands aside rather than locking the account out
// of settings it could never reach.
$_SESSION['usr_user_id'] = $plain->key;
$_SESSION['loggedin'] = true;
check($session->require_recent_second_factor('/profile/password_edit') === null,
	'the gate is a no-op for an account with no second factor');

// Factor enrolled, no recent confirmation: divert to the ceremony.
$_SESSION['usr_user_id'] = $totp_user->key;
$res = $session->require_recent_second_factor('/profile/password_edit');
check($res !== null && $res->redirect !== null
	&& strpos((string)$res->redirect, '/verify-stepup') === 0,
	'an account with a factor and no recent confirmation is sent to the ceremony',
	'redirect: ' . var_export($res ? $res->redirect : null, true));
check($res !== null && strpos((string)$res->redirect, rawurlencode('/profile/password_edit')) !== false,
	'the ceremony is told where to return',
	'redirect: ' . var_export($res ? $res->redirect : null, true));

// Factor enrolled and recently confirmed: proceed.
$session->stamp_second_factor();
check($session->require_recent_second_factor('/profile/password_edit') === null,
	'a recent confirmation lets the action proceed');

// The confirmation expires: the next sensitive action asks again.
su_age_markers($sid, 400);
$res = $session->require_recent_second_factor('/profile/password_edit');
check($res !== null && strpos((string)$res->redirect, '/verify-stepup') === 0,
	'once the confirmation goes stale the gate asks again',
	'redirect: ' . var_export($res ? $res->redirect : null, true));

// An anonymous session has no user to step up.
$_SESSION = array();
check($session->require_recent_second_factor('/profile/password_edit') === null,
	'the gate is a no-op with no signed-in user');

// ---------------------------------------------------------------------------
section('Open-redirect guard');

// The return URL rides in on the request, so it is attacker-controlled on every
// call. Anything that could leave the site must be replaced, not merely escaped:
// a step-up flow that lands on an attacker's page is a phishing handoff carrying
// the user's freshly-proven presence.
$_SESSION['usr_user_id'] = $totp_user->key;
$_SESSION['loggedin'] = true;
$q->execute(array($sid));

$hostile = array(
	'//evil.example.com/path'          => 'a protocol-relative URL',
	'https://evil.example.com'         => 'an absolute https URL',
	'http://evil.example.com'          => 'an absolute http URL',
	'javascript:alert(1)'              => 'a javascript: URL',
	'evil.example.com'                 => 'a bare host',
	''                                 => 'an empty return',
	'\\\\evil.example.com'             => 'a backslash-prefixed UNC-style path',
);

foreach ($hostile as $url => $description) {
	$res = $session->require_recent_second_factor($url);
	$redirect = $res ? (string)$res->redirect : '';
	$returned = '';
	if (preg_match('/[?&]return=([^&]*)/', $redirect, $m)) {
		$returned = rawurldecode($m[1]);
	}
	check($returned === '/profile',
		"$description is replaced with a safe local return",
		'return was: ' . var_export($returned, true));
}

// A legitimate relative return survives intact.
$safe = array('/profile/password_edit', '/profile/security', '/admin/admin_settings');
foreach ($safe as $url) {
	$res = $session->require_recent_second_factor($url);
	$redirect = $res ? (string)$res->redirect : '';
	$returned = '';
	if (preg_match('/[?&]return=([^&]*)/', $redirect, $m)) {
		$returned = rawurldecode($m[1]);
	}
	check($returned === $url,
		"a same-site return ($url) is preserved",
		'return was: ' . var_export($returned, true));
}

$_SESSION = array();
harness_finish();
