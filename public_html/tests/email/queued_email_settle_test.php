<?php
/** @joinery-test
 * name: queued_email_settle
 * tier: db
 * env: any
 * needs: []
 * covers: [data/queued_emails_class.php, tasks/SendQueuedEmails.php, includes/EmailSender.php]
 */
/**
 * A queued email always ends in a status the retry pass reads, and its address
 * columns hold any real address.
 *
 *  - B2: a send that throws (here a From address the message refuses) leaves
 *    the row at Error sending, never Locked, where nothing ever looks again;
 *    the exception still reaches the caller
 *  - B1: equ_to and equ_from hold a 254-character address (they held 64, so a
 *    failed send to a longer address was never queued for its retry)
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$run = getmypid() . '-' . random_int(1000, 9999);

section('A send that throws leaves the row at Error sending (B2)');
$q = new QueuedEmail(NULL);
$q->set('equ_from', 'not-an-address');
$q->set('equ_from_name', 'Settle test');
$q->set('equ_to', 'settle-' . $run . '@example.com');
$q->set('equ_to_name', 'Settle test');
$q->set('equ_subject', 'Settle test ' . $run);
$q->set('equ_body', '<p>Settle test</p>');
$q->set('equ_status', QueuedEmail::READY_TO_SEND);
$q->set('equ_retry_count', 0);
SystemBase::server_initiated_write(function () use ($q) { $q->save(); });
$q->load();
harness_register_row('equ_queued_emails', 'equ_queued_email_id', $q->key);
$threw = '';
try {
	$q->send(false);
} catch (Throwable $e) {
	$threw = $e->getMessage();
}
$row = new QueuedEmail($q->key, TRUE);
check(strpos($threw, 'Invalid from email address') !== false, 'the refusal still reaches the caller', $threw);
check((int)$row->get('equ_status') === QueuedEmail::ERROR_SENDING,
	'the row is at Error sending, not Locked', 'status ' . $row->get('equ_status'));

section('The address columns hold a 254-character address (B1)');
$cols = DbConnector::get_instance()->get_db_link()->query(
	"SELECT column_name, character_maximum_length FROM information_schema.columns
	 WHERE table_name = 'equ_queued_emails' AND column_name IN ('equ_to', 'equ_from')")->fetchAll(PDO::FETCH_KEY_PAIR);
check((int)($cols['equ_to'] ?? 0) >= 254 && (int)($cols['equ_from'] ?? 0) >= 254,
	'equ_to and equ_from take 254 characters (run update_database if not)', json_encode($cols));
$long = str_repeat('a', 64) . '@' . str_repeat('b', 60) . '.' . str_repeat('c', 60) . '.example.com';
$l = new QueuedEmail(NULL);
$l->set('equ_from', $long);
$l->set('equ_from_name', 'Settle test');
$l->set('equ_to', 'settle-long-' . $run . '@example.com');
$l->set('equ_to_name', 'Settle test');
$l->set('equ_subject', 'Settle long ' . $run);
$l->set('equ_body', '<p>Settle test</p>');
$l->set('equ_status', QueuedEmail::DELETED);
SystemBase::server_initiated_write(function () use ($l) { $l->save(); });
$l->load();
harness_register_row('equ_queued_emails', 'equ_queued_email_id', $l->key);
check(strlen($long) > 64 && (string)(new QueuedEmail($l->key, TRUE))->get('equ_from') === $long,
	'a ' . strlen($long) . '-character address is stored whole');

harness_finish();
