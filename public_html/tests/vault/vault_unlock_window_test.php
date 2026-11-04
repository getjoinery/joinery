<?php
/** @joinery-test
 * name: vault_unlock_window
 * tier: db
 * env: dev-only
 * needs: []
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/vault_fixtures.php');

if (!vault_ensure_session()) {
	harness_skip('could not start a CLI session');
	harness_finish();
}

$user = make_user('VaultWin');
$uid = (int)$user->key;
$sid = session_id();
$secret = (new SealedBox())->generateKeypair()['secret'];
$key_id = vault_fixture_key($secret)->id();
$scope = 'user';
$meta_key = 'vaultmeta:' . $sid . ':' . $uid . ':' . $scope;
harness_defer(function () use ($uid) { VaultUnlock::lockAll($uid); });

section('Window lifecycle');
check(!VaultUnlock::isOpen($uid), 'no window before open');
check(VaultUnlock::secretKey($uid) === null, 'secretKey is null while locked');
vault_fixture_open_window($uid, $secret, $scope, ['idle' => null, 'absolute' => null]);
check(VaultUnlock::isOpen($uid), 'open window is visible');
$got = VaultUnlock::secretKey($uid);
check($got instanceof VaultKey && $got->id() === $key_id, 'secretKey returns the key that was armed, as a VaultKey');
check(VaultUnlock::hasAnyOpenWindow($uid), 'the cross-process marker sees the window');
VaultUnlock::close($uid);
check(!VaultUnlock::isOpen($uid), 'close ends the window');
check(VaultUnlock::secretKey($uid) === null, 'secretKey is null after close');

section('Policy caps fire at read time');
// Absolute cap: rewind the arming time past the cap and the next read ends it.
vault_fixture_open_window($uid, $secret, $scope, ['idle' => null, 'absolute' => 3600]);
$meta = vault_store_get($meta_key);
$meta['armed'] = time() - 7200;
vault_store_put($meta_key, $meta);
check(VaultUnlock::secretKey($uid) === null, 'absolute cap exceeded: read reports locked');
check(!VaultUnlock::isOpen($uid), 'and the window was wiped, not just hidden');

// Idle cap: rewind the last content decrypt.
vault_fixture_open_window($uid, $secret, $scope, ['idle' => 600, 'absolute' => null]);
$meta = vault_store_get($meta_key);
$meta['content'] = time() - 1200;
vault_store_put($meta_key, $meta);
check(VaultUnlock::secretKey($uid) === null, 'idle cap exceeded: read reports locked');

// Stale heartbeat: once a surface monitors, silence past the threshold ends it.
vault_fixture_open_window($uid, $secret, $scope, ['idle' => null, 'absolute' => null]);
check(VaultUnlock::heartbeat($uid), 'heartbeat acknowledges a live window');
$meta = vault_store_get($meta_key);
$meta['hb'] = time() - (VaultUnlock::HEARTBEAT_MAX_STALE_SECONDS + 60);
vault_store_put($meta_key, $meta);
check(VaultUnlock::secretKey($uid) === null, 'stale heartbeat: read reports locked');
check(!VaultUnlock::heartbeat($uid), 'heartbeat reports false once the window is gone');

// No metadata -> no policy: a window without meta is never force-ended.
vault_fixture_open_window($uid, $secret, $scope, ['idle' => null, 'absolute' => null]);
vault_store_delete($meta_key);
$got = VaultUnlock::secretKey($uid);
check($got instanceof VaultKey && $got->id() === $key_id, 'a window with no metadata is not force-ended');
VaultUnlock::close($uid);

section('Heartbeat never extends the key TTL');
vault_fixture_open_window($uid, $secret, $scope, ['idle' => null, 'absolute' => null]);
$vault_key = 'vault:' . $sid . ':' . $uid . ':' . $scope;
$before = RamStore::info($vault_key, RamStore::LIFETIME);
// Expiry has whole-second granularity; a 1s sleep always lands time() in a
// later second, which is all the comparison needs.
sleep(1);
VaultUnlock::heartbeat($uid);
$after_hb = RamStore::info($vault_key, RamStore::LIFETIME);
check($after_hb['expires'] === $before['expires'], 'a heartbeat does not extend the key');
VaultUnlock::secretKey($uid);
$after_read = RamStore::info($vault_key, RamStore::LIFETIME);
check($after_read['expires'] > $before['expires'], 'a content decrypt does extend the key');
check($after_read['id'] === $before['id'], 'by touching the slot, not writing the key again');
VaultUnlock::close($uid);

section('The window store');
vault_fixture_open_window($uid, $secret, $scope, ['idle' => null, 'absolute' => null]);
$gen_dir = RamStore::directory() . '/' . RamStore::generation();
check(is_file($gen_dir . '/' . $vault_key) && is_file($gen_dir . '/' . $meta_key),
	'the key slot and its metadata live in this process\'s generation');
check(is_file(RamStore::directory() . '/vaultwin:' . $uid . ':' . $scope),
	'the open-window marker is a plain entry, where cron finds it');
check(json_decode((string)file_get_contents($gen_dir . '/' . $vault_key), true) === PoolVaultKey::wrappedForm($secret),
	'the slot holds the key as a JSON string, nothing else');
VaultUnlock::secretKey($uid);
$meta_before = RamStore::info($meta_key, RamStore::LIFETIME);
VaultUnlock::secretKey($uid);
VaultUnlock::secretKey($uid);
check(RamStore::info($meta_key, RamStore::LIFETIME)['id'] === $meta_before['id'],
	'reads after the first in one request write nothing');
// Another request's lock removes the files; this process's memo must not hide it.
vault_store_delete($vault_key);
check(VaultUnlock::secretKey($uid) === null, 'a slot removed elsewhere reads as locked at once');
vault_fixture_open_window($uid, $secret, $scope, ['idle' => null, 'absolute' => null]);
$meta = vault_store_get($meta_key);
$meta['armed'] = time() - 7200;
$meta['abs_cap'] = 3600;
vault_store_put($meta_key, $meta);
check(VaultUnlock::secretKey($uid) === null, 'metadata rewritten elsewhere is read again: the cap applies');
vault_fixture_open_window($uid, $secret, $scope, ['idle' => null, 'absolute' => null]);
vault_store_put($vault_key, array('not' => 'a key'));
check(VaultUnlock::secretKey($uid) === null && vault_store_get($vault_key) === null,
	'a slot holding anything but a key reads as locked and is removed');
vault_fixture_open_window($uid, $secret, $scope, ['idle' => null, 'absolute' => null]);
$real_generation = RamStore::generation();
RamStore::$generation_override = 'gen-000000000000-1-1-c';
check(VaultUnlock::secretKey($uid) === null, 'another generation (a restarted PHP-FPM) finds no window');
RamStore::$generation_override = null;
VaultUnlock::resetForTests();
check(RamStore::generation() === $real_generation && VaultUnlock::secretKey($uid) instanceof VaultKey,
	'and this generation still holds it');
VaultUnlock::close($uid);

section('A read of content the browser opens counts as a content read');
// One clock (docs/sealed_vault.md § The lock chip): the password vault's
// entries, Fortress mail and Fortress folders never reach secretKey(), so the
// actions serving them call noteClientContentRead() instead.
vault_fixture_open_window($uid, $secret, $scope, ['idle' => 600, 'absolute' => null]);
$meta = vault_store_get($meta_key);
$meta['content'] = time() - 500;
vault_store_put($meta_key, $meta);
check(VaultUnlock::noteClientContentRead($uid) === true, 'with the window open it refreshes the window');
check((vault_store_get($meta_key)['content'] ?? 0) >= time() - 5, 'and restarts the idle cap, as a content decrypt does');
VaultUnlock::setActivitySuppressed(true);
$meta = vault_store_get($meta_key);
$meta['content'] = time() - 500;
vault_store_put($meta_key, $meta);
$suppressed = VaultUnlock::noteClientContentRead($uid);
VaultUnlock::setActivitySuppressed(false);
check($suppressed === false && (vault_store_get($meta_key)['content'] ?? 0) <= time() - 400,
	'background work does not count');
$meta = vault_store_get($meta_key);
$meta['content'] = time() - 1200;
vault_store_put($meta_key, $meta);
check(VaultUnlock::noteClientContentRead($uid) === false && !VaultUnlock::isOpen($uid),
	'past the idle cap it refreshes nothing: the policy ends the window first');
check(VaultUnlock::noteClientContentRead($uid) === false, 'with no window there is nothing to refresh');

section('lockAll and wipe callbacks');
$wipes = [];
VaultUnlock::onWipe(function (int $u, ?string $s) use (&$wipes) { $wipes[] = [$u, $s]; });
vault_fixture_open_window($uid, $secret, $scope, ['idle' => null, 'absolute' => null]);
// A second session's window, mimicked directly at the storage layer.
vault_store_put('vault:othersession:' . $uid . ':' . $scope, PoolVaultKey::wrappedForm($secret));
vault_store_put('vault:othersession:' . ($uid + 1000000) . ':' . $scope, PoolVaultKey::wrappedForm($secret));
VaultUnlock::lockAll($uid);
check(vault_store_get('vault:othersession:' . $uid . ':' . $scope) === null, 'lockAll wipes other sessions\' windows too');
check(vault_store_get('vault:othersession:' . ($uid + 1000000) . ':' . $scope) !== null, 'and no other user\'s');
vault_store_delete('vault:othersession:' . ($uid + 1000000) . ':' . $scope);
check(!VaultUnlock::isOpen($uid), 'lockAll wipes the current session');
// An expired slot nobody swept yet still holds the key: lockAll zeroes it too,
// without reporting a close for a window that already ended.
vault_store_put('vault:expiredsession:' . $uid . ':' . $scope, PoolVaultKey::wrappedForm($secret));
$expired_path = RamStore::directory() . '/' . RamStore::generation() . '/vault:expiredsession:' . $uid . ':' . $scope;
touch($expired_path, time() - 5);
$rows_before = (int)DbConnector::get_instance()->get_db_link()->query(
	"SELECT COUNT(*) FROM evl_event_logs WHERE evl_usr_user_id = " . $uid . " AND evl_note LIKE '%expiredsession%'")->fetchColumn();
VaultUnlock::lockAll($uid);
check(!file_exists($expired_path), 'lockAll removes an expired slot too');
check((int)DbConnector::get_instance()->get_db_link()->query(
	"SELECT COUNT(*) FROM evl_event_logs WHERE evl_usr_user_id = " . $uid . " AND evl_note LIKE '%expiredsession%'")->fetchColumn() === $rows_before,
	'and writes no close for it');
check(!VaultUnlock::hasAnyOpenWindow($uid), 'lockAll removes the cross-process markers');
$saw_lockall = false;
foreach ($wipes as $wp) { if ($wp[0] === $uid && $wp[1] === null) { $saw_lockall = true; } }
check($saw_lockall, 'wipe callbacks fire with a null scope on lockAll');

section('Marker hygiene');
vault_fixture_open_window($uid, $secret, $scope, ['idle' => null, 'absolute' => null]);
$marker = RamStore::directory() . '/vaultwin:' . $uid . ':' . $scope;
check(@filemtime($marker) > time(), 'marker mtime is the window expiry, in the future');
touch($marker, time() - 10);
check(!VaultUnlock::hasAnyOpenWindow($uid), 'an expired marker reads as no-window');
check(!file_exists($marker), 'and is reclaimed opportunistically');
VaultUnlock::close($uid);

section('A close without an audit row');
// For an account that no longer exists (SessionControl::logout(true)): the
// window's keys go, and nothing is written to the audit log in its name.
vault_fixture_open_window($uid, $secret, $scope, ['idle' => null, 'absolute' => null]);
check(VaultUnlock::isOpen($uid, $scope), 'a window is open');
$count_closes = function () use ($uid) {
	$q = DbConnector::get_instance()->get_db_link()->prepare(
		"SELECT COUNT(*) FROM evl_event_logs WHERE evl_usr_user_id = ? AND evl_event = ?");
	$q->execute(array($uid, VaultAudit::EVENT_CLOSED));
	return (int)$q->fetchColumn();
};
$before = $count_closes();
VaultUnlock::close($uid, $scope, VaultAudit::REASON_LOGOUT, false);
check(!VaultUnlock::isOpen($uid, $scope), 'close(..., false) ends the window');
check($count_closes() === $before, 'and writes no close row');
vault_fixture_open_window($uid, $secret, $scope, ['idle' => null, 'absolute' => null]);
VaultUnlock::close($uid, $scope, VaultAudit::REASON_LOGOUT);
check($count_closes() === $before + 1, 'an ordinary close still writes its row');

harness_finish();
?>
