<?php
/** @joinery-test
 * name: fortress_ingest
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * A Fortress row as stored (specs/client_custody_mail.md § R2, WP1).
 *
 * Mail arriving at a Fortress mailbox, and a Sent copy written from one, seal to
 * the owner's `mail` vault in the browser's format — and the test proves it by
 * opening them the way the browser does, with a keypair only the test holds:
 *
 *  - the DEK is `v1.edgeseal.mail.`, every content column `v1.edge.` under it
 *    with the row's AD, and every one opens to the plaintext;
 *  - attachments (two, plus an inline HTML image) are File bytes in the
 *    `v1.edge.` format under the same DEK and the MIME-part AD; nothing about a
 *    file is readable beside it — ima_ name, type, Content-ID blank, the File
 *    named by message and part and typed octet-stream;
 *  - the sealed manifest names them, the sealed search text (gzip case) holds
 *    the body and the attachment names, the sealed snippet the preview;
 *  - no raw is kept, and the routing log holds no display name or subject;
 *  - a message whose attachments cannot be split is deferred (exit 75) and
 *    leaves no row, where a Private one would fall back to a stored raw;
 *  - the Sent copy of a send from the mailbox has the same shape.
 *
 * And what the reader's endpoints then hand over for such a message
 * (specs/client_custody_mail.md § R4, WP2):
 *  - the thread list: the newest message's subject, sender and snippet are
 *    empty and travel under `sealed` as ciphertext that the owner's key opens;
 *    the response says `fortress`; no plaintext of the message is in it;
 *  - a thread: every content column under `sealed`, clear fields empty,
 *    `fortress: true`, attachments (inline included) by id and MIME part with
 *    no name, and no signed URL once the native transport pass has run;
 *  - the attachment download hands over the stored ciphertext, flagged, and
 *    the server-side opener refuses the row; text preview is not offered;
 *  - the inline-image rewrite leaves a Fortress body's cid: references alone;
 *  - reply and forward take a Fortress source, and the reader offers them on
 *    a message it opened (WP4).
 *
 * Run: php tests/run.php test-db --filter=fortress_ingest
 *
 * @version 1.2 - carries the reader-API checks (fortress_reader_api folded in)
 * @version 1.1 - builds on fortress_fixture(), whose teardown also takes the Sent copy's Files
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');
require_once(__DIR__ . '/lib/fortress_fixture.php');
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/attachment_retrieval.php'));

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}

/** A router whose MIME split fails the way a full disk or a malformed part would. */
class FortressIngestFailingRouter extends InboundEmailRouter {
	public function enumerateNonTextParts(string $raw_email): array {
		return array(new class {
			public function getContents() { throw new RuntimeException('forced extraction failure'); }
		});
	}
}

$db = DbConnector::get_instance()->get_db_link();
// The owner, their mail vault, a Fortress domain and one mailbox; its teardown
// takes every message, attachment row and File hanging off the mailbox.
$fx = fortress_fixture('In');
$box = $fx['box'];
$pair = $fx['pair'];
$pub = $fx['pub'];
$owner_id = $fx['owner_id'];
$domain = $fx['domain'];
$alias = $fx['alias'];
$address = $fx['address'];
$domain_name = (string)$domain->get('ied_domain');

/** The row as stored. */
$row_of = function (int $id) use ($db): array {
	$q = $db->prepare('SELECT * FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
	$q->execute(array($id));
	return $q->fetch(PDO::FETCH_ASSOC) ?: array();
};
/** Open the row's DEK as the browser would, with the test's secret. */
$dek_of = function (array $row) use ($box, $pair, $pub): string {
	$prefix = 'v1.edgeseal.mail.';
	return $box->openEdge(substr((string)$row['iem_sealed_key'], strlen($prefix)), $pair['secret'], $pub);
};
/** Open one `v1.edge.` value under $dek with $ad. */
$open = function (string $value, string $dek, string $ad) use ($box): string {
	return $box->aeadDecryptGcm(substr($value, strlen('v1.edge.')), $dek, $ad);
};
/** Every populated sealed column holds `v1.edge.`; the inbound routing address is the one plain one. */
$all_edge = function (array $row): array {
	$bad = array();
	foreach (InboundEmailMessage::$sealed_fields as $col) {
		$v = (string)($row[$col] ?? '');
		if ($v === '' || ($col === 'iem_recipient' && $row['iem_direction'] === 'inbound')) {
			continue;
		}
		if (strncmp($v, 'v1.edge.', 8) !== 0) {
			$bad[] = $col;
		}
	}
	return $bad;
};
$inflate = function (string $search): string {
	return strncmp($search, 'gz:', 3) === 0 ? (string)gzdecode(base64_decode(substr($search, 3))) : $search;
};

try {
	$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
	$pdf = '%PDF-1.4 fortress report ' . bin2hex(random_bytes(8));
	$csv = "quarter,revenue\nq3,1200\n";
	$message_id = '<fin-' . bin2hex(random_bytes(6)) . '@elsewhere.example>';
	$body_line = 'The zebracorn figures for the quarter are attached, please review them before Thursday. ';
	$raw = implode("\r\n", array(
		'From: "Secret Sender Name" <sender@elsewhere.example>',
		'To: ' . $address,
		'Subject: Fortress quarterly numbers',
		'Message-ID: ' . $message_id,
		'MIME-Version: 1.0',
		'Content-Type: multipart/mixed; boundary="OUT"',
		'',
		'--OUT',
		'Content-Type: multipart/related; boundary="REL"',
		'',
		'--REL',
		'Content-Type: multipart/alternative; boundary="ALT"',
		'',
		'--ALT',
		'Content-Type: text/plain; charset=UTF-8',
		'',
		str_repeat($body_line, 60),
		'--ALT',
		'Content-Type: text/html; charset=UTF-8',
		'',
		'<p>' . str_repeat($body_line, 60) . '</p><img src="cid:logo123@elsewhere.example">',
		'--ALT--',
		'--REL',
		'Content-Type: image/png; name="logo.png"',
		'Content-ID: <logo123@elsewhere.example>',
		'Content-Disposition: inline; filename="logo.png"',
		'Content-Transfer-Encoding: base64',
		'',
		chunk_split(base64_encode($png)),
		'--REL--',
		'--OUT',
		'Content-Type: application/pdf; name="quarterly-report.pdf"',
		'Content-Disposition: attachment; filename="quarterly-report.pdf"',
		'Content-Transfer-Encoding: base64',
		'',
		chunk_split(base64_encode($pdf)),
		'--OUT',
		'Content-Type: text/csv; name="figures.csv"',
		'Content-Disposition: attachment; filename="figures.csv"',
		'Content-Transfer-Encoding: base64',
		'',
		chunk_split(base64_encode($csv)),
		'--OUT--',
		'',
	));

	// ------------------------------------------------------------ arrival
	section('arrival: the row seals to the mail key in the browser format');

	$router = new InboundEmailRouter();
	$exit = $router->processEmail($raw, $address);
	check($exit === 0, 'the message is accepted', 'exit ' . var_export($exit, true));
	$q = $db->prepare('SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
		WHERE iem_iea_inbound_email_alias_id = ? AND iem_message_id_header = ?');
	$q->execute(array(intval($alias->key), $message_id));
	$mid = intval($q->fetchColumn());
	check($mid > 0, 'it is stored');
	$row = $row_of($mid);

	check(strncmp((string)$row['iem_sealed_key'], 'v1.edgeseal.mail.', 17) === 0, 'its DEK is sealed to the mail scope');
	check(intval($row['iem_sealed_owner_user_id']) === $owner_id, 'to the owner');
	$bad = $all_edge($row);
	check(empty($bad), 'every content column holds v1.edge. ciphertext', implode(', ', $bad));
	check(InboundEmailMessage::isBrowserSealed($row), 'isBrowserSealed() says so');

	$dek = $dek_of($row);
	check(strlen($dek) === 32, 'the test\'s secret opens the DEK, as the browser\'s would');
	$field = function (string $col) use ($row, $dek, $mid, $open) {
		return $open((string)$row[$col], $dek, InboundEmailMessage::sealAd($mid, $col));
	};
	check($field('iem_subject') === 'Fortress quarterly numbers', 'the subject opens under the row AD');
	check(strpos($field('iem_sender'), 'Secret Sender Name') !== false, 'the sender opens');
	check(strpos($field('iem_body_plain'), 'zebracorn') !== false, 'the plain body opens');
	check(strpos($field('iem_body_html'), 'cid:logo123') !== false, 'the HTML body opens, cid: intact');
	check(strpos($field('iem_raw_headers'), 'Message-ID') !== false, 'the header block opens');

	$wrong = null;
	try { $open((string)$row['iem_subject'], $dek, InboundEmailMessage::sealAd($mid, 'iem_sender')); }
	catch (RuntimeException $e) { $wrong = $e; }
	check($wrong !== null, 'and a value moved to another column does not open');

	// ------------------------------------------------------------ derived fields
	section('the sealed search text, snippet and manifest');

	$search = $field('iem_search_text');
	check(strncmp($search, 'gz:', 3) === 0, 'a repetitive body is stored gzip-compressed');
	$text = $inflate($search);
	check(mb_strlen($text) <= InboundEmailMessage::SEARCH_TEXT_MAX_CHARS, 'within the cap', mb_strlen($text) . ' chars');
	check(strpos($text, 'Fortress quarterly numbers') !== false && strpos($text, 'zebracorn') !== false,
		'it holds the subject and the body');
	check(strpos($text, 'quarterly-report.pdf') !== false && strpos($text, 'figures.csv') !== false,
		'and the attachment names');
	$snippet = $field('iem_snippet');
	check(strpos($snippet, 'The zebracorn figures') === 0 && mb_strlen($snippet) <= InboundEmailMessage::SNIPPET_MAX_CHARS,
		'the snippet is the start of the readable body', $snippet);

	$manifest = json_decode($field('iem_attachment_manifest'), true);
	check(is_array($manifest) && count($manifest) === 3, 'the manifest lists three parts', json_encode($manifest));
	$by_name = array();
	foreach ((array)$manifest as $entry) { $by_name[$entry['filename']] = $entry; }
	check(isset($by_name['quarterly-report.pdf'], $by_name['figures.csv'], $by_name['logo.png']), 'by their real names');
	check(($by_name['logo.png']['inline'] ?? false) === true
		&& ($by_name['logo.png']['content_id'] ?? '') === 'logo123@elsewhere.example',
		'the inline image keeps its Content-ID, inside the seal');
	check(($by_name['quarterly-report.pdf']['content_type'] ?? '') === 'application/pdf', 'and each its real type');

	// ------------------------------------------------------------ attachments
	section('attachments: ciphertext under the message DEK, nothing in the clear beside them');

	$atts = new MultiInboundMessageAttachment(array('message_id' => $mid));
	check(count($atts) === 3, 'three attachment rows');
	$clear = array();
	foreach ($atts as $att) {
		foreach (array('ima_filename', 'ima_content_type', 'ima_content_id') as $col) {
			if ((string)$att->get($col) !== '') { $clear[] = $col . '=' . $att->get($col); }
		}
		$file = new File(intval($att->get('ima_fil_file_id')), TRUE);
		foreach (array('fil_title', 'fil_name', 'fil_type') as $col) {
			$v = (string)$file->get($col);
			if (preg_match('/report|figures|logo|pdf|csv|png/i', $v)) { $clear[] = $col . '=' . $v; }
		}
		if ((string)$file->get('fil_type') !== InboundEmailMessage::FORTRESS_FILE_TYPE) {
			$clear[] = 'fil_type=' . $file->get('fil_type');
		}
		check((bool)$att->get('ima_is_sealed'), 'part ' . $att->get('ima_mime_part') . ' is marked sealed');
	}
	check(empty($clear), 'no name, type or Content-ID is stored in the clear', implode('; ', $clear));

	$pdf_entry = $by_name['quarterly-report.pdf'] ?? array();
	$pdf_att = new InboundMessageAttachment(intval($pdf_entry['id'] ?? 0), TRUE);
	$pdf_file = new File(intval($pdf_att->get('ima_fil_file_id')), TRUE);
	$stored = (string)$pdf_file->read_bytes('original');
	check(strncmp($stored, 'v1.edge.', 8) === 0, 'the stored bytes are v1.edge. ciphertext');
	check($open($stored, $dek, InboundEmailMessage::attachmentAd($mid, (string)$pdf_entry['mime_part'])) === $pdf,
		'and open under the row DEK with the MIME-part AD');

	// ------------------------------------------------------------ no raw, no log leak
	section('no raw copy, and nothing readable in the routing log');

	check((string)($row['iem_raw_message'] ?? '') === '' && (string)($row['iem_raw_storage_key'] ?? '') === ''
		&& ($row['iem_raw_storage_driver'] ?? 'inline') === 'inline', 'no raw is stored, inline or in the raw store');
	$logs = $db->prepare('SELECT iel_from_address, iel_subject FROM iel_inbound_email_logs WHERE iel_iea_inbound_email_alias_id = ?');
	$logs->execute(array(intval($alias->key)));
	$log_leak = array();
	foreach ($logs->fetchAll(PDO::FETCH_ASSOC) as $log) {
		if (stripos((string)$log['iel_from_address'], 'Secret') !== false) { $log_leak[] = 'from'; }
		if ((string)$log['iel_subject'] !== '') { $log_leak[] = 'subject'; }
	}
	check(empty($log_leak), 'the log carries neither display name nor subject', implode(', ', $log_leak));

	// ------------------------------------------------------------ deferral
	section('a split that fails defers the message and leaves no row');

	$failing = new FortressIngestFailingRouter();
	$failed_id = '<fin-fail-' . bin2hex(random_bytes(6)) . '@elsewhere.example>';
	$raw_fail = str_replace($message_id, $failed_id, $raw);
	$threw = null;
	try {
		$failing->storeMessage($raw_fail, $failing->parseEmail($raw_fail), $alias, $domain, $address);
	} catch (\Throwable $e) { $threw = $e->getMessage(); }
	check($threw !== null && stripos($threw, 'deferred') !== false, 'storeMessage refuses instead of keeping a raw', (string)$threw);
	check($failing->processEmail($raw_fail, $address) === 75, 'and the MTA path answers 75, so the sender retries');
	$q->execute(array(intval($alias->key), $failed_id));
	check($q->fetchColumn() === false, 'no row survives');

	// ------------------------------------------------------------ Sent copy
	section('the Sent copy of a send from the mailbox has the same shape');

	$sender = new MailboxSender(MailboxViewer::forUser($owner_id, 10));
	$store = new ReflectionMethod('MailboxSender', 'storeOutboundRow');
	$parts = new ReflectionMethod('MailboxSender', 'storeCopyParts');
	$email = new EmailMessage();
	$email->html('<p>Here is the zebracorn plan.</p>');
	$email->text('Here is the zebracorn plan.');
	$to = array(array('email' => 'pat@elsewhere.example', 'name' => 'Pat Private'));
	$out_mid = '<fin-out-' . bin2hex(random_bytes(6)) . '@' . $domain_name . '>';
	$stored_out = $store->invoke($sender, null, $alias, MailboxSender::MODE_NEW, $address, $to, array(), array(),
		'Outbound plan', $email, $out_mid, null, null);
	$parts->invoke($sender, $stored_out,
		array('regular' => array(array('bytes' => "step one\nstep two\n", 'name' => 'plan-notes.txt')), 'inline' => array()),
		$address, 'Outbound plan', $email);
	$out = $row_of(intval($stored_out['id']));
	check(strncmp((string)$out['iem_sealed_key'], 'v1.edgeseal.mail.', 17) === 0, 'the Sent row seals to the mail scope');
	$bad = $all_edge($out);
	check(empty($bad) && strncmp((string)$out['iem_recipient'], 'v1.edge.', 8) === 0,
		'every content column, the recipients included, is v1.edge.', implode(', ', $bad));
	$out_dek = $dek_of($out);
	$out_id = intval($stored_out['id']);
	$out_field = function (string $col) use ($out, $out_dek, $out_id, $open) {
		return $open((string)$out[$col], $out_dek, InboundEmailMessage::sealAd($out_id, $col));
	};
	check($out_field('iem_subject') === 'Outbound plan', 'the subject opens');
	check(strpos($inflate($out_field('iem_search_text')), 'plan-notes.txt') !== false, 'the search text names the upload');
	$out_manifest = json_decode($out_field('iem_attachment_manifest'), true);
	check(is_array($out_manifest) && count($out_manifest) === 1 && $out_manifest[0]['filename'] === 'plan-notes.txt',
		'the manifest names the upload');
	$out_atts = new MultiInboundMessageAttachment(array('message_id' => $out_id));
	foreach ($out_atts as $att) {
		check((string)$att->get('ima_filename') === '', 'the upload\'s ima_ row carries no name');
		$bytes = (string)(new File(intval($att->get('ima_fil_file_id')), TRUE))->read_bytes('original');
		check($open($bytes, $out_dek, InboundEmailMessage::attachmentAd($out_id, (string)$att->get('ima_mime_part')))
			=== "step one\nstep two\n", 'and its bytes open under the Sent row DEK');
	}

	// ------------------------------------------------ what the reader hands over
	// (formerly fortress_reader_api) — its own mailbox, so nothing above leaks in.
	$afx = fortress_fixture('Api');
	$mid = fortress_ingest($afx, 'Fortress quarterly numbers', 'zebracorn');
	check($mid > 0, 'a message arrived at the Fortress mailbox');
	$alias_id = intval($afx['alias']->key);
	$service = new MailboxService(MailboxViewer::forUser($afx['owner_id'], 0));

	/** No plaintext of the message anywhere in a response. */
	$clean = function ($payload): array {
		$json = json_encode($payload);
		$found = array();
		foreach (array('Fortress quarterly', 'zebracorn', 'Secret Sender', 'quarterly-report.pdf', 'figures.csv', 'logo.png') as $needle) {
			if (stripos($json, $needle) !== false) { $found[] = $needle; }
		}
		return $found;
	};

	// ---------------------------------------------------------------- the list
	section('thread_list: the newest message travels sealed');

	$list = $service->listThreads($alias_id, array('inbox' => true));
	check(!empty($list['fortress']), 'the response says fortress');
	$thread = null;
	foreach ($list['threads'] as $t) { if (intval($t['latest_id']) === $mid) { $thread = $t; } }
	check($thread !== null, 'the thread is listed');
	check($thread['subject'] === '' && $thread['sender'] === '' && $thread['snippet'] === '' && $thread['senders'] === '',
		'its clear subject, sender, senders and snippet are empty');
	check(!array_key_exists('ai_summary', $thread), 'it carries no AI summary');
	$sealed = $thread['sealed'] ?? array();
	check(($sealed['sealed_scope'] ?? '') === 'mail' && intval($sealed['key'] ?? 0) === $mid
		&& ($sealed['sealed_ad_prefix'] ?? '') === 'mail:', 'sealed names the row, the mail scope and the AD prefix');
	$found = $clean($list);
	check(empty($found), 'no plaintext of the message is in the response', implode(', ', $found));

	$dek = fortress_open_dek($afx, (string)$sealed['sealed_dek']);
	check(fortress_open_field($afx, $sealed['iem_subject'], $dek, 'mail:' . $mid . ':iem_subject') === 'Fortress quarterly numbers',
		'the owner\'s key opens the subject with the AD the browser builds');
	check(strpos(fortress_open_field($afx, $sealed['iem_snippet'], $dek, 'mail:' . $mid . ':iem_snippet'), 'zebracorn') !== false,
		'and the snippet');

	// ---------------------------------------------------------------- a thread
	section('thread: every content column sealed, parts unnamed');

	$messages = $service->withSignedTransport($service->getThread($alias_id, (string)$thread['thread_key']));
	check(count($messages) === 1, 'one message');
	$m = $messages[0];
	check(!empty($m['fortress']), 'marked fortress');
	check($m['subject'] === '' && $m['sender'] === '' && $m['body_plain'] === '' && $m['body_html'] === '',
		'its clear content fields are empty');
	check($m['recipient'] === strtolower($afx['address']) || $m['recipient'] === $afx['address'],
		'the routing recipient stays in the clear', (string)$m['recipient']);
	foreach (array('iem_sender', 'iem_subject', 'iem_body_plain', 'iem_body_html', 'iem_attachment_manifest') as $col) {
		check(strncmp((string)($m['sealed'][$col] ?? ''), 'v1.edge.', 8) === 0, $col . ' travels as v1.edge.');
	}
	check(strpos(fortress_open_field($afx, $m['sealed']['iem_body_plain'], $dek, 'mail:' . $mid . ':iem_body_plain'), 'zebracorn') !== false,
		'and the body opens under the owner\'s key');
	$found = $clean($messages);
	check(empty($found), 'no plaintext of the message is in the thread payload', implode(', ', $found));

	// ---------------------------------------------------------------- someone else's
	section('an all-access viewer sees another person\'s rows marked as theirs');

	check(empty($thread['sealed']['foreign']) && empty($m['sealed']['foreign']), 'the owner\'s own rows are not marked');
	$admin = make_user('FortressApiAdmin');
	$oversight = new MailboxService(MailboxViewer::forUser(intval($admin->key), 10));
	$olist = $oversight->listThreads($alias_id, array('inbox' => true));
	$ot = null;
	foreach ($olist['threads'] as $t) { if (intval($t['latest_id']) === $mid) { $ot = $t; } }
	check($ot !== null && !empty($ot['sealed']['foreign']), 'the list marks the row foreign for a superadmin');
	$om = $oversight->withSignedTransport($oversight->getThread($alias_id, (string)$thread['thread_key']));
	check(!empty($om[0]['sealed']['foreign']), 'and so does the thread');
	$found = $clean(array($olist, $om));
	check(empty($found), 'and it carries no plaintext either', implode(', ', $found));

	check(count($m['attachments']) === 3, 'three parts listed, the inline image included');
	$inline = array_values(array_filter($m['attachments'], function ($a) { return !empty($a['inline']); }));
	check(count($inline) === 1, 'one of them inline');
	$unnamed = true; $signed = true;
	foreach ($m['attachments'] as $a) {
		if (array_key_exists('filename', $a) || array_key_exists('content_type', $a)) { $unnamed = false; }
		if (!is_string($a['url']) || strpos($a['url'], 'sig=') === false) { $signed = false; }
	}
	check($unnamed, 'no part carries a name or a type');
	check($signed, 'every part, the inline one included, gets a signed URL for a key-holding app to fetch');

	// What a signed fetch serves: the decrypt hook hands a Fortress part's
	// stored bytes back unchanged, and they open under the row's key.
	$part0 = new InboundMessageAttachment(intval($m['attachments'][0]['id']), TRUE);
	$file0 = new File(intval($part0->get('ima_fil_file_id')), TRUE);
	VaultUnlock::loadConsumerBootstraps();
	$resolve = new ReflectionMethod('File', 'resolve_decrypt_hook');
	$resolve->setAccessible(true);
	$hook = $resolve->invoke(null, $file0->get('fil_source'));
	$stored = (string)$file0->read_bytes('original');
	$served = $hook ? call_user_func($hook, $stored, $file0) : null;
	check($served === $stored && strncmp($stored, 'v1.edge.', 8) === 0,
		'a signed fetch serves the stored ciphertext unchanged');
	$opened = fortress_open_field($afx, $served, $dek, 'mail:' . $mid . ':att:' . $part0->get('ima_mime_part'));
	check($opened !== '' && strlen($opened) === intval($part0->get('ima_size_bytes')),
		'and the owner\'s key opens it with the part\'s AD', strlen($opened) . ' bytes');
	check($m['original_source'] === 'none', 'no original is offered');

	// ---------------------------------------------------------------- attachments
	section('the attachment endpoint hands over ciphertext');

	$att = new InboundMessageAttachment(intval($m['attachments'][0]['id']), TRUE);
	$msg = new InboundEmailMessage($mid, TRUE);
	$got = mailbox_retrieve_attachment_bytes($att, $msg);
	check($got['ok'] && !empty($got['browser_sealed']), 'retrieval answers with the stored bytes, flagged browser-sealed');
	check(strncmp((string)$got['content'], 'v1.edge.', 8) === 0, 'which are v1.edge. ciphertext');
	$refused = null;
	try { InboundEmailMessage::openSealedAttachment($msg, $att, (string)$got['content']); }
	catch (MailboxBrowserSealedException $e) { $refused = $e->getMessage(); }
	check($refused !== null, 'the server-side opener refuses a Fortress attachment');

	$logic_src = (string)file_get_contents(PathHelper::getIncludePath('plugins/mailbox/includes/attachment_retrieval.php'));
	check(strpos($logic_src, "\$browser_sealed ? 'Content-Disposition: attachment'") !== false,
		'the stream names no file for a browser-sealed part');

	// ---------------------------------------------------------------- reply
	section('reply and forward take a Fortress source the browser opened');

	$sender = new MailboxSender(MailboxViewer::forUser($afx['owner_id'], 0));
	$load = new ReflectionMethod('MailboxSender', 'loadSourceInScope');
	$loaded = null;
	try { $loaded = $load->invoke($sender, $mid); } catch (MailboxSenderException $e) { $loaded = $e->getMessage(); }
	check($loaded instanceof InboundEmailMessage && intval($loaded->key) === $mid,
		'the sender loads the source (the quote comes from source_open: fortress_compose)', is_string($loaded) ? $loaded : '');
	$reader_src = (string)file_get_contents(PathHelper::getIncludePath('plugins/mailbox/assets/mailbox_reader.js'));
	check(strpos($reader_src, "latest.alias_id != null && !latest.fortress_placeholder") !== false,
		'and the reader offers reply chips on one it opened, none on one it could not');

	// ---------------------------------------------------------------- inline images
	section('the inline-image rewrite leaves a Fortress body alone');

	$html = '<p>x</p><img src="cid:logo123@elsewhere.example">';
	$out = MailboxService::resolveInlineImages(array(array('id' => $mid, 'body_html' => $html, 'fortress' => true)));
	check($out[0]['body_html'] === $html, 'a message marked fortress is untouched');
	$out = MailboxService::resolveInlineImages(array(array('id' => $mid, 'body_html' => $html)));
	check($out[0]['body_html'] === $html, 'and even unmarked, a Fortress row has no Content-ID in the clear to map');

} catch (\Throwable $e) {
	check(false, 'EXCEPTION', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
