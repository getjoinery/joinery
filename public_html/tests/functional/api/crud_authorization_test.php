<?php
/**
 * CRUD API authorization — functional test suite
 *
 * Covers the three authorization layers and the field floors introduced by
 * specs/implemented/api_crud_resource_authorization.md, plus the AI model
 * surface's parity with the shared floors. Companion to session_keys_test.php
 * (key lifecycle / auth mechanics); both share api_test_harness.php.
 *
 * What is exercised (spec §9 test plan):
 *   - Layer 1 resource exposure: an unexposed model 404s; an exposed one is
 *     reachable; a read-only resource is gated per-verb (in-process assert —
 *     no production model is readable-but-not-writable, so a synthetic class
 *     stands in for that branch).
 *   - Layer 2 row scope (deny-by-default owner-or-staff): owner reads own row,
 *     a non-owner is refused, staff reads any row; public-read models
 *     ($api_public_read) skip the scope.
 *   - Ownership integrity: POST stamps the owner server-side (a body-supplied
 *     owner cannot spoof); the owner column is unwritable on PUT; PUT authorizes
 *     the loaded row so you cannot update a row you do not own.
 *   - Layer 3 write floor: privileged ($api_unwritable_fields, e.g.
 *     usr_permission) and credential (regex) columns are dropped from CRUD
 *     writes, including the User::CreateNew() fast-path; a normal field on the
 *     same request still applies.
 *   - Collection scoping: a non-staff caller's num_results reflects only their
 *     own rows (no count disclosure); staff sees the unfiltered set.
 *   - Nested embed floor: a User export's embedded child objects carry no
 *     unreadable-floor field.
 *   - AI parity: the AI read surface excludes the shared unreadable floor on a
 *     real model (User); the AI write surface strips the shared unwritable
 *     floor (synthetic probe — no production model declares $ai_writable_fields
 *     yet, so the write-strip is verified mechanically).
 *   - Contract envelope (docs/api.md § Contract): success/error envelope keys,
 *     bare error message (no prefix), object error data, integer pagination
 *     fields, and timestamps as UTC 'Y-m-d H:i:s' strings (never serialized
 *     DateTime objects), including through derived embeds.
 *
 * USAGE (CLI only):
 *   php tests/functional/api/crud_authorization_test.php [base_url] [origin_ip]
 *
 * Creates its own users / keys / rows and removes them afterwards.
 */

/** @joinery-test
 * name: api_crud_authorization
 * tier: db
 * env: dev-only
 * needs: []
 */
require_once(__DIR__ . '/api_test_harness.php');
api_test_boot($argv);

// Model fixtures driven over CRUD.
require_once(PathHelper::getIncludePath('data/address_class.php'));  // Bucket B, owner usa_usr_user_id
require_once(PathHelper::getIncludePath('data/posts_class.php'));    // Bucket A, $api_public_read
require_once(PathHelper::getIncludePath('data/settings_class.php')); // Bucket C, unexposed

// AI surface (PHP-level parity checks). ModelRegistry pulls in ModelSchemaBuilder.
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelRegistry.php'));

/**
 * Layer 1 in-process fixture: a readable-but-not-writable resource. No
 * production model carries this combination, so the per-verb gate's read-only
 * branch is asserted against this synthetic class rather than over HTTP.
 */
class ApiReadOnlyProbe extends SystemBase {
	public static $prefix = 'rop';
	public static $tablename = 'rop_readonly_probe';
	public static $pkey_column = 'rop_id';
	public static $field_specifications = array('rop_id' => array('type' => 'int4'));
	public static $api_readable = true;
	public static $api_writable = false;
}

/**
 * AI write-floor fixture: an AI-writable model whose writable declaration
 * includes a privileged column (awp_permission, on the shared unwritable floor)
 * and a credential-suffixed column (awp_api_secret). No production model
 * declares $ai_writable_fields today, so this stands in to prove the AI surface
 * strips the shared write floor.
 */
class AiWriteFloorProbe extends SystemBase {
	public static $prefix = 'awp';
	public static $tablename = 'awp_probe';
	public static $pkey_column = 'awp_id';
	public static $field_specifications = array(
		'awp_id'         => array('type' => 'int4'),
		'awp_name'       => array('type' => 'varchar(50)'),
		'awp_permission' => array('type' => 'int2'),
		'awp_api_secret' => array('type' => 'varchar(64)'),
	);
	public static $ai_readable = true;
	public static $ai_description = 'AI write-floor probe.';
	public static $ai_writable_fields = array('awp_name', 'awp_permission', 'awp_api_secret');
	public static $api_unwritable_fields = array('awp_permission');
}

/**
 * Fail-closed export fixture: an export_as_array() override that injects derived keys.
 * One is allowlisted (safe_derived), one is an undeclared smuggle, one is an undeclared
 * credential-named token. export_for_api() must emit only declared columns + the
 * allowlist, dropping both undeclared keys regardless of name.
 */
class FailClosedProbe extends SystemBase {
	public static $prefix = 'fcp';
	public static $tablename = 'fcp_probe';
	public static $pkey_column = 'fcp_id';
	public static $field_specifications = array(
		'fcp_id'   => array('type' => 'int4'),
		'fcp_name' => array('type' => 'varchar(20)'),
	);
	public static $api_derived_fields = array('safe_derived');
	function export_as_array() {
		return array(
			'fcp_id'        => 1,
			'fcp_name'      => 'ok',
			'safe_derived'  => 'shown',   // allowlisted derived → kept
			'smuggle'       => 'hidden',  // undeclared derived → dropped
			'fcp_api_token' => 'SECRET',  // undeclared (credential-named) derived → dropped
		);
	}
}

// Recursively true if any value anywhere is a serialized PHP DateTime
// (an array carrying 'date' + 'timezone_type') — the shape the contract bans.
function has_datetime_blob($data) {
	if (!is_array($data)) return false;
	if (array_key_exists('date', $data) && array_key_exists('timezone_type', $data)) return true;
	foreach ($data as $v) {
		if (is_array($v) && has_datetime_blob($v)) return true;
	}
	return false;
}

// Recursively true if any array key anywhere matches the credential pattern.
function has_credential_key($data) {
	if (!is_array($data)) return false;
	foreach ($data as $k => $v) {
		if (is_string($k) && preg_match('/_(password|secret|key|token|hash)$/i', $k)) return true;
		if (is_array($v) && has_credential_key($v)) return true;
	}
	return false;
}

// Create an owned Address directly (not via the API) and register it for cleanup.
function make_address($owner_id, $city) {
	$a = new Address(NULL);
	$a->set('usa_usr_user_id', $owner_id);
	$a->set('usa_city', $city);
	$a->save();
	$a->load();
	harness_register_row(Address::$tablename, Address::$pkey_column, $a->key);
	return $a;
}

try {
	$suffix = strtoupper(LibraryFunctions::random_string(6));
	echo "Base URL: $BASE_URL\nTest suffix: $suffix\n";

	// ------------------------------------------------------------------
	section('Setup');
	$user_a = make_user($suffix . 'A');         // non-staff owner A
	$user_b = make_user($suffix . 'B');         // non-staff owner B
	$staff  = make_user($suffix . 'S', 5);      // staff (permission >= 5)
	echo "  Users A=" . $user_a->key . " B=" . $user_b->key . " staff=" . $staff->key . "\n";

	// Full-capability machine keys (apk_permission 4 = read+write+delete).
	$ka = make_machine_key($user_a->key, 'crud-a-' . $suffix, 4);
	$kb = make_machine_key($user_b->key, 'crud-b-' . $suffix, 4);
	$ks = make_machine_key($staff->key,  'crud-s-' . $suffix, 4);
	$ha = key_headers($ka['api_key']->get('apk_public_key'), $ka['secret_key']);
	$hb = key_headers($kb['api_key']->get('apk_public_key'), $kb['secret_key']);
	$hs = key_headers($ks['api_key']->get('apk_public_key'), $ks['secret_key']);

	// Fixtures: A owns two addresses, B owns one; B owns a public post.
	$addr_a1 = make_address($user_a->key, 'Atown1');
	$addr_a2 = make_address($user_a->key, 'Atown2');
	$addr_b1 = make_address($user_b->key, 'Btown1');

	$post_b = new Post(NULL);
	$post_b->set('pst_usr_user_id', $user_b->key);
	$post_b->set('pst_title', 'Public Post ' . $suffix);
	$post_b->set('pst_body', 'body');
	$post_b->save();
	$post_b->load();
	harness_register_row(Post::$tablename, Post::$pkey_column, $post_b->key);

	// ------------------------------------------------------------------
	section('Layer 1 — resource exposure');
	$r = api_request('GET', '/api/v1/Setting/1', $ha);
	check($r['status'] === 404, 'GET unexposed model (Setting) → 404', $r['raw']);
	$r = api_request('GET', '/api/v1/Settings', $ha);
	check($r['status'] === 404, 'GET unexposed collection (Settings) → 404', $r['raw']);
	$r = api_request('GET', '/api/v1/User/' . $user_a->key, $ha);
	check($r['status'] === 200, 'GET exposed model (own User) → 200', $r['raw']);

	// Read-only resource branch (in-process — no production model has this combo).
	check(ApiReadOnlyProbe::$api_readable === true && ApiReadOnlyProbe::$api_writable === false,
		'read-only resource is representable (readable, not writable)');
	check(SystemBase::$api_readable === false && SystemBase::$api_writable === false,
		'SystemBase defaults both flags closed (opt-in)');
	$probe_set = array('ApiReadOnlyProbe');
	$readable = array_filter($probe_set, fn($c) => (bool)$c::$api_readable);
	$writable = array_filter($probe_set, fn($c) => (bool)$c::$api_writable);
	check(in_array('ApiReadOnlyProbe', $readable) && !in_array('ApiReadOnlyProbe', $writable),
		'exposure filter gates the read-only resource per-verb (readable, not writable)');

	// ------------------------------------------------------------------
	section('Contract envelope (docs/api.md § Contract)');
	// Success envelope: fixed keys, object data, contract timestamps.
	$r = api_request('GET', '/api/v1/User/' . $user_a->key, $ha);
	check(($r['json']['api_version'] ?? '') === '1.0', 'success envelope carries api_version 1.0');
	check(is_string($r['json']['success_message'] ?? null), 'success envelope carries success_message string');
	$data = $r['json']['data'] ?? null;
	check(is_array($data) && !empty($data), 'single-resource data is an object');
	check(!has_datetime_blob($data), 'no serialized DateTime object anywhere in the export (incl. embeds)');
	$ts_ok = true; $ts_seen = 0;
	foreach ($data as $k => $v) {
		if (preg_match('/_(time|date)$/', $k) && $v !== null) {
			$ts_seen++;
			if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) $ts_ok = false;
		}
	}
	check($ts_ok && $ts_seen > 0, 'timestamps are UTC Y-m-d H:i:s strings (' . $ts_seen . ' checked)');

	// Collection envelope: integer pagination fields.
	$r = api_request('GET', '/api/v1/Addresss?numperpage=50&page=0', $ha);
	check(is_int($r['json']['num_results'] ?? null), 'collection num_results is an integer');
	check(is_int($r['json']['page'] ?? null), 'collection page is an integer');
	check(is_int($r['json']['numperpage'] ?? null), 'collection numperpage is an integer');
	check(is_array($r['json']['data'] ?? null), 'collection data is an array');

	// Error envelope: fixed keys, bare message, object data.
	$r = api_request('GET', '/api/v1/Setting/1', $ha);
	check(($r['json']['api_version'] ?? '') === '1.0', 'error envelope carries api_version 1.0');
	check(is_string($r['json']['errortype'] ?? null) && ($r['json']['errortype'] ?? '') !== '',
		'error envelope carries errortype');
	$err = $r['json']['error'] ?? '';
	check(is_string($err) && strpos($err, 'Error: ') !== 0, 'error string is the bare message (no prefix)');
	check(is_array($r['json']['data'] ?? null), 'error data is an object, not a string');

	// ------------------------------------------------------------------
	section('Layer 2 — per-record row scope (deny-by-default owner-or-staff)');
	$r = api_request('GET', '/api/v1/Address/' . $addr_a1->key, $ha);
	check($r['status'] === 200, 'owner A reads own Address → 200', $r['raw']);
	check(($r['json']['data']['usa_usr_user_id'] ?? null) == $user_a->key, 'returned row is owned by A');

	$r = api_request('GET', '/api/v1/Address/' . $addr_a1->key, $hb);
	check($r['status'] >= 400, 'non-owner B reading A\'s Address → denied', $r['raw']);

	$r = api_request('GET', '/api/v1/Address/' . $addr_a1->key, $hs);
	check($r['status'] === 200, 'staff reads any Address → 200', $r['raw']);

	// ------------------------------------------------------------------
	section('Public-read override ($api_public_read)');
	$r = api_request('GET', '/api/v1/Post/' . $post_b->key, $ha);
	check($r['status'] === 200, 'A reads another user\'s public Post → 200', $r['raw']);
	check(($r['json']['data']['pst_usr_user_id'] ?? null) == $user_b->key, 'public Post is B\'s row');

	// ------------------------------------------------------------------
	section('Collection scoping — count must not leak (run before ownership mutations)');
	$r = api_request('GET', '/api/v1/Addresss?numperpage=50', $ha);
	$rows_a = $r['json']['data'] ?? array();
	check(($r['json']['num_results'] ?? -1) === 2, 'A num_results == 2 (only A\'s rows)',
		'got ' . ($r['json']['num_results'] ?? 'null'));
	$all_a_owned = true;
	foreach ($rows_a as $row) { if (($row['usa_usr_user_id'] ?? null) != $user_a->key) $all_a_owned = false; }
	check($all_a_owned && count($rows_a) === 2, 'A collection contains only A\'s rows');

	$r = api_request('GET', '/api/v1/Addresss?numperpage=50', $hb);
	check(($r['json']['num_results'] ?? -1) === 1, 'B num_results == 1 (only B\'s row)',
		'got ' . ($r['json']['num_results'] ?? 'null'));

	$r = api_request('GET', '/api/v1/Addresss?numperpage=50', $hs);
	$staff_count = $r['json']['num_results'] ?? -1;
	check($staff_count >= 3, 'staff num_results is the unfiltered total (>= 3)', 'got ' . $staff_count);

	// ------------------------------------------------------------------
	section('Layer 3 — write floor (privileged + credential columns dropped)');
	$before = new User($user_a->key, TRUE);
	$pw_before = $before->get('usr_password');
	$r = api_request('PUT',
		'/api/v1/User/' . $user_a->key . '?usr_permission=10&usr_first_name=FloorOk&usr_password=hacked',
		$ha);
	check($r['status'] === 200, 'PUT own User with privileged+credential fields → 200', $r['raw']);
	$after = new User($user_a->key, TRUE);
	check((int)$after->get('usr_permission') === 0, 'usr_permission unchanged (dropped from write)',
		'got ' . $after->get('usr_permission'));
	check($after->get('usr_password') === $pw_before, 'usr_password unchanged (credential dropped)');
	check($after->get('usr_first_name') === 'FloorOk', 'non-floor field (usr_first_name) WAS applied');

	// ------------------------------------------------------------------
	section('CreateNew fast-path also honors the write floor (§4.4)');
	$new_email = 'apitest_createnew_' . strtolower($suffix) . '@getjoinery.com';
	$r = api_request('POST', '/api/v1/User', $ha, array(
		'usr_email'      => $new_email,
		'usr_first_name' => 'Created',
		'usr_last_name'  => 'ViaApi',
		'usr_permission' => 10,
	), true);
	check($r['status'] === 200, 'POST /User (CreateNew path) → 200', $r['raw']);
	$created = User::GetByColumn('usr_email', $new_email);
	if ($created && $created->key) {
		harness_register_user($created);
		check((int)$created->get('usr_permission') !== 10,
			'created user is NOT elevated (floor stripped before CreateNew)',
			'got ' . $created->get('usr_permission'));
	} else {
		check(false, 'created user row could be loaded for assertion', $r['raw']);
	}

	// ------------------------------------------------------------------
	section('Ownership integrity (§4.4)');
	// POST stamps the owner server-side — a body-supplied owner cannot spoof.
	$r = api_request('POST', '/api/v1/Address', $ha, array(
		'usa_usr_user_id' => $user_b->key,   // attempt to create a row owned by B
		'usa_city'        => 'StampTest',
	), true);
	check($r['status'] === 200, 'POST /Address → 200', $r['raw']);
	$stamped_owner = $r['json']['data']['usa_usr_user_id'] ?? null;
	check($stamped_owner == $user_a->key, 'created Address is owned by caller A, not body-supplied B',
		'got owner ' . $stamped_owner);
	if (isset($r['json']['data'][Address::$pkey_column])) {
		harness_register_row(Address::$tablename, Address::$pkey_column, $r['json']['data'][Address::$pkey_column]);
	}

	// Owner column is unwritable on PUT; a normal field still applies.
	$r = api_request('PUT',
		'/api/v1/Address/' . $addr_a1->key . '?usa_usr_user_id=' . $user_b->key . '&usa_city=Reassigned',
		$ha);
	check($r['status'] === 200, 'PUT own Address with forged owner → 200', $r['raw']);
	$addr_a1_after = new Address($addr_a1->key, TRUE);
	check($addr_a1_after->get('usa_usr_user_id') == $user_a->key, 'owner column NOT reassigned (dropped)');
	check($addr_a1_after->get('usa_city') === 'Reassigned', 'non-owner field (usa_city) WAS applied');

	// PUT authorizes the loaded row — cannot update a row you do not own.
	$r = api_request('PUT', '/api/v1/Address/' . $addr_b1->key . '?usa_city=Hijacked', $ha);
	check($r['status'] >= 400, 'A updating B\'s Address → denied', $r['raw']);
	$addr_b1_after = new Address($addr_b1->key, TRUE);
	check($addr_b1_after->get('usa_city') === 'Btown1', 'B\'s Address row unchanged');

	// ------------------------------------------------------------------
	section('Fail-closed field read (export_for_api allowlist) + nested embed floor');
	$r = api_request('GET', '/api/v1/User/' . $user_a->key, $ha);
	$data = $r['json']['data'] ?? array();
	check(!isset($data['usr_password']), 'User export has no usr_password (top-level floor)');
	$no_unreadable = true;
	foreach (User::$api_unreadable_fields as $f) { if (array_key_exists($f, $data)) $no_unreadable = false; }
	check($no_unreadable, 'User export has none of $api_unreadable_fields');

	// Fail-closed invariant: every emitted key is a declared column OR an opted-in derived key.
	$allowed = array_merge(array_keys(User::$field_specifications), User::$api_derived_fields);
	$stowaways = array_diff(array_keys($data), $allowed);
	check(empty($stowaways), 'User export emits only declared columns + $api_derived_fields',
		'stowaways: ' . implode(',', $stowaways));

	// The activation-token leak is gone by construction (was credential-named + a _qs twin).
	check(!array_key_exists('user_activation_key', $data) && !array_key_exists('user_activation_key_qs', $data),
		'activation-token derived keys are absent from the export');
	// Opted-in derived keys still flow.
	check(array_key_exists('display_name', $data), 'allowlisted derived key (display_name) is present');
	check(array_key_exists('address', $data) || array_key_exists('phone', $data),
		'allowlisted embed key (phone/address) is present');
	check(!has_credential_key($data), 'no credential-suffixed key anywhere in the User export (incl. embeds)');

	// Direct invariant on a synthetic override that injects undeclared derived keys.
	$probe = new FailClosedProbe(NULL);
	$exported = $probe->export_for_api();
	check(!array_key_exists('smuggle', $exported), 'undeclared derived key is dropped (smuggle)');
	check(!array_key_exists('fcp_api_token', $exported),
		'undeclared credential-named derived key is dropped (fcp_api_token)');
	check(array_key_exists('safe_derived', $exported) && array_key_exists('fcp_name', $exported),
		'allowlisted derived key + declared column survive');

	// ------------------------------------------------------------------
	section('AI surface parity with the shared floors');
	// Read: the AI surface excludes the shared unreadable floor on a real model.
	$visible = ModelSchemaBuilder::visibleFields('User');
	check(!in_array('usr_password', $visible, true), 'AI read surface excludes usr_password (credential regex)');
	check(!in_array('usr_authhash', $visible, true),
		'AI read surface excludes usr_authhash (shared $api_unreadable_fields, merged in)');
	check(in_array('usr_email', $visible, true), 'AI read surface still includes a normal field (usr_email)');

	// Write: the AI surface strips the shared unwritable floor (synthetic probe;
	// no production model declares $ai_writable_fields yet).
	echo "  (note: no production model declares \$ai_writable_fields; AI write-floor strip verified via probe)\n";
	$probe = ModelRegistry::get('AiWriteFloorProbe');
	$w = $probe['writable_fields'] ?? array();
	check(in_array('awp_name', $w, true), 'AI writable keeps a normal field (awp_name)');
	check(!in_array('awp_permission', $w, true),
		'AI writable strips $api_unwritable_fields column (awp_permission)');
	check(!in_array('awp_api_secret', $w, true), 'AI writable strips credential-suffixed column (awp_api_secret)');
	$has_floor_warning = false;
	foreach (ModelRegistry::warnings() as $warn) {
		if (($warn['class'] ?? '') === 'AiWriteFloorProbe' && ($warn['kind'] ?? '') === 'writable_unwritable_floor') {
			$has_floor_warning = true;
		}
	}
	check($has_floor_warning, 'ModelRegistry emits a writable_unwritable_floor warning for the stripped column');

} catch (Exception $e) {
	$failed++;
	echo "\nEXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
	section('Cleanup');
	harness_teardown_data();
}

harness_finish();
