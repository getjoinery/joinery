<?php
/** @joinery-test
 * name: account_second_factor_divert
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The sign-in second-factor divert, over real HTTP.
 *
 * A browser holding a remember-me cookie for an account that still owes a
 * second factor is sent to /verify-totp when it opens a page that needs a
 * member (check_permission) or the sign-in form. A public page renders signed
 * out, as to any visitor: diverting every request kept a remembered visitor
 * from reading the public site at all. A divert that also fires on its own
 * target is an infinite redirect: the browser reports ERR_TOO_MANY_REDIRECTS
 * and the site reads as down while every component of it is healthy.
 *
 * The state is only reachable when a remember-me cookie outlives the
 * trusted-device cookie, because proving the factor normally grants a
 * trusted-device cookie that skips the divert entirely. Forgetting trusted
 * devices (security_logic 'revoke_trusted_devices') produces exactly that
 * pairing, which is how it was found.
 *
 * The checks below are deliberately split between the two halves of the rule,
 * because a fix that only satisfies one half is worse than the bug: skip too
 * little and the loop returns; skip too much and a browser owing a factor
 * wanders the site signed out with nothing ever asking it for the factor.
 *
 * Sections: the divert fires for a page that needs a member and for the
 * sign-in form; a public page renders signed out; the factor page
 * renders instead of diverting to itself; a redirect-following browser arrives
 * rather than looping; the pending state is stashed once; the passkey actions
 * the factor page calls are answered; proving the factor returns to the page
 * that was asked for; and the way out stays reachable.
 *
 * Run: php tests/account_security/second_factor_divert_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/http.php');
harness_boot();
harness_http_boot($argv);

require_once(PathHelper::getIncludePath('data/users_class.php'));

// ---------------------------------------------------------------------------
// Fixture: an account that owes a second factor, remembered on this browser.
// ---------------------------------------------------------------------------

$user = make_user('2fadivert');
// A real secret, so a later section can prove the factor with a code.
$totp_secret = 'JBSWY3DPEHPK3PXP';
$user->enable_totp($totp_secret);

// A remember-me cookie exactly as save_user_to_cookie() mints one: the browser
// carries the raw token, the row carries only its hash.
$raw_token = bin2hex(random_bytes(32));
$user->set('usr_remember_tokens', json_encode(array(array(
	'hash'    => hash('sha256', $raw_token),
	'expires' => time() + 3600,
	'created' => time(),
))));
$user->save();
$user->load();

// No sf_trusted cookie anywhere below — that absence IS the condition under
// test, so it is never sent, not even incidentally.
$remembered = 'tt=' . $raw_token;

// ---------------------------------------------------------------------------
section('The divert fires where a member is needed');

$r = harness_request('GET', '/profile/security', array('cookies' => $remembered, 'accept' => null));
check(in_array($r['status'], array(301, 302, 303), true),
	'a page that needs a member is a redirect for a remembered user owing a factor',
	'status ' . $r['status']);
check(strpos((string)$r['redirect_url'], '/verify-totp') !== false,
	'the redirect target is the factor page, not the sign-in form',
	'-> ' . $r['redirect_url']);

$r = harness_request('GET', '/login', array('cookies' => $remembered, 'accept' => null));
check(strpos((string)$r['redirect_url'], '/verify-totp') !== false,
	'the sign-in form sends a remembered user to the factor page instead of asking for a password',
	'status ' . $r['status'] . ' -> ' . $r['redirect_url']);

// ---------------------------------------------------------------------------
section('A public page renders signed out');

// A page anyone may see. A missing page is used because every site has one:
// it must answer as it would to any visitor (404), not divert.
$r = harness_request('GET', '/no-such-page-divert-test', array('cookies' => $remembered, 'accept' => null));
check(strpos((string)$r['redirect_url'], '/verify-totp') === false && $r['status'] === 404,
	'a public page answers as to any visitor, not with a divert',
	'status ' . $r['status'] . ' -> ' . $r['redirect_url']);
$r = harness_request('GET', '/api/v1/ping', array('cookies' => $remembered, 'accept' => null));
check(strpos((string)$r['redirect_url'], '/verify-totp') === false,
	'an API call is not diverted to an HTML page',
	'status ' . $r['status'] . ' -> ' . $r['redirect_url']);

// ---------------------------------------------------------------------------
section('The factor page renders instead of diverting to itself');

$r = harness_request('GET', '/verify-totp', array('cookies' => $remembered, 'accept' => null));
check($r['status'] === 200,
	'/verify-totp answers 200, not another redirect',
	'status ' . $r['status'] . ' -> ' . $r['redirect_url']);
check(stripos($r['body'], 'Confirm it&rsquo;s you') !== false,
	'the body is the factor page, not a redirect stub');

// ---------------------------------------------------------------------------
section('A browser that follows redirects arrives rather than looping');

// This is the regression check proper. curl abandons the request once it passes
// the hop cap, yielding status 0 and an empty body — the loop's exact signature,
// and what the browser was showing.
$r = harness_request('GET', '/profile/security', array('cookies' => $remembered, 'accept' => null, 'follow' => 5));
check($r['status'] === 200,
	'following the divert reaches a page within the hop limit',
	'status ' . $r['status'] . ' ' . $r['error']);
check(stripos($r['body'], 'Confirm it&rsquo;s you') !== false,
	'the page it reaches is the factor page');

// ---------------------------------------------------------------------------
section('The pending state is stashed once, not re-stashed per request');

// Stashing rotates the session id. Doing it on every request discards the id the
// browser was handed a moment earlier — the one carrying the pending state — so
// the factor page could never find what the divert had just written.
$jar = harness_jar_new();
harness_request('GET', '/verify-totp', array('cookies' => $remembered, 'jar' => $jar, 'accept' => null));
$first_session = harness_jar_cookie($jar, 'PHPSESSID');
harness_request('GET', '/verify-totp', array('cookies' => $remembered, 'jar' => $jar, 'accept' => null));
$second_session = harness_jar_cookie($jar, 'PHPSESSID');

check($first_session !== null, 'the first request establishes a session');
check($first_session === $second_session,
	'the session id survives the following request',
	'first ' . substr((string)$first_session, 0, 8) . '…, second ' . substr((string)$second_session, 0, 8) . '…');

// ---------------------------------------------------------------------------
section('The passkey actions the factor page calls are answered');

// The page offers a passkey instead of a typed code, and those two actions are
// how it is offered. Diverting them hands JavaScript an HTML redirect where it
// expects JSON, which breaks the passkey option without breaking the page — the
// failure would land on whoever has no authenticator app.
foreach (array('login_2fa_passkey_options', 'login_2fa_passkey_verify') as $action) {
	$r = harness_request('POST', '/api/v1/action/' . $action, array(
		'cookies' => $remembered,
		'body'    => array(),
	));
	check(strpos((string)$r['redirect_url'], '/verify-totp') === false,
		$action . ' is answered, not diverted to the page that calls it',
		'status ' . $r['status'] . ' -> ' . $r['redirect_url']);
}

// ---------------------------------------------------------------------------
section('Proving the factor returns to the page that was asked for');

// The session is new when the divert fires, so nothing had saved a return and
// the browser landed on /profile instead of the admin page it had opened.
$asked = '/profile/security?from=divert_test';
$jar = harness_jar_new();
$r = harness_request('GET', $asked, array('cookies' => $remembered, 'jar' => $jar, 'accept' => null));
check(strpos((string)$r['redirect_url'], '/verify-totp') !== false,
	'the asked-for page diverts to the factor page', '-> ' . $r['redirect_url']);
$r = harness_request('POST', '/verify-totp', array(
	'cookies' => $remembered, 'jar' => $jar, 'encode' => 'form',
	'body' => array('totp_code' => \OTPHP\TOTP::createFromSecret($totp_secret)->at(time())),
));
check(in_array($r['status'], array(301, 302, 303), true)
		&& substr((string)$r['redirect_url'], -strlen($asked)) === $asked,
	'a proved code returns to the page that was asked for',
	'status ' . $r['status'] . ' -> ' . $r['redirect_url']);

// An API call is not a page a person opened: it never becomes the return.
$jar = harness_jar_new();
harness_request('GET', '/api/v1/ping', array('cookies' => $remembered, 'jar' => $jar, 'accept' => null));
$user->set('usr_totp_last_used_step', null);
$user->save();
$r = harness_request('POST', '/verify-totp', array(
	'cookies' => $remembered, 'jar' => $jar, 'encode' => 'form',
	'body' => array('totp_code' => \OTPHP\TOTP::createFromSecret($totp_secret)->at(time())),
));
check(strpos((string)$r['redirect_url'], '/api/') === false,
	'a pending login first seen on an API call does not return to the API',
	'status ' . $r['status'] . ' -> ' . $r['redirect_url']);

// ---------------------------------------------------------------------------
section('The way out stays reachable');

// Runs last: logging out spends the remember-me token the fixture above minted.
// Without this exemption the Cancel link bounced straight back to the factor
// page, leaving no escape short of clearing cookies by hand.
$r = harness_request('GET', '/logout', array('cookies' => $remembered, 'accept' => null));
check(strpos((string)$r['redirect_url'], '/verify-totp') === false,
	'/logout is reachable while a factor is pending',
	'status ' . $r['status'] . ' -> ' . $r['redirect_url']);
check($r['status'] === 200,
	'/logout renders its own page',
	'status ' . $r['status']);

harness_finish();
