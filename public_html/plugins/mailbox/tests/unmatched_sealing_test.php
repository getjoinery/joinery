<?php
/** @joinery-test
 * name: unmatched_sealing
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Mail that belongs to no mailbox (specs/mailbox_unmatched_sealing.md).
 *
 * The catch-all accepts mail for addresses nobody created — postmaster@, typos,
 * guesses — and stores it with no alias. Sealing normally uses the mailbox's
 * single owner, so that mail had no key and was written in plaintext on a domain
 * whose whole purpose is that it is not. It now seals to the DOMAIN's owner.
 *
 * What this pins down:
 *   - the owner resolution: mailbox owner for aliased mail, domain owner for
 *     alias-less mail, and null for a mailbox with none or several owners (the
 *     fallback must never hand a shared mailbox's mail to a third party);
 *   - the backlog pass converges alias-less plaintext;
 *   - a sealing domain with no owner, and one whose owner has no vault, each
 *     produce their required ceremony row and block the raise;
 *   - the setup check calls alias-less mail converging once an owner exists, and
 *     blocked with the domain-owner cause when one does not.
 *
 * Run: php tests/run.php db --filter=unmatched_sealing
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protection_ceremony.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/mailbox_setup_scope.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));

function um_vault(int $user_id): UserEncryptionVault {
	$kp = sodium_crypto_box_keypair();
	$vault = new UserEncryptionVault(NULL);
	$vault->set('uev_usr_user_id', $user_id);
	$vault->set('uev_public_key', SealedBox::b64url(sodium_crypto_box_publickey($kp)));
	$vault->set('uev_salt', SealedBox::b64url(random_bytes(16)));
	$vault->save();
	$vault->load();
	harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', intval($vault->key));
	return $vault;
}

function um_domain(string $level, ?int $owner_id): InboundEmailDomain {
	$dom = new InboundEmailDomain(NULL);
	$dom->set('ied_domain', 'um-' . bin2hex(random_bytes(4)) . '.example');
	$dom->set('ied_is_enabled', true);
	$dom->set('ied_security_level', $level);
	$dom->set('ied_catch_all_mode', 'store');
	if ($owner_id !== null) {
		$dom->set('ied_owner_usr_user_id', $owner_id);
	}
	$dom->save();
	$dom->load();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($dom->key));
	return $dom;
}

function um_alias(int $domain_id, string $local, array $holder_ids): InboundEmailAlias {
	$alias = new InboundEmailAlias(NULL);
	$alias->set('iea_ied_inbound_email_domain_id', $domain_id);
	$alias->set('iea_alias', $local);
	$alias->set('iea_delivery_mode', 'store');
	$alias->set('iea_destinations', '');
	$alias->set('iea_is_enabled', true);
	$alias->save();
	$alias->load();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', intval($alias->key));
	InboundEmailMailboxGrant::sync_for_alias($alias->key, $holder_ids);
	harness_defer(function () use ($alias) {
		InboundEmailMailboxGrant::sync_for_alias($alias->key, array());
	});
	return $alias;
}

/** An unsealed stored message, optionally with no alias (the catch-all case). */
function um_message(int $domain_id, ?int $alias_id, string $recipient, string $subject): int {
	$msg = new InboundEmailMessage(NULL);
	$msg->set('iem_ied_inbound_email_domain_id', $domain_id);
	if ($alias_id !== null) {
		$msg->set('iem_iea_inbound_email_alias_id', $alias_id);
	}
	$msg->set('iem_direction', 'inbound');
	$msg->set('iem_sender', 'sender@elsewhere.example');
	$msg->set('iem_recipient', $recipient);
	$msg->set('iem_subject', $subject);
	$msg->set('iem_body_plain', 'body of ' . $subject);
	$msg->set('iem_message_id_header', 'um-' . bin2hex(random_bytes(8)) . '@example.com');
	$msg->save();
	$msg->load();
	harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', intval($msg->key));
	return intval($msg->key);
}

function um_row(int $id): array {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare('SELECT * FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
	$q->execute(array($id));
	return $q->fetch(PDO::FETCH_ASSOC) ?: array();
}

/** The ceremony row with this id, or null. */
function um_ceremony_row(array $rows, string $id): ?array {
	foreach ($rows as $r) {
		if (($r['id'] ?? '') === $id) return $r;
	}
	return null;
}

try {

	section('who a message seals to');

	$owner = make_user('UmOwner');
	$owner_id = intval($owner->key);
	um_vault($owner_id);

	$other = make_user('UmOther');
	$other_id = intval($other->key);

	$dom = um_domain(InboundEmailDomain::LEVEL_FORTRESS, $owner_id);
	$dom_id = intval($dom->key);
	$mine = um_alias($dom_id, 'mine', array($owner_id));
	$shared = um_alias($dom_id, 'shared', array($owner_id, $other_id));
	$holderless = um_alias($dom_id, 'nobody', array());

	check(InboundEmailMessage::sealOwnerUserId(intval($mine->key), $dom_id) === $owner_id,
		'a mailbox with one owner seals to that owner');
	check(InboundEmailMessage::sealOwnerUserId(null, $dom_id) === $owner_id,
		'mail with NO mailbox seals to the domain owner');
	check(InboundEmailMessage::sealOwnerUserId(intval($shared->key), $dom_id) === null,
		'a shared mailbox still has no single owner — never falls back to the domain');
	check(InboundEmailMessage::sealOwnerUserId(intval($holderless->key), $dom_id) === null,
		'an ownerless mailbox does not fall back either');
	check(InboundEmailMessage::domainOwnerUserId($dom_id) === $owner_id,
		'the domain owner resolves');

	$ownerless_domain = um_domain(InboundEmailDomain::LEVEL_FORTRESS, null);
	check(InboundEmailMessage::sealOwnerUserId(null, intval($ownerless_domain->key)) === null,
		'with no domain owner there is nobody to seal alias-less mail to');

	section('the backlog pass seals alias-less mail');

	$unmatched = um_message($dom_id, null, 'postmaster@' . $dom->get('ied_domain'), 'DMARC report');
	$aliased   = um_message($dom_id, intval($mine->key), 'mine@' . $dom->get('ied_domain'), 'ordinary mail');
	check(empty(um_row($unmatched)['iem_content_sealed']), 'precondition: stored unsealed');

	$res = mailbox_protection_seal_batch($dom, 50);
	check($res['sealed'] >= 2, 'the pass sealed both rows', json_encode($res));

	$row = um_row($unmatched);
	check(!empty($row['iem_content_sealed']), 'the alias-less message is now sealed');
	check(intval($row['iem_sealed_owner_user_id']) === $owner_id,
		'and is stamped as belonging to the domain owner');
	check(intval(um_row($aliased)['iem_sealed_owner_user_id']) === $owner_id,
		'the aliased message is stamped to its mailbox owner');

	section('a sealing domain must have an owner with a vault');

	// No owner at all.
	$facts = mailbox_protection_facts($ownerless_domain, $owner_id);
	$rows = mailbox_protection_rows($facts, InboundEmailDomain::LEVEL_FORTRESS, $owner_id);
	$row = um_ceremony_row($rows, 'domain_owner');
	check($row !== null && $row['status'] === 'fail' && $row['severity'] === 'required',
		'a domain with no owner fails a required row');
	check($row !== null && ($row['actions'][0]['type'] ?? '') === 'set_domain_owner',
		'and offers to make the acting admin the owner');
	check(!mailbox_protection_required_ok($rows), 'so the raise is blocked');

	// An owner who has no vault.
	$novault_owner = make_user('UmNoVault');
	$novault_domain = um_domain(InboundEmailDomain::LEVEL_FORTRESS, intval($novault_owner->key));
	$facts2 = mailbox_protection_facts($novault_domain, $owner_id);
	$rows2 = mailbox_protection_rows($facts2, InboundEmailDomain::LEVEL_FORTRESS, $owner_id);
	$row2 = um_ceremony_row($rows2, 'domain_owner_vault');
	check($row2 !== null && $row2['status'] === 'fail',
		'an owner without a vault fails a required row');
	check(!mailbox_protection_required_ok($rows2), 'and blocks the raise too');

	// The healthy domain passes.
	$facts3 = mailbox_protection_facts($dom, $owner_id);
	$rows3 = mailbox_protection_rows($facts3, InboundEmailDomain::LEVEL_FORTRESS, $owner_id);
	$row3 = um_ceremony_row($rows3, 'domain_owner');
	check($row3 !== null && $row3['status'] === 'pass',
		'a domain whose owner holds a vault passes');

	section('what the setup check reports');

	// A fresh unsealed alias-less row on the healthy domain: converging, not blocked.
	um_message($dom_id, null, 'abuse@' . $dom->get('ied_domain'), 'another unowned one');
	$check = new InboundEmailSetupCheck();
	$found = null;
	foreach ($check->runDomainChecks((string)$dom->get('ied_domain')) as $r) {
		if (($r['id'] ?? '') === 'domain.sealed_backlog') { $found = $r; }
	}
	check($found !== null && $found['status'] !== 'fail',
		'alias-less mail is converging, not blocked, once the domain has an owner',
		$found ? $found['summary'] : '(no row)');

	// Waiting to seal is the normal in-between state. It must not raise the
	// reader's "needs attention" banner, which fires on warn and fail — a
	// mailbox working exactly as designed should say nothing at all.
	check($found !== null && $found['status'] === InboundEmailSetupCheck::INFO,
		'a fresh sealing backlog is informational, so no banner',
		$found ? $found['status'] : '');
	check($found !== null && mailbox_setup_verdict(array(
			'receiving' => array($found), 'forwarding' => array(),
		))['status'] !== 'attention',
		'and the reader verdict agrees it is not attention-worthy');

	// It becomes a real issue only when nobody drains it: nothing seals on a
	// timer, so mail still in plaintext a day later means the pass never ran.
	$db = DbConnector::get_instance()->get_db_link();
	$db->prepare("UPDATE iem_inbound_email_messages
		SET iem_received_time = now() - interval '3 days'
		WHERE iem_ied_inbound_email_domain_id = ? AND iem_content_sealed = false
		  AND iem_pending_parse = false AND iem_delete_time IS NULL")
		->execute(array($dom_id));

	$aged = null;
	foreach ($check->runDomainChecks((string)$dom->get('ied_domain')) as $r) {
		if (($r['id'] ?? '') === 'domain.sealed_backlog') { $aged = $r; }
	}
	check($aged !== null && $aged['status'] === InboundEmailSetupCheck::WARN,
		'a backlog nobody has drained for days does warn',
		$aged ? $aged['status'] . ' — ' . $aged['summary'] : '(no row)');
	check($aged !== null && mailbox_setup_verdict(array(
			'receiving' => array($aged), 'forwarding' => array(),
		))['status'] === 'attention',
		'and that one does reach the banner');

	// The same shape on a domain with no owner: blocked, and it says why.
	um_message(intval($ownerless_domain->key), null,
		'postmaster@' . $ownerless_domain->get('ied_domain'), 'nowhere to go');
	$found2 = null;
	foreach ($check->runDomainChecks((string)$ownerless_domain->get('ied_domain')) as $r) {
		if (($r['id'] ?? '') === 'domain.sealed_backlog') { $found2 = $r; }
	}
	check($found2 !== null && $found2['status'] === 'fail',
		'with no domain owner it is still blocked', $found2 ? $found2['summary'] : '(no row)');
	check($found2 !== null && strpos($found2['summary'], 'no owner to seal it to') !== false,
		'and the summary names the real cause', $found2 ? $found2['summary'] : '');
	check($found2 !== null && strpos($found2['summary'], 'postmaster@') !== false,
		'and names the address', $found2 ? $found2['summary'] : '');

} catch (Throwable $harness_e) {
	// Names the throw and where it happened, which beats the fatal-handler
	// detail the crash net has to fall back on.
	check(false, 'the suite ran to completion without throwing',
		get_class($harness_e) . ': ' . $harness_e->getMessage()
		. ' @ ' . $harness_e->getFile() . ':' . $harness_e->getLine());
}

// Outside the try, and NEVER in a finally: harness_finish() exit()s, so calling
// it while an exception is unwinding swallows the throw and reports PASS on
// however many checks completed. Fixture teardown runs from here and from the
// crash net, so nothing leaks on either path.
// tests/estate/harness_contract_test.php enforces this shape estate-wide.
harness_finish();
?>
