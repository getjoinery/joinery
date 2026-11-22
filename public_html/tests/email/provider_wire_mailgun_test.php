<?php
/** @joinery-test
 * name: provider_wire_mailgun
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * MailgunProvider on the wire (specs/email_provider_sdk_removal.md, WP4), over
 * ProviderHttp's mocked transport: what each call sends and how each answer is
 * read. No network.
 *
 *  - send: multipart fields, named file parts, the pinned Message-Id, basic auth
 *  - sendBatch: carries Reply-To, headers, attachments and the text part (B2)
 *  - an unreadable attachment fails the send and nothing is sent (B6)
 *  - messages.mime: one 'to' per destination, the message always as content
 *    (even when the MIME string is a valid path), no 'sender' field (B4)
 *  - events: float timestamp, a 401 domain counted as not asked
 *  - domains: show 404 = not registered, show active, create's fields, verify
 *  - operator wording for 401 and 404
 *
 * Run: php tests/run.php --filter=provider_wire_mailgun
 *
 * @version 1.1 - recipient-variables survive a name that is not UTF-8 (B13); file parts carry our own
 *   quoted Content-Disposition (B18); the pre-send domain lookup is not retried (B21); a batch leaves
 *   out the pinned Message-Id (B15); transport failures; send() chunks at 500; 201; the EU base
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getComposerAutoloadPath());

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

$GLOBALS['mg_history'] = array();

function mg_mock(array $queue): MockHandler {
	$GLOBALS['mg_history'] = array();
	$mock = new MockHandler($queue);
	$stack = HandlerStack::create($mock);
	$stack->push(Middleware::history($GLOBALS['mg_history']));
	ProviderHttp::useTestHandler($stack);
	// Each scenario starts with an empty domain-state cache.
	foreach (array('sending_domain_state', 'sending_domain_error') as $prop) {
		(new ReflectionProperty('MailgunProvider', $prop))->setValue(null, array());
	}
	return $mock;
}

function mg_json(array $body, int $status = 200): Response {
	return new Response($status, array('Content-Type' => 'application/json'), json_encode($body));
}

/** The request $i sent. */
function mg_req(int $i = 0) { return $GLOBALS['mg_history'][$i]['request'] ?? null; }

/**
 * A multipart body as a list of [name, filename|null, content-type|null, contents].
 */
function mg_parts($request): array {
	$ct = $request->getHeaderLine('Content-Type');
	if (!preg_match('/boundary=("?)([^";]+)\1/', $ct, $m)) { return array(); }
	$body = (string)$request->getBody();
	$out = array();
	foreach (explode('--' . $m[2], $body) as $chunk) {
		if (trim($chunk) === '' || trim($chunk) === '--') { continue; }
		$chunk = ltrim($chunk, "\r\n");
		$split = strpos($chunk, "\r\n\r\n");
		if ($split === false) { continue; }
		$head = substr($chunk, 0, $split);
		$contents = substr($chunk, $split + 4);
		if (substr($contents, -2) === "\r\n") { $contents = substr($contents, 0, -2); }
		preg_match('/name="([^"]*)"/', $head, $n);
		$filename = preg_match('/filename="([^"]*)"/', $head, $f) ? $f[1] : null;
		$type = preg_match('/Content-Type:\s*([^\r\n]+)/i', $head, $t) ? trim($t[1]) : null;
		$out[] = array($n[1] ?? '', $filename, $type, $contents);
	}
	return $out;
}

/** Values of every part named $name. */
function mg_field(array $parts, string $name): array {
	$vals = array();
	foreach ($parts as $p) { if ($p[0] === $name) { $vals[] = $p[3]; } }
	return $vals;
}

function mg_find(array $parts, string $name): ?array {
	foreach ($parts as $p) { if ($p[0] === $name) { return $p; } }
	return null;
}

harness_set_setting_mem('mailgun_api_key', 'key-0123456789abcdef0123456789abcdef');
harness_set_setting_mem('mailgun_domain', 'mg.example.test');
if (!harness_stored_setting_is_blank('mailgun_eu_api_link')) {
	harness_set_setting_mem('mailgun_eu_api_link', 'https://api.mailgun.net');
}
harness_defer(function () { ProviderHttp::useTestHandler(null); });

function mg_message(): EmailMessage {
	return (new EmailMessage())
		->from('sender@mg.example.test', 'Sender Name')
		->to('one@example.com', 'One')
		->subject('Hello')
		->html('<p>Hi <img src="cid:logo1"></p>')
		->text('Hi')
		->replyTo('reply@example.com')
		->header('In-Reply-To', '<parent@example.com>')
		->attachData('PDFBYTES', 'report.pdf', 'application/pdf')
		->attachInlineData('PNGBYTES', 'logo1', 'logo.png', 'image/png');
}

section('send: multipart fields and named file parts');

$m = mg_message();
$m->messageId('<pinned-1@mg.example.test>');
mg_mock(array(mg_json(array('id' => '<abc.123@mg.example.test>', 'message' => 'Queued. Thank you.'))));
$provider = new MailgunProvider();
check($provider->send($m) === true, 'send() returns true on a 200');
$req = mg_req();
check($req && $req->getMethod() === 'POST'
	&& (string)$req->getUri() === 'https://api.mailgun.net/v3/mg.example.test/messages', 'POSTs to /v3/{domain}/messages');
check($req && $req->getHeaderLine('Authorization') === 'Basic ' . base64_encode('api:key-0123456789abcdef0123456789abcdef'),
	'basic auth as api:key');
$parts = $req ? mg_parts($req) : array();
check(mg_field($parts, 'from') === array('Sender Name <sender@mg.example.test>'), 'from is Name <email>');
check(mg_field($parts, 'to') === array('One <one@example.com>'), 'to');
check(mg_field($parts, 'subject') === array('Hello'), 'subject');
check(mg_field($parts, 'html') === array('<p>Hi <img src="cid:logo1"></p>') && mg_field($parts, 'text') === array('Hi'),
	'html and text both sent');
check(mg_field($parts, 'h:Reply-To') === array('reply@example.com'), 'Reply-To as h:Reply-To');
check(mg_field($parts, 'h:Message-Id') === array('<pinned-1@mg.example.test>'), 'the pinned Message-Id as h:Message-Id');
check(mg_field($parts, 'h:In-Reply-To') === array('<parent@example.com>'), 'a custom header as h:<name>');
check(json_decode(mg_field($parts, 'recipient-variables')[0] ?? '', true) === array('one@example.com' => array('name' => 'One')),
	'recipient-variables');
$att = mg_find($parts, 'attachment');
check($att && $att[1] === 'report.pdf' && $att[2] === 'application/pdf' && $att[3] === 'PDFBYTES',
	'an attachment is an attachment file part with its filename and type');
$inl = mg_find($parts, 'inline');
check($inl && $inl[1] === 'logo1' && $inl[3] === 'PNGBYTES', 'an inline part is an inline file part named by its cid');
check($provider->lastSendReceipt() === array('id' => 'abc.123@mg.example.test', 'response' => 'Queued. Thank you.'),
	'the receipt is the id (brackets trimmed) and message');

mg_mock(array(new Response(202, array(), '{"id":"<x@y>","message":"ok"}')));
check((new MailgunProvider())->send(mg_message()) === true, '202 counts as success');

mg_mock(array(mg_json(array('message' => 'Forbidden'), 401)));
check((new MailgunProvider())->send(mg_message()) === false, 'a 401 fails the send');

section('An unreadable attachment fails the send (B6)');

$m = mg_message();
$ref = new ReflectionProperty('EmailMessage', 'attachments');
$atts = $ref->getValue($m);
$atts[] = array('path' => '/nonexistent/gone.pdf', 'name' => 'gone.pdf');
$ref->setValue($m, $atts);
mg_mock(array(mg_json(array('id' => '<a@b>', 'message' => 'ok'))));
check((new MailgunProvider())->send($m) === false && count($GLOBALS['mg_history']) === 0,
	'send() fails and sends nothing');
mg_mock(array(mg_json(array('id' => '<a@b>', 'message' => 'ok'))));
$r = (new MailgunProvider())->sendBatch($m, array('a@example.com', 'b@example.com'));
check($r['success'] === false && $r['failed_recipients'] === array('a@example.com', 'b@example.com')
	&& count($GLOBALS['mg_history']) === 0, 'sendBatch() fails every recipient and sends nothing');

section('sendBatch: one builder, chunks of 500 (B2)');

$recipients = array();
for ($i = 0; $i < 501; $i++) { $recipients[] = 'r' . $i . '@example.com'; }
mg_mock(array(mg_json(array('id' => '<1@x>', 'message' => 'ok')), mg_json(array('message' => 'boom'), 500)));
$r = (new MailgunProvider())->sendBatch(mg_message(), $recipients);
check(count($GLOBALS['mg_history']) === 2, '501 recipients go in two calls');
check($r['success'] === false && $r['failed_recipients'] === array('r500@example.com'),
	'a failed chunk fails its own recipients only');
$parts = mg_parts(mg_req(0));
check(count(explode(',', mg_field($parts, 'to')[0] ?? '')) === 500, 'the first chunk holds 500');
check(mg_field($parts, 'text') === array('Hi') && mg_field($parts, 'html') !== array(), 'a batch keeps the text part beside the html');
check(mg_field($parts, 'h:Reply-To') === array('reply@example.com'), 'a batch carries Reply-To');
check(mg_field($parts, 'h:In-Reply-To') === array('<parent@example.com>'), 'a batch carries custom headers');
check(mg_find($parts, 'attachment') !== null && mg_find($parts, 'inline') !== null, 'a batch carries attachments');

section('messages.mime: raw relay');

$mime_path = tempnam(sys_get_temp_dir(), 'mgmime');
file_put_contents($mime_path, 'DISK CONTENTS, NEVER SENT');
harness_defer(function () use ($mime_path) { @unlink($mime_path); });
mg_mock(array(mg_json(array('message' => 'nf'), 404), mg_json(array('id' => '<r@x>', 'message' => 'ok'))));
$res = (new MailgunProvider())->relayRawMessage($mime_path, 'bounce@other.example', array('d1@example.com', 'd2@example.com'));
check($res === array('d1@example.com' => true, 'd2@example.com' => true), 'every destination maps to the shared result');
// The sender domain lookup came first (other.example is not the configured domain).
$last = mg_req(count($GLOBALS['mg_history']) - 1);
check($last && (string)$last->getUri() === 'https://api.mailgun.net/v3/mg.example.test/messages.mime',
	'POSTs to /v3/{domain}/messages.mime (fell back to the configured domain)');
$parts = $last ? mg_parts($last) : array();
check(mg_field($parts, 'to') === array('d1@example.com', 'd2@example.com'), 'one to field per destination');
$msg = mg_find($parts, 'message');
check($msg && $msg[1] === 'message' && $msg[3] === $mime_path,
	'the message part is named message, filename message, and holds the string even when it is a valid path');
check(mg_find($parts, 'sender') === null && mg_find($parts, 'o:sender') === null,
	'no envelope-sender field: messages.mime has none (B4)');

mg_mock(array(mg_json(array('message' => 'nope'), 400), mg_json(array('message' => 'nope'), 400)));
$res = (new MailgunProvider())->relayRawMessage('raw', '', array('d1@example.com'));
check($res === array('d1@example.com' => false), 'a refused relay maps every destination to false');

section('events');

harness_set_setting_mem('mailgun_domain', 'mg.example.test');
// Order: apiDomainForSender runs first (show), then the events per domain.
mg_mock(array(
	mg_json(array('domain' => array('state' => 'active'))),
	mg_json(array('message' => 'Forbidden'), 401),
	mg_json(array('items' => array(
		array('event' => 'delivered', 'timestamp' => 1700000001.987, 'recipient' => 'a@x',
			'delivery-status' => array('code' => 250, 'message' => 'OK')),
		array('event' => 'accepted', 'timestamp' => 1700000000.5, 'recipient' => 'a@x'),
	))),
));
$ev = (new MailgunProvider())->deliveryEvents('<id-1@x>', 'other.example');
$q = mg_req(1);
check($q && $q->getUri()->getPath() === '/v3/mg.example.test/events'
	&& strpos($q->getUri()->getQuery(), 'message-id=id-1%40x') !== false && strpos($q->getUri()->getQuery(), 'limit=100') !== false,
	'GET /v3/{domain}/events?message-id=…&limit=100');
check(is_array($ev) && $ev['status'] === 'delivered', 'a 401 domain is skipped and the next domain answers');
check(is_array($ev) && ($ev['events'][0]['time'] ?? '') === gmdate('Y-m-d H:i:s', 1700000000)
	&& ($ev['events'][1]['time'] ?? '') === gmdate('Y-m-d H:i:s', 1700000001), 'a float timestamp is cast to int, events in time order');
check(is_array($ev) && ($ev['events'][1]['detail'] ?? '') === '250 OK', 'detail carries the delivery code and message');

mg_mock(array(mg_json(array('message' => 'Forbidden'), 401)));
check((new MailgunProvider())->deliveryEvents('<id-1@x>', 'mg.example.test') === null,
	'only a 401 answer: no domain was asked, so the lookup is null, not "no events"');

section('domains');

mg_mock(array(mg_json(array('message' => 'Domain not found'), 404)));
check(MailgunProvider::getSendingDomainState('new.example') === 'not_registered', 'a 404 on show means not registered');
check((string)mg_req()->getUri() === 'https://api.mailgun.net/v3/domains/new.example', 'GET /v3/domains/{d}');

mg_mock(array(mg_json(array('domain' => array('name' => 'ok.example', 'state' => 'Active'),
	'sending_dns_records' => array(
		array('record_type' => 'TXT', 'name' => 'pic._domainkey.ok.example', 'value' => 'k=rsa; p=AAA'),
		array('record_type' => 'TXT', 'name' => 'ok.example', 'value' => 'v=spf1 include:mailgun.org ~all'),
	)))));
check(MailgunProvider::getSendingDomainState('ok.example') === 'active', 'show reads domain.state, lowercased');

mg_mock(array(mg_json(array('domain' => array('state' => 'active'), 'sending_dns_records' => array(
	array('record_type' => 'txt', 'name' => 'pic._domainkey.ok.example', 'value' => 'k=rsa; p=AAA'),
	array('record_type' => 'TXT', 'name' => 'ok.example', 'value' => 'v=spf1'),
)))));
$dk = MailgunProvider::getDkimStatus('ok.example');
check($dk === array('status' => 'ok', 'records' => array(array('type' => 'TXT', 'name' => 'pic._domainkey.ok.example', 'value' => 'k=rsa; p=AAA'))),
	'getDkimStatus keeps the _domainkey records');
mg_mock(array(mg_json(array('message' => 'nf'), 404)));
check(MailgunProvider::getDkimStatus('nope.example')['status'] === 'not_registered', 'getDkimStatus: 404 is not_registered');

mg_mock(array(mg_json(array('message' => 'Domain not found'), 404), mg_json(array('message' => 'Domain has been created'))));
check(MailgunProvider::createSendingDomain('fresh.example') === array('status' => 'ok'), 'createSendingDomain creates after a 404');
$c = mg_req(1);
parse_str((string)$c->getBody(), $form);
check($c->getMethod() === 'POST' && (string)$c->getUri() === 'https://api.mailgun.net/v3/domains', 'POST /v3/domains');
check(($form['name'] ?? '') === 'fresh.example' && ($form['force_dkim_authority'] ?? '') === 'true'
	&& ($form['dkim_key_size'] ?? '') === '2048' && ($form['web_scheme'] ?? '') === 'https',
	'create sends force_dkim_authority=true, dkim_key_size=2048, web_scheme=https', (string)$c->getBody());

mg_mock(array(mg_json(array('domain' => array('state' => 'active')))));
check(MailgunProvider::createSendingDomain('have.example') === array('status' => 'ok') && count($GLOBALS['mg_history']) === 1,
	'an existing domain is ok without a create');

mg_mock(array(mg_json(array('message' => 'ok')), mg_json(array('domain' => array('state' => 'active')))));
check(MailgunProvider::verifySendingDomain('v.example') === 'active', 'verify then re-read the fresh state');
check(mg_req(0)->getMethod() === 'PUT' && mg_req(0)->getUri()->getPath() === '/v3/domains/v.example/verify',
	'PUT /v3/domains/{d}/verify');
mg_mock(array(mg_json(array('message' => 'nf'), 404)));
check(MailgunProvider::verifySendingDomain('gone.example') === 'not_registered', 'verify: 404 is not_registered');

section('Operator wording');

mg_mock(array(mg_json(array('message' => 'Forbidden'), 401)));
MailgunProvider::getSendingDomainState('k.example');
check(MailgunProvider::getSendingDomainError('k.example') === 'Mailgun rejected the API key.', '401: our own words');

mg_mock(array(mg_json(array('message' => 'Internal'), 500), mg_json(array('message' => 'Internal'), 500), mg_json(array('message' => 'Internal'), 500)));
MailgunProvider::getSendingDomainState('e.example');
check(MailgunProvider::getSendingDomainError('e.example') === 'Mailgun answered 500: Internal', 'other: the status and Mailgun\'s message');

mg_mock(array(mg_json(array('message' => 'Forbidden'), 401)));
$v = MailgunProvider::validateApiConnection();
check($v['success'] === false && $v['error'] === 'Mailgun rejected the API key.', 'validateApiConnection: 401 wording');

mg_mock(array(mg_json(array('message' => 'Domain not found'), 404),
	mg_json(array('items' => array(array('name' => 'other.example'))))));
$v = MailgunProvider::validateApiConnection();
check($v['success'] === false && $v['error'] === 'This domain is not in the Mailgun account.', 'validateApiConnection: 404 wording');
$l = mg_req(1);
check($l && $l->getUri()->getPath() === '/v3/domains' && strpos($l->getUri()->getQuery(), 'limit=100') !== false
	&& strpos($l->getUri()->getQuery(), 'skip=0') !== false, 'the suggestion lists /v3/domains?limit=100&skip=0');

mg_mock(array(mg_json(array('message' => 'Domain not found'), 404),
	mg_json(array('items' => array(array('name' => 'mg.example.test.old'))))));
$v = MailgunProvider::validateApiConnection();
check(($v['details']['Suggested Domain'] ?? '') === 'mg.example.test.old', 'a near-match domain is suggested');

mg_mock(array(mg_json(array('domain' => array('name' => 'mg.example.test', 'state' => 'active')))));
$v = MailgunProvider::validateApiConnection();
check($v['success'] === true && $v['label'] === 'API Key Valid' && ($v['details']['Status'] ?? '') === 'active',
	'validateApiConnection: a 200 is valid, with name and state');

section('Review fixes (B13, B15, B18, B21) and transport failures');

// B13: a name that is not valid UTF-8 never empties recipient-variables.
$m = (new EmailMessage())->from('sender@mg.example.test')->subject('s')->text('t');
$rp = new ReflectionProperty('EmailMessage', 'recipients');
$rp->setValue($m, array(array('email' => 'bad@example.com', 'name' => "Bad\xffName")));
mg_mock(array(mg_json(array('id' => '<a@b>', 'message' => 'ok'))));
check((new MailgunProvider())->send($m) === true, 'B13: a send whose recipient name is not UTF-8 still goes');
$vars = json_decode(mg_field(mg_parts(mg_req()), 'recipient-variables')[0] ?? '', true);
check(is_array($vars) && isset($vars['bad@example.com']['name']) && $vars['bad@example.com']['name'] !== '',
	'B13: recipient-variables carries the address, the bad byte substituted', json_encode($vars));

// B18: a cid holding a slash and a name holding a quote go out whole.
$m = (new EmailMessage())->from('sender@mg.example.test')->to('one@example.com')->subject('s')->html('<img src="cid:a/b@c">');
$ap = new ReflectionProperty('EmailMessage', 'attachments');
$ap->setValue($m, array(
	array('data' => 'PNG', 'name' => 'logo.png', 'type' => 'image/png', 'cid' => 'a/b@c', 'inline' => true),
	array('data' => 'PDF', 'name' => 'say "hi".pdf', 'type' => 'application/pdf'),
));
mg_mock(array(mg_json(array('id' => '<a@b>', 'message' => 'ok'))));
(new MailgunProvider())->send($m);
$body = (string)mg_req()->getBody();
check(strpos($body, 'Content-Disposition: form-data; name="inline"; filename="a/b@c"') !== false,
	'B18: an inline cid with a slash is sent whole, not basename()\'d');
check(strpos($body, 'Content-Disposition: form-data; name="attachment"; filename="say \"hi\".pdf"') !== false,
	'B18: a quote in a filename is escaped');
check(substr_count($body, 'Content-Disposition:') === count(mg_parts(mg_req())),
	'B18: each part has exactly one Content-Disposition (Guzzle adds none of its own)');

// B15: a batch does not give every recipient the pinned Message-Id.
$m = mg_message();
$m->messageId('<pinned-2@mg.example.test>');
mg_mock(array(mg_json(array('id' => '<1@x>', 'message' => 'ok'))));
(new MailgunProvider())->sendBatch($m, array('a@example.com', 'b@example.com'));
check(mg_field(mg_parts(mg_req()), 'h:Message-Id') === array(), 'B15: a batch leaves out the pinned Message-Id');

// B21: the lookup a send makes before it starts is asked once.
mg_mock(array(mg_json(array('message' => 'down'), 503), mg_json(array('id' => '<a@b>', 'message' => 'ok'))));
$m = (new EmailMessage())->from('someone@other.example')->to('one@example.com')->subject('s')->text('t');
check((new MailgunProvider())->send($m) === true, 'B21: the send goes on after a failed lookup');
check(count($GLOBALS['mg_history']) === 2 && mg_req(0)->getMethod() === 'GET'
	&& (string)mg_req(1)->getUri() === 'https://api.mailgun.net/v3/mg.example.test/messages',
	'B21: the 503 lookup is not retried; the send falls back to the configured domain');

// T1: transport failures.
$timeout = function () {
	return new ConnectException('timed out', new Request('POST', 'https://api.mailgun.net/x'), null, array('errno' => 28));
};
mg_mock(array($timeout()));
check((new MailgunProvider())->send(mg_message()) === false && count($GLOBALS['mg_history']) === 1,
	'a timeout fails the send, never retried (Mailgun may have the message)');
$refused = function () {
	return new ConnectException('refused', new Request('POST', 'https://api.mailgun.net/x'), null, array('errno' => 7));
};
mg_mock(array($refused(), $refused(), $refused()));
check((new MailgunProvider())->send(mg_message()) === false && count($GLOBALS['mg_history']) === 3,
	'a refused connection is tried three times, then the send fails');
mg_mock(array($refused()));
$r = (new MailgunProvider())->relayRawMessage("From: a@b\r\n\r\nx", 'a@mg.example.test', array('x@example.com'));
check($r === array('x@example.com' => false), 'a transport failure fails a raw relay', json_encode($r));

// T3: send() chunks at 500, 201 is success, the EU base.
$m = (new EmailMessage())->from('sender@mg.example.test')->subject('s')->text('t');
$list = array();
for ($i = 0; $i < 501; $i++) { $list[] = array('email' => 's' . $i . '@example.com', 'name' => ''); }
$rp->setValue($m, $list);
mg_mock(array(new Response(201, array(), '{"id":"<1@x>","message":"ok"}'), new Response(201, array(), '{"id":"<2@x>","message":"ok"}')));
check((new MailgunProvider())->send($m) === true, 'a 201 counts as success');
check(count($GLOBALS['mg_history']) === 2
	&& count(explode(',', mg_field(mg_parts(mg_req(0)), 'to')[0] ?? '')) === 500
	&& count(explode(',', mg_field(mg_parts(mg_req(1)), 'to')[0] ?? '')) === 1,
	'send() puts 501 recipients in chunks of 500 and 1');

harness_set_setting_mem('mailgun_eu_api_link', 'https://api.eu.mailgun.net');
mg_mock(array(mg_json(array('id' => '<a@b>', 'message' => 'ok'))));
(new MailgunProvider())->send(mg_message());
check((string)mg_req()->getUri() === 'https://api.eu.mailgun.net/v3/mg.example.test/messages',
	'mailgun_eu_api_link replaces the base');
harness_set_setting_mem('mailgun_eu_api_link', 'https://api.mailgun.net');

harness_finish();

