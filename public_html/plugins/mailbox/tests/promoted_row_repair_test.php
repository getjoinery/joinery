<?php
/** @joinery-test
 * name: promoted_row_repair
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A Sent-folder direction promotion on a sealed mailbox leaves iem_recipient
 * plaintext on a sealed outbound row (specs/bugfix_promoted_sent_row_sealing.md).
 * This pins the whole repair contract:
 *
 *  - markDirectionOutbound() records the debt (iem_reseal_pending) on a sealed
 *    row, and only on a sealed row.
 *  - The read path hands the plaintext back as the true recipient — outbound
 *    only, recipient only; every other plaintext-on-sealed shape still trips
 *    the corruption check.
 *  - A damaged column renders a placeholder WITHOUT raising the thread's
 *    "unlock your vault" banner; a genuinely locked window still raises it.
 *  - PromotedRowRepair::drainForUser() seals the recipient under the row's
 *    EXISTING DEK and clears the flag; hasWork() finds the debt with or
 *    without the flag and goes quiet once paid.
 *  - A repaired row that duplicates the composer's copy (same alias +
 *    Message-ID, sibling with a SEALED recipient) is retired into it: locator
 *    adopted by the keeper, stripped from the duplicate BEFORE its
 *    soft-delete (so ImapSyncer::pushTrash can never relocate the provider's
 *    copy), duplicate soft-deleted.
 *
 * Run: php tests/run.php db --filter=promoted_row_repair
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
if (!PluginHelper::isPluginActive('mailbox')) {
	harness_skip('mailbox plugin inactive');
	harness_finish();
}
if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}

require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/PromotedRowRepair.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));

$db = DbConnector::get_instance()->get_db_link();
$box = new SealedBox();
$crypto = new VaultCrypto();

/** A Postgres boolean off PDO, whichever shape the driver hands back. */
function pg_truth($v): bool {
	return $v === true || $v === 't' || $v === 'true' || $v === 1 || $v === '1';
}

// ---- Fixtures ------------------------------------------------------------
$user = make_user('PromRepair');
$uid = (int)$user->key;
$kp = $box->generateKeypair();

$vault = new UserEncryptionVault(NULL);
$vault->set('uev_usr_user_id', $uid);
$vault->set('uev_scope', UserEncryptionVault::SCOPE_USER);
$vault->set('uev_custody', UserEncryptionVault::CUSTODY_SERVER);
$vault->set('uev_public_key', $kp['public']);
$vault->set('uev_salt', $box->generateSalt());
$vault->set('uev_key_generation', 1);
$vault->save();
harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', (int)$vault->key);

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', 'promrepair-' . bin2hex(random_bytes(4)) . '.example');
$domain->set('ied_owner_usr_user_id', $uid);
$domain->set('ied_is_protected_identity', true);
$domain->set('ied_security_level', 'private');
$domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);

$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
$alias->set('iea_alias', 'inbox');
$alias->set('iea_delivery_mode', 'store');
$alias->save();
harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', (int)$alias->key);
$alias_addr = 'inbox@' . $domain->get('ied_domain');

$grant = new InboundEmailMailboxGrant(NULL);
$grant->set('ieg_iea_inbound_email_alias_id', (int)$alias->key);
$grant->set('ieg_usr_user_id', $uid);
$grant->save();
harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', (int)$grant->key);

// A minimal account: markDirectionOutbound needs an ingestor instance, and the
// duplicate-merge case needs a locator to move.
$account = new InboundImapAccount(NULL);
$account->set('iia_label', 'PromRepair');
$account->set('iia_provider_key', 'imap_generic');
$account->set('iia_imap_host', 'imap.test');
$account->set('iia_iea_inbound_email_alias_id', (int)$alias->key);
$account->set('iia_username', $alias_addr);
$account->set('iia_is_enabled', true);
$account->prepare();
$account->save();
harness_register_row('iia_inbound_imap_accounts', 'iia_inbound_imap_account_id', (int)$account->key);

$promote = function (int $message_id) use ($account) {
	$ingestor = new ImapIngestor(new InboundImapAccount((int)$account->key, TRUE));
	$m = new ReflectionMethod(ImapIngestor::class, 'markDirectionOutbound');
	$m->setAccessible(true);
	$m->invoke($ingestor, $message_id);
};

/** A stored inbound row: plaintext recipient (the routing alias), then sealed. */
$make_inbound = function (string $mid_header, string $thread_key, bool $seal) use ($domain, $alias, $alias_addr, $vault) {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', (int)$domain->key);
	$m->set('iem_iea_inbound_email_alias_id', (int)$alias->key);
	$m->set('iem_recipient', $alias_addr);
	$m->set('iem_sender', 'me@source.example');
	$m->set('iem_subject', 'a sent message');
	$m->set('iem_body_plain', 'body');
	$m->set('iem_body_html', '');
	$m->set('iem_message_id_header', $mid_header);
	$m->set('iem_thread_key', $thread_key);
	$m->save();
	harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', (int)$m->key);
	if ($seal) {
		InboundEmailMessage::sealAndPersistContent((int)$m->key, $vault,
			'me@source.example', $alias_addr, 'a sent message', 'body', '');
	}
	return (int)$m->key;
};

$read_row = function (int $id) use ($db) {
	$stmt = $db->prepare('SELECT * FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
	$stmt->execute(array($id));
	return $stmt->fetch(PDO::FETCH_ASSOC);
};

$suffix = bin2hex(random_bytes(4));

// ---- Promotion records the debt -------------------------------------------
section('Promotion records the sealing debt');

$sealed_id = $make_inbound('<sealed-' . $suffix . '@x.example>', 'tk-sealed-' . $suffix, true);
$promote($sealed_id);
$row = $read_row($sealed_id);
check($row['iem_direction'] === 'outbound', 'sealed row promoted to outbound');
check(pg_truth($row['iem_reseal_pending']), 'promotion of a sealed row records the reseal debt');
check($row['iem_recipient'] === $alias_addr, 'the recipient is still the inbound-written plaintext');

$plain_id = $make_inbound('<plain-' . $suffix . '@x.example>', 'tk-plain-' . $suffix, false);
$promote($plain_id);
$row = $read_row($plain_id);
check($row['iem_direction'] === 'outbound', 'unsealed row promoted to outbound');
check(!pg_truth($row['iem_reseal_pending']), 'an unsealed row owes nothing — flag stays down');

// ---- Read-path tolerance ---------------------------------------------------
section('Read path hands back the true recipient — and only that');

$row = $read_row($sealed_id);
$got = InboundEmailMessage::decryptSealedFieldStatic('iem_recipient', $row['iem_recipient'], $row);
check($got === $alias_addr, 'plaintext recipient on the promoted sealed row reads back as the true value');

$threw = false;
try {
	InboundEmailMessage::decryptSealedFieldStatic('iem_subject', 'not a sealed blob', $row);
} catch (RuntimeException $e) {
	$threw = true;
}
check($threw, 'plaintext in any OTHER sealed column still trips the corruption check');

$draft_row = $row;
$draft_row['iem_direction'] = 'draft';
$threw = false;
try {
	InboundEmailMessage::decryptSealedFieldStatic('iem_recipient', $alias_addr, $draft_row);
} catch (RuntimeException $e) {
	$threw = true;
}
check($threw, 'a plaintext recipient on a sealed DRAFT still trips it — the tolerance is outbound-only');

// ---- Banner semantics ------------------------------------------------------
// CLI holds no unlock window, so a properly sealed column reads as locked here —
// which is exactly the contrast needed: locked raises the banner, damage doesn't.
section('The sealed banner means locked, not damaged');

$svc = new MailboxService(MailboxViewer::forUser($uid, 5));

$messages = $svc->getThread((int)$alias->key, 'tk-sealed-' . $suffix);
check(count($messages) === 1, 'promoted row resolves through its thread');
check(($messages[0]['recipient'] ?? '') === $alias_addr,
	'the thread renders the plaintext recipient, not a sealed placeholder');

// Damaged row: sealed flag up, subject holds plaintext, every other sealed
// column empty (an empty value on a sealed row is nothing, never decrypted).
$damaged_id = $make_inbound('<damaged-' . $suffix . '@x.example>', 'tk-damaged-' . $suffix, false);
$stmt = $db->prepare("UPDATE iem_inbound_email_messages
	SET iem_content_sealed = true, iem_sealed_key = ?, iem_sealed_owner_user_id = ?,
	    iem_sender = '', iem_body_plain = '', iem_body_html = ''
	WHERE iem_inbound_email_message_id = ?");
$stmt->execute(array($crypto->sealItemDek(random_bytes(32), $kp['public']), $uid, $damaged_id));

$messages = $svc->getThread((int)$alias->key, 'tk-damaged-' . $suffix);
check(($messages[0]['subject'] ?? '') === MailboxService::SEALED_PLACEHOLDER,
	'the damaged column renders a placeholder');
check(!$svc->contentLocked(),
	'…but does NOT raise the banner — no unlock fixes a damaged column');

// Locked row: subject properly sealed; with no window (CLI), that is a real
// locked read and the banner must come up.
$locked_id = $make_inbound('<locked-' . $suffix . '@x.example>', 'tk-locked-' . $suffix, false);
$stmt = $db->prepare("UPDATE iem_inbound_email_messages
	SET iem_sender = '', iem_body_plain = '', iem_body_html = ''
	WHERE iem_inbound_email_message_id = ?");
$stmt->execute(array($locked_id));
InboundEmailMessage::sealColumns($locked_id, $vault, array('iem_subject' => 'sealed subject'));

$messages = $svc->getThread((int)$alias->key, 'tk-locked-' . $suffix);
check(($messages[0]['subject'] ?? '') === MailboxService::SEALED_PLACEHOLDER,
	'the locked column renders a placeholder');
check($svc->contentLocked(), 'a genuinely locked window still raises the banner');

// ---- The drain pays the debt ----------------------------------------------
section('Drain seals the recipient under the existing DEK');

check(PromotedRowRepair::hasWork($uid), 'hasWork sees the promoted row');

// Clear the flag but leave the plaintext: the predicate must find the row
// anyway — that self-discovery is what heals rows broken before the flag existed.
$stmt = $db->prepare('UPDATE iem_inbound_email_messages SET iem_reseal_pending = false
	WHERE iem_inbound_email_message_id = ?');
$stmt->execute(array($sealed_id));
check(PromotedRowRepair::hasWork($uid), 'hasWork finds the debt with the flag down (pre-flag rows heal too)');

$done = PromotedRowRepair::drainForUser($uid, $kp['secret']);
check($done >= 1, 'drain repaired the row');

$row = $read_row($sealed_id);
check(strpos((string)$row['iem_recipient'], 'v1.aead.') === 0, 'recipient is sealed now');
check(!pg_truth($row['iem_reseal_pending']), 'flag cleared');
$dek = $crypto->openItemDek($row['iem_sealed_key'], $kp['secret']);
$opened = $crypto->openField($row['iem_recipient'], $dek,
	InboundEmailMessage::sealAd($sealed_id, 'iem_recipient'));
check($opened === $alias_addr, 'sealed under the row\'s EXISTING DEK — original value, same key as the body');
check(!PromotedRowRepair::hasWork($uid), 'debt paid — hasWork goes quiet');

// ---- Duplicate retirement ---------------------------------------------------
section('A promoted duplicate retires into the composer\'s copy');

// The composer's copy: outbound, recipient sealed at store (the compose path).
$header = '<dup-' . $suffix . '@x.example>';
$keeper = new InboundEmailMessage(NULL);
$keeper->set('iem_ied_inbound_email_domain_id', (int)$domain->key);
$keeper->set('iem_iea_inbound_email_alias_id', (int)$alias->key);
$keeper->set('iem_recipient', 'friend@example.org');
$keeper->set('iem_sender', $alias_addr);
$keeper->set('iem_subject', 'a sent message');
$keeper->set('iem_body_plain', 'body');
$keeper->set('iem_body_html', '');
$keeper->set('iem_message_id_header', $header);
$keeper->set('iem_thread_key', 'tk-dup-' . $suffix);
$keeper->set('iem_direction', 'outbound');
$keeper->save();
$keeper_id = (int)$keeper->key;
harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', $keeper_id);
InboundEmailMessage::sealAndPersistContent($keeper_id, $vault, $alias_addr,
	'friend@example.org', 'a sent message', 'body', '', true);

// The coverage-pass duplicate: inbound + locator, then promoted.
$dup_id = $make_inbound($header, 'tk-dup-' . $suffix, true);
$stmt = $db->prepare('UPDATE iem_inbound_email_messages
	SET iem_iia_inbound_imap_account_id = ?, iem_imap_uid = 42, iem_imap_uidvalidity = 7,
	    iem_imap_folder = ?
	WHERE iem_inbound_email_message_id = ?');
$stmt->execute(array((int)$account->key, '[Gmail]/All Mail', $dup_id));
$promote($dup_id);
check($read_row($dup_id)['iem_direction'] === 'outbound',
	'duplicate promoted (the sealed sibling recipient is ciphertext, so the stand-down guard cannot see it)');

$done = PromotedRowRepair::drainForUser($uid, $kp['secret']);
check($done >= 1, 'drain processed the duplicate');

$dup = $read_row($dup_id);
check($dup['iem_delete_time'] !== null, 'duplicate soft-deleted');
check($dup['iem_iia_inbound_imap_account_id'] === null,
	'duplicate stripped of its locator BEFORE deletion — pushTrash can never relocate the provider copy');
check(strpos((string)$dup['iem_recipient'], 'v1.aead.') === 0,
	'even the retired copy obeys the sealing contract (Trash renders it)');

$kept = $read_row($keeper_id);
check($kept['iem_delete_time'] === null, 'composer copy stays live');
check(intval($kept['iem_iia_inbound_imap_account_id']) === (int)$account->key
		&& intval($kept['iem_imap_uid']) === 42,
	'composer copy adopted the locator — its parts stay fetchable');

check(!PromotedRowRepair::hasWork($uid), 'nothing left owing');

harness_finish();
