<?php
/** @joinery-test
 * name: inline_parts
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Which parts of a message are pictures in its body and which are attachments
 * (InlineParts, B5): a part is inline exactly when the HTML body shows it by
 * cid:, whatever its Content-Disposition says.
 *
 *  - InlineParts reads cid: references the way the reader resolves them
 *    (URL-decoded, angle brackets trimmed, any case of "cid:");
 *  - a real ingest (InboundEmailRouter::storeMessage) of fixtures/mime/
 *    cid_disposition.eml stores a picture marked "attachment" that the HTML
 *    shows as inline, and a picture, an Outlook emz and a Gmail-style PDF that
 *    the HTML never shows as attachments, the same answers the browser parser
 *    gives for that file (mime_parser.mjs);
 *  - the reader turns the shown picture into a signed URL and leaves the
 *    listed ones out of the body;
 *  - a message with no HTML lists every part, an inline disposition included;
 *  - a sealed message stored before the rule is decided by InlineFlagBackfill
 *    only inside its owner's window, once;
 *  - no ingest path decides inline any other way (source scan).
 *
 * Run: php plugins/mailbox/tests/inline_parts_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
// Before anything prints: session_start() refuses once output has been sent.
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');
$session_ok = vault_ensure_session();

require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
if (!PluginHelper::isPluginActive('mailbox')) {
	harness_skip('mailbox plugin inactive');
	harness_finish();
}
require_once(__DIR__ . '/lib/mailbox_test_fixture.php');

section('InlineParts reads references as the reader does');
$ids = InlineParts::referencedIds('<p><img src="cid:a%40x"> <img src=\'CID:<b@x>\'> <a href="cid:c@x">c</a> cid: </p>');
check($ids === array('a@x' => true, 'b@x' => true, 'c@x' => true), 'URL-decoded, brackets trimmed, any case of cid:', json_encode($ids));
check(InlineParts::referencedIds(null) === array() && InlineParts::referencedIds('<p>no pictures</p>') === array(),
	'no HTML, or HTML with no cid:, shows nothing');
check(InlineParts::isInline('<a@x>', $ids) && InlineParts::isInline('a@x', $ids), 'a Content-ID with or without brackets matches');
check(!InlineParts::isInline('', $ids) && !InlineParts::isInline(null, $ids) && !InlineParts::isInline('z@x', $ids),
	'an empty or unshown Content-ID is not inline');

section('A real ingest decides by what the HTML shows');
$router = new InboundEmailRouter();
$suffix = bin2hex(random_bytes(4));
$domain_name = 'inline-parts-' . $suffix . '.example';
mailbox_purge_domains('inline-parts-%');
harness_defer(function () { mailbox_purge_domains('inline-parts-%'); });
$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', $domain_name);
$domain->set('ied_is_enabled', true);
$domain->save();
$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
$alias->set('iea_alias', 'box');
$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
$alias->set('iea_is_enabled', true);
$alias->prepare();
$alias->save();
$address = 'box@' . $domain_name;

$fixture = (string)file_get_contents(__DIR__ . '/fixtures/mime/cid_disposition.eml');
$raw = str_replace(array('To: You <you@example.com>', 'Message-ID: <cid-disposition@example.com>'),
	array('To: ' . $address, 'Message-ID: <cid-disposition-' . $suffix . '@example.com>'), $fixture);
$res = $router->storeMessage($raw, $router->parseEmail($raw), $alias, $domain, $address);
$mid = (int)($res['message']->key ?? 0);
check($mid > 0, 'the fixture is stored');
$rows = array();
foreach (DbConnector::get_instance()->get_db_link()->query(
		'SELECT ima_content_id, ima_is_inline, ima_fil_file_id FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id = ' . $mid)
		->fetchAll(PDO::FETCH_ASSOC) as $r) {
	$rows[(string)$r['ima_content_id']] = $r;
}
$inline = function ($cid) use ($rows) { return isset($rows[$cid]) ? (bool)$rows[$cid]['ima_is_inline'] : null; };
check(count($rows) === 4, 'four parts besides the body', json_encode(array_keys($rows)));
check($inline('logo@example.com') === true, 'a picture the HTML shows is inline, though it says attachment');
check($inline('pic2@example.com') === true, 'one with no disposition, shown through CID:<...>, is inline');
check($inline('image002.emz@01DA0000.00000000') === false, 'a Content-ID the HTML never shows is an attachment (Outlook emz)');
check($inline('f_abc123') === false, 'a Gmail-style Content-ID on a PDF is an attachment');

section('The reader shows the inline picture and lists the rest');
$html = (string)(new InboundEmailMessage($mid, TRUE))->get('iem_body_html');
$out = MailboxService::resolveInlineImages(array(array('id' => $mid, 'body_html' => $html)));
$body = (string)$out[0]['body_html'];
check(stripos($body, 'cid:logo') === false && stripos($body, 'cid:<pic2') === false && substr_count($body, '/uploads/') === 2,
	'both shown pictures became signed URLs', $body);

section('A message with no HTML lists every part');
$plain_raw = "From: a@example.com\r\nTo: " . $address . "\r\nSubject: photo\r\nMessage-ID: <inline-plain-" . $suffix . "@example.com>\r\n"
	. "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"b\"\r\n\r\n--b\r\nContent-Type: text/plain\r\n\r\nA photo.\r\n"
	. "--b\r\nContent-Type: image/png; name=\"p.png\"\r\nContent-Disposition: inline; filename=\"p.png\"\r\nContent-ID: <p@x>\r\n"
	. "Content-Transfer-Encoding: base64\r\n\r\niVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==\r\n--b--\r\n";
$res = $router->storeMessage($plain_raw, $router->parseEmail($plain_raw), $alias, $domain, $address);
$pid = (int)($res['message']->key ?? 0);
$flag = DbConnector::get_instance()->get_db_link()->query(
	'SELECT ima_is_inline FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id = ' . $pid)->fetchColumn();
check($pid > 0 && $flag === false, 'an inline-disposed picture in a text-only message is listed, not hidden', var_export($flag, true));

section('A sealed message is decided in its owner\'s window');
if (!$session_ok) {
	harness_skip('could not start a CLI session for an unlock window');
} else {
	$box = new SealedBox();
	$owner = make_user('InlineFlag');
	$uid = (int)$owner->key;
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
	harness_defer(function () use ($uid) { VaultUnlock::lockAll($uid); });
	$sdomain = new InboundEmailDomain(NULL);
	$sdomain->set('ied_domain', 'inline-parts-sealed-' . $suffix . '.example');
	$sdomain->set('ied_owner_usr_user_id', $uid);
	$sdomain->set('ied_security_level', 'private');
	$sdomain->save();
	$salias = new InboundEmailAlias(NULL);
	$salias->set('iea_ied_inbound_email_domain_id', (int)$sdomain->key);
	$salias->set('iea_alias', 'inbox');
	$salias->set('iea_delivery_mode', 'store');
	$salias->save();
	$saddr = 'inbox@' . $sdomain->get('ied_domain');
	$sm = new InboundEmailMessage(NULL);
	$sm->set('iem_ied_inbound_email_domain_id', (int)$sdomain->key);
	$sm->set('iem_iea_inbound_email_alias_id', (int)$salias->key);
	$sm->set('iem_recipient', $saddr);
	$sm->set('iem_sender', 'sender@example.org');
	$sm->set('iem_subject', 'sealed');
	$sm->set('iem_body_plain', 'body');
	$sm->set('iem_body_html', 'x');
	$sm->set('iem_thread_key', 'tk-inline-' . $suffix);
	$sm->save();
	harness_register_model('InboundEmailMessage', (int)$sm->key);
	$smid = (int)$sm->key;
	InboundEmailMessage::sealAndPersistContent($smid, $vault, 'sender@example.org', $saddr, 'sealed', 'body',
		'<p><img src="cid:shown@x"></p>');
	$row = function (string $cid, bool $inline) use ($smid) {
		return (int)InboundMessageAttachment::CreateEntry(array(
			'ima_iem_inbound_email_message_id' => $smid, 'ima_filename' => $cid . '.png', 'ima_content_type' => 'image/png',
			'ima_size_bytes' => 10, 'ima_mime_part' => $cid === 'shown@x' ? '2' : '3', 'ima_content_id' => $cid,
			'ima_is_inline' => $inline, 'ima_is_sealed' => true))->key;
	};
	$shown_id = $row('shown@x', false);   // stored before the rule: shown, but called an attachment
	$hidden_id = $row('hidden@x', true);  // and the reverse
	$flags = function () use ($shown_id, $hidden_id) {
		$q = DbConnector::get_instance()->get_db_link()->query('SELECT ima_inbound_message_attachment_id AS id, ima_is_inline AS i,
			ima_inline_by_body AS d FROM ima_inbound_message_attachments WHERE ima_inbound_message_attachment_id IN ('
			. $shown_id . ',' . $hidden_id . ')');
		$out = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[(int)$r['id']] = array((bool)$r['i'], $r['d']); }
		return $out;
	};
	check(InlineFlagBackfill::hasWork($uid), 'the owner has rows waiting for their body');
	VaultUnlock::lockAll($uid);
	check(InlineFlagBackfill::drainForUser($uid, vault_fixture_dummy_key()) === 0 && $flags()[$shown_id][1] === null,
		'with the window shut nothing is decided');
	vault_fixture_open_window($uid, $kp['secret']);
	$changed = InlineFlagBackfill::drainForUser($uid, vault_fixture_dummy_key());
	$f = $flags();
	check($changed === 2, 'both rows changed', (string)$changed);
	check($f[$shown_id] === array(true, true), 'the picture the body shows is inline now, and marked decided', json_encode($f[$shown_id]));
	check($f[$hidden_id] === array(false, true), 'the Content-ID the body never shows is listed now, and marked decided', json_encode($f[$hidden_id]));
	check(!InlineFlagBackfill::hasWork($uid), 'and nothing is left: a row is decided once');
	VaultUnlock::lockAll($uid);
}

section('Every ingest path decides inline the same way');
$files = array('plugins/mailbox/includes/InboundEmailRouter.php', 'plugins/mailbox/includes/ImapIngestor.php');
foreach ($files as $rel) {
	$src = (string)file_get_contents(PathHelper::getIncludePath($rel));
	check(!preg_match('/\$isInline\s*=\s*\(?\s*\$disp\b/', $src) && strpos($src, "\$disp === 'inline'") === false,
		$rel . ' decides no inline flag from the disposition');
	preg_match_all('/\$isInline\s*=\s*([^;]+);/', $src, $m);
	$rules = array_unique(array_map('trim', $m[1]));
	check($rules !== array() && $rules == array('InlineParts::isInline($cid, $shown)'),
		$rel . ' sets every inline flag through InlineParts', json_encode(array_values($rules)));
}

harness_finish();
