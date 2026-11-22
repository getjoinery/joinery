<?php
/** @joinery-test
 * name: provider_wire_sendgrid
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * SendGridProvider on the wire (specs/email_provider_sdk_removal.md, WP2),
 * over ProviderHttp's mocked transport: what each call sends, and how each
 * answer is read. No network.
 *
 * Run: php tests/run.php --filter=provider_wire_sendgrid
 *
 * @version 1.1 - a send-only key validates as restricted through /v3/scopes; no pinned Message-ID in
 *   batch (B15); headers is an object (B20); transport failure, no 5xx retry, the empty-field check sees
 *   a real request (T1-T3)
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getComposerAutoloadPath());

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

$GLOBALS['sw_history'] = array();

function sw_mock(array $queue): void {
	$GLOBALS['sw_history'] = array();
	$stack = HandlerStack::create(new MockHandler($queue));
	$stack->push(Middleware::history($GLOBALS['sw_history']));
	ProviderHttp::useTestHandler($stack);
}
function sw_req(int $i = 0) { return $GLOBALS['sw_history'][$i]['request'] ?? null; }
function sw_body(int $i = 0) { $r = sw_req($i); return $r ? json_decode((string)$r->getBody(), true) : null; }
function sw_json(array $body, int $status = 200): Response {
	return new Response($status, array('Content-Type' => 'application/json'), json_encode($body));
}

harness_set_setting_mem('sendgrid_api_key', 'SG.TESTKEYabcdefghijklmn.TESTSECRETabcdefghijkl');
harness_set_setting_mem('sendgrid_region', 'global');
harness_set_setting_mem('sendgrid_sandbox_mode', '0');
harness_set_setting_mem('sendgrid_click_tracking', '0');
ProviderHttp::useTestSleep(function ($s) {});
harness_defer(function () { ProviderHttp::useTestHandler(null); ProviderHttp::useTestSleep(null); });
$orig_log = ini_get('error_log');
ini_set('error_log', '/dev/null');
harness_defer(function () use ($orig_log) { ini_set('error_log', $orig_log); });

section('send(): URL, method, credential and body');

$m = (new EmailMessage())
	->from('sender@example.com', 'Sender Name')
	->to('one@example.com', 'One')
	->to('two@example.com')
	->cc('cc@example.com', 'Cee')
	->bcc('bcc@example.com')
	->replyTo('reply@example.com')
	->subject('Hello')
	->html('<p>Hi <img src="cid:logo1"></p>')
	->text('Hi')
	->header('X-Thing', 'v1')
	->messageId('<pinned-2@example.com>')
	->attachInlineData('PNGBYTES', 'logo1', 'logo.png', 'image/png')
	->attachData('PDFBYTES', 'r.pdf', 'application/pdf');
sw_mock(array(new Response(202)));
check((new SendGridProvider())->send($m) === true, 'a 202 is a successful send');
$req = sw_req();
check($req->getMethod() === 'POST' && (string)$req->getUri() === 'https://api.sendgrid.com/v3/mail/send', 'POST https://api.sendgrid.com/v3/mail/send');
check($req->getHeaderLine('Authorization') === 'Bearer SG.TESTKEYabcdefghijklmn.TESTSECRETabcdefghijkl', 'the key is a Bearer token');
$b = sw_body();
$p = $b['personalizations'] ?? array();
check(count($p) === 2 && ($p[0]['to'] ?? null) === array(array('email' => 'one@example.com', 'name' => 'One')),
	'one personalization per To recipient');
check(($p[1]['to'] ?? null) === array(array('email' => 'two@example.com')), 'an empty name is left out, not sent as ""');
check(($p[0]['cc'] ?? null) === array(array('email' => 'cc@example.com', 'name' => 'Cee'))
	&& ($p[0]['bcc'] ?? null) === array(array('email' => 'bcc@example.com')) && !isset($p[1]['cc']),
	'Cc and Bcc ride on the first personalization only');
check(($b['from'] ?? null) === array('email' => 'sender@example.com', 'name' => 'Sender Name'), 'from {email, name}');
check(($b['reply_to'] ?? null) === array('email' => 'reply@example.com'), 'reply_to {email}');
check(array_column($b['content'] ?? array(), 'type') === array('text/plain', 'text/html'), 'text/plain comes before text/html');
check(($b['headers']['X-Thing'] ?? '') === 'v1', 'headers are a name => value map');
check(($b['headers']['Message-ID'] ?? '') === '<pinned-2@example.com>', 'the pinned Message-ID is sent (B1)');
$atts = $b['attachments'] ?? array();
check(($atts[0]['disposition'] ?? '') === 'inline' && ($atts[0]['content_id'] ?? '') === 'logo1'
	&& ($atts[0]['content'] ?? '') === base64_encode('PNGBYTES') && ($atts[0]['type'] ?? '') === 'image/png',
	'an inline part has disposition inline and its content_id');
check(($atts[1]['disposition'] ?? '') === 'attachment' && !isset($atts[1]['content_id']) && ($atts[1]['filename'] ?? '') === 'r.pdf',
	'a regular attachment has disposition attachment');
check(($b['tracking_settings']['click_tracking']['enable'] ?? null) === false, 'click tracking is stated even when off');
check(!isset($b['mail_settings']), 'no sandbox setting when sandbox mode is off');

harness_set_setting_mem('sendgrid_sandbox_mode', '1');
harness_set_setting_mem('sendgrid_click_tracking', '1');
$plain = (new EmailMessage())->from('a@example.com')->to('b@example.com')->subject('s')->text('t');
sw_mock(array(new Response(202)));
(new SendGridProvider())->send($plain);
$b = sw_body();
check(is_array($b) && ($b['personalizations'][0]['to'] ?? null) === array(array('email' => 'b@example.com')),
	'the plain send was made and its body decodes');
check(($b['mail_settings']['sandbox_mode']['enable'] ?? null) === true, 'sandbox mode on sets mail_settings.sandbox_mode.enable');
check(($b['tracking_settings']['click_tracking']['enable'] ?? null) === true, 'click tracking on');
check(!isset($b['from']['name']) && !isset($b['headers']) && !isset($b['reply_to']) && !isset($b['attachments']),
	'empty optional fields are left out');
harness_set_setting_mem('sendgrid_sandbox_mode', '0');

sw_mock(array(sw_json(array('errors' => array(array('message' => 'bad from'))), 400)));
check((new SendGridProvider())->send($plain) === false, 'a 400 is a failed send');

$zero = (new EmailMessage())->from('a@example.com')->to('b@example.com')->subject('s')->text('t');
$hp = new ReflectionProperty('EmailMessage', 'headers');
$hp->setValue($zero, array('0' => 'z'));
sw_mock(array(new Response(202)));
(new SendGridProvider())->send($zero);
check(strpos((string)sw_req()->getBody(), '"headers":{"0":"z"}') !== false, 'a header map is always a JSON object (B20)');

sw_mock(array(new \GuzzleHttp\Exception\ConnectException('down', new \GuzzleHttp\Psr7\Request('POST', 'https://api.sendgrid.com/v3/mail/send'), null, array('errno' => 28))));
check((new SendGridProvider())->send($plain) === false, 'a transport failure (ProviderHttpException) is a failed send');
sw_mock(array(sw_json(array('errors' => array(array('message' => 'x'))), 503), new Response(202)));
check((new SendGridProvider())->send($plain) === false && count($GLOBALS['sw_history']) === 1,
	'a 5xx on a send is not retried (SendGrid may have taken it)');

section('EU region');

harness_set_setting_mem('sendgrid_region', 'eu');
sw_mock(array(new Response(202)));
(new SendGridProvider())->send($plain);
check((string)sw_req()->getUri() === 'https://api.eu.sendgrid.com/v3/mail/send', 'eu region sends to api.eu.sendgrid.com');
harness_set_setting_mem('sendgrid_region', 'global');

section('An attachment that cannot be read fails the send (B6)');

$bad = (new EmailMessage())->from('a@example.com')->to('b@example.com')->subject('s')->text('t');
$ref = new ReflectionProperty('EmailMessage', 'attachments');
$ref->setValue($bad, array(array('path' => '/nonexistent/x.pdf', 'name' => 'x.pdf')));
sw_mock(array(new Response(202)));
check((new SendGridProvider())->send($bad) === false && count($GLOBALS['sw_history']) === 0, 'send() fails and sends nothing');
$r = (new SendGridProvider())->sendBatch($bad, array('a@x.example'));
check($r['success'] === false && $r['failed_recipients'] === array('a@x.example'), 'sendBatch() fails every recipient');

section('sendBatch(): chunks of 1000, a chunk fails as a whole');

$recips = array();
for ($i = 0; $i < 1500; $i++) { $recips[] = 'r' . $i . '@example.com'; }
$bm = (new EmailMessage())->from('a@example.com')->subject('s')->text('t')->header('X-Thing', 'v')->replyTo('rt@example.com')
	->attachData('B', 'b.txt', 'text/plain')->messageId('<pinned-b@example.com>');
sw_mock(array(new Response(202), sw_json(array('errors' => array(array('message' => 'nope'))), 500)));
$r = (new SendGridProvider())->sendBatch($bm, $recips);
check(count($GLOBALS['sw_history']) === 2, 'two requests for 1500 recipients');
$b0 = sw_body(0);
check(count($b0['personalizations']) === 1000
	&& $b0['personalizations'][0]['to'] === array(array('email' => 'r0@example.com', 'name' => 'r0@example.com')),
	'one personalization each, the name set to the address');
check(($b0['headers']['X-Thing'] ?? '') === 'v' && ($b0['reply_to']['email'] ?? '') === 'rt@example.com' && !empty($b0['attachments']),
	'a batch carries headers, reply_to and attachments like a single send (B2)');
check(!isset($b0['headers']['Message-ID']), 'a batch never carries the pinned Message-ID (B15)');
check($r['success'] === false && count($r['failed_recipients']) === 500 && $r['failed_recipients'][0] === 'r1000@example.com',
	'the failed chunk\'s 500 recipients are reported');

section('validateApiConnection()');

sw_mock(array(sw_json(array('type' => 'paid', 'reputation' => 99))));
$v = SendGridProvider::validateApiConnection();
check(sw_req()->getMethod() === 'GET' && (string)sw_req()->getUri() === 'https://api.sendgrid.com/v3/user/account', 'GET /v3/user/account');
check($v['success'] && $v['label'] === 'API Key Valid' && $v['details']['Account Type'] === 'paid', 'a 200 is valid, with account type');

sw_mock(array(sw_json(array('errors' => array(array('message' => 'authorization required'))), 401)));
$v = SendGridProvider::validateApiConnection();
check(!$v['success'] && $v['label'] === 'API Key Rejected' && strpos($v['error'], 'EU-region') !== false,
	'401 is rejected, with the EU-region hint');

sw_mock(array(sw_json(array('errors' => array(array('message' => 'access forbidden'))), 403),
	sw_json(array('scopes' => array('mail.send', 'sender_verification_eligible')))));
$v = SendGridProvider::validateApiConnection();
check((string)sw_req(1)->getUri() === 'https://api.sendgrid.com/v3/scopes', 'a 403 on the account asks the key\'s own scopes');
check($v['success'] && $v['label'] === 'API Key Valid (Restricted)', 'a send-only key is valid, restricted', json_encode($v));

sw_mock(array(sw_json(array('errors' => array(array('message' => 'access forbidden'))), 403),
	sw_json(array('scopes' => array('stats.read')))));
$v = SendGridProvider::validateApiConnection();
check(!$v['success'] && $v['label'] === 'API Key Rejected' && strpos($v['error'], 'Mail Send') !== false,
	'a key without mail.send is rejected, naming the permission');

sw_mock(array(new \GuzzleHttp\Exception\ConnectException('down', new \GuzzleHttp\Psr7\Request('GET', 'https://api.sendgrid.com/v3/user/account'), null, array('errno' => 7))));
$v = SendGridProvider::validateApiConnection();
check(!$v['success'] && $v['label'] === 'API Connection Failed', 'a transport failure on validation reads as API Connection Failed');

sw_mock(array(sw_json(array('errors' => array(array('message' => 'oops'))), 500)));
$v = SendGridProvider::validateApiConnection();
check($v['label'] === 'API Connection Failed' && $v['error'] === 'SendGrid answered 500: oops', 'anything else shows SendGrid\'s own message');

harness_finish();
