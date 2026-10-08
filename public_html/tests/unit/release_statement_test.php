<?php
/** @joinery-test
 * name: release_statement
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The release statement and the watch on Sigstore's log changeover (spec
 * release_transparency, WP3; D4, D5, D-F, O6):
 *
 *  - a manifest lists its RELEASE_STATEMENT and the statement records the
 *    manifest without that line: restamping keeps the subject, today's
 *    verifier still says `signed`, and an unlisted statement is the
 *    `extra_file` every existing node would refuse (why D-F exists)
 *  - the payload: commits by full hash, sha256 artifacts, the core required
 *  - the key chain: genesis heads it, an unchanged key set adds nothing, a
 *    release that introduced a key is added
 *  - the statement key: minted at genesis and its public half listed, never
 *    minted again once nodes hold one, refused when nodes do not hold it
 *  - end to end against a log built here: logged, the document verifies
 *    against the keys the release installs, and refuses a changed artifact
 *    or key list
 *  - the watch, driven by the recorded Sigstore documents: clear, a future
 *    log unpinned (warning, critical inside 30 days), pinned again (clear),
 *    unreachable and an unknown format (blind, last conclusion kept), stale,
 *    the task missing or off
 *
 * Runs offline, no DB. Run: php tests/unit/release_statement_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$fx = __DIR__ . '/../fixtures/release_log';
$tmp = harness_scratch_dir('release_statement');
exec('rm -rf ' . escapeshellarg($tmp) . '/*');

/** The message of what $fn throws, or null when it does not. */
function rs_refusal(callable $fn) {
	try { $fn(); } catch (Exception $e) { return get_class($e) . ': ' . $e->getMessage(); }
	return null;
}

$release = sodium_crypto_sign_keypair();
$release_keys = array('secret' => sodium_crypto_sign_secretkey($release), 'public' => sodium_crypto_sign_publickey($release));
$keys_file = $tmp . '/release_verify_keys';
file_put_contents($keys_file, base64_encode($release_keys['public']) . "\n");

// ---------------------------------------------------------------------------
section('A listed statement, recorded without its own line');

$site = $tmp . '/site';
$plugin = $site . '/public_html/plugins/demo';
mkdir($plugin . '/includes', 0755, true);
file_put_contents($plugin . '/plugin.json', '{"name":"demo","version":"1.0.0"}');
file_put_contents($plugin . '/includes/Demo.php', "<?php class Demo {}\n");
TreeManifestPublisher::write($plugin, $site, $release_keys);
$before = file_get_contents($plugin . '/RELEASE_MANIFEST');
$subject = PackageSignature::statementSubject($before);
check($subject === hash('sha256', $before), 'a manifest with no statement line is its own subject');

file_put_contents($plugin . '/RELEASE_STATEMENT', '{"stand-in":"statement"}');
$v = PackageSignature::verify($plugin, $keys_file);
check($v->verdict === PackageSignature::EXTRA_FILE, 'an unlisted statement is a file the manifest does not list: every node today refuses it', $v->line());

$after = TreeManifestPublisher::restamp($plugin, $release_keys, array('public_html/plugins/demo/RELEASE_STATEMENT' => $plugin . '/RELEASE_STATEMENT'));
check(strpos($after, '  public_html/plugins/demo/RELEASE_STATEMENT') !== false, 'restamped, the manifest lists the statement');
check(PackageSignature::statementSubject($after) === $subject, 'and its subject is unchanged: the bytes the statement recorded');
check($after === TreeManifestPublisher::build($plugin, $site), 'the same bytes a full walk writes');
$v = PackageSignature::verify($plugin, $keys_file);
check($v->signed(), 'today\'s verifier says signed, statement and all', $v->line());

file_put_contents($plugin . '/RELEASE_STATEMENT', '{"stand-in":"a different statement"}');
$v = PackageSignature::verify($plugin, $keys_file);
check($v->verdict === PackageSignature::TAMPERED, 'a statement swapped after signing is tampered', $v->line());
$again = TreeManifestPublisher::restamp($plugin, $release_keys, array('public_html/plugins/demo/RELEASE_STATEMENT' => $plugin . '/RELEASE_STATEMENT'));
check(PackageSignature::statementSubject($again) === $subject && substr_count($again, 'RELEASE_STATEMENT') === 1,
	'restamping again replaces its line, never adds a second');

file_put_contents($plugin . '/includes/Demo.php', "<?php class Demo { /* changed */ }\n");
check(PackageSignature::statementSubject(TreeManifestPublisher::build($plugin, $site)) !== $subject, 'any other change moves the subject');

$r = rs_refusal(function () use ($plugin, $release_keys) {
	TreeManifestPublisher::restamp($plugin, $release_keys, array('public_html/plugins/demo/cache/x' => __FILE__));
});
check($r !== null && strpos($r, 'no manifest lists') !== false, 'a path no manifest lists cannot be restamped in', (string)$r);

check(PackageSignature::statementLines($again) === array('public_html/plugins/demo/RELEASE_STATEMENT' => hash_file('sha256', $plugin . '/RELEASE_STATEMENT')),
	'statementLines names each line the subject leaves out, with its hash, for the check that it is the statement');

$nested = "# h\n" . str_repeat('a', 64) . "  public_html/agent_dist/RELEASE_STATEMENT\n" . str_repeat('b', 64) . "  public_html/x.php\n"
	. str_repeat('c', 64) . "  public_html/RELEASE_STATEMENT\n";
check(PackageSignature::statementSubject($nested) === hash('sha256', "# h\n" . str_repeat('b', 64) . "  public_html/x.php\n"),
	'every statement line goes, at any depth, and every other byte stays');
check(PackageSignature::statementLines($nested) === array('public_html/agent_dist/RELEASE_STATEMENT' => str_repeat('a', 64),
	'public_html/RELEASE_STATEMENT' => str_repeat('c', 64)),
	'so a verifier sees every one of them, and refuses any whose bytes are not the statement it verified (B1)');

// ---------------------------------------------------------------------------
section('The payload');

$c = str_repeat('1', 40);
$a = str_repeat('2', 40);
$keys_installed = array('release_keys' => array(base64_encode($release_keys['public'])), 'statement_keys' => array(), 'log_keys' => array());
$facts = array('version' => '0.8.470', 'core_commit' => $c, 'agent_commit' => $a, 'go_toolchain' => 'go1.22.2',
	'compressors' => array('gzip' => 'gzip 1.12'), 'artifacts' => array('plugin/demo' => $subject, 'core' => $subject),
	'keys_installed' => $keys_installed);
$p = json_decode(ReleaseStatementPublisher::payload($facts, strtotime('2026-10-08T12:00:00Z')), true);
check($p['version'] === '0.8.470' && $p['core_commit'] === $c && $p['agent_commit'] === $a && $p['published_at'] === '2026-10-08T12:00:00Z',
	'it names the version, both commits and when');
check(array_keys($p['artifacts']) === array('core', 'plugin/demo'), 'artifacts sorted by name');
check($p['keys_installed']['statement_keys'] === array() && $p['keys_installed']['log_keys'] === array(),
	'an empty key list stays a list');
foreach (array(
	'a short commit'     => array('core_commit' => 'abc123'),
	'a missing toolchain' => array('go_toolchain' => null),
	'a bad hash'         => array('artifacts' => array('core' => 'nothex')),
	'no core'            => array('artifacts' => array('plugin/demo' => $subject)),
) as $label => $bad) {
	check(rs_refusal(function () use ($facts, $bad) { ReleaseStatementPublisher::payload(array_merge($facts, $bad)); }) !== null, "{$label} is refused");
}

// ---------------------------------------------------------------------------
section('The key chain');

/** A statement document whose payload installs $keys; envelope and entry are stand-ins. */
function rs_doc(array $keys, array $chain = array(), $tag = '') {
	$payload = json_encode(array('version' => $tag, 'keys_installed' => $keys));
	return array('format' => 1, 'envelope' => array('payload' => base64_encode($payload), 'tag' => $tag), 'entry' => array('tag' => $tag), 'key_chain' => $chain);
}
$k1 = array('release_keys' => array('R1'), 'statement_keys' => array('S1'), 'log_keys' => array(array('origin' => 'log-a', 'key' => 'L1')));
$k2 = $k1;
$k2['log_keys'][] = array('origin' => 'log-b', 'key' => 'L2');

check(ReleaseStatementPublisher::keyChain(null) === array(), 'genesis carries no chain');
$g = rs_doc($k1, array(), 'G');
$chain2 = ReleaseStatementPublisher::keyChain($g);
check(count($chain2) === 1 && $chain2[0]['entry']['tag'] === 'G', 'the release after genesis carries genesis, which introduced every key');
$r2 = rs_doc($k1, $chain2, 'R2');
check(ReleaseStatementPublisher::keyChain($r2) === $chain2, 'a release that introduced nothing is not added');
$r3 = rs_doc($k2, ReleaseStatementPublisher::keyChain($r2), 'R3');
$chain4 = ReleaseStatementPublisher::keyChain($r3);
check(array_column(array_column($chain4, 'entry'), 'tag') === array('G', 'R3'), 'a release that introduced a log key is added after it');
check(!isset($chain4[1]['key_chain']), 'links carry envelope and entry only, never a chain of their own');

$held = ReleaseStatementPublisher::held(rs_doc(array('release_keys' => array(), 'statement_keys' => array(base64_encode('S')),
	'log_keys' => array(array('origin' => 'log-a', 'key' => base64_encode('L'))))));
check($held === array('log' => array('log-a' => array('L')), 'statement' => array('S')), 'held keys are read from the last logged release');
check(ReleaseStatementPublisher::held(null) === array('log' => null, 'statement' => null), 'and are null at genesis');

// ---------------------------------------------------------------------------
section('The statement key');

$ksite = $tmp . '/keysite';
mkdir($ksite . '/config', 0755, true);
mkdir($ksite . '/' . ReleaseLogClient::KEYS_DIR . '/statement', 0755, true);
$sk = ReleaseStatementPublisher::statementKey($ksite, null);
check(is_file($ksite . '/config/release_statement_key') && TransparencyProof::isP256($sk['der']), 'genesis mints a P-256 key');
check($sk['listed'] !== null && strpos($sk['listed'], '/release_keys/statement/joinery-') !== false
	&& ReleaseLogClient::repoStatementKeys($ksite) === array($sk['der']), 'and writes its public half under release_keys/statement/ to be committed', (string)$sk['listed']);
check(ReleaseStatementPublisher::statementKey($ksite, null)['listed'] === null, 'listed once, it is not written again');

$holds = rs_doc(array('release_keys' => array(), 'statement_keys' => array(base64_encode($sk['der'])), 'log_keys' => array()));
check(ReleaseStatementPublisher::statementKey($ksite, $holds)['der'] === $sk['der'], 'after genesis the held key signs');

$stranger = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
$stranger_der = ReleaseLogClient::publicDer((function () use ($stranger) { openssl_pkey_export($stranger, $pem); return $pem; })());
$others = rs_doc(array('release_keys' => array(), 'statement_keys' => array(base64_encode($stranger_der)), 'log_keys' => array()));
$r = rs_refusal(function () use ($ksite, $others) { ReleaseStatementPublisher::statementKey($ksite, $others); });
check($r !== null && strpos($r, 'not a statement key the last logged release installed') !== false, 'a key nodes do not hold is refused', (string)$r);

unlink($ksite . '/config/release_statement_key');
$r = rs_refusal(function () use ($ksite, $holds) { ReleaseStatementPublisher::statementKey($ksite, $holds); });
check($r !== null && strpos($r, 'restore the file from this site\'s backup') !== false && !file_exists($ksite . '/config/release_statement_key'),
	'a lost key after genesis is refused, and no new one is minted', (string)$r);

// ---------------------------------------------------------------------------
section('Logged end to end, on a log built here');

$origin = 'log2025-1.rekor.sigstore.dev';
$log = sodium_crypto_sign_keypair();
$log_der = TransparencyProof::ED25519_SPKI_PREFIX . sodium_crypto_sign_publickey($log);
$shard = array('url' => 'https://' . $origin, 'origin' => $origin, 'key' => $log_der, 'ahead' => array(), 'genesis' => true);

/** A tree of $n leaves with ours at $m: root and audit path, by RFC 6962. */
function rs_mth(array $leaves) {
	$n = count($leaves);
	if ($n === 1) { return TransparencyProof::leafHash($leaves[0]); }
	$k = 1; while ($k * 2 < $n) { $k *= 2; }
	return TransparencyProof::nodeHash(rs_mth(array_slice($leaves, 0, $k)), rs_mth(array_slice($leaves, $k)));
}
function rs_path($m, array $leaves) {
	$n = count($leaves);
	if ($n === 1) { return array(); }
	$k = 1; while ($k * 2 < $n) { $k *= 2; }
	if ($m < $k) { return array_merge(rs_path($m, array_slice($leaves, 0, $k)), array(rs_mth(array_slice($leaves, $k)))); }
	return array_merge(rs_path($m - $k, array_slice($leaves, $k)), array(rs_mth(array_slice($leaves, 0, $k))));
}
$fake_log = function ($method, $url, $body) use ($log, $origin) {
	$hr = json_decode($body, true)['hashedRekordRequestV002'];
	$leaf = TransparencyProof::canonicalJson(array('apiVersion' => '0.0.2', 'kind' => 'hashedrekord', 'spec' => array('hashedRekordV002' => array(
		'data' => array('algorithm' => 'SHA2_256', 'digest' => $hr['digest']),
		'signature' => array('content' => $hr['signature']['content'], 'verifier' => $hr['signature']['verifier'])))));
	$leaves = array('a', 'b', 'c', $leaf, 'e');
	$root = rs_mth($leaves);
	$note_body = "{$origin}\n5\n" . base64_encode($root) . "\n";
	$kh = substr(hash('sha256', $origin . "\n\x01" . sodium_crypto_sign_publickey($log), true), 0, 4);
	$note = $note_body . "\n\u{2014} {$origin} " . base64_encode($kh . sodium_crypto_sign_detached($note_body, sodium_crypto_sign_secretkey($log))) . "\n";
	return array('status' => 201, 'body' => json_encode(array('logIndex' => '3', 'kindVersion' => array('kind' => 'hashedrekord', 'version' => '0.0.2'),
		'inclusionProof' => array('logIndex' => '3', 'rootHash' => base64_encode($root), 'treeSize' => '5',
			'hashes' => array_map('base64_encode', rs_path(3, $leaves)), 'checkpoint' => array('envelope' => $note)),
		'canonicalizedBody' => base64_encode($leaf))));
};

$sk = ReleaseStatementPublisher::statementKey($ksite, null);   // minted afresh after the unlink above
$installed = array('release_keys' => array(base64_encode($release_keys['public'])), 'statement_keys' => array(base64_encode($sk['der'])),
	'log_keys' => array(array('origin' => $origin, 'key' => base64_encode($log_der))));
$subjects = array('core' => str_repeat('e', 64), 'plugin/demo' => $subject);
$payload = ReleaseStatementPublisher::payload(array_merge($facts, array('artifacts' => $subjects + array('agent/linux-amd64' => str_repeat('f', 64)),
	'keys_installed' => $installed)));
$client = new ReleaseLogClient(array($origin => $log_der), null, $fake_log);
$doc = ReleaseStatementPublisher::log($client, $shard, $payload, $sk['pem'], null);
$decoded = json_decode($doc, true);
check($decoded['format'] === 1 && $decoded['entry']['log_index'] === 3 && $decoded['entry']['log_origin'] === $origin,
	'the log\'s answer comes back as the stored entry');
check($decoded['key_chain'] === array(), 'genesis: no chain');
check(base64_decode($decoded['envelope']['payload']) === $payload, 'the envelope carries the payload\'s exact bytes');
$got = ReleaseStatementPublisher::verifyDocument($doc, $installed, $subjects);
check($got['artifacts']['plugin/demo'] === $subject, 'it verifies against the keys this release installs, recording every artifact as it ships');

$r = rs_refusal(function () use ($doc, $installed, $subjects) {
	ReleaseStatementPublisher::verifyDocument($doc, $installed, array('plugin/demo' => str_repeat('0', 64)) + $subjects);
});
check($r !== null && strpos($r, 'does not record plugin/demo as it ships') !== false, 'an artifact that changed after logging is refused', (string)$r);
$fewer = $installed; $fewer['release_keys'][] = base64_encode(random_bytes(32));
$r = rs_refusal(function () use ($doc, $fewer, $subjects) { ReleaseStatementPublisher::verifyDocument($doc, $fewer, $subjects); });
check($r !== null && strpos($r, 'different keys') !== false, 'a key list that differs from the one logged is refused', (string)$r);

// A release that ships the key its log rotates to in place (review B7a): two
// keys for one log, current one first, entry under the current one.
$rotating = $installed;
$rotating['log_keys'][] = array('origin' => $origin, 'key' => base64_encode(TransparencyProof::ED25519_SPKI_PREFIX . random_bytes(32)));
$rot_payload = ReleaseStatementPublisher::payload(array_merge($facts, array('artifacts' => $subjects + array('agent/linux-amd64' => str_repeat('f', 64)),
	'keys_installed' => $rotating)));
$rot_doc = ReleaseStatementPublisher::log($client, $shard, $rot_payload, $sk['pem'], null);
$r = rs_refusal(function () use ($rot_doc, $rotating, $subjects) { ReleaseStatementPublisher::verifyDocument($rot_doc, $rotating, $subjects); });
check($r === null, 'a release installing two keys for its log verifies against the one its entry is under', (string)$r);
// The chain, walked as a node holding only genesis keys would: genesis
// logged here, then a release logged under its keys.
$g_payload = ReleaseStatementPublisher::payload(array_merge($facts, array('artifacts' => $subjects, 'keys_installed' => $installed)));
$g_doc = json_decode(ReleaseStatementPublisher::log($client, $shard, $g_payload, $sk['pem'], null), true);
$next_doc = ReleaseStatementPublisher::log($client, $shard, $payload, $sk['pem'], $g_doc);
check(count(json_decode($next_doc, true)['key_chain']) === 1, 'the release after a logged genesis carries genesis as its chain');
check(ReleaseStatementPublisher::verifyDocument($next_doc, $installed, $subjects)['version'] === '0.8.470', 'and the chain walks: genesis verifies on its own keys, the statement on what genesis installed');
$walked = ReleaseStatementPublisher::walkChain(json_decode($next_doc, true)['key_chain']);
check($walked['statement'] === array($sk['der']) && $walked['log'] === array($origin => array($log_der)), 'the walk ends holding the keys genesis installed');
$bad_chain = json_decode($next_doc, true);
$bad_chain['key_chain'][] = $bad_chain['key_chain'][0];
$bad_chain['key_chain'][1]['envelope']['payload'] = base64_encode('{"keys_installed":{"release_keys":[],"statement_keys":[],"log_keys":[]}}');
$r = rs_refusal(function () use ($bad_chain, $installed, $subjects) {
	ReleaseStatementPublisher::verifyDocument(json_encode($bad_chain), $installed, $subjects);
});
check($r !== null && strpos($r, 'link 2 of the key chain does not verify') !== false, 'a chain link that does not verify stops the publish, not a node', (string)$r);

$nolog = $installed; $nolog['log_keys'] = array();
$r = rs_refusal(function () use ($doc, $nolog, $subjects) { ReleaseStatementPublisher::verifyDocument($doc, $nolog, $subjects); });
check($r !== null && strpos($r, 'does not verify against the keys this release installs') !== false,
	'a release that does not install the log\'s key is refused: no node could check it', (string)$r);

// ---------------------------------------------------------------------------
section('The watch on Sigstore\'s log changeover');

$now = strtotime('2026-10-07T17:00:00Z');
$pins = array($origin => base64_decode('MCowBQYDK2VwAyEAt8rlp1knGwjfbcXAYPYAkn0XiLz1x8O4t0YkEhie244='));
$sc_path = 'targets/0f5f38554e29e770d4d5d6f0e1b51fcbf84f61dc6934530a09b7a901eaad5bee.signing_config_rekor_v2.v0.2.json';
$tr_path = 'targets/6494e21ea73fa7ee769f85f57d5a3e6a08725eae1e38c755fc3517c9e6bc0b66.trusted_root.json';

/** Recorded Sigstore, with $docs (TUF target name => bytes) swapped in under fresh hashes. */
function rs_sigstore($fx, array $docs = array(), $down = false) {
	$override = array();
	if ($docs) {
		$targets = json_decode(file_get_contents($fx . '/tuf/14.targets.json'), true);
		foreach ($docs as $name => $bytes) {
			$sha = hash('sha256', $bytes);
			$targets['signed']['targets'][$name] = array('hashes' => array('sha256' => $sha), 'length' => strlen($bytes));
			$override["targets/{$sha}.{$name}"] = $bytes;
		}
		$override['14.targets.json'] = json_encode($targets);
	}
	return function ($method, $url) use ($fx, $override, $down) {
		if ($down) { throw new ReleaseLogBlindException("cannot reach {$url}: Connection timed out"); }
		$path = substr($url, strlen(ReleaseLogClient::TUF_BASE) + 1);
		if (isset($override[$path])) { return array('status' => 200, 'body' => $override[$path]); }
		$file = $fx . '/tuf/' . $path;
		return is_file($file) ? array('status' => 200, 'body' => file_get_contents($file)) : array('status' => 404, 'body' => '');
	};
}
$watch = function ($shipping, $transport, $state, $at, $held = null) use ($pins) {
	$held = $held ?? $pins;
	return ReleaseLogWatch::check(new ReleaseLogClient($shipping, $held, $transport, $at), $state, $at, $held);
};
$task = array('active' => true, 'created' => $now - 86400);

$s = $watch($pins, rs_sigstore($fx), array(), $now);
check($s['attempt'] === 'concluded' && $s['result'] === 'clear' && $s['live'] === $origin, 'today: concluded clear, log2025-1 live');
$cond = ReleaseLogWatch::conditions($s, $task, $now);
check($cond['refused'] === null && $cond['blind'] === null, 'and no incident');

// Sigstore lists log2026-1 from 2027-01-01, with its key in the trusted root.
$next = 'log2026-1.rekor.sigstore.dev';
$next_key = TransparencyProof::ED25519_SPKI_PREFIX . random_bytes(32);
$sc = json_decode(file_get_contents($fx . '/tuf/' . $sc_path), true);
array_unshift($sc['rekorTlogUrls'], array('url' => 'https://' . $next, 'majorApiVersion' => 2, 'validFor' => array('start' => '2027-01-01T00:00:00Z')));
$tr = json_decode(file_get_contents($fx . '/tuf/' . $tr_path), true);
$tr['tlogs'][] = array('baseUrl' => 'https://' . $next, 'hashAlgorithm' => 'SHA2_256',
	'publicKey' => array('rawBytes' => base64_encode($next_key), 'keyDetails' => 'PKIX_ED25519', 'validFor' => array('start' => '2026-12-01T00:00:00Z')));
$ahead_sigstore = rs_sigstore($fx, array(ReleaseLogClient::SIGNING_CONFIG_TARGET => json_encode($sc), ReleaseLogClient::TRUSTED_ROOT_TARGET => json_encode($tr)));

$s = $watch($pins, $ahead_sigstore, $s, $now);
$cond = ReleaseLogWatch::conditions($s, $task, $now);
check($s['result'] === 'ahead' && $s['stage'] === 'unpinned' && $s['origin'] === $next && $s['starts_at'] === strtotime('2027-01-01T00:00:00Z'),
	'a future log whose key is not pinned: concluded "ahead", naming it and its date', json_encode($s));
check($cond['refused'] !== null && $cond['refused']['severity'] === 'warning' && strpos($cond['refused']['title'], $next) !== false
	&& strpos($cond['refused']['title'], '2027-01-01') !== false && strpos($cond['refused']['detail']['Why'], "release_keys/log/{$next}.pub") !== false,
	'an incident names the log, the date and the file to add, as a warning 86 days out', json_encode($cond['refused']));
$late = strtotime('2026-12-10T00:00:00Z');
check(ReleaseLogWatch::conditions($s, $task, $late)['refused']['severity'] === 'critical', 'inside 30 days it is critical');

$both_pins = $pins + array($next => $next_key);
$s1 = $watch($both_pins, $ahead_sigstore, $s, $now);
$cond = ReleaseLogWatch::conditions($s1, $task, $now);
check($s1['result'] === 'ahead' && $s1['stage'] === 'unshipped' && strpos($cond['refused']['detail']['Fix'], 'Publish once before 2027-01-01') !== false,
	'pinning the key does not clear it: nodes hold it only once a logged release ships it (Q1)', json_encode($s1));
$s2 = $watch($both_pins, $ahead_sigstore, $s1, $now, $both_pins);
check($s2['result'] === 'clear' && $s2['ahead'] === array($next) && ReleaseLogWatch::conditions($s2, $task, $now)['refused'] === null,
	'a logged release that installed it clears it, the next log reported ahead and held');
$s2g = ReleaseLogWatch::check(new ReleaseLogClient($both_pins, null, $ahead_sigstore, $now), array(), $now, null);
check($s2g['result'] === 'clear', 'before genesis there is nothing nodes hold to compare, so pinned is enough');

$tr_nokey = json_decode(file_get_contents($fx . '/tuf/' . $tr_path), true);
$s3 = $watch($pins, rs_sigstore($fx, array(ReleaseLogClient::SIGNING_CONFIG_TARGET => json_encode($sc), ReleaseLogClient::TRUSTED_ROOT_TARGET => json_encode($tr_nokey))), array(), $now);
$cond = ReleaseLogWatch::conditions($s3, $task, $now);
check($s3['result'] === 'ahead' && $s3['stage'] === 'unpublished' && strpos($cond['refused']['detail']['Fix'], 'has not published') !== false
	&& $cond['refused']['severity'] === 'warning',
	'a future log whose key Sigstore has not published yet: publishing goes on, the watch raises it, saying there is nothing to add yet (Q2)');
check(ReleaseLogWatch::conditions($s3, $task, $late)['refused']['severity'] === 'critical', 'and critical inside 30 days, as the key is still missing');

$s4 = $watch($pins, rs_sigstore($fx, array(), true), $s, $now + 3600);
$cond = ReleaseLogWatch::conditions($s4, $task, $now + 3600);
check($s4['attempt'] === 'blind' && $s4['result'] === 'ahead' && $s4['concluded_at'] === $now,
	'Sigstore unreachable: blind, and the last conclusion stands');
check($cond['blind'] !== null && $cond['blind']['severity'] === 'warning' && strpos($cond['blind']['detail']['Why'], 'cannot reach') !== false
	&& $cond['refused'] !== null, 'both incidents: cannot see, and the unpinned log still open');

$sc_new = $sc;
$sc_new['mediaType'] = 'application/vnd.dev.sigstore.signingconfig.v0.3+json';
$s5 = $watch($pins, rs_sigstore($fx, array(ReleaseLogClient::SIGNING_CONFIG_TARGET => json_encode($sc_new))), $s2, $now);
check($s5['attempt'] === 'blind' && strpos($s5['blind_reason'], 'format') !== false
	&& ReleaseLogWatch::conditions($s5, $task, $now)['blind'] !== null, 'a format this client cannot read: blind, never green', (string)($s5['blind_reason'] ?? ''));

$stale_at = $now + ReleaseLogWatch::BLIND_AFTER + 60;
$cond = ReleaseLogWatch::conditions($s2, $task, $stale_at);
check($cond['blind'] !== null && strpos($cond['blind']['detail']['Why'], 'has not concluded since') !== false,
	'no conclusion for three days: blind, though the last one was clear');
check(ReleaseLogWatch::conditions($s2, $task, $now + ReleaseLogWatch::BLIND_CRITICAL_AFTER + 60)['blind']['severity'] === 'critical',
	'and critical after two weeks');
check(ReleaseLogWatch::conditions(array(), $task, $now)['blind'] === null, 'a watch created a day ago that has not run yet: not blind yet');
check(ReleaseLogWatch::conditions(array(), array('active' => true, 'created' => $now - 4 * 86400), $now)['blind'] !== null,
	'one created four days ago that never concluded: blind');
check(strpos((string)ReleaseLogWatch::conditions($s2, null, $now)['blind']['detail']['Why'], 'does not exist') !== false,
	'no task row: blind, saying so');
check(strpos((string)ReleaseLogWatch::conditions($s2, array('active' => false, 'created' => $now), $now)['blind']['detail']['Why'], 'turned off') !== false,
	'the task turned off: blind, saying so');

$other = array($origin => TransparencyProof::ED25519_SPKI_PREFIX . random_bytes(32));
$s6 = $watch($other, rs_sigstore($fx), array(), $now);
$cond = ReleaseLogWatch::conditions($s6, $task, $now);
check($s6['result'] === 'refused' && $cond['refused']['severity'] === 'critical' && strpos($cond['refused']['detail']['Why'], 'does not pin') !== false,
	'a pin Sigstore disagrees with: critical, publishing is refused now');

// ---------------------------------------------------------------------------
section('The publisher does these in order');

// publish_upgrade.php runs as a whole only in a real publish, so its order is
// held here: the log is asked before anything is written, the statement is
// logged and placed before any archive exists, and archives are built in one
// place only.
$src = file_get_contents(PathHelper::getIncludePath('plugins/server_manager/includes/publish_upgrade.php'));
$at = function ($needle) use ($src) { $p = strpos($src, $needle); return $p === false ? -1 : $p; };
check($at('$log_client->discover()') > 0 && $at('$log_client->discover()') < $at('AgentDistPublisher::publish($full_site_dir'),
	'the log is asked, and its keys checked, before the agent bundle or anything else is written');
check($at('ReleaseStatementPublisher::statementKey(') < $at('AgentDistPublisher::publish($full_site_dir'),
	'the statement key is listed before the bundle manifest copies the key lists');
check($at('ReleaseStatementPublisher::log(') > $at("\$statement_subjects['plugin/'") && $at('ReleaseStatementPublisher::log(') < $at('TreeManifestPublisher::restamp('),
	'logged after every manifest is signed, placed after it is logged');
check(substr_count($src, 'tar -czf') === 1 && $at('TreeManifestPublisher::restamp(') < $at('tar -czf')
	&& $at('ReleaseStatementPublisher::verifyDocument(') < $at('tar -czf'),
	'one place builds archives, after the statement is placed and checked');
check($at("upg_release_statement") > $at('tar -czf'), 'the statement is kept on the release row once its archives exist');
check($at('ReleaseLogEntry::record(') > $at('ReleaseStatementPublisher::log(') && $at('ReleaseLogEntry::record(') < $at('TreeManifestPublisher::restamp('),
	'every logged statement is on record before anything else can fail (B3)');
check($at('ReleaseStatementPublisher::unfinished()') > 0 && $at('ReleaseStatementPublisher::unfinished()') < $at('$log_client->discover()'),
	'a half-finished earlier publish is refused before anything is written (B4)');
check($at('PackageSignature::statementLines(') < $at('ReleaseStatementPublisher::log(') && strrpos($src, 'PackageSignature::statementLines(') > $at('TreeManifestPublisher::restamp('),
	'a stray statement path is refused before logging, and every statement line checked against the statement after (B1)');
check($at("'dir' => \$full_site_dir, 'subject' => null") > $at('foreach ($pending_archives as $pending) {' . "\n\t\t\t\tif (!empty(\$pending['statement_dir'])) {\n\t\t\t\t\t\$placements[]"),
	'this site\'s own live manifest is restamped last, listing every statement written into it (B2)');

exec('rm -rf ' . escapeshellarg($tmp));
harness_finish();
