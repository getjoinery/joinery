<?php
/** @joinery-test
 * name: sealed_reply_store
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A reply or forward on a Private mailbox stores its Sent copy and its send
 * attempt.
 *
 * Replying opens the sealed original to quote it, so by the time the Sent copy
 * is written the process has read sealed content, and SealedEgressGuard refuses
 * any INSERT carrying a plain string longer than 64 characters. A Message-ID is
 * often that long — four in ten on a real mailbox — and the Sent row carries
 * two: the original's thread key and its own. The defect this pins: a reply
 * sent before any draft was saved had its Sent row refused AFTER the carrier
 * had taken the message, and the person was told "An unexpected error
 * prevented sending." about mail that had gone.
 *
 * What is asserted, with the process holding sealed plaintext:
 *
 *  - a reply with no saved draft stores its Sent row, sealed, carrying the
 *    original's long thread key and its own long Message-ID;
 *  - a reply from a saved sealed draft, and from a draft that was still
 *    plaintext, morphs the draft into the same;
 *  - an upload persists on the Sent copy;
 *  - the send attempt is recorded, with the carrier's receipt;
 *  - a Sent copy that cannot be stored is reported as sent, never as a
 *    failure, and names a reference that finds the log line.
 *
 * Run: php tests/run.php db --only=plugins/mailbox/tests/sealed_reply_store_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');
require_once(__DIR__ . '/lib/mailbox_test_fixture.php');

if (!PluginHelper::isPluginActive('mailbox')) {
	harness_skip('mailbox plugin inactive');
	harness_finish();
}
if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}
// Reading the sealed original needs a real unlock window, which lives in APCu
// against a session id.
if (!vault_apcu_usable()) {
	harness_skip('APCu unavailable in this process',
		'run manually: php -d apc.enable_cli=1 plugins/mailbox/tests/sealed_reply_store_test.php');
	harness_finish();
}
if (!vault_ensure_session()) {
	harness_skip('could not start a CLI session');
	harness_finish();
}

$db = DbConnector::get_instance()->get_db_link();
$box = new SealedBox();
$suffix = substr(md5(uniqid('srs', true)), 0, 8);
// Long enough that the Sent copy's own Message-ID passes 64 characters too.
$domain_name = 'sealed-reply-store-' . $suffix . '.example';
mailbox_purge_domains('sealed-reply-store-%');
harness_defer(function () { mailbox_purge_domains('sealed-reply-store-%'); });

// ---- Fixtures ------------------------------------------------------------

$uid = mailbox_make_user('srs-' . $suffix . '@' . $domain_name);
harness_register_row('usr_users', 'usr_user_id', $uid);

$kp = $box->generateKeypair();
$vault = new UserEncryptionVault(NULL);
$vault->set('uev_usr_user_id', $uid);
$vault->set('uev_scope', UserEncryptionVault::SCOPE_USER);
$vault->set('uev_custody', UserEncryptionVault::CUSTODY_SERVER);
$vault->set('uev_public_key', $kp['public']);
$vault->set('uev_salt', $box->generateSalt());
$vault->set('uev_key_generation', 1);
$vault->save();
$vault->load();
harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', (int)$vault->key);

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', $domain_name);
$domain->set('ied_is_enabled', true);
$domain->set('ied_security_level', InboundEmailDomain::LEVEL_PRIVATE);
$domain->save();
$domain_id = (int)$domain->key;

$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', $domain_id);
$alias->set('iea_alias', 'me');
$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
$alias->set('iea_is_enabled', true);
$alias->prepare();
$alias->save();
$alias->load();
$alias_id = (int)$alias->key;

$grant = new InboundEmailMailboxGrant(NULL);
$grant->set('ieg_iea_inbound_email_alias_id', $alias_id);
$grant->set('ieg_usr_user_id', $uid);
$grant->save();

harness_defer(function () use ($uid) { VaultUnlock::lockAll($uid); });

$seal = MailboxSender::sealTargetFor($alias);
check($seal['sealing'] === true && (int)$seal['owner_id'] === $uid, 'the fixture mailbox seals to its one member');

// A Gmail-shaped Message-ID: 84 characters, the length of the failing original.
$long_id = function (string $tag) use ($suffix): string {
	return '<CAB' . $tag . str_repeat('x', 60 - strlen($tag)) . $suffix . '@mail.gmail.com>';
};

/** A stored message, sealed the way ingest seals one: insert hollow, then seal. */
$make_sealed = function (string $direction, string $message_id, string $thread_key) use ($db, $domain_id, $alias_id, $uid, $vault) {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', $domain_id);
	$m->set('iem_iea_inbound_email_alias_id', $alias_id);
	$m->set('iem_direction', $direction);
	$m->set('iem_recipient', $direction === 'inbound' ? 'me@example.test' : '');
	$m->set('iem_sender', '');
	$m->set('iem_subject', '');
	$m->set('iem_body_plain', '');
	$m->set('iem_body_html', '');
	$m->set('iem_message_id_header', $message_id);
	$m->set('iem_thread_key', $thread_key);
	if ($direction === 'draft') {
		$m->set('iem_draft_author_user_id', $uid);
	}
	$m->set('iem_received_time', gmdate('Y-m-d H:i:s'));
	$m->save();
	$id = (int)$m->key;
	$dek = InboundEmailMessage::sealAndPersistContent($id, $vault, 'Pat <pat@elsewhere.test>',
		'me@example.test', 'Coffee in Brooklyn on October 29?', 'Are you free that morning?',
		'<p>Are you free that morning?</p>', $direction !== 'inbound');
	return array('id' => $id, 'dek' => $dek);
};

$original_id = $long_id('orig');
$original = $make_sealed('inbound', $original_id, $original_id);
check(strlen($original_id) > SealedEgressGuard::THRESHOLD, 'the original\'s Message-ID is longer than the guard\'s threshold', strlen($original_id) . ' characters');

// Two drafts of the reply, saved by an earlier request (an autosave) that never
// opened anything: one sealed, and one still plaintext — begun under a Standard
// mailbox and moved here by a From change, so nothing protects its row until
// the send does.
$draft = $make_sealed('draft', '', $original_id);
$plain = new InboundEmailMessage(NULL);
$plain->set('iem_ied_inbound_email_domain_id', $domain_id);
$plain->set('iem_iea_inbound_email_alias_id', $alias_id);
$plain->set('iem_direction', 'draft');
$plain->set('iem_draft_author_user_id', $uid);
$plain->set('iem_received_time', gmdate('Y-m-d H:i:s'));
$plain->save();
$plain_id = (int)$plain->key;

// Everything above ran cold. Opening the original is what a reply does first.
vault_fixture_open_window($uid, $kp['secret'], UserEncryptionVault::SCOPE_USER, array('idle' => null, 'absolute' => null));
$source = new InboundEmailMessage($original['id'], TRUE);
check($source->get('iem_subject') === 'Coffee in Brooklyn on October 29?', 'the sealed original opens in the window');
check(SealedEgressGuard::isHot(), 'and the process now holds sealed plaintext, as a reply does');

// Teardown writes from this now-hot process (the vault audit row among them);
// the guard's one test-only switch lets it. Registered after the fixtures, so
// it runs before their teardown.
harness_defer(function () { SealedEgressGuard::setArmed(false); });

$sender = new MailboxSender(MailboxViewer::forUser($uid, 10));
$store = new ReflectionMethod('MailboxSender', 'storeOutboundRow');
$make_id = new ReflectionMethod('MailboxSender', 'generateMessageId');

$from = 'me@' . $domain_name;
$to = array(array('email' => 'pat@elsewhere.test', 'name' => 'Pat'));
$reply = function () {
	$email = new EmailMessage();
	$email->html('<p>Yes, see you there.</p><blockquote>Are you free that morning?</blockquote>');
	$email->text("Yes, see you there.\n\n> Are you free that morning?");
	return $email;
};

$row_of = function (int $id) use ($db): array {
	$q = $db->prepare('SELECT iem_direction, iem_content_sealed, iem_sealed_owner_user_id, iem_message_id_header,
		iem_thread_key, iem_draft_author_user_id FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
	$q->execute(array($id));
	return $q->fetch(PDO::FETCH_ASSOC) ?: array();
};

// =========================================================================
section('A reply with no saved draft stores its Sent row');
// =========================================================================

$message_id = $make_id->invoke($sender, $from);
check(strlen($message_id) > SealedEgressGuard::THRESHOLD, 'the reply\'s own Message-ID is long too', strlen($message_id) . ' characters');
$stored = null;
try {
	$stored = $store->invoke($sender, $source, $alias, MailboxSender::MODE_REPLY, $from, $to, array(), array(),
		'RE: Coffee in Brooklyn on October 29?', $reply(), $message_id, null, null);
} catch (Throwable $e) {
	check(false, 'the Sent row is stored', get_class($e) . ': ' . $e->getMessage());
}
if ($stored !== null) {
	$row = $row_of((int)$stored['id']);
	check($row['iem_direction'] === 'outbound', 'the row is outbound');
	check($row['iem_content_sealed'] === true && (int)$row['iem_sealed_owner_user_id'] === $uid, 'sealed to the mailbox owner');
	check($row['iem_thread_key'] === $original_id, 'it joins the original\'s conversation (the long thread key landed)', (string)$row['iem_thread_key']);
	check($row['iem_message_id_header'] === $message_id, 'it carries its own Message-ID', (string)$row['iem_message_id_header']);
	$copy = new InboundEmailMessage((int)$stored['id'], TRUE);
	check($copy->get('iem_subject') === 'RE: Coffee in Brooklyn on October 29?', 'the sealed subject reads back');
	check(strpos((string)$copy->get('iem_body_plain'), 'see you there') !== false, 'and the body');

	// ---------------------------------------------------------------------
	section('An upload persists on the Sent copy');
	// ---------------------------------------------------------------------
	$persist = new ReflectionMethod('MailboxSender', 'persistOutboundUploads');
	$upload_name = 'Coffee shortlist.txt';
	$persist->invoke($sender, (int)$stored['id'], array(array('bytes' => "Devocion\nPartners\n", 'name' => $upload_name)), $stored['dek']);
	$atts = new MultiInboundMessageAttachment(array('message_id' => (int)$stored['id']));
	check(count($atts) === 1, 'the upload has its manifest row', count($atts) . ' rows');
	foreach ($atts as $att) {
		// The domain purge removes the manifest row, not the private File behind it.
		$fid = (int)$att->get('ima_fil_file_id');
		harness_defer(function () use ($fid, $db) {
			try {
				$db->prepare('DELETE FROM ima_inbound_message_attachments WHERE ima_fil_file_id = ?')->execute(array($fid));
				$f = new File($fid, TRUE);
				if ($f->key) { $f->permanent_delete(); }
			} catch (\Throwable $e) {}
		});
		check($att->get('ima_filename') === $upload_name, 'under its full name');
		check((bool)$att->get('ima_is_sealed'), 'sealed like the message');
	}

	// ---------------------------------------------------------------------
	section('The send attempt is recorded');
	// ---------------------------------------------------------------------
	$record = new ReflectionMethod('MailboxSender', 'recordAttempt');
	$receipt = array('id' => '<' . str_repeat('9', 20) . '@smtp.example>',
		'response' => '250 2.0.0 OK  1727185714 d75a77b69052e-4a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d.12 - gsmtp');
	$record->invoke($sender,
		array('direct_delivered' => array(), 'transport' => 'mailgun', 'receipt' => $receipt, 'error' => null),
		MailboxSendAttempt::OUTCOME_SENT, null, (int)$stored['id'], MailboxSendAttempt::SENT_COPY_NOT_APPLICABLE,
		$alias, null, OutboundTransport::forHostedAlias($from), $source, null, $message_id, $from, $to, array(), array());
	$attempts = new MultiMailboxSendAttempt(array('message_id' => (int)$stored['id']));
	check(count($attempts) === 1, 'one attempt names the Sent row', count($attempts) . ' rows');
	foreach ($attempts as $a) {
		check($a->get('mst_message_id_header') === $message_id, 'keyed by the long Message-ID');
		check($a->json('mst_receipt') === $receipt, 'with the carrier\'s receipt');
		check((bool)$a->get('mst_content_sealed'), 'its recipients sealed');
		check(($a->json('mst_recipients')[0]['email'] ?? '') === 'pat@elsewhere.test', 'and readable in the window');
	}
}

// =========================================================================
section('A reply from a saved sealed draft morphs the draft');
// =========================================================================

$draft_row = new InboundEmailMessage($draft['id'], TRUE);
$message_id2 = $make_id->invoke($sender, $from);
try {
	$stored2 = $store->invoke($sender, $source, $alias, MailboxSender::MODE_REPLY, $from, $to, array(), array(),
		'RE: Coffee in Brooklyn on October 29?', $reply(), $message_id2, $draft_row, $draft['dek']);
	$row = $row_of((int)$stored2['id']);
	check((int)$stored2['id'] === $draft['id'], 'the draft row becomes the Sent row');
	check($row['iem_direction'] === 'outbound' && $row['iem_draft_author_user_id'] === null, 'outbound, no longer a draft');
	check($row['iem_content_sealed'] === true, 'still sealed');
	check($row['iem_thread_key'] === $original_id && $row['iem_message_id_header'] === $message_id2, 'threaded, with its Message-ID');
} catch (Throwable $e) {
	check(false, 'the sealed draft morphs', get_class($e) . ': ' . $e->getMessage());
}

// =========================================================================
section('A draft still in plaintext morphs into a sealed Sent row');
// =========================================================================

$plain_row = new InboundEmailMessage($plain_id, TRUE);
$message_id3 = $make_id->invoke($sender, $from);
try {
	$stored3 = $store->invoke($sender, $source, $alias, MailboxSender::MODE_REPLY, $from, $to, array(), array(),
		'RE: Coffee in Brooklyn on October 29?', $reply(), $message_id3, $plain_row, null);
	$row = $row_of((int)$stored3['id']);
	check($row['iem_direction'] === 'outbound' && $row['iem_content_sealed'] === true, 'outbound and sealed');
	check($row['iem_thread_key'] === $original_id && $row['iem_message_id_header'] === $message_id3, 'threaded, with its Message-ID');
} catch (Throwable $e) {
	check(false, 'the plaintext draft morphs', get_class($e) . ': ' . $e->getMessage());
}

// =========================================================================
section('A Sent copy that cannot be stored is reported as sent');
// =========================================================================

$warn = new ReflectionMethod('MailboxSender', 'unsavedCopyWarning');
$ref = MailboxSender::errorReference();
check((bool)preg_match('/^mbx-[0-9a-f]{6}$/', $ref), 'a reference is short and greppable', $ref);
$filed = $warn->invoke(null, MailboxSendAttempt::SENT_COPY_PROVIDER, $ref);
$lost = $warn->invoke(null, MailboxSendAttempt::SENT_COPY_NOT_APPLICABLE, $ref);
foreach (array('provider filed a copy' => $filed, 'no remote copy' => $lost) as $case => $text) {
	check(stripos($text, 'was sent') !== false, $case . ': says the message was sent', $text);
	check(stripos($text, 'not sent') === false && stripos($text, 'prevented') === false, $case . ': never reads as a failure', $text);
	check(strpos($text, $ref) !== false, $case . ': names the reference', $text);
}
check(stripos($filed, 'Sent folder') !== false, 'a filed copy says where the copy is', $filed);
check(stripos($lost, 'again') !== false, 'an unfiled copy warns against sending again', $lost);

harness_finish();
?>
