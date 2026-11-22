<?php
/** @joinery-test
 * name: provider_wire_mailjet
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * MailjetProvider on the wire (specs/email_provider_sdk_removal.md, WP2),
 * over a mocked ProviderHttp transport: the request it sends for validation,
 * a single send and a batch, and how it reads Mailjet's answers.
 *
 * Run: php tests/run.php --filter=provider_wire_mailjet
 *
 * @version 1.1 - batch with attachments goes per recipient (B12); Headers is an object (B20); a non-JSON
 *   200 fails; transport failure, no 5xx retry, batch unreadable attachment, the empty-field check sees
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
harness_set_setting_mem('mailjet_api_key', 'MJKEY');
harness_set_setting_mem('mailjet_api_secret', 'MJSECRET');
harness_set_setting_mem('mailjet_sandbox_mode', '0');

function pw_message(): EmailMessage {
	return (new EmailMessage())
		->from('sender@example.com', 'Sender Name')
		->to('one@example.com', 'One')
		->cc('cc@example.com')
		->bcc('bcc@example.com', 'Bee')
		->replyTo('reply@example.com')
		->subject('Hello')
		->html('<p>Hi <img src="cid:logo1"></p>')
		->text('Hi')
		->header('X-Custom', 'abc')
		->messageId('<pinned.1@example.com>')
		->attachData('PDFBYTES', 'r.pdf', 'application/pdf')
		->attachInlineData('PNGBYTES', 'logo1', 'logo.png', 'image/png');
}
$basic = 'Basic ' . base64_encode('MJKEY:MJSECRET');

section('Validation');

pw_mock(array(pw_json(array('Count' => 1, 'Data' => array(array('Email' => 'acct@example.com', 'CompanyName' => 'Co', 'Country' => 'FR')), 'Total' => 1))));
$v = MailjetProvider::validateApiConnection();
$r = pw_req();
check($r && $r->getMethod() === 'GET' && (string)$r->getUri() === 'https://api.mailjet.com/v3/REST/myprofile', 'GET /v3/REST/myprofile');
check($r && $r->getHeaderLine('Authorization') === $basic, 'key and secret travel as HTTP basic auth');
check($v['success'] && $v['label'] === 'API Credentials Valid' && $v['details']['Account Email'] === 'acct@example.com'
	&& $v['details']['Company Name'] === 'Co' && $v['details']['Country'] === 'FR', 'the profile is read from Data[0]');

pw_mock(array(new Response(401, array(), '')));
$v = MailjetProvider::validateApiConnection();
check(!$v['success'] && $v['label'] === 'Credentials Rejected', 'a 401 is Credentials Rejected');
pw_mock(array(new Response(403, array(), '')));
$v = MailjetProvider::validateApiConnection();
check(!$v['success'] && $v['label'] === 'Access Denied', 'a 403 is Access Denied');
pw_mock(array(pw_json(array('ErrorMessage' => 'Odd'), 400)));
$v = MailjetProvider::validateApiConnection();
check(!$v['success'] && $v['error'] === 'HTTP 400: Odd', 'anything else shows Mailjet\'s own message', $v['error']);

section('Single send');

pw_mock(array(pw_json(array('Messages' => array(array('Status' => 'success'))))));
$ok = (new MailjetProvider())->send(pw_message());
$r = pw_req();
$b = pw_body();
$m = $b['Messages'][0] ?? array();
check($ok === true, 'a 200 is a sent message');
check($r && $r->getMethod() === 'POST' && (string)$r->getUri() === 'https://api.mailjet.com/v3.1/send', 'POST /v3.1/send (no REST segment)');
check($r && $r->getHeaderLine('Authorization') === $basic, 'basic auth on the send');
check(!isset($b['SandboxMode']), 'no SandboxMode unless the setting is on');
check(($m['From'] ?? null) === array('Email' => 'sender@example.com', 'Name' => 'Sender Name'), 'From {Email, Name}');
check(($m['To'] ?? null) === array(array('Email' => 'one@example.com', 'Name' => 'One')), 'To [{Email, Name}]');
check(($m['Cc'] ?? null) === array(array('Email' => 'cc@example.com')) && ($m['Bcc'] ?? null) === array(array('Email' => 'bcc@example.com', 'Name' => 'Bee')),
	'Cc and Bcc, an empty name left out');
check(($m['Subject'] ?? '') === 'Hello' && ($m['TextPart'] ?? '') === 'Hi' && strpos($m['HTMLPart'] ?? '', 'cid:logo1') !== false, 'Subject, HTMLPart, TextPart');
check(($m['ReplyTo'] ?? null) === array('Email' => 'reply@example.com'), 'ReplyTo {Email}');
check(($m['Headers'] ?? null) === array('X-Custom' => 'abc'), 'Headers is a map');
check(!isset($m['Headers']['Message-Id']) && !isset($m['Headers']['Message-ID']), 'the pinned Message-ID is not sent: Mailjet forbids the header (B1)');
check(($m['Attachments'] ?? null) === array(array('ContentType' => 'application/pdf', 'Filename' => 'r.pdf', 'Base64Content' => base64_encode('PDFBYTES'))),
	'Attachments [{ContentType, Filename, Base64Content}]');
check(($m['InlinedAttachments'][0]['ContentID'] ?? '') === 'logo1' && ($m['InlinedAttachments'][0]['Base64Content'] ?? '') === base64_encode('PNGBYTES'),
	'an inline part goes in InlinedAttachments with a bare ContentID');

harness_set_setting_mem('mailjet_sandbox_mode', '1');
pw_mock(array(pw_json(array('Messages' => array(array('Status' => 'success'))))));
(new MailjetProvider())->send(pw_message());
check((pw_body()['SandboxMode'] ?? null) === true, 'sandbox mode sets SandboxMode: true on the top-level body');
harness_set_setting_mem('mailjet_sandbox_mode', '0');

$plain = (new EmailMessage())->from('s@example.com')->to('t@example.com')->subject('s')->text('t');
pw_mock(array(pw_json(array('Messages' => array(array('Status' => 'success'))))));
(new MailjetProvider())->send($plain);
$m = pw_body()['Messages'][0] ?? null;
check(is_array($m) && ($m['To'] ?? null) === array(array('Email' => 't@example.com')), 'the plain send was made and its body decodes');
$m = (array)$m;
check(!isset($m['Headers']) && !isset($m['Cc']) && !isset($m['ReplyTo']) && !isset($m['HTMLPart']) && !isset($m['Attachments'])
	&& !isset($m['From']['Name']), 'empty optional fields are left out, not sent as ""');

pw_mock(array(pw_json(array('Messages' => array(array('Status' => 'error'))), 400)));
check((new MailjetProvider())->send(pw_message()) === false, 'a 400 is a failed send');

pw_mock(array(new Response(200, array(), 'not json')));
check((new MailjetProvider())->send($plain) === false, 'a 200 whose body does not parse is not a send');

$zero = (new EmailMessage())->from('s@example.com')->to('t@example.com')->subject('s')->text('t')->header('X-Zero', 'z');
$hp = new ReflectionProperty('EmailMessage', 'headers');
$hp->setValue($zero, array('0' => 'z'));
pw_mock(array(pw_json(array('Messages' => array(array('Status' => 'success'))))));
(new MailjetProvider())->send($zero);
check(strpos((string)pw_req()->getBody(), '"Headers":{"0":"z"}') !== false, 'a header map is always a JSON object, even with a name "0" (B20)');

section('Failures and retries');

pw_mock(array(new \GuzzleHttp\Exception\ConnectException('down', new \GuzzleHttp\Psr7\Request('POST', 'https://api.mailjet.com/v3.1/send'), null, array('errno' => 28))));
check((new MailjetProvider())->send($plain) === false, 'a transport failure (ProviderHttpException) is a failed send');
pw_mock(array(pw_json(array('ErrorMessage' => 'x'), 503), pw_json(array('Messages' => array(array('Status' => 'success'))))));
check((new MailjetProvider())->send($plain) === false && count($GLOBALS['pw_history']) === 1,
	'a 5xx on a send is not retried (Mailjet may have taken it)');

section('Unreadable attachment');

pw_mock(array(pw_json(array())));
check((new MailjetProvider())->send(pw_with_missing_file(pw_message())) === false && count($GLOBALS['pw_history']) === 0,
	'an attachment that cannot be read fails the send and nothing is sent (B6)');
pw_mock(array(pw_json(array())));
$res = (new MailjetProvider())->sendBatch(pw_with_missing_file(pw_message()), array('a@example.com', 'b@example.com'));
check($res['success'] === false && $res['failed_recipients'] === array('a@example.com', 'b@example.com') && count($GLOBALS['pw_history']) === 0,
	'a batch with an unreadable attachment fails every recipient and sends nothing');

section('Batch');

$recipients = array();
for ($i = 0; $i < 51; $i++) { $recipients[] = 'r' . $i . '@example.com'; }
$answers = array();
for ($i = 0; $i < 50; $i++) { $answers[] = array('Status' => $i === 7 ? 'error' : 'success'); }
$no_files = (new EmailMessage())->from('sender@example.com', 'Sender Name')->to('one@example.com')->replyTo('reply@example.com')
	->subject('Hello')->text('Hi')->header('X-Custom', 'abc');
pw_mock(array(pw_json(array('Messages' => $answers)), pw_json(array('ErrorMessage' => 'x'), 500)));
$res = (new MailjetProvider())->sendBatch($no_files, $recipients);
check(count($GLOBALS['pw_history']) === 2, '51 recipients go in two calls (chunks of 50)');
$b = pw_body();
check(count($b['Messages'] ?? array()) === 50 && ($b['Messages'][0]['To'] ?? null) === array(array('Email' => 'r0@example.com')),
	'one message per recipient');
check(isset($b['Messages'][0]['Headers']['X-Custom']) && isset($b['Messages'][0]['ReplyTo']),
	'each batch message carries headers and Reply-To (B2)');
check($res['failed_recipients'] === array('r7@example.com', 'r50@example.com') && $res['success'] === false,
	'a non-success Status fails that recipient; a failed call fails its chunk', json_encode($res['failed_recipients']));

section('Batch with attachments goes per recipient');

$ok1 = pw_json(array('Messages' => array(array('Status' => 'success'))));
pw_mock(array($ok1, pw_json(array('ErrorMessage' => 'slow down'), 429), pw_json(array('Messages' => array(array('Status' => 'success')))),
	pw_json(array('Messages' => array(array('Status' => 'error'))), 400)));
$res = (new MailjetProvider())->sendBatch(pw_message(), array('a@example.com', 'b@example.com', 'c@example.com'));
check(count($GLOBALS['pw_history']) === 4, 'one call per recipient, a 429 retried');
check(count(pw_body(0)['Messages'] ?? array()) === 1 && isset(pw_body(0)['Messages'][0]['Attachments']),
	'each call carries one message with its attachments once');
check($res['failed_recipients'] === array('c@example.com'), 'a refused recipient is reported failed', json_encode($res['failed_recipients']));

harness_finish();
