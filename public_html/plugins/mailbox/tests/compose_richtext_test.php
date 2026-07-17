<?php
/** @joinery-test
 * name: compose_richtext
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Compose maturity Phase 1 — rich-text sanitizer, plaintext derivation, and the
 * Bcc column (specs/mailbox_compose_maturity.md § Phase 1).
 *
 * Covers:
 *  - MailboxHtmlSanitizer allowlist: script/style dropped, event handlers + inline
 *    styles stripped, disallowed tags unwrapped (text kept), href scheme filtering,
 *    img cid-only, signature mode strips img.
 *  - toPlainText(): tags stripped, links rendered "text <url>".
 *  - iem_bcc rides its OWN column, never merged into iem_recipient; getThread exposes
 *    it on an outbound row only; the compose-only direction guard.
 *  - Sealed round-trip: sealAndPersistContent seals iem_bcc under AD 'iem_bcc',
 *    openable with the owner's key (proves the Sent-copy Bcc seals like the body).
 *
 * The real envelope send (true Bcc on the wire), inline-image embedding, and the
 * plaintext `body` fallback path run through a live transport and are verified on
 * dev with Playwright (§ Tests).
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxHtmlSanitizer.php'));
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));

$S = 'MailboxHtmlSanitizer';

// ── Sanitizer allowlist ──────────────────────────────────────────────────────
section('Sanitizer allowlist');

$out = MailboxHtmlSanitizer::sanitize('<p>Hi <b>there</b><script>alert(1)</script></p>');
check(strpos($out, '<script') === false && strpos($out, 'alert') === false, 'script dropped with its contents', $out);
check(strpos($out, '<b>there</b>') !== false, 'allowed <b> preserved', $out);

$out = MailboxHtmlSanitizer::sanitize('<div style="color:red" onclick="x()">styled</div>');
check(strpos($out, 'style=') === false && strpos($out, 'onclick') === false, 'inline style + event handler stripped', $out);
check(strpos($out, '<div>styled</div>') !== false, 'the <div> itself survives, attribute-free', $out);

$out = MailboxHtmlSanitizer::sanitize('<h1>Head</h1><font color="red">f</font>');
check(strpos($out, '<h1') === false && strpos($out, '<font') === false, 'disallowed tags removed', $out);
check(strpos($out, 'Head') !== false && strpos($out, 'f') !== false, 'unwrapped tags keep their text', $out);

$out = MailboxHtmlSanitizer::sanitize('<a href="https://ok.com">ok</a> <a href="javascript:evil()">x</a> <a href="mailto:a@b.com">m</a>');
check(strpos($out, 'href="https://ok.com"') !== false, 'http(s) href kept', $out);
check(strpos($out, 'href="mailto:a@b.com"') !== false, 'mailto href kept', $out);
check(strpos($out, 'javascript:') === false, 'javascript: href dropped', $out);
check(strpos($out, 'rel="noopener') !== false, 'external links get rel=noopener', $out);

$out = MailboxHtmlSanitizer::sanitize('<img src="cid:abc"><img src="https://e/x.png"><img src="data:image/png;base64,zz">');
check(substr_count($out, '<img') === 1 && strpos($out, 'src="cid:abc"') !== false, 'only the cid: image survives', $out);

$sig = MailboxHtmlSanitizer::sanitize('<p>Jeremy<img src="cid:x"></p>', false);
check(strpos($sig, '<img') === false, 'signature mode strips images entirely', $sig);

// ── Plaintext derivation ─────────────────────────────────────────────────────
section('Plaintext derivation');

$txt = MailboxHtmlSanitizer::toPlainText('<p>See <a href="https://x.com/p">the page</a> now.</p>');
// The only '<' allowed is the link's <url> bracket; no HTML tags survive.
check(strpos($txt, '<p') === false && strpos($txt, '</') === false && strpos($txt, '<a') === false, 'no HTML tags remain in plaintext', $txt);
check(strpos($txt, 'the page <https://x.com/p>') !== false, 'link rendered as text <url>', $txt);

$txt = MailboxHtmlSanitizer::toPlainText('<p>mail <a href="mailto:a@b.com">a@b.com</a></p>');
check(strpos($txt, 'a@b.com') !== false && strpos($txt, 'mailto:') === false, 'mailto renders as the bare address', $txt);

// ── Fixtures for the Bcc column ──────────────────────────────────────────────
section('Bcc column — storage + separation');

$box = new SealedBox();
$crypto = new VaultCrypto();
$kp = $box->generateKeypair();
$user = make_user('MbCompose', 5);
$uid = (int)$user->key;

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
$domain->set('ied_domain', 'compose-' . bin2hex(random_bytes(4)) . '.example');
$domain->set('ied_is_enabled', true);
$domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);

$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
$alias->set('iea_alias', 'me');
$alias->set('iea_delivery_mode', 'store');
$alias->set('iea_is_enabled', true);
$alias->prepare();
$alias->save();
harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', (int)$alias->key);

$grant = new InboundEmailMailboxGrant(NULL);
$grant->set('ieg_iea_inbound_email_alias_id', (int)$alias->key);
$grant->set('ieg_usr_user_id', $uid);
$grant->save();
harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', (int)$grant->key);

// An UNSEALED outbound row (Standard tier) — mirrors storeOutboundRow's non-sealing path.
$mk = function ($direction, $recipient, $bcc, $mid) use ($domain, $alias) {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', (int)$domain->key);
	$m->set('iem_iea_inbound_email_alias_id', (int)$alias->key);
	$m->set('iem_direction', $direction);
	$m->set('iem_sender', 'me@x');
	$m->set('iem_recipient', $recipient);
	$m->set('iem_bcc', $bcc);
	$m->set('iem_subject', 'hi');
	$m->set('iem_body_plain', 'body');
	$m->set('iem_body_html', '<p>body</p>');
	$m->set('iem_message_id_header', $mid);
	$m->set('iem_thread_key', $mid);
	$m->save();
	harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', (int)$m->key);
	return (int)$m->key;
};

$out_id = $mk('outbound', 'alice@x, bob@x', 'secret@x', '<c1@x>');

$loaded = new InboundEmailMessage($out_id, TRUE);
check($loaded->get('iem_bcc') === 'secret@x', 'iem_bcc stored on its own column', (string)$loaded->get('iem_bcc'));
check(strpos((string)$loaded->get('iem_recipient'), 'secret@x') === false, 'bcc address is NOT in iem_recipient (no reply-all leak)', (string)$loaded->get('iem_recipient'));

// getThread exposes bcc on the outbound row only.
$viewer = MailboxViewer::forUser($uid, 5);
$service = new MailboxService($viewer);
$thread = $service->getThread((int)$alias->key, '<c1@x>');
check(count($thread) === 1, 'thread resolves the outbound row');
check(($thread[0]['bcc'] ?? null) === 'secret@x', 'getThread returns bcc on outbound', json_encode($thread[0]['bcc'] ?? null));
check(strpos($thread[0]['recipient'], 'secret@x') === false, 'getThread recipient omits the bcc address', $thread[0]['recipient']);

$in_id = $mk('inbound', 'me@x', null, '<c2@x>');
$in_thread = $service->getThread((int)$alias->key, '<c2@x>');
check(($in_thread[0]['bcc'] ?? '') === '', 'inbound row exposes no bcc', json_encode($in_thread[0]['bcc'] ?? null));

// ── Compose-only direction guard ─────────────────────────────────────────────
section('Compose-only field guard');
check(InboundEmailMessage::isComposeOnlyField('iem_bcc') === true, 'iem_bcc is compose-only');
check(InboundEmailMessage::isComposeOnlyField('iem_recipient') === true, 'iem_recipient is compose-only');
check(InboundEmailMessage::isComposeOnlyField('iem_draft_state') === true, 'iem_draft_state is compose-only');
check(InboundEmailMessage::isComposeOnlyField('iem_subject') === false, 'iem_subject is not compose-only');
check(InboundEmailMessage::isComposedDirection('outbound') === true, 'outbound is a composed direction');
check(InboundEmailMessage::isComposedDirection('draft') === true, 'draft is a composed direction');
check(InboundEmailMessage::isComposedDirection('inbound') === false, 'inbound is not a composed direction');

// The static raw-row hook leaves an inbound row's sealed-flagged bcc/recipient
// UNTOUCHED (the guard short-circuits before any decrypt attempt).
$inbound_row = array(
	'iem_inbound_email_message_id' => 1, 'iem_direction' => 'inbound',
	'iem_content_sealed' => true, 'iem_sealed_key' => 'x',
	'iem_iea_inbound_email_alias_id' => (int)$alias->key,
);
check(InboundEmailMessage::decryptSealedFieldStatic('iem_bcc', 'CIPHER', $inbound_row) === 'CIPHER',
	'inbound iem_bcc bypasses decrypt (guard holds)');

// ── Sealed Bcc round-trip ────────────────────────────────────────────────────
section('Sealed Bcc round-trip');

$sealed_id = $mk('outbound', '', '', '<c3@x>'); // insert empty, then seal in place
$dek = InboundEmailMessage::sealAndPersistContent($sealed_id, $vault, 'me@x',
	'alice@x, bob@x', 'hi', 'body', '<p>body</p>', true, 'secret@x');

$db = DbConnector::get_instance()->get_db_link();
$row = $db->query('SELECT * FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ' . intval($sealed_id))
	->fetch(PDO::FETCH_ASSOC);
check(!empty($row['iem_content_sealed']), 'sealed row flagged content_sealed');
check($row['iem_bcc'] !== 'secret@x' && !empty($row['iem_bcc']), 'iem_bcc column now holds ciphertext', substr((string)$row['iem_bcc'], 0, 24));

$open_dek = $crypto->openItemDek($row['iem_sealed_key'], $kp['secret']);
$bcc_plain = $crypto->openField($row['iem_bcc'], $open_dek, InboundEmailMessage::sealAd($sealed_id, 'iem_bcc'));
check($bcc_plain === 'secret@x', 'sealed iem_bcc opens back to the original under AD iem_bcc', $bcc_plain);
$rcpt_plain = $crypto->openField($row['iem_recipient'], $open_dek, InboundEmailMessage::sealAd($sealed_id, 'iem_recipient'));
check($rcpt_plain === 'alice@x, bob@x' && strpos($rcpt_plain, 'secret@x') === false, 'sealed recipient still excludes the bcc', $rcpt_plain);

harness_finish();
