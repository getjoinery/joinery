<?php
/** @joinery-test
 * name: backup_nightly
 * tier: test-db
 * env: any
 * needs: [test-db]
 */
/**
 * BackupNightly: the nightly task turns itself on exactly once, and only when
 * both halves of backup setup exist — a scheduled target and a PROVEN recovery
 * key. Anything less must leave the schedule alone: activating with an
 * unproven key would run backups that seal to a value nobody has demonstrated
 * they can open.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

$db = DbConnector::get_instance()->get_db_link();

function bn_put_setting($name, $value) {
	global $db;
	$q = $db->prepare('UPDATE stg_settings SET stg_value = ? WHERE stg_name = ?');
	$q->execute(array($value, $name));
	if ($q->rowCount() === 0) {
		$q = $db->prepare('INSERT INTO stg_settings (stg_name, stg_value, stg_group_name) VALUES (?, ?, ?)');
		$q->execute(array($name, $value, 'backups'));
	}
}

function bn_active_task_count() {
	$tasks = new MultiScheduledTask(array('task_class' => 'BackupRun', 'active' => true, 'deleted' => false));
	return $tasks->count_all();
}

// Start from nothing: no target, no key, no BackupRun task at all.
$db->prepare('DELETE FROM sct_scheduled_tasks WHERE sct_task_class = ?')->execute(array('BackupRun'));
bn_put_setting('backup_target_id', '0');
bn_put_setting('backup_recovery_public_key', '');
bn_put_setting('backup_recovery_public_key_proven_fpr', '');

// ── Refusals ────────────────────────────────────────────────────────────
section('Nothing activates until both halves exist');

check(BackupNightly::maybe_activate() === false, 'nothing configured: refuses');
check(bn_active_task_count() === 0, 'and no task row appears');

bn_put_setting('backup_target_id', '123');
check(BackupNightly::maybe_activate() === false, 'target alone: refuses');

$keypair = sodium_crypto_box_keypair();
$pub_raw = sodium_crypto_box_publickey($keypair);
bn_put_setting('backup_recovery_public_key', base64_encode($pub_raw));
check(BackupNightly::maybe_activate() === false, 'target + UNPROVEN key: refuses');
check(bn_active_task_count() === 0, 'still no task row');

// ── Activation ──────────────────────────────────────────────────────────
section('Both halves ready: activates once');

bn_put_setting('backup_recovery_public_key_proven_fpr', hash('sha256', $pub_raw));
check(BackupRecoveryKey::is_ready() === true, 'fixture key reads as proven');

check(BackupNightly::maybe_activate() === true, 'target + proven key: activates');
check(bn_active_task_count() === 1, 'exactly one active BackupRun task exists');

check(BackupNightly::maybe_activate() === false, 'already active: second call is a no-op');
check(bn_active_task_count() === 1, 'and does not add another task');

// An operator switching the task off is a decision maybe_activate must not
// override — only the explicit switch (activate) turns it back on.
$db->prepare('UPDATE sct_scheduled_tasks SET sct_is_active = false WHERE sct_task_class = ?')
	->execute(array('BackupRun'));
check(BackupNightly::maybe_activate() === true,
	'a completed-setup site with the task off gets it back on by maybe_activate');
// (Deliberate: maybe_activate is only called from setup-completing POSTs, so
// reaching here means the operator just finished setup — turning on is right.)
check(bn_active_task_count() === 1, 'reactivated the existing row rather than minting a second');
$count_q = $db->prepare('SELECT COUNT(*) FROM sct_scheduled_tasks WHERE sct_task_class = ?');
$count_q->execute(array('BackupRun'));
check((int)$count_q->fetchColumn() === 1, 'one BackupRun row total');

// ── Cleanup ─────────────────────────────────────────────────────────────
$db->prepare('DELETE FROM sct_scheduled_tasks WHERE sct_task_class = ?')->execute(array('BackupRun'));
bn_put_setting('backup_target_id', '0');
bn_put_setting('backup_recovery_public_key', '');
bn_put_setting('backup_recovery_public_key_proven_fpr', '');

harness_finish();
