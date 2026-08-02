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
	foreach (glob($work . '/*') ?: array() as $f) { @unlink($f); }
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

harness_finish();
