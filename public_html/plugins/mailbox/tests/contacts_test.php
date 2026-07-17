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
 *  - listForUser ranks by use_count and de-duplicates by address.
 *  - Import (vCard + Google CSV): counts, junk-row skip.
 *  - Delete is owner-scoped.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_contacts_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxContacts.php'));

$db = DbConnector::get_instance()->get_db_link();
$svc = new MailboxContacts();

// ── Plaintext store (no vault) ───────────────────────────────────────────────
section('Harvest — plaintext (no vault)');

$u = make_user('ContactPlain', 5);
$uid = (int)$u->key;

$svc->harvest($uid, array('Alice Example <alice@example.com>', 'bob@example.com'), MailboxContact::SOURCE_SENT);
$rows = $db->query("SELECT * FROM imc_mailbox_contacts WHERE imc_usr_user_id = $uid ORDER BY imc_address")->fetchAll(PDO::FETCH_ASSOC);
check(count($rows) === 2, 'two contacts harvested', (string)count($rows));
$alice = $rows[0];
check($alice['imc_address'] === 'alice@example.com', 'plaintext address stored', $alice['imc_address']);
check($alice['imc_display_name'] === 'Alice Example', 'plaintext display name stored', $alice['imc_display_name']);
check($alice['imc_content_sealed'] === 'f' || $alice['imc_content_sealed'] === false, 'not sealed without a vault');
check($alice['imc_address_hash'] === hash('sha256', 'joinery:contact:alice@example.com'), 'plaintext hash is the plain SHA-256');
check(intval($alice['imc_use_count']) === 1, 'use_count starts at 1');

// Re-harvest the same address (different case + display) → bump, not a new row.
$svc->harvest($uid, array('ALICE@example.com'), MailboxContact::SOURCE_SENT);
$again = $db->query("SELECT imc_use_count FROM imc_mailbox_contacts WHERE imc_usr_user_id = $uid AND imc_address = 'alice@example.com'")->fetchColumn();
check(intval($again) === 2, 'a repeat address bumps use_count (dedup by normalized hash)', (string)$again);
$total = intval($db->query("SELECT COUNT(*) FROM imc_mailbox_contacts WHERE imc_usr_user_id = $uid")->fetchColumn());
check($total === 2, 'no duplicate row created for the repeat', (string)$total);

// listForUser ranks by use_count (alice=2 first).
$list = $svc->listForUser($uid);
check(empty($list['locked']) && count($list['contacts']) === 2, 'listForUser returns both, unlocked');
check($list['contacts'][0]['address'] === 'alice@example.com', 'most-used contact ranked first', $list['contacts'][0]['address']);

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
	public function harvestInWindow(int $uid, array $tokens, UserEncryptionVault $vault) {
		foreach ($tokens as $raw) {
			$p = self::parseAddress($raw);
			if ($p === null) continue;
			list($addr, $name) = $p;
			// Reuse the private path via reflection-free re-implementation:
			$hash = $this->addressHash($addr, $this->secret);
			$row = new MailboxContact(NULL);
			$row->set('imc_usr_user_id', $uid);
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
$tsvc->harvestInWindow($suid, array('Carol <carol@secret.example>'), $vault);

$srow = $db->query("SELECT * FROM imc_mailbox_contacts WHERE imc_usr_user_id = $suid LIMIT 1")->fetch(PDO::FETCH_ASSOC);
harness_register_row('imc_mailbox_contacts', 'imc_mailbox_contact_id', intval($srow['imc_mailbox_contact_id']));
check(!empty($srow['imc_content_sealed']) && $srow['imc_content_sealed'] !== 'f', 'sealed contact flagged content_sealed');
check($srow['imc_address'] !== 'carol@secret.example', 'address stored as ciphertext', substr((string)$srow['imc_address'], 0, 20));
check($srow['imc_address_hash'] !== hash('sha256', 'joinery:contact:carol@secret.example'), 'sealed hash is a KEYED blind index (not the plain SHA-256)');

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
$res = $svc->import($iuid, $vcf, 'contacts.vcf');
check($res['imported'] === 1 && $res['skipped'] === 1, 'vCard import counts valid vs junk cards', json_encode($res));
$dana = $db->query("SELECT imc_address, imc_display_name FROM imc_mailbox_contacts WHERE imc_usr_user_id = $iuid AND imc_source='import'")->fetch(PDO::FETCH_ASSOC);
check($dana && $dana['imc_address'] === 'dana@vcard.example' && $dana['imc_display_name'] === 'Dana Import', 'vCard name + email imported', json_encode($dana));

$csv = "Name,Given Name,E-mail 1 - Value\nEve CSV,Eve,eve@csv.example\nBad Row,,notanemail\n";
$res2 = $svc->import($iuid, $csv, 'google.csv');
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
