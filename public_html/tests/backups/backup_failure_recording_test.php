<?php
/** @joinery-test
 * name: backup_failure_recording
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * A backup that fails records that it failed, even when the failure took the
 * database connection with it (specs/disk_headroom_and_unit_diagnosis.md B1).
 *
 * The disk that filled on 2026-09-22 took PostgreSQL down mid-run; the run's
 * failure save went through the dead connection, threw, and the row stayed
 * `running` — the one state SiteBackupNotice keeps quiet about. fail() now
 * reconnects once and retries. The stale-row backstop (a process that writes
 * nothing at all) is pinned in site_backup_notice_test.php.
 *
 * Run: php tests/backups/backup_failure_recording_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

$new_running_row = function () {
	$h = new BackupHistory(NULL);
	$h->set('bkh_type', 'project');
	$h->set('bkh_outcome', 'running');
	$h->set('bkh_slug', 'harness-fail-record');
	$h->set('bkh_profile', 'site');
	$h->save();
	$id = (int)$h->key;
	harness_defer(function () use ($id) {
		$r = new BackupHistory($id, TRUE);
		$r->permanent_delete();
	});
	return $h;
};

$fail = new ReflectionMethod('BackupRunner', 'fail');
$fail->setAccessible(true);

// Kill this process's own backend, the way a PostgreSQL restart does. The
// statement that does it cannot return, so its error is the expected outcome.
$kill_connection = function () {
	try {
		DbConnector::get_instance()->get_db_link()->query('SELECT pg_terminate_backend(pg_backend_pid())');
	} catch (\Throwable $e) {
		// expected: the server hung up
	}
};

section('A failure on a live connection is recorded');
$h = $new_running_row();
$fail->invoke(null, $h, 'first failure');
$reread = new BackupHistory((int)$h->key, TRUE);
check($reread->get('bkh_outcome') === 'failed', 'the row reads failed');
check($reread->get('bkh_message') === 'first failure', 'with the message');

section('A failure whose connection died is still recorded');
$h = $new_running_row();
$kill_connection();
$dead = false;
try {
	DbConnector::get_instance()->get_db_link()->query('SELECT 1');
} catch (\Throwable $e) {
	$dead = true;
}
check($dead, 'the connection is really dead before fail() runs');
$fail->invoke(null, $h, 'No space left on device');
$reread = new BackupHistory((int)$h->key, TRUE);
check($reread->get('bkh_outcome') === 'failed', 'fail() reconnected and recorded the failure',
	'outcome ' . var_export($reread->get('bkh_outcome'), true));
check($reread->get('bkh_message') === 'No space left on device', 'with the message');
check((string)$reread->get('bkh_finish_time') !== '', 'and a finish time');

section('reconnect() stays in the mode it was in');
$db = DbConnector::get_instance();
$name_before = $db->get_db_link()->query('SELECT current_database()')->fetchColumn();
$db->reconnect();
$name_after = $db->get_db_link()->query('SELECT current_database()')->fetchColumn();
check($name_before === $name_after, 'the same database after a reconnect', $name_before . ' / ' . $name_after);

harness_finish();
