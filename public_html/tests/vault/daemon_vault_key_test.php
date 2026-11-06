<?php
/** @joinery-test
 * name: daemon_vault_key
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 240
 *
 * The vault with its keys in the unseal daemon (specs/unseal_daemon.md WP4):
 * the shipped binary for this box runs on a temporary socket as this user,
 * and VaultUnlock is pointed at it (useDaemonForTests), so the real setup,
 * unlock, read, rotation, lock and ladder code run against the real daemon.
 *
 *  - setup mints in the daemon; an unlock opens a window there; the slot holds
 *    a handle and never the key; reads open through it, a page in one trip;
 *  - close and lockAll end the daemon's windows, and not another request's key
 *    with none; a read whose window a re-unlock replaced leaves the new one;
 *    the close row counts unwraps; openOnce() and release() leave no window;
 *  - a restart reads as locked (daemon_restart); the drop and hold reads as
 *    locked (sentinel_drop) and refuses unlocks (vault_held), the hold file
 *    too, before and after the fallback rung;
 *  - a daemon that does not answer: vault_service_unavailable, then the pool
 *    path after five silent minutes, without the retry's wait, then the
 *    daemon again once it answers;
 *  - the chain: a chain sealed before the previous-key scope is re-sealed
 *    under it by the next unlock, unwrap never opens it, a retired key opens
 *    content as a child window; a rotation re-seals the chain from what its
 *    open revealed.
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/vault_fixtures.php');

$machine = php_uname('m');
$shipped = realpath(PathHelper::getSiteRoot() . '/maintenance_scripts/install_tools/joinery_unseal/bin/joinery-unseal-' . $machine);
if ($shipped === false || !is_file($shipped)) {
	harness_skip('the tree ships no unseal daemon for ' . $machine);
	harness_finish();
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
	harness_skip('running as root: the daemon refuses to serve as root');
	harness_finish();
}
if (SealedBox::pqReadyReason() !== null || !vault_ensure_session()) {
	harness_skip('this process cannot seal with post-quantum keys, or has no session');
	harness_finish();
}

$dir = sys_get_temp_dir() . '/dvk_' . getmypid() . '_' . bin2hex(random_bytes(3));
mkdir($dir, 0700);
$bin = $dir . '/joinery-unseal';
copy($shipped, $bin);
chmod($bin, 0700);
$sock = $dir . '/u.sock';
$root = $dir . '/root';
$site_key = UnsealDaemon::key();
mkdir($root . '/run/joinery-unseal', 0700, true);
$hold = $root . '/run/joinery-unseal/' . $site_key . '.hold';
$uid = posix_geteuid();
$GLOBALS['dvk_proc'] = null;

function dvk_start(string $bin, string $sock, string $hold, int $uid): bool {
	dvk_stop();
	$cmd = array($bin, '--socket', $sock, '--allow-uid', (string)$uid, '--hold-file', $hold, '--unlocked-memory');
	$GLOBALS['dvk_proc'] = proc_open($cmd, array(0 => array('file', '/dev/null', 'r'),
		1 => array('file', dirname($sock) . '/out.log', 'a'), 2 => array('file', dirname($sock) . '/out.log', 'a')), $pipes);
	for ($i = 0; $i < 100; $i++) {
		if (file_exists($sock) && ($c = @stream_socket_client('unix://' . $sock, $e, $s, 1))) {
			fclose($c);
			DaemonVaultKey::resetClientsForTests();
			return true;
		}
		usleep(50000);
	}
	return false;
}
function dvk_pid(): int {
	$s = $GLOBALS['dvk_proc'] ? proc_get_status($GLOBALS['dvk_proc']) : null;
	return ($s && $s['running']) ? (int)$s['pid'] : 0;
}
function dvk_stop(): void {
	if ($GLOBALS['dvk_proc']) {
		if ($pid = dvk_pid()) {
			posix_kill($pid, 15);
		}
		for ($i = 0; $i < 50 && dvk_pid(); $i++) {
			usleep(50000);
		}
		proc_close($GLOBALS['dvk_proc']);
		$GLOBALS['dvk_proc'] = null;
	}
	DaemonVaultKey::resetClientsForTests();
}
function dvk_status(string $sock, ?int $user = null): array {
	return (new UnsealClient($sock, 3.0))->status($user)['fields'];
}
/** The newest vault_window_closed note for $user_id. */
function dvk_last_close(int $user_id): string {
	$q = DbConnector::get_instance()->get_db_link()->prepare("SELECT evl_note FROM evl_event_logs WHERE evl_usr_user_id = ?
		AND evl_event = 'vault_window_closed' ORDER BY evl_event_log_id DESC LIMIT 1");
	$q->execute(array($user_id));
	return (string)$q->fetchColumn();
}

harness_defer(function () use ($dir) {
	VaultUnlock::useDaemonForTests(null);
	UnsealDaemon::$root_override = null;
	VaultUnlock::$daemon_retry_us = 3000000;
	RamStore::delete(VaultUnlock::DAEMON_DOWN_KEY, RamStore::LIFETIME);
	dvk_stop();
	foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: array() as $f) {
		if (is_file($f) || is_link($f)) {
			@unlink($f);
		}
	}
	@unlink($dir . '/root/run/joinery-unseal/' . UnsealDaemon::key() . '.hold');
	@rmdir($dir . '/root/run/joinery-unseal');
	@rmdir($dir . '/root/run');
	@rmdir($dir . '/root');
	@rmdir($dir);
});

if (!dvk_start($bin, $sock, $hold, $uid)) {
	harness_skip('the daemon did not start', (string)@file_get_contents($dir . '/out.log'));
	harness_finish();
}
UnsealDaemon::$root_override = $root;
VaultUnlock::$daemon_retry_us = 1000;
RamStore::delete(VaultUnlock::DAEMON_DOWN_KEY, RamStore::LIFETIME);
VaultUnlock::useDaemonForTests(array('socket' => $sock, 'allow_uid' => $uid, 'pool_user' => '', 'init' => 'test'));
$crypto = new VaultCrypto();

// ---------------------------------------------------------------------------
section('Setup mints in the daemon; an unlock opens a window there');
$fx = vault_fixture_vault('Daemon', '', 5);
$user = $fx['user'];
$user_id = (int)$user->key;
harness_defer(function () use ($user_id) { VaultUnlock::lockAll($user_id); });
$vault = new UserEncryptionVault((int)$fx['vault']->key, TRUE);
$public = (string)$vault->get('uev_public_key');
check(strlen(SealedBox::b64url_decode($public)) === 1216, 'setup stored an X-Wing public key the daemon minted');
check(dvk_status($sock, $user_id)['open'] === false, 'setup with the window closed leaves no daemon window once released');

$wrapping = null;
foreach (new MultiUserEncryptionWrapping(array('vault_id' => (int)$vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_PASSKEY)) as $w) {
	$wrapping = $w;
}
$opened = VaultUnlock::open($user_id, $wrapping->unlocker($fx['kek']), array(), UserEncryptionVault::SCOPE_USER, null, VaultAudit::VIA_PASSKEY);
check($opened['key'] instanceof DaemonVaultKey, 'the unlock\'s key is a daemon handle', get_class($opened['key']));
check($opened['key']->publicKey() === $public, 'the daemon opened the vault\'s own key');
check(dvk_status($sock, $user_id)['open'] === true, 'the daemon holds a window for the member');
$slot = RamStore::get('vault:' . session_id() . ':' . $user_id . ':user', $ok, RamStore::LIFETIME);
check($ok && strpos((string)$slot, DaemonVaultKey::SLOT_TAG) === 0, 'the window slot holds a handle');
$pool_twin = PoolVaultKey::open($wrapping->unlocker($fx['kek']))['key'];
$secret_form = $pool_twin->slotValue();
check(strpos((string)$slot, $secret_form) === false && strpos(SealedBox::b64url_decode(substr((string)$slot, strlen(DaemonVaultKey::SLOT_TAG))), substr($secret_form, 3)) === false,
	'and carries nothing of the secret');

// ---------------------------------------------------------------------------
section('Reads open through the daemon');
$key = VaultUnlock::secretKey($user_id);
check($key instanceof DaemonVaultKey && $key->id() === $opened['key']->id(), 'secretKey() is the same daemon window');
$deks = array();
$sealed = array();
for ($i = 0; $i < 5; $i++) {
	$deks[$i] = random_bytes(32);
	$sealed[$i] = $crypto->sealItemDek($deks[$i], $public);
}
check($crypto->openItemDek($sealed[0], $key) === $deks[0], 'one DEK opens');
VaultCrypto::forgetItemDeks();
check($crypto->openItemDeks($sealed, $key) === $deks, 'a page of DEKs opens in one call');
$edge_dek = random_bytes(32);
$edge = $crypto->sealItemDekToBrowserKey($edge_dek, $public, 'user');
check($crypto->openItemDek($edge, $key) === $edge_dek, 'a browser-format DEK opens');
$threw = false;
try { $crypto->openItemDek($crypto->sealItemDek(random_bytes(32), (new SealedBox())->generateKeypair()['public']), $key); }
catch (Throwable $e) { $threw = !($e instanceof VaultLockedException); }
check($threw, 'a DEK sealed to another key is an error, not "locked"');

// ---------------------------------------------------------------------------
section('close and lockAll end the daemon\'s windows');
VaultUnlock::close($user_id);
check(dvk_status($sock, $user_id)['open'] === false, 'close ends the daemon window');
check(strpos(dvk_last_close($user_id), 'unwraps=') !== false, 'the close row counts the window\'s unwraps', dvk_last_close($user_id));
VaultUnlock::open($user_id, $wrapping->unlocker($fx['kek']));
$stray = VaultUnlock::openKey($user_id, $wrapping->unlocker($fx['kek']))['key'];
check(dvk_status($sock)['windows'] >= 2, 'a window and an unarmed key are open');
VaultUnlock::lockAll($user_id);
check(dvk_status($sock, $user_id)['open'] === false, 'lockAll ends every window of the member, unarmed ones too');
// Another request's key with no window (a rotation's drain of the old
// generation, after its answer) is not this request's to end (reviewer2 B2).
$drain = DaemonVaultKey::open($sock, $user_id, 'user', 600, 0, $wrapping->unlocker($fx['kek']))['key'];
VaultUnlock::open($user_id, $wrapping->unlocker($fx['kek']));
VaultUnlock::lockAll($user_id);
VaultCrypto::forgetItemDeks();
$ok_drain = false;
try { $ok_drain = $crypto->openItemDek($sealed[3], $drain) === $deks[3]; } catch (Throwable $e) {}
check($ok_drain && !VaultUnlock::isOpen($user_id), 'lockAll ends the windows, not another request\'s key with none');
$drain->close();
// A read that finds its window gone after the session unlocked again leaves
// the new window alone (reviewer2 B1).
VaultUnlock::open($user_id, $wrapping->unlocker($fx['kek']));
$k1 = VaultUnlock::secretKey($user_id);
$k2 = DaemonVaultKey::open($sock, $user_id, 'user', 600, 0, $wrapping->unlocker($fx['kek']))['key'];
RamStore::set('vault:' . session_id() . ':' . $user_id . ':user', $k2->slotValue(), 600, RamStore::LIFETIME);
$k1->close();
VaultCrypto::forgetItemDeks();
$locked = false;
try { $crypto->openItemDek($sealed[3], $k1); } catch (VaultLockedException $e) { $locked = true; }
VaultCrypto::forgetItemDeks();
$now = VaultUnlock::secretKey($user_id);
$ok_new = false;
try { $ok_new = $now instanceof DaemonVaultKey && $now->sameWindow($k2) && $crypto->openItemDek($sealed[3], $now) === $deks[3]; } catch (Throwable $e) {}
check($locked && $ok_new, 'the old handle reads as locked; the window that replaced it stays open and reads');
VaultUnlock::lockAll($user_id);
$once = VaultUnlock::openOnce($user_id, $wrapping->unlocker($fx['kek']), array(array('kek' => random_bytes(32), 'ad' => 'test:ad')));
check(count($once['wrappings']) === 1 && dvk_status($sock, $user_id)['open'] === false, 'openOnce() returns its wrapping and leaves no window');
$probe = VaultUnlock::openKey($user_id, $wrapping->unlocker($fx['kek']))['key'];
VaultUnlock::release($probe);
check(dvk_status($sock, $user_id)['open'] === false, 'release() closes a key no window took');
$threw = false;
try { VaultUnlock::openKey($user_id, $wrapping->unlocker(random_bytes(32))); } catch (VaultStoreUnavailableException $e) { $threw = 'store'; } catch (RuntimeException $e) { $threw = 'wrong'; }
check($threw === 'wrong', 'a wrong KEK is an ordinary refusal, not the service', (string)$threw);

// ---------------------------------------------------------------------------
section('A restart reads as locked, with its reason');
VaultUnlock::open($user_id, $wrapping->unlocker($fx['kek']));
sleep(1);
dvk_start($bin, $sock, $hold, $uid);
VaultCrypto::forgetItemDeks();
$key = VaultUnlock::secretKey($user_id);
$locked = false;
try { $key === null || $crypto->openItemDek($sealed[1], $key); } catch (VaultLockedException $e) { $locked = true; }
check($key === null || $locked, 'the first read after a restart is locked');
check(!VaultUnlock::isOpen($user_id), 'and the window is closed');
check(strpos(dvk_last_close($user_id), 'reason=' . VaultAudit::REASON_DAEMON_RESTART) !== false, 'audited as daemon_restart', dvk_last_close($user_id));

// ---------------------------------------------------------------------------
section('The drop and hold');
VaultUnlock::open($user_id, $wrapping->unlocker($fx['kek']));
posix_kill(dvk_pid(), defined('SIGUSR1') ? SIGUSR1 : 10);
usleep(200000);
VaultCrypto::forgetItemDeks();
$key = VaultUnlock::secretKey($user_id);
$locked = false;
try { $key === null || $crypto->openItemDek($sealed[2], $key); } catch (VaultLockedException $e) { $locked = true; }
check($key === null || $locked, 'every window reads as locked after the drop');
check(strpos(dvk_last_close($user_id), 'reason=' . VaultAudit::REASON_SENTINEL_DROP) !== false, 'audited as sentinel_drop', dvk_last_close($user_id));
$threw = null;
try { VaultUnlock::open($user_id, $wrapping->unlocker($fx['kek'])); } catch (VaultHeldException $e) { $threw = $e->getMessage(); }
check($threw !== null && stripos($threw, 'security hold') !== false, 'an unlock while held is vault_held, worded as a hold', (string)$threw);
posix_kill(dvk_pid(), defined('SIGUSR2') ? SIGUSR2 : 12);
usleep(200000);
file_put_contents($hold, '');
$threw = false;
try { VaultUnlock::open($user_id, $wrapping->unlocker($fx['kek'])); } catch (VaultHeldException $e) { $threw = true; }
check($threw, 'the hold file alone refuses an unlock');
$threw = false;
try { VaultUnlock::assertStoreUsable(); } catch (VaultHeldException $e) { $threw = true; }
check($threw, 'and a ceremony\'s store check refuses before anything is spent');
unlink($hold);

// ---------------------------------------------------------------------------
section('A daemon that does not answer: unavailable, then the pool, then the daemon');
dvk_stop();
@unlink($sock);
RamStore::delete(VaultUnlock::DAEMON_DOWN_KEY, RamStore::LIFETIME);
$threw = null;
try { VaultUnlock::open($user_id, $wrapping->unlocker($fx['kek'])); } catch (VaultServiceUnavailableException $e) { $threw = $e->getMessage(); }
check($threw !== null && stripos($threw, 'restarting') !== false, 'an unlock is vault_service_unavailable, worded as a restart', (string)$threw);
check(is_int(VaultUnlock::daemonDownSince()), 'the site remembers when it found the daemon silent');
check(!VaultUnlock::isOpen($user_id), 'nothing opened');
RamStore::set(VaultUnlock::DAEMON_DOWN_KEY, time() - VaultUnlock::POOL_RUNG_SECONDS - 5, 3600, RamStore::LIFETIME);
file_put_contents($hold, '');
$threw = false;
try { VaultUnlock::open($user_id, $wrapping->unlocker($fx['kek'])); } catch (VaultHeldException $e) { $threw = true; }
check($threw, 'past five minutes, a hold still refuses: never the pool while held');
unlink($hold);
VaultUnlock::$daemon_retry_us = 2000000;
$t0 = microtime(true);
$opened = VaultUnlock::open($user_id, $wrapping->unlocker($fx['kek']));
$took = microtime(true) - $t0;
VaultUnlock::$daemon_retry_us = 1000;
check($opened['key'] instanceof PoolVaultKey, 'past five silent minutes an unlock takes the pool path');
check($took < 1.0, 'without waiting out the retry (reviewer2 B3)', sprintf('%.2f s', $took));
check(VaultUnlock::secretKey($user_id) instanceof PoolVaultKey, 'and that window reads from the pool');
$facts = array('installed' => true, 'asked' => true, 'answering' => false, 'socket' => $sock, 'error' => 'connect',
	'down_since' => VaultUnlock::daemonDownSince());
check(strpos(VaultHealth::checkUnsealDaemon($facts)['reason'], 'held in the PHP pool') !== false, 'the health row says new windows are in the pool');
VaultUnlock::close($user_id);
dvk_start($bin, $sock, $hold, $uid);
$opened = VaultUnlock::open($user_id, $wrapping->unlocker($fx['kek']));
check($opened['key'] instanceof DaemonVaultKey, 'once it answers, the next unlock uses the daemon');
check(VaultUnlock::daemonDownSince() === null, 'and the silence is forgotten');
VaultUnlock::close($user_id);

// ---------------------------------------------------------------------------
section('The chain of retired keys');
// A chain entry as one was written before the previous-key scope: its KEK
// sealed as a DEK is.
$retired = (new SealedBox())->generateKeypair();
$chain_kek = random_bytes(32);
$entry = array('generation' => 0, 'wrapped' => (new SealedBox())->wrapKey(PoolVaultKey::wrappedForm($retired['secret']), $chain_kek, VaultPreviousKeys::ad(0)),
	'sealed_kek' => $crypto->sealItemDek($chain_kek, $public));
DbConnector::get_instance()->get_db_link()->prepare('UPDATE uev_user_encryption_vaults SET uev_previous_keys = ? WHERE uev_user_encryption_vault_id = ?')
	->execute(array(json_encode(array($entry)), (int)$vault->key));
check(array_keys(VaultPreviousKeys::unscoped($user_id)) === array(0), 'the old entry has no scope');
$opened = VaultUnlock::open($user_id, $wrapping->unlocker($fx['kek']));
$vault = new UserEncryptionVault((int)$vault->key, TRUE);
$entries = VaultPreviousKeys::entries($vault);
check(($entries[0]['scope'] ?? '') === SealedBox::SCOPE_PREVIOUS_KEY, 'the unlock re-sealed it under the previous-key scope');
check($entries[0]['sealed_kek'] !== $entry['sealed_kek'], 'with a new sealed KEK');
$key = VaultUnlock::secretKey($user_id);
$threw = false;
try { $crypto->openItemDek($entries[0]['sealed_kek'], $key); } catch (Throwable $e) { $threw = !($e instanceof VaultLockedException); }
check($threw, 'unwrap never opens a chain KEK (B2)');
$old_dek = random_bytes(32);
$on_retired = $crypto->sealItemDek($old_dek, $retired['public']);
$previous = VaultPreviousKeys::open($vault, $key);
check(isset($previous[0]) && $previous[0] instanceof DaemonVaultKey, 'the retired key opens as a child window');
check(isset($previous[0]) && $crypto->openItemDek($on_retired, $previous[0]) === $old_dek, 'and opens content still sealed to it');
check(VaultUnlock::isOpen($user_id), 'the window is still open');

section('A rotation re-seals the chain from what its open revealed');
VaultUnlock::close($user_id);
SealedEgressGuard::reset();
$set = vault_fixture_code_set($fx['root_salt'], 5);
$twins = vault_fixture_root_recovery($fx['root_salt'], $set, $fx['root_secret']);
$rotated = (new VaultCeremonies())->rotate($user, new UserEncryptionVault((int)$vault->key, TRUE), (int)$fx['passkey']->key, 'label',
	$fx['kek'], '', $set['set'], array('recovery' => $twins), false);
$vault = new UserEncryptionVault((int)$vault->key, TRUE);
$gens = array_column(VaultPreviousKeys::entries($vault), 'generation');
check(in_array(0, $gens, true) && in_array(1, $gens, true), 'the chain holds the old entry and the generation the rotation retired', json_encode($gens));
$all_scoped = true;
foreach (VaultPreviousKeys::entries($vault) as $e) {
	$all_scoped = $all_scoped && (($e['scope'] ?? '') === SealedBox::SCOPE_PREVIOUS_KEY);
}
check($all_scoped, 'every entry is sealed under the previous-key scope');
$new_wrapping = null;
foreach (new MultiUserEncryptionWrapping(array('vault_id' => (int)$vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_PASSKEY)) as $w) {
	if ((int)$w->get('uew_key_generation') === (int)$vault->get('uev_key_generation')) {
		$new_wrapping = $w;
	}
}
$opened = VaultUnlock::open($user_id, $new_wrapping->unlocker($fx['kek']));
$previous = VaultPreviousKeys::open($vault, VaultUnlock::secretKey($user_id));
check(isset($previous[0]) && $crypto->openItemDek($on_retired, $previous[0]) === $old_dek, 'the new key still reaches the oldest retired key');
VaultUnlock::close($user_id);
check(dvk_status($sock, $user_id)['open'] === false, 'closing the window closed its children');

harness_finish();
