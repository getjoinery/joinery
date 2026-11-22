<?php
/** @joinery-test
 * name: provider_wire_brevo
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * BrevoProvider on the wire (specs/email_provider_sdk_removal.md, WP2),
 * over a mocked ProviderHttp transport: the request it sends for validation,
 * a single send and a batch, and how it reads Brevo's answers.
 *
 * Run: php tests/run.php --filter=provider_wire_brevo
 *
 * @version 1.1 - a success must name the message; headers is an object (B20); a non-JSON 201 fails;
 *   transport failure, batch unreadable attachment, the empty-field check sees a real request (T1-T3)
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getComposerAutoloadPath());

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

$GLOBALS['pw_history'] = array();

function pw_mock(array $queue): void {
	$GLOBALS['pw_history'] = array();
	$stack = HandlerStack::create(new MockHandler($queue));
	$stack->push(Middleware::history($GLOBALS['pw_history']));
	ProviderHttp::useTestHandler($stack);
}
function pw_req(int $i = 0) { return $GLOBALS['pw_history'][$i]['request'] ?? null; }
function pw_body(int $i = 0) { $r = pw_req($i); return $r ? json_decode((string)$r->getBody(), true) : null; }
function pw_json($body, int $status = 200): Response {
	return new Response($status, array('Content-Type' => 'application/json'), json_encode($body));
}
/** A message carrying an attachment whose file is gone. */
function pw_with_missing_file(EmailMessage $m): EmailMessage {
	$ref = new ReflectionProperty('EmailMessage', 'attachments');
	$atts = $ref->getValue($m);
	$atts[] = array('path' => '/nonexistent/' . uniqid() . '.pdf', 'name' => 'gone.pdf');
	$ref->setValue($m, $atts);
	return $m;
}

ProviderHttp::useTestSleep(function ($s) {});
harness_defer(function () { ProviderHttp::useTestHandler(null); ProviderHttp::useTestSleep(null); });
harness_set_setting_mem('brevo_api_key', 'xkeysib-TESTKEY');
harness_set_setting_mem('brevo_sandbox_mode', '0');

function pw_message(): EmailMessage {
	return (new EmailMessage())
		->from('sender@example.com', 'Sender Name')
		->to('one@example.com', 'One')
		->cc('cc@example.com')
		->bcc('bcc@example.com', 'Bee')
		->replyTo('reply@example.com')
		->subject('Hello')
		->html('<p>Hi</p>')
		->text('Hi')
		->header('X-Custom', 'abc')
		->messageId('<pinned.1@example.com>')
		->attachData('PDFBYTES', 'r.pdf', 'application/pdf')
		->attachInlineData('PNGBYTES', 'logo1', 'logo.png', 'image/png');
}

section('Validation');

pw_mock(array(pw_json(array('email' => 'acct@example.com', 'companyName' => 'Co',
	'plan' => array(array('type' => 'free', 'credits' => 300))))));
$v = BrevoProvider::validateApiConnection();
$r = pw_req();
check($r && $r->getMethod() === 'GET' && (string)$r->getUri() === 'https://api.brevo.com/v3/account', 'GET /v3/account');
check($r && $r->getHeaderLine('api-key') === 'xkeysib-TESTKEY', 'the key travels in the api-key header');
check($v['success'] && $v['details']['Account Email'] === 'acct@example.com' && $v['details']['Company Name'] === 'Co'
	&& $v['details']['Plan'] === 'free' && $v['details']['Credits'] === 300, 'account email, company, and plan[0] type and credits');

pw_mock(array(pw_json(array('code' => 'unauthorized', 'message' => 'Key not found'), 401)));
$v = BrevoProvider::validateApiConnection();
check(!$v['success'] && $v['label'] === 'API Key Rejected', 'a 401 is API Key Rejected');
pw_mock(array(pw_json(array('code' => 'permission_denied', 'message' => 'no'), 403)));
$v = BrevoProvider::validateApiConnection();
check(!$v['success'] && $v['label'] === 'Access Denied', 'a 403 is Access Denied');
pw_mock(array(pw_json(array('code' => 'bad_request', 'message' => 'Odd'), 400)));
$v = BrevoProvider::validateApiConnection();
check(!$v['success'] && $v['error'] === 'Brevo error 400: Odd', 'anything else shows Brevo\'s own message', $v['error']);

section('Single send');

pw_mock(array(pw_json(array('messageId' => '<x@relay>'), 201)));
$ok = (new BrevoProvider())->send(pw_message());
$r = pw_req();
$b = pw_body();
check($ok === true, 'a 201 is a sent message');
check($r && $r->getMethod() === 'POST' && (string)$r->getUri() === 'https://api.brevo.com/v3/smtp/email', 'POST /v3/smtp/email');
check(($b['sender'] ?? null) === array('email' => 'sender@example.com', 'name' => 'Sender Name'), 'sender {email, name}');
check(($b['to'] ?? null) === array(array('email' => 'one@example.com', 'name' => 'One')), 'to [{email, name}]');
check(($b['cc'] ?? null) === array(array('email' => 'cc@example.com')), 'an empty name is left out');
check(($b['bcc'] ?? null) === array(array('email' => 'bcc@example.com', 'name' => 'Bee')), 'bcc');
check(($b['subject'] ?? '') === 'Hello' && ($b['htmlContent'] ?? '') === '<p>Hi</p>' && ($b['textContent'] ?? '') === 'Hi', 'subject, htmlContent, textContent');
check(($b['replyTo'] ?? null) === array('email' => 'reply@example.com'), 'replyTo {email}');
check(($b['headers'] ?? null) === array('X-Custom' => 'abc'), 'headers are a map');
check(!isset($b['headers']['Message-ID']) && !isset($b['headers']['Message-Id']), 'the pinned Message-ID is not sent: Brevo refuses standard headers (B1)');
$atts = $b['attachment'] ?? array();
check(count($atts) === 2 && $atts[0] === array('name' => 'r.pdf', 'content' => base64_encode('PDFBYTES')), 'attachment [{name, content}]');
check(($atts[1]['name'] ?? '') === 'logo.png' && !isset($atts[1]['cid']), 'an inline part goes as a regular attachment');

harness_set_setting_mem('brevo_sandbox_mode', '1');
pw_mock(array(pw_json(array('messageId' => '<q@relay>'), 201)));
(new BrevoProvider())->send(pw_message());
check((pw_body()['headers']['X-Sib-Sandbox'] ?? '') === 'drop', 'sandbox mode adds X-Sib-Sandbox: drop');
harness_set_setting_mem('brevo_sandbox_mode', '0');

$plain = (new EmailMessage())->from('s@example.com')->to('t@example.com')->subject('s')->text('t');
pw_mock(array(pw_json(array('messageId' => '<q@relay>'), 201)));
(new BrevoProvider())->send($plain);
$b = pw_body();
check(is_array($b) && ($b['to'] ?? null) === array(array('email' => 't@example.com')), 'the plain send was made and its body decodes');
check(is_array($b) && !isset($b['headers']) && !isset($b['cc']) && !isset($b['replyTo']) && !isset($b['htmlContent']) && !isset($b['attachment'])
	&& !isset($b['sender']['name']), 'empty optional fields are left out, not sent as ""');

pw_mock(array(pw_json(array('message' => 'bad'), 400)));
check((new BrevoProvider())->send(pw_message()) === false, 'a 400 is a failed send');

pw_mock(array(new Response(201, array(), 'not json')));
check((new BrevoProvider())->send($plain) === false, 'a 201 whose body does not parse is not a send');
pw_mock(array(pw_json(array(), 201)));
check((new BrevoProvider())->send($plain) === false, 'a 201 that names no message is not a send');

$zero = (new EmailMessage())->from('s@example.com')->to('t@example.com')->subject('s')->text('t');
$hp = new ReflectionProperty('EmailMessage', 'headers');
$hp->setValue($zero, array('0' => 'z'));
pw_mock(array(pw_json(array('messageId' => '<q@relay>'), 201)));
(new BrevoProvider())->send($zero);
check(strpos((string)pw_req()->getBody(), '"headers":{"0":"z"}') !== false, 'a header map is always a JSON object, even with a name "0" (B20)');

pw_mock(array_fill(0, 3, new \GuzzleHttp\Exception\ConnectException('down', new \GuzzleHttp\Psr7\Request('POST', 'https://api.brevo.com/v3/smtp/email'), null, array('errno' => 7))));
check((new BrevoProvider())->send($plain) === false && count($GLOBALS['pw_history']) === 3,
	'a transport failure before Brevo read the request is retried twice, then fails the send');

section('Retries');

pw_mock(array(pw_json(array(), 429), pw_json(array('messageId' => '<q@relay>'), 201)));
check((new BrevoProvider())->send(pw_message()) === true && count($GLOBALS['pw_history']) === 2, 'a 429 is retried');
pw_mock(array(pw_json(array(), 503), pw_json(array('messageId' => '<q@relay>'), 201)));
check((new BrevoProvider())->send(pw_message()) === false && count($GLOBALS['pw_history']) === 1, 'a 5xx is never retried (it could deliver twice)');

section('Unreadable attachment');

pw_mock(array(pw_json(array('messageId' => '<q@relay>'), 201)));
check((new BrevoProvider())->send(pw_with_missing_file(pw_message())) === false && count($GLOBALS['pw_history']) === 0,
	'an attachment that cannot be read fails the send and nothing is sent (B6)');
pw_mock(array(pw_json(array('messageIds' => array('<a@relay>')), 201)));
$res = (new BrevoProvider())->sendBatch(pw_with_missing_file(pw_message()), array('a@example.com', 'b@example.com'));
check($res['success'] === false && $res['failed_recipients'] === array('a@example.com', 'b@example.com') && count($GLOBALS['pw_history']) === 0,
	'a batch with an unreadable attachment fails every recipient and sends nothing');

section('Batch');

$recipients = array();
for ($i = 0; $i < 1001; $i++) { $recipients[] = 'r' . $i . '@example.com'; }
pw_mock(array(pw_json(array('messageIds' => array('<a@relay>')), 201), pw_json(array('message' => 'bad'), 400)));
$res = (new BrevoProvider())->sendBatch(pw_message(), $recipients);
check(count($GLOBALS['pw_history']) === 2, '1001 recipients go in two calls (chunks of 1000)');
$b = pw_body();
check(($b['to'] ?? null) === array(array('email' => 'r0@example.com')), 'to is the chunk\'s first recipient, as Brevo requires');
check(count($b['messageVersions'] ?? array()) === 1000 && ($b['messageVersions'][999]['to'] ?? null) === array(array('email' => 'r999@example.com')),
	'one messageVersion per recipient');
check(($b['replyTo'] ?? null) === array('email' => 'reply@example.com') && count($b['attachment'] ?? array()) === 2 && isset($b['headers']['X-Custom']),
	'a batch carries Reply-To, headers and attachments like a single send (B2)');
check($res['failed_recipients'] === array('r1000@example.com') && $res['success'] === false, 'a refused chunk fails as a whole');

harness_finish();
