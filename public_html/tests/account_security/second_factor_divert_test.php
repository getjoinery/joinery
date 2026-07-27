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
 * second factor is sent to /verify-totp until the factor is proved. That divert
 * lives in the session constructor, so it runs on EVERY request — including the
 * requests that exist to satisfy it. A divert that also fires on its own target
 * is an infinite redirect: the browser reports ERR_TOO_MANY_REDIRECTS and the
 * site reads as down while every component of it is healthy.
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
 * Sections: the divert still fires for an ordinary page; the factor page
 * renders instead of diverting to itself; a redirect-following browser arrives
 * rather than looping; the pending state is stashed once; the passkey actions
 * the factor page calls are answered; and the way out stays reachable.
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
$user->set('usr_totp_enabled_time', gmdate('Y-m-d H:i:s'));

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
section('The divert still fires for an ordinary request');

$r = harness_request('GET', '/', array('cookies' => $remembered, 'accept' => null));
check(in_array($r['status'], array(301, 302, 303), true),
	'an ordinary page is a redirect for a remembered user owing a factor',
	'status ' . $r['status']);
check(strpos((string)$r['redirect_url'], '/verify-totp') !== false,
	'the redirect target is the factor page',
	'-> ' . $r['redirect_url']);

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
$r = harness_request('GET', '/', array('cookies' => $remembered, 'accept' => null, 'follow' => 5));
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
