<?php
/**
 * Scoped machine keys over HTTP — functional test suite
 *
 * A machine key whose apk_scope names actions can call those actions and
 * nothing else. The decisions themselves are pinned offline by
 * tests/unit/api_scoped_key_test.php; this suite proves the wiring over real
 * requests: that action dispatch passes the action's name to
 * ApiAuth::authorize(), and that every other route family refuses the key,
 * including the ones that never reach authorize() (auth/*, app/*,
 * drive_upload, the GET actions listing).
 *
 * requires_scoped_key over HTTP is proved by the one action that declares it,
 * in plugins/dns_filtering/tests/resolver_snapshot_test.php.
 *
 * USAGE (CLI only):
 *   php tests/functional/api/scoped_keys_test.php [base_url] [origin_ip]
 *
 * Creates its own user and keys and removes them afterwards. Requires the
 * apk_scope column (run update_database after deploying the feature).
 *
 * @version 1.0
 */

/** @joinery-test
 * name: api_scoped_keys
 * tier: db
 * env: dev-only
 * needs: []
 */
require_once(__DIR__ . '/api_test_harness.php');
api_test_boot($argv);

try {
	$suffix = strtoupper(LibraryFunctions::random_string(6));

	section('Setup');
	$user = make_user($suffix . 'SC');
	$scoped = make_machine_key($user->key, 'scoped-' . $suffix, 1);
	$scoped['api_key']->set('apk_scope', 'notification_unread_count');
	$scoped['api_key']->save();
	$plain = make_machine_key($user->key, 'plain-' . $suffix, 1);
	$h = key_headers($scoped['api_key']->get('apk_public_key'), $scoped['secret_key']);
	$hp = key_headers($plain['api_key']->get('apk_public_key'), $plain['secret_key']);

	$reloaded = new ApiKey($scoped['api_key']->key, TRUE);
	check($reloaded->scope() === array('notification_unread_count'), 'The scope is stored and read back');

	section('The scoped key calls its action');
	$r = api_request('POST', '/api/v1/action/notification_unread_count', $h, array());
	check($r['status'] === 200, 'Its own action answers 200', $r['raw']);

	section('Every other surface refuses it, naming the scope');
	$refusals = array(
		'another action'        => array('POST', '/api/v1/action/reaction_count', array('entity_type' => 'post', 'entity_id' => 1)),
		'a CRUD read'           => array('GET', '/api/v1/Users', null),
		'a CRUD object read'    => array('GET', '/api/v1/User/' . $user->key, null),
		'the management API'    => array('GET', '/api/v1/management', null),
		'the actions listing'   => array('GET', '/api/v1/actions', null),
		'auth/session'          => array('GET', '/api/v1/auth/session', null),
		'app/navigation'        => array('GET', '/api/v1/app/navigation', null),
		'drive_upload'          => array('GET', '/api/v1/drive_upload/notatoken', null),
		'a form definition'     => array('GET', '/api/v1/form/account_edit', null),
	);
	foreach ($refusals as $label => $req) {
		$r = api_request($req[0], $req[1], $h, $req[2]);
		check($r['status'] === 403, "Refused 403 on $label", $r['status'] . ' ' . substr($r['raw'], 0, 200));
		check(strpos((string)($r['json']['error'] ?? ''), 'notification_unread_count') !== false,
			"The refusal on $label names the scope", $r['json']['error'] ?? $r['raw']);
	}

	section('The same user\'s unscoped key is unaffected');
	$r = api_request('POST', '/api/v1/action/notification_unread_count', $hp, array());
	check($r['status'] === 200, 'An unscoped key calls the action', $r['raw']);
	$r = api_request('GET', '/api/v1/actions', $hp);
	check($r['status'] === 200, 'An unscoped key reads the actions listing', $r['raw']);
	$r = api_request('GET', '/api/v1/auth/session', $hp);
	check($r['status'] === 200, 'An unscoped key reads auth/session', $r['raw']);

} catch (\Throwable $e) {
	check(false, 'unhandled exception mid-suite', $e->getMessage());
	echo "\nEXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
	section('Cleanup');
	harness_teardown_data();
}

harness_finish();
