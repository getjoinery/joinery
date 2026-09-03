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

// The signing key needs no recovery record of its own: it lives in the site's
// config/ directory, which the site's own encrypted project backup carries. So
// minting a key must write nothing to the database at all — this is a safe-tier
// test and must stay free of side effects.

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
// box without agent source as a failure and management nodes cannot publish.
//
// Everything below runs against throwaway directories with the agent source
// path overridden in memory for this process only.

/** Build a disposable site tree with an agent_dist manifest at $bundled_version. */
$make_site = function ($label, $bundled_version) use ($tmp_root) {
	$site = $tmp_root . '/site_' . $label;
	$dist = $site . '/public_html/agent_dist';
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
// The one thing a skip may add: the key the bundle was built with, when the
// manifest predates that record. A bundle at the source's version on the box
// that holds the source was built here, so the record is this site's key.
$after_b = json_decode(file_get_contents($dist_b . '/manifest.json'), true);
$own_pub_b = trim(file_get_contents($site_b . '/config/agent_signing_key.pub'));
check(($after_b['signing_public_key'] ?? '') === $own_pub_b,
	'skipped stamps the missing signing_public_key with this site\'s key');
check($after_b['version'] === '2.0.0' && $after_b['binaries'] === json_decode($before_b, true)['binaries'],
	'and changes nothing else in the manifest');
check(AgentDistPublisher::bundleSigningKey($site_b) === $own_pub_b,
	'bundleSigningKey() reads it back');
$stamped_b = file_get_contents($dist_b . '/manifest.json');
$res = AgentDistPublisher::publish($site_b, null);
check($res['status'] === AgentDistPublisher::STATUS_SKIPPED
	&& file_get_contents($dist_b . '/manifest.json') === $stamped_b,
	'a second skip leaves agent_dist byte-identical');

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


section('install_agent.sh installs forward only, never backward');

// The other half of self-update. install_agent.sh runs at every root moment —
// container start, site install, code upgrade, Run Plugin Installers — and
// ships whatever artifact was current when the site archive was built. A node
// that has self-updated past that is the normal case, not the exception, so an
// installer that reinstalls on any version difference walks the fleet backwards
// one root moment at a time. It cost getjoinery its 1.1.0 agent (2026-08-04),
// replaced by the 0.4.0 in its site tree, reported as a successful install.
//
// The decision is executed here rather than pattern-matched: extract the real
// comparison out of the shipped script and run it.
$installer_sh = dirname(PathHelper::getIncludePath('')) . '/maintenance_scripts/install_tools/install_agent.sh';
$installer_src = is_file($installer_sh) ? file_get_contents($installer_sh) : '';
check($installer_src !== '', 'install_agent.sh is readable', $installer_sh);

$cmp_fn = '';
if (preg_match('/version_is_older\(\) \{.*?\n\}/s', $installer_src, $m)) {
	$cmp_fn = $m[0];
}
check($cmp_fn !== '', 'the version comparison is findable');
check(strpos($cmp_fn, 'sort -V') !== false,
	'it compares with sort -V',
	'string comparison ranks 0.10.0 below 0.9.0');

// Same predicate and same guard expression as the script itself.
$decide = function (string $current, string $dist, string $allow = '0') use ($cmp_fn): string {
	$script = $cmp_fn . "\n"
		. 'if [ -n "$1" ] && [ "$3" != "1" ] && ! version_is_older "$1" "$2"; '
		. 'then echo KEEP; else echo INSTALL; fi';
	$path = tempnam(sys_get_temp_dir(), 'agentver');
	file_put_contents($path, $script);
	$out = shell_exec('bash ' . escapeshellarg($path) . ' '
		. escapeshellarg($current) . ' ' . escapeshellarg($dist) . ' ' . escapeshellarg($allow));
	unlink($path);
	return trim((string)$out);
};

if ($cmp_fn !== '') {
	check($decide('', '0.4.0') === 'INSTALL',
		'a node with no agent gets one');
	check($decide('0.3.0', '0.4.0') === 'INSTALL',
		'an older agent is upgraded');
	check($decide('0.4.0', '0.4.0') === 'KEEP',
		'the same version is left alone');
	check($decide('1.1.0', '0.4.0') === 'KEEP',
		'a self-updated agent is NOT rolled back to the shipped artifact',
		'this is the regression that took getjoinery back to 0.4.0');
	check($decide('0.9.0', '0.10.0') === 'INSTALL',
		'0.9.0 is older than 0.10.0');
	check($decide('0.10.0', '0.9.0') === 'KEEP',
		'0.10.0 is newer than 0.9.0',
		'the pair string comparison gets backwards');
	check($decide('1.1.0', '0.4.0', '1') === 'INSTALL',
		'JOINERY_AGENT_ALLOW_DOWNGRADE forces the shipped artifact on');
}

// Keeping the binary must not mean skipping supervision: the env file and the
// keepalive are what a container recreation loses, and converging them is the
// reason to run this script on a node that is already up to date.
//
// This is now structural rather than a property of the keep branch. Deciding
// the binary and deciding whether the agent runs are separate passes, and the
// second runs whatever the first concluded — so there is no path that keeps a
// binary and skips its supervision, and none that installs one and leaves it
// unsupervised either. Asserted as ordering: supervision and start come after
// the binary pass and outside it.
$binary_pass_at  = strpos($installer_src, 'converge_binary || true');
$supervision_at  = strpos($installer_src, "\nensure_supervision\n");
$start_at        = strpos($installer_src, "\nstart_agent\n");

check($binary_pass_at !== false, 'the binary pass runs on its own');
check($supervision_at !== false && $supervision_at > $binary_pass_at,
	'supervision converges after it, for a kept binary as much as a fresh one',
	'env file and cron keepalive are exactly what a container swap drops');
check($start_at !== false && $start_at > $binary_pass_at,
	'and the agent is started from there, not from the install branch');

// The env file specifically: written on every run, before the switch is even
// consulted, because a machine that is switched on later needs it already there.
$env_at    = strpos($installer_src, "\nwrite_env_file\n");
$switch_at = strpos($installer_src, 'if [ "$AGENT_ENABLED" != "1" ]');
check($env_at !== false && $switch_at !== false && $env_at < $switch_at,
	'the env file is written whether or not the agent runs here');

harness_finish();
