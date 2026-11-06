<?php
/** @joinery-test
 * name: vault_audit_note
 * tier: db
 * env: dev-only
 * needs: []
 *
 * VaultAudit::parseNote() reads back every note VaultAudit writes: each opened
 * row (with and without a holder, the pool rung's fallback, a borrowed slot)
 * and each closed row (every reason, with and without an unwrap count, an
 * unknown open time). A value off its list reads as 'other', a field the note
 * lacks is absent, and nothing outside the two vault events parses. The
 * plane's vault window view reads these rows through log_table_tail
 * (specs/agent_event_log_filter.md).
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$user = make_user('vault_audit_note');
$user_id = (int)$user->key;

/** The newest note of $event for $user_id. */
function van_last(int $user_id, string $event): string {
	$q = DbConnector::get_instance()->get_db_link()->prepare("SELECT evl_note FROM evl_event_logs WHERE evl_usr_user_id = ?
		AND evl_event = ? ORDER BY evl_event_log_id DESC LIMIT 1");
	$q->execute(array($user_id, $event));
	return (string)$q->fetchColumn();
}

// ---------------------------------------------------------------------------
section('Opened rows');
// ---------------------------------------------------------------------------
VaultAudit::opened($user_id, 'user', VaultAudit::VIA_PASSKEY, array('idle' => 7200, 'absolute' => 86400), 'sess-a',
	VaultAudit::HOLDER_DAEMON);
$f = VaultAudit::parseNote(VaultAudit::EVENT_OPENED, van_last($user_id, VaultAudit::EVENT_OPENED));
check($f['scope'] === 'user' && $f['via'] === VaultAudit::VIA_PASSKEY && $f['holder'] === VaultAudit::HOLDER_DAEMON,
	'a daemon passkey open reads back its scope, via and holder', json_encode($f));
check($f['session'] === VaultAudit::handle('sess-a'), 'the session handle reads back, to pair the open with its close');
check($f['idle_cap'] === 7200 && $f['absolute_cap'] === 86400, 'the caps read back in seconds', json_encode($f));
check(is_int($f['idle_setting']) && $f['idle_setting'] % 60 === 0, 'the idle setting reads back in seconds');
check(!array_key_exists('fallback', $f) && !array_key_exists('reason', $f), 'a field the note does not carry is absent');

VaultAudit::opened($user_id, 'user', VaultAudit::VIA_RECOVERY, null, 'sess-b',
	VaultAudit::HOLDER_POOL, VaultAudit::FALLBACK_POOL_RUNG);
$f = VaultAudit::parseNote(VaultAudit::EVENT_OPENED, van_last($user_id, VaultAudit::EVENT_OPENED));
check($f['holder'] === VaultAudit::HOLDER_POOL && $f['fallback'] === VaultAudit::FALLBACK_POOL_RUNG,
	'a pool open the rung sent there says so', json_encode($f));
check($f['idle_cap'] === null && $f['absolute_cap'] === null, 'no caps read as null');

VaultAudit::opened($user_id, 'user', VaultAudit::VIA_APP_BORROW, null, VaultUnlock::CREDENTIAL_SLOT_PREFIX . '42',
	VaultAudit::HOLDER_DAEMON);
$f = VaultAudit::parseNote(VaultAudit::EVENT_OPENED, van_last($user_id, VaultAudit::EVENT_OPENED));
check($f['via'] === VaultAudit::VIA_APP_BORROW && $f['session'] === 'apk42', 'a borrowed slot names its credential', json_encode($f));

VaultAudit::opened($user_id, 'user', VaultAudit::VIA_PASSKEY, null, 'sess-c');
$f = VaultAudit::parseNote(VaultAudit::EVENT_OPENED, van_last($user_id, VaultAudit::EVENT_OPENED));
check(!array_key_exists('holder', $f), 'a row written without a holder has none, never a guess', json_encode($f));

// ---------------------------------------------------------------------------
section('Closed rows');
// ---------------------------------------------------------------------------
$reasons = array(VaultAudit::REASON_IDLE_CAP, VaultAudit::REASON_ABSOLUTE_CAP, VaultAudit::REASON_HEARTBEAT_STALE,
	VaultAudit::REASON_IDLE_EXPIRED, VaultAudit::REASON_EXPLICIT_LOCK, VaultAudit::REASON_LOGOUT, VaultAudit::REASON_IP_CHANGE,
	VaultAudit::REASON_CREDENTIAL_EVENT, VaultAudit::REASON_APP_REVOKED, VaultAudit::REASON_DAEMON_RESTART,
	VaultAudit::REASON_SENTINEL_DROP, VaultAudit::REASON_REPLACED);
$all = true;
foreach ($reasons as $reason) {
	VaultAudit::closed($user_id, 'user', $reason, 'sess-a', 95, 12);
	$f = VaultAudit::parseNote(VaultAudit::EVENT_CLOSED, van_last($user_id, VaultAudit::EVENT_CLOSED));
	$all = $all && $f['reason'] === $reason && $f['open_seconds'] === 95 && $f['unwraps'] === 12;
}
check($all, 'every close reason reads back, with its open time and unwrap count');
VaultAudit::closed($user_id, 'user', VaultAudit::REASON_IDLE_EXPIRED, 'sess-a');
$f = VaultAudit::parseNote(VaultAudit::EVENT_CLOSED, van_last($user_id, VaultAudit::EVENT_CLOSED));
check($f['open_seconds'] === 'unknown' && !array_key_exists('unwraps', $f), 'an unknown open time and a pool close read as such', json_encode($f));

// ---------------------------------------------------------------------------
section('Values off their lists');
// ---------------------------------------------------------------------------
$f = VaultAudit::parseNote(VaultAudit::EVENT_CLOSED, 'scope=user reason=<script> session=ZZZ open_seconds=-4 unwraps=lots extra=1');
check($f['reason'] === 'other' && $f['session'] === 'other' && $f['open_seconds'] === 'other' && $f['unwraps'] === 'other',
	'a value off its list or shape reads as other', json_encode($f));
check(!array_key_exists('extra', $f), 'an unknown field is dropped');
$f = VaultAudit::parseNote(VaultAudit::EVENT_OPENED, 'scope=user via=passkey holder=disk reason=idle_cap');
check($f['holder'] === 'other' && !array_key_exists('reason', $f), 'an opened row keeps only an opened row\'s fields', json_encode($f));
check(VaultAudit::parseNote('login', 'scope=user via=passkey') === array(), 'another event parses to nothing');

harness_finish();
