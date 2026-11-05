<?php
/** @joinery-test
 * name: unseal_daemon
 * tier: safe
 * env: any
 * needs: []
 * timeout: 120
 *
 * The unseal daemon, the shipped binary for this box's architecture, driven
 * from PHP on a temporary socket (specs/unseal_daemon.md WP2). Needs no
 * install: it copies maintenance_scripts/install_tools/joinery_unseal/bin/
 * joinery-unseal-<uname -m> to a temporary directory, runs it as this user,
 * and skips when the tree has no binary for this machine.
 *
 * What it holds the daemon to, against PHP's own crypto (SealedBox,
 * PoolVaultKey) so the two ends agree byte for byte:
 *  - open with a wrapped secret PHP made, and open with nothing (a mint) whose
 *    wrappings PHP opens back to the same key;
 *  - unwrap of DEKs PHP sealed, as one list, all or nothing; the chain's
 *    previous-key scope refused;
 *  - the reveal list: refused on a mint, scoped on an unlock;
 *  - open_previous: a retired key held as a child, opening content sealed to
 *    it, closed with its parent;
 *  - the idle and absolute clocks, and the seven-day ceiling;
 *  - close by user id; status for a user; a handle after close; a wrong KEK;
 *    an oversized request; a garbage frame; one connection reused;
 *  - that no reply, of any request, carries a secret in any form but a
 *    wrapping from open;
 *  - SIGUSR1 dropping every window and holding, SIGUSR2 lifting it, and the
 *    hold file making a restarted daemon start held;
 *  - ping and --selftest.
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$machine = php_uname('m');
$shipped = dirname(PathHelper::getIncludePath('includes')) . '/../maintenance_scripts/install_tools/joinery_unseal/bin/joinery-unseal-' . $machine;
$shipped = realpath($shipped) ?: $shipped;
if (!is_file($shipped)) {
	harness_skip('the tree ships no unseal daemon for ' . $machine, $shipped);
	harness_finish();
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
	harness_skip('running as root: the daemon refuses to serve as root');
	harness_finish();
}
if (SealedBox::pqReadyReason() !== null) {
	harness_skip('this process cannot seal with post-quantum keys', (string)SealedBox::pqReadyReason());
	harness_finish();
}

$dir = sys_get_temp_dir() . '/ud_' . getmypid() . '_' . bin2hex(random_bytes(3));
mkdir($dir, 0700);
$bin = $dir . '/joinery-unseal';
copy($shipped, $bin);
chmod($bin, 0700);
$sock = $dir . '/u.sock';
$hold = $dir . '/hold';
$uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
$USR1 = defined('SIGUSR1') ? SIGUSR1 : 10;
$USR2 = defined('SIGUSR2') ? SIGUSR2 : 12;
$TERM = defined('SIGTERM') ? SIGTERM : 15;

$GLOBALS['ud_proc'] = null;
$GLOBALS['ud_replies'] = array(); // every reply's raw bytes, for the no-secret check

/** Start the daemon; true once its socket answers. */
function ud_start(string $bin, string $sock, string $hold, int $uid): bool {
	ud_stop();
	$cmd = array($bin, '--socket', $sock, '--allow-uid', (string)$uid, '--hold-file', $hold,
		'--unlocked-memory', '--max-frame-bytes', '1048576', '--memory-budget', '4194304', '--max-windows', '50');
	$GLOBALS['ud_proc'] = proc_open($cmd, array(0 => array('file', '/dev/null', 'r'),
		1 => array('file', dirname($sock) . '/out.log', 'a'), 2 => array('file', dirname($sock) . '/out.log', 'a')), $pipes);
	for ($i = 0; $i < 100; $i++) {
		if (file_exists($sock) && ($c = @stream_socket_client('unix://' . $sock, $e, $s, 1))) {
			fclose($c);
			return true;
		}
		usleep(50000);
	}
	return false;
}

function ud_pid(): int {
	$s = $GLOBALS['ud_proc'] ? proc_get_status($GLOBALS['ud_proc']) : null;
	return ($s && $s['running']) ? (int)$s['pid'] : 0;
}

function ud_stop(): void {
	if ($GLOBALS['ud_proc']) {
		if ($pid = ud_pid()) {
			posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
		}
		for ($i = 0; $i < 50 && ud_pid(); $i++) {
			usleep(50000);
		}
		proc_close($GLOBALS['ud_proc']);
		$GLOBALS['ud_proc'] = null;
	}
}

function ud_write($c, string $bytes): bool {
	for ($at = 0; $at < strlen($bytes); $at += $n) {
		$n = @fwrite($c, substr($bytes, $at, 1 << 16));
		if (!$n) {
			return false;
		}
	}
	return true;
}

function ud_read($c, int $n) {
	$out = '';
	while (strlen($out) < $n) {
		$chunk = fread($c, $n - strlen($out));
		if ($chunk === false || $chunk === '') {
			return false;
		}
		$out .= $chunk;
	}
	return $out;
}

/** One request on connection $c (or a fresh one): ['fields' => array, 'parts' => string[]]. */
function ud_call(string $sock, array $fields, array $parts = array(), $c = null): array {
	$own = $c === null;
	if ($own) {
		$c = stream_socket_client('unix://' . $sock, $errno, $errstr, 3);
		if (!$c) {
			return array('fields' => array('ok' => false, 'error' => 'connect'), 'parts' => array());
		}
		stream_set_timeout($c, 10);
	}
	if ($parts) {
		$fields['parts'] = array_map('strlen', array_values($parts));
	}
	$h = json_encode($fields);
	$body = implode('', $parts);
	$dead = array('fields' => array('ok' => false, 'error' => 'closed'), 'parts' => array());
	if (!ud_write($c, pack('N', strlen($h)) . $h . pack('N', strlen($body)) . $body)) {
		return $dead;
	}
	$hl = ud_read($c, 4);
	if ($hl === false) {
		return $dead;
	}
	$hdr = ud_read($c, unpack('N', $hl)[1]);
	$bl = ud_read($c, 4);
	$blen = $bl === false ? 0 : unpack('N', $bl)[1];
	$rbody = $blen ? ud_read($c, $blen) : '';
	if ($own) {
		fclose($c);
	}
	$GLOBALS['ud_replies'][] = $hdr . $rbody;
	$out = array('fields' => json_decode((string)$hdr, true) ?: array(), 'parts' => array());
	$at = 0;
	foreach ($out['fields']['parts'] ?? array() as $n) {
		$out['parts'][] = substr((string)$rbody, $at, $n);
		$at += $n;
	}
	return $out;
}

function ud_ok(array $r): bool {
	return ($r['fields']['ok'] ?? false) === true;
}

function ud_err(array $r): string {
	return (string)($r['fields']['error'] ?? '');
}

/** The raw v2 form of a DEK sealed to a base64url public key, as VaultCrypto hands it to unseal(). */
function ud_seal(string $bytes, string $public_b64url): string {
	return SealedBox::unframeSeal((new SealedBox())->sealDek($bytes, $public_b64url))[1];
}

harness_defer(function () use ($dir) {
	ud_stop();
	foreach (glob($dir . '/*') ?: array() as $f) {
		@unlink($f);
	}
	@rmdir($dir);
});

$box = new SealedBox();
$seed_forms = array(); // every secret this suite knows, in every form, for the no-secret check
$remember = function (string $secret_b64url) use (&$seed_forms) {
	$seed_forms[] = $secret_b64url;
	$seed_forms[] = SealedBox::b64url_decode($secret_b64url);
	$seed_forms[] = PoolVaultKey::wrappedForm($secret_b64url);
};

// ---------------------------------------------------------------------------
section('The binary');

exec(escapeshellarg($bin) . ' --selftest 2>&1', $o, $rc);
check($rc === 0, '--selftest passes on this machine', implode(' ', $o));
check(ud_start($bin, $sock, $hold, $uid), 'the daemon starts and answers on its socket', (string)@file_get_contents($dir . '/out.log'));
check((fileperms($sock) & 0777) === 0660, 'the socket is mode 0660', sprintf('%o', fileperms($sock) & 0777));
$st = ud_call($sock, array('op' => 'status'));
check(ud_ok($st) && $st['fields']['windows'] === 0 && $st['fields']['held'] === false && $st['fields']['daemon'] !== '',
	'status answers: a version, no windows, not held', json_encode($st['fields']));
exec(escapeshellarg($bin) . ' ping --socket ' . escapeshellarg($sock) . ' 2>&1', $o, $rc);
check($rc === 0, 'ping answers 0 for a running daemon');
exec(escapeshellarg($bin) . ' --socket ' . escapeshellarg($sock) . ' --allow-uid ' . $uid . ' --unlocked-memory 2>&1', $o2, $rc2);
check($rc2 !== 0 && strpos(implode(' ', $o2), 'another joinery-unseal') !== false && file_exists($sock)
	&& ud_ok(ud_call($sock, array('op' => 'status'))),
	'a second daemon on the same socket refuses to start, and the first keeps its socket', implode(' ', $o2));
exec(escapeshellarg($bin) . ' ping --timeout 1 --socket ' . escapeshellarg($dir . '/nothing.sock') . ' 2>&1', $o, $rc);
check($rc !== 0, 'ping fails where nothing listens');

// ---------------------------------------------------------------------------
section('open: a wrapping PHP made, and a mint PHP opens back');

$kek = random_bytes(32);
$ad = 'vault:user:7:wrapping:41';
$pool = PoolVaultKey::open(null, array(array('kek' => $kek, 'ad' => $ad)));
$wrapped = $pool['wrappings'][0];
$secret = $box->unwrapKey($wrapped, $kek, $ad); // 'v2.' + seed, the test knows it
$remember(substr($secret, 3));

$o = ud_call($sock, array('op' => 'open', 'user' => 7, 'scope' => 'vault', 'idle' => 600, 'absolute' => 3600, 'unlock' => true),
	array($wrapped, $kek, $ad));
check(ud_ok($o) && strlen($o['fields']['handle'] ?? '') === 64 && ($o['fields']['version'] ?? 0) === 2,
	'an unlock answers a handle and version 2', json_encode($o['fields']));
check(SealedBox::b64url($o['parts'][0] ?? '') === $pool['key']->publicKey(), 'its public key is the one PoolVaultKey derives');
$h = (string)($o['fields']['handle'] ?? '');

$bad = ud_call($sock, array('op' => 'open', 'user' => 7, 'scope' => 'vault', 'idle' => 600, 'unlock' => true),
	array($wrapped, random_bytes(32), $ad));
check(ud_err($bad) === 'unlock_failed', 'a wrong KEK is unlock_failed', ud_err($bad));
$bad = ud_call($sock, array('op' => 'open', 'user' => 7, 'scope' => 'vault', 'idle' => 600, 'unlock' => true),
	array($wrapped, $kek, 'vault:user:7:wrapping:42'));
check(ud_err($bad) === 'unlock_failed', 'a wrapping spliced onto another row (another AD) is unlock_failed');

$k1 = random_bytes(32);
$k2 = random_bytes(32);
$m = ud_call($sock, array('op' => 'open', 'user' => 9, 'scope' => 'vault', 'idle' => 600, 'wrap' => 2),
	array($k1, 'vault:user:9:wrapping:1', $k2, 'vault:user:9:wrapping:2'));
check(ud_ok($m) && count($m['parts']) === 3, 'a mint with a wrap list answers a public key and two wrappings');
$minted_public = SealedBox::b64url($m['parts'][0] ?? '');
$back1 = PoolVaultKey::open(array('wrapped' => $m['parts'][1] ?? '', 'kek' => $k1, 'ad' => 'vault:user:9:wrapping:1'))['key'];
$back2 = PoolVaultKey::open(array('wrapped' => $m['parts'][2] ?? '', 'kek' => $k2, 'ad' => 'vault:user:9:wrapping:2'))['key'];
check($back1->publicKey() === $minted_public && $back2->publicKey() === $minted_public,
	'PHP opens both wrappings to the key the daemon minted');
$remember(substr($box->unwrapKey($m['parts'][1], $k1, 'vault:user:9:wrapping:1'), 3));

// ---------------------------------------------------------------------------
section('unwrap: DEKs PHP sealed, one list, the content scope only');

$deks = array(random_bytes(32), random_bytes(32), str_repeat('a held Direct part ', 2000));
$u = ud_call($sock, array('op' => 'unwrap', 'handle' => $h, 'activity' => true),
	array_map(function ($d) use ($pool) { return ud_seal($d, $pool['key']->publicKey()); }, $deks));
check(ud_ok($u) && $u['parts'] === $deks, 'three sealed DEKs come back in order, in one trip');
$foreign = ud_seal('x', $minted_public);
$u = ud_call($sock, array('op' => 'unwrap', 'handle' => $h), array(ud_seal('y', $pool['key']->publicKey()), $foreign));
check(ud_err($u) === 'unseal_failed' && $u['parts'] === array(), 'one blob sealed to another key fails the whole list, nothing partial');
$chain = SealedBox::sealV2(random_bytes(32), SealedBox::b64url_decode($pool['key']->publicKey()), 'previous-key');
$u = ud_call($sock, array('op' => 'unwrap', 'handle' => $h), array($chain));
check(ud_err($u) === 'unseal_failed', 'a blob of the previous-key scope never opens through unwrap (B2)');
$u = ud_call($sock, array('op' => 'unwrap', 'handle' => str_repeat('0', 64)), array(ud_seal('z', $pool['key']->publicKey())));
check(ud_err($u) === 'unknown_handle', 'a handle the daemon never minted is unknown_handle');

// ---------------------------------------------------------------------------
section('The reveal list and open_previous');

$chain_kek = random_bytes(32);
$sealed_new = SealedBox::sealV2($chain_kek, SealedBox::b64url_decode($pool['key']->publicKey()), 'previous-key');
$sealed_old = ud_seal($chain_kek, $pool['key']->publicKey()); // a chain from before the scope split
$r = ud_call($sock, array('op' => 'open', 'user' => 7, 'scope' => 'vault', 'idle' => 600, 'reveal' => 1), array($sealed_new));
check(ud_err($r) === 'bad_request', 'a reveal list on an open with no unlocker is refused');
$r = ud_call($sock, array('op' => 'open', 'user' => 7, 'scope' => 'vault', 'idle' => 600, 'unlock' => true, 'reveal' => 2),
	array($wrapped, $kek, $ad, $sealed_new, $sealed_old));
check(ud_ok($r) && ($r['fields']['revealed'] ?? null) === array('previous-key', 'dek')
	&& ($r['parts'][1] ?? '') === $chain_kek && ($r['parts'][2] ?? '') === $chain_kek,
	'with an unlocker, each chain KEK comes back with the scope it opened under', json_encode($r['fields']));
ud_call($sock, array('op' => 'close', 'handle' => (string)($r['fields']['handle'] ?? '')));

// A retired generation, wrapped and sealed as VaultPreviousKeys holds it.
$retired = PoolVaultKey::open(null, array(array('kek' => $chain_kek, 'ad' => VaultPreviousKeys::ad(3))));
$remember(substr($box->unwrapKey($retired['wrappings'][0], $chain_kek, VaultPreviousKeys::ad(3)), 3));
$p = ud_call($sock, array('op' => 'open_previous', 'handle' => $h), array($sealed_new, $retired['wrappings'][0], VaultPreviousKeys::ad(3)));
check(ud_ok($p) && SealedBox::b64url($p['parts'][0] ?? '') === $retired['key']->publicKey(),
	'open_previous holds the retired key and answers its public key, never the key');
$child = (string)($p['fields']['handle'] ?? '');
$old_dek = random_bytes(32);
$u = ud_call($sock, array('op' => 'unwrap', 'handle' => $child, 'activity' => true), array(ud_seal($old_dek, $retired['key']->publicKey())));
check(ud_ok($u) && ($u['parts'][0] ?? '') === $old_dek, 'the child opens content still sealed to the retired key');
$p2 = ud_call($sock, array('op' => 'open_previous', 'handle' => $h), array($sealed_new, $retired['wrappings'][0], VaultPreviousKeys::ad(3)));
check(($p2['fields']['handle'] ?? '') === $child, 'the same entry again is the same child');
$p3 = ud_call($sock, array('op' => 'open_previous', 'handle' => $h), array($sealed_new, $retired['wrappings'][0], VaultPreviousKeys::ad(4)));
check(ud_err($p3) === 'unlock_failed', 'an entry under another generation\'s AD does not open');

// ---------------------------------------------------------------------------
section('close, status, and a handle after close');

$a = ud_call($sock, array('op' => 'open', 'user' => 21, 'scope' => 'vault', 'idle' => 600));
ud_call($sock, array('op' => 'open', 'user' => 21, 'scope' => 'mail', 'idle' => 600));
ud_call($sock, array('op' => 'open', 'user' => 22, 'scope' => 'vault', 'idle' => 600));
$s = ud_call($sock, array('op' => 'status', 'user' => 21));
check(($s['fields']['open'] ?? null) === true && !isset($s['fields']['users']), 'status for a user says only whether they hold a window');
$c = ud_call($sock, array('op' => 'close', 'user' => 21));
check(ud_ok($c) && $c['fields']['closed'] === 2, 'close by user id ends both of that user\'s windows', json_encode($c['fields']));
check((ud_call($sock, array('op' => 'status', 'user' => 21))['fields']['open'] ?? null) === false
	&& (ud_call($sock, array('op' => 'status', 'user' => 22))['fields']['open'] ?? null) === true,
	'and no one else\'s');
$u = ud_call($sock, array('op' => 'unwrap', 'handle' => (string)($a['fields']['handle'] ?? '')), array(ud_seal('q', SealedBox::b64url($a['parts'][0] ?? ''))));
check(ud_err($u) === 'unknown_handle', 'a handle after close is unknown_handle');
$c = ud_call($sock, array('op' => 'close', 'handle' => $h));
check(ud_ok($c) && $c['fields']['closed'] === 2 && $c['fields']['unwraps'] === 4,
	'closing a window closes its child and reports the unwraps that opened (3 on it, 1 on its child; failed lists count nothing)', json_encode($c['fields']));
$u = ud_call($sock, array('op' => 'unwrap', 'handle' => $child), array(ud_seal($old_dek, $retired['key']->publicKey())));
check(ud_err($u) === 'unknown_handle', 'the child ended with its parent');

// ---------------------------------------------------------------------------
section('The clocks');

$i = ud_call($sock, array('op' => 'open', 'user' => 31, 'scope' => 'vault', 'idle' => 1, 'absolute' => 60));
$ipub = SealedBox::b64url($i['parts'][0] ?? '');
usleep(1300000);
$u = ud_call($sock, array('op' => 'unwrap', 'handle' => (string)($i['fields']['handle'] ?? '')), array(ud_seal('i', $ipub)));
check(ud_err($u) === 'unknown_handle', 'a window idle past its idle seconds is gone');

$x = ud_call($sock, array('op' => 'open', 'user' => 32, 'scope' => 'vault', 'idle' => 1, 'absolute' => 2));
$xpub = SealedBox::b64url($x['parts'][0] ?? '');
$alive = true;
for ($n = 0; $n < 3; $n++) {
	usleep(600000);
	$alive = $alive && ud_ok(ud_call($sock, array('op' => 'unwrap', 'handle' => (string)$x['fields']['handle'], 'activity' => true), array(ud_seal('x', $xpub))));
}
usleep(400000);
$u = ud_call($sock, array('op' => 'unwrap', 'handle' => (string)$x['fields']['handle'], 'activity' => true), array(ud_seal('x', $xpub)));
check($alive && ud_err($u) === 'unknown_handle', 'activity keeps the idle clock fresh, and the absolute cap ends the window anyway');

$v = ud_call($sock, array('op' => 'open', 'user' => 33, 'scope' => 'vault', 'idle' => 60, 'absolute' => 7 * 86400 + 1));
check(ud_err($v) === 'bad_request', 'an absolute cap past seven days is refused');
$v = ud_call($sock, array('op' => 'open', 'user' => 33, 'scope' => 'vault', 'idle' => 60, 'absolute' => 7 * 86400));
check(ud_ok($v), 'seven days exactly is held');
ud_call($sock, array('op' => 'close', 'user' => 33));

// ---------------------------------------------------------------------------
section('The wire');

$conn = stream_socket_client('unix://' . $sock, $errno, $errstr, 3);
stream_set_timeout($conn, 10);
$t1 = ud_call($sock, array('op' => 'status'), array(), $conn);
$t2 = ud_call($sock, array('op' => 'status', 'user' => 1), array(), $conn);
$t3 = ud_call($sock, array('op' => 'status'), array(), $conn);
check(ud_ok($t1) && ud_ok($t2) && ud_ok($t3), 'one connection carries several trips');
fclose($conn);

// The cap is read from the length prefix, before any body byte arrives: the
// reply comes back to a client that has sent only the frame's head.
$g = stream_socket_client('unix://' . $sock, $errno, $errstr, 3);
stream_set_timeout($g, 5);
$hh = '{"op":"unwrap","handle":"' . str_repeat('a', 64) . '","parts":[1100000]}';
fwrite($g, pack('N', strlen($hh)) . $hh . pack('N', 1100000));
$hl = ud_read($g, 4);
$big = $hl === false ? array() : (json_decode((string)ud_read($g, unpack('N', $hl)[1]), true) ?: array());
$after = fread($g, 16);
fclose($g);
check(($big['error'] ?? '') === 'too_large', 'a request over the frame cap is too_large, answered from its length alone', json_encode($big));
check($after === "\x00\x00\x00\x00" || $after === '' || $after === false, 'and that connection ends');
$g = stream_socket_client('unix://' . $sock, $errno, $errstr, 3);
stream_set_timeout($g, 5);
fwrite($g, "\xff\xff\xff\xff{\"op\"");
$gr = fread($g, 1);
fclose($g);
check($gr === '' || $gr === false, 'a frame whose header length is out of bounds ends its connection unanswered');
$g = stream_socket_client('unix://' . $sock, $errno, $errstr, 3);
fwrite($g, "\x00\x00\x00\x07{\"op\":1}\x00\x00\x00\x00");
fclose($g);
$st = ud_call($sock, array('op' => 'status'));
check(ud_ok($st) && ud_pid() > 0, 'and the daemon is still serving', json_encode($st['fields']));
$e = ud_call($sock, array('op' => 'open', 'user' => 1, 'scope' => 'vault', 'idle' => 60, 'export' => true));
check(ud_err($e) === 'bad_request', 'a field the protocol does not have is refused');
$e = ud_call($sock, array('op' => 'wrap', 'handle' => str_repeat('a', 64)));
check(ud_err($e) === 'unknown_op', 'there is no wrap operation (B1)');

// ---------------------------------------------------------------------------
section('No reply carries a secret');

$leaks = 0;
foreach ($GLOBALS['ud_replies'] as $reply) {
	foreach ($seed_forms as $needle) {
		if ($needle !== '' && strpos($reply, $needle) !== false) {
			$leaks++;
		}
	}
}
check(count($seed_forms) >= 9 && count($GLOBALS['ud_replies']) > 30 && $leaks === 0,
	'none of ' . count($GLOBALS['ud_replies']) . ' replies holds any of the ' . (count($seed_forms) / 3) . ' secrets, raw, encoded or tagged',
	$leaks . ' found');

// ---------------------------------------------------------------------------
section('The sentinel\'s drop and hold');

$d = ud_call($sock, array('op' => 'open', 'user' => 41, 'scope' => 'vault', 'idle' => 600));
$dpub = SealedBox::b64url($d['parts'][0] ?? '');
posix_kill(ud_pid(), $USR1);
usleep(300000);
$u = ud_call($sock, array('op' => 'unwrap', 'handle' => (string)($d['fields']['handle'] ?? '')), array(ud_seal('d', $dpub)));
check(ud_err($u) === 'unknown_handle', 'SIGUSR1 ends every window');
$o = ud_call($sock, array('op' => 'open', 'user' => 41, 'scope' => 'vault', 'idle' => 600, 'unlock' => true), array($wrapped, $kek, $ad));
check(ud_err($o) === 'held' && (ud_call($sock, array('op' => 'status'))['fields']['held'] ?? null) === true,
	'and every open is refused as held, even with a good unlocker');
posix_kill(ud_pid(), $USR2);
usleep(300000);
check(ud_ok(ud_call($sock, array('op' => 'open', 'user' => 41, 'scope' => 'vault', 'idle' => 600))), 'SIGUSR2 lifts the hold');

touch($hold);
ud_stop();
check(!file_exists($sock), 'SIGTERM ends the daemon and removes its socket');
check(ud_start($bin, $sock, $hold, $uid), 'a restart with the hold file present comes up');
check((ud_call($sock, array('op' => 'status'))['fields']['held'] ?? null) === true
	&& ud_err(ud_call($sock, array('op' => 'open', 'user' => 41, 'scope' => 'vault', 'idle' => 600))) === 'held',
	'and starts held: crashing it does not clear the hold');
unlink($hold);
ud_start($bin, $sock, $hold, $uid);
check((ud_call($sock, array('op' => 'status'))['fields']['held'] ?? null) === false
	&& ud_call($sock, array('op' => 'status'))['fields']['windows'] === 0,
	'without the file it starts unheld, holding nothing from before');

harness_finish();
