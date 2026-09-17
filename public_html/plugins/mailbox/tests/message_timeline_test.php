<?php
/** @joinery-test
 * name: message_timeline
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * The message timeline and the records it reads (specs/mailbox_message_timeline.md).
 *
 *  - A1: a stored message's routing-log line names the message row, and a
 *    plaintext mailbox's line carries the subject.
 *  - A2 (forward): the router writes one `forward` attempt per relayed stored
 *    message — sent when every destination took it, partial when some did not,
 *    keyed by the ORIGINAL Message-ID.
 *  - A2 (compose): MailboxSender::recordAttempt() turns EmailSender's report into
 *    a row: transport and label, the receipt, Direct-delivered recipients, and a
 *    failure's error text — a failed send leaves a row (B2).
 *  - The timeline assembles hops from the Received: chain, arrival, authentication,
 *    the routing line and the attempt lines in time order, with timeless state
 *    after the arrival; a message nobody can see it on gets no forward attempt.
 *  - A sealing mailbox whose owner has no vault records the attempt without the
 *    recipients or error, never in the clear.
 *
 * Run: php tests/run.php test-db --filter=message_timeline
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(__DIR__ . '/lib/mailbox_test_fixture.php');

require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domains_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_aliases_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_logs_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_send_attempts_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxSender.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxMessageTimeline.php'));
require_once(PathHelper::getIncludePath('includes/OutboundTransport.php'));

/** Router whose relay answers as told, without touching SMTP or a provider. */
class TimelineProbeRouter extends InboundEmailRouter {
	/** @var array destination => bool the next forwardEmail() returns */
	public $answers = array();
	public function forwardEmail($raw_email, $parsed, $alias, $domain, $destinations) {
		$map = array();
		foreach ($destinations as $d) { $map[$d] = array_key_exists($d, $this->answers) ? $this->answers[$d] : true; }
		return $map;
	}
	protected function checkAliasRateLimit($alias_id) { return true; }
	protected function checkDomainRateLimit($domain_id) { return true; }
}

$db = DbConnector::get_instance()->get_db_link();
$suffix = substr(md5(uniqid('tl', true)), 0, 8);
$domain_name = 'tl-test-' . $suffix . '.example';
mailbox_purge_domains('tl-test-%');
harness_defer(function () { mailbox_purge_domains('tl-test-%'); });

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', $domain_name);
$domain->set('ied_is_enabled', true);
$domain->save();
$domain_id = intval($domain->key);

$make_alias = function (string $local, string $mode, string $destinations = '') use ($domain_id) {
	$a = new InboundEmailAlias(NULL);
	$a->set('iea_ied_inbound_email_domain_id', $domain_id);
	$a->set('iea_alias', $local);
	$a->set('iea_delivery_mode', $mode);
	if ($destinations !== '') { $a->set('iea_destinations', $destinations); }
	$a->set('iea_is_enabled', true);
	$a->prepare(); $a->save();
	return $a;
};

$raw_for = function (string $to, string $token, string $subject) {
	return implode("\r\n", array(
		'Received: from mx.relay.example (mx.relay.example [203.0.113.9]) by mail.' . $to . ' with ESMTPS id ' . $token . '; Tue, 16 Sep 2026 14:02:11 +0000',
		'Received: from sender-host.example.com (sender-host.example.com [198.51.100.7]) by mx.relay.example with ESMTP; Tue, 16 Sep 2026 14:02:09 +0000',
		'From: Alice <alice@example.com>',
		'To: ' . $to,
		'Subject: ' . $subject,
		'Message-ID: <' . $token . '@example.com>',
		'Date: Tue, 16 Sep 2026 14:02:08 +0000',
		'MIME-Version: 1.0',
		'Content-Type: text/plain; charset=UTF-8',
		'',
		'Body ' . $token . '.',
		'',
	));
};
$row_for = function (string $token) use ($db) {
	$q = $db->prepare('SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages WHERE iem_message_id_header = ?');
	$q->execute(array('<' . $token . '@example.com>'));
	return intval($q->fetchColumn());
};

// ── A1: the routing line names its message ─────────────────────────────────
section('A1: the routing log names the message it stored');

$store = $make_alias('store' . $suffix, InboundEmailAlias::MODE_STORE);
$router = new TimelineProbeRouter();
$token1 = 'tl-store-' . $suffix;
$code = $router->processEmail($raw_for('store' . $suffix . '@' . $domain_name, $token1, 'Quarterly figures'), 'store' . $suffix . '@' . $domain_name);
check($code === 0, 'store-mode delivery succeeds', 'code ' . $code);
$stored_id = $row_for($token1);
check($stored_id > 0, 'a message row exists');

$logs = new MultiInboundEmailLog(array('message_id' => $stored_id));
check(count($logs) === 1, 'exactly one routing line names the row', count($logs) . ' lines');
foreach ($logs as $log) {
	check($log->get('iel_status') === InboundEmailLog::STATUS_STORED, 'the line is the stored transaction');
	check($log->get('iel_subject') === 'Quarterly figures', 'and carries the subject on a plaintext mailbox', var_export($log->get('iel_subject'), true));
}

// ── A2 (forward): attempt rows from the relay ───────────────────────────────
section('A2: a forward writes an attempt on the stored copy');

$fwd = $make_alias('fwd' . $suffix, InboundEmailAlias::MODE_FORWARD_AND_STORE, 'one@dest.example,two@dest.example');
$router->answers = array();
$token2 = 'tl-fwd-ok-' . $suffix;
$router->processEmail($raw_for('fwd' . $suffix . '@' . $domain_name, $token2, 'All good'), 'fwd' . $suffix . '@' . $domain_name);
$fwd_ok_id = $row_for($token2);
check($fwd_ok_id > 0, 'forward_and_store kept its copy');

$attempts = new MultiMailboxSendAttempt(array('source_message_id' => $fwd_ok_id));
check(count($attempts) === 1, 'one forward attempt on the copy', count($attempts));
foreach ($attempts as $a) {
	check($a->get('mst_kind') === MailboxSendAttempt::KIND_FORWARD, 'kind is forward');
	check($a->get('mst_outcome') === MailboxSendAttempt::OUTCOME_SENT, 'every destination taken → sent', $a->get('mst_outcome'));
	check($a->get('mst_message_id_header') === '<' . $token2 . '@example.com>', 'keyed by the ORIGINAL Message-ID', $a->get('mst_message_id_header'));
	check(intval($a->get('mst_iea_inbound_email_alias_id')) === intval($fwd->key), 'names the alias');
	$rec = $a->json('mst_recipients');
	check(count($rec) === 2 && $rec[0]['kind'] === 'forward', 'recipients are the destinations, kind forward', json_encode($rec));
	check($a->get('mst_error') === null || $a->get('mst_error') === '', 'no error on a clean forward');
}

$router->answers = array('two@dest.example' => false);
$token3 = 'tl-fwd-part-' . $suffix;
$router->processEmail($raw_for('fwd' . $suffix . '@' . $domain_name, $token3, 'Half way'), 'fwd' . $suffix . '@' . $domain_name);
$fwd_part_id = $row_for($token3);
$attempts = new MultiMailboxSendAttempt(array('source_message_id' => $fwd_part_id));
check(count($attempts) === 1, 'one attempt for the partial forward');
foreach ($attempts as $a) {
	check($a->get('mst_outcome') === MailboxSendAttempt::OUTCOME_PARTIAL, 'one destination refused → partial', $a->get('mst_outcome'));
	check(strpos((string)$a->get('mst_error'), 'two@dest.example') !== false, 'the error names the failed destination', (string)$a->get('mst_error'));
}
$err_logs = new MultiInboundEmailLog(array('message_id' => $fwd_part_id, 'status' => InboundEmailLog::STATUS_ERROR));
check(count($err_logs) === 1, 'the routing log\'s error line names the same row');

// A pure-forward alias stores nothing, so nothing can show an attempt: none is written.
$pure = $make_alias('pure' . $suffix, InboundEmailAlias::MODE_FORWARD, 'one@dest.example');
$before = intval($db->query('SELECT COUNT(*) FROM mst_mailbox_send_attempts')->fetchColumn());
$router->answers = array();
$router->processEmail($raw_for('pure' . $suffix . '@' . $domain_name, 'tl-pure-' . $suffix, 'Pass through'), 'pure' . $suffix . '@' . $domain_name);
$after = intval($db->query('SELECT COUNT(*) FROM mst_mailbox_send_attempts')->fetchColumn());
check($after === $before, 'a forward with no stored copy writes no attempt (no timeline could show it)');

// ── A2 (compose): recordAttempt from the sender's report ────────────────────
section('A2: a compose attempt from EmailSender\'s report');

$uid = mailbox_make_user('tl-user-' . $suffix . '@' . $domain_name);
harness_register_row('usr_users', 'usr_user_id', $uid);
$sender = new MailboxSender(MailboxViewer::forUser($uid, 10));
$record = new ReflectionMethod('MailboxSender', 'recordAttempt');
$hosted = OutboundTransport::forHostedAlias('store' . $suffix . '@' . $domain_name);
$source = new InboundEmailMessage($stored_id, TRUE);
$to  = array(array('email' => 'bob@gmail.example', 'name' => 'Bob'));
$cc  = array(array('email' => 'carol@example.net', 'name' => ''));
$msgid = '<compose-' . $suffix . '@' . $domain_name . '>';

// A failure: the row carries the transport's words, on the source message.
$record->invoke($sender,
	array('direct_delivered' => array(), 'transport' => 'mailgun', 'receipt' => null, 'error' => 'Domain not verified at Mailgun'),
	MailboxSendAttempt::OUTCOME_FAILED, 'Domain not verified at Mailgun', null, null,
	$store, null, $hosted, $source, null, $msgid, 'store' . $suffix . '@' . $domain_name, $to, $cc, array());
$fails = new MultiMailboxSendAttempt(array('source_message_id' => $stored_id, 'kind' => MailboxSendAttempt::KIND_COMPOSE));
check(count($fails) === 1, 'the failed send left a row (B2)');
foreach ($fails as $f) {
	check($f->get('mst_outcome') === MailboxSendAttempt::OUTCOME_FAILED, 'outcome failed');
	check($f->get('mst_error') === 'Domain not verified at Mailgun', 'with the transport\'s error', (string)$f->get('mst_error'));
	check($f->get('mst_transport') === 'mailgun' && $f->get('mst_transport_label') === 'Mailgun', 'transport key and label', $f->get('mst_transport') . '/' . $f->get('mst_transport_label'));
	check($f->get('mst_iem_inbound_email_message_id') === null, 'no outbound row for a failure');
	check(intval($f->get('mst_usr_user_id')) === $uid, 'who pressed send');
	$rec = $f->json('mst_recipients');
	check(count($rec) === 2 && $rec[0]['kind'] === 'to' && $rec[1]['kind'] === 'cc', 'To and Cc kept apart', json_encode($rec));
}

// A success with a receipt, Direct having delivered one of two recipients → partial.
$outbound_row = intval($db->query("INSERT INTO iem_inbound_email_messages
	(iem_ied_inbound_email_domain_id, iem_iea_inbound_email_alias_id, iem_direction, iem_sender, iem_recipient,
	 iem_subject, iem_body_plain, iem_message_id_header, iem_received_time)
	VALUES ($domain_id, " . intval($store->key) . ", 'outbound', 'store$suffix@$domain_name', 'bob@gmail.example',
	 'Re: Quarterly figures', 'thanks', '$msgid', now()) RETURNING iem_inbound_email_message_id")->fetchColumn());
$record->invoke($sender,
	array('direct_delivered' => array('carol@example.net'), 'transport' => 'mailgun',
		'receipt' => array('id' => '20260916.abc@mg.example', 'response' => 'Queued. Thank you.'), 'error' => null),
	MailboxSendAttempt::OUTCOME_SENT, null, $outbound_row, MailboxSendAttempt::SENT_COPY_NOT_APPLICABLE,
	$store, null, $hosted, $source, null, $msgid, 'store' . $suffix . '@' . $domain_name, $to, $cc, array());
$sent = new MultiMailboxSendAttempt(array('message_id' => $outbound_row));
check(count($sent) === 1, 'the sent message has its attempt');
foreach ($sent as $s) {
	check($s->get('mst_outcome') === MailboxSendAttempt::OUTCOME_PARTIAL, 'Direct took one, the carrier the other → partial', $s->get('mst_outcome'));
	check($s->json('mst_receipt')['id'] === '20260916.abc@mg.example', 'the carrier\'s receipt id is kept');
	check($s->json('mst_direct_delivered') === array('carol@example.net'), 'Direct-delivered recipients are kept');
	check($s->get('mst_delivery_status') === 'unknown', 'delivery starts unknown — a send knows acceptance, not arrival');
}

// ── The timeline ────────────────────────────────────────────────────────────
section('The timeline reads it all back in order');

$tl = (new MailboxMessageTimeline(new InboundEmailMessage($stored_id, TRUE)))->build();
$kinds = array_map(function ($e) { return $e['kind']; }, $tl['events']);
$titles = array_map(function ($e) { return $e['title']; }, $tl['events']);
check($tl['locked'] === false, 'a plaintext mailbox is never locked');
check(in_array('hop', $kinds, true), 'the Received: chain yields hops');
check(($titles[0] ?? '') === 'Left the sender\'s server', 'the oldest hop opens the timeline', $titles[0] ?? '(none)');
check(($tl['events'][0]['time'] ?? '') === '2026-09-16 14:02:09', 'with its own UTC time', $tl['events'][0]['time'] ?? '');
check(strpos((string)($tl['events'][0]['detail'] ?? ''), 'sender-host.example.com') !== false, 'naming the sending host');
check(in_array('arrived', $kinds, true) && in_array('auth', $kinds, true) && in_array('routed', $kinds, true), 'arrival, authentication and routing are present', implode(',', $kinds));
$routed = array_values(array_filter($tl['events'], function ($e) { return $e['kind'] === 'routed'; }));
check(strpos($routed[0]['title'] ?? '', 'store' . $suffix . '@' . $domain_name) !== false && strpos($routed[0]['title'], 'stored') !== false,
	'the routing line names the alias and the outcome', $routed[0]['title'] ?? '');
$failed = array_values(array_filter($tl['events'], function ($e) { return $e['kind'] === 'attempt_failed'; }));
check(count($failed) === 1 && strpos($failed[0]['detail'], 'Domain not verified at Mailgun') !== false,
	'the failed reply shows on the message it answered, with the carrier\'s words', json_encode($failed));
$times = array_values(array_filter(array_map(function ($e) { return $e['time']; }, $tl['events'])));
$sorted = $times; sort($sorted);
check($times === $sorted, 'timed events are in time order');

$tl_out = (new MailboxMessageTimeline(new InboundEmailMessage($outbound_row, TRUE)))->build();
$k = array_map(function ($e) { return $e['kind']; }, $tl_out['events']);
check(in_array('sent', $k, true) && in_array('receipt', $k, true) && in_array('delivery', $k, true),
	'the sent message shows sent, the receipt, and the Direct delivery', implode(',', $k));
$direct = array_values(array_filter($tl_out['events'], function ($e) { return $e['kind'] === 'delivery' && strpos($e['title'], 'Joinery Direct') !== false; }));
check(count($direct) === 1 && strpos($direct[0]['detail'], 'carol@example.net') !== false, 'Direct delivery names who it reached');
// Mailgun implements DeliveryEventSource; with no API key on the test box the
// lookup answers null and the panel says it could not reach the carrier — never
// "delivered", never "failed".
$mg_key = (string)Globalvars::get_instance()->get_setting('mailgun_api_key');
if ($mg_key === '') {
	check(count(array_filter($tl_out['notes'], function ($n) { return strpos($n, 'Could not reach Mailgun') !== false; })) === 1,
		'an unreachable carrier is said, not guessed', json_encode($tl_out['notes']));
}

$tl_fwd = (new MailboxMessageTimeline(new InboundEmailMessage($fwd_part_id, TRUE)))->build();
$sent_lines = array_values(array_filter($tl_fwd['events'], function ($e) { return $e['kind'] === 'sent'; }));
check(count($sent_lines) === 1 && strpos($sent_lines[0]['title'], 'Forwarded') === 0
	&& strpos($sent_lines[0]['detail'], 'some recipients could not be sent') !== false,
	'a partial forward reads as forwarded with a caveat', json_encode($sent_lines));

// ── Sealing: a protected mailbox with nobody to seal to keeps the fact, not the words
section('A sealing mailbox without a vault records the attempt without content');

$sealed_domain = new InboundEmailDomain(NULL);
$sealed_domain->set('ied_domain', 'tl-test-sealed-' . $suffix . '.example');
$sealed_domain->set('ied_is_enabled', true);
$sealed_domain->set('ied_security_level', InboundEmailDomain::LEVEL_PRIVATE);
$sealed_domain->save();
$private = new InboundEmailAlias(NULL);
$private->set('iea_ied_inbound_email_domain_id', intval($sealed_domain->key));
$private->set('iea_alias', 'private');
$private->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
$private->set('iea_is_enabled', true);
$private->prepare(); $private->save();
check($private->seals_content(), 'the fixture mailbox seals');
$id = MailboxSendAttempt::record(array(
	'mst_kind' => MailboxSendAttempt::KIND_COMPOSE,
	'mst_iea_inbound_email_alias_id' => intval($private->key),
	'mst_message_id_header' => '<sealed-' . $suffix . '@x>',
	'mst_recipients' => array(array('email' => 'secret@example.org', 'name' => '', 'kind' => 'to')),
	'mst_outcome' => MailboxSendAttempt::OUTCOME_FAILED,
	'mst_error' => 'refused: secret@example.org unknown',
	'mst_transport' => 'smtp',
));
check($id !== null, 'the attempt is recorded');
$raw = $db->prepare('SELECT mst_recipients, mst_error, mst_outcome FROM mst_mailbox_send_attempts WHERE mst_mailbox_send_attempt_id = ?');
$raw->execute(array($id));
$r = $raw->fetch(PDO::FETCH_ASSOC);
check($r['mst_outcome'] === 'failed', 'the outcome is kept');
check($r['mst_recipients'] === null && $r['mst_error'] === null, 'the recipients and error are NOT written in the clear', json_encode($r));

harness_finish();
?>
