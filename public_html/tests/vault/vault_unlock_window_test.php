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

if (!vault_apcu_usable()) {
	harness_skip('APCu unavailable in this process', 'run manually: php -d apc.enable_cli=1 tests/vault/vault_unlock_window_test.php');
	harness_finish();
}
if (!vault_ensure_session()) {
	harness_skip('could not start a CLI session');
	harness_finish();
}

$user = make_user('VaultWin');
$uid = (int)$user->key;
$sid = session_id();
$secret = random_bytes(32);
$scope = 'user';
$meta_key = 'vaultmeta:' . $sid . ':' . $uid . ':' . $scope;
harness_defer(function () use ($uid) { VaultUnlock::lockAll($uid); });

section('Window lifecycle');
check(!VaultUnlock::isOpen($uid), 'no window before open');
check(VaultUnlock::secretKey($uid) === null, 'secretKey is null while locked');
VaultUnlock::open($uid, $secret, $scope, ['idle' => null, 'absolute' => null]);
check(VaultUnlock::isOpen($uid), 'open window is visible');
check(VaultUnlock::secretKey($uid) === $secret, 'secretKey returns the exact bytes stored');
check(VaultUnlock::hasAnyOpenWindow($uid), 'the cross-process marker sees the window');
VaultUnlock::close($uid);
check(!VaultUnlock::isOpen($uid), 'close ends the window');
check(VaultUnlock::secretKey($uid) === null, 'secretKey is null after close');

section('Policy caps fire at read time');
// Absolute cap: rewind the arming time past the cap and the next read ends it.
VaultUnlock::open($uid, $secret, $scope, ['idle' => null, 'absolute' => 3600]);
$meta = apcu_fetch($meta_key);
$meta['armed'] = time() - 7200;
apcu_store($meta_key, $meta, 3600);
check(VaultUnlock::secretKey($uid) === null, 'absolute cap exceeded: read reports locked');
check(!VaultUnlock::isOpen($uid), 'and the window was wiped, not just hidden');

// Idle cap: rewind the last content decrypt.
VaultUnlock::open($uid, $secret, $scope, ['idle' => 600, 'absolute' => null]);
$meta = apcu_fetch($meta_key);
$meta['content'] = time() - 1200;
apcu_store($meta_key, $meta, 3600);
check(VaultUnlock::secretKey($uid) === null, 'idle cap exceeded: read reports locked');

// Stale heartbeat: once a surface monitors, silence past the threshold ends it.
VaultUnlock::open($uid, $secret, $scope, ['idle' => null, 'absolute' => null]);
check(VaultUnlock::heartbeat($uid), 'heartbeat acknowledges a live window');
$meta = apcu_fetch($meta_key);
$meta['hb'] = time() - (VaultUnlock::HEARTBEAT_MAX_STALE_SECONDS + 60);
apcu_store($meta_key, $meta, 3600);
check(VaultUnlock::secretKey($uid) === null, 'stale heartbeat: read reports locked');
check(!VaultUnlock::heartbeat($uid), 'heartbeat reports false once the window is gone');

// No metadata -> no policy: a window without meta is never force-ended.
VaultUnlock::open($uid, $secret, $scope, ['idle' => null, 'absolute' => null]);
apcu_delete($meta_key);
check(VaultUnlock::secretKey($uid) === $secret, 'a window with no metadata is not force-ended');
VaultUnlock::close($uid);

section('Heartbeat never extends the key TTL');
if (!function_exists('apcu_key_info')) {
	harness_skip('apcu_key_info unavailable', 'TTL-extension asymmetry not directly observable');
} else {
	VaultUnlock::open($uid, $secret, $scope, ['idle' => null, 'absolute' => null]);
	$vault_key = 'vault:' . $sid . ':' . $uid . ':' . $scope;
	$before = apcu_key_info($vault_key);
	// creation_time has whole-second granularity; a 1s sleep always lands
	// time() in a later second, which is all the comparison needs.
	sleep(1);
	VaultUnlock::heartbeat($uid);
	$after_hb = apcu_key_info($vault_key);
	check($after_hb['creation_time'] === $before['creation_time'], 'a heartbeat does not re-store the key');
	VaultUnlock::secretKey($uid);
	$after_read = apcu_key_info($vault_key);
	check($after_read['creation_time'] > $before['creation_time'], 'a content decrypt does re-store the key');
	VaultUnlock::close($uid);
}

section('lockAll and wipe callbacks');
$wipes = [];
VaultUnlock::onWipe(function (int $u, ?string $s) use (&$wipes) { $wipes[] = [$u, $s]; });
VaultUnlock::open($uid, $secret, $scope, ['idle' => null, 'absolute' => null]);
// A second session's window, mimicked directly at the storage layer.
apcu_store('vault:othersession:' . $uid . ':' . $scope, $secret, 3600);
VaultUnlock::lockAll($uid);
check(apcu_fetch('vault:othersession:' . $uid . ':' . $scope) === false, 'lockAll wipes other sessions\' windows too');
check(!VaultUnlock::isOpen($uid), 'lockAll wipes the current session');
check(!VaultUnlock::hasAnyOpenWindow($uid), 'lockAll removes the cross-process markers');
$saw_lockall = false;
foreach ($wipes as $wp) { if ($wp[0] === $uid && $wp[1] === null) { $saw_lockall = true; } }
check($saw_lockall, 'wipe callbacks fire with a null scope on lockAll');

section('Marker hygiene');
VaultUnlock::open($uid, $secret, $scope, ['idle' => null, 'absolute' => null]);
$marker = '/dev/shm/vault_window_' . $uid . '_' . $scope;
check(@filemtime($marker) > time(), 'marker mtime is the window expiry, in the future');
touch($marker, time() - 10);
check(!VaultUnlock::hasAnyOpenWindow($uid), 'an expired marker reads as no-window');
check(!file_exists($marker), 'and is reclaimed opportunistically');
VaultUnlock::close($uid);

harness_finish();
?>
