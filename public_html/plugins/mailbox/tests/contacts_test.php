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
 *  - DELIBERATE ENTRY ONLY: manualAdd() and import() are the only public writers, so mail
 *    traffic cannot file a contact. Guarded by the public surface itself, not by a comment.
 *  - Add upsert: a new address inserts; a re-add bumps use_count (dedup by hash).
 *  - THE POSTURE SWITCH: a row seals only when the owner holds a vault AND the
 *    mailbox's domain seals content — the same condition the mail beside it uses.
 *    Vault possession alone is not enough, because sealing an address book while
 *    the mail it describes sits in plaintext protects nothing (every correspondent
 *    is already visible in that mail) and would cost the Joinery Direct contact
 *    gate its readability at Standard.
 *  - Plaintext store: address + name stored cleartext, hash = SHA-256.
 *  - Sealed store: address/name are ciphertext, hash is a keyed blind index
 *    (does NOT equal the plain SHA-256), and opens back under the owner's key.
 *  - listForMailbox ranks by use_count and de-duplicates by address.
 *  - MAILBOX SCOPE: the same address added on two mailboxes is two independent rows,
 *    and neither mailbox's list or lookup can see the other's.
 *  - Import (vCard + Google CSV): counts, junk-row skip, and a later hand-add re-stamps
 *    an imported row rather than duplicating it.
 *  - lookup(): reports how the row got there; an unknown address returns null.
 *  - Delete is owner-scoped.
 *
 * @version 2.1 - sealing follows the domain's posture, not vault possession alone
 * @version 2.0
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

// A second domain that SEALS content, so both sides of the posture switch are
// exercised against the real service rather than only the plaintext side.
$sealed_domain = new InboundEmailDomain(NULL);
$sealed_domain->set('ied_domain', 'contact-sealed-' . bin2hex(random_bytes(4)) . '.example');
$sealed_domain->set('ied_is_enabled', true);
$sealed_domain->set('ied_security_level', InboundEmailDomain::LEVEL_PRIVATE);
$sealed_domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$sealed_domain->key);

$sealed_alias = (function () use ($sealed_domain) {
	$a = new InboundEmailAlias(NULL);
	$a->set('iea_ied_inbound_email_domain_id', (int)$sealed_domain->key);
	$a->set('iea_alias', 'private');
	$a->set('iea_delivery_mode', 'store');
	$a->set('iea_is_enabled', true);
	$a->prepare();
	$a->save();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', (int)$a->key);
	return (int)$a->key;
})();

// ── Nothing files a contact by itself ────────────────────────────────────────
// The regression this file exists to hold down: mail traffic used to write here, so
// every address that ever sent you spam became a contact. The guard is the public
// surface — if a traffic-driven writer is added back, this fails.
section('Deliberate entry only');

$public = array();
foreach ((new ReflectionClass('MailboxContacts'))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
	if ($m->isStatic() || $m->getDeclaringClass()->getName() !== 'MailboxContacts') { continue; }
	$public[] = $m->getName();
}
sort($public);
// addressHash, aliasHasContact, listForMailbox and lookup are READERS; the only
// writers are manualAdd() and import(). aliasHasContact answers the Direct contact
// gate for a shared mailbox and writes nothing.
$expected = array('addressHash', 'aliasHasContact', 'deleteContact', 'import', 'listForMailbox', 'lookup', 'manualAdd');
check($public === $expected,
	'the only public writers are manualAdd() and import() — no traffic-driven entry point',
	implode(',', $public));

check(!defined('MailboxContact::SOURCE_SENT') && !defined('MailboxContact::SOURCE_RECEIVED'),
	'there is no sent/received contact source to write');
check(MailboxContact::SOURCE_MANUAL === 'manual' && MailboxContact::SOURCE_IMPORT === 'import',
	'the two sources that remain are both deliberate acts');

// ── Plaintext store (no vault) ───────────────────────────────────────────────
section('Add — plaintext (no vault)');

$u = make_user('ContactPlain', 5);
$uid = (int)$u->key;

check($svc->manualAdd($uid, 'Alice Example <alice@example.com>', $work_alias) === true, 'a named add succeeds');
check($svc->manualAdd($uid, 'bob@example.com', $work_alias) === true, 'a bare-address add succeeds');
$rows = $db->query("SELECT * FROM imc_mailbox_contacts WHERE imc_usr_user_id = $uid ORDER BY imc_address")->fetchAll(PDO::FETCH_ASSOC);
check(count($rows) === 2, 'two contacts stored', (string)count($rows));
$alice = $rows[0];
check($alice['imc_address'] === 'alice@example.com', 'plaintext address stored', $alice['imc_address']);
check($alice['imc_display_name'] === 'Alice Example', 'plaintext display name stored', $alice['imc_display_name']);
check($alice['imc_content_sealed'] === 'f' || $alice['imc_content_sealed'] === false, 'not sealed without a vault');
check($alice['imc_address_hash'] === hash('sha256', 'joinery:contact:' . $work_alias . ':alice@example.com'), 'plaintext hash is the plain SHA-256 over mailbox + address');
check(intval($alice['imc_use_count']) === 1, 'use_count starts at 1');
check($alice['imc_source'] === 'manual', 'a hand-added row records that it was added by hand', $alice['imc_source']);
check(intval($alice['imc_iea_inbound_email_alias_id']) === $work_alias, 'the add recorded its mailbox', (string)$alice['imc_iea_inbound_email_alias_id']);

// Re-add the same address (different case) → bump, not a new row.
$svc->manualAdd($uid, 'ALICE@example.com', $work_alias);
$again = $db->query("SELECT imc_use_count FROM imc_mailbox_contacts WHERE imc_usr_user_id = $uid AND imc_address = 'alice@example.com'")->fetchColumn();
check(intval($again) === 2, 'a re-add bumps use_count (dedup by normalized hash)', (string)$again);
$total = intval($db->query("SELECT COUNT(*) FROM imc_mailbox_contacts WHERE imc_usr_user_id = $uid")->fetchColumn());
check($total === 2, 'no duplicate row created for the re-add', (string)$total);

// listForMailbox ranks by use_count (alice=2 first).
$list = $svc->listForMailbox($uid, $work_alias);
check(empty($list['locked']) && count($list['contacts']) === 2, 'listForMailbox returns both, unlocked');
check($list['contacts'][0]['address'] === 'alice@example.com', 'most-used contact ranked first', $list['contacts'][0]['address']);

// ── Mailbox scope ────────────────────────────────────────────────────────────
// The whole point of scoping: what you keep as work@ must not surface while
// composing as personal@.
section('Mailbox scope');

$empty = $svc->listForMailbox($uid, $personal_alias);
check(count($empty['contacts']) === 0, 'a second mailbox starts with its own empty store', (string)count($empty['contacts']));
check($svc->lookup($uid, 'alice@example.com', $personal_alias) === null, 'a contact of one mailbox does not look up in another');

// The SAME address on the second mailbox is a separate row, not a bump.
$svc->manualAdd($uid, 'alice@example.com', $personal_alias);
$alice_rows = intval($db->query("SELECT COUNT(*) FROM imc_mailbox_contacts WHERE imc_usr_user_id = $uid AND imc_address = 'alice@example.com'")->fetchColumn());
check($alice_rows === 2, 'the same address on two mailboxes is two rows', (string)$alice_rows);
check($svc->lookup($uid, 'alice@example.com', $work_alias) !== null, 'the first mailbox still holds its own row');
check(count($svc->listForMailbox($uid, $personal_alias)['contacts']) === 1, 'the second mailbox lists only its own contact');

// An add naming no mailbox is refused rather than stored unscoped.
check($svc->manualAdd($uid, 'nowhere@example.com', 0) === false, 'an add with no mailbox is refused');
check(intval($db->query("SELECT COUNT(*) FROM imc_mailbox_contacts WHERE imc_usr_user_id = $uid AND imc_address = 'nowhere@example.com'")->fetchColumn()) === 0,
	'and nothing was stored unscoped');

// ── Lookup ───────────────────────────────────────────────────────────────────
section('Lookup');

$look = $svc->lookup($uid, 'ALICE@example.com', $work_alias);
check($look !== null, 'lookup finds a stored address (case-insensitive)');
check($look && $look['source'] === 'manual', 'lookup reports how the row got here', $look ? $look['source'] : '');
check($look && !empty($look['added_time']), 'lookup reports when it was added', $look ? (string)$look['added_time'] : '');
check($look && !array_key_exists('saved', $look), 'there is no seen-vs-saved flag: every row is a deliberate save');
check($svc->lookup($uid, 'nobody@example.com', $work_alias) === null, 'an unknown address looks up as null');

// A later add with a display name fills one the first add never carried.
$bob = $svc->lookup($uid, 'bob@example.com', $work_alias);
check($bob && $bob['name'] === '', 'bob was added bare, so has no display name', $bob ? $bob['name'] : '');
$svc->manualAdd($uid, 'Bob Builder <bob@example.com>', $work_alias);
$named = $svc->lookup($uid, 'bob@example.com', $work_alias);
check($named && $named['name'] === 'Bob Builder', 'a named add fills the missing display name', $named ? $named['name'] : '');
$bob_rows = intval($db->query("SELECT COUNT(*) FROM imc_mailbox_contacts WHERE imc_usr_user_id = $uid AND imc_address = 'bob@example.com'")->fetchColumn());
check($bob_rows === 1, 'naming an existing contact does not duplicate the row', (string)$bob_rows);

check($svc->manualAdd($uid, 'not-an-address', $work_alias) === false, 'manual add rejects a non-address');

// ── Sealed store (vault) ─────────────────────────────────────────────────────
section('Add — sealed (vault)');

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

// No browser session in CLI, so VaultUnlock::secretKey() is null → the service would
// refuse the add. Drive the sealed path directly with the keypair secret via a tiny
// subclass that supplies the secret (mirrors an in-window add).
class TestSealedContacts extends MailboxContacts {
	public $secret;
	public $alias_id = 0;
	public function addInWindow(int $uid, array $tokens, UserEncryptionVault $vault) {
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
			$row->set('imc_source', MailboxContact::SOURCE_MANUAL);
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
$tsvc->addInWindow($suid, array('Carol <carol@secret.example>'), $vault);

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

// ── The posture switch ───────────────────────────────────────────────────────
// Sealing hangs off the MAILBOX's posture, not on whether the adding user happens
// to hold a vault. This is what makes the Joinery Direct contact gate able to run
// live at Standard: contacts are genuinely readable there, so no locked vault can
// block the gate and no lock-state oracle appears on the wire.
section('Sealing follows the mailbox posture, not vault possession');

// Same vault-holding user, Standard mailbox: the row stores like a no-vault
// user's, and the add succeeds with no unlock window anywhere in sight.
check($svc->manualAdd($suid, 'dave@standard.example', $work_alias) === true,
	'a vault holder adding on a STANDARD mailbox succeeds with no open window');
$plain = $db->query("SELECT * FROM imc_mailbox_contacts
	WHERE imc_usr_user_id = $suid AND imc_iea_inbound_email_alias_id = $work_alias
	  AND imc_address = 'dave@standard.example' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check($plain !== false, 'and the row is there to read');
if ($plain !== false) {
	harness_register_row('imc_mailbox_contacts', 'imc_mailbox_contact_id', intval($plain['imc_mailbox_contact_id']));
	check(empty($plain['imc_content_sealed']) || $plain['imc_content_sealed'] === 'f',
		'stored UNSEALED — the mail beside it is plaintext, so sealing the address book would protect nothing');
	check($plain['imc_address_hash'] === hash('sha256', 'joinery:contact:' . $work_alias . ':dave@standard.example'),
		'and indexed by the plain SHA-256, which is what a live gate can look up without a vault');
}
check($svc->lookup($suid, 'dave@standard.example', $work_alias) !== null,
	'a Standard lookup answers rather than reporting locked — the live contact gate depends on exactly this');

// The same user on a SEALING mailbox with no open window: nowhere to put the
// address, so the add must say so rather than report a save that never landed.
check($svc->manualAdd($suid, 'dave@secret.example', $sealed_alias) === false,
	'the same user adding on a SEALING mailbox with no open window gets a failed add, not a silent drop');
$locked = $svc->lookup($suid, 'dave@secret.example', $sealed_alias);
check(is_array($locked) && !empty($locked['locked']),
	'and a lookup there reports locked rather than "not a contact"');

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

// Hand-adding an address that arrived by import re-stamps the row rather than duplicating.
$svc->manualAdd($iuid, 'dana@vcard.example', $work_alias);
$dana_after = $svc->lookup($iuid, 'dana@vcard.example', $work_alias);
check($dana_after && $dana_after['source'] === 'manual', 'a hand-add re-stamps an imported contact', $dana_after ? $dana_after['source'] : '');
check(intval($db->query("SELECT COUNT(*) FROM imc_mailbox_contacts WHERE imc_usr_user_id = $iuid AND imc_address='dana@vcard.example'")->fetchColumn()) === 1,
	'and does not duplicate the row');

// ── Delete (owner-scoped) ────────────────────────────────────────────────────
section('Delete');
$del_id = intval($db->query("SELECT imc_mailbox_contact_id FROM imc_mailbox_contacts WHERE imc_usr_user_id = $iuid LIMIT 1")->fetchColumn());
$other = make_user('ContactOther', 5);
check($svc->deleteContact((int)$other->key, $del_id) === false, 'a non-owner cannot delete a contact');
check($svc->deleteContact($iuid, $del_id) === true, 'the owner can delete their contact');
check(intval($db->query("SELECT COUNT(*) FROM imc_mailbox_contacts WHERE imc_mailbox_contact_id = $del_id")->fetchColumn()) === 0, 'the contact row is gone');

harness_finish();
