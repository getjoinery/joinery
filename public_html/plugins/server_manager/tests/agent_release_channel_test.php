<?php
/** @joinery-test
 * name: agent_release_channel
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Agent release channel — the publish-side half of agent self-update.
 *
 * The agent will only install a binary whose Ed25519 signature verifies
 * against the public key baked into it at build time. That makes the
 * publisher's key handling and manifest format load-bearing: a manifest the
 * Go side cannot parse, or a signature scheme that does not round-trip, means
 * updates silently never happen (or worse, a signing bug means they are
 * refused fleet-wide with no fallback path but hands-on-box).
 *
 * Everything here runs against temp directories — no DB writes, no Go
 * toolchain, no real key material. The PHP↔Go signature compatibility itself
 * (sodium detached sign → crypto/ed25519 verify) is RFC 8032 on both sides
 * and is proven end-to-end by the live self-update gate.
 *
 * Run: php plugins/server_manager/tests/agent_release_channel_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/AgentDistPublisher.php'));

$tmp_root = sys_get_temp_dir() . '/agent_release_channel_' . bin2hex(random_bytes(4));
mkdir($tmp_root, 0777, true);
register_shutdown_function(function () use ($tmp_root) {
	exec('rm -rf ' . escapeshellarg($tmp_root));
});

// ---------------------------------------------------------------------------
section('Signing keys: generated once, stable, correctly shaped');

$config_dir = $tmp_root . '/config';
mkdir($config_dir, 0777, true);

$keys = AgentDistPublisher::ensureKeys($config_dir);
check(file_exists($config_dir . '/agent_signing_key'), 'secret key file created');
check(file_exists($config_dir . '/agent_signing_key.pub'), 'public key file created');
check((fileperms($config_dir . '/agent_signing_key') & 0777) === 0600, 'secret key file is owner-only');
check(strlen($keys['secret']) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 'secret key has sodium sign length');
check(strlen($keys['public']) === 32, 'public key is 32 raw bytes (ed25519)');
check(base64_decode($keys['public_b64']) === $keys['public'], 'public_b64 decodes to the public key');

$again = AgentDistPublisher::ensureKeys($config_dir);
check($again['public_b64'] === $keys['public_b64'], 'second call returns the same keypair');

// A key minted into a temp dir is not this site's trust root, so it must not be
// escrowed — otherwise this safe-tier test would write rows to the live table.
$q = DbConnector::get_instance()->get_db_link()->prepare(
	"SELECT COUNT(*) FROM bke_backup_key_escrow WHERE bke_key_fingerprint = ? AND bke_kind = 'agent_signing'");
$q->execute([hash('sha256', trim(file_get_contents($config_dir . '/agent_signing_key')))]);
check((int)$q->fetchColumn() === 0, 'a signing key outside the site config dir is not escrowed (no DB writes here)');

$pub_file = trim(file_get_contents($config_dir . '/agent_signing_key.pub'));
check($pub_file === $keys['public_b64'], '.pub sibling holds the base64 public key');

file_put_contents($config_dir . '/agent_signing_key', "not base64 key material\n");
$malformed_threw = false;
try {
	AgentDistPublisher::ensureKeys($config_dir);
} catch (Exception $e) {
	$malformed_threw = true;
}
check($malformed_threw, 'malformed secret key throws instead of signing with garbage');

// ---------------------------------------------------------------------------
section('Signature scheme: detached ed25519 over the raw binary, base64 in the manifest');

$config_dir2 = $tmp_root . '/config2';
mkdir($config_dir2, 0777, true);
$keys = AgentDistPublisher::ensureKeys($config_dir2);

$fake_binary = random_bytes(4096);
$sig = sodium_crypto_sign_detached($fake_binary, $keys['secret']);
check(sodium_crypto_sign_verify_detached($sig, $fake_binary, $keys['public']), 'signature verifies against the public key');
check(strlen($sig) === 64, 'signature is 64 bytes (what Go ed25519.Verify expects)');
check(!sodium_crypto_sign_verify_detached($sig, $fake_binary . 'x', $keys['public']), 'tampered binary fails verification');

$other = sodium_crypto_sign_keypair();
check(!sodium_crypto_sign_verify_detached($sig, $fake_binary, sodium_crypto_sign_publickey($other)), 'other key fails verification');

// ---------------------------------------------------------------------------
section('Manifest contract: the exact shape the Go agent parses');

$dist_dir = $tmp_root . '/agent_dist';
mkdir($dist_dir, 0777, true);
file_put_contents($dist_dir . '/joinery-agent-linux-amd64.gz', gzencode($fake_binary, 9));
$manifest = array(
	'version'  => '9.9.9',
	'binaries' => array(
		'linux-amd64' => array(
			'file'      => 'joinery-agent-linux-amd64.gz',
			'sha256'    => hash('sha256', $fake_binary),
			'signature' => base64_encode($sig),
		),
	),
);
file_put_contents($dist_dir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$read = AgentDistPublisher::readManifest($dist_dir);
check(($read['version'] ?? '') === '9.9.9', 'readManifest round-trips version');
check(AgentDistPublisher::artifactsPresent($dist_dir, $read), 'artifactsPresent true when the named file exists');

unlink($dist_dir . '/joinery-agent-linux-amd64.gz');
check(!AgentDistPublisher::artifactsPresent($dist_dir, $read), 'artifactsPresent false when a named binary is missing');
check(AgentDistPublisher::readManifest($tmp_root . '/nowhere') === null, 'readManifest null on absent dir');

$gz_restored = gzencode($fake_binary, 9);
file_put_contents($dist_dir . '/joinery-agent-linux-amd64.gz', $gz_restored);
$decompressed = gzdecode(file_get_contents($dist_dir . '/joinery-agent-linux-amd64.gz'));
check($decompressed === $fake_binary, 'gz artifact decompresses back to the signed bytes');
check(hash('sha256', $decompressed) === $read['binaries']['linux-amd64']['sha256'], 'manifest sha256 matches the decompressed bytes');

// ---------------------------------------------------------------------------
section('Source version parsing and carry-forward inputs');

$src_dir = $tmp_root . '/agent_src';
mkdir($src_dir, 0777, true);
file_put_contents($src_dir . '/main.go', "package main\n\nvar version = \"1.2.3\"\n");
check(AgentDistPublisher::readSourceVersion($src_dir) === '1.2.3', 'readSourceVersion parses var version from main.go');
check(AgentDistPublisher::readSourceVersion($tmp_root . '/nowhere') === null, 'readSourceVersion null when main.go missing');

// The real agent checkout, when present on this box, must be parseable — a
// publish here silently carrying forward because of a format drift in main.go
// is exactly the staleness this channel exists to kill.
$real_src = AgentDistPublisher::sourcePath();
if (is_dir($real_src) && file_exists($real_src . '/main.go')) {
	$real_version = AgentDistPublisher::readSourceVersion($real_src);
	check(preg_match('/^\d+\.\d+\.\d+$/', (string)$real_version) === 1, 'real agent source version is parseable (' . ($real_version ?: 'FAILED') . ')');
} else {
	harness_skip('real agent source absent on this box - carry-forward path applies');
}

// ---------------------------------------------------------------------------
section('publish() status contract: a failed rebuild is distinguishable from a carry-forward');

// The distinction is what lets publish_upgrade.php refuse a release rather than
// ship a bundle it already knows is stale. Getting it wrong in either direction
// is costly: treat a failure as benign and the release goes out wrong; treat a
// box without agent source as a failure and control planes cannot publish.
//
// Everything below runs against throwaway directories with the agent source
// path overridden in memory for this process only.

/** Build a disposable site tree with an agent_dist manifest at $bundled_version. */
$make_site = function ($label, $bundled_version) use ($tmp_root) {
	$site = $tmp_root . '/site_' . $label;
	$dist = $site . '/public_html/plugins/server_manager/agent_dist';
	mkdir($dist, 0777, true);
	mkdir($site . '/config', 0777, true);
	if ($bundled_version !== null) {
		file_put_contents($dist . '/manifest.json', json_encode(array(
			'version'  => $bundled_version,
			'binaries' => array('linux-amd64' => array(
				'file' => 'joinery-agent-linux-amd64.gz', 'sha256' => 'x', 'signature' => 'y',
			)),
		)));
		file_put_contents($dist . '/joinery-agent-linux-amd64.gz', 'placeholder');
	}
	return array($site, $dist);
};

// -- no agent source on this box: carry forward, never fail ------------------
harness_set_setting_mem('server_manager_agent_source_path', $tmp_root . '/no_such_agent_src');
list($site_a, $dist_a) = $make_site('carried', '1.0.0');
$res = AgentDistPublisher::publish($site_a, null);
check($res['status'] === AgentDistPublisher::STATUS_CARRIED,
	'no agent source -> carried (publish continues)',
	'got ' . var_export($res['status'], true));
check($res['bundled_version'] === '1.0.0', 'carried result reports the bundled version');

// -- source and bundle agree: skip, without invoking the toolchain -----------
$same_src = $tmp_root . '/agent_src_same';
mkdir($same_src, 0777, true);
file_put_contents($same_src . '/main.go', "package main\n\nvar version = \"2.0.0\"\n");
harness_set_setting_mem('server_manager_agent_source_path', $same_src);
list($site_b, $dist_b) = $make_site('skipped', '2.0.0');
$before_b = file_get_contents($dist_b . '/manifest.json');
$res = AgentDistPublisher::publish($site_b, null);
check($res['status'] === AgentDistPublisher::STATUS_SKIPPED,
	'source version == bundled version -> skipped',
	'got ' . var_export($res['status'], true));
check(file_get_contents($dist_b . '/manifest.json') === $before_b,
	'skipped leaves agent_dist byte-identical');

// -- rebuild owed but the build breaks: failed, and nothing is disturbed -----
// main.go declares a newer version and does not compile, which is the shape of
// the real incident: the publisher knew it owed a rebuild and could not deliver.
$bad_src = $tmp_root . '/agent_src_bad';
mkdir($bad_src, 0777, true);
file_put_contents($bad_src . '/main.go', "package main\n\nvar version = \"9.9.9\"\n\nthis is not go\n");
file_put_contents($bad_src . '/go.mod', "module joinery-agent-broken\n\ngo 1.21\n");
harness_set_setting_mem('server_manager_agent_source_path', $bad_src);
list($site_c, $dist_c) = $make_site('failed', '1.0.0');
$before_c = file_get_contents($dist_c . '/manifest.json');
$res = AgentDistPublisher::publish($site_c, null);

check($res['status'] === AgentDistPublisher::STATUS_FAILED,
	'rebuild owed + build fails -> failed (publish must refuse)',
	'got ' . var_export($res['status'], true));
check($res['source_version'] === '9.9.9' && $res['bundled_version'] === '1.0.0',
	'failed result names both versions so the refusal can explain itself',
	'got source=' . var_export($res['source_version'], true)
	. ' bundled=' . var_export($res['bundled_version'], true));
check(file_get_contents($dist_c . '/manifest.json') === $before_c,
	'failed rebuild leaves the previous agent_dist in place');
check(!is_dir($dist_c . '.staging'), 'failed rebuild cleans up its staging directory');

harness_finish();
