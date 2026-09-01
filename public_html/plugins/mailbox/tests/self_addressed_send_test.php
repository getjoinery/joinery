<?php
/** @joinery-test
 * name: self_addressed_send
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A message you send to yourself is ONE message
 * (specs/bugfix_self_addressed_send.md).
 *
 * The defect this pins: a send to the mailbox that composed it left two rows
 * carrying one Message-ID — MailboxSender's Sent copy, and the copy the message
 * came back as when it completed the trip out through MX and in through Postfix.
 * They shared a thread key, so opening the conversation showed the same message
 * twice: once tagged Sent, once reading as a reply to itself. The dedup that
 * should have caught it never could — its key is (Message-ID, recipient,
 * direction), and the directions differ by construction.
 *
 * What is asserted:
 *
 *  - The delivery reconciles onto the composer's row: no second row, the flag
 *    that puts that one row in the Inbox, and the delivery's authentication
 *    verdicts adopted onto a row that had only placeholders.
 *  - It does not over-reach: ordinary mail, a delivery whose Sent copy was
 *    thrown away, and a same-Message-ID row in a DIFFERENT mailbox all still
 *    store their own row.
 *  - The reader shows that one row in the Inbox AND in Sent, and the
 *    conversation holds one message.
 *  - Sealing posture: composing asks the MAILBOX whether to seal, not whether
 *    its owner happens to hold a vault. A Standard mailbox whose owner holds
 *    one used to store sealed Sent mail beside plaintext delivered mail —
 *    unreadable outside the unlock window, and outside search, on a mailbox
 *    nobody asked to encrypt.
 *
 * Run: php tests/run.php db --filter=self_addressed_send
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxSender.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));

$suffix = bin2hex(random_bytes(4));
$db = DbConnector::get_instance()->get_db_link();

/** A live Standard domain, registered for teardown. */
function sas_domain(string $suffix): InboundEmailDomain {
	$domain = new InboundEmailDomain(NULL);
	$domain->set('ied_domain', 'sas-' . $suffix . '.example');
	$domain->set('ied_is_enabled', true);
	$domain->set('ied_security_level', InboundEmailDomain::LEVEL_STANDARD);
	$domain->save();
	$domain->load();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($domain->key));
	return $domain;
}

/** A live store-mode mailbox on $domain. */
function sas_alias(InboundEmailDomain $domain, string $local): InboundEmailAlias {
	$alias = new InboundEmailAlias(NULL);
	$alias->set('iea_ied_inbound_email_domain_id', intval($domain->key));
	$alias->set('iea_alias', $local);
	$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
	$alias->set('iea_destinations', '');
	$alias->set('iea_is_enabled', true);
	$alias->save();
	$alias->load();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', intval($alias->key));
	return $alias;
}

/** The composer's Sent row, as MailboxSender writes one. */
function sas_composer_row(InboundEmailAlias $alias, string $message_id): int {
	$db = DbConnector::get_instance()->get_db_link();
	$address = strtolower($alias->get_full_address());
	$stmt = $db->prepare("INSERT INTO iem_inbound_email_messages
		(iem_ied_inbound_email_domain_id, iem_iea_inbound_email_alias_id, iem_sender, iem_recipient,
		 iem_subject, iem_body_plain, iem_message_id_header, iem_thread_key, iem_direction,
		 iem_is_read, iem_dkim_result, iem_spf_result, iem_dmarc_result, iem_auth_source,
		 iem_received_time, iem_create_time)
		VALUES (?, ?, ?, ?, 'A note to myself', 'the body', ?, ?, 'outbound',
		 true, 'unverified', 'unverified', 'unverified', 'none', now(), now())
		RETURNING iem_inbound_email_message_id");
	$stmt->execute(array(intval($alias->get('iea_ied_inbound_email_domain_id')), intval($alias->key),
		$address, $address, $message_id, $message_id));
	$id = intval($stmt->fetchColumn());
	harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', $id);
	return $id;
}

/** The wire form of a message addressed to $to, carrying $message_id. */
function sas_raw(string $from, string $to, string $message_id): string {
	return "From: $from\r\nTo: $to\r\nSubject: A note to myself\r\n"
		. "Message-ID: $message_id\r\n\r\nthe body\r\n";
}

/** How many live rows this mailbox holds. */
function sas_row_count(InboundEmailAlias $alias): int {
	$db = DbConnector::get_instance()->get_db_link();
	$stmt = $db->prepare('SELECT COUNT(*) FROM iem_inbound_email_messages
		WHERE iem_iea_inbound_email_alias_id = ? AND iem_delete_time IS NULL');
	$stmt->execute(array(intval($alias->key)));
	return intval($stmt->fetchColumn());
}

/** One column off a message row. */
function sas_col(int $message_id, string $column) {
	$db = DbConnector::get_instance()->get_db_link();
	$stmt = $db->prepare("SELECT $column FROM iem_inbound_email_messages
		WHERE iem_inbound_email_message_id = ?");
	$stmt->execute(array($message_id));
	return $stmt->fetchColumn();
}

/** The delivery verdicts Postfix's milter hands the router. */
function sas_auth(): array {
	return array('dkim' => 'pass', 'spf' => 'pass', 'dmarc' => 'pass', 'source' => 'milter');
}

try {

	$router  = new InboundEmailRouter();
	$domain  = sas_domain($suffix);
	$alias   = sas_alias($domain, 'me');
	$address = strtolower($alias->get_full_address());

	// The owner holds a vault. On a Standard mailbox that must change nothing —
	// it is exactly the condition that used to seal the Sent copy alone.
	$owner = make_user('SelfSend' . $suffix);
	$keys  = sodium_crypto_box_keypair();
	$vault = new UserEncryptionVault(NULL);
	$vault->set('uev_usr_user_id', intval($owner->key));
	$vault->set('uev_public_key', SealedBox::b64url(sodium_crypto_box_publickey($keys)));
	$vault->set('uev_salt', SealedBox::b64url(random_bytes(16)));
	$vault->save();
	$vault->load();
	harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', intval($vault->key));

	$grant = new InboundEmailMailboxGrant(NULL);
	$grant->set('ieg_iea_inbound_email_alias_id', intval($alias->key));
	$grant->set('ieg_usr_user_id', intval($owner->key));
	$grant->save();
	$grant->load();
	harness_register_row('ieg_inbound_email_mailbox_grants',
		'ieg_inbound_email_mailbox_grant_id', intval($grant->key));

	// -----------------------------------------------------------------------
	section('the delivery reconciles onto the composer row');

	$mid = '<sas-self-' . $suffix . '@sas.example>';
	$composer_id = sas_composer_row($alias, $mid);
	$before = sas_row_count($alias);

	$raw = sas_raw($address, $address, $mid);
	$result = $router->storeMessage($raw, $router->parseEmail($raw), $alias, $domain,
		$address, sas_auth(), array('signal' => 'none', 'score' => null));

	check(!empty($result['dedup']) && $result['message'] === null,
		'the delivered copy of a self-send reports as a dedup, storing nothing');
	check(sas_row_count($alias) === $before,
		'no second row — the conversation cannot show the message twice',
		'rows before ' . $before . ', after ' . sas_row_count($alias));
	check((bool)sas_col($composer_id, 'iem_self_delivered'),
		'the composer row is marked self-delivered — what puts it in the Inbox');
	check((string)sas_col($composer_id, 'iem_direction') === 'outbound',
		'and stays outbound: it is still the message the member sent');
	check((string)sas_col($composer_id, 'iem_dkim_result') === 'pass'
		&& (string)sas_col($composer_id, 'iem_auth_source') === 'milter',
		'it adopts the delivery verdicts, which only the delivery could know');
	check((bool)sas_col($composer_id, 'iem_is_read'),
		'and is not turned unread — the member wrote it');

	// Idempotent: a retried delivery (Postfix redelivers on any transient error)
	// must not fork a row on the second pass either.
	$again = $router->storeMessage($raw, $router->parseEmail($raw), $alias, $domain,
		$address, sas_auth(), array('signal' => 'none', 'score' => null));
	check(!empty($again['dedup']) && sas_row_count($alias) === $before,
		'a redelivery reconciles again rather than forking a row');

	// Direct is a store path too, and discovery can resolve a domain this very
	// deployment hosts — so the same message can leave over the channel and
	// arrive back here. A path that opted out would duplicate on one transport
	// and not the other.
	$direct_mid = '<sas-direct-' . $suffix . '@sas.example>';
	$direct_composer = sas_composer_row($alias, $direct_mid);
	$direct_before = sas_row_count($alias);
	$dres = $router->storeDirectMessage(
		array('sender' => $address, 'subject' => 'A note to myself', 'message_id' => $direct_mid),
		array('body_plain' => 'the body', 'body_html' => '', 'attachments' => array()),
		$alias, $domain, $address, true);
	check(!empty($dres['dedup']) && sas_row_count($alias) === $direct_before,
		'a self-send arriving over Direct reconciles too, storing no second row');
	check((bool)sas_col($direct_composer, 'iem_self_delivered')
		&& (string)sas_col($direct_composer, 'iem_auth_source') === 'joinery_direct',
		'onto the composer row, which records what actually vouched for it');

	// -----------------------------------------------------------------------
	section('it reaches for nothing else');

	// Ordinary incoming mail is untouched by any of this.
	$ext_mid = '<sas-ext-' . $suffix . '@elsewhere.example>';
	$ext_raw = sas_raw('someone@elsewhere.example', $address, $ext_mid);
	$ext = $router->storeMessage($ext_raw, $router->parseEmail($ext_raw), $alias, $domain,
		$address, sas_auth(), array('signal' => 'none', 'score' => null));
	check(empty($ext['dedup']) && !empty($ext['message']),
		'a message from outside stores its own row, as always');
	if (!empty($ext['message'])) {
		harness_register_model('InboundEmailMessage', intval($ext['message']->key));
		check(!sas_col(intval($ext['message']->key), 'iem_self_delivered'),
			'and carries no self-delivered flag');
	}

	// A composed row the member threw away is not a copy to reconcile onto: the
	// delivery is then the only copy they have, and must land.
	$trashed_mid = '<sas-trashed-' . $suffix . '@sas.example>';
	$trashed_id = sas_composer_row($alias, $trashed_mid);
	$db->prepare('UPDATE iem_inbound_email_messages SET iem_delete_time = now()
		WHERE iem_inbound_email_message_id = ?')->execute(array($trashed_id));
	$traw = sas_raw($address, $address, $trashed_mid);
	$tres = $router->storeMessage($traw, $router->parseEmail($traw), $alias, $domain,
		$address, sas_auth(), array('signal' => 'none', 'score' => null));
	check(empty($tres['dedup']) && !empty($tres['message']),
		'a delivery whose Sent copy was discarded stores on its own');
	if (!empty($tres['message'])) {
		harness_register_model('InboundEmailMessage', intval($tres['message']->key));
	}

	// The lookup is scoped to ONE mailbox. A neighbour's row with the same
	// Message-ID must never swallow this mailbox's delivery.
	$neighbour = sas_alias($domain, 'someone-else');
	$shared_mid = '<sas-shared-' . $suffix . '@sas.example>';
	sas_composer_row($neighbour, $shared_mid);
	$nraw = sas_raw($address, $address, $shared_mid);
	$nres = $router->storeMessage($nraw, $router->parseEmail($nraw), $alias, $domain,
		$address, sas_auth(), array('signal' => 'none', 'score' => null));
	check(empty($nres['dedup']) && !empty($nres['message']),
		'a composed row in another mailbox does not capture this delivery');
	if (!empty($nres['message'])) {
		harness_register_model('InboundEmailMessage', intval($nres['message']->key));
	}

	// -----------------------------------------------------------------------
	section('the reader shows that one row in both views');

	$service = new MailboxService(MailboxViewer::forUser(intval($owner->key), 0));

	$in_inbox = false;
	foreach ($service->listThreads(intval($alias->key), array('inbox' => true), 1, 50)['threads'] as $t) {
		if ($t['thread_key'] === $mid) { $in_inbox = intval($t['msg_count']); }
	}
	check($in_inbox === 1,
		'the Inbox lists the self-send once, as one message',
		'msg_count: ' . var_export($in_inbox, true));

	$in_sent = false;
	foreach ($service->listThreads(intval($alias->key), array('sent' => true), 1, 50)['threads'] as $t) {
		if ($t['thread_key'] === $mid) { $in_sent = intval($t['msg_count']); }
	}
	check($in_sent === 1, 'and Sent lists it too — it is in both, like every mail client',
		'msg_count: ' . var_export($in_sent, true));

	check(count($service->messageIdsInThread(intval($alias->key), $mid)) === 1,
		'opening the conversation shows one message, not the same one twice');

	// -----------------------------------------------------------------------
	section('composing asks the mailbox whether to seal');

	$seal = MailboxSender::sealTargetFor($alias);
	check($seal['sealing'] === false && $seal['vault'] === null,
		'a Standard mailbox does not seal its Sent copy, though its owner holds a vault');

	// The same resolver says yes the moment the mailbox actually seals, so this
	// is posture being read — not sealing quietly switched off everywhere.
	$alias->set('iea_security_level', InboundEmailDomain::LEVEL_PRIVATE);
	$alias->save();
	$raised = new InboundEmailAlias(intval($alias->key), TRUE);
	$seal = MailboxSender::sealTargetFor($raised);
	check($seal['sealing'] === true && $seal['vault'] !== null,
		'and a Private mailbox does seal it, to the owner who holds the key');
	check(intval($seal['owner_id']) === intval($owner->key), 'sealed to that mailbox owner');

} finally {
	harness_finish();
}
