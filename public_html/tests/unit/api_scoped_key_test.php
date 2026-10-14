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
 * A machine key may carry apk_scope, a list of entries: action names, and
 * record entries ('Event:read') naming a model and one verb. A scoped key
 * reaches those and nothing else, and an action declaring
 * requires_scoped_key admits nothing but a key scoped to it. This drives
 * authorize() and refuseScopedKeyOutsideItsScope() directly with unsaved
 * keys, so it needs no database; the HTTP route families are covered by
 * tests/functional/api/scoped_keys_test.php.
 *
 * It also holds the rule refuseScopedKeyOutsideItsScope() leans on: no model
 * shares a name with a route family apiv1.php dispatches, so a key scoped to
 * a model can never be let through to a family by name.
 *
 * Run: php tests/unit/api_scoped_key_test.php
 *
 * @version 1.2 - a refusal names what the key lacked, and a sandboxed plugin's where its manifest asks
 * @version 1.1 - record entries, the scope grammar, the permission a scope
 *   needs, and the model/route-family name rule
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
function decide(array $auth, $api_entry, $endpoint, $user_permission = 0, $record = null) {
	try {
		ApiAuth::authorize($auth, $api_entry, $user_permission, 'Action', $endpoint, $record);
		return array('allowed' => true);
	} catch (RuntimeException $e) {
		list($status, , $message) = explode('|', $e->getMessage(), 3);
		return array('allowed' => false, 'status' => (int)$status, 'message' => $message);
	}
}

function outside_actions($api_entry, $family) {
	try {
		ApiAuth::refuseScopedKeyOutsideItsScope($api_entry, $family);
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

section('Record entries');

$records = key_of(ApiKey::TYPE_MACHINE, "Event:read, Event:create, Post:delete, $SNAP", 4);
check($records->scope_actions() === array($SNAP), 'Action entries are told apart from record entries');
check($records->scope_records() === array('Event' => array('read', 'create'), 'Post' => array('delete')),
	'Record entries group by model', json_encode($records->scope_records()));
check($records->scope_label() === "$SNAP; Event: read, create; Post: delete", 'The scope reads in words', $records->scope_label());

// A record endpoint's call: the capability its verb needs, and the model and
// verb as authorize()'s own argument.
$record_call = function ($api_entry, $model, $verb) {
	$capability = $verb === 'read' ? ApiAuth::CAP_READ : ($verb === 'delete' ? ApiAuth::CAP_DELETE : ApiAuth::CAP_WRITE);
	return decide(array('capability' => $capability), $api_entry, null, 0, array('model' => $model, 'verb' => $verb));
};
check($record_call($records, 'Event', 'read')['allowed'], 'It reads the model its scope names');
check($record_call($records, 'Event', 'create')['allowed'], 'It creates where its scope says create');
check($record_call($records, 'Post', 'delete')['allowed'], 'It deletes where its scope says delete');
$r = $record_call($records, 'Event', 'update');
check(!$r['allowed'] && $r['status'] === 403, 'A verb its scope does not give the model is refused');
$r = $record_call($records, 'Event', 'delete');
check(!$r['allowed'] && $r['status'] === 403, 'Delete is not implied by create');
$r = $record_call($records, 'User', 'read');
check(!$r['allowed'] && $r['status'] === 403, 'A model its scope does not name is refused');
check(!$r['allowed'] && strpos($r['message'], 'Event: read, create') !== false, 'The refusal names the scope', $r['message'] ?? '');
check(strpos($r['message'], "'User:read'") !== false, 'and the entry it would need', $r['message'] ?? '');
$r = $record_call($records, 'Event', 'update');
check(strpos($r['message'], "'Event:update' (update Event records)") !== false, 'A missing verb is named with its model', $r['message'] ?? '');
$r = decide($READ, key_of(ApiKey::TYPE_MACHINE, 'Event:read'), 'notification_unread_count');
check(!$r['allowed'] && strpos($r['message'], "the action 'notification_unread_count'") !== false, 'A missing action is named', $r['message'] ?? '');
check(strpos($r['message'], 'manifest') === false, 'and a key that is no plugin\'s is not told about a manifest', $r['message'] ?? '');
// A sandboxed plugin's key: the refusal says where its manifest asks.
$plugin_key = new class(NULL) extends ApiKey {
	function sandbox_plugin(): ?string { return 'notifier'; }
};
$plugin_key->set('apk_type', ApiKey::TYPE_MACHINE);
$plugin_key->set('apk_scope', 'Event:read');
$plugin_key->set('apk_permission', 1);
$r = $record_call($plugin_key, 'User', 'read');
check(strpos($r['message'], 'The plugin notifier asks for it in its manifest under permissions.records {"User": ["read"]}') !== false,
	'A plugin\'s key is told the manifest line that asks for it', $r['message'] ?? '');
$r = decide($READ, $plugin_key, 'notification_unread_count');
check(strpos($r['message'], 'permissions.actions ["notification_unread_count"]') !== false, 'and for an action', $r['message'] ?? '');
$r = decide($READ, $records, null);
check(!$r['allowed'] && $r['status'] === 403, 'A surface with no name (forms, management) is still refused');
$r = decide($READ, key_of(ApiKey::TYPE_MACHINE, 'Event:read'), $SNAP);
check(!$r['allowed'] && $r['status'] === 403, 'A key with only record entries calls no action');
$r = $record_call(key_of(ApiKey::TYPE_MACHINE, 'notification_unread_count'), 'Event', 'read');
check(!$r['allowed'] && $r['status'] === 403, 'A key with only action entries reads no records');
$r = $record_call(key_of(ApiKey::TYPE_MACHINE, 'Event:create', 1), 'Event', 'create');
check(!$r['allowed'] && $r['status'] === 403, 'The capability gate still applies inside the scope');
check($record_call($plain, 'User', 'update')['allowed'], 'An unscoped key reaches records as before');
$r = decide(array('capability' => ApiAuth::CAP_READ, 'record' => array('model' => 'Event', 'verb' => 'read')),
	key_of(ApiKey::TYPE_MACHINE, 'Event:read'), null);
check(!$r['allowed'] && $r['status'] === 403,
	'A record named inside a contract (a descriptor\'s auth block) opens nothing: only the record endpoints\' own argument counts');

section('Record entries before a route family runs');

$event_key = key_of(ApiKey::TYPE_MACHINE, 'Post:read');
check(outside_actions($event_key, 'Post')['allowed'], 'A key scoped to a model reaches its one-record URL');
check(outside_actions($event_key, 'Posts')['allowed'], 'and its list URL');
check(outside_actions($event_key, 'post')['allowed'], 'resolved as apiv1.php resolves it (first letter raised)');
foreach (array('Users', 'User', 'management', 'auth', 'app', 'form', 'drive_upload', 'actions', '') as $family) {
	$r = outside_actions($event_key, $family);
	check(!$r['allowed'] && $r['status'] === 403, "It is refused on '$family'");
}
check(outside_actions($event_key, 'action')['allowed'], 'Action dispatch is left to authorize()');
$_SERVER['REQUEST_METHOD'] = 'DELETE';
$r = outside_actions($event_key, 'User');
check(strpos($r['message'], "'User:delete'") !== false, 'A model it does not name is named with the verb the request asks', $r['message'] ?? '');
$_SERVER['REQUEST_METHOD'] = 'GET';
$r = outside_actions($event_key, 'Users');
check(strpos($r['message'], "'User:read'") !== false && strpos($r['message'], 'Post: read') !== false,
	'a list URL is named as its model, beside what the key has', $r['message'] ?? '');
$r = outside_actions($event_key, 'auth');
check(strpos($r['message'], 'no scoped key reaches this') !== false, 'A family no scope reaches says so', $r['message'] ?? '');
unset($_SERVER['REQUEST_METHOD']);
$r = outside_actions($event_key, 'Postx');
check(!$r['allowed'], 'A list URL is the model name plus s, nothing else');
$r = outside_actions(key_of(ApiKey::TYPE_MACHINE, 'Aut:read'), 'auth');
check(!$r['allowed'] && $r['status'] === 403, 'A model one letter short of a family name does not open that family');

section('No model shares a name with a route family');

// The families apiv1.php dispatches by name, read from its own source so a
// new one is covered the day it is added.
$router = file_get_contents(PathHelper::getIncludePath('api/apiv1.php'));
preg_match_all("~strtolower\\(\\\$url_segments\\[2\\] \\?\\? ''\\) === '([a-z_]+)'~", $router, $m);
$families = array_values(array_unique($m[1]));
check(count($families) >= 5, 'The route families are found in apiv1.php', implode(', ', $families));
$collisions = array();
foreach (LibraryFunctions::discover_model_classes(array('include_plugins' => true)) as $class) {
	foreach (array($class, $class . 's') as $url_name) {
		if (in_array(strtolower($url_name), $families, true)) {
			$collisions[] = $class;
		}
	}
}
check($collisions === array(), 'No model is named like a route family', implode(', ', $collisions));

// A scope entry is only text: one naming a family must open nothing, and
// cannot be saved in the first place.
foreach ($families as $family) {
	if ($family === 'action') {
		continue;
	}
	$entry = ucfirst($family) . ':read';
	$r = outside_actions(key_of(ApiKey::TYPE_MACHINE, $entry), $family);
	check(!$r['allowed'] && $r['status'] === 403, "A key scoped '$entry' is refused on $family");
	check(ApiKey::scope_entry_problem($entry) !== null, "'$entry' cannot be saved: it names no model");
}

section('The scope grammar');

foreach (array('Event:read', 'EventRegistrant:delete', 'notification_unread_count', 'dns_filtering/resolver_snapshot') as $good) {
	check(ApiKey::scope_entry_problem($good) === null, "'$good' is an entry");
}
foreach (array('Event:write', 'event:read', 'Event', 'a b', 'Event:read:x', 'x/y/z', '*') as $bad) {
	check(ApiKey::scope_entry_problem($bad) !== null, "'$bad' is refused");
}
$threw = false;
try {
	key_of(ApiKey::TYPE_MACHINE, 'Event:read,Event:write')->save();
} catch (ApiKeyException $e) {
	$threw = strpos($e->getMessage(), 'Event:write') !== false;
}
check($threw, 'Saving a malformed scope is refused before it reaches the database, naming the entry');
$threw = '';
try {
	key_of(ApiKey::TYPE_MACHINE, 'Evnet:read')->save();
} catch (ApiKeyException $e) {
	$threw = $e->getMessage();
}
check(strpos($threw, 'Evnet') !== false, 'A record entry naming no model (a typo) is refused', $threw);
$threw = false;
try {
	key_of(ApiKey::TYPE_MACHINE, ' , ')->save();
} catch (ApiKeyException $e) {
	$threw = true;
}
check($threw, 'A scope that names nothing is refused: it would read as unscoped');
check(key_of(ApiKey::TYPE_MACHINE, 'Post:Read')->scope_label() === 'Post:Read',
	'An entry that parses as neither kind is shown as stored, so a refusal still says what the key holds');

section('The permission a scope needs');

$caps = array('reader' => ApiAuth::CAP_READ, 'writer' => ApiAuth::CAP_WRITE, 'free' => null);
check(ApiKey::permission_for_scope(array('Event:read'), $caps) === 1, 'Reading only: 1');
check(ApiKey::permission_for_scope(array('Event:create'), $caps) === 2, 'Writing only: 2');
check(ApiKey::permission_for_scope(array('Event:read', 'Event:update'), $caps) === 3, 'Reading and writing: 3');
check(ApiKey::permission_for_scope(array('Event:delete'), $caps) === 4, 'Deleting: 4');
check(ApiKey::permission_for_scope(array('reader'), $caps) === 1, 'A read action: 1');
check(ApiKey::permission_for_scope(array('reader', 'writer'), $caps) === 3, 'A read and a write action: 3');
check(ApiKey::permission_for_scope(array('free'), $caps) === 1, 'An action gating on no capability: 1');
check(ApiKey::permission_for_scope(array('unknown_action'), $caps) === 2, 'An action not described is taken as a write');

section('Only a machine key may be scoped');

$threw = false;
try {
	key_of(ApiKey::TYPE_SESSION, $SNAP)->save();
} catch (ApiKeyException $e) {
	$threw = true;
}
check($threw, 'Saving a scoped session key is refused before it reaches the database');

harness_finish();
