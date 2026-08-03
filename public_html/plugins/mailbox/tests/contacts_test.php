<?php
/** @joinery-test
 * name: contacts
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Compose maturity Phase 4 — contact store (specs/mailbox_compose_maturity.md § Phase 4).
 *
 * Covers:
 *  - Harvest upsert: a new address inserts; a repeat bumps use_count (dedup by hash).
 *  - Plaintext store (no vault): address + name stored cleartext, hash = SHA-256.
 *  - Sealed store (vault): address/name are ciphertext, hash is a keyed blind index
 *    (does NOT equal the plain SHA-256), and opens back under the owner's key.
 *  - listForMailbox ranks by use_count and de-duplicates by address.
 *  - MAILBOX SCOPE: the same address harvested on two mailboxes is two independent
 *    rows, and neither mailbox's list or lookup can see the other's.
 *  - Import (vCard + Google CSV): counts, junk-row skip.
 *  - Delete is owner-scoped.
 *  - lookup(): a harvested address reads back as seen-not-saved; a deliberate add stamps
 *    it saved and fills a missing display name; an unknown address returns null.
 *
 * @version 1.2
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_contacts_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxContacts.php'));

$db = DbConnector::get_instance()->get_db_link();
$svc = new MailboxContacts();

// ── Fixtures: two mailboxes, so scope can actually be tested ─────────────────
// A contact row carries a real alias FK, so these are genuine aliases rather than
// invented ids.
$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', 'contact-' . bin2hex(random_bytes(4)) . '.example');
$domain->set('ied_is_enabled', true);
$domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);

$make_alias = function ($local) use ($domain) {
	$a = new InboundEmailAlias(NULL);
	$a->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
	$a->set('iea_alias', $local);
	$a->set('iea_delivery_mode', 'store');
	$a->set('iea_is_enabled', true);
	$a->prepare();
	$a->save();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', (int)$a->key);
	return (int)$a->key;
};
$work_alias     = $make_alias('work');
$personal_alias = $make_alias('personal');

// ── Plaintext store (no vault) ───────────────────────────────────────────────
section('Harvest — plaintext (no vault)');

$u = make_user('ContactPlain', 5);
$uid = (int)$u->key;

$svc->harvest($uid, array('Alice Example <alice@example.com>', 'bob@example.com'), MailboxContact::SOURCE_SENT, $work_alias);
$rows = $db->query("SELECT * FROM imc_mailbox_contacts WHERE imc_usr_user_id = $uid ORDER BY imc_address")->fetchAll(PDO::FETCH_ASSOC);
check(count($rows) === 2, 'two contacts harvested', (string)count($rows));
$alice = $rows[0];
check($alice['imc_address'] === 'alice@example.com', 'plaintext address stored', $alice['imc_address']);
check($alice['imc_display_name'] === 'Alice Example', 'plaintext display name stored', $alice['imc_display_name']);
check($alice['imc_content_sealed'] === 'f' || $alice['imc_content_sealed'] === false, 'not sealed without a vault');
check($alice['imc_address_hash'] === hash('sha256', 'joinery:contact:' . $work_alias . ':alice@example.com'), 'plaintext hash is the plain SHA-256 over mailbox + address');
check(intval($alice['imc_use_count']) === 1, 'use_count starts at 1');
check(intval($alice['imc_iea_inbound_email_alias_id']) === $work_alias, 'the harvest recorded its mailbox', (string)$alice['imc_iea_inbound_email_alias_id']);

// Re-harvest the same address (different case + display) → bump, not a new row.
$svc->harvest($uid, array('ALICE@example.com'), MailboxContact::SOURCE_SENT, $work_alias);
$again = $db->query("SELECT imc_use_count FROM imc_mailbox_contacts WHERE imc_usr_user_id = $uid AND imc_address = 'alice@example.com'")->fetchColumn();
check(intval($again) === 2, 'a repeat address bumps use_count (dedup by normalized hash)', (string)$again);
$total = intval($db->query("SELECT COUNT(*) FROM imc_mailbox_contacts WHERE imc_usr_user_id = $uid")->fetchColumn());
check($total === 2, 'no duplicate row created for the repeat', (string)$total);

// listForMailbox ranks by use_count (alice=2 first).
$list = $svc->listForMailbox($uid, $work_alias);
check(empty($list['locked']) && count($list['contacts']) === 2, 'listForMailbox returns both, unlocked');
check($list['contacts'][0]['address'] === 'alice@example.com', 'most-used contact ranked first', $list['contacts'][0]['address']);

// ── Mailbox scope ────────────────────────────────────────────────────────────
// The whole point of scoping: what you harvest writing as work@ must not surface
// while composing as personal@.
section('Mailbox scope');

$empty = $svc->listForMailbox($uid, $personal_alias);
check(count($empty['contacts']) === 0, 'a second mailbox starts with its own empty store', (string)count($empty['contacts']));
check($svc->lookup($uid, 'alice@example.com', $personal_alias) === null, 'a contact of one mailbox does not look up in another');

// The SAME address on the second mailbox is a separate row, not a bump.
$svc->harvest($uid, array('alice@example.com'), MailboxContact::SOURCE_SENT, $personal_alias);
$alice_rows = intval($db->query("SELECT COUNT(*) FROM imc_mailbox_contacts WHERE imc_usr_user_id = $uid AND imc_address = 'alice@example.com'")->fetchColumn());
check($alice_rows === 2, 'the same address on two mailboxes is two rows', (string)$alice_rows);
$work_alice = $svc->lookup($uid, 'alice@example.com', $work_alias);
$pers_alice = $svc->lookup($uid, 'alice@example.com', $personal_alias);
check($work_alice && intval($work_alice['use_count']) === 2, 'the first mailbox keeps its own use_count', $work_alice ? (string)$work_alice['use_count'] : '');
check($pers_alice && intval($pers_alice['use_count']) === 1, 'the second mailbox counts independently', $pers_alice ? (string)$pers_alice['use_count'] : '');
check(count($svc->listForMailbox($uid, $personal_alias)['contacts']) === 1, 'the second mailbox lists only its own contact');

// A harvest naming no mailbox is dropped rather than stored unscoped.
$svc->harvest($uid, array('nowhere@example.com'), MailboxContact::SOURCE_SENT, 0);
check(intval($db->query("SELECT COUNT(*) FROM imc_mailbox_contacts WHERE imc_usr_user_id = $uid AND imc_address = 'nowhere@example.com'")->fetchColumn()) === 0,
	'a harvest with no mailbox is dropped, not stored unscoped');

// ── Lookup: seen vs saved ────────────────────────────────────────────────────
section('Lookup — seen vs saved');

$look = $svc->lookup($uid, 'ALICE@example.com', $work_alias);
check($look !== null, 'lookup finds a harvested address (case-insensitive)');
check($look && $look['saved'] === false, 'a harvested contact is seen, not saved', $look ? $look['source'] : '');
check($look && intval($look['use_count']) === 2, 'lookup reports use_count', $look ? (string)$look['use_count'] : '');
check($svc->lookup($uid, 'nobody@example.com', $work_alias) === null, 'an unknown address looks up as null');

// A deliberate add stamps the existing row saved rather than inserting a second one.
check($svc->manualAdd($uid, 'bob@example.com', $work_alias) === true, 'manual add accepts a bare address');
$after = $svc->lookup($uid, 'bob@example.com', $work_alias);
check($after && $after['saved'] === true, 'a deliberate add marks the contact saved', $after ? $after['source'] : '');
$bob_rows = intval($db->query("SELECT COUNT(*) FROM imc_mailbox_contacts WHERE imc_usr_user_id = $uid AND imc_address = 'bob@example.com'")->fetchColumn());
check($bob_rows === 1, 'adding an already-harvested address does not duplicate the row', (string)$bob_rows);
check($after && $after['name'] === '', 'bob still has no display name (none was harvested)', $after ? $after['name'] : '');

// Adding with a display name fills one the harvest never captured.
$svc->manualAdd($uid, 'Bob Builder <bob@example.com>', $work_alias);
$named = $svc->lookup($uid, 'bob@example.com', $work_alias);
check($named && $named['name'] === 'Bob Builder', 'a named add fills the missing display name', $named ? $named['name'] : '');

check($svc->manualAdd($uid, 'not-an-address', $work_alias) === false, 'manual add rejects a non-address');
check($svc->manualAdd($uid, 'valid@example.com', 0) === false, 'manual add refuses when no mailbox is named');

// ── Sealed store (vault) ─────────────────────────────────────────────────────
section('Harvest — sealed (vault)');

$box = new SealedBox();
$vc = new VaultCrypto();
$su = make_user('ContactSealed', 5);
$suid = (int)$su->key;
$kp = $box->generateKeypair();

$vault = new UserEncryptionVault(NULL);
$vault->set('uev_usr_user_id', $suid);
$vault->set('uev_scope', UserEncryptionVault::SCOPE_USER);
$vault->set('uev_custody', UserEncryptionVault::CUSTODY_SERVER);
$vault->set('uev_public_key', $kp['public']);
$vault->set('uev_salt', $box->generateSalt());
$vault->set('uev_key_generation', 1);
$vault->save();
harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', (int)$vault->key);

// No browser session in CLI, so VaultUnlock::secretKey() is null → the service
// would skip harvest. Drive the sealed path directly with the keypair secret via a
// tiny subclass that supplies the secret (mirrors an in-window harvest).
class TestSealedContacts extends MailboxContacts {
	public $secret;
	public $alias_id = 0;
	public function harvestInWindow(int $uid, array $tokens, UserEncryptionVault $vault) {
		foreach ($tokens as $raw) {
			$p = self::parseAddress($raw);
			if ($p === null) continue;
			list($addr, $name) = $p;
			// Reuse the private path via reflection-free re-implementation:
			$hash = $this->addressHash($addr, $this->secret, $this->alias_id);
			$row = new MailboxContact(NULL);
			$row->set('imc_usr_user_id', $uid);
			$row->set('imc_iea_inbound_email_alias_id', $this->alias_id);
			$row->set('imc_address_hash', $hash);
			$row->set('imc_source', 'sent');
			$row->set('imc_address', '');
			$row->set('imc_display_name', '');
			$row->save();
			$crypto = new VaultCrypto();
			$dek = $crypto->newItemDek();
			$sk = $crypto->sealItemDek($dek, (string)$vault->get('uev_public_key'));
			$db = DbConnector::get_instance()->get_db_link();
			$db->prepare('UPDATE imc_mailbox_contacts SET imc_address=?, imc_display_name=?, imc_sealed_key=?, imc_key_generation=?, imc_sealed_owner_user_id=?, imc_content_sealed=true WHERE imc_mailbox_contact_id=?')
				->execute(array(
					$crypto->sealField($addr, $dek, MailboxContact::sealAd((int)$row->key, 'imc_address')),
					$crypto->sealField($name, $dek, MailboxContact::sealAd((int)$row->key, 'imc_display_name')),
					$sk, 1, $uid, (int)$row->key));
		}
	}
}
$tsvc = new TestSealedContacts();
$tsvc->secret = $kp['secret'];
$tsvc->alias_id = $work_alias;
$tsvc->harvestInWindow($suid, array('Carol <carol@secret.example>'), $vault);

$srow = $db->query("SELECT * FROM imc_mailbox_contacts WHERE imc_usr_user_id = $suid LIMIT 1")->fetch(PDO::FETCH_ASSOC);
harness_register_row('imc_mailbox_contacts', 'imc_mailbox_contact_id', intval($srow['imc_mailbox_contact_id']));
check(!empty($srow['imc_content_sealed']) && $srow['imc_content_sealed'] !== 'f', 'sealed contact flagged content_sealed');
check($srow['imc_address'] !== 'carol@secret.example', 'address stored as ciphertext', substr((string)$srow['imc_address'], 0, 20));
check($srow['imc_address_hash'] !== hash('sha256', 'joinery:contact:' . $work_alias . ':carol@secret.example'), 'sealed hash is a KEYED blind index (not the plain SHA-256)');

$cid = intval($srow['imc_mailbox_contact_id']);
$dek = $vc->openItemDek($srow['imc_sealed_key'], $kp['secret']);
$opened = $vc->openField($srow['imc_address'], $dek, MailboxContact::sealAd($cid, 'imc_address'));
check($opened === 'carol@secret.example', 'sealed address opens back under the owner key + AD', $opened);
$openedName = $vc->openField($srow['imc_display_name'], $dek, MailboxContact::sealAd($cid, 'imc_display_name'));
check($openedName === 'Carol', 'sealed display name opens back', $openedName);

// ── Import ───────────────────────────────────────────────────────────────────
section('Import (vCard + CSV)');

$iu = make_user('ContactImport', 5);
$iuid = (int)$iu->key;

$vcf = "BEGIN:VCARD\nVERSION:3.0\nFN:Dana Import\nEMAIL:dana@vcard.example\nEND:VCARD\n"
	 . "BEGIN:VCARD\nFN:No Email\nTEL:555\nEND:VCARD\n";
$res = $svc->import($iuid, $vcf, 'contacts.vcf', $work_alias);
check($res['imported'] === 1 && $res['skipped'] === 1, 'vCard import counts valid vs junk cards', json_encode($res));
$dana = $db->query("SELECT imc_address, imc_display_name FROM imc_mailbox_contacts WHERE imc_usr_user_id = $iuid AND imc_source='import'")->fetch(PDO::FETCH_ASSOC);
check($dana && $dana['imc_address'] === 'dana@vcard.example' && $dana['imc_display_name'] === 'Dana Import', 'vCard name + email imported', json_encode($dana));

$csv = "Name,Given Name,E-mail 1 - Value\nEve CSV,Eve,eve@csv.example\nBad Row,,notanemail\n";
$res2 = $svc->import($iuid, $csv, 'google.csv', $work_alias);
check($res2['imported'] === 1 && $res2['skipped'] === 1, 'Google CSV import counts valid vs junk rows', json_encode($res2));
$eve = intval($db->query("SELECT COUNT(*) FROM imc_mailbox_contacts WHERE imc_usr_user_id = $iuid AND imc_address='eve@csv.example'")->fetchColumn());
check($eve === 1, 'CSV email imported', (string)$eve);

// ── Delete (owner-scoped) ────────────────────────────────────────────────────
section('Delete');
$del_id = intval($db->query("SELECT imc_mailbox_contact_id FROM imc_mailbox_contacts WHERE imc_usr_user_id = $iuid LIMIT 1")->fetchColumn());
$other = make_user('ContactOther', 5);
check($svc->deleteContact((int)$other->key, $del_id) === false, 'a non-owner cannot delete a contact');
check($svc->deleteContact($iuid, $del_id) === true, 'the owner can delete their contact');
check(intval($db->query("SELECT COUNT(*) FROM imc_mailbox_contacts WHERE imc_mailbox_contact_id = $del_id")->fetchColumn()) === 0, 'the contact row is gone');

harness_finish();
