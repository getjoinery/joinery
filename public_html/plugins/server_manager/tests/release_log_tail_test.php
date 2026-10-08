<?php
/** @joinery-test
 * name: release_log_tail
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * ReleaseLogTail (spec release_transparency, O5), against a log of the test's
 * own: a signed checkpoint and entry bundles served the way Rekor v2 serves
 * them, full and partial.
 *
 *  - a log is read from our first logged entry, and only up to a checkpoint
 *    seen SETTLE_SECONDS earlier
 *  - our own entry is matched to the ledger and reported seen
 *  - an entry under our key that the ledger does not hold, or holds with other
 *    bytes, is unaccounted for, once, and stops counting when the ledger holds it
 *  - an entry under another key, or one that only mentions ours, is not ours
 *  - the time budget stops a run, and the next carries on
 *  - a checkpoint signed by another key, or smaller than one already seen, is refused and reported
 *  - blind: task missing, a log not read up to date for a day (warning), a week (critical)
 *  - the log keys releases installed, checked against a trusted root: a key
 *    the root lists for that log passes, another key or another log's does not;
 *    no completed check for three days is blind
 *
 * Run: php tests/run.php safe --filter=release_log_tail
 *
 * @version 1.2 - a checkpoint smaller than one already seen
 * @version 1.1 - the log keys releases installed, checked against Sigstore's trusted root
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$origin = 'log.tail-test.example';
$log_pair = sodium_crypto_sign_keypair();
$log_der = hex2bin('302a300506032b6570032100') . sodium_crypto_sign_publickey($log_pair);

/** A P-256 public key's DER. */
function rlt_p256_der() {
	$k = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
	return base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', openssl_pkey_get_details($k)['key']));
}
/** A hashedrekord leaf under $der, as Rekor writes one. */
function rlt_leaf($der, $n, $content = null) {
	return TransparencyProof::canonicalJson(array('apiVersion' => '0.0.2', 'kind' => 'hashedrekord', 'spec' => array('hashedRekordV002' => array(
		'data' => array('algorithm' => 'SHA2_256', 'digest' => base64_encode(hash('sha256', 'statement ' . $n, true))),
		'signature' => array('content' => $content ?? base64_encode('sig ' . $n),
			'verifier' => array('keyDetails' => 'PKIX_ECDSA_P256_SHA_256', 'publicKey' => array('rawBytes' => base64_encode($der))))))));
}

$ours = rlt_p256_der();
$other = rlt_p256_der();
$leaves = array();
for ($i = 0; $i < 1300; $i++) {
	$leaves[$i] = rlt_leaf($other, $i);
}
$leaves[600] = rlt_leaf($ours, 600);                                  // our genesis, in the ledger
$leaves[700] = rlt_leaf($other, 700, base64_encode($ours));           // mentions our key, signed by another
$leaves[900] = rlt_leaf($ours, 900);                                  // a stranger under our key
$leaves[950] = rlt_leaf($ours, 950);                                  // the ledger holds 950 with other bytes
$leaves[1100] = rlt_leaf($ours, 1100);                                // past the first settled checkpoint
$ledger = array(
	"{$origin}#600" => array('id' => 1, 'leaf' => $leaves[600]),
	"{$origin}#950" => array('id' => 2, 'leaf' => rlt_leaf($ours, 9500)),
);

$size = 1000;
$signer = $log_pair;
$asked = array();
$transport = function ($url) use (&$size, &$signer, &$asked, $origin, $leaves) {
	$asked[] = $url;
	if ($url === "https://{$origin}/api/v2/checkpoint") {
		$body = "{$origin}\n{$size}\n" . base64_encode(str_repeat("\x01", 32)) . "\n";
		$kh = substr(hash('sha256', $origin . "\n\x01" . sodium_crypto_sign_publickey($signer), true), 0, 4);
		return array('status' => 200, 'body' => $body . "\n\u{2014} {$origin} " . base64_encode($kh . sodium_crypto_sign_detached($body, sodium_crypto_sign_secretkey($signer))) . "\n");
	}
	$prefix = "https://{$origin}/api/v2/tile/entries/";
	if (strpos($url, $prefix) !== 0) { return array('status' => 404, 'body' => ''); }
	$path = substr($url, strlen($prefix));
	$width = 256;
	if (preg_match('#^(.*)\.p/(\d+)$#', $path, $m)) { $path = $m[1]; $width = (int)$m[2]; }
	$tile = (int)str_replace(array('x', '/'), '', $path);
	if ($tile * 256 + $width > $size) { return array('status' => 404, 'body' => ''); }
	$bytes = '';
	for ($i = $tile * 256; $i < $tile * 256 + $width; $i++) { $bytes .= pack('n', strlen($leaves[$i])) . $leaves[$i]; }
	return array('status' => 200, 'body' => $bytes);
};
$reader = new LogTileReader($transport);
$keys = array($origin => $log_der);
$t0 = 1800000000;
$far = microtime(true) + 60;

section('The first run reads nothing until its checkpoint settles');

$state = ReleaseLogTail::read($reader, array(), array($ours), $keys, $ledger, $t0, $far);
$log = $state['logs'][$origin];
check($log['from'] === 600 && $log['next'] === 600, 'the log is read from our first logged entry', json_encode($log));
check($log['caught_up_at'] === null && !$state['unaccounted'] && !$state['seen'], 'a checkpoint seen just now is not read up to, nor called caught up');

section('A settled checkpoint is read up to, and every entry under our key accounted for');

$size = 1300;
$state = ReleaseLogTail::read($reader, $state, array($ours), $keys, $ledger, $t0 + 700, $far);
$log = $state['logs'][$origin];
check($log['next'] === 1000 && $log['target'] === 1000 && $log['caught_up_at'] === $t0 + 700,
	'read to the settled checkpoint (1000), not the newest (1300)', json_encode($log));
check(in_array("https://{$origin}/api/v2/tile/entries/003.p/232", $asked, true), 'the bundle the checkpoint ends inside is read as a partial one');
check($state['seen'] === array(1), 'our own entry is matched to its ledger row', json_encode($state['seen']));
$found = array_column($state['unaccounted'], 'why', 'index');
check(count($found) === 2 && isset($found[900], $found[950]), 'two entries under our key are unaccounted for', json_encode($found));
check(strpos($found[900], 'no publish here') === 0 && strpos($found[950], 'differs') !== false, 'each says why');
check(!isset($found[700]), 'an entry that only mentions our key, signed by another, is not ours');

section('The next run carries on, and reports nothing twice');

$state = ReleaseLogTail::read($reader, $state, array($ours), $keys, $ledger, $t0 + 1400, $far);
check($state['logs'][$origin]['next'] === 1300 && count($state['unaccounted']) === 3,
	'read on to 1300, finding 1100 and no repeat', json_encode(array_column($state['unaccounted'], 'index')));

section('The ledger decides, each time it is asked');

check(count(ReleaseLogTail::unaccounted($state, $ledger)) === 3, 'three are unaccounted for');
$later = $ledger + array("{$origin}#900" => array('id' => 3, 'leaf' => $leaves[900]));
check(array_column(ReleaseLogTail::unaccounted($state, $later), 'index') === array(950, 1100),
	'an entry the ledger comes to hold, byte for byte, stops counting');
$wrong = $ledger + array("{$origin}#1100" => array('id' => 4, 'leaf' => 'other bytes'));
check(count(ReleaseLogTail::unaccounted($state, $wrong)) === 3, 'one the ledger holds with other bytes still counts');

section('The time budget, and a checkpoint under another key');

$size = 1600;
$seen_1600 = ReleaseLogTail::read($reader, $state, array($ours), $keys, $ledger, $t0 + 2100, $far);
$before = $seen_1600['logs'][$origin]['next'];
$stopped = ReleaseLogTail::read($reader, $seen_1600, array($ours), $keys, $ledger, $t0 + 2900, microtime(true) - 1);
check($before === 1300 && $stopped['logs'][$origin]['target'] === 1600 && $stopped['logs'][$origin]['next'] === 1300
	&& $stopped['logs'][$origin]['caught_up_at'] === $t0 + 2100,
	'a run out of time reads nothing more and is not caught up', json_encode($stopped['logs'][$origin]));
$resumed = ReleaseLogTail::read($reader, $stopped, array($ours), $keys, $ledger, $t0 + 3000, $far);
check($resumed['logs'][$origin]['next'] === 1600 && $resumed['logs'][$origin]['caught_up_at'] === $t0 + 3000, 'the next run carries on to the end');
$signer = sodium_crypto_sign_keypair();
$refused = ReleaseLogTail::read($reader, $state, array($ours), $keys, $ledger, $t0 + 2100, $far);
check($refused['logs'][$origin]['next'] === $before && strpos((string)$refused['last_error'], $origin) !== false,
	'a checkpoint signed by another key is refused, and the run says so', (string)$refused['last_error']);
$signer = $log_pair;
$size = 1500;
$shrunk = ReleaseLogTail::read($reader, $resumed, array($ours), $keys, $ledger, $t0 + 3700, $far);
check($shrunk['logs'][$origin] === $resumed['logs'][$origin] && strpos((string)$shrunk['last_error'], 'never shrinks') !== false,
	'a smaller checkpoint than one already seen is refused, and the log is not called caught up', (string)$shrunk['last_error']);

section('Blind');

$active = array('active' => true, 'created' => $t0);
check(ReleaseLogTail::blindCondition($state, null, array($origin), $t0 + 1400)['severity'] === 'warning', 'no task: blind');
check(ReleaseLogTail::blindCondition($state, $active, array($origin), $t0 + 1400) === null, 'caught up: clear');
$day = ReleaseLogTail::blindCondition($state, $active, array($origin), $t0 + 1400 + 86400 + 60);
check($day !== null && $day['severity'] === 'warning' && strpos($day['detail']['Why'], $origin) !== false,
	'a day without catching up: a warning naming the log', json_encode($day));
check(ReleaseLogTail::blindCondition($state, $active, array($origin), $t0 + 1400 + 8 * 86400)['severity'] === 'critical', 'a week: critical');
$new = ReleaseLogTail::blindCondition($state, $active, array($origin, 'log.never-read.example'), $t0 + 2 * 86400);
check($new !== null && strpos($new['detail']['Why'], 'never read') !== false, 'a log nodes trust that was never read counts too');

section('Log keys against the trusted root');

$ledger_keys = array(
	'a#1' => array('id' => 1, 'leaf' => 'x', 'log_keys' => array(array($origin, $log_der))),
	'a#2' => array('id' => 2, 'leaf' => 'y', 'log_keys' => array(array($origin, $log_der), array('log.other.example', $log_der))),
);
$installed = ReleaseLogTail::installedLogKeys($ledger_keys);
check(count($installed) === 2, 'each installed log key is checked once', json_encode(array_column($installed, 0)));
$trusted = array('tlogs' => array(array('baseUrl' => 'https://' . $origin, 'publicKey' => array('rawBytes' => base64_encode($log_der)))));
$rooted = ReleaseLogTail::checkRoot($state, $trusted, $installed, $t0 + 1400);
check($rooted['root']['checked'] === 2 && count($rooted['root']['unknown']) === 1
	&& $rooted['root']['unknown'][0] === array('origin' => 'log.other.example', 'fingerprint' => ReleaseLogTail::fingerprint($log_der)),
	'the root lists the key for its own log; the same key named for another log is not listed', json_encode($rooted['root']));
$other_key = hex2bin('302a300506032b6570032100') . str_repeat("\x07", 32);
$wrong = ReleaseLogTail::checkRoot($state, $trusted, array(array($origin, $other_key)), $t0 + 1400);
check(count($wrong['root']['unknown']) === 1, 'another key for a listed log is not listed');

$fresh_state = $state; $fresh_state['logs'][$origin]['caught_up_at'] = $t0 + 1400 + 3 * 86400;
$stale = ReleaseLogTail::blindCondition($fresh_state, $active, array($origin), $t0 + 1400 + 3 * 86400 + 60);
check($stale !== null && strpos($stale['detail']['Why'], 'trusted root') !== false, 'a log caught up but no key check for three days is blind', json_encode($stale));
$fresh_state = ReleaseLogTail::checkRoot($fresh_state, $trusted, $installed, $t0 + 1400 + 3 * 86400);
check(ReleaseLogTail::blindCondition($fresh_state, $active, array($origin), $t0 + 1400 + 3 * 86400 + 60) === null, 'checked: clear');

harness_finish();
