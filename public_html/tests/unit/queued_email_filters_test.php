<?php
/** @joinery-test
 * name: queued_email_filters
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */

/**
 * MultiQueuedEmail filter formats produce valid SQL. Regression for the
 * SendQueuedEmails crash: multi_status once built the string-condition value
 * with the column name repeated inside parentheses, yielding
 * "equ_status (equ_status = 6 OR ...)" — an undefined-function SQL error that
 * killed the email-queue drain on every site that activated the task.
 * Read-only: count_all() executes the generated SQL without touching rows.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/queued_emails_class.php'));

section('multi_status filter generates executable SQL');

$failed = new MultiQueuedEmail(array(
	'multi_status' => array(QueuedEmail::ERROR_SENDING, QueuedEmail::PERMANENT_FAILURE)));
$count = $failed->count_all();
check(is_numeric($count) && $count >= 0, 'two-status filter executes', var_export($count, true));

$single = new MultiQueuedEmail(array('multi_status' => array(QueuedEmail::READY_TO_SEND)));
$count = $single->count_all();
check(is_numeric($count) && $count >= 0, 'single-status filter executes', var_export($count, true));

// Non-numeric input must not reach the SQL (values are cast to int).
$hostile = new MultiQueuedEmail(array('multi_status' => array('7; DROP TABLE x', QueuedEmail::SENT)));
$count = $hostile->count_all();
check(is_numeric($count) && $count >= 0, 'non-numeric statuses are neutralized by int cast');

section('The Outbox page filters generate executable SQL');
$outbox = new MultiQueuedEmail(array('address' => "o'brien@example.com", 'since' => '2026-01-01 00:00:00',
	'until' => '2026-02-01 00:00:00', 'equ_template' => 'x', 'equ_entity_type' => 'usr_users', 'equ_entity_id' => 1));
$count = $outbox->count_all();
check(is_numeric($count) && $count >= 0, 'address, date, template and record filters execute together', var_export($count, true));

harness_finish();
