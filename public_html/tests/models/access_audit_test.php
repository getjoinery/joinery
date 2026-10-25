<?php
/** @joinery-test
 * name: access_audit
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * Access::decide() records what the audit is for (spec declared_permissions 3.6):
 * a signed-in user refused, allowed work that needs 9 or more, and any use of
 * the keys class. An anonymous refusal and ordinary allowed work write nothing.
 *
 * Run: php tests/run.php --filter=access_audit
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();   // the event rows land in the copied test database

/** A session stand-in: decide() asks only these two. */
class AccessAuditSession {
	public $permission; public $user_id;
	function __construct($permission, $user_id) { $this->permission = $permission; $this->user_id = $user_id; }
	function get_permission() { return $this->permission; }
	function get_user_id() { return $this->user_id; }
}

$db = DbConnector::get_instance()->get_db_link();
$user = make_user(strtoupper(LibraryFunctions::random_string(6)) . 'AA', 8);

/** The access rows for the fixture user written since $after, oldest first. */
function aa_rows($user_id, $after) {
	global $db;
	$q = $db->prepare("SELECT evl_event_log_id, evl_event, evl_was_success, evl_note FROM evl_event_logs
		WHERE evl_usr_user_id = ? AND evl_event LIKE 'access_%' AND evl_event_log_id > ? ORDER BY evl_event_log_id");
	$q->execute(array($user_id, $after));
	$rows = $q->fetchAll(PDO::FETCH_ASSOC);
	foreach ($rows as $r) harness_register_row('evl_event_logs', 'evl_event_log_id', $r['evl_event_log_id']);
	return $rows;
}
function aa_mark() {
	global $db;
	return (int)$db->query('SELECT COALESCE(MAX(evl_event_log_id), 0) FROM evl_event_logs')->fetchColumn();
}

$as8 = new AccessAuditSession(8, $user->key);

section('a signed-in refusal is recorded');
$mark = aa_mark();
check(Access::decide('keys:write', $as8) === false, 'an 8 may not change a key');
$rows = aa_rows($user->key, $mark);
check(count($rows) === 1 && $rows[0]['evl_event'] === 'access_refused' && !$rows[0]['evl_was_success'],
	'one access_refused row, not a success', json_encode($rows));
check(isset($rows[0]) && strpos($rows[0]['evl_note'], 'keys:write (needs 10, has 8)') === 0,
	'the note names what was asked and both levels', $rows[0]['evl_note'] ?? '');

section('an anonymous refusal is not');
$mark = aa_mark();
check(Access::decide(8, new AccessAuditSession(0, 0)) === false, 'anonymous is refused');
$q = $db->prepare("SELECT COUNT(*) FROM evl_event_logs WHERE evl_event LIKE 'access_%' AND evl_event_log_id > ?");
$q->execute(array($mark));
check((int)$q->fetchColumn() === 0, 'and nothing is written');

section('ordinary allowed work is not');
$mark = aa_mark();
check(Access::decide('personal:write', $as8) && Access::decide('config:read', $as8) && Access::decide(8, $as8),
	'an 8 writes personal data, reads config, runs an 8 action');
check(aa_rows($user->key, $mark) === array(), 'none of it is recorded');

section('9 and above, and keys, are');
$as10 = new AccessAuditSession(10, $user->key);
$mark = aa_mark();
check(Access::decide('personal:permanent_delete', $as10), 'a permanent delete (9)');
check(Access::decide(10, $as10), 'a level 10 action');
check(Access::decide('keys:read', $as10), 'reading a key');
$rows = aa_rows($user->key, $mark);
check(count($rows) === 3 && count(array_filter($rows, function ($r) { return $r['evl_event'] === 'access_granted' && $r['evl_was_success']; })) === 3,
	'three access_granted rows', json_encode($rows));
check(isset($rows[1]) && strpos($rows[1]['evl_note'], 'level 10 (needs 10, has 10)') === 0,
	'a level reads as a level', $rows[1]['evl_note'] ?? '');

harness_finish();
