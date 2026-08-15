<?php
/** @joinery-test
 * name: vault_audit
 * tier: db
 * env: dev-only
 * needs: []
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/vault_fixtures.php');

if (!vault_apcu_usable()) {
	harness_skip('APCu unavailable in this process', 'run manually: php -d apc.enable_cli=1 tests/vault/vault_audit_test.php');
	harness_finish();
}
if (!vault_ensure_session()) {
	harness_skip('could not start a CLI session');
	harness_finish();
}

$user   = make_user('VaultAudit');
$uid    = (int)$user->key;
$sid    = session_id();
$secret = random_bytes(32);
$scope  = 'user';
harness_defer(function () use ($uid) { VaultUnlock::lockAll($uid); });

/** Audit rows for this user, oldest first. */
function audit_rows(int $uid): array {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare('SELECT evl_event, evl_note FROM evl_event_logs
		WHERE evl_usr_user_id = ? AND evl_event IN (?, ?)
		ORDER BY evl_event_log_id');
	$q->execute(array($uid, VaultAudit::EVENT_OPENED, VaultAudit::EVENT_CLOSED));
	return $q->fetchAll(PDO::FETCH_ASSOC);
}

function last_row(int $uid): ?array {
	$rows = audit_rows($uid);
	return $rows ? $rows[count($rows) - 1] : null;
}

section('Opening a window records how it was armed');

check(count(audit_rows($uid)) === 0, 'no audit rows before anything opens');

VaultUnlock::open($uid, $secret, $scope, array('idle' => 600, 'absolute' => 7200), VaultAudit::VIA_PASSKEY);
$rows = audit_rows($uid);
check(count($rows) === 1, 'one row after open', count($rows) . ' rows');
check($rows[0]['evl_event'] === VaultAudit::EVENT_OPENED, 'and it is the opened event');
check(strpos($rows[0]['evl_note'], 'via=passkey') !== false, 'the arming method is recorded');
check(strpos($rows[0]['evl_note'], 'idle_cap=600s') !== false, 'the idle cap is recorded');
check(strpos($rows[0]['evl_note'], 'absolute_cap=7200s') !== false, 'the absolute cap is recorded');

section('The session id never reaches the log');

check(strpos($rows[0]['evl_note'], $sid) === false, 'the raw session id is absent from the note');
$handle = VaultAudit::handle($sid);
check(strpos($rows[0]['evl_note'], 'session=' . $handle) !== false, 'a one-way handle stands in for it');
check($handle !== $sid && strlen($handle) === 12, 'the handle is a truncated digest, not the id');
check(VaultAudit::handle(null) === 'none', 'no session reads as none');

section('The secret key never reaches the log');

foreach (audit_rows($uid) as $row) {
	check(strpos($row['evl_note'], $secret) === false, 'no secret bytes in an audit note');
	check(strpos($row['evl_note'], base64_encode($secret)) === false, 'nor an encoded form of them');
}

section('An explicit lock records itself, with a duration');

VaultUnlock::close($uid);
$row = last_row($uid);
check($row['evl_event'] === VaultAudit::EVENT_CLOSED, 'closing writes a closed row');
check(strpos($row['evl_note'], 'reason=' . VaultAudit::REASON_EXPLICIT_LOCK) !== false, 'with the explicit-lock reason');
check(preg_match('/open_seconds=\d+/', $row['evl_note']) === 1, 'and how long the window was open');

section('Locking nothing writes nothing');

$before = count(audit_rows($uid));
VaultUnlock::close($uid);                 // already closed
VaultUnlock::lock($uid, 'no-such-session');
check(count(audit_rows($uid)) === $before, 'a lock with no window to close is not an event');

section('Each policy cap reports its own reason');

VaultUnlock::open($uid, $secret, $scope, array('idle' => null, 'absolute' => 1), VaultAudit::VIA_PASSPHRASE);
$meta_key = 'vaultmeta:' . $sid . ':' . $uid . ':' . $scope;
$meta = apcu_fetch($meta_key);
$meta['armed'] = time() - 3600;           // armed an hour ago, absolute cap is 1s
apcu_store($meta_key, $meta, 600);
check(!VaultUnlock::isOpen($uid), 'the absolute cap ends the window');
check(strpos(last_row($uid)['evl_note'], 'reason=' . VaultAudit::REASON_ABSOLUTE_CAP) !== false,
	'and names the absolute cap');

VaultUnlock::open($uid, $secret, $scope, array('idle' => 1, 'absolute' => null), VaultAudit::VIA_PASSPHRASE);
$meta = apcu_fetch($meta_key);
$meta['content'] = time() - 3600;         // no content decrypt for an hour, idle cap is 1s
apcu_store($meta_key, $meta, 600);
check(!VaultUnlock::isOpen($uid), 'the idle cap ends the window');
check(strpos(last_row($uid)['evl_note'], 'reason=' . VaultAudit::REASON_IDLE_CAP) !== false,
	'and names the idle cap');

VaultUnlock::open($uid, $secret, $scope, array('idle' => null, 'absolute' => null), VaultAudit::VIA_PASSPHRASE);
VaultUnlock::heartbeat($uid, $scope);
$meta = apcu_fetch($meta_key);
$meta['hb'] = time() - (VaultUnlock::HEARTBEAT_MAX_STALE_SECONDS + 60);
apcu_store($meta_key, $meta, 600);
check(!VaultUnlock::isOpen($uid), 'a stale heartbeat ends the window');
check(strpos(last_row($uid)['evl_note'], 'reason=' . VaultAudit::REASON_HEARTBEAT_STALE) !== false,
	'and names the stale heartbeat');

section('An APCu expiry is noticed by the next read');

// The case nothing else can report: no cap fired, no lock was called — the
// entry simply aged out of the cache, which runs no code of its own.
VaultUnlock::open($uid, $secret, $scope, array('idle' => null, 'absolute' => null), VaultAudit::VIA_PASSKEY);
$before = count(audit_rows($uid));
apcu_delete('vault:' . $sid . ':' . $uid . ':' . $scope);
apcu_delete($meta_key);
check(!VaultUnlock::isOpen($uid), 'the window reads as closed');
$row = last_row($uid);
check(count(audit_rows($uid)) === $before + 1, 'exactly one row for the expiry');
check($row['evl_event'] === VaultAudit::EVENT_CLOSED, 'an expiry closes the trail');
check(strpos($row['evl_note'], 'reason=' . VaultAudit::REASON_IDLE_EXPIRED) !== false,
	'named as an idle expiry');
check(preg_match('/open_seconds=\d+/', $row['evl_note']) === 1,
	'with a duration, from the session record rather than the vanished metadata');

$before = count(audit_rows($uid));
VaultUnlock::isOpen($uid);
VaultUnlock::secretKey($uid);
VaultUnlock::heartbeat($uid, $scope);
check(count(audit_rows($uid)) === $before, 'and it is reported once, not on every subsequent read');

section('Heartbeats are not events');

VaultUnlock::open($uid, $secret, $scope, array('idle' => null, 'absolute' => null), VaultAudit::VIA_PASSKEY);
$before = count(audit_rows($uid));
for ($i = 0; $i < 5; $i++) {
	VaultUnlock::heartbeat($uid, $scope);
	VaultUnlock::isOpen($uid);
	VaultUnlock::secretKey($uid);
}
check(count(audit_rows($uid)) === $before,
	'an open window that keeps being used writes nothing further');

section('A credential event closes every session it wipes');

$other = 'other-session-' . bin2hex(random_bytes(4));
apcu_store('vault:' . $other . ':' . $uid . ':' . $scope, $secret, 600);
apcu_store('vaultmeta:' . $other . ':' . $uid . ':' . $scope,
	array('armed' => time() - 120, 'content' => time(), 'hb' => null,
	      'idle_cap' => null, 'abs_cap' => null), 600);

$before = count(audit_rows($uid));
VaultUnlock::lockAll($uid);
$rows = array_slice(audit_rows($uid), $before);
check(count($rows) === 2, 'both windows are accounted for', count($rows) . ' rows');
foreach ($rows as $row) {
	check($row['evl_event'] === VaultAudit::EVENT_CLOSED, 'each is a closed row');
	check(strpos($row['evl_note'], 'reason=' . VaultAudit::REASON_CREDENTIAL_EVENT) !== false,
		'attributed to the credential event');
}
$handles = array_map(function ($r) {
	preg_match('/session=(\w+)/', $r['evl_note'], $m);
	return $m[1] ?? '';
}, $rows);
check(count(array_unique($handles)) === 2, 'one row per session, distinguishable by handle');
check(in_array(VaultAudit::handle($other), $handles, true), 'including the session that was not doing the locking');

section('The owning session does not double-report a close it did not do');

// The other session's window was wiped from under it; when a request on that
// session next looks, the tombstone tells it the close is already recorded.
$before = count(audit_rows($uid));
VaultUnlock::isOpen($uid);
check(count(audit_rows($uid)) === $before, 'no second row for a window already closed elsewhere');

harness_finish();
?>
