<?php
/** @joinery-test
 * name: mail_header_text
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * PURPOSE: A header's text arrives readable however it was written (B30).
 *
 *  - MailHeaderText::decode: encoded words in UTF-8, Latin-1 and a multibyte
 *    charset; raw UTF-8 beside them kept (PHP's own decoders turn it into "?"
 *    or drop it); adjacent words joined; Latin-1 bytes outside a word read as
 *    Windows-1252; a folded value unfolded; an unreadable word left as written.
 *  - A message stored with a raw UTF-8 Subject and sender name keeps them.
 *  - MailAddressList reads an encoded name with raw UTF-8 beside it.
 *  - A message with raw Latin-1 header bytes is stored (B66): Subject, names,
 *    To/Cc and the kept header block all come out UTF-8 (MailHeaderText::block).
 *  - The whole route (processEmail) with a Latin-1 sender name, Message-ID and
 *    References: stored once, logged, thread key text; a log line cut at its
 *    column width cuts between characters; an IMAP-supplied thread key is text.
 *
 * Run: php plugins/mailbox/tests/mail_header_text_test.php
 *
 * @version 1.3 - NUL bytes (reviewer2 R1)
 * @version 1.2 - the whole route, the log line and the thread key (B66)
 * @version 1.1 - raw Latin-1 header bytes (B66)
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
if (!PluginHelper::isPluginActive('mailbox')) {
	harness_skip('mailbox plugin inactive');
	harness_finish();
}
require_once(__DIR__ . '/lib/mailbox_test_fixture.php');

section('Decoding a header value');
$cases = array(
	'raw UTF-8 is kept'                       => array('Héllo wörld', 'Héllo wörld'),
	'a word beside raw UTF-8'                 => array('=?UTF-8?B?' . base64_encode('Héllo') . '?= wörld', 'Héllo wörld'),
	'adjacent words are joined'               => array('=?UTF-8?Q?caf=C3=A9?= =?UTF-8?Q?_au_lait?=', 'café au lait'),
	'a Latin-1 word'                          => array('=?ISO-8859-1?Q?caf=E9?=', 'café'),
	'a multibyte charset'                     => array('=?iso-2022-jp?B?GyRCRnxLXDhsGyhC?=', '日本語'),
	'raw Latin-1 reads as Windows-1252'       => array("Plain \xe9t\xe9 \x93quoted\x94", 'Plain été “quoted”'),
	'a language tag is dropped'               => array('=?utf-8*en?q?hi_there?=', 'hi there'),
	'a folded value is unfolded'              => array("Re: =?UTF-8?B?w6k=?=\r\n =?UTF-8?B?w6k=?= end", 'Re: éé end'),
	'an unreadable word stays as written'     => array('=?bogus?B?!!!?=', '=?bogus?B?!!!?='),
	'text with no words is unchanged'         => array('no words at all', 'no words at all'),
);
foreach ($cases as $label => $c) {
	$got = MailHeaderText::decode($c[0]);
	check($got === $c[1], $label, json_encode($got, JSON_UNESCAPED_UNICODE));
}
check(mb_check_encoding(MailHeaderText::decode("\xff\xfe\x80 =?UTF-8?B?" . base64_encode("\xff") . '?='), 'UTF-8'),
	'whatever the bytes, the answer is UTF-8');

section('Stored mail keeps raw UTF-8 headers');
$suffix = bin2hex(random_bytes(4));
mailbox_purge_domains('header-text-%');
harness_defer(function () { mailbox_purge_domains('header-text-%'); });
$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', 'header-text-' . $suffix . '.example');
$domain->set('ied_is_enabled', true);
$domain->save();
$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
$alias->set('iea_alias', 'box');
$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
$alias->set('iea_is_enabled', true);
$alias->prepare();
$alias->save();
$to = 'box@header-text-' . $suffix . '.example';
$raw = "From: \"Zoë Ünïcode\" <zoe@sender.example>\r\nTo: " . $to . "\r\nSubject: Grüße aus Köln\r\n"
	. "Message-ID: <ht-" . $suffix . "@sender.example>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nHallo\r\n";
$router = new InboundEmailRouter();
$res = $router->storeMessage($raw, $router->parseEmail($raw), $alias, $domain, $to);
$msg = new InboundEmailMessage((int)$res['message']->key, TRUE);
check((string)$msg->get('iem_subject') === 'Grüße aus Köln', 'the Subject is stored as written', (string)$msg->get('iem_subject'));
check(strpos((string)$msg->get('iem_sender'), 'Zoë Ünïcode') !== false, 'and so is the sender\'s name', (string)$msg->get('iem_sender'));

section('Stored mail with raw Latin-1 header bytes (B66)');
// An old client writes Latin-1 straight into its headers. The header block is
// kept as text, and the database refuses bytes that are not UTF-8: the store
// failed and the message was deferred until its queue lifetime ran out.
$raw = "From: \"Ren\xE9 M\xFCller\" <rene@sender.example>\r\nTo: \"J\xF6rg\" <" . $to . ">\r\nCc: \"Fran\xE7oise\" <fr@sender.example>\r\n"
	. "Subject: Gr\xFC\xDFe aus K\xF6ln\r\nX-Note: Caf\xC3\xA9 stays UTF-8\r\n"
	. "Message-ID: <ht-l1-" . $suffix . "@sender.example>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=ISO-8859-1\r\n\r\nHall\xF6\r\n";
$res = null;
try {
	$res = $router->storeMessage($raw, $router->parseEmail($raw), $alias, $domain, $to);
} catch (Throwable $e) {
	check(false, 'a message with Latin-1 header bytes is stored', $e->getMessage());
}
if ($res !== null && !empty($res['message'])) {
	$msg = new InboundEmailMessage((int)$res['message']->key, TRUE);
	check((string)$msg->get('iem_subject') === 'Grüße aus Köln', 'a message with Latin-1 header bytes is stored, its Subject readable',
		(string)$msg->get('iem_subject'));
	check(strpos((string)$msg->get('iem_sender'), 'René Müller') !== false, 'and the sender\'s name', (string)$msg->get('iem_sender'));
	$block = (string)$msg->get('iem_raw_headers');
	check(mb_check_encoding($block, 'UTF-8') && strpos($block, 'Subject: Grüße aus Köln') !== false
		&& strpos($block, 'Cc: "Françoise"') !== false && strpos($block, 'X-Note: Café stays UTF-8') !== false,
		'the kept header block reads each Latin-1 line as Windows-1252 and leaves a UTF-8 line as it was', $block);
	check(strpos((string)$msg->get('iem_to'), 'Jörg') !== false && strpos((string)$msg->get('iem_cc'), 'Françoise') !== false,
		'the To and Cc names are readable', (string)$msg->get('iem_to') . ' / ' . (string)$msg->get('iem_cc'));
}
section('The whole route with a Latin-1 sender name (B66)');
// The routing log is written from the parsed From line, the thread key from
// References and the dedup lookups from the Message-ID: each must be text,
// or the message is stored and then deferred again on every retry.
$route_mid = 'ht-route-' . $suffix . "-caf\xE9@sender.example";
$raw = "From: \"Ren\xE9 Route\" <rene@sender.example>\r\nTo: " . $to . "\r\nSubject: Route " . $suffix . "\r\n"
	. "Message-ID: <" . $route_mid . ">\r\nReferences: <earlier-caf\xE9@sender.example>\r\n"
	. "X-Odd: before\0after\r\nX-Last: kept-" . $suffix . "\r\n"
	. "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=ISO-8859-1\r\n\r\nHall\xF6\r\n";
$code = null;
try {
	$code = $router->processEmail($raw, $to);
} catch (Throwable $e) {
	check(false, 'the routed message does not throw', $e->getMessage());
}
$db = DbConnector::get_instance()->get_db_link();
$stmt = $db->prepare("SELECT iem_inbound_email_message_id, iem_message_id_header, iem_thread_key FROM iem_inbound_email_messages
	WHERE iem_iea_inbound_email_alias_id = ? AND iem_subject = ?");
$stmt->execute(array((int)$alias->key, 'Route ' . $suffix));
$stored = $stmt->fetchAll(PDO::FETCH_ASSOC);
check($code === 0 && count($stored) === 1, 'it is accepted (exit 0) and stored once', 'exit ' . var_export($code, true) . ', rows ' . count($stored));
if ($stored) {
	check($stored[0]['iem_message_id_header'] === '<ht-route-' . $suffix . '-café@sender.example>'
		&& $stored[0]['iem_thread_key'] === '<earlier-café@sender.example>',
		'its Message-ID and thread key are text', json_encode($stored[0], JSON_UNESCAPED_UNICODE));
	$stmt = $db->prepare('SELECT iel_from_address FROM iel_inbound_email_logs WHERE iel_iem_inbound_email_message_id = ?');
	$stmt->execute(array((int)$stored[0]['iem_inbound_email_message_id']));
	$from_logged = (string)$stmt->fetchColumn();
	check(strpos($from_logged, 'René Route') !== false, 'and the routing log has its line, the sender readable', $from_logged);
	$kept = (string)(new InboundEmailMessage((int)$stored[0]['iem_inbound_email_message_id'], TRUE))->get('iem_raw_headers');
	check(strpos($kept, 'X-Odd: beforeafter') !== false && strpos($kept, 'X-Last: kept-' . $suffix) !== false,
		'a NUL in one header costs neither that header nor the ones after it');
}
check($router->computeThreadKey(array('headers' => array('references' => "<imap-caf\xE9@x>")), null) === '<imap-café@x>'
	&& mb_check_encoding((string)$router->computeThreadKey(array('headers' => array('in-reply-to' => str_repeat('é', 200))), null), 'UTF-8'),
	'a thread key from headers the IMAP path hands in is text, cut between characters');
// A Subject past the column's 4000 bytes, a two-byte character astride the cut.
$long_subject = str_repeat('a', 3999) . 'é tail ' . $suffix;
$raw = "From: long@sender.example\r\nTo: " . $to . "\r\nSubject: " . $long_subject . "\r\n"
	. "Message-ID: <ht-long-" . $suffix . "@sender.example>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nx\r\n";
$res = null;
try { $res = $router->storeMessage($raw, $router->parseEmail($raw), $alias, $domain, $to); } catch (Throwable $e) { $res = $e->getMessage(); }
check(is_array($res) && !empty($res['message']) && strlen((string)(new InboundEmailMessage((int)$res['message']->key, TRUE))->get('iem_subject')) === 3999,
	'a Subject longer than its column is cut between characters and stored', is_string($res) ? $res : '');
$log = InboundEmailLog::CreateEntry(str_repeat('a', 499) . 'é', 'x@y.example', str_repeat('s', 999) . 'ü', '', 'stored',
	(int)$alias->key, "error caf\xE9", (int)$domain->key);
check($log->key && mb_check_encoding((string)$log->get('iel_subject'), 'UTF-8') && strlen((string)$log->get('iel_subject')) === 999
	&& strlen((string)$log->get('iel_from_address')) === 499 && (string)$log->get('iel_error_message') === 'error café',
	'a log line cut at its column width cuts between characters');
$log->permanent_delete();

check(MailHeaderText::block("X-A: a\0b\r\nX-B: c") === "X-A: ab\r\nX-B: c" && MailHeaderText::decode("Hi\0 there") === 'Hi there',
	'a NUL byte is dropped, so nothing after it is lost');
check(MailHeaderText::block("A: \xE9\r\nB: \xC3\xA9\r\n C\xE9") === "A: é\r\nB: é\r\n Cé",
	'MailHeaderText::block works line by line, line endings kept');
check(MailHeaderText::block(substr("S: " . str_repeat("\xC3\xA9", 3), 0, 8), true) === "S: éé",
	'a block cut inside a character drops the broken tail');

section('An address list with an encoded name');
$list = MailAddressList::parse('=?UTF-8?B?' . base64_encode('Zoë') . '?= Ünïcode <z@x.example>, plain@x.example');
check(count($list) === 2 && $list[0]['name'] === 'Zoë Ünïcode', 'the name decodes, raw UTF-8 beside it kept',
	json_encode($list, JSON_UNESCAPED_UNICODE));

harness_finish();
