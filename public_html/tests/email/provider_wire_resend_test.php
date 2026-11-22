<?php
/** @joinery-test
 * name: provider_wire_resend
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * ResendProvider on the wire (specs/email_provider_sdk_removal.md, WP2), over
 * ProviderHttp's mocked transport: what each call sends, and how each answer
 * is read. No network.
 *
 * Run: php tests/run.php --filter=provider_wire_resend
 *
 * @version 1.1 - no pinned Message-ID in batch entries (B15); the per-recipient path retries a 429 (B16);
 *   headers is an object (B20); quoted display names; a success must carry an id; transport failure,
 *   no 5xx retry, the empty-field check sees a real request (T1-T3)
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getComposerAutoloadPath());

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

$GLOBALS['rw_history'] = array();

function rw_mock(array $queue): void {
	$GLOBALS['rw_history'] = array();
	$stack = HandlerStack::create(new MockHandler($queue));
	$stack->push(Middleware::history($GLOBALS['rw_history']));
	ProviderHttp::useTestHandler($stack);
}
function rw_req(int $i = 0) { return $GLOBALS['rw_history'][$i]['request'] ?? null; }
function rw_body(int $i = 0) { $r = rw_req($i); return $r ? json_decode((string)$r->getBody(), true) : null; }
function rw_json(array $body, int $status = 200): Response {
	return new Response($status, array('Content-Type' => 'application/json'), json_encode($body));
}
function rw_message(): EmailMessage {
	return (new EmailMessage())
		->from('sender@example.com', 'Sender Name')
		->to('one@example.com', 'One')
		->cc('cc@example.com')
		->bcc('bcc@example.com')
		->replyTo('reply@example.com')
		->subject('Hello')
		->html('<p>Hi <img src="cid:logo1"></p>')
		->text('Hi')
		->header('X-Thing', 'v1')
		->attachInlineData('PNGBYTES', 'logo1', 'logo.png', 'image/png');
}

harness_set_setting_mem('resend_api_key', 're_TESTKEY_abcdefghijklmnop');
ProviderHttp::useTestSleep(function ($s) {});
harness_defer(function () { ProviderHttp::useTestHandler(null); ProviderHttp::useTestSleep(null); });
$orig_log = ini_get('error_log');
ini_set('error_log', '/dev/null');
harness_defer(function () use ($orig_log) { ini_set('error_log', $orig_log); });

section('send(): URL, method, credential and body');

$m = rw_message();
$m->messageId('<pinned-1@example.com>');
rw_mock(array(rw_json(array('id' => 'abc'))));
check((new ResendProvider())->send($m) === true, 'a 200 is a successful send');
$req = rw_req();
check($req->getMethod() === 'POST' && (string)$req->getUri() === 'https://api.resend.com/emails', 'POST https://api.resend.com/emails');
check($req->getHeaderLine('Authorization') === 'Bearer re_TESTKEY_abcdefghijklmnop', 'the key is a Bearer token');
$b = rw_body();
check(($b['from'] ?? '') === 'Sender Name <sender@example.com>', 'from is "Name <email>"');
check(($b['to'] ?? null) === array('One <one@example.com>'), 'to is a list of "Name <email>"');
check(($b['cc'] ?? null) === array('cc@example.com') && ($b['bcc'] ?? null) === array('bcc@example.com'),
	'cc and bcc without a name are bare addresses');
check(($b['subject'] ?? '') === 'Hello' && ($b['html'] ?? '') !== '' && ($b['text'] ?? '') === 'Hi', 'subject, html, text');
check(($b['reply_to'] ?? '') === 'reply@example.com', 'reply_to');
check(($b['headers']['X-Thing'] ?? '') === 'v1', 'headers are a name => value map');
check(($b['headers']['Message-ID'] ?? '') === '<pinned-1@example.com>', 'the pinned Message-ID is sent (B1)');
$att = $b['attachments'][0] ?? array();
check(($att['filename'] ?? '') === 'logo.png' && ($att['content'] ?? '') === base64_encode('PNGBYTES') && !isset($att['cid']),
	'an inline part goes as a regular attachment (no Content-ID field)');

$plain = (new EmailMessage())->from('a@example.com')->to('b@example.com')->subject('s')->text('t');
rw_mock(array(rw_json(array('id' => 'x'))));
(new ResendProvider())->send($plain);
$b = rw_body();
check(is_array($b) && ($b['to'] ?? null) === array('b@example.com'), 'the plain send was made and its body decodes');
check(is_array($b) && !isset($b['html']) && !isset($b['headers']) && !isset($b['cc']) && !isset($b['attachments']) && !isset($b['reply_to']),
	'empty optional fields are left out, not sent as ""');

rw_mock(array(rw_json(array('name' => 'validation_error', 'message' => 'bad from'), 422)));
check((new ResendProvider())->send($plain) === false, 'a 422 is a failed send');

rw_mock(array(new Response(200, array(), 'not json')));
check((new ResendProvider())->send($plain) === false, 'a 200 whose body does not parse is not a send');
rw_mock(array(rw_json(array())));
check((new ResendProvider())->send($plain) === false, 'a 200 without an id is not a send');

rw_mock(array(rw_json(array('id' => 'x'))));
$named = (new EmailMessage())->from('a@example.com', 'Smith, Jo')->to('b@example.com', 'Doe "JD"')->subject('s')->text('t');
(new ResendProvider())->send($named);
$b = rw_body();
check(($b['from'] ?? '') === '"Smith, Jo" <a@example.com>' && ($b['to'] ?? null) === array('"Doe \\"JD\\"" <b@example.com>'),
	'display names with a comma or quotes are quoted per RFC 5322', json_encode($b));

$zero = (new EmailMessage())->from('a@example.com')->to('b@example.com')->subject('s')->text('t');
$hp = new ReflectionProperty('EmailMessage', 'headers');
$hp->setValue($zero, array('0' => 'z'));
rw_mock(array(rw_json(array('id' => 'x'))));
(new ResendProvider())->send($zero);
check(strpos((string)rw_req()->getBody(), '"headers":{"0":"z"}') !== false, 'a header map is always a JSON object (B20)');

rw_mock(array(new \GuzzleHttp\Exception\ConnectException('down', new \GuzzleHttp\Psr7\Request('POST', 'https://api.resend.com/emails'), null, array('errno' => 28))));
check((new ResendProvider())->send($plain) === false, 'a transport failure (ProviderHttpException) is a failed send');
rw_mock(array(rw_json(array('message' => 'x'), 503), rw_json(array('id' => 'x'))));
check((new ResendProvider())->send($plain) === false && count($GLOBALS['rw_history']) === 1,
	'a 5xx on a send is not retried (Resend may have taken it)');

section('An attachment that cannot be read fails the send (B6)');

$bad = (new EmailMessage())->from('a@example.com')->to('b@example.com')->subject('s')->text('t');
$ref = new ReflectionProperty('EmailMessage', 'attachments');
$ref->setValue($bad, array(array('path' => '/nonexistent/x.pdf', 'name' => 'x.pdf')));
rw_mock(array(rw_json(array('id' => 'x'))));
check((new ResendProvider())->send($bad) === false && count($GLOBALS['rw_history']) === 0, 'send() fails and sends nothing');
rw_mock(array(rw_json(array())));
$r = (new ResendProvider())->sendBatch($bad, array('a@x.example', 'b@x.example'));
check($r['success'] === false && $r['failed_recipients'] === array('a@x.example', 'b@x.example'), 'sendBatch() fails every recipient');

section('sendBatch(): chunks of 100, a chunk fails as a whole');

$recips = array();
for ($i = 0; $i < 150; $i++) { $recips[] = 'r' . $i . '@example.com'; }
$bm = (new EmailMessage())->from('a@example.com')->subject('s')->text('t')->header('X-Thing', 'v')->replyTo('rt@example.com')
	->messageId('<pinned-b@example.com>');
rw_mock(array(rw_json(array('data' => array_fill(0, 100, array('id' => 'e')))), rw_json(array('name' => 'rate_limit_exceeded', 'message' => 'slow'), 400)));
$r = (new ResendProvider())->sendBatch($bm, $recips);
check(count($GLOBALS['rw_history']) === 2, 'two requests for 150 recipients');
check((string)rw_req(0)->getUri() === 'https://api.resend.com/emails/batch', 'POST /emails/batch');
$b0 = rw_body(0);
check(count($b0) === 100 && ($b0[0]['to'] ?? null) === array('r0@example.com'), 'first chunk is 100 entries, one recipient each');
check(($b0[0]['headers']['X-Thing'] ?? '') === 'v' && ($b0[0]['reply_to'] ?? '') === 'rt@example.com',
	'batch entries carry headers and reply_to, like a single send (B2)');
check(!isset($b0[0]['headers']['Message-ID']), 'batch entries never carry the pinned Message-ID (B15)');
check($r['success'] === false && count($r['failed_recipients']) === 50 && $r['failed_recipients'][0] === 'r100@example.com',
	'the failed chunk\'s 50 recipients are reported');

$am = (new EmailMessage())->from('a@example.com')->subject('s')->text('t')->attachData('B', 'b.txt', 'text/plain');
rw_mock(array(rw_json(array('id' => '1')), rw_json(array('name' => 'rate_limit_exceeded'), 429), rw_json(array('message' => 'no'), 500)));
$r = (new ResendProvider())->sendBatch($am, array('x@example.com', 'y@example.com'));
check((string)rw_req(0)->getUri() === 'https://api.resend.com/emails' && count($GLOBALS['rw_history']) === 3,
	'with attachments (which /emails/batch refuses), one /emails per recipient; a 429 there is retried (B16)');
check(isset(rw_body(0)['attachments']) && $r['failed_recipients'] === array('y@example.com'),
	'each keeps its attachments, and only the refused recipient fails');

section('validateApiConnection(): decided by the error name (B7)');

rw_mock(array(rw_json(array('data' => array(array('id' => 1), array('id' => 2))))));
$v = ResendProvider::validateApiConnection();
check((string)rw_req()->getUri() === 'https://api.resend.com/api-keys' && rw_req()->getMethod() === 'GET', 'GET /api-keys');
check($v['success'] && $v['label'] === 'API Key Valid' && $v['details']['API Keys on Account'] === 2, 'a full key is valid, with its key count');

rw_mock(array(rw_json(array('statusCode' => 401, 'name' => 'restricted_api_key',
	'message' => 'This API key is restricted to only send emails'), 401)));
$v = ResendProvider::validateApiConnection();
check($v['success'] && $v['label'] === 'API Key Valid (Restricted)', 'a send-only key (401 restricted_api_key, message says "API key") is valid, restricted');

rw_mock(array(rw_json(array('statusCode' => 403, 'name' => 'invalid_api_key', 'message' => 'API key is invalid'), 403)));
$v = ResendProvider::validateApiConnection();
check(!$v['success'] && $v['label'] === 'API Key Rejected', '403 invalid_api_key is rejected');

rw_mock(array(rw_json(array('statusCode' => 401, 'name' => 'missing_api_key', 'message' => 'Missing API key'), 401)));
check(ResendProvider::validateApiConnection()['label'] === 'API Key Rejected', '401 missing_api_key is rejected');

rw_mock(array(rw_json(array('name' => 'internal_server_error', 'message' => 'oops'), 500)));
$v = ResendProvider::validateApiConnection();
check(!$v['success'] && $v['label'] === 'API Connection Failed' && $v['error'] === 'Resend answered 500: oops',
	'anything else shows Resend\'s own message with the status');

section('getSpfMechanism(): domains list, then the domain\'s records');

rw_mock(array(
	rw_json(array('data' => array(array('id' => 'd-other', 'name' => 'other.example'), array('id' => 'd1', 'name' => 'mail.example')))),
	rw_json(array('id' => 'd1', 'records' => array(
		array('record' => 'DKIM', 'type' => 'TXT', 'value' => 'p=x'),
		array('record' => 'SPF', 'type' => 'TXT', 'value' => 'v=spf1 include:amazonses.com ~all')))),
));
check(ResendProvider::getSpfMechanism('mail.example') === 'include:amazonses.com', 'the SPF TXT value minus v=spf1 and all');
check((string)rw_req(1)->getUri() === 'https://api.resend.com/domains/d1', 'the matching domain\'s detail is read');
rw_mock(array());
check(ResendProvider::getSpfMechanism('mail.example') === 'include:amazonses.com' && count($GLOBALS['rw_history']) === 0,
	'the answer is cached for the request');
rw_mock(array(rw_json(array('name' => 'invalid_api_key'), 403)));
check(ResendProvider::getSpfMechanism('nothere.example') === '', 'a refused list gives no mechanism');

harness_finish();
