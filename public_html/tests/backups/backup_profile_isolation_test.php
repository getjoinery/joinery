<?php
/** @joinery-test
 * name: backup_profile_isolation
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Two parties backing up one site must not collide.
 *
 * A site backs itself up; a control plane managing it takes its own copies.
 * Those are two parties' backups under two recovery keys, and every place they
 * could touch — working directory, lock, tar snapshot, bucket path, envelope
 * recipient, local sweep — has to keep them apart. The failure modes here are
 * all silent: a shared snapshot corrupts both chains while every run reports
 * success, a shared bucket path makes a listing unattributable, and a shared
 * sweep deletes the other party's only local copy.
 *
 * This pins the isolation itself. The end-to-end behaviour of a run is covered
 * by backup_chain_gate.sh with real tar and openssl.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/BackupProfile.php'));
require_once(PathHelper::getIncludePath('includes/BackupEnvelope.php'));
require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));

// ── Naming and paths ────────────────────────────────────────────────────────
section('A profile decides where a run lives');

check(BackupProfile::normalize('') === BackupProfile::SITE,
	'an unstated profile is the site\'s own');
check(BackupProfile::normalize('manager') === BackupProfile::MANAGER,
	'the manager profile is addressable by name');

$threw = false;
try { BackupProfile::normalize('mangaer'); }
catch (BackupProfileException $e) { $threw = true; }
check($threw, 'an unknown profile throws rather than falling back to site');

$base = '/srv/site/backups';
$site_dir = BackupProfile::output_dir(BackupProfile::SITE, $base);
$mgr_dir  = BackupProfile::output_dir(BackupProfile::MANAGER, $base);

check($site_dir === $base, 'the site profile builds in the configured directory', $site_dir);
check($mgr_dir !== $site_dir, 'the manager profile builds somewhere else', $mgr_dir);
check(strpos($mgr_dir, rtrim($base, '/') . '/') === 0,
	'and inside it, so one permission decision covers both', $mgr_dir);

// Separate directories are what give each profile its own snapshot. Sharing one
// .snar is the single worst collision available: each run advances it, so each
// profile would treat the other's work as already archived and both chains
// would restore to something that never existed.
check(BackupProfile::machine_lock_path($base) === BackupProfile::machine_lock_path($base . '/'),
	'the machine lock path is stable regardless of trailing slash');
check(dirname(BackupProfile::machine_lock_path($base)) === $base,
	'the machine lock sits in the base directory, so both profiles contend for one file');

// Two locks, two jobs. The per-profile lock is correctness — two runs of one
// profile share a snapshot and a manifest. The machine lock is the box: two
// profiles archiving the same tree at once is twice the I/O for no extra safety.
$site_lock = $site_dir . '/.jy_backup.lock';
$mgr_lock  = $mgr_dir  . '/.jy_backup.lock';
check($site_lock !== $mgr_lock,
	'each profile has its own correctness lock, so one cannot exclude the other by accident');
check(BackupProfile::machine_lock_path($base) !== $site_lock
	&& BackupProfile::machine_lock_path($base) !== $mgr_lock,
	'and the machine lock is a third file, held above both');

// ── Bucket layout ───────────────────────────────────────────────────────────
section('Two parties, two shelves');

check(BackupProfile::path_segment(BackupProfile::SITE) !== BackupProfile::path_segment(BackupProfile::MANAGER),
	'the profiles file under different bucket segments');

// ── Key separation ──────────────────────────────────────────────────────────
section('A manager run seals to the manager key, not the site\'s');

$site_recovery = sodium_crypto_box_keypair();
$mgr_recovery  = sodium_crypto_box_keypair();
$site_key      = sodium_crypto_box_keypair();

$data_key = base64_encode(random_bytes(32));

// What a manager-profile run builds: the control plane's recovery key, plus the
// node's own site key so the node can restore itself unattended.
$manager_envelope = BackupEnvelope::build($data_key, 'files-0000.tar.gz.enc', array(
	array('kind' => 'recovery', 'pub' => sodium_crypto_box_publickey($mgr_recovery)),
	array('kind' => 'site',     'pub' => sodium_crypto_box_publickey($site_key)),
));

check(BackupEnvelope::open($manager_envelope, sodium_crypto_box_secretkey($mgr_recovery)) === $data_key,
	'the control plane\'s recovery key opens its own backup');
check(BackupEnvelope::open($manager_envelope, sodium_crypto_box_secretkey($site_key)) === $data_key,
	'and so does the node, unattended, with its own site key');

// The whole point of two keys: the site operator's recovery key is not a key to
// the control plane's copies, and vice versa.
$opened = null;
try { $opened = BackupEnvelope::open($manager_envelope, sodium_crypto_box_secretkey($site_recovery)); }
catch (Throwable $e) { $opened = false; }
check($opened === false || $opened === null,
	'the site operator\'s recovery key does NOT open the control plane\'s backup');

$site_envelope = BackupEnvelope::build($data_key, 'files-0000.tar.gz.enc', array(
	array('kind' => 'recovery', 'pub' => sodium_crypto_box_publickey($site_recovery)),
	array('kind' => 'site',     'pub' => sodium_crypto_box_publickey($site_key)),
));
$opened = null;
try { $opened = BackupEnvelope::open($site_envelope, sodium_crypto_box_secretkey($mgr_recovery)); }
catch (Throwable $e) { $opened = false; }
check($opened === false || $opened === null,
	'and the control plane\'s recovery key does NOT open the site\'s own backup');

section('A supplied recovery key is validated, not trusted');

$threw = false;
try { BackupEnvelope::recipients_for_foreign_recovery('not-a-key'); }
catch (BackupEnvelopeException $e) { $threw = true; }
check($threw, 'a malformed recovery key fails at build time');

// Sealing to a well-formed key nobody holds the private half of always appears
// to succeed, which is why the encoding is checked rather than assumed.
$b64 = base64_encode(sodium_crypto_box_publickey($mgr_recovery));
check(BackupEnvelope::normalize_public_key($b64) === sodium_crypto_box_publickey($mgr_recovery),
	'a base64 recovery key is accepted and decoded');
check(BackupEnvelope::normalize_public_key(sodium_crypto_box_publickey($mgr_recovery))
	=== sodium_crypto_box_publickey($mgr_recovery),
	'and raw bytes are accepted unchanged');

// ── Local sweep ─────────────────────────────────────────────────────────────
section('Neither profile sweeps the other\'s local files');

$tmp = sys_get_temp_dir() . '/jy_profile_sweep_' . bin2hex(random_bytes(4));
$site_out = BackupProfile::output_dir(BackupProfile::SITE, $tmp);
$mgr_out  = BackupProfile::output_dir(BackupProfile::MANAGER, $tmp);
mkdir($mgr_out, 0700, true);

$old = time() - (30 * 86400);
$site_file = $site_out . '/demo-20260101_000000.tar.gz.enc';
$mgr_file  = $mgr_out  . '/demo-20260101_000000.tar.gz.enc';
file_put_contents($site_file, 'x');
file_put_contents($mgr_file, 'x');
touch($site_file, $old);
touch($mgr_file, $old);

$swept = BackupRunner::sweep_local(array('output_dir' => $site_out, 'keep_local' => 7));

check($swept === 1, 'the site sweep takes its own stale archive', (string)$swept);
check(!is_file($site_file), 'which is gone');
check(is_file($mgr_file), 'and the control plane\'s copy is untouched');

$swept = BackupRunner::sweep_local(array('output_dir' => $mgr_out, 'keep_local' => 7));
check($swept === 1 && !is_file($mgr_file), 'the manager sweep takes its own', (string)$swept);

@unlink($site_file); @unlink($mgr_file); @rmdir($mgr_out); @rmdir($site_out);

// ── Retention ───────────────────────────────────────────────────────────────
section('The manager profile never prunes the shelf it does not own');

// The credential a node is handed for a manager run cannot delete. Retention
// there belongs to the control plane — so both passes must decline outright
// rather than try and fail. The plan carries no keep count on purpose: the
// flag is the whole answer, and declining must never depend on reading a
// number the manager plan does not have.
$mgr_plan = array('prunes_cloud' => false, 'profile' => BackupProfile::MANAGER,
                  'slug' => 'demo');
check(BackupRunner::enforce_cloud_retention($mgr_plan) === 0,
	'standalone retention declines for a manager-profile run');
check(BackupRunner::enforce_chain_retention($mgr_plan) === 0,
	'chain retention declines too');

harness_finish();
