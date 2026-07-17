<?php
/** @joinery-test
 * name: routing_authz
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Routing authorization suite — the HTTP half of the routing-security coverage
 * (the in-process path-traversal filter is pinned separately by
 * tests/integration/routing_security_test.php).
 *
 * Proves the front controller's gates over real HTTP against the running site:
 *
 *   1. Sensitive server files are never served: /includes/*.php, /data/*.php,
 *      and a direct /adm/*.php request all 404 (routing goes through serve.php,
 *      which never exposes source by path).
 *   2. Anonymous access to a permission-gated route redirects to /login rather
 *      than rendering it.
 *   3. The permission gate is enforced for an authenticated-but-underprivileged
 *      user: a permission-0 member is refused /admin/* and a permission-5 staff
 *      user is refused /tests/*, while the correctly-privileged user is allowed
 *      (positive controls, so the test cannot pass by refusing everything).
 *   4. A private file is 404 (not 403) to a stranger over HTTP — the ownership
 *      gate hides existence rather than advertising it.
 *
 * Run: php tests/integration/routing_authz_test.php [base_url] [origin_ip]
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/http.php');
harness_http_boot($argv);
harness_boot();

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));

/** Activate a fixture user so the web login (activation_required_login) accepts it. */
function authz_activate($user) {
	$user->set('usr_is_activated', true);
	$user->save();
}

try {
	$run_id = substr(md5(uniqid('authz', true)), 0, 6);

	section('Sensitive server files are never served by path');
	foreach (array(
		'/includes/RouteHelper.php' => 'core include',
		'/data/users_class.php'     => 'data model class',
		'/adm/admin_users.php'      => 'admin page by .php path',
	) as $path => $label) {
		$r = harness_request('GET', $path, array('accept' => null));
		check($r['status'] === 404, "$label ($path) → 404", 'status ' . $r['status']);
	}

	section('Anonymous access to a gated route redirects to /login');
	foreach (array('/admin/admin_users', '/tests/') as $path) {
		$r = harness_request('GET', $path, array('accept' => null, 'follow' => false));
		$to_login = ($r['status'] === 302 || $r['status'] === 301)
			&& preg_match('#/login#', $r['redirect_url']);
		check((bool)$to_login, "anonymous $path → redirect to /login",
			'status ' . $r['status'] . ' → ' . $r['redirect_url']);
	}

	section('Permission gate for authenticated-but-underprivileged users');
	// A permission-0 member and a permission-5 staff user, each logged into a
	// real web session; then probe a route above each one's level and one at or
	// below it (the positive control).
	$member = make_user('AzM' . $run_id, 0);
	$staff  = make_user('AzS' . $run_id, 5);
	$super  = make_user('AzX' . $run_id, 10);
	authz_activate($member);
	authz_activate($staff);
	authz_activate($super);

	$member_jar = harness_jar_new('authz');
	$staff_jar  = harness_jar_new('authz');
	$super_jar  = harness_jar_new('authz');
	check(harness_web_login($member_jar, $member->get('usr_email'), 'TestPassword_AzM' . $run_id) !== null,
		'permission-0 member logged in');
	check(harness_web_login($staff_jar, $staff->get('usr_email'), 'TestPassword_AzS' . $run_id) !== null,
		'permission-5 staff logged in');
	check(harness_web_login($super_jar, $super->get('usr_email'), 'TestPassword_AzX' . $run_id) !== null,
		'permission-10 superadmin logged in');

	// /admin/* requires permission 5; /tests/* requires permission 10. An
	// authenticated user below the bar gets 401 (check_permission throws
	// SystemAuthenticationError with a 401 status), NOT a redirect and NOT a 200.
	$r = harness_request('GET', '/admin/admin_users', array('jar' => $member_jar, 'accept' => null, 'follow' => false));
	check($r['status'] === 401, 'permission-0 member refused /admin/* with 401', 'status ' . $r['status']);

	$r = harness_request('GET', '/tests/', array('jar' => $staff_jar, 'accept' => null, 'follow' => false));
	check($r['status'] === 401, 'permission-5 staff refused /tests/* with 401', 'status ' . $r['status']);

	// Positive controls: the privileged user is actually let in, so the refusals
	// above are the gate working and not the pages being broken for everyone.
	$r = harness_request('GET', '/admin/admin_users', array('jar' => $staff_jar, 'accept' => null, 'follow' => false));
	check($r['status'] === 200, 'permission-5 staff allowed /admin/* (positive control)', 'status ' . $r['status']);

	$r = harness_request('GET', '/tests/', array('jar' => $super_jar, 'accept' => null, 'follow' => false));
	check($r['status'] === 200, 'permission-10 superadmin allowed /tests/* (positive control)', 'status ' . $r['status']);

	section('A private file is 404 (not 403) to a stranger');
	// 404, not 403: the gate hides the file's existence rather than confirming it
	// and refusing. Minted here, fetched with no session.
	$bytes = 'routing-authz-private-' . bin2hex(random_bytes(6));
	$pfile = File::createFromBytes($bytes, 'authz_private_' . $run_id . '.txt', 'text/plain',
		$member->key, array('fil_private' => true));
	check($pfile && $pfile->key, 'fixture: private file created');
	harness_defer(function () use ($pfile) {
		if ($pfile && $pfile->key) { $pfile->permanent_delete(); }
	});

	$r = harness_request('GET', $pfile->get_url(), array('accept' => null, 'follow' => false));
	check($r['status'] === 404, 'anonymous fetch of a private file → 404 (existence hidden, not 403)',
		'status ' . $r['status']);

} finally {
	harness_teardown_data();
}

harness_finish();
