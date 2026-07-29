<?php
/** @joinery-test
 * name: protection_ceremony
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Protection ceremony (specs/mailbox_protection_ceremony.md): the guided path
 * to Private/Fortress.
 *
 *  - Row evaluation matrix (pure — hand-built facts): single-reader rows with
 *    inline remove actions, holderless mailboxes, holder-vault rows (self vs
 *    named-other), the passkeys kill-switch blocker, recommended PRF rows,
 *    Fortress relay/DNS rows, required_ok gating.
 *  - Mutation-point refusal: grant-list changes on a protected domain refuse
 *    a second member or none at all.
 *  - Backlog sealing: a raise converges earlier plaintext rows — sealed to the
 *    holder's vault public key, batch-driven, holderless rows skipped and
 *    counted as remaining.
 *
 * Run: php tests/run.php db --filter=protection_ceremony
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protection_ceremony.php'));
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));

const ACTING = 42;

/** Hand-built facts for the pure evaluation sections. */
function pc_facts(array $aliases, bool $passkeys = true, bool $fronted = false): array {
	return array('passkeys_enabled' => $passkeys, 'relay_fronted' => $fronted, 'aliases' => $aliases);
}
function pc_holder(int $uid, bool $vault = true, bool $prf = true, string $name = 'Holder'): array {
	return array('user_id' => $uid, 'name' => $name, 'has_vault' => $vault, 'has_prf_passkey' => $prf);
}
function pc_alias(int $id, array $holders): array {
	return array('alias_id' => $id, 'address' => 'box' . $id . '@x.example', 'holders' => $holders);
}
function pc_row(array $rows, string $id): ?array {
	foreach ($rows as $r) {
		if ($r['id'] === $id) { return $r; }
	}
	return null;
}

try {

	// -----------------------------------------------------------------------
	section('row evaluation: one reader per mailbox');

	$rows = mailbox_protection_rows(pc_facts(array(
		pc_alias(1, array(pc_holder(ACTING), pc_holder(7, true, true, 'Sam Other'))),
	)), InboundEmailDomain::LEVEL_PRIVATE, ACTING);
	$shared = pc_row($rows, 'single_reader:1');
	check($shared !== null && $shared['status'] === 'fail' && $shared['severity'] === 'required',
		'a shared mailbox is a required failure');
	check(count($shared['actions']) === 2 && $shared['actions'][0]['type'] === 'remove_grant',
		'each holder gets an inline remove action');
	check(!mailbox_protection_required_ok($rows), 'a shared mailbox blocks the raise');
	check(mailbox_protection_first_failure($rows) === $shared['summary'],
		'the save refusal carries the failing row\'s own words');

	$rows = mailbox_protection_rows(pc_facts(array(pc_alias(2, array()))),
		InboundEmailDomain::LEVEL_PRIVATE, ACTING);
	$empty = pc_row($rows, 'has_reader:2');
	check($empty !== null && $empty['status'] === 'fail'
		&& $empty['actions'][0]['type'] === 'add_reader', 'a holderless mailbox fails with an add-owner action');

	$rows = mailbox_protection_rows(pc_facts(array(pc_alias(3, array(pc_holder(ACTING))))),
		InboundEmailDomain::LEVEL_PRIVATE, ACTING);
	check(pc_row($rows, 'single_reader') !== null && pc_row($rows, 'single_reader')['status'] === 'pass',
		'all-single renders the pass row');
	check(mailbox_protection_required_ok($rows), 'clean facts pass the gate');

	// -----------------------------------------------------------------------
	section('row evaluation: vaults, passkeys, kill switch');

	$rows = mailbox_protection_rows(pc_facts(array(pc_alias(1, array(pc_holder(ACTING, false))))),
		InboundEmailDomain::LEVEL_PRIVATE, ACTING);
	$vault = pc_row($rows, 'holder_vault:' . ACTING);
	check($vault !== null && $vault['status'] === 'fail'
		&& $vault['actions'][0]['type'] === 'vault_self',
		'the session user\'s missing vault offers the set-up-now path');

	$rows = mailbox_protection_rows(pc_facts(array(pc_alias(1, array(pc_holder(9, false, false, 'Robin Reader'))))),
		InboundEmailDomain::LEVEL_PRIVATE, ACTING);
	$other = pc_row($rows, 'holder_vault:9');
	check($other !== null && $other['status'] === 'fail' && count($other['actions']) === 0
		&& strpos($other['summary'], 'Robin Reader') !== false,
		'another holder\'s missing vault names them and offers no admin fix (their key, not yours)');

	$rows = mailbox_protection_rows(pc_facts(array(pc_alias(1, array(pc_holder(ACTING)))), false),
		InboundEmailDomain::LEVEL_PRIVATE, ACTING);
	check(pc_row($rows, 'passkeys_platform') !== null
		&& pc_row($rows, 'passkeys_platform')['status'] === 'fail',
		'the passkeys kill switch renders a required blocker when off');
	check(pc_row($rows, 'holder_passkey') === null, 'no passkey rows while the platform switch is off');
	check(!mailbox_protection_required_ok($rows), 'the kill switch blocks the raise');

	$rows = mailbox_protection_rows(pc_facts(array(pc_alias(1, array(pc_holder(ACTING, true, false))))),
		InboundEmailDomain::LEVEL_PRIVATE, ACTING);
	$prf = pc_row($rows, 'holder_passkey:' . ACTING);
	check($prf !== null && $prf['severity'] === 'recommended' && $prf['status'] === 'warn'
		&& $prf['actions'][0]['type'] === 'passkey_self', 'a missing PRF passkey warns (recommended)');
	check(mailbox_protection_required_ok($rows), 'a recommended row never blocks the raise');

	// -----------------------------------------------------------------------
	section('row evaluation: fortress');

	$facts_ok = pc_facts(array(pc_alias(1, array(pc_holder(ACTING)))), true, false);
	$rows = mailbox_protection_rows($facts_ok, InboundEmailDomain::LEVEL_PRIVATE, ACTING);
	check(pc_row($rows, 'relay_fronted') === null, 'private target never asks for a relay');

	$rows = mailbox_protection_rows($facts_ok, InboundEmailDomain::LEVEL_FORTRESS, ACTING);
	check(pc_row($rows, 'relay_fronted') !== null && pc_row($rows, 'relay_fronted')['status'] === 'fail',
		'fortress without a relay is a required failure');
	check(!mailbox_protection_required_ok($rows), 'no relay blocks a fortress raise');

	$rows = mailbox_protection_rows(pc_facts(array(pc_alias(1, array(pc_holder(ACTING)))), true, true),
		InboundEmailDomain::LEVEL_FORTRESS, ACTING);
	check(pc_row($rows, 'relay_fronted')['status'] === 'pass', 'a fronted deployment passes the relay row');
	check(pc_row($rows, 'fortress_dns') !== null && pc_row($rows, 'fortress_dns')['status'] === 'info',
		'the DNS/protect stage is announced as the next step, not a blocker');
	check(mailbox_protection_required_ok($rows), 'fronted + clean facts clears a fortress raise');

	// -----------------------------------------------------------------------
	// Owning a Fortress domain locks the account out of every page but
	// /profile/security until a second factor exists, and the raise seals the
	// signing key to whoever performs it — so the requirement has to block the
	// raise rather than ambush the operator immediately after it.
	section('row evaluation: the acting user needs a second factor for fortress');

	$facts_2fa = pc_facts(array(pc_alias(1, array(pc_holder(ACTING)))), true, true);

	$rows = mailbox_protection_rows($facts_2fa, InboundEmailDomain::LEVEL_FORTRESS, ACTING);
	check(pc_row($rows, 'second_factor_self') === null,
		'facts that never mention the acting second factor raise no row');
	check(mailbox_protection_required_ok($rows), 'and do not block the raise');

	$rows = mailbox_protection_rows($facts_2fa + array('acting_has_second_factor' => true),
		InboundEmailDomain::LEVEL_FORTRESS, ACTING);
	check(pc_row($rows, 'second_factor_self') === null, 'an enrolled second factor raises no row');
	check(mailbox_protection_required_ok($rows), 'and clears the fortress raise');

	$rows = mailbox_protection_rows($facts_2fa + array('acting_has_second_factor' => false),
		InboundEmailDomain::LEVEL_FORTRESS, ACTING);
	$sf = pc_row($rows, 'second_factor_self');
	check($sf !== null && $sf['status'] === 'fail' && $sf['severity'] === 'required',
		'a missing second factor is a required failure');
	check(!mailbox_protection_required_ok($rows), 'and blocks the fortress raise');
	check($sf !== null && isset($sf['actions'][0]['type'])
		&& $sf['actions'][0]['type'] === 'second_factor_self',
		'the row carries the enrollment action');

	$rows = mailbox_protection_rows($facts_2fa + array('acting_has_second_factor' => false),
		InboundEmailDomain::LEVEL_PRIVATE, ACTING);
	check(pc_row($rows, 'second_factor_self') === null,
		'private never asks for it — only fortress makes you the signing owner');

	// The rendered row must offer the way out, or the block is a dead end.
	// An unsaved domain is enough — render only reads it for the backlog wording.
	$sf_dom = new InboundEmailDomain(NULL);
	$sf_dom->set('ied_domain', 'pc-2fa.example');
	$sf_html = mailbox_protection_render(
		mailbox_protection_rows($facts_2fa + array('acting_has_second_factor' => false),
			InboundEmailDomain::LEVEL_FORTRESS, ACTING),
		$sf_dom, array('editor_url' => '/x', 'alias_url' => '/y'),
		InboundEmailDomain::LEVEL_FORTRESS);
	check(strpos($sf_html, 'Add a second factor') !== false, 'the rendered row links to enrollment');
	check(strpos($sf_html, '/profile/security') !== false, 'and points at the security page');

	// -----------------------------------------------------------------------
	section('mutation-point refusal: grants on a protected domain');

	$dom = new InboundEmailDomain(NULL);
	$dom->set('ied_domain', 'pc-protected-' . bin2hex(random_bytes(3)) . '.example');
	$dom->set('ied_is_enabled', true);
	$dom->set('ied_security_level', InboundEmailDomain::LEVEL_PRIVATE);
	$dom->save();
	$dom->load();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($dom->key));

	check(mailbox_protected_grant_error($dom, array(1, 2)) !== null,
		'a second member on a protected mailbox is refused');
	check(mailbox_protected_grant_error($dom, array()) !== null,
		'a holderless mailbox on a protected domain is refused');
	check(mailbox_protected_grant_error($dom, array(1)) === null,
		'exactly one member is the accepted shape');

	$std = new InboundEmailDomain(NULL);
	$std->set('ied_domain', 'pc-standard-' . bin2hex(random_bytes(3)) . '.example');
	$std->set('ied_is_enabled', true);
	$std->set('ied_security_level', InboundEmailDomain::LEVEL_STANDARD);
	$std->save();
	$std->load();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($std->key));
	check(mailbox_protected_grant_error($std, array(1, 2, 3)) === null,
		'standard domains keep group mailboxes');

	// -----------------------------------------------------------------------
	section('backlog sealing: a raise converges history');

	$owner = make_user('PcOwner');
	$keypair = sodium_crypto_box_keypair();
	$vault_row = new UserEncryptionVault(NULL);
	$vault_row->set('uev_usr_user_id', intval($owner->key));
	$vault_row->set('uev_public_key', SealedBox::b64url(sodium_crypto_box_publickey($keypair)));
	$vault_row->set('uev_salt', SealedBox::b64url(random_bytes(16)));
	$vault_row->save();
	$vault_row->load();
	harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', intval($vault_row->key));

	$alias = new InboundEmailAlias(NULL);
	$alias->set('iea_ied_inbound_email_domain_id', intval($dom->key));
	$alias->set('iea_alias', 'sealme');
	$alias->set('iea_delivery_mode', 'store');
	$alias->set('iea_destinations', '');
	$alias->set('iea_is_enabled', true);
	$alias->save();
	$alias->load();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', intval($alias->key));
	InboundEmailMailboxGrant::sync_for_alias($alias->key, array(intval($owner->key)));
	harness_defer(function () use ($alias) {
		InboundEmailMailboxGrant::sync_for_alias($alias->key, array());
	});

	// Facts gathering against real rows — the row-evaluation sections above
	// feed hand-built facts, so they can never catch a gatherer that forgets
	// to load() a Multi (which silently counts zero, never errors).
	$pk = new Passkey(NULL);
	$pk->set('pkc_usr_user_id', intval($owner->key));
	$pk->set('pkc_credential_id', SealedBox::b64url(random_bytes(16)));
	$pk->set('pkc_source_json', '{}');
	$pk->set('pkc_prf_capable', true);
	$pk->set('pkc_label', 'PcOwner key');
	$pk->save();
	$pk->load();
	harness_register_row('pkc_passkey_credentials', 'pkc_passkey_credential_id', intval($pk->key));

	$facts_live = mailbox_protection_facts($dom);
	$holder_seen = null;
	foreach ($facts_live['aliases'] as $fa) {
		if ($fa['alias_id'] === intval($alias->key)) {
			$holder_seen = isset($fa['holders'][0]) ? $fa['holders'][0] : null;
		}
	}
	check($holder_seen !== null && $holder_seen['has_vault'] === true, 'facts: holder vault detected from real rows');
	check($holder_seen !== null && $holder_seen['has_prf_passkey'] === true, 'facts: holder PRF passkey detected from real rows');

	$msg_ids = array();
	foreach (array('first plaintext body', 'second plaintext body') as $i => $body) {
		$msg = new InboundEmailMessage(NULL);
		$msg->set('iem_ied_inbound_email_domain_id', intval($dom->key));
		$msg->set('iem_iea_inbound_email_alias_id', intval($alias->key));
		$msg->set('iem_sender', 'sender@elsewhere.example');
		$msg->set('iem_recipient', 'sealme@' . $dom->get('ied_domain'));
		$msg->set('iem_subject', 'pre-raise subject ' . $i);
		$msg->set('iem_body_plain', $body);
		$msg->save();
		$msg->load();
		$msg_ids[] = intval($msg->key);
		harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', intval($msg->key));
	}

	check(mailbox_protection_backlog_count(intval($dom->key)) === 2, 'both pre-raise rows count as backlog');

	$result = mailbox_protection_seal_batch($dom, 200);
	check($result['sealed'] === 2 && $result['remaining'] === 0,
		'the sealing pass converges the whole backlog', json_encode($result));

	$db = DbConnector::get_instance()->get_db_link();
	$stmt = $db->prepare("SELECT iem_content_sealed, iem_subject, iem_body_plain, iem_sealed_key
		FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?");
	$stmt->execute(array($msg_ids[0]));
	$sealed_row = $stmt->fetch(PDO::FETCH_ASSOC);
	check(!empty($sealed_row['iem_content_sealed']), 'the row is flagged sealed');
	check(strpos((string)$sealed_row['iem_subject'], 'pre-raise subject') === false,
		'the subject column no longer carries plaintext');
	check(strpos((string)$sealed_row['iem_body_plain'], 'plaintext body') === false,
		'the body column no longer carries plaintext');
	check((string)$sealed_row['iem_sealed_key'] !== '', 'the DEK is sealed onto the row');

	// The sealed content opens with the holder's secret key — the raise sealed
	// to the RIGHT vault, not just any key.
	require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
	$crypto = new VaultCrypto();
	$dek = $crypto->openItemDek((string)$sealed_row['iem_sealed_key'],
		SealedBox::b64url(sodium_crypto_box_secretkey($keypair)));
	check(is_string($dek) && $dek !== '', 'the holder\'s secret key opens the row DEK');

	// A mailbox with no vault-holding owner is skipped, never half-sealed.
	$orphan = new InboundEmailAlias(NULL);
	$orphan->set('iea_ied_inbound_email_domain_id', intval($dom->key));
	$orphan->set('iea_alias', 'orphan');
	$orphan->set('iea_delivery_mode', 'store');
	$orphan->set('iea_destinations', '');
	$orphan->set('iea_is_enabled', true);
	$orphan->save();
	$orphan->load();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', intval($orphan->key));

	$omsg = new InboundEmailMessage(NULL);
	$omsg->set('iem_ied_inbound_email_domain_id', intval($dom->key));
	$omsg->set('iem_iea_inbound_email_alias_id', intval($orphan->key));
	$omsg->set('iem_sender', 'sender@elsewhere.example');
	$omsg->set('iem_recipient', 'orphan@' . $dom->get('ied_domain'));
	$omsg->set('iem_subject', 'orphan subject');
	$omsg->set('iem_body_plain', 'orphan body');
	$omsg->save();
	$omsg->load();
	harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', intval($omsg->key));

	$result = mailbox_protection_seal_batch($dom, 200);
	check($result['sealed'] === 0 && $result['remaining'] === 1,
		'a holderless mailbox\'s rows are skipped and stay counted', json_encode($result));

} catch (\Throwable $e) {
	check(false, 'uncaught ' . get_class($e), $e->getMessage());
}

harness_finish();
