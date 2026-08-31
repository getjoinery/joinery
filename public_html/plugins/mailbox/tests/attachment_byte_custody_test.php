<?php
/** @joinery-test
 * name: mailbox_attachment_byte_custody
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */
/**
 * Attachment byte custody (specs/mailbox_attachment_byte_custody.md).
 *
 * The rule under test: LOCAL BYTES WIN. A message ingested over IMAP records
 * which attachments exist but not their bytes; if the same message later
 * arrives in an archive, the platform is holding the real bytes and keeps them.
 * That has to be true whichever order the user happened to do the two things
 * in, which is the property the whole feature exists for.
 *
 * The load-bearing check is the sealed upgrade with the vault LOCKED. The design
 * rests on one claim — sealing needs only the vault's public key, so a
 * background import can seal onto an existing message without anybody being
 * signed in. If that claim is false the whole approach is wrong, so it is
 * asserted rather than assumed.
 *
 * Also covered here is the bug class this change could have introduced: the
 * bytes are now a container under the FILE's own key, and every reader that
 * previously keyed on ima_is_sealed would happily have returned that container
 * as a successful read — ciphertext with a 200, no error anywhere. Each reader
 * is checked to return the original bytes, never the container.
 *
 * Run: php tests/run.php db --filter=mailbox_attachment_byte_custody
 *
 * @version 1.3
 * @changelog 1.3 - D3: a stored copy that lists no attachments under an archive
 *   copy that has some is named in the ledger, not accepted as a clean duplicate.
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');

if (!PluginHelper::isPluginActive('mailbox')) {
	harness_skip('mailbox plugin inactive');
	harness_finish();
}
if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}
// Reading sealed bytes needs a real unlock window, which lives in APCu against a
// session id. Writing them does not — which is the point of the sealed-with-the-
// vault-locked check above, and why that one never needs either of these.
if (!vault_apcu_usable()) {
	harness_skip('APCu unavailable in this process',
		'run manually: php -d apc.enable_cli=1 plugins/mailbox/tests/attachment_byte_custody_test.php');
	harness_finish();
}
if (!vault_ensure_session()) {
	harness_skip('could not start a CLI session');
	harness_finish();
}

// Classes (SealedBox, VaultCrypto, DriveSealed, the mailbox models,
// MailArchiveImporter, …) resolve by name; only this non-class fragment needs
// requiring.
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/attachment_retrieval.php'));

$db = DbConnector::get_instance()->get_db_link();
$box = new SealedBox();
$crypto = new VaultCrypto();
$suffix = substr(md5(uniqid('abc', true)), 0, 8);

// ---- Fixtures ------------------------------------------------------------

$user = make_user('MbCustody');
$uid = (int)$user->key;

$kp1 = $box->generateKeypair();
$kp2 = $box->generateKeypair(); // the generation the rotation moves to

$vault = new UserEncryptionVault(NULL);
$vault->set('uev_usr_user_id', $uid);
$vault->set('uev_scope', UserEncryptionVault::SCOPE_USER);
$vault->set('uev_custody', UserEncryptionVault::CUSTODY_SERVER);
$vault->set('uev_public_key', $kp1['public']);
$vault->set('uev_salt', $box->generateSalt());
$vault->set('uev_key_generation', 1);
$vault->save();
$vault->load();

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', 'custody-' . $suffix . '.example');
$domain->set('ied_is_enabled', true);
$domain->save();
$domain_id = (int)$domain->key;

$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', $domain_id);
$alias->set('iea_alias', 'box' . $suffix);
$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
$alias->set('iea_is_enabled', true);
$alias->prepare();
$alias->save();
$alias->load();
$alias_id = (int)$alias->key;

// A single grantee, so the attachment owner resolves to a real person rather
// than to the system user (which is what a shared mailbox would give).
$grant = new InboundEmailMailboxGrant(NULL);
$grant->set('ieg_iea_inbound_email_alias_id', $alias_id);
$grant->set('ieg_usr_user_id', $uid);
$grant->save();

$file_ids = array();
$message_ids = array();

// Registered here so it runs BEFORE make_user's own teardown (LIFO) — the user
// row cannot go until everything pointing at it has.
harness_defer(function () use (&$file_ids, &$message_ids, $alias_id, $domain_id, $vault, $grant, $db) {
	foreach ($message_ids as $mid) {
		try {
			$db->prepare('DELETE FROM ima_inbound_message_attachments
				WHERE ima_iem_inbound_email_message_id = ?')->execute(array($mid));
		} catch (\Throwable $e) {}
	}
	$db->prepare('DELETE FROM iem_inbound_email_messages WHERE iem_iea_inbound_email_alias_id = ?')
		->execute(array($alias_id));
	foreach (array_unique($file_ids) as $fid) {
		try {
			$f = new File((int)$fid, TRUE);
			if ($f->key) { $f->permanent_delete(); }
		} catch (\Throwable $e) {}
	}
	try { $db->prepare('DELETE FROM mie_mail_import_entries WHERE mie_mir_mail_import_run_id IN
			(SELECT mir_mail_import_run_id FROM mir_mail_import_runs WHERE mir_iea_inbound_email_alias_id = ?)')
		->execute(array($alias_id)); } catch (\Throwable $e) {}
	try { $db->prepare('DELETE FROM mir_mail_import_runs WHERE mir_iea_inbound_email_alias_id = ?')
		->execute(array($alias_id)); } catch (\Throwable $e) {}
	try { $grant->permanent_delete(); } catch (\Throwable $e) {}
	$db->prepare('DELETE FROM iea_inbound_email_aliases WHERE iea_inbound_email_alias_id = ?')
		->execute(array($alias_id));
	$db->prepare('DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id = ?')
		->execute(array($domain_id));
	try { $vault->permanent_delete(); } catch (\Throwable $e) {}
});
harness_defer(function () use ($uid) { VaultUnlock::lockAll($uid); });

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$pdf = "%PDF-1.4\n% a small pretend document\n%%EOF\n";

/** A multipart/mixed message carrying the given attachment parts. */
$build_raw = function (string $mid, array $parts): string {
	$b = 'BOUND42';
	$out = "Message-ID: <" . $mid . ">\r\n"
		. "Date: Thu, 4 Aug 2016 10:00:00 +0000\r\n"
		. "From: Sender <sender@elsewhere.test>\r\n"
		. "To: me@example.test\r\n"
		. "Delivered-To: me@example.test\r\n"
		. "Subject: Custody " . $mid . "\r\n"
		. "MIME-Version: 1.0\r\n"
		. "Content-Type: multipart/mixed; boundary=\"" . $b . "\"\r\n\r\n"
		. "--" . $b . "\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text.\r\n\r\n";
	foreach ($parts as $p) {
		$out .= "--" . $b . "\r\n"
			. "Content-Type: " . $p['type'] . "; name=\"" . $p['name'] . "\"\r\n"
			. "Content-Transfer-Encoding: base64\r\n"
			. (empty($p['cid']) ? '' : "Content-ID: <" . $p['cid'] . ">\r\n")
			. "Content-Disposition: attachment; filename=\"" . $p['name'] . "\"\r\n\r\n"
			. chunk_split(base64_encode($p['bytes']), 76, "\r\n") . "\r\n";
	}
	return $out . "--" . $b . "--\r\n";
};

/**
 * A message in the shape an IMAP ingest leaves behind: stored row, manifest
 * rows describing the parts, and no bytes anywhere — 'remote' means the bytes
 * are still in the source mailbox.
 */
$make_remote_message = function (string $mid, array $rows) use (
		&$message_ids, $domain_id, $alias_id) {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', $domain_id);
	$m->set('iem_iea_inbound_email_alias_id', $alias_id);
	$m->set('iem_message_id_header', '<' . $mid . '>');
	$m->set('iem_recipient', 'me@example.test');
	$m->set('iem_direction', 'inbound');
	$m->set('iem_sender', 'sender@elsewhere.test');
	$m->set('iem_subject', 'Custody ' . $mid);
	$m->set('iem_body_plain', 'Body text.');
	$m->set('iem_raw_storage_driver', 'remote');
	$m->set('iem_received_time', gmdate('Y-m-d H:i:s'));
	$m->prepare();
	$m->save();
	$m->load();
	$message_ids[] = (int)$m->key;

	foreach ($rows as $row) {
		InboundMessageAttachment::CreateEntry(array_merge(array(
			'ima_iem_inbound_email_message_id' => (int)$m->key,
			'ima_is_inline'   => false,
			'ima_encoding'    => 'base64',
			'ima_fil_file_id' => null,
			'ima_is_sealed'   => false,
		), $row));
	}
	return $m;
};

/** Import one raw message as an archive of its own, to completion. */
$import_raw = function (string $raw, string $name) use (&$file_ids, $alias_id, $uid) {
	$file = File::createFromBytes($raw, $name, 'message/rfc822', $uid,
		array('fil_private' => true, 'fil_source' => File::SOURCE_MAIL_IMPORT_ARCHIVE));
	$file_ids[] = (int)$file->key;

	$run = new MailImportRun(NULL);
	$run->set('mir_iea_inbound_email_alias_id', $alias_id);
	$run->set('mir_usr_user_id', $uid);
	$run->set('mir_fil_file_id', (int)$file->key);
	$run->set('mir_source_name', $name);
	$run->set('mir_state', MailImportRun::STATE_QUEUED);
	$run->set('mir_own_addresses', "me@example.test");
	$run->prepare();
	$run->save();
	$run->load();

	$importer = new MailArchiveImporter($run);
	for ($i = 0; $i < 20; $i++) {
		$scan = $importer->scanBatch(microtime(true) + 20, 500);
		if (!empty($scan['done'])) { break; }
	}
	$run->load();
	$run->moveTo(MailImportRun::STATE_SCANNED);
	$importer->applySelection(array('*'), true, true);

	$totals = array('stored' => 0, 'dedup' => 0, 'failed' => 0, 'seen' => 0);
	for ($i = 0; $i < 20; $i++) {
		$batch = $importer->importBatch(50);
		foreach (array('stored', 'dedup', 'failed', 'seen') as $k) {
			$totals[$k] += (int)$batch[$k];
		}
		if (!empty($batch['exhausted'])) { break; }
	}
	return $totals;
};

/** The manifest rows of a message, oldest first. */
$manifest_of = function (int $message_id): array {
	$rows = new MultiInboundMessageAttachment(array('message_id' => $message_id));
	$rows->load();
	$out = array();
	foreach ($rows as $row) { $out[] = $row; }
	return $out;
};

$find_message = function (string $mid) use ($db, $alias_id): ?int {
	$stmt = $db->prepare('SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
		WHERE iem_message_id_header = ? AND iem_iea_inbound_email_alias_id = ?');
	$stmt->execute(array('<' . $mid . '>', $alias_id));
	$id = $stmt->fetchColumn();
	return $id === false ? null : (int)$id;
};

$open_window = function (string $secret) use ($uid) {
	VaultUnlock::open($uid, $secret, UserEncryptionVault::SCOPE_USER,
		array('idle' => null, 'absolute' => null));
};

// =========================================================================
section('Both orders converge on the same end state');
// =========================================================================

$raw_a = $build_raw('converge-imap-first-' . $suffix, array(
	array('name' => 'dot.png', 'type' => 'image/png', 'bytes' => $png)));

$msg_a = $make_remote_message('converge-imap-first-' . $suffix, array(
	array('ima_filename' => 'dot.png', 'ima_content_type' => 'image/png',
		'ima_mime_part' => '2', 'ima_size_bytes' => 999)));

$before = $manifest_of((int)$msg_a->key);
check(count($before) === 1 && $before[0]->get('ima_fil_file_id') === null,
	'IMAP first: the attachment starts as a reference, with no bytes stored');

$totals = $import_raw($raw_a, 'imap-first.eml');
check($totals['dedup'] === 1 && $totals['stored'] === 0,
	'IMAP first: importing the same message deduped rather than storing a second copy',
	json_encode($totals));

$after = $manifest_of((int)$msg_a->key);
$adopted_file_id = (int)$after[0]->get('ima_fil_file_id');
check($adopted_file_id > 0,
	'IMAP first: the dedup handed over its bytes — the row is now file-backed');
$file_ids[] = $adopted_file_id;

$adopted = new File($adopted_file_id, TRUE);
check($adopted->key && $adopted->read_bytes('original') === $png,
	'IMAP first: the stored bytes are the real attachment, decoded');
check((string)$adopted->get('fil_source') === File::SOURCE_EMAIL_ATTACHMENT,
	'IMAP first: the File is tagged email_attachment, so it stays out of Drive and its quota',
	(string)$adopted->get('fil_source'));
check((bool)$adopted->get('fil_private'), 'IMAP first: the File is private');
check(!$adopted->get('fil_content_sealed') && !$after[0]->get('ima_is_sealed'),
	'IMAP first: a plaintext message keeps plaintext bytes — neither sealed flag is set');
check((int)$after[0]->get('ima_size_bytes') === strlen($png),
	'IMAP first: the size column now records the decoded size, not the encoded one',
	(string)$after[0]->get('ima_size_bytes'));
check((string)$after[0]->get('ima_encoding') === 'base64',
	'IMAP first: the source transfer encoding is left alone, as on every MIME-split row',
	(string)$after[0]->get('ima_encoding'));
check((string)$after[0]->get('ima_filename') === 'dot.png'
	&& (string)$after[0]->get('ima_mime_part') === '2',
	'IMAP first: the identity columns were left exactly as they were');

// The other order, which already worked — asserted so "equivalent" is a
// measured claim rather than an assumption.
$raw_b = $build_raw('converge-archive-first-' . $suffix, array(
	array('name' => 'dot.png', 'type' => 'image/png', 'bytes' => $png)));
$totals_b = $import_raw($raw_b, 'archive-first.eml');
check($totals_b['stored'] === 1, 'archive first: the message stored', json_encode($totals_b));

$msg_b_id = $find_message('converge-archive-first-' . $suffix);
$message_ids[] = (int)$msg_b_id;
$mb = $manifest_of((int)$msg_b_id);
$b_file_id = (int)$mb[0]->get('ima_fil_file_id');
$file_ids[] = $b_file_id;
$b_file = new File($b_file_id, TRUE);

check($b_file->key && $b_file->read_bytes('original') === $png,
	'archive first: the bytes are local too');
check((bool)$b_file->get('fil_content_sealed') === (bool)$adopted->get('fil_content_sealed')
	&& (bool)$mb[0]->get('ima_is_sealed') === (bool)$after[0]->get('ima_is_sealed')
	&& (string)$mb[0]->get('ima_encoding') === (string)$after[0]->get('ima_encoding'),
	'THE TWO ORDERS CONVERGE: same custody, same sealed state, same encoding');

// =========================================================================
section('A sealed message adopts bytes with the vault LOCKED');
// =========================================================================

// This is the claim the whole design rests on: sealing needs only the public
// key, so a background import — which never has an unlock window — can still
// seal bytes onto protected mail.
VaultUnlock::lockAll($uid);
check(!VaultUnlock::isOpen($uid), 'no unlock window is open before the import runs');

$sealed_mid = 'sealed-locked-' . $suffix;
$raw_s = $build_raw($sealed_mid, array(
	array('name' => 'secret.pdf', 'type' => 'application/pdf', 'bytes' => $pdf)));

$msg_s = $make_remote_message($sealed_mid, array(
	array('ima_filename' => 'secret.pdf', 'ima_content_type' => 'application/pdf',
		'ima_mime_part' => '2', 'ima_size_bytes' => 42)));
InboundEmailMessage::sealAndPersistContent((int)$msg_s->key, $vault,
	'sender@elsewhere.test', 'me@example.test', 'Custody ' . $sealed_mid, 'Body text.', '');
$msg_s->load();
check((bool)$msg_s->get('iem_content_sealed'), 'the message is sealed before the import runs');

$totals_s = $import_raw($raw_s, 'sealed-locked.eml');
check($totals_s['dedup'] === 1, 'sealed: the import deduped', json_encode($totals_s));

$ms = $manifest_of((int)$msg_s->key);
$sealed_file_id = (int)$ms[0]->get('ima_fil_file_id');
check($sealed_file_id > 0,
	'SEALED BYTES LANDED WITH THE VAULT LOCKED — sealing needs only the public key');
$file_ids[] = $sealed_file_id;

$sealed_file = new File($sealed_file_id, TRUE);
check((bool)$sealed_file->get('fil_content_sealed'),
	'sealed: the File carries its own sealed state (fil_content_sealed)');
check(!$ms[0]->get('ima_is_sealed'),
	'sealed: ima_is_sealed stays false — the two shapes are never both set');
check((int)$sealed_file->get('fil_sealed_owner_user_id') === $uid,
	'sealed: wrapped to the owner the MESSAGE records, not to whoever ran the import',
	(string)$sealed_file->get('fil_sealed_owner_user_id'));
check((string)$sealed_file->get('fil_source') === File::SOURCE_EMAIL_ATTACHMENT,
	'sealed: still tagged email_attachment, so Drive never lists it');
check($sealed_file->read_bytes('original') !== $pdf,
	'sealed: what is on disk is NOT the plaintext');
check((int)$sealed_file->get('fil_plain_size_bytes') === strlen($pdf),
	'sealed: the plain size is recorded for display and Range arithmetic',
	(string)$sealed_file->get('fil_plain_size_bytes'));

// The key really does open with the owner's vault secret.
$fk_direct = $crypto->openItemDek((string)$sealed_file->get('fil_sealed_key'), $kp1['secret']);
check(SealedFileContainer::openBytes($sealed_file->read_bytes('original'), $fk_direct) === $pdf,
	'sealed: the container opens with the owner vault key and yields the original bytes');

// Sealing is a write, so an import must never go "hot" for the egress guard. If
// adoption ever started OPENING sealed content instead, this would trip — and
// every later run record in that process would be refused.
check(!SealedEgressGuard::isHot(),
	'the import never opened sealed content, so the egress guard stayed cold',
	implode(', ', SealedEgressGuard::sources()));

// =========================================================================
section('A stored copy that lists no attachments is called out, not accepted');
// =========================================================================

// D3 (specs/mail_import_loss_proof.md). A duplicate normally means "we already
// have this, nothing to do". But when the stored copy lists NO attachments and
// the archive copy plainly has some, the two disagree about what the message is
// — and silently accepting that is how an attachment goes missing with every
// counter still reading clean.
//
// Since a message and its manifest are now written in one transaction, this
// shape can no longer be a half-finished write caught mid-flight. It is
// persistent: a row from before that guarantee, or one stored by a path that
// writes no manifest. So there is nothing to retry — the job is to NAME it.
//
// THIS SECTION SITS HERE ON PURPOSE, above the first sealed READ. Opening
// sealed content arms the egress guard for the rest of the process, which then
// refuses any write longer than 64 characters — and these reasons are long,
// because carrying the colliding message id is the entire point of them.

$gap_mid = 'custody-manifest-gap';
$gap_msg = $make_remote_message($gap_mid, array());   // deliberately no manifest rows
check(AttachmentByteCustody::manifestRowCount((int)$gap_msg->key) === 0,
	'gap: the stored copy lists no attachments at all');

$gap_raw = $build_raw($gap_mid, array(
	array('name' => 'statement.pdf', 'type' => 'application/pdf', 'bytes' => $pdf),
));
$gap_totals = $import_raw($gap_raw, 'manifest-gap.eml');

check($gap_totals['dedup'] === 1 && $gap_totals['stored'] === 0,
	'gap: the message is still recognised as one we already hold', json_encode($gap_totals));

$stmt = $db->prepare("SELECT mie_reason, mie_iem_inbound_email_message_id
	FROM mie_mail_import_entries
	WHERE mie_iem_inbound_email_message_id = ? OR mie_reason LIKE ?
	ORDER BY mie_mail_import_entry_id DESC LIMIT 1");
$stmt->execute(array((int)$gap_msg->key, MailImportEntry::REASON_DEDUP_NO_MANIFEST . '%'));
$gap_row = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
$gap_reason = (string)($gap_row['mie_reason'] ?? '');

check(strncmp($gap_reason, MailImportEntry::REASON_DEDUP_NO_MANIFEST,
		strlen(MailImportEntry::REASON_DEDUP_NO_MANIFEST)) === 0,
	'gap: THE LEDGER NAMES THE MISMATCH instead of recording a clean duplicate', $gap_reason);
check(strpos($gap_reason, 'archive copy has 1') !== false,
	'gap: and says how many the archive copy carried, so the report can be acted on', $gap_reason);

// An ORDINARY duplicate must not be dragged into the same bucket, or the report
// section would fill with noise and stop meaning anything.
$plain_mid = 'custody-plain-dupe';
$plain_msg = $make_remote_message($plain_mid, array(
	array('ima_filename' => 'statement.pdf', 'ima_content_type' => 'application/pdf',
		'ima_mime_part' => '2', 'ima_size_bytes' => strlen($pdf)),
));
$plain_raw = $build_raw($plain_mid, array(
	array('name' => 'statement.pdf', 'type' => 'application/pdf', 'bytes' => $pdf),
));
$import_raw($plain_raw, 'plain-dupe.eml');

$stmt = $db->prepare("SELECT mie_reason FROM mie_mail_import_entries
	WHERE mie_iem_inbound_email_message_id = ? ORDER BY mie_mail_import_entry_id DESC LIMIT 1");
$stmt->execute(array((int)$plain_msg->key));
$plain_reason = (string)$stmt->fetchColumn();
check(strncmp($plain_reason, MailImportEntry::REASON_DEDUP_NO_MANIFEST,
		strlen(MailImportEntry::REASON_DEDUP_NO_MANIFEST)) !== 0,
	'gap: a duplicate whose manifest was there is NOT flagged', $plain_reason);

// =========================================================================
section('A locked read reports locked, never ciphertext');
// =========================================================================

$res = mailbox_retrieve_attachment_bytes($ms[0], $msg_s);
check(empty($res['ok']) && !empty($res['locked']),
	'locked: the retrieval helper answers locked => true so the reader can offer the one-tap unlock',
	json_encode(array('ok' => $res['ok'], 'locked' => $res['locked'])));
check($res['content'] === null, 'locked: no bytes came back at all — not ciphertext with a success');

// =========================================================================
section('What must NOT be upgraded');
// =========================================================================

// Idempotence: the same archive again changes nothing.
$before_ids = array((int)$after[0]->get('ima_fil_file_id'), (int)$ms[0]->get('ima_fil_file_id'));
$totals_again = $import_raw($raw_a, 'imap-first-again.eml');
check($totals_again['dedup'] === 1, 're-import: still a dedup', json_encode($totals_again));
$again = $manifest_of((int)$msg_a->key);
check((int)$again[0]->get('ima_fil_file_id') === $before_ids[0],
	'idempotent: a row that already has its bytes is left completely alone');
$tagged = $db->prepare('SELECT COUNT(*) FROM iem_inbound_email_messages
	WHERE iem_inbound_email_message_id = ? AND iem_mir_mail_import_run_id IS NOT NULL');
$tagged->execute(array((int)$msg_a->key));
check((int)$tagged->fetchColumn() === 0,
	'idempotent: adoption tags nothing with the run, so UNDO still cannot remove mail it did not create');

// A soft-deleted message is not refilled — the same reason a re-import cannot
// resurrect one.
$deleted_mid = 'soft-deleted-' . $suffix;
$raw_d = $build_raw($deleted_mid, array(
	array('name' => 'dot.png', 'type' => 'image/png', 'bytes' => $png)));
$msg_d = $make_remote_message($deleted_mid, array(
	array('ima_filename' => 'dot.png', 'ima_content_type' => 'image/png',
		'ima_mime_part' => '2', 'ima_size_bytes' => 999)));
InboundEmailMessage::updateColumns((int)$msg_d->key, array('iem_delete_time' => gmdate('Y-m-d H:i:s')));
$import_raw($raw_d, 'soft-deleted.eml');
$md = $manifest_of((int)$msg_d->key);
check($md[0]->get('ima_fil_file_id') === null,
	'a soft-deleted message is not upgraded — thrown-away mail stays thrown away');

// A message whose raw is stored locally already HAS its bytes; its rows are
// section pointers into that raw, so copying them out would duplicate custody.
$local_mid = 'section-pointer-' . $suffix;
$raw_p = $build_raw($local_mid, array(
	array('name' => 'dot.png', 'type' => 'image/png', 'bytes' => $png)));
$msg_p = $make_remote_message($local_mid, array(
	array('ima_filename' => 'dot.png', 'ima_content_type' => 'image/png',
		'ima_mime_part' => '2', 'ima_size_bytes' => 999)));
InboundEmailMessage::updateColumns((int)$msg_p->key, array('iem_raw_storage_driver' => 'inline'));
$import_raw($raw_p, 'section-pointer.eml');
$mp = $manifest_of((int)$msg_p->key);
check($mp[0]->get('ima_fil_file_id') === null,
	'a message whose raw is stored locally is not upgraded — those bytes are already local');

// Two identical parts and nothing to tell them apart: attaching the wrong bytes
// would be worse than leaving the references alone.
$amb_mid = 'ambiguous-' . $suffix;
$raw_amb = $build_raw($amb_mid, array(
	array('name' => 'same.png', 'type' => 'image/png', 'bytes' => $png),
	array('name' => 'same.png', 'type' => 'image/png', 'bytes' => $pdf)));
$msg_amb = $make_remote_message($amb_mid, array(
	array('ima_filename' => 'same.png', 'ima_content_type' => 'image/png',
		'ima_mime_part' => '7', 'ima_size_bytes' => 999),
	array('ima_filename' => 'same.png', 'ima_content_type' => 'image/png',
		'ima_mime_part' => '8', 'ima_size_bytes' => 999),
));
$totals_amb = $import_raw($raw_amb, 'ambiguous.eml');
check($totals_amb['dedup'] === 1,
	'ambiguous: the dedup outcome is still recorded — adoption is a bonus, never a condition',
	json_encode($totals_amb));
$mamb = $manifest_of((int)$msg_amb->key);
$unmatched = 0;
foreach ($mamb as $row) { if ($row->get('ima_fil_file_id') === null) { $unmatched++; } }
check($unmatched === 2,
	'ambiguous: neither row was upgraded — no guessing which part is which',
	'rows still reference-backed: ' . $unmatched);

// =========================================================================
section('A Joinery Direct delivery adopts too — parts, not a raw');
// =========================================================================

// Direct never assembles a MIME document; its dedup path hands over the
// already-decoded parts (AttachmentByteCustody::adoptParts). A Direct part has
// no MIME section, so this match rides on filename+type.
$direct_mid = 'direct-parts-' . $suffix;
$msg_dir = $make_remote_message($direct_mid, array(
	array('ima_filename' => 'dot.png', 'ima_content_type' => 'image/png',
		'ima_mime_part' => '2', 'ima_size_bytes' => 999)));

$router = new InboundEmailRouter();
$res_dir = $router->storeDirectMessage(
	array('sender' => 'sender@elsewhere.test', 'subject' => 'Custody ' . $direct_mid,
		'message_id' => '<' . $direct_mid . '>'),
	array('body_plain' => 'Body text.', 'body_html' => '',
		'attachments' => array(array('filename' => 'dot.png', 'content_type' => 'image/png',
			'content_id' => '', 'is_inline' => false, 'bytes' => $png))),
	$alias, $domain, 'me@example.test', true);
check(!empty($res_dir['dedup']) && empty($res_dir['message']),
	'direct: the delivery deduped against the IMAP reference row');

$mdir = $manifest_of((int)$msg_dir->key);
$dir_file_id = (int)$mdir[0]->get('ima_fil_file_id');
check($dir_file_id > 0,
	'DIRECT PARTS ADOPTED: the reference row is now file-backed');
$file_ids[] = $dir_file_id;
$dir_file = new File($dir_file_id, TRUE);
check($dir_file->key && $dir_file->read_bytes('original') === $png,
	'direct: the stored bytes are the delivered attachment');
check((int)$mdir[0]->get('ima_size_bytes') === strlen($png),
	'direct: the size column records the decoded size');

// =========================================================================
section('Every reader understands the self-sealed shape');
// =========================================================================

$open_window($kp1['secret']);
check(VaultUnlock::isOpen($uid), 'the owner window is open for the read checks');

$res = mailbox_retrieve_attachment_bytes($ms[0], $msg_s);
check(!empty($res['ok']) && $res['content'] === $pdf,
	'download endpoint: returns the original bytes in-window');

check(InboundEmailMessage::openSealedAttachment($msg_s, $ms[0],
		$sealed_file->read_bytes('original')) === $pdf,
	'opener without the File passed: resolves it from the row and returns plaintext');
check(InboundEmailMessage::openSealedAttachment($msg_s, $ms[0],
		$sealed_file->read_bytes('original'), $sealed_file) === $pdf,
	'opener with the File passed: same plaintext, one less load');

// The forward re-attach path, driven directly. This is the site where a leaked
// container would end up as ciphertext inside a real outgoing email, so it is
// checked against the method itself rather than by inspection.
$sender = new MailboxSender(MailboxViewer::forUser($uid, (int)$user->get('usr_permission')));
$read_part = new ReflectionMethod('MailboxSender', 'readOriginalPartBytes');
$ingestor = null;
check($read_part->invokeArgs($sender, array($ms[0], $msg_s, &$ingestor)) === $pdf,
	'forward re-attach: the original bytes, never the container');

// The File decrypt hook the bootstrap registers — the path a served attachment
// takes when it is read whole. It resolves the message from the manifest on its
// own, so it is checked through the registration rather than by hand.
$whole_prop = new ReflectionProperty('File', 'decrypt_hooks');
$whole = $whole_prop->getValue();
check(isset($whole[File::SOURCE_EMAIL_ATTACHMENT]),
	'the mailbox bootstrap registered a whole-bytes decrypt hook');
check(call_user_func($whole[File::SOURCE_EMAIL_ATTACHMENT],
		$sealed_file->read_bytes('original'), $sealed_file) === $pdf,
	'decrypt hook: returns the original bytes, never the container');

// The streaming decrypt hook is what turns a closed vault into a 423 rather
// than a 200 full of container bytes. Reach the registration itself.
$hooks_prop = new ReflectionProperty('File', 'streaming_decrypt_hooks');
$hooks = $hooks_prop->getValue();
check(isset($hooks[File::SOURCE_EMAIL_ATTACHMENT]),
	'a streaming decryptor is registered for mail attachments');
$opener = $hooks[File::SOURCE_EMAIL_ATTACHMENT];
check(call_user_func($opener, $sealed_file, 'original') instanceof DriveSealedStream,
	'streaming: a self-sealed attachment streams through the container decryptor');
check(call_user_func($opener, $adopted, 'original') === null,
	'streaming: a plaintext attachment returns null and serves unchanged');

// =========================================================================
section('The legacy message-DEK shape still opens');
// =========================================================================

$legacy_mid = 'legacy-shape-' . $suffix;
$msg_l = $make_remote_message($legacy_mid, array());
$dek = InboundEmailMessage::sealAndPersistContent((int)$msg_l->key, $vault,
	'sender@elsewhere.test', 'me@example.test', 'Custody ' . $legacy_mid, 'Body text.', '');
$msg_l->load();

$legacy_plain = "legacy attachment bytes";
$legacy_cipher = $crypto->sealField($legacy_plain, $dek,
	InboundEmailMessage::attachmentAd((int)$msg_l->key, '2'));
$legacy_file = File::createFromBytes($legacy_cipher, 'legacy.txt', 'text/plain', $uid,
	array('fil_private' => true, 'fil_source' => File::SOURCE_EMAIL_ATTACHMENT));
$file_ids[] = (int)$legacy_file->key;
$legacy_att = InboundMessageAttachment::CreateEntry(array(
	'ima_iem_inbound_email_message_id' => (int)$msg_l->key,
	'ima_filename' => 'legacy.txt', 'ima_content_type' => 'text/plain',
	'ima_mime_part' => '2', 'ima_encoding' => 'binary', 'ima_is_inline' => false,
	'ima_fil_file_id' => (int)$legacy_file->key, 'ima_is_sealed' => true,
));

check(InboundEmailMessage::openSealedAttachment($msg_l, $legacy_att, $legacy_cipher) === $legacy_plain,
	'legacy: an attachment sealed under the message DEK still opens unchanged');
check(call_user_func($opener, $legacy_file, 'original') === null,
	'legacy: the streaming opener declines it, so it falls through to the whole-bytes hook as before');

// =========================================================================
section('Rotation re-wraps an adopted attachment');
// =========================================================================

VaultUnlock::lockAll($uid);
$callbacks = VaultUnlock::resealCallbacks();
check(count($callbacks) >= 1, 'reseal callbacks are registered');
foreach ($callbacks as $cb) {
	call_user_func($cb, $uid, $kp1['secret'], 1, $kp2['public'], 2);
}

$sealed_file->load();
check((int)$sealed_file->get('fil_key_generation') === 2,
	'rotation: the adopted attachment moved to the new generation with the Drive sweep — no new code',
	(string)$sealed_file->get('fil_key_generation'));
$fk_after = $crypto->openItemDek((string)$sealed_file->get('fil_sealed_key'), $kp2['secret']);
check(SealedFileContainer::openBytes($sealed_file->read_bytes('original'), $fk_after) === $pdf,
	'rotation: and it still opens afterwards, with the same bytes');

$open_window($kp2['secret']);
$msg_s->load();
$ms_after = $manifest_of((int)$msg_s->key);
$res = mailbox_retrieve_attachment_bytes($ms_after[0], $msg_s);
check(!empty($res['ok']) && $res['content'] === $pdf,
	'rotation: the download endpoint still returns the original bytes');

// =========================================================================
section('Lowering the mailbox unseals an adopted attachment too');
// =========================================================================

// A message that goes plaintext must not keep an attachment that still demands
// an unlock window. The self-sealed shape cannot be opened by the message DEK,
// so the unseal pass hands it to the Drive lower instead.
$open_window($kp2['secret']);
$msg_s->load();
check(InboundEmailMessage::unsealAndPersistContent($msg_s),
	'lowering: the unseal pass ran');

$sealed_file->load();
check(!$sealed_file->get('fil_content_sealed'),
	'lowering: the adopted attachment is no longer a sealed container');
check($sealed_file->read_bytes('original') === $pdf,
	'lowering: its bytes are the plaintext original, readable without any window');

VaultUnlock::lockAll($uid);
$msg_s->load();
$ms_low = $manifest_of((int)$msg_s->key);
$res = mailbox_retrieve_attachment_bytes($ms_low[0], $msg_s);
check(!empty($res['ok']) && $res['content'] === $pdf,
	'lowering: and it now opens with the vault LOCKED, like any plaintext attachment');

VaultUnlock::lockAll($uid);
harness_finish();
