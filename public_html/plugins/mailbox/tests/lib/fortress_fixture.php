<?php
/**
 * Shared fixtures for the Fortress mail suites (specs/client_custody_mail.md).
 * Not a test file (no @joinery-test header) — required by a suite after
 * harness_boot() and harness_test_mode(), with tests/lib/vault_fixtures.php.
 *
 * fortress_fixture() builds an owner holding a client-custody `mail` vault from
 * a keypair only the test knows, a Fortress domain they own, and one store-mode
 * mailbox granted to them, and registers all of it for teardown.
 * fortress_ingest() delivers a message with two attachments and an inline
 * image through the real MTA path. fortress_open_*() open what was stored the
 * way the owner's browser would.
 *
 * @version 1.0
 */

/**
 * @return array{owner_id:int, pair:array, pub:string, box:SealedBox, domain:InboundEmailDomain,
 *               alias:InboundEmailAlias, address:string, file_ids:array}
 */
function fortress_fixture(string $label = 'Fx'): array {
	$db = DbConnector::get_instance()->get_db_link();
	$box = new SealedBox();
	$pair = $box->generateKeypair();
	$pub = base64_encode(SealedBox::b64url_decode($pair['public']));

	$owner = make_user('Fortress' . $label);
	$owner_id = intval($owner->key);
	vault_fixture_client_vault($owner_id, $pub, InboundEmailMessage::SEAL_SCOPE_FORTRESS);

	$domain_name = 'harnesstest-fx-' . bin2hex(random_bytes(4)) . '.example';
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

	$fx = array(
		'owner_id' => $owner_id, 'pair' => $pair, 'pub' => $pub, 'box' => $box,
		'domain' => $domain, 'alias' => $alias, 'address' => 'harnesstest_box@' . $domain_name,
	);
	// Everything hanging off the mailbox, children first; the Files behind its
	// attachments go through the model so their blobs are released.
	harness_defer(function () use ($db, $alias) {
		$aid = intval($alias->key);
		$files = $db->prepare('SELECT ima_fil_file_id FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id IN
			(SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages WHERE iem_iea_inbound_email_alias_id = ?)
			AND ima_fil_file_id IS NOT NULL');
		$files->execute(array($aid));
		$file_ids = $files->fetchAll(PDO::FETCH_COLUMN);
		foreach (array(
			'DELETE FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id IN
				(SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages WHERE iem_iea_inbound_email_alias_id = ?)',
			'DELETE FROM ieg_inbound_email_mailbox_grants WHERE ieg_iea_inbound_email_alias_id = ?',
			'DELETE FROM iel_inbound_email_logs WHERE iel_iea_inbound_email_alias_id = ?',
			'DELETE FROM mst_mailbox_send_attempts WHERE mst_iea_inbound_email_alias_id = ?',
			'DELETE FROM iem_inbound_email_messages WHERE iem_iea_inbound_email_alias_id = ?',
		) as $sql) {
			try { $db->prepare($sql)->execute(array($aid)); } catch (\Throwable $e) {}
		}
		foreach ($file_ids as $fid) {
			try { $f = new File(intval($fid), TRUE); if ($f->key) { $f->permanent_delete(); } } catch (\Throwable $e) {}
		}
	});
	return $fx;
}

/** A multipart message: plain + HTML body with an inline cid: image, a PDF and a CSV. */
function fortress_raw(array $fx, string $message_id, string $subject = 'Fortress quarterly numbers',
		string $body_word = 'zebracorn'): string {
	$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
	$line = 'The ' . $body_word . ' figures for the quarter are attached. ';
	return implode("\r\n", array(
		'From: "Secret Sender Name" <sender@elsewhere.example>',
		'To: ' . $fx['address'],
		'Subject: ' . $subject,
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
		str_repeat($line, 5),
		'--ALT',
		'Content-Type: text/html; charset=UTF-8',
		'',
		'<p>' . str_repeat($line, 5) . '</p><img src="cid:logo123@elsewhere.example">',
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
		chunk_split(base64_encode('%PDF-1.4 fortress report')),
		'--OUT',
		'Content-Type: text/csv; name="figures.csv"',
		'Content-Disposition: attachment; filename="figures.csv"',
		'Content-Transfer-Encoding: base64',
		'',
		chunk_split(base64_encode("quarter,revenue\nq3,1200\n")),
		'--OUT--',
		'',
	));
}

/** Deliver fortress_raw() through the MTA path; returns the stored row id (0 on failure). */
function fortress_ingest(array $fx, string $subject = 'Fortress quarterly numbers', string $body_word = 'zebracorn'): int {
	$message_id = '<fx-' . bin2hex(random_bytes(6)) . '@elsewhere.example>';
	$router = new InboundEmailRouter();
	$router->processEmail(fortress_raw($fx, $message_id, $subject, $body_word), $fx['address']);
	$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
		WHERE iem_iea_inbound_email_alias_id = ? AND iem_message_id_header = ?');
	$q->execute(array(intval($fx['alias']->key), $message_id));
	return intval($q->fetchColumn());
}

/** The stored row. */
function fortress_row(int $id): array {
	$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT * FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
	$q->execute(array($id));
	return $q->fetch(PDO::FETCH_ASSOC) ?: array();
}

/** A sealed row's DEK, opened as the owner's browser opens it. */
function fortress_open_dek(array $fx, string $sealed_dek): string {
	return $fx['box']->openEdge(substr($sealed_dek, strlen('v1.edgeseal.mail.')), $fx['pair']['secret'], $fx['pub']);
}

/** One `v1.edge.` value opened under $dek and $ad. */
function fortress_open_field(array $fx, string $value, string $dek, string $ad): string {
	return $fx['box']->aeadDecryptGcm(substr($value, strlen('v1.edge.')), $dek, $ad);
}
