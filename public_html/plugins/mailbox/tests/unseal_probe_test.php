<?php
/** @joinery-test
 * name: unseal_probe
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * mailbox_protection_owner_has_unseal_work() — the reader's per-load question
 * "does any of my sealed mail sit in a mailbox that no longer seals?".
 *
 * It asks the distinct (mailbox, domain) pairs of the owner's sealed rows
 * instead of the rows, so every state here is also put to the row-by-row
 * query it answers for, and the two must agree:
 *
 *   - nothing sealed, or sealed only where the mailbox seals: no
 *   - a sealed row in a Standard mailbox: yes; a pending-parse one too
 *   - deleted rows, unsealed rows and another owner's rows: never count
 *   - a mailbox that inherits its level takes it from the ROW's domain (a
 *     reply's Sent copy keeps the source's domain), so a Private-domain
 *     mailbox's row on a Standard domain is work
 *   - rows with no mailbox answer by their domain
 *   - many pairs: the skip-scan walks past sealing pairs to one that is not
 *
 * Levels and flags are written with plain UPDATEs: the fixtures are rows in
 * given states, not sealed content, so no model hook seals or refuses them.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protection_ceremony.php'));

$db = DbConnector::get_instance()->get_db_link();
// An owner id no real user holds, so rows of the dev database never answer.
$owner = 2000000000 + random_int(1, 99999999);
$other = $owner + 1;
$tag = substr(md5(uniqid('', true)), 0, 8);

function up_domain(string $name, string $level): int {
	$d = new InboundEmailDomain(NULL);
	$d->set('ied_domain', $name);
	$d->set('ied_is_enabled', true);
	$d->save();
	$d->load();
	harness_register_model('InboundEmailDomain', $d->key);
	$st = DbConnector::get_instance()->get_db_link()->prepare(
		'UPDATE ied_inbound_email_domains SET ied_security_level = ? WHERE ied_inbound_email_domain_id = ?');
	$st->execute(array($level, $d->key));
	return intval($d->key);
}

function up_alias(int $domain_id, string $local, ?string $level): int {
	$a = new InboundEmailAlias(NULL);
	$a->set('iea_ied_inbound_email_domain_id', $domain_id);
	$a->set('iea_alias', $local);
	$a->set('iea_delivery_mode', 'store');
	$a->set('iea_destinations', '');
	$a->set('iea_is_enabled', true);
	$a->save();
	$a->load();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', intval($a->key));
	$st = DbConnector::get_instance()->get_db_link()->prepare(
		'UPDATE iea_inbound_email_aliases SET iea_security_level = ? WHERE iea_inbound_email_alias_id = ?');
	$st->execute(array($level, $a->key));
	return intval($a->key);
}

/** A row in the given state; returns its id. */
function up_row(int $domain_id, ?int $alias_id, int $owner_id, bool $sealed, bool $pending = false, bool $deleted = false): int {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', $domain_id);
	$m->set('iem_iea_inbound_email_alias_id', $alias_id);
	$m->set('iem_direction', 'inbound');
	$m->set('iem_sender', 'sender@elsewhere.example');
	$m->set('iem_recipient', 'probe@unseal-probe.example');
	$m->set('iem_subject', 'unseal probe fixture');
	$m->save();
	$m->load();
	harness_register_model('InboundEmailMessage', intval($m->key));
	$st = DbConnector::get_instance()->get_db_link()->prepare(
		'UPDATE iem_inbound_email_messages SET iem_sealed_owner_user_id = ?, iem_content_sealed = ?,
		   iem_pending_parse = ?, iem_delete_time = ' . ($deleted ? 'now()' : 'NULL') . '
		 WHERE iem_inbound_email_message_id = ?');
	$st->execute(array($owner_id, $sealed ? 'true' : 'false', $pending ? 'true' : 'false', $m->key));
	return intval($m->key);
}

function up_forget(int $id): void {
	$st = DbConnector::get_instance()->get_db_link()->prepare(
		'UPDATE iem_inbound_email_messages SET iem_delete_time = now() WHERE iem_inbound_email_message_id = ?');
	$st->execute(array($id));
}

/** The row-by-row question the function answers for: the oracle. */
function up_rowwise(int $owner_id): bool {
	$st = DbConnector::get_instance()->get_db_link()->prepare(
		"SELECT 1 FROM iem_inbound_email_messages m " . mailbox_protection_posture_join() . "
		 WHERE m.iem_sealed_owner_user_id = ?
		   AND (m.iem_content_sealed = true OR m.iem_pending_parse = true)
		   AND m.iem_delete_time IS NULL
		   AND NOT (" . mailbox_protection_seals_sql() . ") LIMIT 1");
	$st->execute(array($owner_id));
	return (bool)$st->fetchColumn();
}

function up_expect(int $owner_id, bool $want, string $label): void {
	$got = mailbox_protection_owner_has_unseal_work($owner_id);
	$oracle = up_rowwise($owner_id);
	check($got === $want && $oracle === $want, $label,
		'function=' . var_export($got, true) . ' row-by-row=' . var_export($oracle, true) . ' want=' . var_export($want, true));
}

section('fixtures: a Private and a Standard domain, inheriting and explicit mailboxes');
$d_priv = up_domain("unseal-probe-p-$tag.example", 'private');
$d_std  = up_domain("unseal-probe-s-$tag.example", 'standard');
$a_priv_inherit = up_alias($d_priv, "inherit-$tag", null);        // Private by its domain
$a_std          = up_alias($d_std, "plain-$tag", null);           // Standard by its domain
$a_std_explicit = up_alias($d_std, "raised-$tag", 'private');     // Private on a Standard domain
check($d_priv > 0 && $d_std > 0 && $a_priv_inherit > 0 && $a_std > 0 && $a_std_explicit > 0, 'fixtures made');

section('nothing to unseal');
up_expect($owner, false, 'an owner with no rows has no work');
up_expect(0, false, 'no user has no work');
up_row($d_priv, $a_priv_inherit, $owner, true);
up_row($d_priv, $a_priv_inherit, $owner, true);
up_row($d_std, $a_std_explicit, $owner, true);
up_row($d_priv, null, $owner, true);
up_expect($owner, false, 'sealed rows only where the mailbox (or, with none, the domain) seals: no work');
up_row($d_std, $a_std, $owner, true, false, true);
up_expect($owner, false, 'a deleted sealed row in a Standard mailbox is not work');
up_row($d_std, $a_std, $owner, false);
up_expect($owner, false, 'an unsealed row in a Standard mailbox is not work');
up_row($d_std, $a_std, $other, true);
up_expect($owner, false, 'another owner\'s sealed row is not this owner\'s work');
up_expect($other, true, '... and is that owner\'s');

section('work, found past the sealing pairs');
$r = up_row($d_std, $a_std, $owner, true);
up_expect($owner, true, 'a sealed row in a Standard mailbox is work');
up_forget($r);
up_expect($owner, false, 'and once it is gone there is none');
$r = up_row($d_std, $a_std, $owner, false, true);
up_expect($owner, true, 'a pending-parse row in a Standard mailbox is work');
up_forget($r);

section('an inherited level comes from the row\'s domain');
$r = up_row($d_std, $a_priv_inherit, $owner, true);
up_expect($owner, true, 'a Private-domain mailbox\'s row on a Standard domain (a reply\'s Sent copy) is work');
up_forget($r);
$r = up_row($d_priv, $a_std_explicit, $owner, true);
up_expect($owner, false, 'an explicitly Private mailbox seals whatever domain its row carries');
up_forget($r);

section('rows with no mailbox answer by their domain');
$r = up_row($d_std, null, $owner, true);
up_expect($owner, true, 'a sealed row with no mailbox on a Standard domain is work');
up_forget($r);
up_expect($owner, false, 'and none remains after it');

section('the lowered mailbox: every sealed row it holds becomes work');
$db->prepare('UPDATE ied_inbound_email_domains SET ied_security_level = ? WHERE ied_inbound_email_domain_id = ?')
	->execute(array('standard', $d_priv));
up_expect($owner, true, 'lowering the Private domain makes its inheriting mailbox\'s rows work');

harness_finish();
