<?php
/** @joinery-test
 * name: release_log_verify
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * A node refuses what is not in the public log (spec release_transparency,
 * WP4; D5, D6, B5, B6, B9):
 *
 *  - against a log built here with a known key: a logged package is `signed`
 *    and says where it is logged; with the log required, a package with no
 *    statement is `unlogged`; without it, the same package installs
 *  - a real proof and leaf carried by a different statement (B5); a
 *    checkpoint from a log the node holds no key for; a statement that does
 *    not record this package; a second, different RELEASE_STATEMENT (B1)
 *  - a fresh archive carrying a file on a path no manifest lists is refused
 *    (B6), a live tree is not; the core's config template is allowed only as
 *    the listed bytes
 *  - a key chain that introduces a log key the node does not hold: the
 *    statement verifies, the key comes back in keys_proven, nothing is written
 *    (B9); persistProvenKeys() appends it once
 *  - the node's settings: the key files' formats, an opt-out that is not
 *    root's alone is ignored, the unlogged warning has its own words
 *  - the install script treats `unlogged` like `unsigned` (D6), held as text
 *
 * Offline, no DB. Run: php tests/unit/release_log_verify_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$tmp = harness_scratch_dir('release_log_verify');
exec('rm -rf ' . escapeshellarg($tmp) . '/*');

function rlv_refusal(callable $fn) {
	try { $fn(); } catch (Exception $e) { return $e->getMessage(); }
	return null;
}

// --- a release key, a statement key, two logs built here --------------------
$release = sodium_crypto_sign_keypair();
$rkeys = array('secret' => sodium_crypto_sign_secretkey($release), 'public' => sodium_crypto_sign_publickey($release));
$keys_file = $tmp . '/release_verify_keys';
file_put_contents($keys_file, base64_encode($rkeys['public']) . "\n");

$sk = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
openssl_pkey_export($sk, $stmt_pem);
$stmt_der = ReleaseLogClient::publicDer($stmt_pem);

/** A log built here: origin, key pair, DER, and a transport that answers a submission with a proof. */
function rlv_log($origin) {
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
$log1 = rlv_log('log2025-1.rekor.sigstore.dev');
$log2 = rlv_log('log2026-1.rekor.sigstore.dev');

/** The keys_installed block for a statement. */
function rlv_installed($rkeys, $stmt_der, array $logs) {
	return array('release_keys' => array(base64_encode($rkeys['public'])), 'statement_keys' => array(base64_encode($stmt_der)),
		'log_keys' => array_map(function ($l) { return array('origin' => $l['origin'], 'key' => base64_encode($l['der'])); }, $logs));
}

/** Log a statement recording $subjects on $log, chained from $previous. Returns the RELEASE_STATEMENT bytes. */
function rlv_statement($log, $stmt_pem, array $subjects, array $installed, $previous = null) {
	$payload = ReleaseStatementPublisher::payload(array('version' => '0.9.' . count($subjects), 'core_commit' => str_repeat('1', 40),
		'agent_commit' => str_repeat('2', 40), 'go_toolchain' => 'go1.22.2', 'compressors' => array('gzip' => 'gzip'),
		'artifacts' => $subjects + array('core' => str_repeat('c', 64)), 'keys_installed' => $installed));
	return ReleaseStatementPublisher::log($log['client'], $log['shard'], $payload, $stmt_pem, $previous);
}

/** A plugin package under $site: files written, manifest signed. Returns [dir, subject]. */
function rlv_plugin($site, $name, $rkeys, array $extra = array()) {
	$dir = $site . '/public_html/plugins/' . $name;
	exec('rm -rf ' . escapeshellarg($dir));
	mkdir($dir . '/includes', 0755, true);
	file_put_contents($dir . '/plugin.json', '{"name":"' . $name . '","version":"1.0.0"}');
	file_put_contents($dir . '/includes/Thing.php', "<?php class Thing {}\n");
	foreach ($extra as $rel => $bytes) {
		@mkdir(dirname($dir . '/' . $rel), 0755, true);
		file_put_contents($dir . '/' . $rel, $bytes);
	}
	TreeManifestPublisher::write($dir, $site, $rkeys);
	return array($dir, PackageSignature::statementSubject(file_get_contents($dir . '/RELEASE_MANIFEST')));
}

/** Put a statement into a plugin package and list it. */
function rlv_place($site, $dir, $rkeys, $bytes, $rel_in_plugin = 'RELEASE_STATEMENT') {
	$abs = $dir . '/' . $rel_in_plugin;
	@mkdir(dirname($abs), 0755, true);
	file_put_contents($abs, $bytes);
	TreeManifestPublisher::restamp($dir, $rkeys, array(substr($abs, strlen($site) + 1) => $abs));
}

$site = $tmp . '/site';
$installed = rlv_installed($rkeys, $stmt_der, array($log1));
$node = array('required' => true, 'statement_keys' => array($stmt_der), 'log_keys' => array($log1['origin'] => array($log1['der'])));
$open = array('required' => false) + $node;
$verify = function ($dir, $log, $fresh = false) use ($keys_file) {
	return PackageSignature::verify($dir, $keys_file, array('log' => $log, 'fresh' => $fresh));
};

// ---------------------------------------------------------------------------
section('A logged package, and one that is not');

list($dir, $subject) = rlv_plugin($site, 'demo', $rkeys);
$v = $verify($dir, $node);
check($v->verdict === PackageSignature::UNLOGGED && strpos($v->detail, 'carries no RELEASE_STATEMENT') !== false,
	'the log required, a signed package with no statement is unlogged', $v->line());
check($verify($dir, $open)->signed(), 'the log not required (a fork\'s opt-out), the same package installs');

$doc = rlv_statement($log1, $stmt_pem, array('plugin/demo' => $subject), $installed);
rlv_place($site, $dir, $rkeys, $doc);
$v = $verify($dir, $node);
check($v->signed() && $v->log['origin'] === $log1['origin'] && $v->log['index'] === 1 && strpos($v->detail, 'logged publicly') !== false,
	'with its logged statement it is signed, and says where it is logged', $v->line());
check($v->keys_proven === array('statement' => array(), 'log' => array()), 'proving no key the node lacked');

// ---------------------------------------------------------------------------
section('What does not prove a package is logged');

// B5: the real leaf, proof and checkpoint, carried by a different statement.
$real = json_decode($doc, true);
$other = $real;
$other['envelope'] = ReleaseLogClient::signEnvelope('{"version":"9.9.9","artifacts":{"plugin/demo":"' . $subject . '"}}', $stmt_pem);
rlv_place($site, $dir, $rkeys, json_encode($other));
$v = $verify($dir, $node);
check($v->verdict === PackageSignature::UNLOGGED && strpos($v->detail, 'records a different statement') !== false,
	'a logged statement\'s proof carried by an unlogged statement: unlogged (B5)', $v->line());

// A checkpoint the node cannot check.
rlv_place($site, $dir, $rkeys, rlv_statement($log2, $stmt_pem, array('plugin/demo' => $subject), $installed));
$v = $verify($dir, $node);
check($v->verdict === PackageSignature::UNLOGGED && strpos($v->detail, 'holds no key for') !== false,
	'logged on a log the node holds no key for: unlogged', $v->line());
$stranger = $node;
$stranger['log_keys'] = array($log2['origin'] => array($log1['der']));
$v = $verify($dir, $stranger);
check($v->verdict === PackageSignature::UNLOGGED && strpos($v->detail, 'not signed by') !== false,
	'a checkpoint signed by a key other than the one held for that log: unlogged', $v->line());

// A statement that is real and logged, but about something else.
rlv_place($site, $dir, $rkeys, rlv_statement($log1, $stmt_pem, array('plugin/demo' => str_repeat('0', 64)), $installed));
$v = $verify($dir, $node);
check($v->verdict === PackageSignature::UNLOGGED && strpos($v->detail, 'does not record this package') !== false,
	'a logged statement whose artifacts do not include this manifest: unlogged', $v->line());

// A statement key the node does not hold.
$v = $verify($dir, array('statement_keys' => array(ReleaseLogClient::publicDer((function () {
	$k = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1')); openssl_pkey_export($k, $p); return $p; })()))) + $node);
check($v->verdict === PackageSignature::UNLOGGED && strpos($v->detail, 'not signed by a statement key') !== false,
	'signed by a statement key the node does not hold: unlogged', $v->line());

// B1: a second file under the statement's name, other bytes.
list($dir, $subject) = rlv_plugin($site, 'demo', $rkeys);
$doc = rlv_statement($log1, $stmt_pem, array('plugin/demo' => $subject), $installed);
rlv_place($site, $dir, $rkeys, $doc);
rlv_place($site, $dir, $rkeys, '<?php // anything at all', 'includes/RELEASE_STATEMENT');
$v = $verify($dir, $node);
check($v->verdict === PackageSignature::UNLOGGED && strpos($v->detail, 'more than one') !== false,
	'another file named RELEASE_STATEMENT with other bytes is never left out unlogged (B1)', $v->line());

check($verify($dir, $node)->keys_proven === array(), 'and an unlogged verdict proves no keys');

// ---------------------------------------------------------------------------
section('A fresh archive carries nothing the manifest does not list (B6)');

list($dir, $subject) = rlv_plugin($site, 'demo', $rkeys, array('cache/y.php' => '<?php echo 1;'));
rlv_place($site, $dir, $rkeys, rlv_statement($log1, $stmt_pem, array('plugin/demo' => $subject), $installed));
$v = $verify($dir, $node, true);
check($v->verdict === PackageSignature::EXTRA_FILE && $v->file === 'cache/y.php', 'a fresh archive with cache/y.php is extra_file', $v->line());
check($verify($dir, $node)->signed(), 'the same files as a live tree: cache is skipped, as a live tree holds one');

$core = $tmp . '/core';
mkdir($core . '/maintenance_scripts/install_tools', 0755, true);
mkdir($core . '/config', 0755, true);
mkdir($core . '/public_html', 0755, true);
file_put_contents($core . '/public_html/index.php', "<?php\n");
file_put_contents($core . '/maintenance_scripts/install_tools/default_Globalvars_site.php', "<?php // template\n");
TreeManifestPublisher::write($core, $core, $rkeys);
copy($core . '/maintenance_scripts/install_tools/default_Globalvars_site.php', $core . '/config/default_Globalvars_site.php');
$v = PackageSignature::verify($core, $keys_file, array('fresh' => true, 'log' => $open));
check($v->signed(), 'the core archive\'s config template, the listed install_tools copy byte for byte, is allowed', $v->line());
file_put_contents($core . '/config/default_Globalvars_site.php', "<?php // something else\n");
$v = PackageSignature::verify($core, $keys_file, array('fresh' => true, 'log' => $open));
check($v->verdict === PackageSignature::EXTRA_FILE, 'any other bytes there are extra_file', $v->line());

// ---------------------------------------------------------------------------
section('A key chain the node walks, and what it proves (B9)');

// Genesis on log1 installs both logs' keys; the release is logged on log2.
// A node that holds only log1 walks genesis and can check it.
$both = rlv_installed($rkeys, $stmt_der, array($log1, $log2));
$genesis = json_decode(rlv_statement($log1, $stmt_pem, array('theme/x' => str_repeat('e', 64)), $both), true);
list($dir, $subject) = rlv_plugin($site, 'demo', $rkeys);
rlv_place($site, $dir, $rkeys, rlv_statement($log2, $stmt_pem, array('plugin/demo' => $subject), $both, $genesis));
$node_root = $tmp . '/node';
mkdir($node_root . '/config', 0755, true);
$before = glob($node_root . '/config/*');
$v = $verify($dir, $node);
check($v->signed() && $v->log['origin'] === $log2['origin'], 'logged on a log the node did not hold, reached through the chain: signed', $v->line());
check($v->keys_proven === array('statement' => array(), 'log' => array($log2['origin'] => array($log2['der']))),
	'the new log key comes back in keys_proven');
check(glob($node_root . '/config/*') === $before, 'and verify() wrote nothing');

$added = PackageSignature::persistProvenKeys($v->keys_proven, $node_root);
check($added === array('config/transparency_log_keys: ' . $log2['origin'] . ' ' . base64_encode($log2['der'])),
	'a root caller persists it as one "<origin> <key>" line', json_encode($added));
check(PackageSignature::persistProvenKeys($v->keys_proven, $node_root) === array(), 'and never twice');
check(PackageSignature::readLogKeys($node_root . '/' . PackageSignature::LOG_KEYS_FILE) === array($log2['origin'] => array($log2['der'])),
	'read back as origin => keys');

$bad = json_decode(file_get_contents($dir . '/RELEASE_STATEMENT'), true);
$bad['key_chain'][0]['entry']['inclusion_proof']['root_hash'] = base64_encode(str_repeat("\1", 32));
rlv_place($site, $dir, $rkeys, json_encode($bad));
$v = $verify($dir, $node);
check($v->verdict === PackageSignature::UNLOGGED, 'a chain link that does not verify adds nothing: the release on the unknown log is unlogged', $v->line());

// ---------------------------------------------------------------------------
section('The node\'s settings');

$cfg = $tmp . '/cfgsite';
mkdir($cfg . '/config', 0755, true);
$w = function ($rel, $body, $mode = 0644) use ($cfg) { file_put_contents($cfg . '/' . $rel, $body); chmod($cfg . '/' . $rel, $mode); };
$w(PackageSignature::STATEMENT_KEYS_FILE, base64_encode($stmt_der) . "\n" . base64_encode($log1['der']) . "\n# note\n");
$w(PackageSignature::LOG_KEYS_FILE, $log1['origin'] . ' ' . base64_encode($log1['der']) . "\nnot a line\n" . $log1['origin'] . ' ' . base64_encode($stmt_der) . "\n");
$n = PackageSignature::nodeLog($cfg);
check($n['statement_keys'] === array($stmt_der), 'statement keys: P-256 only, the Ed25519 line skipped');
check($n['log_keys'] === array($log1['origin'] => array($log1['der'])), 'log keys: "<origin> <Ed25519 key>" only');
check($n['required'] === false, 'no release_log_required: not required');
$w(PackageSignature::LOG_REQUIRED_FILE, '');
check(PackageSignature::nodeLog($cfg)['required'] === true, 'release_log_required: required');
$w(PackageSignature::LOG_OPTIONAL_FILE, '');
check(PackageSignature::nodeLog($cfg)['required'] === false, 'a release_log_optional that is root\'s alone opts out');
chmod($cfg . '/' . PackageSignature::LOG_OPTIONAL_FILE, 0666);
check(PackageSignature::nodeLog($cfg)['required'] === true, 'one anybody could have written is ignored');
chmod($cfg . '/' . PackageSignature::STATEMENT_KEYS_FILE, 0666);
check(PackageSignature::nodeLog($cfg)['statement_keys'] === array(), 'and so is a statement key file anybody could have written');

check(strpos(PackageAcknowledgement::warning(PackageSignature::UNLOGGED), 'signed by Joinery but is not in the public release log') !== false
	&& strpos(PackageAcknowledgement::warning(), 'unsigned package') !== false, 'the unlogged warning has its own words');

// ---------------------------------------------------------------------------
section('The install script treats unlogged like unsigned (D6)');

$ie = file_get_contents(PathHelper::getIncludePath('utils/install_extension.php'));
check(strpos($ie, "return \$verdict->verdict === PackageSignature::UNLOGGED ? 'unlogged' : 'unsigned';") !== false,
	'a package that did not verify is recorded unlogged or unsigned by its verdict');
check(substr_count($ie, '$trust = $restricted;') >= 2 && strpos($ie, 'PackageAcknowledgement::warning($verdict->verdict)') !== false,
	'refused without the acknowledgement, with the warning for its verdict; installed with it under that trust');
check(strpos($ie, "if (\$trust !== 'signed') {\n\t\tinstall_extension_register_as_web_user") !== false,
	'its database half runs as the web user, like unsigned');
check(strpos($ie, "if (\$trust === 'signed') {\n\t\treturn;") !== false, 'and it is recorded, emailed and logged like unsigned');
check(strpos($ie, "install_extension_verify(\$dir, \$tree_rel . \$staged_name, true)") !== false, 'an upload is verified as a fresh archive');

// ---------------------------------------------------------------------------
section('The upgrade loads the staged helper only as the signed release names it (review B1, B2)');

$up = file_get_contents(PathHelper::getIncludePath('utils/upgrade.php'));
$fn = substr($up, (int)strpos($up, 'function upgrade_proof_ready('));
$fn = substr($fn, 0, (int)strpos($fn, "\n\t}\n"));
check(strpos($fn, "PackageSignature::trustedListing(") !== false && strpos($fn, "'public_html/includes/TransparencyProof.php'") !== false
	&& strpos($fn, 'hash_equals($listed, $hash)') !== false && strpos($fn, 'trustedListing(') < strpos($fn, 'require_once($staged)'),
	'the staged TransparencyProof is hashed against the signed listing before it is loaded as root');
check(substr_count($up, "upgrade_ensure_verify_keys(\$full_site_dir, \$live_directory);\n\t\t\tupgrade_proof_ready(\$stage_location, \$stage_directory);") === 2,
	'at both core verify sites, after the key file is ensured and before the verify');
check(strpos($up, "'includes/TransparencyProof.php',") === false, 'and it stays out of the one-shot self-update set');
check(strpos($up, 'carries no release statement; on a machine that installs') !== false,
	'a kept plugin with a host installer and no statement is named in the upgrade transcript');

$pub = file_get_contents(PathHelper::getIncludePath('plugins/server_manager/includes/publish_upgrade.php'));
check(substr_count($pub, '$unlisted = publish_unlisted_members(') === 2,
	'the publisher refuses a plugin or theme holding a file on a path no manifest lists, rather than dropping it (review Q5)');

exec('rm -rf ' . escapeshellarg($tmp));
section('Keys a release proves are kept, or nothing is installed (WP7 review B4)');

// The agent refuses its own update when it cannot record the keys a release
// proved; every PHP install path does the same. Persisting runs only as root,
// so the rule is read from each caller: the catch around persistProvenKeys
// stops the install, never warns and carries on.
foreach (array('utils/upgrade.php' => 'upgrade_abort(', 'utils/install_extension.php' => 'exit(1)',
		'includes/AbstractExtensionManager.php' => 'throw new Exception(', 'utils/verify_package.php' => 'exit(1)') as $rel => $stop) {
	$src = (string)file_get_contents(PathHelper::getIncludePath($rel));
	$at = strpos($src, 'persistProvenKeys(');
	$catch = $at === false ? false : strpos($src, 'catch (Throwable $e)', $at);
	$block = $catch === false ? '' : substr($src, $catch, (int)strpos($src, "\n\t}", $catch + 1) - $catch + 200);
	$block = substr($block, 0, (int)strpos($block, '}', (int)strpos($block, $stop)) + 1);
	check($catch !== false && strpos($block, $stop) !== false && stripos($block, 'warning') === false,
		"{$rel}: a key that cannot be recorded stops the install ({$stop})", $block);
}

harness_finish();
