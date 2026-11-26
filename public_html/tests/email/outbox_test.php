<?php
/** @joinery-test
 * name: outbox
 * tier: db
 * env: any
 * needs: []
 * covers: [includes/EmailSender.php, includes/EmailMessage.php, data/queued_emails_class.php, tasks/SendQueuedEmails.php, migrations/email_outbox_existing_rows.php]
 */
/**
 * The outbox (specs/email_outbox.md): one row for every email the platform
 * sends on its own behalf, its content only while it may still be needed, and
 * send-once keys on that row.
 *
 * Every send here goes to EmailSender::$capture, never to a provider; test
 * mode is switched off only where a check needs the real recipients, with the
 * capture still in place.
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$db = DbConnector::get_instance()->get_db_link();
$run = getmypid() . '-' . random_int(1000, 9999);
$trap = (string)EmailSender::testModeTrap();

$captured = array();
$capture_ok = function ($template, $to, $vars, $message) use (&$captured) {
	$captured[] = array('template' => $template, 'to' => $to, 'vars' => $vars, 'message' => $message);
	return true;
};
$capture_fail = function ($template, $to, $vars, $message) use (&$captured) {
	$captured[] = array('template' => $template, 'to' => $to, 'vars' => $vars, 'message' => $message);
	return false;
};
EmailSender::$capture = $capture_ok;
harness_defer(function () { EmailSender::$capture = null; });

/** The outbox rows whose subject carries $marker, oldest first. */
$rows = function (string $marker) use ($db): array {
	$q = $db->prepare('SELECT * FROM equ_queued_emails WHERE equ_subject LIKE ? ORDER BY equ_queued_email_id');
	$q->execute(array('%' . $marker . '%'));
	return $q->fetchAll(PDO::FETCH_ASSOC);
};
$row = function (int $id) use ($db): array {
	$q = $db->prepare('SELECT * FROM equ_queued_emails WHERE equ_queued_email_id = ?');
	$q->execute(array($id));
	return $q->fetch(PDO::FETCH_ASSOC) ?: array();
};
$msg = function (string $subject, $to = null) use ($run) {
	return EmailMessage::create($to ?? ('outbox-' . $run . '@example.com'), $subject, '<p>Outbox test ' . $run . '</p>');
};
$retry = function (int $id) {
	$q = new QueuedEmail($id, TRUE);
	try {
		return $q->send(false);
	} catch (Throwable $e) {
		return false;
	}
};
$user = make_user('Outbox');

try {
	// ------------------------------------------------------------------
	section('An ordinary send: one row, no content at any point, ended Sent');
	$seen_inside = null;
	EmailSender::$capture = function ($t, $to, $v, $m) use (&$seen_inside, &$captured, $db) {
		$captured[] = array('template' => $t, 'to' => $to, 'vars' => $v, 'message' => $m);
		$q = $db->query("SELECT equ_status, equ_body, equ_message_extras FROM equ_queued_emails ORDER BY equ_queued_email_id DESC LIMIT 1");
		$seen_inside = $q->fetch(PDO::FETCH_ASSOC);
		return true;
	};
	$m = EmailMessage::fromTemplate('blank_template', array('subject' => 'Ordinary ' . $run, 'body' => '<p>x</p>'));
	$m->subject('Ordinary ' . $run)->to('ordinary-' . $run . '@example.com')->about($user);
	check((new EmailSender())->send($m) === true, 'the send reports success');
	$r = $rows('Ordinary ' . $run);
	check(count($r) === 1, 'one row', count($r));
	check($seen_inside && (int)$seen_inside['equ_status'] === QueuedEmail::LOCKED && $seen_inside['equ_body'] === null
		&& $seen_inside['equ_message_extras'] === null, 'while it was being sent the row was Locked with no body or extras');
	$r = $r[0] ?? array();
	check((int)$r['equ_status'] === QueuedEmail::SENT && $r['equ_body'] === null && $r['equ_message_extras'] === null,
		'afterwards it is Sent, still with no content');
	check($r['equ_template'] === 'blank_template' && $r['equ_entity_type'] === 'usr_users' && (int)$r['equ_entity_id'] === (int)$user->key
		&& $r['equ_sent_time'] !== null && $r['equ_service'] === 'capture' && $r['equ_locked_time'] !== null,
		'with its template, record, sent time, service and lock time');
	check(strpos((string)$r['equ_recipients'], $trap) !== false && $r['equ_to'] === $trap,
		'in test mode the row is the trap\'s (R39)');
	EmailSender::$capture = $capture_ok;

	section('Three To and one Cc are one row, and a retry sends the Cc once (R40)');
	harness_set_setting_mem('email_test_mode', '0');
	$m = $msg('Cc row ' . $run, array('a-' . $run . '@example.com', 'b-' . $run . '@example.com', 'c-' . $run . '@example.com'));
	$m->cc('cc-' . $run . '@example.com');
	EmailSender::$capture = $capture_fail;
	(new EmailSender())->send($m);
	$r = $rows('Cc row ' . $run);
	$rec = json_decode((string)($r[0]['equ_recipients'] ?? ''), true);
	check(count($r) === 1 && count($rec['to'] ?? array()) === 3 && count($rec['cc'] ?? array()) === 1
		&& $r[0]['equ_to'] === 'a-' . $run . '@example.com', 'one row with every address whole', json_encode($rec));
	check((int)$r[0]['equ_status'] === QueuedEmail::ERROR_SENDING && $r[0]['equ_body'] !== null && $r[0]['equ_message_extras'] !== null,
		'the failure (no fallback configured here) waits for its retry, and only now holds its content (B3, R47)');
	EmailSender::$capture = $capture_ok;
	$captured = array();
	check($retry((int)$r[0]['equ_queued_email_id']) === true, 'the task\'s retry sends it');
	$sent = $captured[0]['message'] ?? null;
	check($sent && count($sent->getRecipients()) === 3 && count($sent->getCc()) === 1 && count($captured) === 1,
		'once, to the three, with the Cc');
	$after = $row((int)$r[0]['equ_queued_email_id']);
	check((int)$after['equ_status'] === QueuedEmail::SENT && $after['equ_body'] === null && $after['equ_message_extras'] === null
		&& count($rows('Cc row ' . $run)) === 1, 'the same row is Sent, its content cleared, and no second row was written (R50)');
	harness_set_setting_mem('email_test_mode', '1');

	section('A subject is stored whole (R48)');
	$long = 'Long ' . $run . ' ' . str_repeat('s', 300);
	(new EmailSender())->send($msg($long));
	$r = $rows('Long ' . $run);
	check(count($r) === 1 && strpos($r[0]['equ_subject'], $long) !== false && mb_strlen($r[0]['equ_subject']) > 300,
		'a 300-character subject and its test-mode prefix', mb_strlen((string)($r[0]['equ_subject'] ?? '')));

	section('What writes no row');
	try { (new EmailSender())->send(EmailMessage::create('not-an-address', 'Invalid ' . $run, '<p>x</p>')); } catch (Throwable $e) {}
	check(count($rows('Invalid ' . $run)) === 0, 'an invalid message');
	harness_set_setting_mem('email_dry_run', '1');
	check((new EmailSender())->send($msg('Dry ' . $run)) === true && count($rows('Dry ' . $run)) === 0, 'a dry run');
	harness_set_setting_mem('email_dry_run', '0');
	harness_set_setting_mem('email_test_recipient', '   '); // blank once trimmed
	check((new EmailSender())->send($msg('Empty trap ' . $run)) === true && count($rows('Empty trap ' . $run)) === 0,
		'test mode with an empty trap');
	harness_set_setting_mem('email_test_recipient', $trap);
	$m = $msg('Trap cc ' . $run);
	$m->cc('trapcc-' . $run . '@example.com');
	(new EmailSender())->send($m);
	$r = $rows('Trap cc ' . $run);
	$rec = json_decode((string)($r[0]['equ_recipients'] ?? ''), true);
	check(count($r) === 1 && ($rec['cc'] ?? null) === array(), 'test mode writes one row, to the trap, with no Cc (R39)');
	$captured = array();
	$mail = new EmailMessage();
	$mail->to('inj-' . $run . '@example.com')->subject('Injected ' . $run)->text('x');
	$inj = new class implements EmailServiceProvider {
		public static function getKey(): string { return 'outbox_test_transport'; }
		public static function getLabel(): string { return 'Outbox test transport'; }
		public static function validateConfiguration(): array { return array('valid' => true, 'errors' => array()); }
		public function send(EmailMessage $m): bool { return false; }
		public function sendBatch(EmailMessage $m, array $r): array { return array('success' => false, 'failed_recipients' => $r); }
		public static function getSettingsFields(): array { return array(); }
		public static function getSpfMechanism(string $domain): string { return ''; }
	};
	EmailSender::$capture = null;
	try {
		(new EmailSender())->send($mail, true, $inj);
	} catch (Throwable $e) {
		check(false, 'the injected send ran', $e->getMessage());
	}
	EmailSender::$capture = $capture_ok;
	check(count($rows('Injected ' . $run)) === 0, 'an injected transport writes no row and queues nothing, even when it fails');

	section('The stored message is the whole message (B6, R18)');
	harness_set_setting_mem('email_test_mode', '0');
	harness_set_setting_mem('defaultreplyto', 'replies-' . $run . '@example.com');
	$m = new EmailMessage();
	$m->to('whole-' . $run . '@example.com')->cc('whole-cc-' . $run . '@example.com')->bcc('whole-bcc-' . $run . '@example.com')
		->subject('Whole ' . $run)->html('<p>html part</p>')->text('text part')->header('X-Outbox-Test', 'kept')
		->messageId('<whole-' . $run . '@example.com>')->attachData('attached bytes', 'note.txt', 'text/plain');
	EmailSender::$capture = $capture_fail;
	(new EmailSender())->send($m);
	EmailSender::$capture = $capture_ok;
	$captured = array();
	$r = $rows('Whole ' . $run);
	$retry((int)$r[0]['equ_queued_email_id']);
	$sent = $captured[0]['message'] ?? null;
	$att = $sent ? $sent->getAttachments() : array();
	check($sent && count($sent->getCc()) === 1 && count($sent->getBcc()) === 1 && $sent->getTextBody() === 'text part'
		&& $sent->getHtmlBody() === '<p>html part</p>' && ($sent->getHeaders()['X-Outbox-Test'] ?? '') === 'kept'
		&& $sent->getMessageId() === '<whole-' . $run . '@example.com>'
		&& count($att) === 1 && $att[0]['data'] === 'attached bytes' && $att[0]['name'] === 'note.txt',
		'the retry carries Cc, Bcc, both parts, the header, the message id and the attachment');
	check($sent && $sent->getReplyTo() === 'replies-' . $run . '@example.com', 'and the site\'s Reply-To, stamped again (R18)');
	harness_set_setting_mem('defaultreplyto', '');
	harness_set_setting_mem('email_test_mode', '1');

	section('A message that cannot be stored is still sent; only its retry is lost (R38)');
	$big = $msg('Big ' . $run);
	$big->attachData(random_bytes(1100000), 'big.bin');
	check((new EmailSender())->send($big) === true && (int)($rows('Big ' . $run)[0]['equ_status'] ?? 0) === QueuedEmail::SENT,
		'one over the cap is sent and recorded');
	EmailSender::$capture = $capture_fail;
	$big = $msg('Big fail ' . $run);
	$big->attachData(random_bytes(1100000), 'big.bin');
	(new EmailSender())->send($big);
	$r = $rows('Big fail ' . $run)[0] ?? array();
	check((int)($r['equ_status'] ?? 0) === QueuedEmail::PERMANENT_FAILURE && strpos((string)$r['equ_error'], 'MiB') !== false,
		'its failure is Permanent failure, saying why', (string)($r['equ_error'] ?? ''));
	$dir = harness_scratch_dir('outbox');
	$path = $dir . '/gone.txt';
	file_put_contents($path, 'soon gone');
	$gone = $msg('Gone file ' . $run);
	$gone->attach($path);
	EmailSender::$capture = function ($t, $to, $v, $m) use ($path) { @unlink($path); return false; };
	(new EmailSender())->send($gone);
	check((int)($rows('Gone file ' . $run)[0]['equ_status'] ?? 0) === QueuedEmail::PERMANENT_FAILURE,
		'an attached file that cannot be read: Permanent failure');
	EmailSender::$capture = $capture_fail;
	$nul = $msg('Nul ' . $run);
	$nul->metadata('note', "a\0b");
	(new EmailSender())->send($nul);
	check((int)($rows('Nul ' . $run)[0]['equ_status'] ?? 0) === QueuedEmail::PERMANENT_FAILURE, 'a NUL in the message: Permanent failure');
	$threw = false;
	try { $q = $msg('Queue nul ' . $run); $q->metadata('note', "a\0b"); (new EmailSender())->queue($q); } catch (Throwable $e) { $threw = true; }
	check($threw && count($rows('Queue nul ' . $run)) === 0, 'queue() throws for one, writing nothing (R42)');
	(new EmailSender())->send($msg('No retry ' . $run), false);
	check((int)($rows('No retry ' . $run)[0]['equ_status'] ?? 0) === QueuedEmail::PERMANENT_FAILURE,
		'a caller that allows no retry: Permanent failure (R19)');

	section('A send that succeeds never reads its attachments again (N5)');
	$path2 = $dir . '/read-once.txt';
	file_put_contents($path2, 'read once');
	$once_read = $msg('Read once ' . $run);
	$once_read->attach($path2);
	EmailSender::$capture = function ($t, $to, $v, $m) use ($path2) { @unlink($path2); return true; };
	(new EmailSender())->send($once_read);
	check((int)($rows('Read once ' . $run)[0]['equ_status'] ?? 0) === QueuedEmail::SENT, 'the file removed during the send: still Sent');

	section('A throw, a stale lock, and a late outcome (B2, R23, R64)');
	EmailSender::$capture = function () { throw new RuntimeException('provider blew up'); };
	$threw = '';
	try { (new EmailSender())->send($msg('Throw ' . $run)); } catch (Throwable $e) { $threw = $e->getMessage(); }
	$r = $rows('Throw ' . $run)[0] ?? array();
	check($threw === 'provider blew up' && (int)$r['equ_status'] === QueuedEmail::ERROR_SENDING && $r['equ_body'] !== null,
		'a throw inside the send leaves Error sending with its content, and goes on to the caller');
	EmailSender::$capture = $capture_ok;
	$stale = function (string $subject, bool $body) use ($db) {
		$q = $db->prepare("INSERT INTO equ_queued_emails (equ_from, equ_from_name, equ_to, equ_to_name, equ_subject, equ_body,
			equ_status, equ_retry_count, equ_locked_time) VALUES ('s@example.com', 's', 't@example.com', 't', ?, ?, 3, 0,
			now() - interval '20 minutes') RETURNING equ_queued_email_id");
		$q->execute(array($subject, $body ? '<p>b</p>' : null));
		$id = (int)$q->fetchColumn();
		QueuedEmail::$written_ids[$id] = true;
		return $id;
	};
	$with = $stale('Stale with ' . $run, true);
	$without = $stale('Stale without ' . $run, false);
	QueuedEmail::expire_stale();
	check((int)$row($with)['equ_status'] === QueuedEmail::ERROR_SENDING && (int)$row($with)['equ_retry_count'] === 1,
		'a stale Locked row with its content goes back to Error sending, counting a try');
	check((int)$row($without)['equ_status'] === QueuedEmail::PERMANENT_FAILURE, 'one without content: Permanent failure');
	check(QueuedEmail::settle($without, QueuedEmail::SENT) === false && (int)$row($without)['equ_status'] === QueuedEmail::PERMANENT_FAILURE,
		'a late outcome for a row already settled changes nothing');

	section('Suppressed on its retry: Deleted (R22)');
	EmailSender::$capture = $capture_fail;
	(new EmailSender())->send($msg('Dry retry ' . $run));
	(new EmailSender())->send($msg('Trap retry ' . $run));
	EmailSender::$capture = $capture_ok;
	harness_set_setting_mem('email_dry_run', '1');
	$retry((int)$rows('Dry retry ' . $run)[0]['equ_queued_email_id']);
	harness_set_setting_mem('email_dry_run', '0');
	harness_set_setting_mem('email_test_recipient', '   '); // blank once trimmed
	$retry((int)$rows('Trap retry ' . $run)[0]['equ_queued_email_id']);
	harness_set_setting_mem('email_test_recipient', $trap);
	check((int)$rows('Dry retry ' . $run)[0]['equ_status'] === QueuedEmail::DELETED && $rows('Dry retry ' . $run)[0]['equ_service'] === 'dry_run',
		'under dry run');
	check((int)$rows('Trap retry ' . $run)[0]['equ_status'] === QueuedEmail::DELETED
		&& $rows('Trap retry ' . $run)[0]['equ_service'] === 'test_trap_empty', 'under an empty trap');

	section('claim(), retries and the limit (R51, R63, R64)');
	EmailSender::$capture = $capture_fail;
	(new EmailSender())->send($msg('Limit ' . $run));
	$id = (int)$rows('Limit ' . $run)[0]['equ_queued_email_id'];
	$q = new QueuedEmail($id, TRUE);
	check($q->claim() === true && (int)$row($id)['equ_status'] === QueuedEmail::LOCKED && $row($id)['equ_locked_time'] !== null,
		'claim() takes an Error sending row, setting its lock time');
	check((new QueuedEmail($id, TRUE))->claim() === false, 'and refuses one already Locked');
	$db->prepare('UPDATE equ_queued_emails SET equ_status = 6 WHERE equ_queued_email_id = ?')->execute(array($id));
	$retry($id);
	check((int)$row($id)['equ_status'] === QueuedEmail::ERROR_SENDING && (int)$row($id)['equ_retry_count'] === 1,
		'a retry that fails stays Error sending and counts one try');
	$retry($id);
	$retry($id);
	QueuedEmail::give_up(3);
	check((int)$row($id)['equ_status'] === QueuedEmail::PERMANENT_FAILURE && $row($id)['equ_body'] === null,
		'after the third, Permanent failure, content cleared');
	EmailSender::$capture = $capture_ok;
	$ready = new EmailMessage();
	$ready->to('ready-' . $run . '@example.com')->subject('Queued ' . $run)->html('<p>q</p>');
	check((new EmailSender())->queue($ready) === true && (int)$rows('Queued ' . $run)[0]['equ_status'] === QueuedEmail::READY_TO_SEND
		&& $rows('Queued ' . $run)[0]['equ_body'] !== null, 'queue() writes a Ready row with its content');
	$qid = (int)$rows('Queued ' . $run)[0]['equ_queued_email_id'];
	check((new QueuedEmail($qid, TRUE))->claim() === true, 'claim() takes a Ready row');
	$db->prepare('UPDATE equ_queued_emails SET equ_status = 4 WHERE equ_queued_email_id = ?')->execute(array($qid));
	check((new QueuedEmail($qid, TRUE))->claim() === false, 'and refuses a Sent one');

	section('A batch: one provider call, a row per recipient (R52)');
	$batch_provider = new class implements EmailServiceProvider {
		public static $calls = 0;
		public static $fail = array();
		public static function getKey(): string { return 'outbox_batch'; }
		public static function getLabel(): string { return 'Outbox batch (test)'; }
		public static function validateConfiguration(): array { return array('valid' => true, 'errors' => array()); }
		public function send(EmailMessage $m): bool { return true; }
		public function sendBatch(EmailMessage $m, array $r): array {
			self::$calls++;
			$failed = array_values(array_intersect($r, self::$fail));
			return array('success' => !$failed, 'failed_recipients' => $failed);
		}
		public static function getSettingsFields(): array { return array(); }
		public static function getSpfMechanism(string $domain): string { return ''; }
	};
	$registry = new ReflectionProperty('EmailSender', 'providers');
	EmailSender::getAvailableServices();
	$registry->setValue(null, array_merge((array)$registry->getValue(), array('outbox_batch' => get_class($batch_provider))));
	harness_defer(function () { EmailSender::resetProviderCache(); });
	harness_set_setting_mem('email_service', 'outbox_batch');
	harness_set_setting_mem('email_fallback_service', '');
	harness_set_setting_mem('email_test_mode', '0');
	$b1 = 'b1-' . $run . '@example.com';
	$b2 = 'b2-' . $run . '@example.com';
	$batch_provider::$fail = array($b2);
	$bm = new EmailMessage();
	$bm->subject('Batch ' . $run)->html('<p>batch</p>')->to($b1);
	$res = (new EmailSender())->sendBatch($bm, array($b1, $b2));
	harness_set_setting_mem('email_test_mode', '1');
	$br = $rows('Batch ' . $run);
	$by = array();
	foreach ($br as $x) { $by[$x['equ_to']] = $x; }
	check($batch_provider::$calls === 1 && count($br) === 2, 'one provider call, two rows', count($br));
	check((int)($by[$b1]['equ_status'] ?? 0) === QueuedEmail::SENT && (int)($by[$b2]['equ_status'] ?? 0) === QueuedEmail::ERROR_SENDING
		&& ($by[$b2]['equ_body'] ?? null) !== null && $res['failed_recipients'] === array($b2),
		'the delivered one Sent; the failed one its own Error sending row with its content, and no other row');
	$throw_provider = new class implements EmailServiceProvider {
		public static function getKey(): string { return 'outbox_batch_throw'; }
		public static function getLabel(): string { return 'Outbox batch throw (test)'; }
		public static function validateConfiguration(): array { return array('valid' => true, 'errors' => array()); }
		public function send(EmailMessage $m): bool { return false; }
		public function sendBatch(EmailMessage $m, array $r): array { throw new RuntimeException('batch provider down'); }
		public static function getSpfMechanism(string $domain): string { return ''; }
	};
	$registry->setValue(null, array_merge((array)$registry->getValue(), array('outbox_batch_throw' => get_class($throw_provider))));
	harness_set_setting_mem('email_service', 'outbox_batch_throw');
	harness_set_setting_mem('email_test_mode', '0');
	$bt = new EmailMessage();
	$bt->subject('Batch throw ' . $run)->html('<p>batch</p>')->to($b1);
	$bthrew = '';
	try { (new EmailSender())->sendBatch($bt, array($b1, $b2)); } catch (Throwable $e) { $bthrew = $e->getMessage(); }
	harness_set_setting_mem('email_test_mode', '1');
	$btr = $rows('Batch throw ' . $run);
	$bt_ok = count($btr) === 2;
	foreach ($btr as $x) {
		$bt_ok = $bt_ok && (int)$x['equ_status'] === QueuedEmail::ERROR_SENDING && $x['equ_body'] !== null;
	}
	check($bthrew === 'batch provider down' && $bt_ok,
		'a provider that throws leaves every recipient at Error sending with its content, and the throw goes on (reviewer2 C2)');
	$bo = new EmailMessage();
	$bo->subject('Batch once ' . $run)->html('<p>x</p>')->to($b1)->about($user)->once('batch-' . $run);
	$bo_refused = false;
	try { (new EmailSender())->sendBatch($bo, array($b1, $b2)); } catch (Throwable $e) { $bo_refused = true; }
	check($bo_refused && count($rows('Batch once ' . $run)) === 0, 'a send-once message cannot be batch-sent (reviewer2 C6)');
	harness_set_setting_mem('email_service', '');

	section('Unrecorded mail (R25, R56)');
	$u = $msg('Unrecorded ' . $run);
	$u->header('X-Campaign', 'c1')->unrecorded('test');
	(new EmailSender())->send($u);
	check(count($rows('Unrecorded ' . $run)) === 0, 'sent, it writes no row');
	EmailSender::$capture = $capture_fail;
	$u = $msg('Unrecorded fail ' . $run, array('u1-' . $run . '@example.com', 'u2-' . $run . '@example.com'));
	$u->header('X-Campaign', 'c1')->unrecorded('test');
	(new EmailSender())->send($u);
	$ur = $rows('Unrecorded fail ' . $run);
	check(count($ur) === 1 && $ur[0]['equ_service'] === 'unrecorded' && strpos((string)$ur[0]['equ_message_extras'], 'X-Campaign') !== false
		&& (int)$ur[0]['equ_status'] === QueuedEmail::ERROR_SENDING, 'failed, one retry row per email, keeping its headers');
	EmailSender::$capture = $capture_ok;
	$retry((int)$ur[0]['equ_queued_email_id']);
	check(count($rows('Unrecorded fail ' . $run)) === 0, 'sent on its retry, the row is deleted');

	section('Inside a caller\'s transaction (R4, R53)');
	$captured = array();
	$db->beginTransaction();
	(new EmailSender())->send($msg('Txn ' . $run));
	check(count($captured) === 1 && (int)($rows('Txn ' . $run)[0]['equ_status'] ?? 0) === QueuedEmail::SENT,
		'the email is sent, its row and outcome written');
	$bad = $msg('Txn bad ' . $run);
	// A record whose table name is longer than the column: the row is refused.
	$too_long = new class extends SystemBase { public static $tablename = 'zz_outbox_a_table_name_longer_than_the_sixty_four_characters_the_column_holds';
		public static $pkey_column = 'zz_id'; public static $prefix = 'zz'; public static $field_specifications = array(); };
	$too_long->key = 1;
	$bad->about($too_long);
	(new EmailSender())->send($bad);
	check(count($captured) === 2 && count($rows('Txn bad ' . $run)) === 0, 'a record write that fails still sends, with no row',
		count($captured) . ' sent, ' . count($rows('Txn bad ' . $run)) . ' row(s)');
	check((int)$db->query('SELECT 1')->fetchColumn() === 1, 'and leaves the caller\'s transaction usable');
	$db->rollBack();
	check(count($rows('Txn ' . $run)) === 0, 'a caller that rolls back leaves no row');

	section('A hot process (R43, R56)');
	SealedEgressGuard::markHot('outbox test');
	$hot_subject = 'Hot ' . $run . ' ' . str_repeat('h', 100);
	(new EmailSender())->send($msg($hot_subject), true, null, EmailSender::EGRESS_CONTENT_FREE);
	EmailSender::$capture = $capture_fail;
	(new EmailSender())->send($msg('Hot fail ' . $run), true, null, EmailSender::EGRESS_CONTENT_FREE);
	EmailSender::$capture = $capture_ok;
	$captured = array();
	(new EmailSender())->send($msg('Hot compose ' . $run), true, null, EmailSender::EGRESS_USER_COMPOSE);
	try {
		$queued_hot = (new EmailSender())->queue($msg('Hot queue ' . $run));
	} catch (SealedContentEgressException $e) {
		$queued_hot = false; // refused by the guard before anything is written
	}
	SealedEgressGuard::reset();
	$hr = $rows('Hot ' . $run . ' h')[0] ?? array();
	check((int)($hr['equ_status'] ?? 0) === QueuedEmail::SENT && $hr['equ_body'] === null && mb_strlen((string)$hr['equ_subject']) > 100,
		'a content-free send writes its row, a long subject and no content');
	check((int)($rows('Hot fail ' . $run)[0]['equ_status'] ?? 0) === QueuedEmail::PERMANENT_FAILURE
		&& $rows('Hot fail ' . $run)[0]['equ_body'] === null, 'its failure is Permanent failure, still with no content');
	check(count($captured) === 1 && count($rows('Hot compose ' . $run)) === 0, 'another hot send is sent with no row');
	check($queued_hot === false && count($rows('Hot queue ' . $run)) === 0, 'queue() refuses');

	section('The API');
	foreach (array('equ_recipients', 'equ_message_extras', 'equ_template', 'equ_entity_type', 'equ_entity_id', 'equ_once_key') as $col) {
		check(QueuedEmail::is_unwritable_field($col), 'the API cannot write ' . $col);
	}

	section('A retry Joinery Direct partly delivers records who is still owed (reviewer2 F1)');
	$refuse_provider = new class implements EmailServiceProvider {
		public static function getKey(): string { return 'outbox_refuse'; }
		public static function getLabel(): string { return 'Outbox refuse (test)'; }
		public static function validateConfiguration(): array { return array('valid' => true, 'errors' => array()); }
		public function send(EmailMessage $m): bool { return false; }
		public function sendBatch(EmailMessage $m, array $r): array { return array('success' => false, 'failed_recipients' => $r); }
		public static function getSpfMechanism(string $domain): string { return ''; }
	};
	$registry->setValue(null, array_merge((array)$registry->getValue(), array('outbox_refuse' => get_class($refuse_provider))));
	$direct_prop = new ReflectionProperty('EmailSender', 'direct_attempt');
	$direct_before = $direct_prop->getValue();
	harness_defer(function () use ($direct_prop, $direct_before) { $direct_prop->setValue(null, $direct_before); });
	$da = 'direct-a-' . $run . '@example.com';
	$db_ = 'direct-b-' . $run . '@example.com';
	harness_set_setting_mem('email_test_mode', '0');
	EmailSender::$capture = $capture_fail;
	(new EmailSender())->send($msg('Direct owed ' . $run, array($da, $db_)));
	$did = (int)$rows('Direct owed ' . $run)[0]['equ_queued_email_id'];
	$direct_to_a = 0;
	$direct_prop->setValue(null, function (EmailMessage $m) use ($da, &$direct_to_a) {
		$to = array_column($m->getRecipients(), 'email');
		if (in_array($da, $to, true)) {
			$direct_to_a++;
			return array('delivered' => array($da), 'remaining' => array_values(array_diff($to, array($da))));
		}
		return array('delivered' => array(), 'remaining' => $to);
	});
	EmailSender::$capture = null;
	harness_set_setting_mem('email_service', 'outbox_refuse');
	$retry($did);
	$owed = json_decode((string)$row($did)['equ_message_extras'], true)['owed'] ?? null;
	check((int)$row($did)['equ_status'] === QueuedEmail::ERROR_SENDING && $owed === array($db_),
		'the row now owes only the one the provider refused', json_encode($owed));
	$retry($did);
	check($direct_to_a === 1, 'and the next retry never sends the one Direct delivered a second copy', $direct_to_a);
	harness_set_setting_mem('email_service', '');
	$direct_prop->setValue(null, $direct_before);
	EmailSender::$capture = $capture_ok;
	harness_set_setting_mem('email_test_mode', '1');

	section('A retry sent to the trap says so on its row (reviewer2 F3)');
	harness_set_setting_mem('email_test_mode', '0');
	EmailSender::$capture = $capture_fail;
	(new EmailSender())->send($msg('Readdress ' . $run, 'real-' . $run . '@example.com'));
	EmailSender::$capture = $capture_ok;
	harness_set_setting_mem('email_test_mode', '1');
	$rid = (int)$rows('Readdress ' . $run)[0]['equ_queued_email_id'];
	$retry($rid);
	check((int)$row($rid)['equ_status'] === QueuedEmail::SENT && $row($rid)['equ_to'] === $trap
		&& strpos((string)$row($rid)['equ_recipients'], 'real-' . $run) === false
		&& strpos((string)$row($rid)['equ_subject'], '[for real-' . $run) === 0,
		'written with test mode off, retried with it on: the row is the trap\'s');

	// ------------------------------------------------------------------
	section('Send once');
	$captured = array();
	$once = function (string $key, ?SystemBase $about = null, $tpl = 'blank_template') use ($user, $run) {
		return EmailSender::sendTemplateOnce($about ?? $user, $key, $tpl, 'once-' . $run . '@example.com',
			array('subject' => 'Once ' . $run, 'body' => '<p>o</p>'));
	};
	check($once('k1-' . $run) === true && $once('k1-' . $run) === false && count($captured) === 1, 'the same key twice sends once');
	$other = make_user('OutboxOther');
	check($once('k1-' . $run, $other) === true, 'a key on one record never blocks another record');
	$fake = new Product(NULL);
	$fake->key = (int)$user->key; // same id, another table
	check(EmailSender::sendTemplateOnce($fake, 'k1-' . $run, 'blank_template', 'once-' . $run . '@example.com',
		array('subject' => 'Once ' . $run, 'body' => '<p>o</p>')) === true, 'or another table\'s row with the same id');
	check($once('k1-' . $run, null, 'no_such_template_' . $run) === false, 'a key already taken renders nothing (R61)');
	$refused = function (callable $fn) { try { $fn(); return false; } catch (Throwable $e) { return true; } };
	check($refused(function () use ($run) { $m = new EmailMessage(); $m->about(new User(NULL)); }), 'an unsaved record is refused');
	check($refused(function () use ($user, $run) {
		$m = EmailMessage::create(array('x1-' . $run . '@example.com', 'x2-' . $run . '@example.com'), 'Two ' . $run, '<p>x</p>');
		(new EmailSender())->send($m->about($user)->once('two-' . $run));
	}), 'a second To is refused');
	check($refused(function () use ($user, $run) {
		$m = EmailMessage::create('x1-' . $run . '@example.com', 'Cc ' . $run, '<p>x</p>');
		(new EmailSender())->send($m->cc('x2-' . $run . '@example.com')->about($user)->once('cc-' . $run));
	}), 'a Cc is refused');
	$db->beginTransaction();
	check($refused(function () use ($once, $run) { $once('txn-' . $run); }), 'refused inside a transaction');
	$db->rollBack();
	harness_set_setting_mem('email_dry_run', '1');
	check($once('dry-' . $run) === true && EmailSender::onceKeys($user, 'dry-') === array(), 'under dry run it returns true and writes no row (R62)');
	harness_set_setting_mem('email_dry_run', '0');
	$key_row = function (string $key, ?SystemBase $about = null) use ($db, $user) {
		$about = $about ?? $user;
		$q = $db->prepare('SELECT * FROM equ_queued_emails WHERE equ_entity_type = ? AND equ_entity_id = ? AND equ_once_key = ?');
		$q->execute(array($about::$tablename, (int)$about->key, $key));
		return $q->fetch(PDO::FETCH_ASSOC) ?: array();
	};
	check(($key_row('k1-' . $run)['equ_to'] ?? '') === $trap, 'under test mode the row is the trap\'s');
	EmailSender::$capture = $capture_fail;
	check($once('fail-' . $run) === true, 'a failed send still returns true: the key is taken (R24)');
	$fr = $key_row('fail-' . $run);
	check((int)$fr['equ_status'] === QueuedEmail::ERROR_SENDING && $fr['equ_body'] !== null, 'and it waits for its retry with its content');
	EmailSender::$capture = $capture_ok;
	$db->prepare('UPDATE equ_queued_emails SET equ_status = 8, equ_body = NULL WHERE equ_queued_email_id = ?')
		->execute(array((int)$fr['equ_queued_email_id']));
	check($once('fail-' . $run) === true && (int)$key_row('fail-' . $run)['equ_queued_email_id'] === (int)$fr['equ_queued_email_id']
		&& (int)$key_row('fail-' . $run)['equ_status'] === QueuedEmail::SENT, 'one at Permanent failure is revived as the same row (R21)');
	$db->prepare("UPDATE equ_queued_emails SET equ_status = 5, equ_service = 'dry_run' WHERE equ_queued_email_id = ?")
		->execute(array((int)$fr['equ_queued_email_id']));
	check(EmailSender::onceKeys($user, 'fail-') === array() && $once('fail-' . $run) === true
		&& (int)$key_row('fail-' . $run)['equ_status'] === QueuedEmail::SENT, 'and one Deleted by a suppression (R60)');
	$queued_once = EmailSender::sendTemplateOnce($user, 'q-' . $run, 'blank_template', 'once-' . $run . '@example.com',
		array('subject' => 'Once ' . $run, 'body' => '<p>o</p>'), true);
	check($queued_once === true && (int)$key_row('q-' . $run)['equ_status'] === QueuedEmail::READY_TO_SEND, 'a queued send-once is Ready');
	check(EmailSender::forgetOnce($user, 'q-' . $run) === true, 'forgetOnce() drops the key');
	$fq = $db->prepare("SELECT equ_status, equ_body FROM equ_queued_emails WHERE equ_queued_email_id = ?");
	$gone_q = $db->query("SELECT equ_queued_email_id FROM equ_queued_emails WHERE equ_entity_type = 'usr_users' AND equ_entity_id = "
		. (int)$user->key . " AND equ_once_key IS NULL AND equ_status = 5 ORDER BY equ_queued_email_id DESC LIMIT 1")->fetchColumn();
	check($gone_q !== false, 'the unsent row is Deleted with its key cleared, kept as history');
	check($once('q-' . $run) === true && (int)($key_row('q-' . $run)['equ_status'] ?? 0) === QueuedEmail::SENT, 'and the key sends again');
	EmailSender::$capture = $capture_fail;
	$once('gu-' . $run);
	$gid = (int)$key_row('gu-' . $run)['equ_queued_email_id'];
	$db->prepare('UPDATE equ_queued_emails SET equ_retry_count = 3 WHERE equ_queued_email_id = ?')->execute(array($gid));
	QueuedEmail::give_up(3);
	check($key_row('gu-' . $run)['equ_given_up_time'] !== null, 'a send-once that failed for good has its given-up time');
	$once('gu-' . $run);
	check((int)$key_row('gu-' . $run)['equ_status'] === QueuedEmail::ERROR_SENDING && $key_row('gu-' . $run)['equ_given_up_time'] !== null,
		'revived by the next pass and failing again, it keeps it (reviewer2 F2)');
	EmailSender::$capture = $capture_ok;
	$retry($gid);
	check((int)$key_row('gu-' . $run)['equ_status'] === QueuedEmail::SENT && $key_row('gu-' . $run)['equ_given_up_time'] === null,
		'Sent clears it');
	check($refused(function () use ($user) {
		EmailSender::sendTemplateOnce($user, 'blank', 'blank_template', '', array());
	}), 'a blank address throws, never the false that means already told (reviewer2 F5)');
	check(in_array('k1-' . $run, EmailSender::onceKeys($user, 'k1-'), true), 'onceKeys() lists a record\'s live keys');
	$claims = $db->prepare('SELECT count(*) FROM equ_queued_emails WHERE equ_entity_type = ? AND equ_entity_id = ? AND equ_once_key = ?');
	$claims->execute(array('usr_users', (int)$user->key, 'k1-' . $run));
	check((int)$claims->fetchColumn() === 1, 'the once index keeps one row per key');

	// ------------------------------------------------------------------
	section('Retention');
	$aged = function (string $subject, int $status, ?string $key = null, ?SystemBase $about = null) use ($db) {
		$q = $db->prepare("INSERT INTO equ_queued_emails (equ_from, equ_from_name, equ_to, equ_to_name, equ_subject, equ_status,
			equ_retry_count, equ_timestamp, equ_entity_type, equ_entity_id, equ_once_key)
			VALUES ('s@example.com', 's', 't@example.com', 't', ?, ?, 0, now() - interval '400 days', ?, ?, ?) RETURNING equ_queued_email_id");
		$q->execute(array($subject, $status, $about ? $about::$tablename : null, $about ? (int)$about->key : null, $key));
		$id = (int)$q->fetchColumn();
		QueuedEmail::$written_ids[$id] = true;
		return $id;
	};
	$gone_user = make_user('OutboxGone');
	$keep_live = $aged('Ret live ' . $run, QueuedEmail::SENT, 'ret-' . $run, $user);
	$deleted_user = make_user('OutboxSoft');
	$keep_soft = $aged('Ret soft ' . $run, QueuedEmail::SENT, 'ret-' . $run, $deleted_user);
	$deleted_user->soft_delete();
	$fake_gone = new User(NULL);
	$fake_gone->key = 2000000000 + random_int(0, 99999);
	$drop_gone = $aged('Ret gone ' . $run, QueuedEmail::SENT, 'ret-' . $run, $fake_gone);
	$fake_type = new class extends SystemBase { public static $tablename = 'zz_outbox_no_model'; public static $pkey_column = 'zz_id'; public static $prefix = 'zz'; public static $field_specifications = array(); };
	$fake_type->key = 1;
	$keep_type = $aged('Ret type ' . $run, QueuedEmail::SENT, 'ret-' . $run, $fake_type);
	$drop_plain = $aged('Ret plain ' . $run, QueuedEmail::PERMANENT_FAILURE);
	$keep_ready = $aged('Ret ready ' . $run, QueuedEmail::READY_TO_SEND);
	$keep_error = $aged('Ret error ' . $run, QueuedEmail::ERROR_SENDING);
	QueuedEmail::purge_finished(180);
	check($row($drop_plain) === array(), 'a finished row past the window goes');
	check($row($drop_gone) === array(), 'a once row whose record is gone goes');
	check($row($keep_live) !== array() && $row($keep_soft) !== array(), 'a once row is kept while its record exists, soft-deleted included');
	check($row($keep_type) !== array(), 'a table with no model keeps its rows');
	check($row($keep_ready) !== array() && $row($keep_error) !== array(), 'Ready and Error sending are never swept by age');

	section('The migration for the rows already there (R54, R65)');
	$old_locked = $aged('Mig locked ' . $run, QueuedEmail::LOCKED);
	$db->prepare('UPDATE equ_queued_emails SET equ_body = ? WHERE equ_queued_email_id = ?')->execute(array('<p>old</p>', $old_locked));
	$old_seven = $aged('Mig seven ' . $run, 7);
	$old_one = $aged('Mig one ' . $run, 1);
	$old_sent = $aged('Mig sent ' . $run, QueuedEmail::SENT);
	$db->prepare('UPDATE equ_queued_emails SET equ_body = ? WHERE equ_queued_email_id = ?')->execute(array('<p>old</p>', $old_sent));
	$new_locked = $stale('Mig new ' . $run, true);
	$db->prepare('UPDATE equ_queued_emails SET equ_locked_time = now() WHERE equ_queued_email_id = ?')->execute(array($new_locked));
	require_once(PathHelper::getIncludePath('migrations/email_outbox_existing_rows.php'));
	ob_start();
	email_outbox_existing_rows();
	ob_end_clean();
	check((int)$row($old_locked)['equ_status'] === QueuedEmail::PERMANENT_FAILURE && $row($old_locked)['equ_body'] === null,
		'a Locked row with no lock time: Permanent failure');
	check((int)$row($old_seven)['equ_status'] === QueuedEmail::PERMANENT_FAILURE, 'a row at 7: Permanent failure');
	check((int)$row($old_one)['equ_status'] === QueuedEmail::DELETED, 'a row at 1: Deleted');
	check($row($old_sent)['equ_body'] === null, 'a finished row\'s body is cleared');
	check((int)$row($new_locked)['equ_status'] === QueuedEmail::LOCKED, 'a row the new code locked is left alone');

	section('The debug email log is gone (E1)');
	check($db->query("SELECT to_regclass('public.dbl_debug_email_logs')")->fetchColumn() === null, 'its table');
	check((int)$db->query("SELECT count(*) FROM stg_settings WHERE stg_name = 'email_debug_mode'")->fetchColumn() === 0, 'its setting row');
	check((int)$db->query("SELECT count(*) FROM amu_admin_menus WHERE amu_slug = 'email-debug-logs'")->fetchColumn() === 0, 'its menu row');
	check((int)$db->query("SELECT count(*) FROM amu_admin_menus WHERE amu_slug = 'email-outbox'")->fetchColumn() === 1,
		'the Outbox page is on the menu (run update_database if not)');
} catch (Throwable $e) {
	check(false, 'EXCEPTION', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}
SealedEgressGuard::reset();
EmailSender::$capture = null;

harness_finish();
