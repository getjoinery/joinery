<?php
/** @joinery-test
 * name: backup_runner
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The self-backup runner.
 *
 * Two things here can destroy data and one can produce a worthless backup, so
 * those are what is asserted:
 *
 *   * retention decides which restore points to delete — it must never empty
 *     the shelf, whatever it is asked for
 *   * the local sweep deletes files by age — it must not take the envelope off
 *     an archive it is keeping, and 0 must mean never
 *   * the local sweep must reach inside chain directories, and must leave the
 *     manifest and snapshot alone: nothing else deletes a chain artifact on a
 *     managed node, and losing either file silently forces a full every run
 *   * the run must ship the archive IT made, not whatever happens to look
 *     newest in the directory
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));
require_once(PathHelper::getIncludePath('includes/BackupEnvelope.php'));

// ── Retention selection ─────────────────────────────────────────────────────
section('Retention never empties the shelf');

$rows = array('newest', 'a', 'b', 'c', 'd', 'oldest');

check(BackupRunner::surplus($rows, 4) === array('d', 'oldest'),
	'keeping 4 of 6 drops the two oldest', implode(',', BackupRunner::surplus($rows, 4)));
check(BackupRunner::surplus($rows, 6) === array(),
	'keeping exactly as many as exist drops nothing');
check(BackupRunner::surplus($rows, 10) === array(),
	'keeping more than exist drops nothing');
check(BackupRunner::surplus(array(), 4) === array(),
	'an empty shelf has nothing surplus');
check(BackupRunner::surplus(array('only'), 1) === array(),
	'a single backup is never surplus');

// A misconfigured or corrupted count must not be read as "delete everything".
foreach (array(0, -1, -100, '0', 'nonsense') as $bad) {
	$s = BackupRunner::surplus($rows, $bad);
	check(count($s) === count($rows) - 1,
		'a keep count of ' . var_export($bad, true) . ' still keeps the newest one',
		'would drop ' . count($s) . ' of ' . count($rows));
	check(!in_array('newest', $s, true), 'and never the newest');
}

// The newest is never in the surplus set, at any keep count.
for ($k = 1; $k <= 8; $k++) {
	check(!in_array('newest', BackupRunner::surplus($rows, $k), true),
		"keep={$k} never drops the newest backup");
}

// ── Local sweep ─────────────────────────────────────────────────────────────
section('Local sweep');

$work = sys_get_temp_dir() . '/jy_runner_test_' . getmypid();
@mkdir($work, 0700, true);
register_shutdown_function(function () use ($work) {
	foreach (glob($work . '/*/*') ?: array() as $f) { @unlink($f); }
	foreach (glob($work . '/*') ?: array() as $f) { is_dir($f) ? @rmdir($f) : @unlink($f); }
	foreach (glob($work . '/.*.snar') ?: array() as $f) { @unlink($f); }
	@rmdir($work);
});

$make = function ($name, $age_days) use ($work) {
	$path = $work . '/' . $name;
	file_put_contents($path, 'x');
	touch($path, time() - (int)($age_days * 86400));
	return $path;
};

$old_archive  = $make('site-old.tar.gz.enc', 30);
$old_sidecar  = $make('site-old.tar.gz.enc' . BackupEnvelope::SIDECAR_SUFFIX, 30);
$new_archive  = $make('site-new.tar.gz.enc', 1);
$new_sidecar  = $make('site-new.tar.gz.enc' . BackupEnvelope::SIDECAR_SUFFIX, 1);
$old_snapshot = $make('auto_pre_restore_20260101.sql.gz', 30);
$unrelated    = $make('notes.txt', 90);

$plan = array('output_dir' => $work, 'keep_local' => 7);
$swept = BackupRunner::sweep_local($plan);

check(!file_exists($old_archive), 'an archive past the window is swept');
check(!file_exists($old_sidecar), 'and its envelope goes with it, never left orphaned');
check(!file_exists($old_snapshot), 'the auto_pre_* snapshot a restore left behind is swept too');
check(file_exists($new_archive), 'a recent archive is kept');
check(file_exists($new_sidecar), 'and keeps its envelope — sweeping it would strand the archive');
check(file_exists($unrelated), 'a file that is not a backup is left alone');
check($swept >= 2, 'the sweep reports what it removed', (string)$swept);

// 0 means never, not "immediately".
$keep_all = $make('site-ancient.tar.gz.enc', 400);
$none = BackupRunner::sweep_local(array('output_dir' => $work, 'keep_local' => 0));
check($none === 0 && file_exists($keep_all),
	'a window of 0 sweeps nothing at all, however old the file');

// ── Chain directories ───────────────────────────────────────────────────────
// Incremental runs write one directory down, where the single-level glob above
// never sees them. On a managed node nothing else deletes a local chain
// artifact either — enforce_chain_retention() is gated on pruning the bucket,
// which such a node deliberately does not do — so a miss here means those
// archives are kept forever while the sweep reports success.
section('Local sweep reaches inside a chain');

// The ancient file the previous case parked at the top level would be swept by
// this pass too, and the count below is meant to be about the chain alone.
@unlink($keep_all);

$chain_d = $work . '/' . BackupChain::DIR_PREFIX . '20260101_030000';
@mkdir($chain_d, 0700, true);

$make_in = function ($name, $age_days) use ($chain_d) {
	$path = $chain_d . '/' . $name;
	file_put_contents($path, 'x');
	touch($path, time() - (int)($age_days * 86400));
	return $path;
};

$chain_old_files = $make_in('files-0000.tar.gz.enc', 30);
$chain_old_db    = $make_in('db-0000.sql.gz.enc', 30);
$chain_old_meta  = $make_in('meta-0000.tar.gz.enc', 30);
$chain_new_files = $make_in('files-0009.tar.gz.enc', 1);
$chain_manifest  = $make_in(BackupChain::MANIFEST_NAME, 30);
$chain_snapshot  = $make('.site.snar', 30);

$swept_chain = BackupRunner::sweep_local(array('output_dir' => $work, 'keep_local' => 7));

check(!file_exists($chain_old_files), 'a chain archive past the window is swept');
check(!file_exists($chain_old_db) && !file_exists($chain_old_meta),
	'every artifact kind goes, not just the files tar');
check(file_exists($chain_new_files),
	'a recent run in the same chain is kept — the window is per file, not per chain');
check(file_exists($chain_manifest),
	'the manifest survives: without it the next run reads no_chain and starts a fresh full');
check(file_exists($chain_snapshot),
	'the snapshot survives: without it the next run reads snar_lost and starts a fresh full');
check(is_dir($chain_d),
	'the emptied chain directory stays — retiring a chain is chain retention\'s call');
check($swept_chain === 3, 'the sweep counts what it removed from the chain', (string)$swept_chain);

// ── Plan refusals ───────────────────────────────────────────────────────────
section('The run refuses rather than producing something worthless');

// slug() is what a retention delete prefixes with, so it must reject anything
// that could widen a delete past this site.
$settings_row = function ($name, $value) {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare(
		"INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name)
		 VALUES (?, ?, 1, NOW(), NOW(), 'backups')
		 ON CONFLICT (stg_name) DO UPDATE SET stg_value = EXCLUDED.stg_value");
	$q->execute(array($name, $value));
};

$db = DbConnector::get_instance()->get_db_link();
$q = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
$q->execute(array('backup_path_slug'));
$original_slug = (string)$q->fetchColumn();

foreach (array('../other-site', 'a/b', 'has space', 'semi;colon', '*') as $bad) {
	$settings_row('backup_path_slug', $bad);
	$threw = false;
	try { BackupRunner::slug(); } catch (Exception $e) { $threw = true; }
	check($threw, 'a backup folder name of ' . var_export($bad, true) . ' is refused');
}

$settings_row('backup_path_slug', 'good-name_1');
check(BackupRunner::slug() === 'good-name_1', 'a sane folder name is accepted', BackupRunner::slug());

$settings_row('backup_path_slug', $original_slug);

// A run with nothing configured reports why instead of failing obscurely.
$result = BackupRunner::run(array());
check(in_array($result['status'], array('skipped', 'error'), true),
	'an unconfigured site does not claim success', $result['status']);
check(strlen($result['message']) > 20, 'and says what is missing', $result['message']);

// ── Staged chain restores nobody came back for ──────────────────────────────
section('A staged chain restore is swept as a unit');

// stage_chain downloads a whole chain onto the node and recovers the chain data
// key beside it, so an operator can approve a restore against artifacts already
// verified there. Deciding NOT to approve is an ordinary outcome — and nothing
// removed the workspace afterwards, so a look at an approval screen left the
// entire chain on disk for ever. Measured at 67MB for a small site.
$base = $work . '/base';
$profile_out = $base . '/manager';
@mkdir($profile_out, 0700, true);

$staged = $base . '/' . BackupRunner::STAGED_RESTORE_PREFIX . BackupChain::DIR_PREFIX . '20260101_040000';
@mkdir($staged, 0700, true);
file_put_contents($staged . '/files-0000.tar.gz.enc', 'x');
file_put_contents($staged . '/' . BackupChain::MANIFEST_NAME, '{}');
// The recovered chain data key. BackupNaming does not know this name, so a
// sweep that folded the workspace's files into the ordinary candidate list
// would delete the archives and leave the KEY — the worse half of the leak.
file_put_contents($staged . '/chain.key', 'a-recovered-data-key');
touch($staged, time() - (30 * 86400));

$fresh = $base . '/' . BackupRunner::STAGED_RESTORE_PREFIX . BackupChain::DIR_PREFIX . '20260101_050000';
@mkdir($fresh, 0700, true);
file_put_contents($fresh . '/files-0000.tar.gz.enc', 'x');

// A manager-profile plan: output_dir is a level BELOW the base, which is why
// the workspace has to be resolved from base_dir and not from output_dir.
BackupRunner::sweep_local(array(
	'output_dir' => $profile_out, 'base_dir' => $base, 'keep_local' => 7));

check(!is_dir($staged), 'a staged restore past the window is removed');
check(!file_exists($staged . '/chain.key'),
	'the recovered chain key goes with it — the part BackupNaming would not have matched');
check(is_dir($fresh) && file_exists($fresh . '/files-0000.tar.gz.enc'),
	'a workspace still inside the window is left alone — a restore may be mid-flight');

// It is resolved from the base, not the profile directory. A manager-profile
// run whose output_dir was used instead would never see the workspace at all,
// which is exactly how this was missed the first time.
$deep = $profile_out . '/' . BackupRunner::STAGED_RESTORE_PREFIX . BackupChain::DIR_PREFIX . '20260101_060000';
@mkdir($deep, 0700, true);
file_put_contents($deep . '/files-0000.tar.gz.enc', 'x');
touch($deep, time() - (30 * 86400));
BackupRunner::sweep_local(array(
	'output_dir' => $profile_out, 'base_dir' => $base, 'keep_local' => 7));
check(is_dir($deep),
	'and only under the base: stage_chain never writes one inside a profile directory');

harness_finish();
