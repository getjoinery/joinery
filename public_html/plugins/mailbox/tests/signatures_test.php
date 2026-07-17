<?php
/** @joinery-test
 * name: signatures
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Compose maturity Phase 3 — per-mailbox signatures (specs/mailbox_compose_maturity.md
 * § Phase 3).
 *
 * Covers:
 *  - saveSignature/signatureFor are per-grant (user × mailbox): one grantee of a shared
 *    mailbox never sees another's; a non-grantee cannot write one.
 *  - The signature is sanitized against the compose allowlist with images stripped.
 *  - listMailboxes exposes each viewer's OWN signature per mailbox.
 *  - The signature column is plaintext (not sealed).
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxHtmlSanitizer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));

$db = DbConnector::get_instance()->get_db_link();

// ── Fixtures: a shared mailbox with two grantees, plus a non-grantee ─────────
$alice = make_user('SigAlice', 5);
$bob = make_user('SigBob', 5);
$carol = make_user('SigCarol', 5); // no grant
$aid_u = (int)$alice->key; $bid_u = (int)$bob->key; $cid_u = (int)$carol->key;

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', 'sig-' . bin2hex(random_bytes(4)) . '.example');
$domain->set('ied_is_enabled', true);
$domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);

$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
$alias->set('iea_alias', 'team');
$alias->set('iea_delivery_mode', 'store');
$alias->set('iea_is_enabled', true);
$alias->prepare();
$alias->save();
harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', (int)$alias->key);
$aid = (int)$alias->key;

foreach (array($aid_u, $bid_u) as $uid) {
	$g = new InboundEmailMailboxGrant(NULL);
	$g->set('ieg_iea_inbound_email_alias_id', $aid);
	$g->set('ieg_usr_user_id', $uid);
	$g->save();
	harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', (int)$g->key);
}

// ── Per-grant save + read ────────────────────────────────────────────────────
section('Per-grant signature');

check(InboundEmailMailboxGrant::signatureFor($aid_u, $aid) === '', 'no signature by default');

$ok = InboundEmailMailboxGrant::saveSignature($aid_u, $aid, '<p>Alice — Founder</p>');
check($ok === true, 'a grantee can save their signature');
check(InboundEmailMailboxGrant::signatureFor($aid_u, $aid) === '<p>Alice — Founder</p>', 'signatureFor returns the saved signature');
check(InboundEmailMailboxGrant::signatureFor($bid_u, $aid) === '', 'a different grantee of the shared mailbox sees no signature (personal)');

InboundEmailMailboxGrant::saveSignature($bid_u, $aid, '<p>Bob</p>');
check(InboundEmailMailboxGrant::signatureFor($aid_u, $aid) === '<p>Alice — Founder</p>', "Bob's save did not overwrite Alice's");
check(InboundEmailMailboxGrant::signatureFor($bid_u, $aid) === '<p>Bob</p>', "Bob's own signature saved independently");

// A non-grantee cannot write a signature (no row to update).
check(InboundEmailMailboxGrant::saveSignature($cid_u, $aid, '<p>Carol</p>') === false, 'a non-grantee cannot save a signature');
check(InboundEmailMailboxGrant::signatureFor($cid_u, $aid) === '', 'a non-grantee has no signature');

// ── Sanitization (images stripped) ───────────────────────────────────────────
section('Signature sanitization');
$dirty = '<p onclick="x()">Jeremy <b>Founder</b></p><img src="cid:x"><script>bad()</script>';
$clean = MailboxHtmlSanitizer::sanitize($dirty, false);
check(strpos($clean, '<script') === false && strpos($clean, 'onclick') === false, 'script + handlers stripped from a signature', $clean);
check(strpos($clean, '<img') === false, 'images excluded from signatures', $clean);
check(strpos($clean, '<b>Founder</b>') !== false, 'safe formatting kept', $clean);

// ── listMailboxes exposes the viewer's own signature ─────────────────────────
section('Signature in the mailboxes payload');

$alice_boxes = (new MailboxService(MailboxViewer::forUser($aid_u, 5)))->listMailboxes();
$alice_sig = null;
foreach ($alice_boxes['mailboxes'] as $m) { if ($m['alias_id'] === $aid) { $alice_sig = $m['signature']; } }
check($alice_sig === '<p>Alice — Founder</p>', "listMailboxes carries Alice's signature for Alice", json_encode($alice_sig));

$bob_boxes = (new MailboxService(MailboxViewer::forUser($bid_u, 5)))->listMailboxes();
$bob_sig = null;
foreach ($bob_boxes['mailboxes'] as $m) { if ($m['alias_id'] === $aid) { $bob_sig = $m['signature']; } }
check($bob_sig === '<p>Bob</p>', "listMailboxes carries Bob's own signature for Bob (not Alice's)", json_encode($bob_sig));

// ── Not sealed ───────────────────────────────────────────────────────────────
section('Signature is plaintext');
$stored = $db->query("SELECT ieg_signature FROM ieg_inbound_email_mailbox_grants
	WHERE ieg_usr_user_id = $aid_u AND ieg_iea_inbound_email_alias_id = $aid")->fetchColumn();
check($stored === '<p>Alice — Founder</p>', 'the signature column stores cleartext HTML (never sealed)', (string)$stored);

harness_finish();
