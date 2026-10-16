<?php
/** @joinery-test
 * name: release_key_rotation
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The trusted set and its authority (specs/release_key_rotation.md R1, R2,
 * F1-F6), through the real publisher functions and PackageSignature::verify()
 * against a log built here:
 *
 *  - the ceremony, M -> N -> N+1: a node follows each step; one that skipped N
 *    walks N's link and installs N+1 (T2, the site tree's half); its set ends
 *    with the new keys alone
 *  - a thief holding the old keys: after N, a release that changes the set is
 *    `unauthorized_keys`, a forged link in the chain adds nothing, and a
 *    release that keeps N's set still installs (inherent until N+1, T5); after
 *    N+1, anything signed by the old key is refused, and named as retired
 *    where the node recorded it (T4, F6)
 *  - persistProvenKeys() replaces the two key files and writes the record,
 *    the dropped keys kept as retired; nothing is written for the same set
 *  - a manifest signature that is not exactly one signature is refused (T12)
 *  - the publisher: repoAuthority() reads release_keys/authority;
 *    assertMayChangeSet() refuses a set change by keys outside the authority;
 *    keyChain() adds a link for a key dropped or an authority changed;
 *    walkChain() refuses a chain whose set change is not authorised
 *  - the release log watch reads under retired statement keys (F9), and the
 *    host converger leaves a node's key files alone once it holds the record
 *    (F3), both held as text
 *  - installers write the set only after the code it came with is in place
 *  - what a node reports (R6): a status check's release_trust is kept only in
 *    its own shape, and ReleaseTrustView says whether each set is the one
 *    expected
 *
 * Offline, no DB. Run: php tests/unit/release_key_rotation_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$tmp = harness_scratch_dir('release_key_rotation');
exec('rm -rf ' . escapeshellarg($tmp) . '/*');

function rkr_refusal(callable $fn) {
	try { $fn(); } catch (Exception $e) { return $e->getMessage(); }
	return null;
}

/** A log built here: origin, key pair, DER, and a transport that answers a submission with a proof. */
function rkr_log($origin) {
	$pair = sodium_crypto_sign_keypair();
	$der = TransparencyProof::ED25519_SPKI_PREFIX . sodium_crypto_sign_publickey($pair);
	$mth = function (array $leaves) use (&$mth) {
		$n = count($leaves);
		if ($n === 1) { return TransparencyProof::leafHash($leaves[0]); }
		$k = 1; while ($k * 2 < $n) { $k *= 2; }
		return TransparencyProof::nodeHash($mth(array_slice($leaves, 0, $k)), $mth(array_slice($leaves, $k)));
	};
	$path = function ($m, array $leaves) use (&$path, $mth) {
		$n = count($leaves);
		if ($n === 1) { return array(); }
		$k = 1; while ($k * 2 < $n) { $k *= 2; }
		if ($m < $k) { return array_merge($path($m, array_slice($leaves, 0, $k)), array($mth(array_slice($leaves, $k)))); }
		return array_merge($path($m - $k, array_slice($leaves, $k)), array($mth(array_slice($leaves, 0, $k))));
	};
	$transport = function ($method, $url, $body) use ($pair, $origin, $mth, $path) {
		$hr = json_decode($body, true)['hashedRekordRequestV002'];
		$leaf = TransparencyProof::canonicalJson(array('apiVersion' => '0.0.2', 'kind' => 'hashedrekord', 'spec' => array('hashedRekordV002' => array(
			'data' => array('algorithm' => 'SHA2_256', 'digest' => $hr['digest']),
			'signature' => array('content' => $hr['signature']['content'], 'verifier' => $hr['signature']['verifier'])))));
		$leaves = array('a', $leaf, 'c');
		$root = $mth($leaves);
		$note_body = "{$origin}\n3\n" . base64_encode($root) . "\n";
		$kh = substr(hash('sha256', $origin . "\n\x01" . sodium_crypto_sign_publickey($pair), true), 0, 4);
		$note = $note_body . "\n\u{2014} {$origin} " . base64_encode($kh . sodium_crypto_sign_detached($note_body, sodium_crypto_sign_secretkey($pair))) . "\n";
		return array('status' => 201, 'body' => json_encode(array('logIndex' => '1', 'kindVersion' => array('kind' => 'hashedrekord', 'version' => '0.0.2'),
			'inclusionProof' => array('logIndex' => '1', 'rootHash' => base64_encode($root), 'treeSize' => '3',
				'hashes' => array_map('base64_encode', $path(1, $leaves)), 'checkpoint' => array('envelope' => $note)),
			'canonicalizedBody' => base64_encode($leaf))));
	};
	$client = new ReleaseLogClient(array($origin => $der), null, $transport);
	$shard = array('url' => 'https://' . $origin, 'origin' => $origin, 'key' => $der, 'ahead' => array(), 'waiting' => array(), 'genesis' => true);
	return array('origin' => $origin, 'der' => $der, 'client' => $client, 'shard' => $shard);
}
$log = rkr_log('log2025-1.rekor.sigstore.dev');
$log_keys = array($log['origin'] => array($log['der']));

/** A release key pair, as TreeManifestPublisher takes it. */
function rkr_release_key() {
	$pair = sodium_crypto_sign_keypair();
	return array('secret' => sodium_crypto_sign_secretkey($pair), 'public' => sodium_crypto_sign_publickey($pair),
		'b64' => base64_encode(sodium_crypto_sign_publickey($pair)));
}
/** A statement key: PEM and DER. */
function rkr_statement_key() {
	$sk = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
	openssl_pkey_export($sk, $pem);
	return array('pem' => $pem, 'der' => ReleaseLogClient::publicDer($pem), 'b64' => base64_encode(ReleaseLogClient::publicDer($pem)));
}
$old_r = rkr_release_key();
$new_r = rkr_release_key();
$old_s = rkr_statement_key();
$new_s = rkr_statement_key();

/** keys_installed for release keys $r and statement keys $s. */
function rkr_installed(array $r, array $s, array $log) {
	return array('release_keys' => array_map(function ($k) { return $k['b64']; }, $r),
		'statement_keys' => array_map(function ($k) { return $k['b64']; }, $s),
		'log_keys' => array(array('origin' => $log['origin'], 'key' => base64_encode($log['der']))));
}
/** An authority of release keys $r and statement keys $s. */
function rkr_authority(array $r, array $s) {
	return array('release_keys' => array_map(function ($k) { return $k['b64']; }, $r),
		'statement_keys' => array_map(function ($k) { return $k['b64']; }, $s));
}

/**
 * A logged statement, as the publisher writes one: its payload recording
 * $subject as plugin/demo, signed by $signer, chained from $previous.
 * Returns the RELEASE_STATEMENT bytes.
 */
function rkr_statement($log, $version, $signer, array $installed, ?array $authority, ?array $previous, $subject) {
	$facts = array('version' => $version, 'core_commit' => str_repeat('1', 40), 'agent_commit' => str_repeat('2', 40),
		'go_toolchain' => 'go1.22.2', 'compressors' => array('gzip' => 'gzip'),
		'artifacts' => array('plugin/demo' => $subject, 'core' => str_repeat('c', 64)), 'keys_installed' => $installed);
	if ($authority !== null) {
		$facts['authority'] = $authority;
	}
	return ReleaseStatementPublisher::log($log['client'], $log['shard'], ReleaseStatementPublisher::payload($facts), $signer['pem'], $previous);
}

/**
 * A plugin package signed by $rkey and carrying a statement the closure
 * writes for its subject. Returns [dir, statement document].
 */
function rkr_package($site, $name, array $rkey, callable $statement) {
	$dir = $site . '/public_html/plugins/' . $name;
	exec('rm -rf ' . escapeshellarg($dir));
	mkdir($dir . '/includes', 0755, true);
	file_put_contents($dir . '/plugin.json', '{"name":"' . $name . '","version":"1.0.0"}');
	file_put_contents($dir . '/includes/Thing.php', "<?php class Thing {}\n");
	TreeManifestPublisher::write($dir, $site, $rkey);
	$subject = PackageSignature::statementSubject(file_get_contents($dir . '/RELEASE_MANIFEST'));
	$bytes = $statement($subject);
	file_put_contents($dir . '/RELEASE_STATEMENT', $bytes);
	TreeManifestPublisher::restamp($dir, $rkey, array(substr($dir . '/RELEASE_STATEMENT', strlen($site) + 1) => $dir . '/RELEASE_STATEMENT'));
	return array($dir, json_decode($bytes, true));
}

/** A node holding release keys $r, statement keys $s and $authority (null: the whole set). */
function rkr_node($tmp, $label, array $r, array $s, ?array $authority) {
	$file = $tmp . '/' . $label . '_release_verify_keys';
	file_put_contents($file, implode("\n", array_map(function ($k) { return $k['b64']; }, $r)) . "\n");
	return array('file' => $file, 'statement' => array_map(function ($k) { return $k['der']; }, $s), 'authority' => $authority);
}
function rkr_verify($dir, array $node, array $log_keys) {
	return PackageSignature::verify($dir, $node['file'], array(
		'log' => array('required' => true, 'statement_keys' => $node['statement'], 'log_keys' => $log_keys),
		'authority' => $node['authority'], 'name' => 'demo'));
}
/** A verdict's trust, decoded to raw keys for comparison. */
function rkr_trust_is(PackageVerdict $v, array $r, array $s, array $ar, array $as) {
	$b = function (array $keys) { $o = array_map(function ($k) { return $k['b64']; }, $keys); sort($o); return $o; };
	return is_array($v->trust) && $v->trust['release_keys'] === $b($r) && $v->trust['statement_keys'] === $b($s)
		&& $v->trust['authority']['release_keys'] === $b($ar) && $v->trust['authority']['statement_keys'] === $b($as);
}

$site = $tmp . '/site';

// ---------------------------------------------------------------------------
section('The ceremony: genesis, M, N, N+1');

// Each release's statement is built over its own package; the next takes it as $previous.
list($g_dir, $g_doc) = rkr_package($site, 'demo', $old_r, function ($subject) use ($log, $old_r, $old_s) {
	return rkr_statement($log, '0.9.1', $old_s, rkr_installed(array($old_r), array($old_s), $log), null, null, $subject);
});
list($m_dir, $m_doc) = rkr_package($site . '_m', 'demo', $old_r, function ($subject) use ($log, $old_r, $old_s, $g_doc) {
	return rkr_statement($log, '0.9.2', $old_s, rkr_installed(array($old_r), array($old_s), $log),
		rkr_authority(array($old_r), array($old_s)), $g_doc, $subject);
});
check(count($m_doc['key_chain']) === 1, 'M carries genesis as its chain\'s one link');
list($n_dir, $n_doc) = rkr_package($site . '_n', 'demo', $old_r, function ($subject) use ($log, $old_r, $old_s, $new_r, $new_s, $m_doc) {
	return rkr_statement($log, '0.9.3', $old_s, rkr_installed(array($old_r, $new_r), array($old_s, $new_s), $log),
		rkr_authority(array($new_r), array($new_s)), $m_doc, $subject);
});
check(count($n_doc['key_chain']) === 2, 'N\'s chain holds genesis and M: M named an authority, which genesis did not');
list($n1_dir, $n1_doc) = rkr_package($site . '_n1', 'demo', $new_r, function ($subject) use ($log, $new_r, $new_s, $n_doc) {
	return rkr_statement($log, '0.9.4', $new_s, rkr_installed(array($new_r), array($new_s), $log),
		rkr_authority(array($new_r), array($new_s)), $n_doc, $subject);
});
check(count($n1_doc['key_chain']) === 3, 'N+1\'s chain adds N, which introduced the new keys');

$before_m = rkr_node($tmp, 'before_m', array($old_r), array($old_s), null);
$v = rkr_verify($m_dir, $before_m, $log_keys);
check($v->signed() && rkr_trust_is($v, array($old_r), array($old_s), array($old_r), array($old_s)),
	'a node from before M installs M, and records M\'s authority: the old keys', $v->line());

$at_m = rkr_node($tmp, 'at_m', array($old_r), array($old_s), array('release' => array($old_r['public']), 'statement' => array($old_s['der'])));
$v = rkr_verify($n_dir, $at_m, $log_keys);
check($v->signed() && rkr_trust_is($v, array($old_r, $new_r), array($old_s, $new_s), array($new_r), array($new_s)),
	'at M, N installs: signed by the authority, it adds the new keys and makes them the authority', $v->line());

$at_n = rkr_node($tmp, 'at_n', array($old_r, $new_r), array($old_s, $new_s), array('release' => array($new_r['public']), 'statement' => array($new_s['der'])));
$v = rkr_verify($n1_dir, $at_n, $log_keys);
check($v->signed() && rkr_trust_is($v, array($new_r), array($new_s), array($new_r), array($new_s)),
	'at N, N+1 installs: signed by the new keys, it drops the old ones', $v->line());

$v = rkr_verify($n1_dir, $at_m, $log_keys);
check($v->signed() && rkr_trust_is($v, array($new_r), array($new_s), array($new_r), array($new_s)),
	'a node that skipped N walks N\'s link from M\'s keys and installs N+1 (T2)', $v->line());
$v = rkr_verify($n1_dir, $before_m, $log_keys);
check($v->signed() && rkr_trust_is($v, array($new_r), array($new_s), array($new_r), array($new_s)),
	'and so does one from before M that skipped both, its authority the whole set', $v->line());

$at_n1 = rkr_node($tmp, 'at_n1', array($new_r), array($new_s), array('release' => array($new_r['public']), 'statement' => array($new_s['der'])));
$v = rkr_verify($n1_dir, $at_n1, $log_keys);
check($v->signed() && rkr_trust_is($v, array($new_r), array($new_s), array($new_r), array($new_s)),
	'at N+1, N+1 verifies again with nothing to change: genesis, M and N\'s links are under a key it no longer holds and add nothing', $v->line());

// ---------------------------------------------------------------------------
section('A thief with the old keys');

// After N: a release putting the old keys back as the set and the authority,
// with N's genuine chain copied in, signed and logged under the old keys.
list($t_dir) = rkr_package($site . '_t', 'demo', $old_r, function ($subject) use ($log, $old_r, $old_s, $n_doc) {
	return rkr_statement($log, '0.9.5', $old_s, rkr_installed(array($old_r), array($old_s), $log),
		rkr_authority(array($old_r), array($old_s)), $n_doc, $subject);
});
$v = rkr_verify($t_dir, $at_n, $log_keys);
check($v->verdict === PackageSignature::UNAUTHORIZED_KEYS && strpos($v->detail, 'not signed by the keys allowed to change them') !== false,
	'at N, a release retiring the new keys, signed by the old, is unauthorized_keys (T5)', $v->line());
check($v->trust === null && $v->keys_proven === array(), 'and leaves nothing to persist');

// The same thief forges a link: a statement under the old key installing the old set, put into the chain.
$forged = json_decode(rkr_statement($log, '0.9.6', $old_s, rkr_installed(array($old_r), array($old_s), $log),
	rkr_authority(array($old_r), array($old_s)), $n_doc, str_repeat('d', 64)), true);
list($t2_dir) = rkr_package($site . '_t2', 'demo', $old_r, function ($subject) use ($log, $old_r, $old_s, $n_doc, $forged) {
	$doc = json_decode(rkr_statement($log, '0.9.7', $old_s, rkr_installed(array($old_r), array($old_s), $log),
		rkr_authority(array($old_r), array($old_s)), $n_doc, $subject), true);
	$doc['key_chain'][] = array('envelope' => $forged['envelope'], 'entry' => $forged['entry']);
	return json_encode($doc);
});
$v = rkr_verify($t2_dir, $at_n, $log_keys);
check($v->verdict === PackageSignature::UNAUTHORIZED_KEYS,
	'a forged link changing the set under the old key adds nothing; the release is still unauthorized_keys', $v->line());

// Between N and N+1 the old key can still sign code that keeps N's set: inherent (R1).
list($t3_dir) = rkr_package($site . '_t3', 'demo', $old_r, function ($subject) use ($log, $old_r, $old_s, $new_r, $new_s, $n_doc) {
	return rkr_statement($log, '0.9.8', $old_s, rkr_installed(array($old_r, $new_r), array($old_s, $new_s), $log),
		rkr_authority(array($new_r), array($new_s)), $n_doc, $subject);
});
$v = rkr_verify($t3_dir, $at_n, $log_keys);
check($v->signed() && rkr_trust_is($v, array($old_r, $new_r), array($old_s, $new_s), array($new_r), array($new_s)),
	'at N, a release keeping N\'s set installs under the old key, and changes nothing (why N and N+1 are the same day)', $v->line());

// After N+1: anything under the old key.
$v = rkr_verify($t_dir, $at_n1, $log_keys);
check($v->verdict === PackageSignature::UNKNOWN_KEY, 'at N+1, a release signed by the old key is refused (T4)', $v->line());
$v = rkr_verify($n_dir, $at_n1, $log_keys);
check($v->verdict === PackageSignature::UNKNOWN_KEY, 'and so is N itself: no rollback below N+1 (R8, F6)', $v->line());

// A manifest whose signature file holds two signatures (T12).
list($two_dir) = rkr_package($site . '_two', 'demo', $new_r, function ($subject) use ($log, $new_r, $new_s, $n1_doc) {
	return rkr_statement($log, '0.9.9', $new_s, rkr_installed(array($new_r), array($new_s), $log),
		rkr_authority(array($new_r), array($new_s)), $n1_doc, $subject);
});
check(rkr_verify($two_dir, $at_n1, $log_keys)->signed(), 'the release after N+1 installs at N+1');
$sig = base64_decode(trim(file_get_contents($two_dir . '/RELEASE_MANIFEST.sig')), true);
$second = sodium_crypto_sign_detached(file_get_contents($two_dir . '/RELEASE_MANIFEST'), $old_r['secret']);
file_put_contents($two_dir . '/RELEASE_MANIFEST.sig', base64_encode($sig . $second) . "\n");
check(rkr_verify($two_dir, $at_n1, $log_keys)->verdict === PackageSignature::UNREADABLE,
	'a signature file holding a second signature is unreadable: one signature per manifest (T12)');

// ---------------------------------------------------------------------------
section('What a root caller writes');

$root = $tmp . '/node';
@mkdir($root . '/config', 0755, true);
file_put_contents($root . '/config/release_verify_keys', $old_r['b64'] . "\n");
file_put_contents($root . '/config/release_statement_keys', $old_s['b64'] . "\n");
chmod($root . '/config/release_verify_keys', 0644);
chmod($root . '/config/release_statement_keys', 0644);

$v_n = rkr_verify($n_dir, $at_m, $log_keys);
$lines = PackageSignature::persistProvenKeys(array('trust' => $v_n->trust), $root);
$record = PackageSignature::readTrustRecord($root);
check($record !== null && $record['set_by'] === '0.9.3' && count($record['release_keys']) === 2
	&& $record['authority']['release_keys'] === array($new_r['b64']),
	'N\'s set is recorded: both keys, the new key the authority, set by N', implode('; ', $lines));
check(count(PackageSignature::readKeys($root . '/config/release_verify_keys')) === 2, 'the release key file holds both');
check(PackageSignature::nodeAuthority($root) === array('release' => array($new_r['public']), 'statement' => array($new_s['der'])),
	'nodeAuthority() reads it back');
check(PackageSignature::persistProvenKeys(array('trust' => $v_n->trust), $root) === array(), 'the same set again writes nothing');

$v_n1 = rkr_verify($n1_dir, $at_n, $log_keys);
PackageSignature::persistProvenKeys(array('trust' => $v_n1->trust), $root);
$record = PackageSignature::readTrustRecord($root);
check(PackageSignature::readKeys($root . '/config/release_verify_keys') === array($new_r['public'])
	&& PackageSignature::readStatementKeys($root . '/config/release_statement_keys') === array($new_s['der']),
	'N+1 replaces both key files with the new keys alone');
check($record['retired']['release_keys'] === array($old_r['b64']) && $record['retired']['statement_keys'] === array($old_s['b64']),
	'and the record keeps the old keys as retired, for saying so');
check(PackageSignature::nodeRetired($root)['release'] === array($old_r['public']), 'nodeRetired() reads them back');
chmod($root . '/config/release_trust', 0666);
check(PackageSignature::readTrustRecord($root) === null, 'a record anybody could have written is not read');
chmod($root . '/config/release_trust', 0644);

// ---------------------------------------------------------------------------
section('The publisher');

$repo = $tmp . '/repo';
$kd = $repo . '/maintenance_scripts/install_tools/release_keys';
@mkdir($kd . '/release', 0755, true);
@mkdir($kd . '/statement', 0755, true);
@mkdir($kd . '/log', 0755, true);
file_put_contents($kd . '/release/old.pub', $old_r['b64'] . "\n");
file_put_contents($kd . '/release/new.pub', $new_r['b64'] . "\n");
file_put_contents($kd . '/statement/old.pub', $old_s['b64'] . "\n");
file_put_contents($kd . '/statement/new.pub', $new_s['b64'] . "\n");
$a = AgentDistPublisher::repoAuthority($repo);
check(count($a['release_keys']) === 2 && count($a['statement_keys']) === 2, 'with no release_keys/authority, the authority is every listed key');
file_put_contents($kd . '/authority', "# the new keys alone\nrelease/new.pub\nstatement/new.pub\n");
check(AgentDistPublisher::repoAuthority($repo) === array('release_keys' => array($new_r['b64']), 'statement_keys' => array($new_s['b64'])),
	'release_keys/authority names the files that form it');
file_put_contents($kd . '/authority', "release/new.pub\n");
check(strpos((string)rkr_refusal(function () use ($repo) { AgentDistPublisher::repoAuthority($repo); }), 'names no statement_keys') !== false,
	'an authority leaving a kind empty is refused: nothing could change the set again');
file_put_contents($kd . '/authority', "release/gone.pub\nstatement/new.pub\n");
check(strpos((string)rkr_refusal(function () use ($repo) { AgentDistPublisher::repoAuthority($repo); }), 'not a key file') !== false,
	'one naming a file that is not there is refused');

$n_installed = rkr_installed(array($old_r, $new_r), array($old_s, $new_s), $log);
$n_authority = rkr_authority(array($new_r), array($new_s));
check(rkr_refusal(function () use ($m_doc, $n_installed, $n_authority, $old_r, $old_s) {
	ReleaseStatementPublisher::assertMayChangeSet($m_doc, $n_installed, $n_authority, $old_r['b64'], $old_s['der']);
}) === null, 'N may change M\'s set: signed by M\'s authority, the old keys');
check(strpos((string)rkr_refusal(function () use ($n_doc, $old_r, $old_s, $log) {
	ReleaseStatementPublisher::assertMayChangeSet($n_doc, rkr_installed(array($old_r), array($old_s), $log),
		rkr_authority(array($old_r), array($old_s)), $old_r['b64'], $old_s['der']);
}), 'nodes would refuse it') !== false, 'a publish after N putting the old keys back under the old keys is refused before the log');
check(rkr_refusal(function () use ($n_doc, $n_installed, $n_authority, $old_r, $old_s) {
	ReleaseStatementPublisher::assertMayChangeSet($n_doc, $n_installed, $n_authority, $old_r['b64'], $old_s['der']);
}) === null, 'one keeping N\'s set needs no authority');

$walked = ReleaseStatementPublisher::walkChain($n1_doc['key_chain']);
check($walked['release'] === array($old_r['b64'], $new_r['b64']) && $walked['authority']['statement'] === array($new_s['der']),
	'walkChain() ends with N\'s set and authority');
$bad_chain = $n1_doc['key_chain'];
$bad_chain[] = array('envelope' => $forged['envelope'], 'entry' => $forged['entry']);
check(strpos((string)rkr_refusal(function () use ($bad_chain) { ReleaseStatementPublisher::walkChain($bad_chain); }),
	'not signed by a statement key of the authority') !== false, 'a chain with a link the authority did not sign is refused at the publisher');

$same_set_later = json_decode(rkr_statement($log, '0.9.10', $new_s, rkr_installed(array($new_r), array($new_s), $log),
	rkr_authority(array($new_r), array($new_s)), $n1_doc, str_repeat('e', 64)), true);
check(count($same_set_later['key_chain']) === 4 && count(ReleaseStatementPublisher::keyChain($same_set_later)) === 4,
	'N+1 dropped keys, so it joins the chain; a release after it with the same set does not');

@mkdir($kd . '/retired/statement', 0755, true);
rename($kd . '/statement/old.pub', $kd . '/retired/statement/old.pub');
check(ReleaseLogClient::repoRetiredStatementKeys($repo) === array($old_s['der']) && ReleaseLogClient::repoStatementKeys($repo) === array($new_s['der']),
	'a retired statement key is read from release_keys/retired/statement/, and is no longer a listed one');
$tail_src = (string)file_get_contents(PathHelper::getRootDir() . '/plugins/server_manager/includes/ReleaseLogTail.php');
check(strpos($tail_src, 'ReleaseLogClient::repoRetiredStatementKeys($root)') !== false,
	'the release log watch reads under retired statement keys too: a stolen key is the one a thief logs with (F9)');

// ---------------------------------------------------------------------------
section('The host converger leaves the set to the record (F3)');

$hf = (string)file_get_contents(dirname(PathHelper::getRootDir()) . '/maintenance_scripts/install_tools/_host_files.sh');
check(strpos($hf, '[[ -f "${site_root}/config/release_trust" ]]') !== false,
	'the converger writes release and statement keys only on a node that holds no config/release_trust');

// ---------------------------------------------------------------------------
section('What a node reports (R6)');

$a = str_repeat('a', 12); $b = str_repeat('b', 12); $c = str_repeat('c', 12);
$clean = JobResultProcessor::status_release_trust(array(
	'site'  => array('recorded' => true, 'release' => array($b, $a, $a, 'not-a-name', 7), 'statement' => array($c),
		'authority' => array('release' => array($b), 'statement' => array($c)), 'set_by' => '0.9.3', 'extra' => '<script>'),
	'agent' => array('recorded' => false, 'release' => array($a), 'statement' => array($c),
		'authority' => array('release' => array($b), 'statement' => array($c)), 'set_by' => 'x; rm -rf /'),
	'containers' => array('anything'),
));
check($clean['site'] === array('recorded' => true, 'release' => array($a, $b), 'statement' => array($c),
	'authority' => array('release' => array($b), 'statement' => array($c)), 'set_by' => '0.9.3'),
	'a reported set keeps its names sorted and once, drops what is no 12-hex name, and keeps nothing else');
check($clean['agent']['authority'] === null && $clean['agent']['set_by'] === null && !array_key_exists('containers', $clean),
	'a set no release recorded has the whole set as authority, whatever it claims; a version that is none is dropped');
check(JobResultProcessor::status_release_trust('text') === null
	&& JobResultProcessor::status_release_trust(array('site' => 'x')) === array('site' => null, 'agent' => null),
	'what is not the shape is nothing');

$expected_prop = new ReflectionProperty('ReleaseTrustView', 'expected');
$expected_prop->setAccessible(true);
$expected_prop->setValue(null, array('release' => array($a, $b), 'statement' => array($c),
	'authority' => array('release' => array($b), 'statement' => array($c)), 'source' => 'the test'));
$status = array('release_trust' => array(
	'site'  => array('recorded' => true, 'release' => array($a, $b), 'statement' => array($c),
		'authority' => array('release' => array($b), 'statement' => array($c)), 'set_by' => '0.9.3'),
	'agent' => array('recorded' => false, 'release' => array($a), 'statement' => array($c)),
));
$rows = ReleaseTrustView::reported(json_encode($status));
check(count($rows) === 2 && $rows[0][0] === 'Site' && $rows[1][0] === 'Agent', 'a node\'s status data names its site and its agent');
check(ReleaseTrustView::standing($rows[0][1]) === 'ours' && ReleaseTrustView::standing($rows[1][1]) === 'differs',
	'the site holding N\'s set is as expected; an agent still on its compiled-in key differs');
check(ReleaseTrustView::attention($status, '1.81.0') === 'differs', 'so the node is marked on the dashboard');
$status['release_trust']['agent'] = $status['release_trust']['site'];
check(ReleaseTrustView::attention($status, '1.81.0') === null, 'once both hold N\'s set, it is not');
check(ReleaseTrustView::attention(array('disk_usage_percent' => 3), '1.81.0') === 'unreported'
	&& ReleaseTrustView::attention(array('disk_usage_percent' => 3), '1.82.4') === 'unreported',
	'a node whose agent reports its keys and whose status holds none is marked too: "every node shows" has to be readable');
check(ReleaseTrustView::attention(array('disk_usage_percent' => 3), '1.80.0') === null
	&& ReleaseTrustView::attention(null, null) === null && ReleaseTrustView::attention(null, 'dev') === null,
	'an older agent, or none, is not: it reports no keys, and its row already says it is behind');
$agent_only = array('web_root' => '/var/www/html/x/public_html', 'release_trust' => array('site' => null, 'agent' => $status['release_trust']['agent']));
check(ReleaseTrustView::attention($agent_only, '1.81.0') === 'unreported',
	'a node that hosts a site and reports the agent\'s keys but not the site\'s is marked');
unset($agent_only['web_root']);
check(ReleaseTrustView::attention($agent_only, '1.81.0') === null, 'a machine with no site reports the agent\'s alone, as it should');
$status['release_trust']['site']['authority'] = null;
check(ReleaseTrustView::standing(JobResultProcessor::reported_trust($status['release_trust']['site'])) === 'differs',
	'the same keys under another authority differ: the authority is what a rotation moves first');
check(ReleaseTrustView::reported(array('disk_usage_percent' => 3)) === array() && ReleaseTrustView::standing(null) === 'unknown',
	'a node whose agent reports no keys shows none');
ReleaseTrustView::reset();

// ---------------------------------------------------------------------------
section('The set is written after the code it came with (reviewer2 A)');

$kp = array('statement' => array('s'), 'log' => array('o' => array('l')), 'trust' => array('release_keys' => array('r')));
check(PackageSignature::withoutTrust($kp) === array('statement' => array('s'), 'log' => array('o' => array('l')))
	&& PackageSignature::trustOf($kp) === array('trust' => array('release_keys' => array('r')))
	&& PackageSignature::trustOf(array('log' => array())) === array(),
	'an installer can write the log keys alone first and the set alone after');
$up = (string)file_get_contents(PathHelper::getRootDir() . '/utils/upgrade.php');
$verify_at = strpos($up, 'persistProvenKeys($log_keys)');
$tier_at = strpos($up, "out_step('Verifying the Deployed Code')");
$trust_at = strrpos($up, 'upgrade_persist_trust();');
check($verify_at !== false && $tier_at !== false && $trust_at !== false && $verify_at < $tier_at && $tier_at < $trust_at,
	'upgrade.php writes the log keys at the verdict and the set only after the deploy tier: a deploy that rolls back keeps the set its code is signed under');
$ie = (string)file_get_contents(PathHelper::getRootDir() . '/utils/install_extension.php');
check(strpos($ie, 'persistProvenKeys(PackageSignature::withoutTrust($verdict->keys_proven))') !== false
	&& strpos($ie, 'echo "installed $type files: $staged_name\\n";' . "\n\t\tinstall_extension_persist_trust(\$verdict);") !== false,
	'install_extension.php does the same: the set once the files are installed');
$vp = (string)file_get_contents(PathHelper::getRootDir() . '/utils/verify_package.php');
check(strpos($vp, 'PackageSignature::verifyTree($dir, $keys_file, $style, $sandboxed, $core_verdict)') !== false
	&& strpos($vp, 'persistProvenKeys(PackageSignature::trustOf($core_verdict->keys_proven))') !== false
	&& strpos($vp, '$keys_file === null') !== false && strpos($vp, 'posix_geteuid() === 0') !== false,
	'the code check (verify_package.php --tree), run by root against the node\'s own keys, writes the live core\'s set: a missed write heals');
$aem = (string)file_get_contents(PathHelper::getRootDir() . '/includes/AbstractExtensionManager.php');
check(strpos($aem, 'persistProvenKeys(PackageSignature::withoutTrust($verdict->keys_proven))') !== false
	&& strpos($aem, 'persistProvenKeys(PackageSignature::trustOf($verdict->keys_proven))') > strpos($aem, "could not place the verified"),
	'and the extension refresh: the set once the package is placed');

harness_finish();
