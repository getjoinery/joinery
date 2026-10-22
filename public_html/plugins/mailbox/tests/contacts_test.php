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
 *  - Edit (updateContact): a new name keeps the row; a new address replaces the row,
 *    joining a contact that already holds it; owner-scoped; a bad address is refused.
 *  - A hand add can carry its name apart from the address, taken as typed.
 *  - Delete is owner-scoped.
 *  - Export: the vCard and Google CSV import back exactly, awkward names included; a
 *    formula name opens as text; a long line folds on a character boundary.
 *  - Import from other programs: a multi-line Google note keeps its row (B2), current
 *    Google First/Middle/Last columns, an Apple grouped, folded, escaped vCard, a quoted
 *    vCard parameter holding a colon, a card named by its address, a vCard 2.1 card
 *    with only N, a byte order mark and quoted-printable names, raw Windows-1252
 *    files, Outlook's E-mail Address columns, and Yahoo's First / Last.
 *  - A sealed store exports only in an open window, and is locked, not empty, outside
 *    one; an import into it then says locked rather than counting what never landed.
 *  - A row whose write fails is skipped and counted so; the rows after it still land.
 *
 * @version 2.3 - export round trip and sealed export; CSV and vCard import fixes
 * @version 2.2 - updateContact(); manualAdd() with a separate name
 * @version 2.1 - sealing follows the domain's posture, not vault possession alone
 * @version 2.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');
$has_session = vault_ensure_session(); // before any output: the sealed export opens a window
harness_boot();
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domains_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_aliases_class.php'));
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
// addressHash, aliasHasContact, listForMailbox, listForUser and lookup are
// READERS; the only writers that can bring an address in are manualAdd() and
// import(), and updateContact() changes a row the person already made (a new
// address replaces theirs, from their own edit). aliasHasContact answers
// the Direct contact gate for a shared mailbox and writes nothing. listForUser
// spans every mailbox the user holds, for surfaces that are not scoped to one
// (the Messenger people picker) — a SELECT and per-row decryption, nothing more.
$expected = array('addressHash', 'aliasHasContact', 'deleteContact', 'import', 'listForMailbox',
	'listForUser', 'lookup', 'manualAdd', 'updateContact');
check($public === $expected,
	'the only public writers are manualAdd(), import() and updateContact() — no traffic-driven entry point',
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
// refuse the add. Drive the sealed path directly via a tiny subclass that supplies
// a contact-index key (mirrors an in-window add).
class TestSealedContacts extends MailboxContacts {
	public $secret;   // stands in for the user's contact-index key (MailboxContactIndexKey)
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
$tsvc->secret = random_bytes(32);
$tsvc->alias_id = $work_alias;
$tsvc->addInWindow($suid, array('Carol <carol@secret.example>'), $vault);

$srow = $db->query("SELECT * FROM imc_mailbox_contacts WHERE imc_usr_user_id = $suid LIMIT 1")->fetch(PDO::FETCH_ASSOC);
harness_register_row('imc_mailbox_contacts', 'imc_mailbox_contact_id', intval($srow['imc_mailbox_contact_id']));
check(!empty($srow['imc_content_sealed']) && $srow['imc_content_sealed'] !== 'f', 'sealed contact flagged content_sealed');
check($srow['imc_address'] !== 'carol@secret.example', 'address stored as ciphertext', substr((string)$srow['imc_address'], 0, 20));
check($srow['imc_address_hash'] !== hash('sha256', 'joinery:contact:' . $work_alias . ':carol@secret.example'), 'sealed hash is a KEYED blind index (not the plain SHA-256)');

$cid = intval($srow['imc_mailbox_contact_id']);
$dek = $vc->openItemDek($srow['imc_sealed_key'], vault_fixture_key($kp['secret']));
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

// ── Edit ─────────────────────────────────────────────────────────────────────
section('Edit');
$eu = make_user('ContactEdit', 5);
$euid = (int)$eu->key;
check($svc->manualAdd($euid, 'frank@edit.example', $work_alias, 'Frank "The Tank" <O\'Neil>') === true,
	'a hand add with a separate name succeeds');
$frank = $db->query("SELECT * FROM imc_mailbox_contacts WHERE imc_usr_user_id = $euid AND imc_address = 'frank@edit.example'")->fetch(PDO::FETCH_ASSOC);
check($frank && $frank['imc_display_name'] === 'Frank "The Tank" <O\'Neil>', 'the separate name is kept as typed', $frank ? $frank['imc_display_name'] : '');
$fid = intval($frank['imc_mailbox_contact_id']);

check($svc->updateContact($euid, $fid, 'frank@edit.example', 'Frank Edited') === 'saved', 'a new name saves');
check($db->query("SELECT imc_display_name FROM imc_mailbox_contacts WHERE imc_mailbox_contact_id = $fid")->fetchColumn() === 'Frank Edited',
	'and keeps the same row');

check($svc->updateContact($euid, $fid, 'not-an-address', 'X') === 'invalid', 'a bad address is refused');
$other_editor = (int)make_user('ContactEditOther', 5)->key;
check($svc->updateContact($other_editor, $fid, 'x@edit.example', 'X') === 'missing',
	'another user\'s contact is not theirs to change');

check($svc->updateContact($euid, $fid, 'Frank.New@Edit.example', 'Frank New') === 'saved', 'a new address saves');
$moved = $svc->lookup($euid, 'frank.new@edit.example', $work_alias);
check($moved && $moved['name'] === 'Frank New' && $moved['source'] === 'manual', 'the new address holds the name', json_encode($moved));
check($svc->lookup($euid, 'frank@edit.example', $work_alias) === null, 'the old address is gone');
check(intval($db->query("SELECT COUNT(*) FROM imc_mailbox_contacts WHERE imc_mailbox_contact_id = $fid")->fetchColumn()) === 0, 'its row went with it');

// Moving onto an address the mailbox already holds joins that contact.
$svc->manualAdd($euid, 'gina@edit.example', $work_alias, 'Gina');
$hid = intval($db->query("SELECT imc_mailbox_contact_id FROM imc_mailbox_contacts WHERE imc_usr_user_id = $euid AND imc_address = 'frank.new@edit.example'")->fetchColumn());
check($svc->updateContact($euid, $hid, 'gina@edit.example', 'Gina Joined') === 'saved', 'an edit onto a kept address saves');
$left = $svc->listForMailbox($euid, $work_alias)['contacts'];
check(count($left) === 1 && $left[0]['address'] === 'gina@edit.example' && $left[0]['name'] === 'Gina Joined',
	'the two are one contact, named as edited', json_encode($left));

// ── Delete (owner-scoped) ────────────────────────────────────────────────────
section('Delete');
$del_deletion_rule_id = intval($db->query("SELECT imc_mailbox_contact_id FROM imc_mailbox_contacts WHERE imc_usr_user_id = $iuid LIMIT 1")->fetchColumn());
$other = make_user('ContactOther', 5);
check($svc->deleteContact((int)$other->key, $del_deletion_rule_id) === false, 'a non-owner cannot delete a contact');
check($svc->deleteContact($iuid, $del_deletion_rule_id) === true, 'the owner can delete their contact');
check(intval($db->query("SELECT COUNT(*) FROM imc_mailbox_contacts WHERE imc_mailbox_contact_id = $del_deletion_rule_id")->fetchColumn()) === 0, 'the contact row is gone');

// ── Export round trip ────────────────────────────────────────────────────────
// What a member downloads must import back as it was, here or anywhere else
// (specs/export_mail_contacts_calendar_drive.md, WP2). The names are the awkward
// ones: quotes and angle brackets, vCard's own separators, a backslash, a
// formula, wide characters long enough to fold, and no name at all.
section('Export round trip (vCard + Google CSV)');

// The sealed section above opened sealed content, which leaves this process
// refusing any long plaintext write (SealedEgressGuard). Each add, export and
// import here is a request of its own on a Standard mailbox, which starts cold.
SealedEgressGuard::reset();

$xu = (int)make_user('ContactExport', 5)->key;
$originals = array(
	'frank@export.example' => 'Frank "The Tank" <O\'Neil>',
	'jo@export.example'    => 'Smith, Jo; Jr. \\ back',
	'evil@export.example'  => '=HYPERLINK("http://evil.example")',
	'bare@export.example'  => '',
	'zoe@export.example'   => 'Zoë 漢字 ' . str_repeat('Longname ', 8) . 'End',
	'mary@export.example'  => 'Mary Ann Smith',
	'apos@export.example'  => "'=1+1 is text",
);
foreach ($originals as $addr => $name) {
	$svc->manualAdd($xu, $addr, $work_alias, $name);
}
$held = $svc->listForMailbox($xu, $work_alias, null)['contacts'];
check(count($held) === count($originals), 'all the contacts to export are held', (string)count($held));

$as_map = function (array $contacts) {
	$map = array();
	foreach ($contacts as $c) {
		$map[$c['address']] = $c['name'];
	}
	ksort($map);
	return $map;
};
$want = $originals;
ksort($want);

$vcf = MailboxContacts::toVcard($held);
check(substr_count($vcf, "BEGIN:VCARD\r\n") === count($originals) && substr_count($vcf, "VERSION:3.0\r\n") === count($originals),
	'one vCard 3.0 card per contact');
check(array_filter(explode("\r\n", $vcf), function ($l) { return strlen($l) > 75 || !mb_check_encoding($l, 'UTF-8'); }) === array(),
	'every vCard line is 75 octets or under and valid UTF-8 (long lines fold)');
check(strpos($vcf, "N:Smith;Mary Ann;;;\r\n") !== false, 'N carries the name cut at its last space');
check(strpos($vcf, "FN:bare@export.example\r\n") !== false, 'a contact with no name carries its address as FN, which vCard requires');
check(strpos($vcf, 'FN:Smith\\, Jo\\; Jr. \\\\ back') !== false, 'commas, semicolons and backslashes are escaped');

$vu = (int)make_user('ContactExportVcf', 5)->key;
$back = $svc->import($vu, $vcf, 'contacts.vcf', $work_alias);
check($back['imported'] === count($originals) && $back['skipped'] === 0, 'the vCard imports every contact', json_encode($back));
check($as_map($svc->listForMailbox($vu, $work_alias, null)['contacts']) === $want,
	'and they come back exactly: same addresses, same names, no name stays no name',
	json_encode($as_map($svc->listForMailbox($vu, $work_alias, null)['contacts']), JSON_UNESCAPED_UNICODE));

$csv = MailboxContacts::toGoogleCsv($held);
check(strncmp($csv, CsvWriter::BOM . "First Name,Last Name,E-mail 1 - Value\r\n", 3 + 39) === 0,
	'the CSV starts with a byte order mark and Google\'s own column names');
check(strpos($csv, "\"'=HYPERLINK(\"\"http://evil.example\"\")\"") !== false,
	'a name that would run as a formula opens as text');

$cu = (int)make_user('ContactExportCsv', 5)->key;
$back = $svc->import($cu, $csv, 'contacts.csv', $work_alias);
check($back['imported'] === count($originals) && $back['skipped'] === 0, 'the CSV imports every contact', json_encode($back));
check($as_map($svc->listForMailbox($cu, $work_alias, null)['contacts']) === $want,
	'and they come back exactly, the formula name included',
	json_encode($as_map($svc->listForMailbox($cu, $work_alias, null)['contacts']), JSON_UNESCAPED_UNICODE));

// ── Files from other programs ────────────────────────────────────────────────
section('Import: Google (B2, current columns), Apple, vCard 2.1, Outlook and Yahoo files');

$gu = (int)make_user('ContactGoogle', 5)->key;
$google_now = "First Name,Middle Name,Last Name,Notes,E-mail 1 - Label,E-mail 1 - Value\r\n"
	. "Hana,,Ito,\"met at the fair\r\nlikes tea, not coffee\",* Home,hana@google.example\r\n"
	. "Ivo,Q,Petrov,,* Work,ivo@google.example\r\n";
$res = $svc->import($gu, $google_now, 'contacts.csv', $work_alias);
check($res['imported'] === 2 && $res['skipped'] === 0, 'a line break inside a quoted note does not split its row', json_encode($res));
check($as_map($svc->listForMailbox($gu, $work_alias, null)['contacts']) === array(
	'hana@google.example' => 'Hana Ito',
	'ivo@google.example'  => 'Ivo Q Petrov',
), 'First, Middle and Last Name are joined into the name');

$au = (int)make_user('ContactApple', 5)->key;
$apple = "BEGIN:VCARD\r\nVERSION:3.0\r\nN:Doe;Jane;;;\r\nFN:Doe\\, Jane Ellen-Marie Whitfield-Montgomery of the Very Long Na\r\n me\r\n"
	. "item1.EMAIL;type=INTERNET;type=pref:Jane@Apple.example\r\nitem1.X-ABLabel:_\$!<Other>!\$_\r\nEND:VCARD\r\n";
$res = $svc->import($au, $apple, 'jane.vcf', $work_alias);
check($res['imported'] === 1, 'an address Apple writes under a group prefix (item1.EMAIL) is read', json_encode($res));
check($as_map($svc->listForMailbox($au, $work_alias, null)['contacts']) === array(
	'jane@apple.example' => 'Doe, Jane Ellen-Marie Whitfield-Montgomery of the Very Long Name',
), 'a folded, escaped FN is unfolded and unescaped');

$ku = (int)make_user('ContactVcardOdd', 5)->key;
$odd = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Ann Quoted\r\nEMAIL;X-LABEL=\"home:main\";TYPE=INTERNET:ann@quoted.example\r\nEND:VCARD\r\n"
	. "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:one@nameless.example\r\nN:;;;;\r\nEMAIL:one@nameless.example\r\nEMAIL:two@nameless.example\r\nEND:VCARD\r\n"
	. "BEGIN:VCARD\r\nVERSION:2.1\r\nN:Kowalski;Jan;Maria;Dr.;\r\nEMAIL;INTERNET:jan@v21.example\r\nEND:VCARD\r\n";
$res = $svc->import($ku, $odd, 'odd.vcf', $work_alias);
check($res['imported'] === 4 && $res['skipped'] === 0, 'every address is read, one behind a quoted parameter holding a colon', json_encode($res));
check($as_map($svc->listForMailbox($ku, $work_alias, null)['contacts']) === array(
	'ann@quoted.example'   => 'Ann Quoted',
	'jan@v21.example'      => 'Dr. Jan Maria Kowalski',
	'one@nameless.example' => '',
	'two@nameless.example' => '',
), 'a card named by one of its addresses has no name for any of them; a card with only N is named from it',
	json_encode($as_map($svc->listForMailbox($ku, $work_alias, null)['contacts'])));

$ou = (int)make_user('ContactOutlook', 5)->key;
$outlook = "First Name,Middle Name,Last Name,E-mail Address,E-mail Type,E-mail Display Name,E-mail 2 Address\r\n"
	. "Jo,,Ray,jo@outlook.example,SMTP,Jo Ray (jo@outlook.example),jo.ray@outlook.example\r\n";
$res = $svc->import($ou, $outlook, 'outlook.csv', $work_alias);
check($res['imported'] === 2 && $res['skipped'] === 0, 'Outlook\'s E-mail Address columns are read, its Type and Display Name columns are not', json_encode($res));
check($as_map($svc->listForMailbox($ou, $work_alias, null)['contacts']) === array(
	'jo.ray@outlook.example' => 'Jo Ray',
	'jo@outlook.example'     => 'Jo Ray',
), 'both addresses carry the joined name');

$pu = (int)make_user('ContactOldPhone', 5)->key;
$old_phone = CsvWriter::BOM
	. "BEGIN:VCARD\r\nVERSION:2.1\r\nN;CHARSET=UTF-8;ENCODING=QUOTED-PRINTABLE:Z=C3=B6e;Ren=\r\n=C3=A9;;;\r\nEMAIL;INTERNET:rene@qp.example\r\nEND:VCARD\r\n"
	. "BEGIN:VCARD\r\nVERSION:2.1\r\nFN;CHARSET=ISO-8859-1;ENCODING=QUOTED-PRINTABLE:Jos=E9 Garc=EDa\r\nEMAIL:jose@qp.example\r\nEND:VCARD\r\n";
$res = $svc->import($pu, $old_phone, 'phone.vcf', $work_alias);
check($res['imported'] === 2 && $res['skipped'] === 0, 'a byte order mark before the first card is not a card', json_encode($res));
check($as_map($svc->listForMailbox($pu, $work_alias, null)['contacts']) === array(
	'jose@qp.example' => 'José García',
	'rene@qp.example' => 'René Zöe',
), 'quoted-printable names are decoded, across a soft line break and from their CHARSET',
	json_encode($as_map($svc->listForMailbox($pu, $work_alias, null)['contacts']), JSON_UNESCAPED_UNICODE));

// Desktop Outlook saves raw Windows-1252 with no CHARSET, which the database refuses
// as it stands: it is read as Windows-1252 instead, as is an unknown CHARSET and
// a value that claims UTF-8 but is not.
$wu = (int)make_user('ContactWin1252', 5)->key;
$win = "BEGIN:VCARD\r\nVERSION:2.1\r\nN:Garc\xEDa;Jos\xE9\r\nFN:Jos\xE9 Garc\xEDa\r\nEMAIL;INTERNET:jose@win.example\r\nEND:VCARD\r\n"
	. "BEGIN:VCARD\r\nVERSION:2.1\r\nFN;CHARSET=X-NO-SUCH-CHARSET:Ren\xE9\r\nEMAIL:rene@win.example\r\nEND:VCARD\r\n"
	. "BEGIN:VCARD\r\nVERSION:2.1\r\nFN;CHARSET=UTF-8:Ama\xE9l\r\nEMAIL:amael@win.example\r\nEND:VCARD\r\n";
$res = $svc->import($wu, $win, 'outlook.vcf', $work_alias);
$res_csv = $svc->import($wu, "First Name,Last Name,E-mail Address\r\nZo\xEB,Caf\xE9,zoe@win.example\r\n", 'outlook.csv', $work_alias);
check($res['imported'] === 3 && $res_csv['imported'] === 1, 'raw 8-bit vCard and CSV files import', json_encode(array($res, $res_csv)));
check($as_map($svc->listForMailbox($wu, $work_alias, null)['contacts']) === array(
	'amael@win.example' => 'Amaél',
	'jose@win.example' => 'José García',
	'rene@win.example' => 'René',
	'zoe@win.example'  => 'Zoë Café',
), 'with their names read as Windows-1252', json_encode($as_map($svc->listForMailbox($wu, $work_alias, null)['contacts']), JSON_UNESCAPED_UNICODE));

$yu = (int)make_user('ContactYahoo', 5)->key;
$res = $svc->import($yu, "First,Middle,Last,Nickname,Email\r\nYuki,,Tanaka,,yuki@yahoo.example\r\n", 'yahoo.csv', $work_alias);
check($res['imported'] === 1 && $as_map($svc->listForMailbox($yu, $work_alias, null)['contacts']) === array('yuki@yahoo.example' => 'Yuki Tanaka'),
	'Yahoo\'s First / Last columns give the name', json_encode($res));

// ── Export from a sealed store ───────────────────────────────────────────────
// A sealed store reads only in an open window. With it shut the list is locked,
// which contacts_export refuses rather than handing over an empty file.
section('Export from a sealed store');
if (!($has_session && vault_apcu_usable())) {
	harness_skip('sealed export', 'needs a session and APCu on this CLI (the export reads in a window)');
} else {
	// A request of its own, which starts cold (see the export round trip above).
	SealedEgressGuard::reset();
	$sx = (int)make_user('ContactExportSealed', 5)->key;
	$skp = vault_fixture_server_vault($sx);
	harness_defer(function () use ($sx) {
		VaultUnlock::lockAll($sx);
		$db = DbConnector::get_instance()->get_db_link();
		$db->prepare('DELETE FROM imc_mailbox_contacts WHERE imc_usr_user_id = ?')->execute(array($sx));
		$db->prepare('DELETE FROM mck_mailbox_contact_index_keys WHERE mck_usr_user_id = ?')->execute(array($sx));
	});

	$shut = $svc->listForMailbox($sx, $sealed_alias, null);
	check(!empty($shut['locked']) && $shut['contacts'] === array(), 'with the window shut the list is locked, not empty');

	vault_fixture_open_window($sx, $skp['secret']);
	check($svc->manualAdd($sx, 'hana@sealed.example', $sealed_alias, 'Hana "Sealed" Ito') === true, 'an in-window add on the sealed mailbox succeeds');
	$hrow = $db->query("SELECT imc_address, imc_content_sealed FROM imc_mailbox_contacts WHERE imc_usr_user_id = $sx")->fetch(PDO::FETCH_ASSOC);
	check($hrow && $hrow['imc_content_sealed'] !== 'f' && $hrow['imc_content_sealed'] !== false && $hrow['imc_address'] !== 'hana@sealed.example',
		'and is stored sealed');
	$open = $svc->listForMailbox($sx, $sealed_alias, null);
	check(empty($open['locked'])
		&& MailboxContacts::toVcard($open['contacts']) === MailboxContacts::toVcard(array(array('address' => 'hana@sealed.example', 'name' => 'Hana "Sealed" Ito'))),
		'with the window open the export holds the opened contact', json_encode($open, JSON_UNESCAPED_UNICODE));

	VaultUnlock::close($sx);
	check(!empty($svc->listForMailbox($sx, $sealed_alias, null)['locked']), 'and once the window shuts, it is locked again');
	$res = $svc->import($sx, "BEGIN:VCARD\r\nFN:Ivo\r\nEMAIL:ivo@sealed.example\r\nEND:VCARD\r\n", 'c.vcf', $sealed_alias);
	check(!empty($res['locked']) && $res['imported'] === 0, 'an import into it now stores nothing and says locked, not imported', json_encode($res));
}

// ── Import counts what landed ────────────────────────────────────────────────
// One row whose write is refused must not take the rows after it down, and the
// counts must say what was stored. The egress guard is a real refusal: a process
// that has opened sealed content refuses a long plaintext write.
section('Import: a failed row is skipped, the rest land');
$fu = (int)make_user('ContactRowFail', 5)->key;
SealedEgressGuard::reset();
SealedEgressGuard::markHot('contacts_test');
$res = $svc->import($fu, "Name,E-mail 1 - Value\r\nAnn,ann@rows.example\r\n"
	. 'Long ' . str_repeat('Name ', 15) . ",long@rows.example\r\nBo,bo@rows.example\r\n", 'rows.csv', $work_alias);
SealedEgressGuard::reset();
check($res['imported'] === 2 && $res['skipped'] === 1, 'the refused row is counted skipped, not imported', json_encode($res));
check($as_map($svc->listForMailbox($fu, $work_alias, null)['contacts']) === array('ann@rows.example' => 'Ann', 'bo@rows.example' => 'Bo'),
	'and the row after it still landed');

harness_finish();
