<?php
/** @joinery-test
 * name: api_scoped_key
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * Scoped machine keys: the decisions ApiAuth::authorize() makes.
 *
 * A machine key may carry apk_scope, a list of action names. A scoped key
 * reaches those actions and nothing else, and an action declaring
 * requires_scoped_key admits nothing but a key scoped to it. This drives
 * authorize() and refuseScopedKeyOutsideActions() directly with unsaved keys,
 * so it needs no database; the HTTP route families are covered by
 * tests/functional/api/scoped_keys_test.php.
 *
 * Run: php tests/unit/api_scoped_key_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

/**
 * Stand in for the real api_error(), which lives in apiv1.php and exits.
 * Declared before ApiAuth loads so the class binds to it.
 */
if (!function_exists('api_error')) {
	function api_error($message, $error_type = 'TransactionError', $status_code = 400) {
		throw new RuntimeException($status_code . '|' . $error_type . '|' . $message);
	}
}

require_once(PathHelper::getIncludePath('includes/ApiAuth.php'));

/** An unsaved key of the given type, scope and permission. */
function key_of($type, $scope = '', $permission = 1) {
	$key = new ApiKey(NULL);
	$key->set('apk_type', $type);
	$key->set('apk_scope', $scope);
	$key->set('apk_permission', $permission);
	return $key;
}

/** Run authorize() and report 'allowed', or the refusal's status and message. */
function decide(array $auth, $api_entry, $endpoint, $user_permission = 0) {
	try {
		ApiAuth::authorize($auth, $api_entry, $user_permission, 'Action', $endpoint);
		return array('allowed' => true);
	} catch (RuntimeException $e) {
		list($status, , $message) = explode('|', $e->getMessage(), 3);
		return array('allowed' => false, 'status' => (int)$status, 'message' => $message);
	}
}

function outside_actions($api_entry, $family) {
	try {
		ApiAuth::refuseScopedKeyOutsideActions($api_entry, $family);
		return array('allowed' => true);
	} catch (RuntimeException $e) {
		list($status, , $message) = explode('|', $e->getMessage(), 3);
		return array('allowed' => false, 'status' => (int)$status, 'message' => $message);
	}
}

$SNAP = 'dns_filtering/resolver_snapshot';
$READ = array('capability' => ApiAuth::CAP_READ);
$SCOPED_ONLY = array('capability' => ApiAuth::CAP_READ, 'requires_machine_key' => true,
	'requires_scoped_key' => true);

section('Reading a scope');

check(key_of(ApiKey::TYPE_MACHINE)->scope() === array(), 'An empty scope is no scope');
check(!key_of(ApiKey::TYPE_MACHINE)->is_scoped(), 'An empty scope is unscoped');
check(key_of(ApiKey::TYPE_MACHINE, " $SNAP , other_action ,, ")->scope() === array($SNAP, 'other_action'),
	'Names are trimmed and empty entries dropped');
check(key_of(ApiKey::TYPE_MACHINE, $SNAP)->is_scoped(), 'A named action makes the key scoped');

section('A scoped key reaches only its scope');

$scoped = key_of(ApiKey::TYPE_MACHINE, $SNAP);
check(decide($READ, $scoped, $SNAP)['allowed'], 'It calls the action its scope names');
$r = decide($READ, $scoped, 'notification_unread_count');
check(!$r['allowed'] && $r['status'] === 403, 'It is refused 403 on another action');
check(!$r['allowed'] && strpos($r['message'], $SNAP) !== false, 'The refusal names the scope', $r['message'] ?? '');
$r = decide($READ, $scoped, null);
check(!$r['allowed'] && $r['status'] === 403, 'It is refused on a surface with no name (CRUD, forms, management)');
$r = decide(array('requires_machine_key' => true, 'min_user_permission' => 0), $scoped, null);
check(!$r['allowed'] && $r['status'] === 403, 'It is refused on the management default contract');
check(decide($READ, key_of(ApiKey::TYPE_MACHINE, "other_action,$SNAP"), $SNAP)['allowed'],
	'A key scoped to several actions calls each of them');

section('The scope does not widen the rest of the contract');

$r = decide(array('capability' => ApiAuth::CAP_WRITE), $scoped, $SNAP);
check(!$r['allowed'] && $r['status'] === 403, 'A read-only scoped key is still refused a write action');
$r = decide(array('capability' => ApiAuth::CAP_READ, 'min_user_permission' => 5), $scoped, $SNAP, 0);
check(!$r['allowed'] && $r['status'] === 403, 'The user floor still applies inside the scope');

section('An unscoped key is unchanged');

$plain = key_of(ApiKey::TYPE_MACHINE, '', 4);
check(decide($READ, $plain, 'notification_unread_count')['allowed'], 'It calls any action its permission allows');
check(decide($READ, $plain, null)['allowed'], 'It reaches surfaces with no name');
check(outside_actions($plain, 'management')['allowed'], 'It is not confined to action dispatch');
check(outside_actions(null, 'management')['allowed'], 'A browser session is not confined to action dispatch');

section('requires_scoped_key admits only a key scoped to the action');

check(decide($SCOPED_ONLY, $scoped, $SNAP)['allowed'], 'The key scoped to it is admitted');
$r = decide($SCOPED_ONLY, $plain, $SNAP);
check(!$r['allowed'] && $r['status'] === 403, 'An unscoped machine key is refused');
$r = decide($SCOPED_ONLY, null, $SNAP, 10);
check(!$r['allowed'] && $r['status'] === 403, 'A browser session is refused, even a superadmin\'s');
$r = decide($SCOPED_ONLY, key_of(ApiKey::TYPE_MACHINE, 'other_action'), $SNAP);
check(!$r['allowed'] && $r['status'] === 403, 'A key scoped to another action is refused');
$r = decide(array('capability' => ApiAuth::CAP_READ, 'requires_scoped_key' => true),
	key_of(ApiKey::TYPE_SESSION, '', 4), $SNAP);
check(!$r['allowed'] && $r['status'] === 403, 'A session key is refused');

section('Route families outside action dispatch');

foreach (array('management', 'auth', 'app', 'drive_upload', 'actions', 'user') as $family) {
	$r = outside_actions($scoped, $family);
	check(!$r['allowed'] && $r['status'] === 403, "A scoped key is refused on $family");
}

section('Only a machine key may be scoped');

$threw = false;
try {
	key_of(ApiKey::TYPE_SESSION, $SNAP)->save();
} catch (ApiKeyException $e) {
	$threw = true;
}
check($threw, 'Saving a scoped session key is refused before it reaches the database');

harness_finish();
