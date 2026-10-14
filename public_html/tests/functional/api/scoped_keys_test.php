<?php
/**
 * Scoped machine keys over HTTP — functional test suite
 *
 * A machine key whose apk_scope names actions can call those actions and
 * nothing else; one whose scope names a model and verbs ('Location:read')
 * reaches that model's record endpoints for those verbs and nothing else. The decisions themselves are pinned offline by
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
 * @version 1.2 - a refusal names the entry the key lacked
 * @version 1.1 - record entries over HTTP
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

	section('A key scoped to a model and verbs');
	if (!class_exists('Location')) {
		harness_skip('Record entries over HTTP', 'the event_manager plugin is not active, so Location is not a record endpoint');
	} else {
		$admin = make_user($suffix . 'SR', 5);
		$rec = make_machine_key($admin->key, 'records-' . $suffix, 3);
		$rec['api_key']->set('apk_scope', 'Location:read,Location:create');
		$rec['api_key']->save();
		$hr = key_headers($rec['api_key']->get('apk_public_key'), $rec['secret_key']);

		$r = api_request('GET', '/api/v1/Locations', $hr);
		check($r['status'] === 200, 'It reads the list of its model', $r['status'] . ' ' . substr($r['raw'], 0, 200));
		$r = api_request('POST', '/api/v1/Location', $hr,
			array('loc_name' => 'HarnessTest Location ' . $suffix, 'loc_link' => 'harnesstest-location-' . strtolower($suffix)), true);
		check($r['status'] === 200, 'It creates a record of its model', $r['status'] . ' ' . substr($r['raw'], 0, 300));
		$location_id = (int)($r['json']['data']['loc_location_id'] ?? 0);
		check($location_id > 0, 'The create answers with the new record\'s id', substr($r['raw'], 0, 300));
		if ($location_id > 0) {
			harness_register_row('loc_locations', 'loc_location_id', $location_id);
			$r = api_request('GET', '/api/v1/Location/' . $location_id, $hr);
			check($r['status'] === 200, 'It reads one record of its model', $r['status']);

			$r = api_request('PUT', '/api/v1/Location/' . $location_id . '?loc_name=changed', $hr);
			check($r['status'] === 403, 'Updating is refused: the scope gives read and create only', $r['status'] . ' ' . substr($r['raw'], 0, 200));
			check(strpos((string)($r['json']['error'] ?? ''), "'Location:update'") !== false,
				'and the refusal names the entry it lacked', $r['json']['error'] ?? $r['raw']);
			$r = api_request('DELETE', '/api/v1/Location/' . $location_id, $hr);
			check($r['status'] === 403, 'Deleting is refused', $r['status'] . ' ' . substr($r['raw'], 0, 200));
			$still = new Location($location_id, TRUE);
			check($still->get('loc_name') === 'HarnessTest Location ' . $suffix && !$still->get('loc_delete_time'),
				'The refused update and delete changed nothing');
		}

		// The key acts as its owner, so the owner cannot be deleted under it,
		// and the refusal says where the key is deleted.
		$refused = '';
		try {
			$admin->soft_delete();
		} catch (SystemDisplayableError $e) {
			$refused = $e->getMessage();
		}
		check(strpos($refused, '/admin/admin_api_key?apk_api_key_id=' . (int)$rec['api_key']->key) !== false
			&& strpos($refused, 'Location: read, create') !== false,
			'Deleting the key\'s owner is refused, naming the key\'s page and its scope', $refused);

		$record_refusals = array(
			'another model'      => array('GET', '/api/v1/Users', null),
			'another model, one' => array('GET', '/api/v1/User/' . $admin->key, null),
			'an action'          => array('POST', '/api/v1/action/notification_unread_count', array()),
			'the management API' => array('GET', '/api/v1/management', null),
			'the actions listing'=> array('GET', '/api/v1/actions', null),
			'auth/session'       => array('GET', '/api/v1/auth/session', null),
			'a form definition'  => array('GET', '/api/v1/form/account_edit', null),
		);
		$r = api_request('GET', '/api/v1/Users', $hr);
		check(strpos((string)($r['json']['error'] ?? ''), "'User:read'") !== false,
			'Another model\'s refusal names the entry a read of it would need', $r['json']['error'] ?? $r['raw']);
		foreach ($record_refusals as $label => $req) {
			$r = api_request($req[0], $req[1], $hr, $req[2]);
			check($r['status'] === 403, "Refused 403 on $label", $r['status'] . ' ' . substr($r['raw'], 0, 200));
			check(strpos((string)($r['json']['error'] ?? ''), 'Location: read, create') !== false,
				"The refusal on $label names the scope", $r['json']['error'] ?? $r['raw']);
		}
	}

	section('Authentication never re-validates a stored key');
	// touch_last_used() runs inside authenticate(); a full save() there would
	// refuse a row that predates a newer rule and fail the request.
	$odd = make_machine_key($user->key, 'odd-' . $suffix, 1);
	ApiKey::updateColumns((int)$odd['api_key']->key, array(
		'apk_permission'     => null,
		'apk_scope'          => 'Not An Entry',
		'apk_last_used_time' => gmdate('Y-m-d H:i:s', time() - 7200),
	));
	$loaded = new ApiKey((int)$odd['api_key']->key, TRUE);
	$threw = '';
	try {
		$loaded->touch_last_used('192.0.2.7');
	} catch (\Throwable $e) {
		$threw = get_class($e) . ': ' . $e->getMessage();
	}
	$after = new ApiKey((int)$odd['api_key']->key, TRUE);
	check($threw === '' && strtotime($after->get('apk_last_used_time') . ' UTC') > time() - 60,
		'Stamping last-used on a row no save() would accept still works', $threw);
	check($after->get('apk_permission') === null && $after->get('apk_scope') === 'Not An Entry',
		'and writes nothing but the stamp');

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
