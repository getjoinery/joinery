<?php
/** @joinery-test
 * name: mailbox_contact_level_converge
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */
/**
 * A contact row takes its mailbox's level, and a mailbox's level change takes
 * the contacts with it (MailboxContactConvergence, the vault's deferred work
 * `mailbox_contact_level`):
 *   - raising a mailbox: the adder's plaintext rows seal in the adder's window,
 *     with the keyed blind index, and the list and lookup still find them;
 *   - another grantee's rows wait for that grantee's window and are counted;
 *   - with the window closed a pass converts nothing and reports locked;
 *   - lowering: the rows open back, with the plain digest, readable with no
 *     window; a hand-add made in the meantime is merged, not duplicated;
 *   - the receipts name the contacts still to converge;
 *   - the Fortress mail card says the contact list stays server custody.
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(PathHelper::getIncludePath('tests/lib/vault_fixtures.php'));
$has_session = vault_ensure_session();
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domains_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_aliases_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_contacts_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxContacts.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxProtectionLevel.php'));
require_once(PathHelper::getIncludePath('includes/ProtectionLevelPicker.php'));

if (!($has_session && vault_apcu_usable())) {
	harness_skip('contact level converge', 'needs a session and APCu on this CLI (every conversion runs in a window)');
	harness_finish();
}

$db = DbConnector::get_instance()->get_db_link();
$svc = new MailboxContacts();

function clv_row(int $id): ?array {
	$r = DbConnector::get_instance()->get_db_link()->query('SELECT * FROM imc_mailbox_contacts WHERE imc_mailbox_contact_id = ' . (int)$id)->fetch(PDO::FETCH_ASSOC);
	return $r ?: null;
}
function clv_sealed(array $r): bool {
	return in_array($r['imc_content_sealed'], array(true, 't', 1, '1'), true);
}
/** [id => row] of one user's rows on one mailbox, by address digest order. */
function clv_rows(int $user_id, int $alias_id): array {
	$stmt = DbConnector::get_instance()->get_db_link()->prepare('SELECT * FROM imc_mailbox_contacts WHERE imc_usr_user_id = ? AND imc_iea_inbound_email_alias_id = ? ORDER BY imc_mailbox_contact_id');
	$stmt->execute(array($user_id, $alias_id));
	$out = array();
	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[(int)$r['imc_mailbox_contact_id']] = $r; }
	return $out;
}
function clv_set_level(InboundEmailDomain $domain, string $level): void {
	$domain->set_security_level($level);
	$domain->prepare();
	$domain->save();
	MailboxContacts::forgetPosture();
}

// ── Fixtures ────────────────────────────────────────────────────────────────
$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', 'harnesstest-clv-' . bin2hex(random_bytes(4)) . '.example');
$domain->set('ied_is_enabled', true);
$domain->set('ied_security_level', InboundEmailDomain::LEVEL_STANDARD);
$domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);
$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
$alias->set('iea_alias', 'box');
$alias->set('iea_delivery_mode', 'store');
$alias->set('iea_is_enabled', true);
$alias->prepare();
$alias->save();
harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', (int)$alias->key);
$alias_id = (int)$alias->key;

$adder = make_user('ClvAdder', 5);
$other = make_user('ClvOther', 5);
$kp_adder = vault_fixture_server_vault((int)$adder->key);
$kp_other = vault_fixture_server_vault((int)$other->key);
$cleanup_contacts = function () use ($adder, $other) {
	DbConnector::get_instance()->get_db_link()->prepare('DELETE FROM imc_mailbox_contacts WHERE imc_usr_user_id IN (?, ?)')
		->execute(array((int)$adder->key, (int)$other->key));
	DbConnector::get_instance()->get_db_link()->prepare('DELETE FROM mck_mailbox_contact_index_keys WHERE mck_usr_user_id IN (?, ?)')
		->execute(array((int)$adder->key, (int)$other->key));
};
harness_defer($cleanup_contacts);

// Both users add on the STANDARD mailbox: plaintext rows, plain digests.
check($svc->manualAdd((int)$adder->key, 'Ann Example <ann@example.com>', $alias_id), 'fixture: the adder files a contact');
check($svc->manualAdd((int)$adder->key, 'bob@example.com', $alias_id), 'fixture: and a second');
check($svc->manualAdd((int)$other->key, 'Carl <carl@example.com>', $alias_id), 'fixture: another grantee files one');
$adder_rows = clv_rows((int)$adder->key, $alias_id);
$other_rows = clv_rows((int)$other->key, $alias_id);
check(count($adder_rows) === 2 && count($other_rows) === 1, 'fixture: three plaintext rows');
foreach ($adder_rows as $r) { check(!clv_sealed($r) && $r['imc_address_hash'] === $svc->addressHash($r['imc_address'], null, $alias_id), 'fixture: plaintext row with the plain digest (' . $r['imc_address'] . ')'); }

// ---------------------------------------------------------------------------
section('Raising a mailbox: the adder\'s rows seal in the adder\'s window');

clv_set_level($domain, InboundEmailDomain::LEVEL_PRIVATE);
check(MailboxContactConvergence::backlogCount((int)$domain->key) === 3, 'every plaintext row on the raised domain is backlog');
check(MailboxContactConvergence::hasWork((int)$adder->key) && MailboxContactConvergence::hasWork((int)$other->key),
	'both adders have deferred work');

VaultUnlock::close((int)$adder->key);
$pass = ProtectionLevelChange::convergeBatch(new MailboxContactConvergence((int)$adder->key));
check($pass['locked'] && $pass['converted'] === 0 && $pass['remaining'] === 2,
	'with the window closed a pass converts nothing and reports locked', json_encode($pass));

$key = vault_fixture_open_window((int)$adder->key, $kp_adder['secret']);
$done = MailboxContactConvergence::drain((int)$adder->key, microtime(true) + 60);
$adder_rows = clv_rows((int)$adder->key, $alias_id);
check($done === 2 && count($adder_rows) === 2, 'the drain converts the adder\'s two rows', $done);
$all_sealed = true;
foreach ($adder_rows as $r) {
	$all_sealed = $all_sealed && clv_sealed($r) && strpos((string)$r['imc_address'], 'v1.aead.') === 0 && !empty($r['imc_sealed_key'])
		&& (int)$r['imc_sealed_owner_user_id'] === (int)$adder->key;
}
check($all_sealed, 'the rows hold ciphertext, sealed to the adder', json_encode(array_values($adder_rows)));
$vault = UserEncryptionVault::loadForUser((int)$adder->key);
$index_key = MailboxContactIndexKey::openForUser((int)$adder->key, $vault, $key);
$keyed = $svc->addressHash('ann@example.com', $index_key, $alias_id);
$found = false;
foreach ($adder_rows as $r) { if ($r['imc_address_hash'] === $keyed) { $found = true; } }
check($found && $keyed !== $svc->addressHash('ann@example.com', null, $alias_id), 'the digest is the keyed blind index now');
$list = $svc->listForMailbox((int)$adder->key, $alias_id);
$addrs = array_map(function ($c) { return $c['address']; }, $list['contacts'] ?? array());
sort($addrs);
check(empty($list['locked']) && $addrs === array('ann@example.com', 'bob@example.com'), 'the list reads both back in the window', json_encode($list));
$lk = $svc->lookup((int)$adder->key, 'ann@example.com', $alias_id);
check(is_array($lk) && ($lk['name'] ?? '') === 'Ann Example', 'lookup finds the sealed row by its keyed digest', json_encode($lk));
check(!MailboxContactConvergence::hasWork((int)$adder->key), 'the adder has no deferred work left');

$other_row = array_values(clv_rows((int)$other->key, $alias_id))[0];
check(!clv_sealed($other_row) && MailboxContactConvergence::backlogCount((int)$domain->key) === 1
	&& MailboxContactConvergence::hasWork((int)$other->key),
	'the other grantee\'s row waits for their window and stays counted');
$row_html = mailbox_contacts_receipt_row((int)$domain->key, 0, 'encrypted');
check(strpos($row_html, '1 contact will be encrypted') !== false, 'the raise receipt names it', $row_html);

vault_fixture_open_window((int)$other->key, $kp_other['secret']);
MailboxContactConvergence::drain((int)$other->key, microtime(true) + 60);
$other_row = array_values(clv_rows((int)$other->key, $alias_id))[0];
check(clv_sealed($other_row) && MailboxContactConvergence::backlogCount((int)$domain->key) === 0, 'in their window it seals too, and the backlog is empty');
check(mailbox_contacts_receipt_row((int)$domain->key, 0, 'encrypted') === '', 'and the receipt says nothing about contacts');

// A row added while sealing is in force, in the sealed shape, under the adder's window.
check($svc->manualAdd((int)$adder->key, 'Dee <dee@example.com>', $alias_id), 'a hand-add on the Private mailbox lands sealed');

// ---------------------------------------------------------------------------
section('Lowering: the rows open back, readable with no window; a hand-add made meanwhile is merged');

clv_set_level($domain, InboundEmailDomain::LEVEL_STANDARD);
check(MailboxContactConvergence::backlogCount((int)$domain->key) === 4, 'every sealed row on the lowered domain is backlog');
// A hand-add of an address already held, made on the lowered mailbox before the
// rows converge: it lands as a new plaintext row with the plain digest.
check($svc->manualAdd((int)$adder->key, 'ann@example.com', $alias_id), 'fixture: ann re-added on the lowered mailbox');
check(count(clv_rows((int)$adder->key, $alias_id)) === 4, 'fixture: a second ann row exists for the moment');

$done = MailboxContactConvergence::drain((int)$adder->key, microtime(true) + 60);
$adder_rows = clv_rows((int)$adder->key, $alias_id);
check(count($adder_rows) === 3, 'the sealed ann row is merged into the plaintext one, not kept beside it', count($adder_rows));
$plain = true;
$ann = null;
foreach ($adder_rows as $r) {
	$plain = $plain && !clv_sealed($r) && $r['imc_sealed_key'] === null && $r['imc_address_hash'] === $svc->addressHash($r['imc_address'], null, $alias_id);
	if ($r['imc_address'] === 'ann@example.com') { $ann = $r; }
}
check($plain, 'every row is plaintext with the plain digest', json_encode(array_values($adder_rows)));
check($ann !== null && (int)$ann['imc_use_count'] >= 2, 'the merged row carries both use counts', $ann ? $ann['imc_use_count'] : 'none');
VaultUnlock::close((int)$adder->key);
$list = $svc->listForMailbox((int)$adder->key, $alias_id);
$addrs = array_map(function ($c) { return $c['address']; }, $list['contacts'] ?? array());
sort($addrs);
check(empty($list['locked']) && $addrs === array('ann@example.com', 'bob@example.com', 'dee@example.com'),
	'with no window the list reads every contact on the Standard mailbox', json_encode($list));
check(MailboxContactConvergence::backlogCount((int)$domain->key) === 1 && MailboxContactConvergence::hasWork((int)$other->key),
	'the other grantee\'s sealed row waits for their window');
check(strpos(mailbox_contacts_receipt_row((int)$domain->key, 0, 'opened'), '1 contact will be opened') !== false, 'the lowering receipt names it');
MailboxContactConvergence::drain((int)$other->key, microtime(true) + 60);
check(MailboxContactConvergence::backlogCount((int)$domain->key) === 0, 'and their window opens it');
VaultUnlock::close((int)$other->key);

// ---------------------------------------------------------------------------
section('The card');

$note = ProtectionLevelPicker::notesFor(InboundEmailDomain::LEVEL_FORTRESS, ProtectionLevelPicker::SERVICE_MAIL);
check(count($note) === 1 && stripos($note[0], 'contact list') !== false && stripos($note[0], 'not end to end') !== false,
	'mail\'s Fortress card says the contact list is encrypted on the server and not end to end', json_encode($note));
check(ProtectionLevelPicker::notesFor(InboundEmailDomain::LEVEL_PRIVATE, ProtectionLevelPicker::SERVICE_MAIL) === array(),
	'the Private card needs no note: contacts are Private there in the same sense as the mail');
$linked = ProtectionLevelPicker::renderLinked(InboundEmailDomain::LEVEL_FORTRESS, array('service' => ProtectionLevelPicker::SERVICE_MAIL));
check(strpos($linked, 'not end to end') !== false, 'the linked cards (the mailbox editor) carry the note too');

harness_finish();
