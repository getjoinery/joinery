<?php
/** @joinery-test
 * name: backup_verify_site
 * tier: test-db
 * env: any
 * needs: [test-db]
 */
/**
 * The site's own side of backup verification: what the Backups page says
 * about "verified restorable", and what the scheduled task decides.
 *
 * In the test database, because every answer here is "the newest row of a
 * kind" and only an empty history can be reasoned about from a fixture:
 *
 *   * with no backups of its own the task says so and does nothing — a
 *     management node's backups are verified from that management node
 *   * the Status box picks the newest PASS as "last verified restorable",
 *     and shows a failure only when it is newer than that pass
 *   * the task is due on the interval, of a backup newer than the last verify
 *   * the Status and Recent backups blocks render from fixture rows without
 *     an error, and say "verified restorable" where the row was stamped
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

require_once(PathHelper::getIncludePath('includes/BackupVerifyLauncher.php'));
require_once(PathHelper::getIncludePath('includes/BackupVerifier.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_backups_logic.php'));
require_once(PathHelper::getIncludePath('tasks/BackupVerify.php'));
require_once(PathHelper::getIncludePath('data/backup_history_class.php'));

$db = DbConnector::get_instance()->get_db_link();
$db->exec('DELETE FROM bkh_backup_history');

// ── Nothing of its own ──────────────────────────────────────────────────────
section('A site with no backups of its own has nothing to verify');

$task = new BackupVerify();
$r = $task->run(array());
check($r['status'] === 'skipped', 'the task skips', $r['status']);
check(strpos($r['message'], 'takes no backups of its own') !== false
	&& strpos($r['message'], 'management node') !== false,
	'and says so in one sentence, naming who does verify them', $r['message']);
$r = $task->dryRun(array());
check($r['status'] === 'skipped' && strpos($r['message'], 'takes no backups of its own') !== false,
	'a dry run says the same');

$m = _admin_backups_milestones();
check($m['verified'] === null && $m['verify_failed'] === null, 'the Status box has nothing verified to show');

// ── Fixture rows ────────────────────────────────────────────────────────────
$mk = function (array $f) {
	$row = new BackupHistory(NULL);
	$row->set('bkh_type', 'project');
	$row->set('bkh_slug', 'testsite');
	$row->set('bkh_profile', $f['profile'] ?? 'site');
	$row->set('bkh_outcome', $f['outcome'] ?? 'success');
	$row->set('bkh_chain_id', $f['chain'] ?? 'chain-20260901_040000');
	$row->set('bkh_chain_seq', $f['seq'] ?? 0);
	$row->set('bkh_start_time', $f['start']);
	$row->set('bkh_finish_time', $f['start']);
	$row->set('bkh_upload_time', $f['start']);
	$row->set('bkh_target_name', 'Test shelf');
	$row->set_artifacts(array(array('kind' => 'files', 'name' => 'files.tar.gz.enc', 'bytes' => 12345, 'level' => (int)($f['seq'] ?? 0))));
	foreach (array('verify_time', 'verify_level', 'verify_outcome', 'verify_message') as $k) {
		if (isset($f[$k])) { $row->set('bkh_' . $k, $f[$k]); }
	}
	$row->save();
	return $row;
};

$r0 = $mk(array('seq' => 0, 'start' => '2026-09-01 04:00:00', 'verify_time' => '2026-09-02 10:00:00',
	'verify_level' => 2, 'verify_outcome' => 'pass',
	'verify_message' => 'Opened and read the backup of 2026-09-01 04:00 UTC: 3 archives, 1.2 MB, 40 files.'));
$r1 = $mk(array('seq' => 1, 'start' => '2026-09-03 04:00:00', 'verify_time' => '2026-09-01 12:00:00',
	'verify_level' => 2, 'verify_outcome' => 'fail', 'verify_message' => 'an OLDER failure than the pass'));
$r2 = $mk(array('seq' => 2, 'start' => '2026-09-05 04:00:00'));

section('The Status box: the newest pass, and a failure only when newer than it');

$m = _admin_backups_milestones();
check($m['verified'] !== null && (int)$m['verified']->key === (int)$r0->key, 'the run with the pass is "last verified restorable"');
check($m['verify_failed'] === null, 'a failure older than that pass is not shown beside it');

$r3 = $mk(array('seq' => 3, 'start' => '2026-09-07 04:00:00', 'verify_time' => '2026-09-08 10:00:00',
	'verify_level' => 3, 'verify_outcome' => 'fail',
	'verify_message' => 'Verification of the backup of 2026-09-07 04:00 UTC failed: files-0003.tar.gz.enc does not match its recorded hash.'));
$m = _admin_backups_milestones();
check((int)$m['verified']->key === (int)$r0->key, 'the pass still stands as the last proof');
check($m['verify_failed'] !== null && (int)$m['verify_failed']->key === (int)$r3->key,
	'and a failure newer than it is shown beside it');

$r4 = $mk(array('seq' => 4, 'start' => '2026-09-09 04:00:00', 'verify_time' => '2026-09-10 10:00:00',
	'verify_level' => 3, 'verify_outcome' => 'pass',
	'verify_message' => 'Rehearsed a restore of the backup of 2026-09-09 04:00 UTC: 5 archives, 2.1 MB, 44 files, 12 tables, 3 users.'));
$m = _admin_backups_milestones();
check((int)$m['verified']->key === (int)$r4->key && $m['verify_failed'] === null,
	'a newer pass takes over and the older failure stops being shown');

// ── The task's due rule ─────────────────────────────────────────────────────
section('The task verifies on the interval, of a backup newer than the last verify');

$d = BackupVerifyLauncher::due(30, '2026-09-13 12:00:00');
check($d['run'] !== null && (int)$d['run']->key === (int)$r4->key, 'the newest successful offsite run of the site\'s own is the candidate');
check(!$d['due'] && strpos($d['reason'], 'the one last verified') !== false,
	'nothing newer than the last verify: not due, saying so', $d['reason']);

$r5 = $mk(array('seq' => 5, 'start' => '2026-09-12 04:00:00'));
$d = BackupVerifyLauncher::due(30, '2026-09-13 12:00:00');
check(!$d['due'] && strpos($d['reason'], 'next verification is due on 2026-10-10') !== false,
	'a newer backup, but the last verify is inside the interval: not due, naming the date', $d['reason']);
$d = BackupVerifyLauncher::due(3, '2026-09-13 12:00:00');
check($d['due'] && (int)$d['run']->key === (int)$r5->key, 'past the interval with a newer backup: due, of the newest run');
$d = BackupVerifyLauncher::due(0, '2026-09-13 12:00:00');
check(!$d['due'] && strpos($d['reason'], 'switched off') !== false, '0 is never');

$r = $task->dryRun(array());
check($r['status'] === 'skipped' && strpos($r['message'], 'the backup of 2026-09-12 04:00 UTC') !== false
	&& strpos($r['message'], '12.1 KB') !== false,
	'a dry run names the run it would open and how big it is', $r['message']);

// A never-verified history is due at once, however new the backup: the
// backup runs daily too, so a "wait until it is a day old" rule would be
// beaten by the next morning's run every day and the first verify never come.
$db->exec('UPDATE bkh_backup_history SET bkh_verify_time = NULL, bkh_verify_outcome = NULL, bkh_verify_level = NULL, bkh_verify_message = NULL');
$d = BackupVerifyLauncher::due(30, '2026-09-12 06:00:00');
check($d['due'] && $d['reason'] === 'never verified' && (int)$d['run']->key === (int)$r5->key,
	'never verified, newest backup two hours old: due, of that backup', $d['reason']);
$d = BackupVerifyLauncher::due(30, '2026-09-13 12:00:00');
check($d['due'] && $d['reason'] === 'never verified', 'never verified, backup a day old: due');

// ── The background launch delivers the request ──────────────────────────────
section('A verify started from the page receives its request on stdin');

// The request carries signed links and crosses on stdin only. A job bash puts
// in the background gets /dev/null as stdin unless handed a descriptor
// explicitly, in which case the verify reads nothing and refuses — and both
// page buttons are silent no-ops. So: a script that writes what it read.
$scratch = harness_scratch_dir('verify-detach');
$probe   = $scratch . '/echo_stdin.php';
$landed  = $scratch . '/stdin.txt';
@unlink($landed);   // the scratch directory outlives a run; a stale file here would be read as the answer
file_put_contents($probe, '<?php file_put_contents(' . var_export($landed, true) . ', stream_get_contents(STDIN));' . "\n");
$payload = json_encode(array('chain_id' => 'chain-20260912_040000', 'level' => 2, 'artifact_urls' => array('files-0000.tar.gz.enc' => 'https://shelf.invalid/x?X-Amz-Signature=test')));
BackupVerifyLauncher::detach($probe, $payload);
$got = null;
for ($i = 0; $i < 100 && $got === null; $i++) {
	usleep(100000);
	if (is_file($landed)) { $got = (string)file_get_contents($landed); }
}
check($got === $payload, 'the detached script read the whole request from stdin',
	$got === null ? 'nothing was written within 10 s' : 'read ' . strlen($got) . ' bytes');
$big = str_repeat('x', 200000);
@unlink($landed);
BackupVerifyLauncher::detach($probe, $big);
$got = null;
for ($i = 0; $i < 100 && $got === null; $i++) {
	usleep(100000);
	if (is_file($landed) && filesize($landed) >= strlen($big)) { $got = (string)file_get_contents($landed); }
}
check($got === $big, 'a request larger than a pipe buffer arrives whole (the launcher blocks until the script has read it)');

// ── The page renders from fixture rows ──────────────────────────────────────
section('The Status and Recent backups blocks render, and say verified restorable');

$db->exec("UPDATE bkh_backup_history SET bkh_verify_time = '2026-09-10 10:00:00', bkh_verify_level = 3, bkh_verify_outcome = 'pass',"
	. " bkh_verify_message = 'Rehearsed a restore of the backup of 2026-09-09 04:00 UTC: 5 archives, 2.1 MB, 44 files, 12 tables, 3 users.'"
	. " WHERE bkh_id = " . (int)$r4->key);
$db->exec("UPDATE bkh_backup_history SET bkh_verify_time = '2026-09-12 10:00:00', bkh_verify_level = 2, bkh_verify_outcome = 'fail',"
	. " bkh_verify_message = 'Verification of the backup of 2026-09-12 04:00 UTC failed: db-0005.sql.gz.enc could not be decrypted.'"
	. " WHERE bkh_id = " . (int)$r5->key);

/** The page's box API, with FormWriter as the page would hand it out. */
class BvsStubPage {
	public function begin_box($o = null) { echo '<div class="box">'; }
	public function end_box($o = null) { echo '</div>'; }
	public function getFormWriter($id = 'form1', $o = array()) { return new FormWriterV2HTML5($id); }
}

$source = file_get_contents(PathHelper::getIncludePath('adm/admin_backups.php'));
$status_start = strpos($source, "// ── Status ──");
$status_end   = strpos($source, "// ── Recovery key ──", $status_start);
$recent_start = strpos($source, "// ── Recent backups ──");
$recent_end   = strpos($source, '$page->admin_footer();', $recent_start);
check($status_start !== false && $status_end !== false && $recent_start !== false && $recent_end !== false,
	'the two blocks are where the page keeps them');

// The page's variables, assembled the way the logic assembles them but
// without its session gate (there is no browser here).
$history = new MultiBackupHistory(array('include_pruned' => true), array('bkh_start_time' => 'DESC'), 30, 0);
$history->load();
$milestones = _admin_backups_milestones();
$ceremony = _admin_backups_ceremony_time();
$verify_every = _admin_backups_verify_every_days();
$recovery = BackupRecoveryKey::setup_state();
$plan = null; $plan_problem = '';
try { $plan = BackupRunner::plan(); } catch (Exception $e) { $plan_problem = $e->getMessage(); }
$task = _admin_backups_task_state();
$is_managed = false; $manager_url = '';
$page = new BvsStubPage();
$tz = 'UTC';
$when = function ($utc) use ($tz) {
	return $utc ? LibraryFunctions::convert_time($utc, 'UTC', $tz, 'M j, Y g:i A T') : '—';
};

$rendered = '';
$threw = null;
try {
	ob_start();
	eval('?>' . '<?php ' . substr($source, $status_start, $status_end - $status_start));
	eval('?>' . '<?php ' . substr($source, $recent_start, $recent_end - $recent_start));
	$rendered = ob_get_clean();
} catch (\Throwable $e) {
	ob_end_clean();
	$threw = $e;
}
check($threw === null, 'both blocks render without an error', $threw ? $threw->getMessage() . ' at ' . $threw->getFile() . ':' . $threw->getLine() : '');
check(strpos($rendered, 'Last verified restorable') !== false, 'the Status box has its fourth row');
check(strpos($rendered, 'Sep 10, 2026 10:00 AM UTC') !== false && strpos($rendered, 'rehearsed') !== false,
	'dated and named by level');
check(strpos($rendered, 'Verification failed Sep 12, 2026 10:00 AM UTC') !== false && strpos($rendered, 'db-0005.sql.gz.enc') !== false,
	'a failure newer than the pass is shown in red with its reason');
check(strpos($rendered, 'recovery key was last proven') !== false, 'the ceremony date sits beside it');
check(substr_count($rendered, 'verified restorable &middot;') === 1, 'Recent backups says verified restorable on the stamped run, and only there',
	(string)substr_count($rendered, 'verified restorable &middot;'));
check(strpos($rendered, 'verification failed &middot; Sep 12, 2026 10:00 AM UTC') !== false,
	'and verification failed on the one that failed');
foreach (array('chain-2026', 'restore point', ' seq ') as $jargon) {
	check(stripos($rendered, $jargon) === false, 'nobody reads "' . trim($jargon) . '" on the page');
}

// A verify that proved nothing either way — skipped for disk, or refused
// before it read anything — stamps the message only. The button's flash
// promised a result; this is where it shows.
section('A verify that skipped or could not start still shows on the page');

$r6 = $mk(array('seq' => 6, 'start' => '2026-09-13 04:00:00',
	'verify_message' => 'Could not verify the backup of 2026-09-13 04:00 UTC: needs 2.4 GB free, has 858.3 MB.'));
$m = _admin_backups_milestones();
check($m['verify_attempt'] !== null && (int)$m['verify_attempt']->key === (int)$r6->key,
	'the attempt on the newest run is a milestone of its own', $m['verify_attempt'] ? 'row ' . $m['verify_attempt']->key : 'null');
check((int)$m['verified']->key === (int)$r4->key, 'and the last pass still stands');

$history = new MultiBackupHistory(array('include_pruned' => true), array('bkh_start_time' => 'DESC'), 30, 0);
$history->load();
$milestones = $m;
$rendered = '';
$threw = null;
try {
	ob_start();
	eval('?>' . '<?php ' . substr($source, $status_start, $status_end - $status_start));
	eval('?>' . '<?php ' . substr($source, $recent_start, $recent_end - $recent_start));
	$rendered = ob_get_clean();
} catch (\Throwable $e) {
	ob_end_clean();
	$threw = $e;
}
check($threw === null, 'both blocks render', $threw ? $threw->getMessage() : '');
check(strpos($rendered, 'Last attempt: Could not verify the backup of 2026-09-13 04:00 UTC: needs 2.4 GB free, has 858.3 MB.') !== false,
	'the Status box says what became of the last attempt');
check(strpos($rendered, 'not verified &middot; Could not verify the backup of 2026-09-13 04:00 UTC') !== false,
	'and the run\'s row says not verified, with the reason');

// The same skip landing on a run already proven: the proof stands, the skip rides beside it.
$db->exec("UPDATE bkh_backup_history SET bkh_verify_message = 'Could not verify the backup of 2026-09-09 04:00 UTC: busy: another backup was running'"
	. " WHERE bkh_id = " . (int)$r4->key);
$db->exec('DELETE FROM bkh_backup_history WHERE bkh_id = ' . (int)$r6->key);
$history = new MultiBackupHistory(array('include_pruned' => true), array('bkh_start_time' => 'DESC'), 30, 0);
$history->load();
$milestones = _admin_backups_milestones();
ob_start();
eval('?>' . '<?php ' . substr($source, $status_start, $status_end - $status_start));
eval('?>' . '<?php ' . substr($source, $recent_start, $recent_end - $recent_start));
$rendered = ob_get_clean();
check(substr_count($rendered, 'verified restorable &middot;') === 1 && strpos($rendered, 'since then: Could not verify the backup of 2026-09-09 04:00 UTC: busy') !== false,
	'a skip on a proven run keeps "verified restorable" and adds "since then" with the reason');
check(strpos($rendered, 'Last attempt: Could not verify the backup of 2026-09-09') !== false,
	'the Status box shows it too, since it is about the backup last proven');

$db->exec('DELETE FROM bkh_backup_history');
harness_finish();
