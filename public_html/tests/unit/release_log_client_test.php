<?php
/** @joinery-test
 * name: release_log_client
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The release log client and the offline proof checks (spec
 * release_transparency, WP1; D4, D5, D-C, O2):
 *
 *  - the RFC 6962 inclusion walk, against trees built here of every size up to
 *    20 and every leaf in them
 *  - discovery from a recorded copy of Sigstore's TUF CDN (2026-10-07): the
 *    live shard, its pinned checkpoint key, a target whose hash is wrong, a
 *    pin that is missing or different, a future shard whose key nodes do not
 *    hold yet (the shard-ahead refusal), no shard live
 *  - a recorded Rekor v2 answer (log2025-1, index 142362519, a throwaway
 *    P-256 key): accepted only with leaf, proof and checkpoint all present and
 *    all verified; refused for a stranger's checkpoint, a changed proof hash,
 *    a different statement, a leaf that is not canonical
 *  - the statement key: minted 0600 as P-256, signs an envelope that verifies,
 *    refused when the repository does not list it
 *
 * Runs offline, no DB: every HTTP call is answered from
 * tests/fixtures/release_log/. Run: php tests/unit/release_log_client_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$fx = __DIR__ . '/../fixtures/release_log';
$now = strtotime('2026-10-07T17:00:00Z');
$origin = 'log2025-1.rekor.sigstore.dev';
$shard_url = 'https://' . $origin;
$log_key = base64_decode('MCowBQYDK2VwAyEAt8rlp1knGwjfbcXAYPYAkn0XiLz1x8O4t0YkEhie244=');
$pins = array($origin => $log_key);

$envelope = json_decode(file_get_contents($fx . '/rekor/envelope.json'), true);
$statement_der = base64_decode(trim(file_get_contents($fx . '/rekor/statement_key.pub')));
$response = file_get_contents($fx . '/rekor/response.json');

/** A transport answering GETs from the recorded TUF tree, POSTs from $post. */
function rl_transport($fx, $post = null, array $override = array()) {
	return function ($method, $url, $body) use ($fx, $post, $override) {
		if ($method === 'POST') {
			return $post ? $post($url, $body) : array('status' => 500, 'body' => '{}');
		}
		$path = substr($url, strlen(ReleaseLogClient::TUF_BASE) + 1);
		if (isset($override[$path])) { return array('status' => 200, 'body' => $override[$path]); }
		$file = $fx . '/tuf/' . $path;
		return is_file($file) ? array('status' => 200, 'body' => file_get_contents($file)) : array('status' => 404, 'body' => '');
	};
}

/** The message of what $fn throws, or null when it does not. */
function rl_refusal(callable $fn) {
	try { $fn(); } catch (Exception $e) { return get_class($e) . ': ' . $e->getMessage(); }
	return null;
}

// ---------------------------------------------------------------------------
section('The inclusion walk, against trees built here');

function rl_mth(array $leaves) {
	$n = count($leaves);
	if ($n === 1) { return TransparencyProof::leafHash($leaves[0]); }
	$k = 1; while ($k * 2 < $n) { $k *= 2; }
	return TransparencyProof::nodeHash(rl_mth(array_slice($leaves, 0, $k)), rl_mth(array_slice($leaves, $k)));
}
function rl_path($m, array $leaves) {
	$n = count($leaves);
	if ($n === 1) { return array(); }
	$k = 1; while ($k * 2 < $n) { $k *= 2; }
	if ($m < $k) { return array_merge(rl_path($m, array_slice($leaves, 0, $k)), array(rl_mth(array_slice($leaves, $k)))); }
	return array_merge(rl_path($m - $k, array_slice($leaves, $k)), array(rl_mth(array_slice($leaves, 0, $k))));
}
$all_hold = true; $any_wrong_holds = false; $cases = 0;
for ($n = 1; $n <= 20; $n++) {
	$leaves = array();
	for ($i = 0; $i < $n; $i++) { $leaves[] = "leaf-$n-$i"; }
	$root = rl_mth($leaves);
	for ($m = 0; $m < $n; $m++) {
		$path = rl_path($m, $leaves);
		$lh = TransparencyProof::leafHash($leaves[$m]);
		$cases++;
		if (!TransparencyProof::inclusionHolds($lh, $m, $n, $path, $root)) { $all_hold = false; }
		if ($n > 1 && TransparencyProof::inclusionHolds($lh, ($m + 1) % $n, $n, $path, $root)) { $any_wrong_holds = true; }
		if ($path && TransparencyProof::inclusionHolds($lh, $m, $n, array_slice($path, 1), $root)) { $any_wrong_holds = true; }
		if (TransparencyProof::inclusionHolds($lh, $m, $n, array_merge($path, array(str_repeat("\0", 32))), $root)) { $any_wrong_holds = true; }
	}
}
check($all_hold, "every leaf of every tree up to 20 leaves walks to its root ({$cases} cases)");
check(!$any_wrong_holds, 'the wrong index, a shortened path or a padded path never does');
check(!TransparencyProof::inclusionHolds(TransparencyProof::leafHash('x'), 3, 3, array(), rl_mth(array('x'))),
	'an index outside the tree never does');

// ---------------------------------------------------------------------------
section('Discovery from the recorded TUF CDN');

$client = new ReleaseLogClient($pins, $pins, rl_transport($fx), $now);
$shard = $client->discover();
check($shard['url'] === $shard_url && $shard['origin'] === $origin, 'the live shard is log2025-1', json_encode(array($shard['url'], $shard['origin'])));
check($shard['key'] === $log_key && $shard['ahead'] === array() && $shard['waiting'] === array() && $shard['genesis'] === false, 'its key is the pinned one, nodes hold it, nothing is queued ahead');

$sc_path = 'targets/0f5f38554e29e770d4d5d6f0e1b51fcbf84f61dc6934530a09b7a901eaad5bee.signing_config_rekor_v2.v0.2.json';
$tr_path = 'targets/6494e21ea73fa7ee769f85f57d5a3e6a08725eae1e38c755fc3517c9e6bc0b66.trusted_root.json';
$sc = json_decode(file_get_contents($fx . '/tuf/' . $sc_path), true);
$tr = json_decode(file_get_contents($fx . '/tuf/' . $tr_path), true);

$r = rl_refusal(function () use ($fx, $pins, $now, $tr_path) {
	(new ReleaseLogClient($pins, $pins, rl_transport($fx, null, array($tr_path => '{"tlogs":[]}')), $now))->discover();
});
check($r !== null && strpos($r, 'does not match the hash') !== false, 'a target whose bytes do not match the targets file is refused', (string)$r);

$r = rl_refusal(function () use ($fx, $pins, $now) { (new ReleaseLogClient(array(), $pins, rl_transport($fx), $now))->discover(); });
check($r !== null && strpos($r, 'release_keys/log/' . $origin . '.pub') !== false && strpos($r, base64_encode($log_key)) !== false,
	'no pin: refused, naming the file to add and the key to put in it', (string)$r);

$other = array($origin => TransparencyProof::ED25519_SPKI_PREFIX . random_bytes(32));
$r = rl_refusal(function () use ($fx, $other, $pins, $now) { (new ReleaseLogClient($other, $pins, rl_transport($fx), $now))->discover(); });
check($r !== null && strpos($r, 'does not pin') !== false, 'a pin the trusted root disagrees with: refused', (string)$r);

$r = rl_refusal(function () use ($fx, $pins) { (new ReleaseLogClient($pins, $pins, rl_transport($fx), strtotime('2025-12-01T00:00:00Z')))->discover(); });
check($r !== null && strpos($r, 'no Rekor v2 log that is taking entries now') !== false, 'before the shard\'s start nothing is live: refused', (string)$r);

$ended = $sc;
$ended['rekorTlogUrls'][0]['validFor']['end'] = '2026-06-01T00:00:00Z';
$r = rl_refusal(function () use ($pins, $now, $ended, $tr) { (new ReleaseLogClient($pins, $pins, null, $now))->chooseShard($ended, $tr); });
check($r !== null && strpos($r, 'taking entries now') !== false, 'a shard whose validity ended is not live', (string)$r);

// Which keys nodes hold is a separate question from which keys this tree ships.
$s = (new ReleaseLogClient($pins, null, null, $now))->chooseShard($sc, $tr);
check($s['origin'] === $origin && $s['genesis'] === true, 'no release has carried a statement yet: genesis, said so in the result');
$r = rl_refusal(function () use ($pins, $now, $sc, $tr) { (new ReleaseLogClient($pins, array(), null, $now))->chooseShard($sc, $tr); });
check($r !== null && strpos($r, 'not yet shipped') !== false, 'a prior statement whose release shipped no log key: refused, not genesis', (string)$r);
$r = rl_refusal(function () use ($pins, $other, $now, $sc, $tr) { (new ReleaseLogClient($pins, $other, null, $now))->chooseShard($sc, $tr); });
check($r !== null && strpos($r, 'nodes hold a different checkpoint key') !== false, 'nodes holding a different key for the live log: refused', (string)$r);

$bad_end = $sc;
$bad_end['rekorTlogUrls'][0]['validFor']['end'] = 'next tuesday-ish';
$r = rl_refusal(function () use ($pins, $now, $bad_end, $tr) { (new ReleaseLogClient($pins, $pins, null, $now))->chooseShard($bad_end, $tr); });
check($r !== null && strpos($r, 'validFor end that is not a time') !== false, 'an end time that does not parse is refused, not read as "never ends"', (string)$r);

// A key rotated in place: two trusted-root entries for one log.
$rotated_key = TransparencyProof::ED25519_SPKI_PREFIX . random_bytes(32);
$tr_rot = $tr;
foreach ($tr_rot['tlogs'] as &$tl) {
	if ($tl['baseUrl'] === $shard_url) { $tl['publicKey']['validFor']['end'] = '2026-06-01T00:00:00Z'; }
}
unset($tl);
$tr_rot['tlogs'][] = array('baseUrl' => $shard_url, 'hashAlgorithm' => 'SHA2_256',
	'publicKey' => array('rawBytes' => base64_encode($rotated_key), 'keyDetails' => 'PKIX_ED25519', 'validFor' => array('start' => '2026-06-01T00:00:00Z')));
$rot = array($origin => $rotated_key);
$s = (new ReleaseLogClient($rot, $rot, null, $now))->chooseShard($sc, $tr_rot);
check($s['key'] === $rotated_key, 'a key rotated in place: the entry valid now is the one checked');
$r = rl_refusal(function () use ($pins, $now, $sc, $tr_rot) { (new ReleaseLogClient($pins, $pins, null, $now))->chooseShard($sc, $tr_rot); });
check($r !== null && strpos($r, 'does not pin') !== false, 'and a pin of the retired key alone is refused', (string)$r);
$s = (new ReleaseLogClient(array($origin => array($log_key, $rotated_key)), $rot, null, $now))->chooseShard($sc, $tr_rot);
check($s['key'] === $rotated_key, 'both keys pinned for the log: the one valid now is the one checked');

// A key the live log rotates to in place, named ahead of its start (review B7):
// it is pinned and shipped before it takes over, as a new log's key is.
$future_key = TransparencyProof::ED25519_SPKI_PREFIX . random_bytes(32);
$tr_next = $tr;
$tr_next['tlogs'][] = array('baseUrl' => $shard_url, 'hashAlgorithm' => 'SHA2_256',
	'publicKey' => array('rawBytes' => base64_encode($future_key), 'keyDetails' => 'PKIX_ED25519', 'validFor' => array('start' => '2027-03-01T00:00:00Z')));
$r = null;
try { (new ReleaseLogClient($pins, $pins, null, $now))->chooseShard($sc, $tr_next); }
catch (ReleaseLogShardAheadException $e) { $r = $e; }
check($r !== null && $r->origin === $origin && $r->starts_at === strtotime('2027-03-01T00:00:00Z')
	&& strpos($r->getMessage(), 'changes its checkpoint key') !== false && strpos($r->getMessage(), base64_encode($future_key)) !== false,
	'the live log\'s next key, unpinned: refused as a changeover ahead, naming the key and the date', $r ? $r->getMessage() : 'no refusal');
$both_keys = array($origin => array($log_key, $future_key));
$s = (new ReleaseLogClient($both_keys, $pins, null, $now))->chooseShard($sc, $tr_next);
$ahead_keys = array_column($s['ahead_detail'], 'key');
check($s['key'] === $log_key && in_array($future_key, $ahead_keys, true),
	'pinned beside the current key: it passes, the current key is checked, and the next is reported ahead for the watch');
$tr_both = $tr_rot;
foreach ($tr_both['tlogs'] as &$tl) { unset($tl['publicKey']['validFor']['end']); }
unset($tl);
$r = rl_refusal(function () use ($rot, $now, $sc, $tr_both) { (new ReleaseLogClient($rot, $rot, null, $now))->chooseShard($sc, $tr_both); });
check($r !== null && strpos($r, '2 key(s)') !== false && strpos($r, 'exactly one must be') !== false, 'two keys valid at once for one log: refused, saying how many', (string)$r);

// Shard-ahead: Sigstore lists log2026-1 from 2027-01-01.
$next = 'log2026-1.rekor.sigstore.dev';
$next_key = TransparencyProof::ED25519_SPKI_PREFIX . random_bytes(32);
$sc_ahead = $sc;
array_unshift($sc_ahead['rekorTlogUrls'], array('url' => 'https://' . $next, 'majorApiVersion' => 2, 'validFor' => array('start' => '2027-01-01T00:00:00Z')));
$tr_ahead = $tr;
$tr_ahead['tlogs'][] = array('baseUrl' => 'https://' . $next, 'hashAlgorithm' => 'SHA2_256',
	'publicKey' => array('rawBytes' => base64_encode($next_key), 'keyDetails' => 'PKIX_ED25519', 'validFor' => array('start' => '2026-12-01T00:00:00Z')));

$r = rl_refusal(function () use ($pins, $now, $sc_ahead, $tr_ahead) { (new ReleaseLogClient($pins, $pins, null, $now))->chooseShard($sc_ahead, $tr_ahead); });
check($r !== null && strpos($r, 'the next log') !== false && strpos($r, "release_keys/log/{$next}.pub") !== false,
	'a future shard whose key nodes do not hold: refused while the old shard still takes writes', (string)$r);

$both = $pins + array($next => $next_key);
$s = (new ReleaseLogClient($both, $pins, null, $now))->chooseShard($sc_ahead, $tr_ahead);
check($s['origin'] === $origin && $s['ahead'] === array($next), 'once pinned in the tree it passes (this release ships it), the old shard stays live and the next is reported ahead');

$s = (new ReleaseLogClient($both, $both, null, strtotime('2027-01-02T00:00:00Z')))->chooseShard($sc_ahead, $tr_ahead);
check($s['origin'] === $next, 'after its start the newer shard is the live one, once a release has shipped its key');

$r = rl_refusal(function () use ($both, $pins, $sc_ahead, $tr_ahead) { (new ReleaseLogClient($both, $pins, null, strtotime('2027-01-02T00:00:00Z')))->chooseShard($sc_ahead, $tr_ahead); });
check($r !== null && strpos($r, 'not yet shipped') !== false && strpos($r, 'Publish once on the old log first') !== false,
	'a live shard pinned in this commit but not shipped by an earlier release: refused, every node would refuse it', (string)$r);

$s = (new ReleaseLogClient($pins, $pins, null, $now))->chooseShard($sc_ahead, $tr);
check($s['origin'] === $origin && $s['ahead'] === array() && $s['waiting'] === array(array('origin' => $next, 'start' => strtotime('2027-01-01T00:00:00Z'))),
	'a future shard the trusted root has no key for yet: not refused (nobody can act on it), reported as waiting for the watch', json_encode($s['waiting']));

// ---------------------------------------------------------------------------
section('The recorded log answer');

$client = new ReleaseLogClient($pins, $pins, null, $now);
$entry = $client->entryFromResponse($shard, $envelope, $statement_der, $response);
check($entry['log_origin'] === $origin && $entry['log_index'] === 142362519 && $entry['inclusion_proof']['tree_size'] === 142362579,
	'leaf, proof and checkpoint come back as the stored entry', json_encode(array($entry['log_index'], $entry['inclusion_proof']['tree_size'])));
$v = TransparencyProof::verifyEntry($envelope, $entry, array($statement_der), $pins);
check($v['log_index'] === 142362519 && $v['statement_key'] === $statement_der, 'and the stored entry verifies offline on its own');

$tle = json_decode($response, true);
foreach (array(
	'the entry\'s leaf bytes' => function ($t) { unset($t['canonicalizedBody']); return $t; },
	'an inclusion proof'      => function ($t) { unset($t['inclusionProof']['hashes']); return $t; },
	'a checkpoint'            => function ($t) { unset($t['inclusionProof']['checkpoint']); return $t; },
) as $what => $strip) {
	$r = rl_refusal(function () use ($client, $shard, $envelope, $statement_der, $tle, $strip) {
		$client->entryFromResponse($shard, $envelope, $statement_der, json_encode($strip($tle)));
	});
	check($r !== null && strpos($r, "did not return {$what}") !== false, "an answer without {$what} is refused", (string)$r);
}

// A stranger signs the same checkpoint body under the log's name.
$note = $tle['inclusionProof']['checkpoint']['envelope'];
$body = substr($note, 0, strpos($note, "\n\n") + 1);
$stranger = sodium_crypto_sign_keypair();
$spk = sodium_crypto_sign_publickey($stranger);
$stranger_hash = substr(hash('sha256', $origin . "\n\x01" . $spk, true), 0, 4);
$real_hash = substr(hash('sha256', $origin . "\n\x01" . substr($log_key, -32), true), 0, 4);
$ssig = sodium_crypto_sign_detached($body, sodium_crypto_sign_secretkey($stranger));
foreach (array('its own key id' => $stranger_hash, 'the log\'s key id' => $real_hash) as $label => $kh) {
	$t = $tle;
	$t['inclusionProof']['checkpoint']['envelope'] = $body . "\n\u{2014} {$origin} " . base64_encode($kh . $ssig) . "\n";
	$r = rl_refusal(function () use ($client, $shard, $envelope, $statement_der, $t) {
		$client->entryFromResponse($shard, $envelope, $statement_der, json_encode($t));
	});
	check($r !== null && strpos($r, "not signed by {$origin}'s key") !== false, "a checkpoint signed by a stranger under {$label} is refused", (string)$r);
}

$t = $tle;
$h = base64_decode($t['inclusionProof']['hashes'][5]); $h[0] = chr(ord($h[0]) ^ 1);
$t['inclusionProof']['hashes'][5] = base64_encode($h);
$r = rl_refusal(function () use ($client, $shard, $envelope, $statement_der, $t) { $client->entryFromResponse($shard, $envelope, $statement_der, json_encode($t)); });
check($r !== null && strpos($r, 'does not place this entry') !== false, 'one changed bit in the proof is refused', (string)$r);

$t = $tle;
$t['inclusionProof']['rootHash'] = base64_encode(str_repeat("\1", 32));
$r = rl_refusal(function () use ($client, $shard, $envelope, $statement_der, $t) { $client->entryFromResponse($shard, $envelope, $statement_der, json_encode($t)); });
check($r !== null && strpos($r, 'different tree than the checkpoint') !== false, 'a proof root that is not the checkpoint\'s root is refused', (string)$r);

// B5: the real leaf, proof and checkpoint shipped with a different statement.
$k2 = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
openssl_pkey_export($k2, $pem2);
$other_env = ReleaseLogClient::signEnvelope('{"version":"9.9.9"}', $pem2);
$other_der = ReleaseLogClient::publicDer($pem2);
$r = rl_refusal(function () use ($other_env, $entry, $other_der, $pins) { TransparencyProof::verifyEntry($other_env, $entry, array($other_der), $pins); });
check($r !== null && strpos($r, 'records a different statement') !== false, 'a logged statement\'s proof carried by an unlogged statement is refused', (string)$r);

$r = rl_refusal(function () use ($other_env, $entry, $statement_der, $pins) { TransparencyProof::verifyEntry($other_env, $entry, array($statement_der), $pins); });
check($r !== null && strpos($r, 'not signed by a statement key this machine trusts') !== false, 'a statement signed by a key the node does not hold is refused', (string)$r);

$leaf = json_decode(base64_decode($entry['leaf']), true);
$e2 = $entry;
$e2['leaf'] = base64_encode(json_encode(array('spec' => $leaf['spec'], 'kind' => $leaf['kind'], 'apiVersion' => $leaf['apiVersion']), JSON_UNESCAPED_SLASHES));
$r = rl_refusal(function () use ($envelope, $e2, $statement_der, $pins) { TransparencyProof::verifyEntry($envelope, $e2, array($statement_der), $pins); });
check($r !== null && strpos($r, 'not canonical JSON') !== false, 'the same leaf with its keys reordered is refused', (string)$r);

$r = rl_refusal(function () use ($envelope, $entry, $statement_der) { TransparencyProof::verifyEntry($envelope, $entry, array($statement_der), array()); });
check($r !== null && strpos($r, 'holds no key for') !== false, 'a node without the log\'s key refuses', (string)$r);

// ---------------------------------------------------------------------------
section('Submitting');

$sent = null;
$post = function ($url, $body) use (&$sent, $response) { $sent = array($url, json_decode($body, true)); return array('status' => 201, 'body' => $response); };
$client = new ReleaseLogClient($pins, $pins, rl_transport($fx, $post), $now);
$got = $client->submitEntry($shard, $envelope, $statement_der);
$pae = TransparencyProof::pae($envelope['payloadType'], base64_decode($envelope['payload']));
$hr = $sent[1]['hashedRekordRequestV002'] ?? array();
check($sent[0] === $shard_url . '/api/v2/log/entries', 'it posts to the live shard\'s v2 entries endpoint', (string)$sent[0]);
check(base64_decode($hr['digest'] ?? '') === hash('sha256', $pae, true) && ($hr['signature']['content'] ?? null) === $envelope['signatures'][0]['sig']
	&& base64_decode($hr['signature']['verifier']['publicKey']['rawBytes'] ?? '') === $statement_der
	&& ($hr['signature']['verifier']['keyDetails'] ?? null) === 'PKIX_ECDSA_P256_SHA_256',
	'as a hashedrekord over sha256(PAE), the envelope\'s signature, the statement key');
check($got === $entry, 'and returns the verified entry');

$client = new ReleaseLogClient($pins, $pins, rl_transport($fx, function () {
	return array('status' => 400, 'body' => '{"code":3,"message":"invalid type, must be hashedrekord","details":[]}');
}), $now);
$r = rl_refusal(function () use ($client, $shard, $envelope, $statement_der) { $client->submitEntry($shard, $envelope, $statement_der); });
check($r !== null && strpos($r, 'refused the statement (HTTP 400): invalid type, must be hashedrekord') !== false, 'a refusal carries the log\'s own words', (string)$r);

$r = rl_refusal(function () use ($client, $shard, $envelope, $log_key) { $client->submitEntry($shard, $envelope, $log_key); });
check($r !== null && strpos($r, 'not a P-256 key') !== false, 'an Ed25519 statement key is refused before anything is sent', (string)$r);

$client = new ReleaseLogClient($pins, $pins, function ($m, $u) { throw new ReleaseLogException("cannot reach {$u}: timed out"); }, $now);
$r = rl_refusal(function () use ($client) { $client->discover(); });
check($r !== null && strpos($r, 'cannot reach') !== false, 'a log that cannot be reached stops the publish', (string)$r);

// A log answering with someone else's entry: log() signs afresh, so the recorded answer does not bind.
$client = new ReleaseLogClient($pins, $pins, rl_transport($fx, function () use ($response) { return array('status' => 201, 'body' => $response); }), $now);
$r = rl_refusal(function () use ($client, $pem2) { $client->log('{"version":"9.9.9"}', $pem2); });
check($r !== null && strpos($r, 'does not prove the statement is in it') !== false, 'an answer for a different entry is refused end to end', (string)$r);

// ---------------------------------------------------------------------------
section('The statement key');

$tmp = harness_scratch_dir('release_log_client');
exec('rm -rf ' . escapeshellarg($tmp) . '/*');
$cfg = $tmp . '/config';
mkdir($cfg, 0755, true);
$pem = ReleaseLogClient::ensureStatementKey($cfg);
$der = ReleaseLogClient::publicDer($pem);
check((fileperms($cfg . '/release_statement_key') & 0777) === 0600, 'minted 0600');
check(TransparencyProof::isP256($der), 'as a P-256 key');
check(ReleaseLogClient::ensureStatementKey($cfg) === $pem, 'and read back unchanged the second time');

$env = ReleaseLogClient::signEnvelope('{"version":"1.0.0"}', $pem);
check(TransparencyProof::verifyEnvelope($env, array($other_der, $der)) === $der, 'an envelope it signs verifies against its public half');
check($env['payloadType'] === TransparencyProof::PAYLOAD_TYPE && $env['signatures'][0]['keyid'] === hash('sha256', $der),
	'typed as a release statement, key id = sha256 of the key');
$bad = $env; $bad['payload'] = base64_encode('{"version":"1.0.1"}');
$r = rl_refusal(function () use ($bad, $der) { TransparencyProof::verifyEnvelope($bad, array($der)); });
check($r !== null, 'a changed payload no longer verifies', (string)$r);

$site = $tmp . '/site';
mkdir($site . '/' . ReleaseLogClient::KEYS_DIR . '/statement', 0755, true);
mkdir($site . '/' . ReleaseLogClient::KEYS_DIR . '/log', 0755, true);
$r = rl_refusal(function () use ($site, $der) { ReleaseLogClient::assertStatementKeyListed($site, $der); });
check($r !== null && strpos($r, base64_encode($der)) !== false, 'a statement key the repository does not list is refused, naming the line to add', (string)$r);
file_put_contents($site . '/' . ReleaseLogClient::KEYS_DIR . '/statement/joinery.pub', base64_encode($der) . "\n");
file_put_contents($site . '/' . ReleaseLogClient::KEYS_DIR . '/statement/junk.pub', base64_encode($log_key) . "\n");
check(rl_refusal(function () use ($site, $der) { ReleaseLogClient::assertStatementKeyListed($site, $der); }) === null, 'once listed it passes');
check(ReleaseLogClient::repoStatementKeys($site) === array($der), 'a non-P-256 file under statement/ is ignored');

file_put_contents($site . '/' . ReleaseLogClient::KEYS_DIR . "/log/{$origin}.pub", base64_encode($log_key) . "\n");
file_put_contents($site . '/' . ReleaseLogClient::KEYS_DIR . '/log/junk.pub', base64_encode($der) . "\n");
check(ReleaseLogClient::repoLogKeys($site) === array($origin => array($log_key)), 'log pins are read origin => keys, a non-Ed25519 file ignored');
$second = TransparencyProof::ED25519_SPKI_PREFIX . random_bytes(32);
file_put_contents($site . '/' . ReleaseLogClient::KEYS_DIR . "/log/{$origin}.pub", base64_encode($log_key) . "\n" . base64_encode($second) . "\n" . base64_encode($log_key) . "\n");
check(ReleaseLogClient::repoLogKeys($site) === array($origin => array($log_key, $second)), 'one key per line, a log may pin two, each once');
file_put_contents($site . '/' . ReleaseLogClient::KEYS_DIR . "/log/{$origin}.pub", base64_encode($log_key) . "\n");

// The repository's own pins: every file under release_keys/log/ must be read
// as a key, or the publisher silently does not pin it and refuses to log.
$repo_site = dirname(PathHelper::getRootDir());
$repo_files = glob($repo_site . '/' . ReleaseLogClient::KEYS_DIR . '/log/*.pub') ?: array();
$repo_pins = ReleaseLogClient::repoLogKeys($repo_site);
check(count($repo_files) > 0 && count($repo_pins) === count($repo_files),
	'every pin in the repository\'s release_keys/log/ is an Ed25519 key the publisher reads',
	count($repo_files) . ' files, ' . count($repo_pins) . ' read');

exec('rm -rf ' . escapeshellarg($tmp));
harness_finish();
