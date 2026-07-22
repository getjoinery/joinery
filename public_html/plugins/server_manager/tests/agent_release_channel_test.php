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

harness_finish();
