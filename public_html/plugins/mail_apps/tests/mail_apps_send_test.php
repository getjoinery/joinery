<?php
/** @joinery-test
 * name: mail_apps_send
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Sending and adding from a mail program (specs/mail_apps_plugin.md
 * § Sending, § Messages Thunderbird adds), with the send itself dry-run
 * (email_dry_run): the pipeline runs end to end and touches no transport.
 *
 * Covers:
 *  - MailboxSender::sendRaw(): the From must be this mailbox; a message signed
 *    or encrypted as a whole is refused; the envelope decides who receives it
 *    and the extra envelope recipients become Bcc; the Message-ID is kept when
 *    it names this mailbox's domain and minted otherwise
 *  - the Sent copy: stored once, read, without Received / X-Originating-IP,
 *    under the Message-ID it was sent with; numbered in Sent
 *  - a program's APPEND of its copy to Sent stores nothing new and answers the
 *    stored message's number; an unknown message APPENDed to Sent is stored
 *  - reply marks: a sent reply marks its original \Answered
 *  - a program's draft APPENDed to Drafts becomes a Joinery draft (attachments
 *    and all), found again by its Message-ID, and EXPUNGE deletes it
 *
 * Run: php -d apc.enable_cli=1 plugins/mail_apps/tests/mail_apps_send_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

if (!class_exists('MailAppContext')) {
	harness_skip('the mail_apps plugin is not active here', 'activate it, then run this suite');
	harness_finish();
}
require_once(__DIR__ . '/lib/mail_apps_fixtures.php');
harness_set_setting_mem('mail_apps_enabled', '1'); // the purpose's switch (AppAccess requiresSetting)
harness_set_setting_mem('email_dry_run', '1');

$db = DbConnector::get_instance()->get_db_link();
$user = make_user('MailAppsSend', 0);
$uid = (int)$user->key;
$domain = mafx_domain('send');
$alias = mafx_alias($domain, 'writer', $uid);
$address = strtolower($alias->get_full_address());
$domain_name = substr(strrchr($address, '@'), 1);
harness_defer(function () { SessionControl::get_instance()->clear_api_user(); });
$ctx = mafx_context($uid, $address);
$sender = new MailboxSender($ctx->viewer);

$original = mafx_deliver($alias, $domain, mafx_raw($address, 'Question', 'Can you reply?'));
$original_mid = (string)$db->query("SELECT iem_message_id_header FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = $original")->fetchColumn();

$compose = function (string $from, string $message_id, array $extra = array(), string $body = 'Hello there') use ($address) {
	$h = array(
		'From' => $from,
		'To' => '"Alice A" <alice@example.org>',
		'Cc' => 'carol@example.org',
		'Subject' => 'Re: Question',
		'Date' => gmdate('D, d M Y H:i:s +0000'),
		'Message-ID' => $message_id,
		'Received' => 'from my-laptop.local (192.168.1.5)',
		'X-Originating-IP' => '[192.168.1.5]',
		'MIME-Version' => '1.0',
		'Content-Type' => 'text/plain; charset=UTF-8',
	) + $extra;
	$out = '';
	foreach ($h as $k => $v) { $out .= "$k: $v\r\n"; }
	return $out . "\r\n" . $body . "\r\n";
};
$stored_ids = array();
$track = function ($id) use (&$stored_ids) {
	if ($id > 0) { $stored_ids[] = $id; harness_register_model('InboundEmailMessage', $id); }
};

require_once(PathHelper::getIncludePath('plugins/mail_apps/logic/mail_submit_logic.php'));
$submit = function (string $raw, array $rcpt) use ($address) {
	return mail_submit_logic(array('mailbox' => $address, 'message' => base64_encode($raw),
		'mail_from' => $address, 'rcpt_to' => $rcpt));
};

section('The From must be this mailbox');
$r = $submit($compose('<someone@else.example>', '<x1@' . $domain_name . '>'), array('alice@example.org'));
check($r->error && ($r->data['smtp_code'] ?? '') === '550 5.7.1', 'A message from another address is refused 550 5.7.1', json_encode($r->data));

section('A message signed as a whole is refused');
$raw = str_replace('Content-Type: text/plain; charset=UTF-8', 'Content-Type: multipart/signed; protocol="application/pgp-signature"; boundary="b"',
	$compose('<' . $address . '>', '<x2@' . $domain_name . '>'));
$r = $submit($raw, array('alice@example.org'));
check($r->error && ($r->data['smtp_code'] ?? '') === '554 5.6.0' && strpos($r->error, 'PGP/MIME') !== false,
	'PGP/MIME is refused 554 5.6.0, saying why', (string)$r->error);
$refused = '';
try {
	$sender->sendRaw($alias, $raw, $address, array('alice@example.org'));
} catch (MailboxSenderException $e) {
	$refused = $e->getMessage();
}
check(strpos($refused, 'PGP/MIME') !== false, 'And mailbox refuses it too, on its own', $refused);

section('A reply sends and stores its Sent copy');
$their_id = '<reply-' . bin2hex(random_bytes(6)) . '@' . $domain_name . '>';
$raw = $compose('"Writer W" <' . $address . '>', $their_id, array('In-Reply-To' => $original_mid, 'References' => $original_mid));
$r = $submit($raw, array('alice@example.org', 'carol@example.org', 'secret@example.org'));
check(!$r->error && ($r->data['message_id'] ?? '') === $their_id, 'It is sent, under the program\'s Message-ID naming this domain',
	json_encode($r->data) . ' ' . $r->error);
$sent_id = (int)$db->query("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
	WHERE iem_message_id_header = " . $db->quote($their_id) . " AND iem_direction = 'outbound'")->fetchColumn();
$track($sent_id);
$sent = array('outbound_id' => $sent_id);
check($sent_id > 0, 'Its Sent copy is stored');
$row = $db->query('SELECT * FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ' . $sent_id)->fetch(PDO::FETCH_ASSOC);
check(in_array($row['iem_is_read'], array(true, 't', 1, '1'), true), 'The Sent copy is read');
check(strpos((string)$row['iem_raw_headers'], 'Received:') === false && strpos((string)$row['iem_raw_headers'], 'X-Originating-IP') === false,
	'The headers naming the program\'s machine are gone from the Sent copy');
check($row['iem_thread_key'] !== null && $row['iem_thread_key'] !== '', 'It threads with the conversation');
check(strpos((string)$row['iem_raw_headers'], 'Bcc: secret@example.org') !== false,
	'The Sent copy records the Bcc the envelope carried', (string)$row['iem_raw_headers']);
check((bool)$db->query("SELECT mam_is_answered FROM mam_mail_app_messages WHERE mam_iem_inbound_email_message_id = $original")->fetchColumn(),
	'The original it replies to carries \\Answered');

$ctx = mafx_context($uid, $address);
$sent_folder = MailAppFolders::byKey($ctx, 'sent');
MailAppFolders::reconcile($ctx, $sent_folder);
$sent_uid = array_search((int)$sent['outbound_id'], mafx_live($ctx, 'sent'), true);
check($sent_uid !== false, 'It is numbered in Sent');

section('A forward is marked forwarded, not answered');
$fwd_target = mafx_deliver($alias, $domain, mafx_raw($address, 'Pass this on', 'please forward'));
$fwd_mid = (string)$db->query("SELECT iem_message_id_header FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = $fwd_target")->fetchColumn();
$fwd_raw = $compose('<' . $address . '>', '<fwd-' . bin2hex(random_bytes(5)) . '@' . $domain_name . '>',
	array('X-Forwarded-Message-Id' => $fwd_mid, 'In-Reply-To' => $fwd_mid, 'References' => $fwd_mid));
$r = $submit($fwd_raw, array('alice@example.org'));
check(!$r->error, 'The forward is sent', (string)$r->error);
$track((int)$db->query("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
	WHERE iem_message_id_header = " . $db->quote($r->data['message_id'] ?? '') . " AND iem_direction = 'outbound'")->fetchColumn());
$marks = $db->query("SELECT mam_is_answered, mam_is_forwarded FROM mam_mail_app_messages WHERE mam_iem_inbound_email_message_id = $fwd_target")->fetch(PDO::FETCH_ASSOC);
check($marks && in_array($marks['mam_is_forwarded'], array(true, 't', 1, '1'), true)
	&& !in_array($marks['mam_is_answered'], array(true, 't', 1, '1'), true), 'Its original carries $Forwarded and not \\Answered', json_encode($marks));

section('A program\'s copy to Sent is recognised');
$appended = MailAppMutations::append($ctx, $sent_folder, $raw, array('\\Seen'), null);
check($appended['uid'] === $sent_uid, 'APPEND of the same message answers the stored number', json_encode($appended));
$count = (int)$db->query("SELECT COUNT(*) FROM iem_inbound_email_messages WHERE iem_message_id_header = " . $db->quote($their_id))->fetchColumn();
check($count === 1, 'Nothing new was stored');
$other_raw = $compose('<' . $address . '>', '<elsewhere-' . bin2hex(random_bytes(4)) . '@' . $domain_name . '>');
$appended = MailAppMutations::append($ctx, $sent_folder, $other_raw, array('\\Seen'), null);
$other_id = mafx_live($ctx, 'sent')[$appended['uid']] ?? null;
$track((int)$other_id);
check($appended['uid'] !== null && $other_id !== null, 'A message sent elsewhere and APPENDed to Sent is stored there');

section('A minted Message-ID when the program\'s names another machine');
$raw2 = $compose('<' . $address . '>', '<abc@my-laptop.local>');
$sent2 = $sender->sendRaw($alias, $raw2, $address, array('alice@example.org'));
$track((int)$sent2['outbound_id']);
check(substr($sent2['message_id'], -strlen($domain_name) - 1) === $domain_name . '>', 'The Message-ID is minted on this mailbox\'s domain', $sent2['message_id']);

section('Forward as attachment');
$inner = "From: <x@elsewhere.example>\r\nTo: <y@elsewhere.example>\r\nSubject: Inner\r\nMIME-Version: 1.0\r\n"
	. "Content-Type: multipart/mixed; boundary=\"in1\"\r\n\r\n--in1\r\nContent-Type: text/plain\r\n\r\ninner text\r\n"
	. "--in1\r\nContent-Type: application/octet-stream; name=\"inner.bin\"\r\nContent-Disposition: attachment; filename=\"inner.bin\"\r\n"
	. "Content-Transfer-Encoding: base64\r\n\r\n" . base64_encode('inner bytes') . "\r\n--in1--\r\n";
$outer = "From: <$address>\r\nTo: <alice@example.org>\r\nSubject: Fwd: Inner\r\nMessage-ID: <fa-" . bin2hex(random_bytes(4)) . "@$domain_name>\r\n"
	. "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"out1\"\r\n\r\n--out1\r\nContent-Type: text/plain\r\n\r\nsee attached\r\n"
	. "--out1\r\nContent-Type: message/rfc822; name=\"Inner.eml\"\r\nContent-Disposition: attachment; filename=\"Inner.eml\"\r\n\r\n"
	. $inner . "\r\n--out1--\r\n";
$fa = $sender->sendRaw($alias, $outer, $address, array('alice@example.org'));
$track((int)$fa['outbound_id']);
mafx_register_files((int)$fa['outbound_id']);
$names = $db->query("SELECT ima_filename FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id = "
	. (int)$fa['outbound_id'] . " ORDER BY ima_filename")->fetchAll(PDO::FETCH_COLUMN);
$carry = new ReflectionMethod('MailboxSender', 'carryOverRaw');
$carried = $carry->invoke($sender, $outer, array('alice@example.org'), $alias, $address);
$sent_names = array();
foreach ($carried['email']->getAttachments() as $a) { $sent_names[] = $a['name'] ?? ($a['filename'] ?? json_encode(array_keys($a))); }
check($sent_names === array('Inner.eml'), 'What is sent carries the forwarded message as one attachment, its insides not loose beside it',
	json_encode($sent_names));
$rebuilt = MailAppRebuild::bytes($ctx, (int)$fa['outbound_id']);
$served = array();
foreach (MimeParse::parseMessage($rebuilt['bytes'] ?? '')->getParts() as $p) {
	if ($p->getName()) { $served[] = $p->getName(); }
}
check($served === array('Inner.eml'), 'The copy a program is served holds the forwarded message once, its insides not loose beside it',
	json_encode($served));
$dfa = MailAppMutations::append($ctx, MailAppFolders::byKey($ctx, 'drafts:' . $uid), $outer, array('\\Draft'), null);
$dfa_id = mafx_live($ctx, 'drafts:' . $uid)[$dfa['uid']] ?? null;
$track((int)$dfa_id);
mafx_register_files((int)$dfa_id);
$names = $db->query("SELECT ima_filename FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id = "
	. (int)$dfa_id . " ORDER BY ima_filename")->fetchAll(PDO::FETCH_COLUMN);
check(in_array('Inner.eml', $names, true), 'A draft forwarding a message keeps it as an attachment', json_encode($names));

section('A Bcc-only message names none of its recipients');
$bcc_only = "From: <$address>\r\nTo: undisclosed-recipients:;\r\nSubject: Quiet\r\nMessage-ID: <bo-" . bin2hex(random_bytes(4))
	. "@$domain_name>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nhello\r\n";
$carried = $carry->invoke($sender, $bcc_only, array('a@example.org', 'b@example.org'), $alias, $address);
$to_list = array_column($carried['email']->getRecipients(), 'email');
$bcc_list = array_column($carried['email']->getBcc(), 'email');
sort($bcc_list);
check($to_list === array($address), 'To is the mailbox itself', json_encode($to_list));
check($bcc_list === array('a@example.org', 'b@example.org'), 'Every recipient stays Bcc', json_encode($bcc_list));

section('A recipient that cannot be sent to is refused by name');
$r = $submit($compose('<' . $address . '>', '<bad-' . bin2hex(random_bytes(3)) . '@' . $domain_name . '>'),
	array('alice@example.org', '"quoted local"@example.org'));
check($r->error && ($r->data['smtp_code'] ?? '') === '554 5.6.0' && strpos($r->error, 'quoted local') !== false,
	'Refused 554, naming the address, rather than sent to the rest', json_encode($r->data) . ' ' . $r->error);

section('An attached message\'s body is never the outer message\'s');
$inner = "From: <x@example.org>\r\nSubject: Inner\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"in\"\r\n\r\n"
	. "--in\r\nContent-Type: text/plain\r\n\r\ninner plain\r\n--in\r\nContent-Type: text/html\r\n\r\n<p>inner html</p>\r\n--in--\r\n";
$outer = "From: <$address>\r\nTo: <alice@example.org>\r\nSubject: Fwd\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"out\"\r\n\r\n"
	. "--out\r\nContent-Type: text/plain\r\n\r\nmy note\r\n--out\r\nContent-Type: message/rfc822\r\n\r\n" . $inner . "\r\n--out--\r\n";
$router = new InboundEmailRouter();
$bodies = $router->extractBodies($outer, null);
check(trim($bodies['plain']) === 'my note' && $bodies['html'] === '', 'The bodies are the sender\'s own note only', json_encode($bodies));
$part_types = array();
foreach ($router->enumerateNonTextParts($outer) as $p) { $part_types[] = strtolower((string)$p->getType()); }
check(in_array('message/rfc822', $part_types, true), 'The attached message is listed as a part', json_encode($part_types));

section('Refusals say whether to try again');
$r = $submit($compose('<' . $address . '>', '<nr-' . bin2hex(random_bytes(3)) . '@' . $domain_name . '>'), array('not an address'));
check($r->error && ($r->data['smtp_code'] ?? '') === '554 5.6.0', 'No valid recipient is refused for good (554), not "try later"',
	json_encode($r->data) . ' ' . $r->error);

section('A program\'s draft');
$drafts = MailAppFolders::byKey($ctx, 'drafts:' . $uid);
$draft_mid = '<draft-' . bin2hex(random_bytes(5)) . '@' . $domain_name . '>';
$draft_raw = "From: <$address>\r\nTo: bob@example.org\r\nSubject: Half written\r\nMessage-ID: $draft_mid\r\nMIME-Version: 1.0\r\n"
	. "Content-Type: multipart/mixed; boundary=\"d1\"\r\n\r\n--d1\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nNot done yet\r\n"
	. "--d1\r\nContent-Type: application/octet-stream; name=\"notes.bin\"\r\nContent-Disposition: attachment; filename=\"notes.bin\"\r\n"
	. "Content-Transfer-Encoding: base64\r\n\r\n" . base64_encode('draft attachment bytes') . "\r\n--d1--\r\n";
$appended = MailAppMutations::append($ctx, $drafts, $draft_raw, array('\\Draft', '\\Seen'), null);
$draft_id = mafx_live($ctx, $drafts['key'])[$appended['uid']] ?? null;
$track((int)$draft_id);
check($draft_id !== null, 'It becomes a Joinery draft, numbered in Drafts');
$drow = $db->query("SELECT iem_direction, iem_subject FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = " . (int)$draft_id)->fetch(PDO::FETCH_ASSOC);
check($drow && $drow['iem_direction'] === 'draft' && $drow['iem_subject'] === 'Half written', 'With its subject');
$att = (int)$db->query("SELECT COUNT(*) FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id = " . (int)$draft_id)->fetchColumn();
check($att === 1, 'And its attachment');
mafx_register_files((int)$draft_id);
check(MailAppSearch::uids($ctx, $drafts, 'message-id', $draft_mid) === array($appended['uid']), 'Found again by its Message-ID');
$bytes = MailAppRebuild::bytes($ctx, (int)$draft_id);
check($bytes['ok'] && strpos($bytes['bytes'], 'Message-ID: ' . $draft_mid) !== false, 'Served back under the Message-ID the program gave it');
MailAppMutations::store($ctx, $drafts, array($appended['uid']), 'add', array('\\Deleted'));
MailAppMutations::expunge($ctx, $drafts);
check(!$db->query("SELECT 1 FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = " . (int)$draft_id)->fetchColumn(),
	'EXPUNGE in Drafts deletes the draft');

harness_finish();
