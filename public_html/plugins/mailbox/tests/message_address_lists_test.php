<?php
/** @joinery-test
 * name: message_address_lists
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Who else a message went to: the To and Cc lists (iem_to / iem_cc) survive
 * every ingest path and reach the reader.
 *
 *  - MailAddressList: quoted names containing commas, comments, groups,
 *    encoded words, a display name carrying a fake angle-addr, and a folded /
 *    repeated header in a raw block — all parse to one canonical form.
 *  - Plaintext push ingest stores both lists; getThread returns them; the
 *    envelope recipient is still iem_recipient (routing, untouched).
 *  - A row stored before the columns existed answers from its retained header
 *    block, and a row with neither answers '' — never a warning, never a write.
 *  - Sealed push ingest seals both lists under the message DEK (ciphertext at
 *    rest, opens to the canonical form); with no unlock window the thread read
 *    gives the placeholder, never ciphertext.
 *  - The IMAP-extracted path stores them from the parsed headers.
 *  - Joinery Direct: the outbound header part carries To and Cc (never Bcc),
 *    and the receiver reads them into meta in canonical form.
 *
 * Run: php tests/run.php db --filter=message_address_lists
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');

if (!PluginHelper::isPluginActive('mailbox')) {
	harness_skip('mailbox plugin inactive');
	harness_finish();
}
if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}

require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectEnvelope.php'));

$db = DbConnector::get_instance()->get_db_link();
$box = new SealedBox();
$crypto = new VaultCrypto();
$router = new InboundEmailRouter();
$suffix = bin2hex(random_bytes(4));

$CC_HEADER = '"Ford, Tom" <tford@akamai.example>, "Beltran, Luis" <lubeltra@akamai.example>';
$CC_CANON  = '"Ford, Tom" <tford@akamai.example>, "Beltran, Luis" <lubeltra@akamai.example>';

// ---- MailAddressList -------------------------------------------------------
section('MailAddressList: one canonical form from every header shape');

check(MailAddressList::fromHeader($CC_HEADER) === $CC_CANON,
	'quoted names with commas keep their commas and their quotes', MailAddressList::fromHeader($CC_HEADER));
check(MailAddressList::fromHeader('"info@x.example" <info@x.example>') === 'info@x.example',
	'a name equal to the address is dropped');
check(MailAddressList::fromHeader('a@x.example, Bob <b@x.example>; c@x.example (Carol)') === 'a@x.example, "Bob" <b@x.example>, c@x.example',
	'bare addresses, unquoted names, semicolons and comments');
check(MailAddressList::fromHeader('Team: a@x.example, "Support <fake@evil.example>" <real@ok.example>;')
		=== 'a@x.example, "Support fake@evil.example" <real@ok.example>',
	'a group label is dropped and a fake angle-addr inside a name cannot become the address');
check(MailAddressList::fromHeader('=?UTF-8?Q?J=C3=BCrgen_M=C3=BCller?= <j@x.example>, a@x.example, A@X.EXAMPLE')
		=== '"Jürgen Müller" <j@x.example>, a@x.example',
	'encoded words decode and a repeated address (any case) is stored once');
check(MailAddressList::fromHeader(array('a@x.example', 'b@x.example')) === 'a@x.example, b@x.example',
	'a repeated header (array) is one list');
check(MailAddressList::fromHeader('') === '' && MailAddressList::fromHeader(null) === '',
	'nothing in, nothing out');
check(MailAddressList::addresses($CC_CANON) === array('tford@akamai.example', 'lubeltra@akamai.example'),
	'addresses() gives the bare lowercase addresses in order');
check(MailAddressList::format(array(array('email' => 'a@x.example', 'name' => 'Ann "A" <x>'), 'b@x.example'))
		=== '"Ann A x" <a@x.example>, b@x.example',
	'format() strips quotes and angle brackets from a name, accepts a bare string entry');

$block = "Return-Path: <z@a.example>\r\nTo: \"info@x.example\" <info@x.example>\r\n"
	. "CC: \"Ford, Tom\" <tford@akamai.example>, \"Beltran,\r\n Luis\" <lubeltra@akamai.example>\r\n"
	. "Subject: x\r\nX-To: nope@x.example\r\n";
$lists = MailAddressList::fromHeaderBlock($block);
check($lists['to'] === 'info@x.example' && $lists['cc'] === $CC_CANON,
	'fromHeaderBlock() unfolds a continued Cc and ignores X-To', json_encode($lists));
check(MailAddressList::fromHeaderBlock("Subject: none\r\n") === array('to' => '', 'cc' => ''),
	'a block with neither header answers two empty lists');

// ---- Fixtures --------------------------------------------------------------
$user = make_user('AddrLists');
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

$plain_domain = new InboundEmailDomain(NULL);
$plain_domain->set('ied_domain', 'addr-p-' . $suffix . '.example');
$plain_domain->set('ied_owner_usr_user_id', $uid);
$plain_domain->set('ied_is_enabled', true);
$plain_domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$plain_domain->key);

$sealed_domain = new InboundEmailDomain(NULL);
$sealed_domain->set('ied_domain', 'addr-s-' . $suffix . '.example');
$sealed_domain->set('ied_owner_usr_user_id', $uid);
$sealed_domain->set('ied_is_protected_identity', true);
$sealed_domain->set('ied_security_level', 'private');
$sealed_domain->set('ied_is_enabled', true);
$sealed_domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$sealed_domain->key);

$mk_alias = function (InboundEmailDomain $d) use ($uid) {
	$a = new InboundEmailAlias(NULL);
	$a->set('iea_ied_inbound_email_domain_id', (int)$d->key);
	$a->set('iea_alias', 'inbox');
	$a->set('iea_delivery_mode', 'store');
	$a->set('iea_is_enabled', true);
	$a->save();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', (int)$a->key);
	$g = new InboundEmailMailboxGrant(NULL);
	$g->set('ieg_iea_inbound_email_alias_id', (int)$a->key);
	$g->set('ieg_usr_user_id', $uid);
	$g->save();
	harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', (int)$g->key);
	return $a;
};
$plain_alias = $mk_alias($plain_domain);
$sealed_alias = $mk_alias($sealed_domain);

$raw_email = function (string $token, string $recipient) use ($CC_HEADER) {
	return implode("\r\n", array(
		'Received: from mx.example ([192.0.2.1]) by test.example; Mon, 14 Sep 2026 12:00:00 +0000',
		'From: "Hartleb, Zak" <zhartleb@akamai.example>',
		'To: "' . $recipient . '" <' . $recipient . '>',
		'CC: ' . $CC_HEADER,
		'Subject: Thread ' . $token,
		'Message-ID: <' . $token . '@example.com>',
		'MIME-Version: 1.0',
		'Content-Type: text/plain; charset=UTF-8',
		'',
		'Body of ' . $token . '.',
		'',
	));
};

$reload = function (int $id) { return new InboundEmailMessage($id, TRUE); };
$svc = new MailboxService(MailboxViewer::forUser($uid, 5));
$thread_of = function (InboundEmailAlias $a, InboundEmailMessage $m) use ($svc) {
	return $svc->getThread((int)$a->key, (string)$m->get('iem_thread_key'));
};
$raw_row = function (int $id) use ($db) {
	return $db->query('SELECT * FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ' . $id)
		->fetch(PDO::FETCH_ASSOC);
};

// ---- Plaintext push ingest -------------------------------------------------
section('Plaintext push ingest stores both lists and the reader gets them');

$token = 'addr-plain-' . $suffix;
$plain_addr = 'inbox@' . $plain_domain->get('ied_domain');
$raw = $raw_email($token, $plain_addr);
$res = $router->storeMessage($raw, $router->parseEmail($raw), $plain_alias, $plain_domain, $plain_addr);
$plain_id = (int)$res['message']->key;
harness_register_model('InboundEmailMessage', $plain_id);

$m = $reload($plain_id);
check((string)$m->get('iem_to') === $plain_addr, 'iem_to holds the To list (name equal to address dropped)', (string)$m->get('iem_to'));
check((string)$m->get('iem_cc') === $CC_CANON, 'iem_cc holds the Cc list in canonical form', (string)$m->get('iem_cc'));
check((string)$m->get('iem_recipient') === $plain_addr, 'iem_recipient is still the one routing address');

$thread = $thread_of($plain_alias, $m);
check(count($thread) === 1 && $thread[0]['to'] === $plain_addr && $thread[0]['cc'] === $CC_CANON,
	'getThread returns to and cc', json_encode(array($thread[0]['to'] ?? null, $thread[0]['cc'] ?? null)));

// ---- Rows from before the columns existed ---------------------------------
section('A legacy row answers from its retained header block, or says nothing');

$db->prepare('UPDATE iem_inbound_email_messages SET iem_to = NULL, iem_cc = NULL
	WHERE iem_inbound_email_message_id = ?')->execute(array($plain_id));
$thread = $thread_of($plain_alias, $reload($plain_id));
check($thread[0]['to'] === $plain_addr && $thread[0]['cc'] === $CC_CANON,
	'NULL columns + retained headers: the lists come from iem_raw_headers', json_encode(array($thread[0]['to'], $thread[0]['cc'])));
$after = $raw_row($plain_id);
check($after['iem_to'] === null && $after['iem_cc'] === null,
	'…and the page view wrote nothing back');

$db->prepare('UPDATE iem_inbound_email_messages SET iem_raw_headers = NULL
	WHERE iem_inbound_email_message_id = ?')->execute(array($plain_id));
$thread = $thread_of($plain_alias, $reload($plain_id));
check($thread[0]['to'] === '' && $thread[0]['cc'] === '',
	'NULL columns and no header block: both lists are empty strings');

// ---- IMAP-extracted path ---------------------------------------------------
section('The IMAP-extracted path stores the lists from its parsed headers');

$token = 'addr-imap-' . $suffix;
$auth = array('dkim' => 'unverified', 'spf' => 'unverified', 'dmarc' => 'unverified', 'source' => 'none');
$res = $router->storeExtracted(array(
	'sender'            => 'sender@example.com',
	'subject'           => 'Thread ' . $token,
	'body_plain'        => 'Body of ' . $token . '.',
	'body_html'         => '',
	'message_id_header' => '<' . $token . '@example.com>',
	'headers'           => array('to' => $plain_addr . ', other@x.example', 'cc' => $CC_HEADER),
	'size_bytes'        => 100,
	'received_time'     => gmdate('Y-m-d H:i:s'),
), $plain_alias, $plain_domain, $plain_addr, $auth);
$imap_id = (int)$res['message']->key;
harness_register_model('InboundEmailMessage', $imap_id);
$m = $reload($imap_id);
check((string)$m->get('iem_to') === $plain_addr . ', other@x.example' && (string)$m->get('iem_cc') === $CC_CANON,
	'storeExtracted stores To and Cc', json_encode(array($m->get('iem_to'), $m->get('iem_cc'))));

// ---- Joinery Direct --------------------------------------------------------
section('Joinery Direct carries To and Cc in the header part, never Bcc');

$email = new EmailMessage();
$email->from('alice@example.com', 'Alice')->to('bob@x.test', 'Bob')->to('carol@x.test')
	->cc('dan@x.test', 'Dan, Jr.')->bcc('hidden@x.test')->subject('Hi');
$header_block = MailDirectHandler::buildParts($email)[0]['bytes'];
check(strpos($header_block, "To: \"Bob\" <bob@x.test>, carol@x.test\r\n") !== false,
	'the outbound header part carries To in canonical form', $header_block);
check(strpos($header_block, "Cc: \"Dan, Jr.\" <dan@x.test>\r\n") !== false,
	'and Cc', $header_block);
check(strpos($header_block, 'hidden@x.test') === false, 'Bcc never leaves the sender');

$envelope = DirectEnvelope::fromVerified(array(
	'kind' => 'mail', 'sender' => 'alice@example.com', 'sender_domain' => 'example.com',
	'recipient' => 'bob@x.test', 'recipient_user_id' => 7, 'recipient_alias_id' => 3,
	'nonce' => 'abcdef0123456789abcdef0123456789', 'timestamp' => '2026-09-14 10:00:00',
));
$parse = new ReflectionMethod('MailDirectHandler', 'parseHeaderPart');
$parse->setAccessible(true);
$meta = $parse->invoke(null, $header_block, $envelope);
check(($meta['to'] ?? '') === '"Bob" <bob@x.test>, carol@x.test' && ($meta['cc'] ?? '') === '"Dan, Jr." <dan@x.test>',
	'the receiver reads both lists back into meta', json_encode(array($meta['to'] ?? null, $meta['cc'] ?? null)));

// Last on purpose: opening the sealed lists below marks this process as having
// held sealed content, after which the egress guard refuses any plaintext
// write over 64 characters — which every ingest above legitimately does.
// ---- Sealed push ingest ----------------------------------------------------
section('Sealed push ingest seals both lists under the message DEK');

$token = 'addr-sealed-' . $suffix;
$sealed_addr = 'inbox@' . $sealed_domain->get('ied_domain');
$raw = $raw_email($token, $sealed_addr);
$res = $router->storeMessage($raw, $router->parseEmail($raw), $sealed_alias, $sealed_domain, $sealed_addr);
$sealed_id = (int)$res['message']->key;
harness_register_model('InboundEmailMessage', $sealed_id);

$row = $raw_row($sealed_id);
check(in_array($row['iem_content_sealed'], array('t', true, '1', 1), true), 'the row is sealed (precondition)');
check(strpos((string)$row['iem_to'], 'v1.aead.') === 0 && strpos((string)$row['iem_cc'], 'v1.aead.') === 0,
	'iem_to and iem_cc hold ciphertext, never plaintext');
$dek = $crypto->openItemDek((string)$row['iem_sealed_key'], vault_fixture_key($kp['secret']));
check($crypto->openField((string)$row['iem_cc'], $dek, InboundEmailMessage::sealAd($sealed_id, 'iem_cc')) === $CC_CANON,
	'the sealed Cc opens with the row DEK to the canonical list');
check($crypto->openField((string)$row['iem_to'], $dek, InboundEmailMessage::sealAd($sealed_id, 'iem_to')) === $sealed_addr,
	'so does the sealed To');

$thread = $thread_of($sealed_alias, $reload($sealed_id));
check($thread[0]['to'] === MailboxService::SEALED_PLACEHOLDER && $thread[0]['cc'] === MailboxService::SEALED_PLACEHOLDER,
	'with no unlock window the reader gets the placeholder, not ciphertext', json_encode(array($thread[0]['to'], $thread[0]['cc'])));

// A sealed legacy row (lists NULL, header block sealed) is locked, not empty.
$db->prepare('UPDATE iem_inbound_email_messages SET iem_to = NULL, iem_cc = NULL
	WHERE iem_inbound_email_message_id = ?')->execute(array($sealed_id));
$thread = $thread_of($sealed_alias, $reload($sealed_id));
check($thread[0]['to'] === MailboxService::SEALED_PLACEHOLDER,
	'a sealed row with NULL lists and a sealed header block answers locked');

harness_finish();
