<?php
/** @joinery-test
 * name: protection_ceremony
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Protection ceremony (specs/mailbox_protection_ceremony.md): the guided path
 * to Private and its add-ons.
 *
 *  - Row evaluation matrix (pure — hand-built facts): single-reader rows with
 *    inline remove actions, holderless mailboxes, holder-vault rows (self vs
 *    named-other), the passkeys kill-switch blocker, recommended PRF rows,
 *    the add-ons' relay / next-step rows and the absence of any second-factor
 *    row, required_ok gating.
 *  - Mutation-point refusal: grant-list changes on a protected domain refuse
 *    a second member or none at all.
 *  - Backlog sealing: a raise converges earlier plaintext rows — sealed to the
 *    holder's vault public key, batch-driven, holderless rows skipped and
 *    counted as remaining.
 *
 * Run: php tests/run.php db --filter=protection_ceremony
 *
 * @version 1.4
 * @changelog 1.4 - neither add-on raises a second-factor row
 * @changelog 1.3 - the relay rows belong to the add-ons, not a level
 * @changelog 1.2 - a member with no vault is refused too: the vault is the key
 *   the mail seals to, so "has a member" was never the whole rule
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');
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
	section('row evaluation: the Seal at the relay add-on');

	$facts_ok = pc_facts(array(pc_alias(1, array(pc_holder(ACTING)))), true, false);
	$rows = mailbox_protection_rows($facts_ok, InboundEmailDomain::LEVEL_PRIVATE, ACTING);
	check(pc_row($rows, 'relay_fronted') === null, 'plain Private never asks for a relay');

	$rows = mailbox_protection_rows($facts_ok, InboundEmailDomain::LEVEL_PRIVATE, ACTING,
		array('relay_seal' => true));
	check(pc_row($rows, 'relay_fronted') !== null && pc_row($rows, 'relay_fronted')['status'] === 'fail',
		'relay sealing without a relay is a required failure');
	check(!mailbox_protection_required_ok($rows), 'no relay blocks switching relay sealing on');

	$rows = mailbox_protection_rows(pc_facts(array(pc_alias(1, array(pc_holder(ACTING)))), true, true),
		InboundEmailDomain::LEVEL_PRIVATE, ACTING, array('relay_seal' => true));
	check(pc_row($rows, 'relay_fronted')['status'] === 'pass', 'a fronted deployment passes the relay row');
	check(mailbox_protection_required_ok($rows), 'fronted + clean facts clears relay sealing');

	$addon_only = mailbox_protection_addon_rows($facts_ok, array('relay_seal' => true));
	check(count($addon_only) === 1 && $addon_only[0]['id'] === 'relay_fronted',
		'the add-on rows are only the add-on\'s own, never the level\'s');

	// -----------------------------------------------------------------------
	section('row evaluation: the Only send while I\'m signed in add-on');

	$rows = mailbox_protection_rows($facts_ok, InboundEmailDomain::LEVEL_PRIVATE, ACTING,
		array('send_lock' => true));
	check(pc_row($rows, 'relay_fronted') === null, 'the sending lock is independent of the relay');
	check(pc_row($rows, 'send_lock_next') !== null && pc_row($rows, 'send_lock_next')['status'] === 'info',
		'the DNS/protect stage is announced as the next step, not a blocker');
	check(mailbox_protection_required_ok($rows), 'clean facts clear the sending lock');

	// -----------------------------------------------------------------------
	// Neither add-on asks anything of the person switching it on beyond what
	// the domain itself needs: the rows are about the domain and its readers.
	section('row evaluation: neither add-on asks for a second factor');

	$facts_2fa = pc_facts(array(pc_alias(1, array(pc_holder(ACTING)))), true, true);

	foreach (array('relay_seal', 'send_lock') as $addon) {
		$rows = mailbox_protection_rows($facts_2fa, InboundEmailDomain::LEVEL_PRIVATE, ACTING,
			array($addon => true));
		$factor_rows = array_filter($rows, function ($r) {
			return stripos($r['label'] . ' ' . $r['summary'], 'second factor') !== false;
		});
		check(count($factor_rows) === 0 && mailbox_protection_required_ok($rows),
			$addon . ': no second-factor row, and clean facts clear the switch');
		check(count(array_filter(mailbox_protection_addon_rows($facts_2fa, array($addon => true)),
				function ($r) { return stripos($r['label'] . ' ' . $r['summary'], 'second factor') !== false; })) === 0,
			$addon . ': the add-on\'s own rows carry no second-factor row either');
	}

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

	// One member is the accepted shape only when that member has a VAULT: it is
	// the key the mail seals to, and without one a protected mailbox would store
	// plaintext (specs/mailbox_connect_flow.md § E).
	$novault = make_user('PcNoVault');
	check(mailbox_protected_grant_error($dom, array(intval($novault->key))) !== null,
		'one member with no vault is refused — there is nothing to seal to');

	$member = make_user('PcMember');
	$member_keys = sodium_crypto_box_keypair();
	$member_vault = new UserEncryptionVault(NULL);
	$member_vault->set('uev_usr_user_id', intval($member->key));
	$member_vault->set('uev_public_key', SealedBox::b64url(sodium_crypto_box_publickey($member_keys)));
	$member_vault->set('uev_salt', SealedBox::b64url(random_bytes(16)));
	$member_vault->save();
	$member_vault->load();
	harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', intval($member_vault->key));
	check(mailbox_protected_grant_error($dom, array(intval($member->key))) === null,
		'exactly one member, holding a vault, is the accepted shape');

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
	// Cleared directly, not through sync_for_alias: emptying the holder list of a
	// sealing mailbox is exactly what the invariant refuses, and teardown is not
	// a place to argue with it.
	harness_defer(function () use ($alias) {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('DELETE FROM ieg_inbound_email_mailbox_grants
			WHERE ieg_iea_inbound_email_alias_id = ?');
		$stmt->execute(array(intval($alias->key)));
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
		harness_register_model('InboundEmailMessage', intval($msg->key));
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
		vault_fixture_key(SealedBox::b64url(sodium_crypto_box_secretkey($keypair))));
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
	harness_register_model('InboundEmailMessage', intval($omsg->key));

	$result = mailbox_protection_seal_batch($dom, 200);
	check($result['sealed'] === 0 && $result['remaining'] === 1,
		'a holderless mailbox\'s rows are skipped and stay counted', json_encode($result));

} catch (\Throwable $e) {
	check(false, 'uncaught ' . get_class($e), $e->getMessage());
}

harness_finish();
