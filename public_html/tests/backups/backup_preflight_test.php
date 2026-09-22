<?php
/** @joinery-test
 * name: backup_preflight
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A backup run refuses, before it writes anything, when this disk cannot hold
 * what the run lands locally (specs/disk_headroom_and_unit_diagnosis.md §5).
 *
 * Every archive and dump streams to the bucket, so what a run needs here is
 * the snapshot, the manifest and a few small files — and a run that streams
 * essentially never refuses, which is correct. What is pinned: the rule, the
 * wording (both figures, always), the sizes it reads, and that a refused chain
 * run leaves the snapshot, the manifest and the chain directory as they were,
 * so a refusal costs nothing but the run.
 *
 * Run: php tests/backups/backup_preflight_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$GiB = 1073741824;

section('The rule, pure');
$need = BackupRunner::PREFLIGHT_LOCAL_OVERHEAD;
$required = (int)ceil($need * 1.2) + BackupRunner::PREFLIGHT_FLOOR;
check(BackupRunner::preflight_refusal($need, $required, 0) === '', 'exactly the padded need plus the floor is enough');
$msg = BackupRunner::preflight_refusal($need, $required - 1, 0);
check($msg !== '', 'one byte less is refused');
check(BackupRunner::preflight_refusal($need, null, 0) === '', 'unknowable free space is not a refusal');
check(BackupRunner::preflight_refusal($need, 40 * $GiB, 17 * $GiB) === '',
	'a streaming run on a disk with room is not refused, however big its archive');

$msg = BackupRunner::preflight_refusal(12 * $GiB, (int)(14.6 * $GiB), 0);
check(strpos($msg, 'needs about 15.4 GB on disk') !== false && strpos($msg, '14.6 GB is free') !== false,
	'the refusal names both figures', $msg);
$msg = BackupRunner::preflight_refusal($need, 500 * 1048576, (int)(16 * $GiB));
check(strpos($msg, '500 MB is free') !== false, 'a small free figure is worded in its own unit', $msg);
check(strpos($msg, 'about 16 GB last time') !== false && strpos($msg, 'streams to backup storage') !== false,
	'and says the archive itself does not land here', $msg);

section('What the rule reads');
$manifest = array('runs' => array(
	array('seq' => 0, 'level' => 0, 'artifacts' => array('files' => array('bytes' => 5000))),
	array('seq' => 1, 'level' => 1, 'artifacts' => array('files' => array('bytes' => 70))),
	array('seq' => 2, 'level' => 1, 'artifacts' => array('files' => array('bytes' => 90))),
));
check(BackupRunner::expected_bytes($manifest, 0) === 5000, 'a full expects the newest full');
check(BackupRunner::expected_bytes($manifest, 1) === 90, 'an incremental expects the newest run');
check(BackupRunner::expected_bytes(null, 0) === 0, 'no manifest expects nothing');

$work = sys_get_temp_dir() . '/jy_preflight_' . getmypid();
@mkdir($work, 0700, true);
harness_defer(function () use ($work) { exec('rm -rf ' . escapeshellarg($work)); });
file_put_contents($work . '/snar', str_repeat('s', 3000));
file_put_contents($work . '/manifest.json', str_repeat('m', 200));
check(BackupRunner::local_need(array(), $work . '/snar', $work . '/manifest.json') === BackupRunner::PREFLIGHT_LOCAL_OVERHEAD + 3200,
	'the local need is the snapshot plus the manifest plus the fixed overhead');
check(BackupRunner::local_need(array(), '', '') === BackupRunner::PREFLIGHT_LOCAL_OVERHEAD,
	'a standalone run needs only the overhead');
check(BackupRunner::local_need(array(), $work . '/missing', '') === BackupRunner::PREFLIGHT_LOCAL_OVERHEAD,
	'a missing file counts as nothing');

section('A refused chain run writes nothing');
$slug = 'preflight-' . getmypid();
$plan = BackupRunner::plan(array('profile' => 'manager', 'manager' => array(
	'bucket' => 'bkt',
	'credentials' => array('access_key' => 'K', 'secret_key' => 'S', 'region' => 'r', 'endpoint' => 'http://127.0.0.1:9'),
	'slug' => $slug, 'type' => 'project', 'mode' => 'chain',
	'delete_local_after_upload' => 0, 'keep_local_days' => 0, 'target_name' => 'nowhere',
)));
$plan['base_dir']   = $work;
$plan['output_dir'] = $work . '/manager';
$plan['free_bytes'] = 1;
@mkdir($plan['output_dir'], 0700, true);
$snar = $plan['output_dir'] . '/.jy_backup.snar';
$before = scandir($plan['output_dir']);

$history = new BackupHistory(NULL);
$history->set('bkh_type', 'project');
$history->set('bkh_outcome', 'running');
$history->set('bkh_slug', $slug);
$history->set('bkh_profile', 'manager');
$history->save();
harness_register_row('bkh_backup_history', 'bkh_backup_history_id', $history->key);

$execute = new ReflectionMethod('BackupRunner', 'execute_chain');
$execute->setAccessible(true);
$error = null;
try {
	$execute->invoke(null, $plan, $history);
} catch (BackupRunnerException $e) {
	$error = $e->getMessage();
}
check($error !== null && strpos($error, 'Not started: this run needs about') !== false && strpos($error, '1 B is free') !== false,
	'the run throws the refusal, naming both figures', (string)$error);
check(scandir($plan['output_dir']) === $before, 'nothing was written: no chain directory, no snapshot, no key file',
	implode(',', scandir($plan['output_dir'])));

// The throw is what run() records through fail(); the recording itself is
// pinned in backup_failure_recording_test.php.
$fail = new ReflectionMethod('BackupRunner', 'fail');
$fail->setAccessible(true);
$fail->invoke(null, $history, $error);
$reread = new BackupHistory((int)$history->key, TRUE);
check($reread->get('bkh_outcome') === 'failed' && strpos((string)$reread->get('bkh_message'), 'is free') !== false,
	'recorded, it is a failed run whose message is the refusal');

harness_finish();
