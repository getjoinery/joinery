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
 * Run: php tests/run.php test-db --filter=fortress_ingest
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');

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
$box = new SealedBox();
$pair = $box->generateKeypair();
$pub = base64_encode(SealedBox::b64url_decode($pair['public']));

$file_ids = array();
harness_defer(function () use (&$file_ids) {
	foreach (array_unique($file_ids) as $fid) {
		try { $f = new File(intval($fid), TRUE); if ($f->key) { $f->permanent_delete(); } } catch (\Throwable $e) {}
	}
});

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
	// ---------------------------------------------------------------- fixtures
	$owner = make_user('FinOwner');
	$owner_id = intval($owner->key);
	vault_fixture_client_vault($owner_id, $pub, InboundEmailMessage::SEAL_SCOPE_FORTRESS);

	$domain_name = 'harnesstest-fin-' . bin2hex(random_bytes(4)) . '.example';
	$domain = new InboundEmailDomain(NULL);
	$domain->set('ied_domain', $domain_name);
	$domain->set('ied_is_enabled', true);
	$domain->set('ied_owner_usr_user_id', $owner_id);
	$domain->save();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($domain->key));
	$domain->set_security_level(InboundEmailDomain::LEVEL_FORTRESS);
	$domain->save();
	$domain = new InboundEmailDomain(intval($domain->key), TRUE);

	$alias = new InboundEmailAlias(NULL);
	$alias->set('iea_ied_inbound_email_domain_id', intval($domain->key));
	$alias->set('iea_alias', 'harnesstest_box');
	$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
	$alias->set('iea_destinations', '');
	$alias->set('iea_is_enabled', true);
	$alias->save();
	$alias = new InboundEmailAlias(intval($alias->key), TRUE);
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', intval($alias->key));
	InboundEmailMailboxGrant::sync_for_alias(intval($alias->key), array($owner_id));
	harness_defer(function () use ($db, $alias) {
		$db->prepare('DELETE FROM ieg_inbound_email_mailbox_grants WHERE ieg_iea_inbound_email_alias_id = ?')
			->execute(array(intval($alias->key)));
		$db->prepare('DELETE FROM iel_inbound_email_logs WHERE iel_iea_inbound_email_alias_id = ?')
			->execute(array(intval($alias->key)));
		$db->prepare('DELETE FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id IN
			(SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages WHERE iem_iea_inbound_email_alias_id = ?)')
			->execute(array(intval($alias->key)));
		$db->prepare('DELETE FROM mst_mailbox_send_attempts WHERE mst_iea_inbound_email_alias_id = ?')
			->execute(array(intval($alias->key)));
		$db->prepare('DELETE FROM iem_inbound_email_messages WHERE iem_iea_inbound_email_alias_id = ?')
			->execute(array(intval($alias->key)));
	});
	$address = 'harnesstest_box@' . $domain_name;

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
		$file_ids[] = intval($att->get('ima_fil_file_id'));
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
		$file_ids[] = intval($att->get('ima_fil_file_id'));
		check((string)$att->get('ima_filename') === '', 'the upload\'s ima_ row carries no name');
		$bytes = (string)(new File(intval($att->get('ima_fil_file_id')), TRUE))->read_bytes('original');
		check($open($bytes, $out_dek, InboundEmailMessage::attachmentAd($out_id, (string)$att->get('ima_mime_part')))
			=== "step one\nstep two\n", 'and its bytes open under the Sent row DEK');
	}

} catch (\Throwable $e) {
	check(false, 'EXCEPTION', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
