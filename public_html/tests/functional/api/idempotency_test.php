<?php
/**
 * Idempotent action writes — functional test suite
 *
 * Covers specs/implemented/api_contract_and_idempotency.md § Change 2 (pinned in
 * docs/api.md § Contract — Idempotent writes) over the key-credential path;
 * browser_session_test.php § 1b covers the user-scoped (browser session) path.
 *
 * What is exercised:
 *   - First request with an Idempotency-Key executes and stores its outcome.
 *   - A retry (same key, same body) replays the stored response byte-for-byte
 *     and does NOT re-execute (proven by mutating the row out-of-band between
 *     the two requests).
 *   - The same key with a different body → 409 ActionError.
 *   - Keys are scoped per credential: a second user reusing the same key
 *     string executes independently.
 *   - Requests without the header keep executing every time (baseline).
 *   - Expiry: a backdated row is removed by the PurgeIdempotencyKeys task,
 *     after which the same key executes fresh.
 *
 * USAGE (CLI only):
 *   php tests/functional/api/idempotency_test.php [base_url] [origin_ip]
 *
 * Creates its own users / keys / rows and removes them afterwards.
 */

/** @joinery-test
 * name: api_idempotency
 * tier: db
 * env: dev-only
 * needs: []
 */
require_once(__DIR__ . '/api_test_harness.php');
api_test_boot($argv);

require_once(PathHelper::getIncludePath('data/api_idempotency_keys_class.php'));
require_once(PathHelper::getIncludePath('tasks/PurgeIdempotencyKeys.php'));

// Register every idempotency row in a credential scope for teardown.
function register_aik_rows($scope) {
	$rows = new MultiApiIdempotencyKey(array('credential_scope' => $scope));
	$rows->load();
	foreach ($rows as $row) {
		harness_register_row(ApiIdempotencyKey::$tablename, ApiIdempotencyKey::$pkey_column, $row->key);
	}
}

try {
	$suffix = strtoupper(LibraryFunctions::random_string(6));
	echo "Base URL: $BASE_URL\nTest suffix: $suffix\n";

	// ------------------------------------------------------------------
	section('Setup');
	$user_a = make_user($suffix . 'A');
	$user_b = make_user($suffix . 'B');
	$ka = make_machine_key($user_a->key, 'idem-a-' . $suffix, 4);
	$kb = make_machine_key($user_b->key, 'idem-b-' . $suffix, 4);
	$ha = key_headers($ka['api_key']->get('apk_public_key'), $ka['secret_key']);
	$hb = key_headers($kb['api_key']->get('apk_public_key'), $kb['secret_key']);
	$scope_a = 'key:' . $ka['api_key']->key;
	$scope_b = 'key:' . $kb['api_key']->key;

	$idem_key = 'idem-' . strtolower($suffix) . '-op1';
	$body = array('usr_first_name' => 'IdemFirst', 'usr_last_name' => 'Run' . $suffix,
		'usr_timezone' => 'America/Chicago');

	// ------------------------------------------------------------------
	section('1. First request executes and stores its outcome');
	$r1 = api_request('POST', '/api/v1/action/account_edit', array_merge($ha,
		array('Idempotency-Key: ' . $idem_key)), $body);
	check($r1['status'] === 200, 'first request with Idempotency-Key → 200', $r1['raw']);
	$user_a->load();
	check($user_a->get('usr_first_name') === 'IdemFirst', 'action executed (DB updated)');
	$stored = ApiIdempotencyKey::find(hash('sha256', $idem_key), $scope_a);
	check($stored !== null, 'outcome row exists for the key + credential scope');
	check((int)$stored->get('aik_response_status') === 200, 'stored status is 200',
		'got ' . $stored->get('aik_response_status'));

	// ------------------------------------------------------------------
	section('2. Retry replays without re-executing');
	// Mutate out-of-band; a re-execution would overwrite this.
	$user_a->set('usr_first_name', 'MutatedBetween');
	$user_a->save();
	$r2 = api_request('POST', '/api/v1/action/account_edit', array_merge($ha,
		array('Idempotency-Key: ' . $idem_key)), $body);
	check($r2['status'] === 200, 'retry → 200', $r2['raw']);
	check($r2['raw'] === $r1['raw'], 'retry response is byte-identical to the original');
	$user_a->load();
	check($user_a->get('usr_first_name') === 'MutatedBetween', 'retry did NOT re-execute (DB untouched)');

	// ------------------------------------------------------------------
	section('3. Same key, different body → 409');
	$r = api_request('POST', '/api/v1/action/account_edit', array_merge($ha,
		array('Idempotency-Key: ' . $idem_key)),
		array('usr_first_name' => 'DifferentBody'));
	check($r['status'] === 409, 'different body under the same key → 409', 'status ' . $r['status'] . ' ' . $r['raw']);
	check(($r['json']['errortype'] ?? '') === 'ActionError', '409 errortype is ActionError', $r['raw']);
	check(strpos($r['raw'], 'already used') !== false, '409 names the key reuse', $r['raw']);
	$user_a->load();
	check($user_a->get('usr_first_name') === 'MutatedBetween', 'conflicting request did not execute');

	// ------------------------------------------------------------------
	section('4. Keys are scoped per credential');
	$r = api_request('POST', '/api/v1/action/account_edit', array_merge($hb,
		array('Idempotency-Key: ' . $idem_key)), $body);
	check($r['status'] === 200, 'user B reusing the same key string → 200 (own scope)', $r['raw']);
	$user_b->load();
	check($user_b->get('usr_first_name') === 'IdemFirst', 'B\'s request executed for B');
	check(ApiIdempotencyKey::find(hash('sha256', $idem_key), $scope_b) !== null,
		'B has an independent outcome row in B\'s scope');

	// ------------------------------------------------------------------
	section('5. No header → executes every time (baseline unchanged)');
	$nb = array('usr_first_name' => 'NoHeader', 'usr_last_name' => 'Run' . $suffix,
		'usr_timezone' => 'America/Chicago');
	$r = api_request('POST', '/api/v1/action/account_edit', $ha, $nb);
	check($r['status'] === 200, 'headerless request 1 → 200', $r['raw']);
	$user_a->set('usr_first_name', 'MutatedAgain');
	$user_a->save();
	$r = api_request('POST', '/api/v1/action/account_edit', $ha, $nb);
	check($r['status'] === 200, 'headerless request 2 → 200', $r['raw']);
	$user_a->load();
	check($user_a->get('usr_first_name') === 'NoHeader',
		'headerless repeat re-executed (DB overwritten both times)');

	// ------------------------------------------------------------------
	section('6. Expiry: the purge task removes old keys, then the key is fresh');
	$stored->load();
	$stored->set('aik_create_time', LibraryFunctions::time_shift(gmdate('Y-m-d H:i:s'), '-25 hours', 'Y-m-d H:i:s'));
	$stored->save();
	$task = new PurgeIdempotencyKeys();
	$result = $task->run(array('hours_to_keep' => 24));
	check(($result['status'] ?? '') === 'success', 'purge task ran', json_encode($result));
	check(ApiIdempotencyKey::find(hash('sha256', $idem_key), $scope_a) === null,
		'backdated row was purged');
	$r = api_request('POST', '/api/v1/action/account_edit', array_merge($ha,
		array('Idempotency-Key: ' . $idem_key)), $body);
	check($r['status'] === 200, 'expired key executes fresh → 200', $r['raw']);
	$user_a->load();
	check($user_a->get('usr_first_name') === 'IdemFirst', 'fresh execution reached the DB');

} catch (Exception $e) {
	$failed++;
	echo "\nEXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
	section('Cleanup');
	if (isset($scope_a)) register_aik_rows($scope_a);
	if (isset($scope_b)) register_aik_rows($scope_b);
	harness_teardown_data();
}

harness_finish();
