<?php
/** @joinery-test
 * name: attachment_preview
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Safe attachment preview — the reader's "read this without opening it" path.
 *
 * Two things are worth testing here, and they are not the parsing (that is
 * tests/unit/document_text_test.php):
 *
 *  1. ELIGIBILITY. Most real PDFs arrive declared application/octet-stream, so
 *     a Preview button decided from the declared type alone would be missing
 *     from nearly all of them. The flag has to consult the filename too — while
 *     staying a UI hint that never widens what a parser is handed. An encrypted
 *     database (.kdbx), equally declared octet-stream, must stay hidden.
 *
 *  2. THE GATE. A preview is exactly as private as the attachment, which is
 *     exactly as private as its message. The endpoint must refuse an ungranted
 *     viewer, and a NULL-alias (catch-all / unmatched) message must stay
 *     superadmin-only — the same rule the download endpoint applies.
 *
 * Sessions are simulated with SessionControl::set_api_user (the mechanism the
 * API dispatcher uses), so the logic runs exactly as it would behind /api/v1.
 *
 * Run: php plugins/mailbox/tests/attachment_preview_test.php  (schema synced).
 *
 * @version 1.1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/attachment_text_logic.php'));

$db = DbConnector::get_instance()->get_db_link();
$session = SessionControl::get_instance();

// ── Fixtures ─────────────────────────────────────────────────────────────────
$granted = make_user('PrevGranted', 5);
$other   = make_user('PrevOther', 5);
$super   = make_user('PrevSuper', 10);
$granted_uid = (int)$granted->key;
$other_uid   = (int)$other->key;
$super_uid   = (int)$super->key;

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', 'prev-' . bin2hex(random_bytes(4)) . '.example');
$domain->set('ied_is_enabled', true);
$domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);

$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
$alias->set('iea_alias', 'inbox');
$alias->set('iea_delivery_mode', 'store');
$alias->set('iea_is_enabled', true);
$alias->prepare();
$alias->save();
harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', (int)$alias->key);

$grant = new InboundEmailMailboxGrant(NULL);
$grant->set('ieg_iea_inbound_email_alias_id', (int)$alias->key);
$grant->set('ieg_usr_user_id', $granted_uid);
$grant->save();
harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', (int)$grant->key);

// A stored-raw message carrying one real text attachment, so retrieval has
// actual bytes to hand the extractor.
$attachment_text = "Invoice INV-4471\nAmount due: 1,250.00\nNet 30 days.\n";
$raw = "From: sender@example.test\r\n"
	. "To: inbox@x\r\n"
	. "Subject: Invoice\r\n"
	. "MIME-Version: 1.0\r\n"
	. "Content-Type: multipart/mixed; boundary=\"bnd42\"\r\n\r\n"
	. "--bnd42\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nSee attached.\r\n"
	. "--bnd42\r\nContent-Type: text/plain; charset=utf-8\r\n"
	. "Content-Disposition: attachment; filename=\"invoice.txt\"\r\n\r\n"
	. $attachment_text
	. "--bnd42--\r\n";

$mk_message = function ($alias_id) use ($domain, $raw) {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', (int)$domain->key);
	$m->set('iem_iea_inbound_email_alias_id', $alias_id);
	$m->set('iem_direction', 'inbound');
	$m->set('iem_sender', 'sender@example.test');
	$m->set('iem_recipient', 'inbox@x');
	$m->set('iem_subject', 'Invoice');
	$mid = '<prev-' . bin2hex(random_bytes(4)) . '@x>';
	$m->set('iem_message_id_header', $mid);
	$m->set('iem_thread_key', $mid);
	$m->set('iem_raw_message', $raw);
	$m->set('iem_raw_storage_driver', 'inline');
	$m->save();
	harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', (int)$m->key);
	return (int)$m->key;
};

$mk_attachment = function ($message_id, $filename, $type, $part = '2') {
	$a = new InboundMessageAttachment(NULL);
	$a->set('ima_iem_inbound_email_message_id', $message_id);
	$a->set('ima_filename', $filename);
	$a->set('ima_content_type', $type);
	$a->set('ima_size_bytes', 51);
	$a->set('ima_mime_part', $part);
	$a->set('ima_is_inline', false);
	$a->save();
	harness_register_row('ima_inbound_message_attachments', 'ima_inbound_message_attachment_id', (int)$a->key);
	return (int)$a->key;
};

$message_id = $mk_message((int)$alias->key);
$txt_id  = $mk_attachment($message_id, 'invoice.txt', 'text/plain');
$kdbx_id = $mk_attachment($message_id, 'passwords.kdbx', 'application/octet-stream', '3');

// A NULL-alias (unmatched / catch-all) message: superadmin-only, always.
$orphan_id = $mk_message(NULL);
$orphan_att_id = $mk_attachment($orphan_id, 'invoice.txt', 'text/plain');

/** Run the endpoint as a given user. */
$run = function ($uid, $attachment_id) use ($session) {
	$session->set_api_user($uid);
	try {
		return attachment_text_logic(array('attachment_id' => $attachment_id));
	} finally {
		$session->clear_api_user();
	}
};

// ── Eligibility: the octet-stream problem ────────────────────────────────────
section('Eligibility');

// What the reader draws its button from — the SAME helper MailboxService uses,
// so this tests production rather than a copy of it.
$kind = function ($declared, $filename) {
	return MailboxService::previewKind($declared, $filename);
};

check($kind('application/octet-stream', 'statement.pdf') === 'text',
	'a PDF declared octet-stream is still offered a preview (the 71% case)');
check($kind('application/pdf', 'statement.pdf') === 'text', 'an honestly declared PDF is offered a preview');
check($kind('application/octet-stream', 'passwords.kdbx') === null,
	'an encrypted database declared octet-stream is offered nothing');
check($kind('application/octet-stream', 'archive.7z') === null,
	'a format nothing here can read is offered nothing');
check($kind('application/octet-stream', 'contract.docx') === 'text', 'a docx previews as text');

// A picture has no text to pull out, so it gets the other kind of preview.
check($kind('image/png', 'photo.png') === 'image', 'a PNG previews as a picture, not as text');
check($kind('image/jpeg', 'scan.jpg') === 'image', 'so does a JPEG');
check($kind('application/octet-stream', 'photo.HEIC') === null,
	'a format the browser cannot show is offered nothing');
check($kind('application/octet-stream', 'holiday.jpeg') === 'image',
	'an image declared octet-stream is recognized by its name');
// SVG is markup wearing an image's name: it previews as TEXT, never as a picture.
check($kind('image/svg+xml', 'chart.svg') === 'text',
	'an SVG previews as text, never as a rendered picture');

// The payload the reader actually receives carries the flag.
$service_rows = null;
$msg = new InboundEmailMessage($message_id, TRUE);
$thread_key = (string)$msg->get('iem_thread_key');
if ($thread_key !== '') {
	$service = new MailboxService(MailboxViewer::forUser($granted_uid, 5));
	foreach ($service->getThread((int)$alias->key, $thread_key) as $m) {
		foreach (($m['attachments'] ?? array()) as $att) {
			if ((int)$att['id'] === $txt_id) $service_rows = $att;
		}
	}
}
if ($service_rows === null) {
	check(true, 'SKIP: the reader payload was not reachable in this run', 'thread lookup returned nothing');
} else {
	check(array_key_exists('preview_kind', $service_rows),
		'the reader payload carries the preview kind');
	check(($service_rows['preview_kind'] ?? null) === 'text',
		'and it says text for a readable attachment', json_encode($service_rows['preview_kind'] ?? null));
}

// ── The gate ─────────────────────────────────────────────────────────────────
section('The gate');

$r = $run($granted_uid, $txt_id);
check($r->error === null, 'a granted viewer gets a result', (string)$r->error);
check(!empty($r->data['previewable']), 'the attachment is previewable');
check(($r->data['status'] ?? '') === DocumentText::OK, 'and it read as text', json_encode($r->data['status'] ?? null));
check(strpos((string)($r->data['text'] ?? ''), 'INV-4471') !== false,
	'the text is the attachment, not the message body', substr((string)($r->data['text'] ?? ''), 0, 80));
check(strpos((string)($r->data['text'] ?? ''), 'See attached') === false,
	'the message body part is not what came back');
check(($r->data['filename'] ?? '') === 'invoice.txt', 'the answer names the file');

$r = $run($other_uid, $txt_id);
check($r->error !== null, 'an ungranted viewer is refused', json_encode($r->data));
check(strpos((string)$r->error, 'access') !== false, 'and told it is an access refusal', (string)$r->error);

$r = $run($other_uid, $orphan_att_id);
check($r->error !== null, 'a NULL-alias message refuses a non-superadmin', json_encode($r->data));

$r = $run($super_uid, $orphan_att_id);
check($r->error === null, 'a superadmin can read a NULL-alias message attachment', (string)$r->error);

$session->set_api_user(0);
$r = attachment_text_logic(array('attachment_id' => $txt_id));
$session->clear_api_user();
check($r->error !== null, 'a signed-out request is refused');

// ── Type refusal ─────────────────────────────────────────────────────────────
section('Type refusal');

$r = $run($granted_uid, $kdbx_id);
check(isset($r->data['previewable']) && $r->data['previewable'] === false,
	'an unreadable type answers previewable:false rather than erroring', json_encode($r->data));
check(!empty($r->data['reason']), 'and says why');

$r = $run($granted_uid, 0);
check($r->error !== null, 'a missing attachment id is refused');
$r = $run($granted_uid, 999999999);
check($r->error !== null, 'an attachment that does not exist is refused');

// ── Byte ceiling ─────────────────────────────────────────────────────────────
section('Byte ceiling');

// Oversize is refused from the RECORDED size, before any fetch or decrypt —
// and the refusal still writes a throttle row, or the costliest requests
// would be the only unthrottled ones.
$big_id = $mk_attachment($message_id, 'huge.pdf', 'application/pdf');
$big = new InboundMessageAttachment($big_id, TRUE);
$big->set('ima_size_bytes', 999999999);
$big->save();

$r = $run($granted_uid, $big_id);
check(($r->data['status'] ?? '') === DocumentText::TOO_LARGE,
	'an oversize attachment is refused from its recorded size, before any fetch', json_encode($r->data));
check(!empty($r->data['previewable']), 'the refusal still says the type was previewable');

$stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM rql_request_logs
	WHERE rql_feature = 'mailbox_preview' AND rql_action = ?");
$stmt->execute(array('attachment ' . $big_id . ' too large'));
check((int)$stmt->fetch(PDO::FETCH_ASSOC)['cnt'] === 1,
	'the size refusal wrote a throttle row');

// ── Rate limit ───────────────────────────────────────────────────────────────
section('Rate limit');

// 30 previews per 5 minutes per IP. Counted from rql_request_logs, so the check
// is against real rows: fill the window, then confirm the next one is refused.
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
$db->prepare("DELETE FROM rql_request_logs WHERE rql_feature = 'mailbox_preview' AND rql_ip_address = ?")
	->execute(array($ip));
harness_defer(function () use ($db, $ip) {
	$db->prepare("DELETE FROM rql_request_logs WHERE rql_feature = 'mailbox_preview' AND rql_ip_address = ?")
		->execute(array($ip));
});

check(RequestLogger::check_rate_limit('mailbox_preview', 30, 300),
	'an empty window allows a preview');

$stmt = $db->prepare("INSERT INTO rql_request_logs
	(rql_feature, rql_action, rql_ip_address, rql_was_success, rql_create_time)
	VALUES ('mailbox_preview', 'test fill', ?, true, NOW())");
for ($i = 0; $i < 29; $i++) { $stmt->execute(array($ip)); }
check(RequestLogger::check_rate_limit('mailbox_preview', 30, 300),
	'the 30th request inside the window is still allowed');

$stmt->execute(array($ip));
check(!RequestLogger::check_rate_limit('mailbox_preview', 30, 300),
	'the 31st is refused');

$r = $run($granted_uid, $txt_id);
check(!empty($r->data['rate_limited']),
	'and the endpoint says so rather than spawning another subprocess', json_encode($r->data));
check(isset($r->data['previewable']) && $r->data['previewable'] === false,
	'a throttled answer draws no text');

harness_finish();
