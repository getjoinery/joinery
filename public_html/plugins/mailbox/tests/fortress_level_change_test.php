<?php
/** @joinery-test
 * name: fortress_level_change
 * tier: test-db
 * env: dev-only
 * needs: []
 * timeout: 180
 */
/**
 * A mailbox's stored mail moving onto and off end-to-end
 * (specs/client_custody_mail.md § R8, WP5).
 *
 *  - Private → Fortress: the deferred raise moves a Private row (a shape (a)
 *    part and a shape (b) one) and a Standard row onto the mail key: fields in
 *    the browser's format, parts re-stored nameless under the message DEK,
 *    names in the sealed manifest, search text and snippet sealed, no raw, no
 *    plaintext of the message anywhere on the server;
 *  - Fortress → Private: the owner's browser walk (VaultCustodyChange, done
 *    here with the browser's crypto) moves each key to the server's; the
 *    rows read in the window, their parts download, the settle pass puts the
 *    names back;
 *  - Fortress → Standard: the unseal pass takes a lowered row to plaintext
 *    with its names;
 *  - the Private seal batch leaves Fortress mail to the raise;
 *  - a row that cannot move is stamped and passed by, and a row sealed to
 *    another owner's key is not the raise's to take (B24);
 *  - a lowered row whose key a rotation re-sealed still settles (B22), and
 *    no Fortress-only column outlives the lowering (B25).
 *
 * Run: php tests/run.php test-db --filter=fortress_level_change
 *
 * @version 1.1 - B22, B24, B25 (review 2026-09-28)
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');
require_once(__DIR__ . '/lib/fortress_fixture.php');
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/attachment_retrieval.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protection_ceremony.php'));

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}
if (!vault_apcu_usable() || !vault_ensure_session()) {
	harness_skip('APCu or a session is unavailable, so no unlock window can open');
	harness_finish();
}

/** The message's attachment rows, keyed by MIME part. */
function flc_parts(int $mid): array {
	$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT * FROM ima_inbound_message_attachments
		WHERE ima_iem_inbound_email_message_id = ? ORDER BY ima_mime_part');
	$q->execute(array($mid));
	$out = array();
	foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[$r['ima_mime_part']] = $r; }
	return $out;
}

/** Set the domain's level through the one writer, and forget the per-request scope memo. */
function flc_level(InboundEmailDomain $domain, string $level): InboundEmailDomain {
	$d = new InboundEmailDomain(intval($domain->key), TRUE);
	$d->set_security_level($level);
	$d->save();
	InboundEmailMessage::forgetSealScopes();
	return new InboundEmailDomain(intval($domain->key), TRUE);
}

try {
	$db = DbConnector::get_instance()->get_db_link();
	$fx = fortress_fixture('Level');
	$owner_id = $fx['owner_id'];
	$domain = $fx['domain'];

	// The owner's server vault, from a keypair the test holds, and its window.
	$kp = sodium_crypto_box_keypair();
	$server_secret = SealedBox::b64url(sodium_crypto_box_secretkey($kp));
	$sv = new UserEncryptionVault(NULL);
	$sv->set('uev_usr_user_id', $owner_id);
	$sv->set('uev_public_key', SealedBox::b64url(sodium_crypto_box_publickey($kp)));
	$sv->set('uev_salt', SealedBox::b64url(random_bytes(16)));
	$sv->save();
	harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', (int)$sv->key);
	$key = vault_fixture_open_window($owner_id, $server_secret);
	$mail_vault = InboundEmailMessage::loadSealVault($owner_id, InboundEmailMessage::SEAL_SCOPE_FORTRESS);

	$needles = array('Standard lemonade', 'Private quarterly', 'zebracorn', 'quokkafig', 'Secret Sender', 'quarterly-report.pdf', 'figures.csv', 'logo.png', 'fortress report');
	$leaks = function (array $ids) use ($db, $needles): array {
		$in = implode(',', array_map('intval', $ids));
		$dump = json_encode($db->query("SELECT * FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC))
			. json_encode($db->query("SELECT a.*, f.fil_name, f.fil_title, f.fil_type FROM ima_inbound_message_attachments a
				LEFT JOIN fil_files f ON f.fil_file_id = a.ima_fil_file_id WHERE a.ima_iem_inbound_email_message_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC));
		return array_values(array_filter($needles, function ($n) use ($dump) { return stripos($dump, $n) !== false; }));
	};

	// ------------------------------------------------------------ the rows
	section('A Standard row and a Private row with a shape (b) part');

	$domain = flc_level($domain, InboundEmailDomain::LEVEL_STANDARD);
	$std = fortress_ingest($fx, 'Standard lemonade', 'quokkafig');
	check($std > 0 && fortress_row($std)['iem_content_sealed'] === false, 'the Standard message is stored in the clear');

	$domain = flc_level($domain, InboundEmailDomain::LEVEL_PRIVATE);
	$priv = fortress_ingest($fx, 'Private quarterly numbers', 'zebracorn');
	$prow = fortress_row($priv);
	check($priv > 0 && strpos((string)$prow['iem_sealed_key'], 'v1.seal.') === 0 && strpos((string)$prow['iem_subject'], 'v1.aead.') === 0,
		'the Private message is sealed to the server key');
	$pparts = flc_parts($priv);
	check(count($pparts) === 3, 'with three parts');

	// The PDF becomes shape (b): a File sealed under its own key.
	$pdf = null;
	foreach ($pparts as $r) { if ($r['ima_filename'] === 'quarterly-report.pdf') { $pdf = $r; } }
	$pdf_att = new InboundMessageAttachment(intval($pdf['ima_inbound_message_attachment_id']), TRUE);
	$got = mailbox_retrieve_attachment_bytes($pdf_att, new InboundEmailMessage($priv, TRUE));
	$pdf_file = new File(intval($pdf['ima_fil_file_id']), TRUE);
	$pdf_file->replace_bytes((string)$got['content']);
	$db->prepare('UPDATE ima_inbound_message_attachments SET ima_is_sealed = false WHERE ima_inbound_message_attachment_id = ?')
		->execute(array(intval($pdf['ima_inbound_message_attachment_id'])));
	DriveSealed::sealExistingFile(new File(intval($pdf['ima_fil_file_id']), TRUE));
	check((bool)(new File(intval($pdf['ima_fil_file_id']), TRUE))->get('fil_content_sealed'), 'the PDF is now a self-sealed File (shape (b))');
	$old_file_ids = array_map(function ($r) { return intval($r['ima_fil_file_id']); }, array_values($pparts));

	// ------------------------------------------------------------ the raise
	section('Private → Fortress: the deferred raise');

	check(mailbox_protection_backlog_count(intval($domain->key)) === 1, 'at Private the Standard row is the seal backlog');
	mailbox_protection_seal_batch($domain);
	$std_private = fortress_row($std);
	check($std_private['iem_content_sealed'] === true, 'the Private seal batch sealed the Standard row to the server key');
	// Put it back to Standard content so the raise meets a Standard row too.
	InboundEmailMessage::unsealAndPersistContent(new InboundEmailMessage($std, TRUE));
	check(fortress_row($std)['iem_content_sealed'] === false, 'and unsealing it again gives a Standard row for the raise');

	$domain = flc_level($domain, InboundEmailDomain::LEVEL_FORTRESS);
	check(MailboxFortressLevel::backlogCount($owner_id) === 2 && MailboxFortressLevel::hasRaiseWork($owner_id),
		'at Fortress both messages wait to move', (string)MailboxFortressLevel::backlogCount($owner_id));
	check(mailbox_protection_backlog_count(intval($domain->key)) === 0, 'and the Private seal batch does not count the Standard one');
	$sealed_before = mailbox_protection_seal_batch($domain);
	check($sealed_before['sealed'] === 0 && fortress_row($std)['iem_content_sealed'] === false, 'nor seal it to the server key');

	// B24: a row that cannot move is stamped and passed by; a row sealed to
	// someone else's key is not this owner's to move at all.
	$bad = fortress_ingest($fx, 'Unmovable', 'wombatine');
	$db->prepare("UPDATE iem_inbound_email_messages SET iem_sealed_key = 'v1.seal.not-a-key', iem_content_sealed = true,
		iem_sealed_owner_user_id = ? WHERE iem_inbound_email_message_id = ?")->execute(array($owner_id, $bad));
	check(MailboxFortressLevel::backlogCount($owner_id) === 3 && MailboxFortressLevel::backlogCount($owner_id, 0, true) === 3,
		'three wait, all ready');
	$db->prepare('UPDATE iem_inbound_email_messages SET iem_sealed_owner_user_id = ? WHERE iem_inbound_email_message_id = ?')
		->execute(array($owner_id + 1000000, $priv));
	check(MailboxFortressLevel::backlogCount($owner_id, 0, true) === 2, 'a row on another owner\'s key is not ready');
	$db->prepare('UPDATE iem_inbound_email_messages SET iem_sealed_owner_user_id = ? WHERE iem_inbound_email_message_id = ?')
		->execute(array($owner_id, $priv));

	$moved = MailboxFortressLevel::drainRaise($owner_id, $key, microtime(true) + 60);
	check($moved === 2 && MailboxFortressLevel::backlogCount($owner_id) === 1, 'the raise moves both good rows past the bad one', (string)$moved);
	check((string)fortress_row($bad)['iem_fortress_move_attempt_time'] !== '' && !MailboxFortressLevel::hasRaiseWork($owner_id)
		&& MailboxFortressLevel::backlogCount($owner_id, 0, true) === 0,
		'the bad row is stamped, and no pass takes it again for a while');
	$bad_files = array_filter(array_map(function ($r) { return intval($r['ima_fil_file_id']); }, flc_parts($bad)));
	foreach (array('DELETE FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id = ?',
			'DELETE FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?') as $sql) {
		$db->prepare($sql)->execute(array($bad));
	}
	foreach ($bad_files as $fid) { (new File($fid, TRUE))->permanent_delete(); }
	check(MailboxFortressLevel::backlogCount($owner_id) === 0, 'with it gone, nothing waits');

	foreach (array('Private' => array($priv, 'Private quarterly numbers', 'zebracorn'), 'Standard' => array($std, 'Standard lemonade', 'quokkafig')) as $label => $want) {
		list($mid, $subject, $word) = $want;
		$row = fortress_row($mid);
		check(strpos((string)$row['iem_sealed_key'], 'v1.edgeseal.mail.') === 0 && InboundEmailMessage::isBrowserSealed($row),
			$label . ': the key is sealed to the mail vault');
		$dek = fortress_open_dek($fx, (string)$row['iem_sealed_key']);
		check(fortress_open_field($fx, (string)$row['iem_subject'], $dek, 'mail:' . $mid . ':iem_subject') === $subject,
			$label . ': the owner\'s device opens the subject');
		$search = fortress_open_field($fx, (string)$row['iem_search_text'], $dek, 'mail:' . $mid . ':iem_search_text');
		if (strncmp($search, 'gz:', 3) === 0) { $search = (string)gzdecode(base64_decode(substr($search, 3))); }
		check(strpos($search, $word) !== false && strpos($search, 'figures.csv') !== false, $label . ': search text sealed beside it, names included');
		check(strpos(fortress_open_field($fx, (string)$row['iem_snippet'], $dek, 'mail:' . $mid . ':iem_snippet'), $word) !== false,
			$label . ': and the snippet');
		check((string)$row['iem_search_written_time'] !== '', $label . ': the search catch-up time is stamped');
		check((string)$row['iem_raw_message'] === '' && (string)$row['iem_raw_storage_key'] === '', $label . ': no raw kept');
		$manifest = json_decode(fortress_open_field($fx, (string)$row['iem_attachment_manifest'], $dek, 'mail:' . $mid . ':iem_attachment_manifest'), true);
		$names = array_column(is_array($manifest) ? $manifest : array(), 'filename');
		sort($names);
		check($names === array('figures.csv', 'logo.png', 'quarterly-report.pdf'), $label . ': the manifest names the three parts', json_encode($names));
		$ok = true;
		foreach (flc_parts($mid) as $part => $r) {
			$file = new File(intval($r['ima_fil_file_id']), TRUE);
			if ($r['ima_filename'] !== '' || $r['ima_content_type'] !== '' || (string)$r['ima_content_id'] !== ''
					|| !$file->key || $file->get('fil_type') !== 'application/octet-stream' || $file->get('fil_content_sealed')) {
				$ok = false;
				continue;
			}
			$plain = fortress_open_field($fx, (string)$file->read_bytes('original'), $dek, 'mail:' . $mid . ':att:' . $part);
			if (intval($r['ima_size_bytes']) !== strlen($plain)) { $ok = false; }
		}
		check($ok, $label . ': every part is re-stored nameless and opens under the message DEK');
	}
	$pdf_new = null;
	foreach (flc_parts($priv) as $part => $r) { if (intval($r['ima_inbound_message_attachment_id']) === intval($pdf['ima_inbound_message_attachment_id'])) { $pdf_new = $r; } }
	$dek = fortress_open_dek($fx, (string)fortress_row($priv)['iem_sealed_key']);
	check(fortress_open_field($fx, (string)(new File(intval($pdf_new['ima_fil_file_id']), TRUE))->read_bytes('original'), $dek,
		'mail:' . $priv . ':att:' . $pdf_new['ima_mime_part']) === '%PDF-1.4 fortress report', 'the shape (b) PDF became shape (a) with its bytes');
	$gone = 0;
	foreach ($old_file_ids as $fid) { if (!(new File($fid, TRUE))->key) { $gone++; } }
	check($gone === count($old_file_ids), 'the old Files are gone');
	$found = $leaks(array($priv, $std));
	check(empty($found), 'no plaintext of either message on the server', implode(', ', $found));

	// ------------------------------------------------------------ the lowering
	section('Fortress → Private: the browser moves the keys back');

	$still = VaultCustodyChange::page($owner_id, 'mail', '', 0, 100);
	check(!array_intersect(array_column($still['rows'], 'id'), array($priv, $std)) && $still['remaining'] === 0,
		'while the domain is Fortress the walk lists none of its messages');
	$domain = flc_level($domain, InboundEmailDomain::LEVEL_PRIVATE);
	check(MailboxFortressLevel::loweringBacklogCount($owner_id, intval($domain->key)) === 2, 'two messages wait for the browser');
	$page = VaultCustodyChange::page($owner_id, 'mail', '', 0, 100);
	$rows = array();
	while (true) {
		foreach ($page['rows'] as $r) { if (in_array($r['id'], array($priv, $std), true)) { $rows[] = $r; } }
		if (!$page['next']) { break; }
		$page = VaultCustodyChange::page($owner_id, 'mail', $page['next']['model'], $page['next']['after_id'], 100);
	}
	check(count($rows) === 2 && $rows[0]['target_scope'] === 'user', 'the walk lists both, bound for the user vault');
	$crypto = new VaultCrypto();
	$out = array();
	foreach ($rows as $r) {
		$out[] = array('model' => $r['model'], 'id' => $r['id'],
			'sealed_dek' => $crypto->sealItemDekToBrowserKey(fortress_open_dek($fx, $r['sealed_dek']), $r['target_public_key'], 'user'));
	}
	check(VaultCustodyChange::accept($owner_id, 'mail', $out) === 2, 'and stores both keys');
	check(MailboxFortressLevel::loweringBacklogCount($owner_id, intval($domain->key)) === 0, 'nothing left to move');

	$lowered = new InboundEmailMessage($priv, TRUE);
	check(!InboundEmailMessage::isBrowserSealed($lowered) && $lowered->get('iem_subject') === 'Private quarterly numbers',
		'in the window the server reads the lowered message');
	check(MailboxFortressLevel::hasSettleWork($owner_id), 'its attachment names wait for the settle pass');
	// B22: a server rotation before the settle re-seals the key into the
	// server's own format; the row must still settle.
	$std_dek = $crypto->openItemDek((string)fortress_row($std)['iem_sealed_key'], $key);
	$db->prepare('UPDATE iem_inbound_email_messages SET iem_sealed_key = ? WHERE iem_inbound_email_message_id = ?')
		->execute(array($crypto->sealItemDek($std_dek, (string)$sv->get('uev_public_key')), $std));
	check(strpos((string)fortress_row($std)['iem_sealed_key'], 'v1.seal.') === 0, 'a rotation re-sealed one lowered key into v1.seal.');
	check(MailboxFortressLevel::drainSettle($owner_id, $key, microtime(true) + 30) === 2, 'which settles both, the rotated one too');
	$settled = fortress_row($std);
	check($settled['iem_attachment_manifest'] === null && $settled['iem_search_text'] === null && $settled['iem_snippet'] === null,
		'settled, the row keeps no Fortress-only column');
	$names = array();
	foreach (flc_parts($std) as $r) { $names[] = $r['ima_filename']; }
	sort($names);
	check($names === array('figures.csv', 'logo.png', 'quarterly-report.pdf'), 'the rotated row\'s names are back too', json_encode($names));
	$names = array();
	foreach (flc_parts($priv) as $r) { $names[] = $r['ima_filename']; }
	sort($names);
	check($names === array('figures.csv', 'logo.png', 'quarterly-report.pdf'), 'the names are back on the parts', json_encode($names));
	$csv = null;
	foreach (flc_parts($priv) as $r) { if ($r['ima_filename'] === 'figures.csv') { $csv = $r; } }
	$got = mailbox_retrieve_attachment_bytes(new InboundMessageAttachment(intval($csv['ima_inbound_message_attachment_id']), TRUE),
		new InboundEmailMessage($priv, TRUE));
	check($got['ok'] && empty($got['browser_sealed']) && $got['content'] === "quarter,revenue\nq3,1200\n",
		'and a part downloads through the server', (string)($got['error'] ?? ''));
	check(!MailboxFortressLevel::hasSettleWork($owner_id), 'nothing left to settle');

	// ------------------------------------------------------------ to Standard
	section('Private → Standard: a lowered row unseals');

	$domain = flc_level($domain, InboundEmailDomain::LEVEL_STANDARD);
	$res = mailbox_protection_unseal_batch($domain, $owner_id);
	$srow = fortress_row($priv);
	check($res['unsealed'] >= 1 && $srow['iem_content_sealed'] === false && $srow['iem_subject'] === 'Private quarterly numbers',
		'the unseal pass takes it to plaintext', json_encode($res));
	$csv_file = new File(intval($csv['ima_fil_file_id']), TRUE);
	check($csv_file->read_bytes('original') === "quarter,revenue\nq3,1200\n", 'its parts too');
	check($srow['iem_search_text'] === null && $srow['iem_snippet'] === null && $srow['iem_attachment_manifest'] === null
		&& $srow['iem_search_written_time'] === null, 'and it keeps no Fortress-only column');

} catch (\Throwable $e) {
	check(false, 'EXCEPTION', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

VaultUnlock::lockAll($fx['owner_id'] ?? 0);
harness_finish();
