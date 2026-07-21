<?php
/** @joinery-test
 * name: lowering_unseal
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Lowering unseal (specs/mailbox_lowering_unseal.md): a downgrade converges
 * sealed history back to plaintext.
 *
 *  - Round trip: seal → lower → unseal restores content byte-for-byte
 *    (subject/body/sender, outbound recipient), clears the sealed flags and
 *    key wrapping, and unseals attachment Files (ima_is_sealed back to false).
 *  - Caller scoping: only the caller's own rows converge; another holder's
 *    rows count in others_remaining and stay sealed.
 *  - Window gating: a closed window unseals nothing and answers locked.
 *  - Sealing-domain refusal: a Private/Fortress domain is never unsealed.
 *  - Search key: aliasSealedContentActive() follows actual sealed content.
 *  - Lowering receipt render: progress/locked/completed/others variants.
 *  - mailbox/unseal_batch action refusals.
 *
 * Run: php tests/run.php db --filter=lowering_unseal
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protection_ceremony.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/unseal_batch_logic.php'));
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));

if (!vault_apcu_usable()) {
	harness_skip('APCu unavailable in this process', 'run manually: php -d apc.enable_cli=1 plugins/mailbox/tests/lowering_unseal_test.php');
	harness_finish();
}
if (!vault_ensure_session()) {
	harness_skip('could not start a CLI session');
	harness_finish();
}

function lu_vault(int $user_id, string $public_b64): UserEncryptionVault {
	$vault = new UserEncryptionVault(NULL);
	$vault->set('uev_usr_user_id', $user_id);
	$vault->set('uev_public_key', $public_b64);
	$vault->set('uev_salt', SealedBox::b64url(random_bytes(16)));
	$vault->save();
	$vault->load();
	harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', intval($vault->key));
	return $vault;
}

function lu_alias(int $domain_id, string $local, int $holder_id): InboundEmailAlias {
	$alias = new InboundEmailAlias(NULL);
	$alias->set('iea_ied_inbound_email_domain_id', $domain_id);
	$alias->set('iea_alias', $local);
	$alias->set('iea_delivery_mode', 'store');
	$alias->set('iea_destinations', '');
	$alias->set('iea_is_enabled', true);
	$alias->save();
	$alias->load();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', intval($alias->key));
	InboundEmailMailboxGrant::sync_for_alias($alias->key, array($holder_id));
	harness_defer(function () use ($alias) {
		InboundEmailMailboxGrant::sync_for_alias($alias->key, array());
	});
	return $alias;
}

function lu_message(int $domain_id, int $alias_id, string $direction, string $subject, string $body, string $recipient): int {
	$msg = new InboundEmailMessage(NULL);
	$msg->set('iem_ied_inbound_email_domain_id', $domain_id);
	$msg->set('iem_iea_inbound_email_alias_id', $alias_id);
	$msg->set('iem_direction', $direction);
	$msg->set('iem_sender', 'sender@elsewhere.example');
	$msg->set('iem_recipient', $recipient);
	$msg->set('iem_subject', $subject);
	$msg->set('iem_body_plain', $body);
	$msg->save();
	$msg->load();
	harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', intval($msg->key));
	return intval($msg->key);
}

function lu_row(int $id): array {
	$db = DbConnector::get_instance()->get_db_link();
	$stmt = $db->prepare('SELECT * FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
	$stmt->execute(array($id));
	return $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
}

try {

	// -----------------------------------------------------------------------
	section('fixtures: a Private domain, two holders, sealed history');

	$owner = make_user('LuOwner');
	$owner_id = intval($owner->key);
	$owner_kp = sodium_crypto_box_keypair();
	lu_vault($owner_id, SealedBox::b64url(sodium_crypto_box_publickey($owner_kp)));

	$other = make_user('LuOther');
	$other_id = intval($other->key);
	$other_kp = sodium_crypto_box_keypair();
	lu_vault($other_id, SealedBox::b64url(sodium_crypto_box_publickey($other_kp)));

	$dom = new InboundEmailDomain(NULL);
	$dom->set('ied_domain', 'lu-' . bin2hex(random_bytes(3)) . '.example');
	$dom->set('ied_is_enabled', true);
	$dom->set('ied_security_level', InboundEmailDomain::LEVEL_PRIVATE);
	$dom->save();
	$dom->load();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($dom->key));
	$dom_id = intval($dom->key);

	$mine = lu_alias($dom_id, 'mine', $owner_id);
	$theirs = lu_alias($dom_id, 'theirs', $other_id);

	$m1 = lu_message($dom_id, intval($mine->key), 'inbound', 'first subject', 'first body plaintext', 'mine@' . $dom->get('ied_domain'));
	$m2 = lu_message($dom_id, intval($mine->key), 'inbound', 'second subject', 'second body plaintext', 'mine@' . $dom->get('ied_domain'));
	$m3 = lu_message($dom_id, intval($mine->key), 'outbound', 'sent subject', 'sent body plaintext', 'friend@far.example');
	$m4 = lu_message($dom_id, intval($theirs->key), 'inbound', 'their subject', 'their body plaintext', 'theirs@' . $dom->get('ied_domain'));

	$sealed = mailbox_protection_seal_batch($dom, 200);
	check($sealed['sealed'] === 4 && $sealed['remaining'] === 0,
		'the raise convergence seals all four rows', json_encode($sealed));

	// A sealed attachment File on m1, sealed under m1's own DEK — placed
	// after the row seal so the DEK wrapping exists to seal under.
	$att_plain = 'attachment plaintext bytes ' . bin2hex(random_bytes(6));
	$file = File::createFromBytes($att_plain, 'note.txt', 'text/plain', User::USER_SYSTEM, array('fil_private' => true));
	harness_register_row('fil_files', 'fil_file_id', intval($file->key));
	$crypto = new VaultCrypto();
	$m1_dek = $crypto->openItemDek((string)lu_row($m1)['iem_sealed_key'],
		SealedBox::b64url(sodium_crypto_box_secretkey($owner_kp)));
	$file->replace_bytes($crypto->sealField($att_plain, $m1_dek, InboundEmailMessage::attachmentAd($m1, '2')));
	$att = new InboundMessageAttachment(NULL);
	$att->set('ima_iem_inbound_email_message_id', $m1);
	$att->set('ima_filename', 'note.txt');
	$att->set('ima_content_type', 'text/plain');
	$att->set('ima_mime_part', '2');
	$att->set('ima_fil_file_id', intval($file->key));
	$att->set('ima_is_sealed', true);
	$att->save();
	$att->load();
	harness_register_row('ima_inbound_message_attachments', 'ima_inbound_message_attachment_id', intval($att->key));

	// -----------------------------------------------------------------------
	section('a sealing domain is never unsealed');

	check(InboundEmailMessage::aliasSealedContentActive(intval($mine->key)),
		'sealed-content-active is true on a sealing domain');
	$res = mailbox_protection_unseal_batch($dom, $owner_id, 25);
	check($res['unsealed'] === 0 && $res['own_remaining'] === 0 && $res['others_remaining'] === 0,
		'the batch driver refuses a domain that still seals', json_encode($res));
	check(!empty(lu_row($m1)['iem_content_sealed']), 'the refused rows are untouched');

	// -----------------------------------------------------------------------
	section('lowered, window closed: locked, nothing converges');

	$dom->set('ied_security_level', InboundEmailDomain::LEVEL_STANDARD);
	$dom->save();
	$dom = new InboundEmailDomain($dom_id, TRUE);

	check(InboundEmailMessage::aliasSealedContentActive(intval($mine->key)),
		'sealed-content-active stays true while leftovers remain');

	VaultUnlock::lockAll($owner_id);
	$msg1 = new InboundEmailMessage($m1, TRUE);
	check(InboundEmailMessage::unsealAndPersistContent($msg1) === false,
		'the primitive refuses with a closed window');
	check(!empty(lu_row($m1)['iem_content_sealed']), 'the row stays sealed');

	$res = mailbox_protection_unseal_batch($dom, $owner_id, 25);
	check(!empty($res['locked']) && $res['unsealed'] === 0,
		'the batch answers locked with a closed window', json_encode($res));
	check($res['own_remaining'] === 3 && $res['others_remaining'] === 1,
		'locked counts still split own vs others', json_encode($res));

	// -----------------------------------------------------------------------
	section('window open: history converges, caller-scoped, bounded');

	VaultUnlock::open($owner_id, SealedBox::b64url(sodium_crypto_box_secretkey($owner_kp)));
	harness_defer(function () use ($owner_id) { VaultUnlock::lockAll($owner_id); });

	$res = mailbox_protection_unseal_batch($dom, $owner_id, 2);
	check($res['unsealed'] === 2 && $res['own_remaining'] === 1,
		'a bounded pass converges exactly the batch size', json_encode($res));
	$res = mailbox_protection_unseal_batch($dom, $owner_id, 25);
	check($res['unsealed'] === 1 && $res['own_remaining'] === 0 && $res['others_remaining'] === 1,
		'the next pass finishes the callers rows and names the others', json_encode($res));

	$r1 = lu_row($m1);
	check(empty($r1['iem_content_sealed']) && $r1['iem_sealed_key'] === null
		&& $r1['iem_sealed_owner_user_id'] === null && intval($r1['iem_key_generation']) === 0,
		'the sealed flags and key wrapping are fully cleared');
	check($r1['iem_subject'] === 'first subject' && $r1['iem_body_plain'] === 'first body plaintext'
		&& $r1['iem_sender'] === 'sender@elsewhere.example',
		'content is restored byte-for-byte');
	$r3 = lu_row($m3);
	check($r3['iem_recipient'] === 'friend@far.example',
		'an outbound rows sealed recipient is restored');
	$r2 = lu_row($m2);
	check($r2['iem_recipient'] === 'mine@' . $dom->get('ied_domain'),
		'an inbound rows routing recipient (never sealed) is untouched');

	$att = new InboundMessageAttachment(intval($att->key), TRUE);
	check(!$att->get('ima_is_sealed'), 'the attachment manifest row is unsealed');
	$file = new File(intval($file->key), TRUE);
	check($file->read_bytes('original') === $att_plain, 'the attachment File bytes are plaintext again');

	$r4 = lu_row($m4);
	check(!empty($r4['iem_content_sealed']), 'the other holders row stays sealed — not this sessions key');

	check(!InboundEmailMessage::aliasSealedContentActive(intval($mine->key)),
		'sealed-content-active is false once the mailbox converges — plaintext search resumes');
	check(InboundEmailMessage::aliasSealedContentActive(intval($theirs->key)),
		'the other holders mailbox still reads as sealed content');

	$counts = mailbox_protection_unseal_counts($dom, $owner_id);
	check($counts['own'] === 0 && $counts['others'] === 1,
		'the editor counts match', json_encode($counts));

	// -----------------------------------------------------------------------
	section('lowering receipt render');

	$state = array('own_backlog' => 3, 'others_backlog' => 1, 'window_open' => true,
		'editor_url' => '/plugins/mailbox/admin/admin_mailbox_domains?ied_inbound_email_domain_id=' . $dom_id);
	$html = mailbox_lowering_receipt_render($dom, $state);
	check(strpos($html, 'This domain is now Standard') !== false, 'the title states the event');
	check(strpos($html, 'Unsealing earlier messages — 3 remaining') !== false, 'the unseal row is live');
	check(strpos($html, '1 message stay') !== false, 'other holders rows are named, info not error');
	check(strpos($html, 'ceremony_unseal_batch') !== false && strpos($html, '<noscript>') !== false,
		'the noscript batch form is present');
	check(strpos($html, 'd-none') !== false, 'the button hides while the loop will run');

	$state['window_open'] = false;
	$html = mailbox_lowering_receipt_render($dom, $state);
	check(strpos($html, 'Unlock your vault') !== false, 'a closed window renders the unlock hint');
	check(strpos($html, '<noscript>') === false, 'no batch form without an open window');
	check(strpos($html, 'd-none') === false, 'the button stays reachable in the locked state');

	$state = array('own_backlog' => 0, 'others_backlog' => 0, 'window_open' => true, 'editor_url' => '');
	$html = mailbox_lowering_receipt_render($dom, $state);
	check(strpos($html, 'All earlier messages are readable') !== false, 'the completed fact renders');
	check(strpos($html, 'Open mailbox') !== false && strpos($html, 'd-none') === false,
		'the completed card offers the mailbox');

	// -----------------------------------------------------------------------
	section('mailbox/unseal_batch action refusals');

	$descriptor = unseal_batch_logic_api();
	check(!empty($descriptor['requires_session'])
		&& !empty($descriptor['auth']['requires_browser_session']),
		'the action requires a browser session');

	// The CLI harness session has no signed-in user, so the gate refuses first.
	$res = unseal_batch_logic(array('domain_id' => $dom_id));
	check($res->error !== null, 'an anonymous session is refused');

} finally {
	harness_finish();
}
?>
