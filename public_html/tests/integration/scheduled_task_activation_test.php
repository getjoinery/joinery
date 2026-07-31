<?php
/** @joinery-test
 * name: scheduled_task_activation
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * activate_on_install — tasks a subsystem cannot function without now create
 * their own rows (specs/scheduled_task_consolidation.md § Part 3).
 *
 * The whole feature is one JSON key and one safety rule, and the safety rule is
 * what this test exists for:
 *
 *   A task row is created ONLY when no row exists for that class at all,
 *   INCLUDING soft-deleted ones.
 *
 * Deactivating a task in admin soft-deletes its row. If auto-activation ignored
 * soft-deleted rows, an operator who deliberately turned a task off would get it
 * back on the next upgrade or plugin toggle, with no way to make the removal
 * stick — the platform overruling a deliberate decision, silently, forever.
 *
 * Run: php tests/integration/scheduled_task_activation_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/ScheduledTaskRegistry.php'));
require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));

$db = DbConnector::get_instance()->get_db_link();

// Work against a real declared core task so the test exercises the same
// discovery path production does. RetentionSweep is flagged and core-owned.
$subject = 'RetentionSweep';

$row_count = function ($include_deleted) use ($db, $subject) {
	$sql = "SELECT count(*) FROM sct_scheduled_tasks WHERE sct_task_class = ?";
	if (!$include_deleted) {
		$sql .= " AND sct_delete_time IS NULL";
	}
	$q = $db->prepare($sql);
	$q->execute(array($subject));
	return (int)$q->fetchColumn();
};
$wipe = function () use ($db, $subject) {
	$q = $db->prepare("DELETE FROM sct_scheduled_tasks WHERE sct_task_class = ?");
	$q->execute(array($subject));
};

// ---------------------------------------------------------------------------
section('A. A flagged task with no row is created');

$wipe();
check($row_count(true) === 0, 'starting from no row at all');

$activated = ScheduledTaskRegistry::activateDeclared('core');
check(in_array('Retention Sweep', $activated, true),
	'activateDeclared reports what it created', json_encode($activated));
check($row_count(false) === 1, 'exactly one row exists');

$q = $db->prepare("SELECT sct_is_active, sct_frequency, sct_schedule_time, sct_plugin_name
	FROM sct_scheduled_tasks WHERE sct_task_class = ?");
$q->execute(array($subject));
$row = $q->fetch(PDO::FETCH_ASSOC);
check((bool)$row['sct_is_active'] === true, 'the created row is active');
check($row['sct_frequency'] === 'daily', 'it took the declared frequency', $row['sct_frequency']);
check(substr((string)$row['sct_schedule_time'], 0, 5) === '03:00',
	'it took the declared time', (string)$row['sct_schedule_time']);
check($row['sct_plugin_name'] === null, 'a core task records no owning plugin');

// ---------------------------------------------------------------------------
section('B. A flagged task with an active row is not duplicated');

ScheduledTaskRegistry::activateDeclared('core');
ScheduledTaskRegistry::activateDeclared('core');
check($row_count(false) === 1, 'repeat runs create nothing — the call is idempotent');

// ---------------------------------------------------------------------------
section('C. A soft-deleted row is NOT recreated');

// This is the operator's off switch. Deactivating in admin soft-deletes the
// row; the next upgrade must respect that rather than undo it.
$db->prepare("UPDATE sct_scheduled_tasks SET sct_delete_time = now() WHERE sct_task_class = ?")
	->execute(array($subject));
check($row_count(false) === 0 && $row_count(true) === 1,
	'the row is soft-deleted, as a Deactivate click leaves it');

$activated = ScheduledTaskRegistry::activateDeclared('core');
check(!in_array('Retention Sweep', $activated, true),
	'activateDeclared does not claim to have activated it', json_encode($activated));
check($row_count(true) === 1,
	'a deliberate removal sticks — no new row appears beside the soft-deleted one');

// ---------------------------------------------------------------------------
section('D. An unflagged task is never auto-created');

// DriveUsageReconcile is deliberately opt-in: a drift backstop, not something
// correctness depends on. Nothing should create it on the operator's behalf.
$db->prepare("DELETE FROM sct_scheduled_tasks WHERE sct_task_class = 'DriveUsageReconcile'")->execute();
ScheduledTaskRegistry::activateDeclared('core');
$q = $db->query("SELECT count(*) FROM sct_scheduled_tasks WHERE sct_task_class = 'DriveUsageReconcile'");
check((int)$q->fetchColumn() === 0, 'an opt-in task stays opt-in');

// ---------------------------------------------------------------------------
section('E. Plugin scope only activates that plugin');

$discovered = ScheduledTaskRegistry::discover();
$mailbox_flagged = array();
foreach ($discovered as $class => $info) {
	if (($info['source'] ?? '') === 'plugin:mailbox' && !empty($info['json']['activate_on_install'])) {
		$mailbox_flagged[] = $class;
	}
}
check(count($mailbox_flagged) > 0, 'mailbox declares flagged tasks to scope against',
	json_encode($mailbox_flagged));

$core_flagged_names = array();
foreach ($discovered as $class => $info) {
	if (($info['source'] ?? '') === 'core' && !empty($info['json']['activate_on_install'])) {
		$core_flagged_names[] = $info['json']['name'] ?? $class;
	}
}
$from_mailbox = ScheduledTaskRegistry::activateDeclared('mailbox');
$leaked = array_intersect($from_mailbox, $core_flagged_names);
check(empty($leaked), 'activating a plugin scope never touches core tasks', json_encode($leaked));

// Restore a working state: the platform expects this task active.
$wipe();
ScheduledTaskRegistry::activateDeclared('core');
check($row_count(false) === 1, 'the sweep is left activated for the rest of the estate');

harness_finish();
