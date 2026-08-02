<?php
/** @joinery-test
 * name: backup_envelope_cli
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The envelope format is written in two places that must never drift: the node
 * CLI (maintenance_scripts/sysadmin_tools/backup_envelope.php, deliberately
 * standalone so it works when the platform is gone) and the core class
 * (includes/BackupEnvelope.php). They are separate on purpose, so this holds
 * them to one contract in both directions.
 *
 * Drift here is silent and only shows up at disaster time, which is the one
 * moment nobody can afford to debug a JSON schema.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/BackupEnvelope.php'));

$cli = PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools/backup_envelope.php';
if (!is_file($cli)) {
	harness_skip('node envelope CLI not present', $cli);
	harness_finish();
}

$work = sys_get_temp_dir() . '/jy_envelope_test_' . getmypid();
@mkdir($work . '/config', 0700, true);
register_shutdown_function(function () use ($work) {
	foreach (glob($work . '/config/*') ?: [] as $f) { @unlink($f); }
	foreach (glob($work . '/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
	@rmdir($work . '/config');
	@rmdir($work);
});

$recovery = sodium_crypto_box_keypair();
$rec_pub  = base64_encode(sodium_crypto_box_publickey($recovery));
$rec_sec  = base64_encode(sodium_crypto_box_secretkey($recovery));

$key_file     = $work . '/run.key';
$sidecar_file = $work . '/demo.tar.gz.enc' . BackupEnvelope::SIDECAR_SUFFIX;
$site_file    = $work . '/config/backup_site_key';

// ── CLI writes, core reads ──────────────────────────────────────────────────
section('CLI mints, core opens');

$cmd = 'php ' . escapeshellarg($cli) . ' mint'
	. ' --recovery-pub ' . escapeshellarg($rec_pub)
	. ' --artifact ' . escapeshellarg('demo.tar.gz.enc')
	. ' --key-out ' . escapeshellarg($key_file)
	. ' --sidecar-out ' . escapeshellarg($sidecar_file)
	. ' --site-key ' . escapeshellarg($site_file) . ' 2>&1';
$out = [];
$rc = 0;
exec($cmd, $out, $rc);
check($rc === 0, 'CLI mint succeeds', implode(' | ', $out));
check(is_file($key_file) && is_file($sidecar_file), 'CLI wrote the key file and the sidecar');
check(strpos(implode("\n", $out), 'ENVELOPE_RECIPIENTS=recovery,site') !== false,
	'CLI reports both recipients', implode(' | ', $out));

$minted_key = trim((string)@file_get_contents($key_file));
check($minted_key !== '', 'CLI wrote a data key');
check(strpos(implode("\n", $out), $minted_key) === false,
	'the data key never appears on stdout (job output rows persist forever)');

$env = BackupEnvelope::read_sidecar($sidecar_file);
check($env['cipher'] === BackupEnvelope::CIPHER, 'core accepts the CLI cipher name', (string)$env['cipher']);
check($env['artifact'] === 'demo.tar.gz.enc', 'core reads the CLI artifact name');
check(BackupEnvelope::open($env, $rec_sec) === $minted_key,
	'core opens a CLI-minted envelope with the recovery key');

$site_kp = base64_decode(trim((string)@file_get_contents($site_file)), true);
check(BackupEnvelope::open($env, $site_kp) === $minted_key,
	'core opens a CLI-minted envelope with the CLI-minted site key');

// ── Core writes, CLI reads ──────────────────────────────────────────────────
section('Core mints, CLI opens');

$core_key = base64_encode(random_bytes(32));
$core_env = BackupEnvelope::build($core_key, 'core.tar.gz.enc',
	[['kind' => 'recovery', 'pub' => sodium_crypto_box_publickey($recovery)]]);
$core_sidecar = $work . '/core.tar.gz.enc' . BackupEnvelope::SIDECAR_SUFFIX;
BackupEnvelope::write_sidecar($core_sidecar, $core_env);

$rec_file = $work . '/recovery.key';
file_put_contents($rec_file, $rec_sec);

$out = [];
$rc = 0;
exec('php ' . escapeshellarg($cli) . ' open'
	. ' --sidecar ' . escapeshellarg($core_sidecar)
	. ' --private ' . escapeshellarg($rec_file) . ' 2>&1', $out, $rc);
check($rc === 0, 'CLI open succeeds on a core-written sidecar', implode(' | ', $out));
check(trim(implode('', $out)) === $core_key, 'CLI recovers exactly the core data key');

// ── Shared refusals ─────────────────────────────────────────────────────────
section('Both refuse the same mistakes');

$pub_file = $work . '/recovery.pub';
file_put_contents($pub_file, $rec_pub);
$out = [];
$rc = 0;
exec('php ' . escapeshellarg($cli) . ' open --sidecar ' . escapeshellarg($core_sidecar)
	. ' --private ' . escapeshellarg($pub_file) . ' 2>&1', $out, $rc);
check($rc !== 0, 'CLI refuses the public key');
check(strpos(implode(' ', $out), 'PUBLIC half') !== false,
	'CLI names the public-key mistake, matching core', implode(' | ', $out));

$stranger = sodium_crypto_box_keypair();
$str_file = $work . '/stranger.key';
file_put_contents($str_file, base64_encode(sodium_crypto_box_secretkey($stranger)));
$out = [];
$rc = 0;
exec('php ' . escapeshellarg($cli) . ' open --sidecar ' . escapeshellarg($core_sidecar)
	. ' --private ' . escapeshellarg($str_file) . ' 2>&1', $out, $rc);
check($rc !== 0, 'CLI refuses an unrelated key');

// A newer format must be refused rather than half-read, in both engines.
$future = json_decode((string)file_get_contents($core_sidecar), true);
$future['version'] = 99;
$future_file = $work . '/future.keys.json';
file_put_contents($future_file, json_encode($future));
$out = [];
$rc = 0;
exec('php ' . escapeshellarg($cli) . ' open --sidecar ' . escapeshellarg($future_file)
	. ' --private ' . escapeshellarg($rec_file) . ' 2>&1', $out, $rc);
check($rc !== 0, 'CLI refuses a newer envelope version');

$threw = false;
try { BackupEnvelope::decode(json_encode($future)); }
catch (BackupEnvelopeException $e) { $threw = true; }
check($threw, 'core refuses a newer envelope version too');

// ── Site key stability ──────────────────────────────────────────────────────
section('Site key is minted once');

$out = [];
exec('php ' . escapeshellarg($cli) . ' site-key --site-key ' . escapeshellarg($site_file) . ' 2>&1', $out);
$reported = trim(implode('', $out));
check($reported === base64_encode(sodium_crypto_box_publickey($site_kp)),
	'site-key reports the public half of the existing keypair, never a new one', $reported);

// Backups run as more than one account (the web user for scheduled runs, the
// deploy user over SSH), so "exists but I cannot read it" is a real state. It
// must never be mistaken for "absent" — minting over a live key would orphan
// the site recipient on every backup already sealed to the first one.
$before = file_get_contents($site_file);
chmod($site_file, 0000);
$unreadable = (@file_get_contents($site_file) === false); // root can read anything
if ($unreadable) {
	$out = [];
	$rc = 0;
	exec('php ' . escapeshellarg($cli) . ' site-key --site-key ' . escapeshellarg($site_file) . ' 2>&1', $out, $rc);
	check($rc !== 0, 'an unreadable site key is an error, not a silent re-mint', implode(' | ', $out));
	check(strpos(implode(' ', $out), 'not readable') !== false,
		'and it says so, naming the account', implode(' | ', $out));
	chmod($site_file, 0600);
	check(file_get_contents($site_file) === $before, 'the existing key was left untouched');
} else {
	chmod($site_file, 0600);
	harness_skip('cannot make a file unreadable as this user', 'running with override privileges');
}

harness_finish();
