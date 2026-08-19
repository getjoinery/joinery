<?php
/** @joinery-test
 * name: backup_manager_coverage
 * tier: db
 * env: any
 * needs: [db]
 */
/**
 * BackupHistory::manager_coverage() — the run proving a control plane
 * currently backs this site up. The setup wizard's backups step reads it as
 * a second green path, so what counts as coverage has to be exact: a recent
 * manager-profile success that reached its bucket, and nothing else — not a
 * stale one, not a failure, not a local-only run, not the site's own backup.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/backup_history_class.php'));
require_once(PathHelper::getIncludePath('includes/BackupProfile.php'));

/** One history row, registered for cleanup. */
function coverage_row(array $fields) {
	$row = new BackupHistory(NULL);
	$row->set('bkh_type', 'project');
	$row->set('bkh_slug', 'coverage-test');
	foreach ($fields as $k => $v) {
		$row->set($k, $v);
	}
	$row->save();
	harness_register_row('bkh_backup_history', 'bkh_id', $row->key);
	return $row;
}

$now = gmdate('Y-m-d H:i:s');
$stale = LibraryFunctions::time_shift($now, '-' . (BackupHistory::MANAGER_COVERAGE_DAYS + 1) . ' days', 'Y-m-d H:i:s');

section('What does not count');

$stale_row = coverage_row(array(
	'bkh_profile' => BackupProfile::MANAGER, 'bkh_outcome' => 'success',
	'bkh_finish_time' => $stale, 'bkh_upload_time' => $stale,
));
$covered = BackupHistory::manager_coverage();
check($covered === null || (int)$covered->key !== (int)$stale_row->key,
	'a run older than MANAGER_COVERAGE_DAYS is not coverage',
	'abandoned coverage must not read as protection');

$failed_row = coverage_row(array(
	'bkh_profile' => BackupProfile::MANAGER, 'bkh_outcome' => 'failed',
	'bkh_finish_time' => $now,
));
$covered = BackupHistory::manager_coverage();
check($covered === null || (int)$covered->key !== (int)$failed_row->key,
	'a failed run is not coverage');

$local_row = coverage_row(array(
	'bkh_profile' => BackupProfile::MANAGER, 'bkh_outcome' => 'success',
	'bkh_finish_time' => $now,
));
$covered = BackupHistory::manager_coverage();
check($covered === null || (int)$covered->key !== (int)$local_row->key,
	'a success that never reached the bucket is not coverage',
	'a local-only archive does not survive this server dying');

$site_row = coverage_row(array(
	'bkh_profile' => BackupProfile::SITE, 'bkh_outcome' => 'success',
	'bkh_finish_time' => $now, 'bkh_upload_time' => $now,
));
$covered = BackupHistory::manager_coverage();
check($covered === null || (int)$covered->key !== (int)$site_row->key,
	'the site\'s own run is not manager coverage',
	'the two parties\' backups must never answer for each other');

section('What does count');

$fresh_row = coverage_row(array(
	'bkh_profile' => BackupProfile::MANAGER, 'bkh_outcome' => 'success',
	'bkh_finish_time' => $now, 'bkh_upload_time' => $now,
));
$covered = BackupHistory::manager_coverage();
check($covered !== null && (int)$covered->key === (int)$fresh_row->key,
	'a fresh manager-profile success that reached its bucket is coverage');

section('The backups step reads it');
require_once(PathHelper::getIncludePath('includes/SetupSteps.php'));
$step = SetupSteps::get('backups');
check(is_array($step) && SetupSteps::statusFor($step, null) === SetupSteps::STATUS_GREEN,
	'with live coverage the backups step is green',
	'a fleet-backed node must not be told no backups are running');
check(is_callable($step['copy'] ?? null)
	&& strpos(SetupSteps::copyFor($step, null), 'already backed up') !== false,
	'and its intro says so instead of asking for a bucket');

harness_finish();
