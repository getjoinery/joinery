<?php
/** @joinery-test
 * name: provider_wire_postmark
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * PostmarkProvider on the wire (specs/email_provider_sdk_removal.md, WP2),
 * over a mocked ProviderHttp transport: the request it sends for validation,
 * a single send and a batch, and how it reads Postmark's answers.
 *
 * Run: php tests/run.php --filter=provider_wire_postmark
 *
 * @version 1.1 - batch with attachments goes per recipient (B12); no pinned Message-ID in batch (B15);
 *   quoted display names; a non-JSON 200 fails; transport failure, no 5xx retry, the empty-field
 *   check sees a real request (T1-T3)
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
harness_set_setting_mem('postmark_server_token', '11111111-2222-3333-4444-555555555555');
harness_set_setting_mem('postmark_message_stream', 'outbound');

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

section('Validation');

pw_mock(array(pw_json(array('ID' => 1, 'Name' => 'My Server'))));
$v = PostmarkProvider::validateApiConnection();
$r = pw_req();
check($r && $r->getMethod() === 'GET' && (string)$r->getUri() === 'https://api.postmarkapp.com/server', 'GET /server');
check($r && $r->getHeaderLine('X-Postmark-Server-Token') === '11111111-2222-3333-4444-555555555555', 'the token travels in X-Postmark-Server-Token');
check($v['success'] && $v['label'] === 'API Key Valid' && $v['details']['Server Name'] === 'My Server', 'a 200 is valid, server name from Name');

pw_mock(array(pw_json(array('ErrorCode' => 10, 'Message' => 'Bad token'), 401)));
$v = PostmarkProvider::validateApiConnection();
check(!$v['success'] && $v['label'] === 'API Key Rejected', 'a 401 is API Key Rejected');

pw_mock(array(pw_json(array('ErrorCode' => 10, 'Message' => 'Bad token'), 422)));
$v = PostmarkProvider::validateApiConnection();
check(!$v['success'] && $v['label'] === 'API Key Rejected', 'ErrorCode 10 is API Key Rejected whatever the status');

pw_mock(array(pw_json(array('ErrorCode' => 500, 'Message' => 'Internal'), 500)));
$v = PostmarkProvider::validateApiConnection();
check(!$v['success'] && $v['error'] === 'Postmark error 500: Internal', 'anything else shows Postmark\'s own message', $v['error']);

section('Single send');

pw_mock(array(pw_json(array('ErrorCode' => 0, 'Message' => 'OK', 'MessageID' => 'x'))));
$ok = (new PostmarkProvider())->send(pw_message());
$r = pw_req();
$b = pw_body();
check($ok === true, 'a 200 with ErrorCode 0 is a sent message');
check($r && $r->getMethod() === 'POST' && (string)$r->getUri() === 'https://api.postmarkapp.com/email', 'POST /email');
check(($b['From'] ?? '') === 'Sender Name <sender@example.com>', 'From is "Name <email>"');
check(($b['To'] ?? '') === 'One <one@example.com>', 'To is joined "Name <email>"');
check(($b['Cc'] ?? '') === 'cc@example.com' && ($b['Bcc'] ?? '') === 'Bee <bcc@example.com>', 'Cc and Bcc joined, an empty name left out');
check(($b['Subject'] ?? '') === 'Hello' && ($b['TextBody'] ?? '') === 'Hi' && strpos($b['HtmlBody'] ?? '', 'cid:logo1') !== false, 'subject and both bodies');
check(($b['ReplyTo'] ?? '') === 'reply@example.com', 'ReplyTo');
check(($b['MessageStream'] ?? '') === 'outbound' && array_key_exists('TrackOpens', $b) && array_key_exists('TrackLinks', $b), 'stream and tracking fields');
$hdrs = $b['Headers'] ?? array();
check(array_is_list($hdrs) && in_array(array('Name' => 'X-Custom', 'Value' => 'abc'), $hdrs, true), 'headers are a list of {Name, Value}');
check(in_array(array('Name' => 'Message-ID', 'Value' => '<pinned.1@example.com>'), $hdrs, true), 'the pinned Message-ID is sent (B1)');
check(in_array(array('Name' => 'X-PM-KeepID', 'Value' => 'true'), $hdrs, true), 'with X-PM-KeepID: true so Postmark keeps it');
$atts = $b['Attachments'] ?? array();
check(count($atts) === 2, 'both parts are attachments');
check(($atts[0]['Name'] ?? '') === 'r.pdf' && ($atts[0]['Content'] ?? '') === base64_encode('PDFBYTES') && ($atts[0]['ContentType'] ?? '') === 'application/pdf' && !isset($atts[0]['ContentID']), 'a regular attachment: Name, Content, ContentType');
check(($atts[1]['ContentID'] ?? '') === 'cid:logo1', 'an inline part carries ContentID cid:<cid>');

pw_mock(array(pw_json(array('ErrorCode' => 300, 'Message' => 'Invalid email request'), 422)));
check((new PostmarkProvider())->send(pw_message()) === false, 'a 422 is a failed send');

$plain = (new EmailMessage())->from('s@example.com')->to('t@example.com')->subject('s')->text('t');
pw_mock(array(pw_json(array('ErrorCode' => 0))));
(new PostmarkProvider())->send($plain);
$b = pw_body();
check(is_array($b) && ($b['To'] ?? '') === 't@example.com', 'the plain send was made and its body decodes');
check(is_array($b) && !isset($b['Headers']) && !isset($b['Cc']) && !isset($b['ReplyTo']) && !isset($b['HtmlBody']) && !isset($b['Attachments']),
	'empty optional fields are left out, not sent as ""');

pw_mock(array(new Response(200, array(), 'not json')));
check((new PostmarkProvider())->send($plain) === false, 'a 200 whose body does not parse is not a send');

pw_mock(array(pw_json(array('ErrorCode' => 0))));
$named = (new EmailMessage())->from('s@example.com', 'Smith, Jo')->to('t@example.com', 'Doe "JD", Jane')->subject('s')->text('t');
(new PostmarkProvider())->send($named);
$b = pw_body();
check(($b['From'] ?? '') === '"Smith, Jo" <s@example.com>', 'a display name with a comma is quoted', $b['From'] ?? '');
check(($b['To'] ?? '') === '"Doe \\"JD\\", Jane" <t@example.com>', 'quotes inside a display name are escaped', $b['To'] ?? '');

section('Failures and retries');

pw_mock(array(new \GuzzleHttp\Exception\ConnectException('down', new \GuzzleHttp\Psr7\Request('POST', 'https://api.postmarkapp.com/email'), null, array('errno' => 28))));
check((new PostmarkProvider())->send($plain) === false, 'a transport failure (ProviderHttpException) is a failed send');
pw_mock(array(new \GuzzleHttp\Exception\ConnectException('down', new \GuzzleHttp\Psr7\Request('GET', 'https://api.postmarkapp.com/server'), null, array('errno' => 7))));
$v = PostmarkProvider::validateApiConnection();
check(!$v['success'] && $v['label'] === 'API Connection Failed', 'a transport failure on validation reads as API Connection Failed');
pw_mock(array(pw_json(array('ErrorCode' => 500, 'Message' => 'x'), 503), pw_json(array('ErrorCode' => 0))));
check((new PostmarkProvider())->send($plain) === false && count($GLOBALS['pw_history']) === 1,
	'a 5xx on a send is not retried (Postmark may have taken it)');

section('Unreadable attachment');

pw_mock(array(pw_json(array('ErrorCode' => 0))));
check((new PostmarkProvider())->send(pw_with_missing_file(pw_message())) === false && count($GLOBALS['pw_history']) === 0,
	'an attachment that cannot be read fails the send and nothing is sent (B6)');
pw_mock(array(pw_json(array())));
$res = (new PostmarkProvider())->sendBatch(pw_with_missing_file(pw_message()), array('a@example.com', 'b@example.com'));
check($res['success'] === false && $res['failed_recipients'] === array('a@example.com', 'b@example.com') && count($GLOBALS['pw_history']) === 0,
	'a batch with an unreadable attachment fails every recipient and sends nothing');

section('Batch');

$recipients = array();
for ($i = 0; $i < 501; $i++) { $recipients[] = 'r' . $i . '@example.com'; }
$answers = array();
for ($i = 0; $i < 500; $i++) { $answers[] = array('ErrorCode' => $i === 3 ? 406 : 0, 'To' => $recipients[$i]); }
$no_files = (new EmailMessage())->from('sender@example.com', 'Sender Name')->to('one@example.com')->replyTo('reply@example.com')
	->subject('Hello')->html('<p>Hi</p>')->text('Hi')->header('X-Custom', 'abc')->messageId('<pinned.1@example.com>');
pw_mock(array(pw_json($answers), pw_json(array('ErrorCode' => 500, 'Message' => 'x'), 500)));
$res = (new PostmarkProvider())->sendBatch($no_files, $recipients);
check(count($GLOBALS['pw_history']) === 2, '501 recipients go in two calls (chunks of 500)');
check((string)pw_req()->getUri() === 'https://api.postmarkapp.com/email/batch', 'POST /email/batch');
$b = pw_body();
check(is_array($b) && count($b) === 500 && ($b[0]['To'] ?? '') === 'r0@example.com', 'the first call carries 500 entries, one recipient each');
check(in_array(array('Name' => 'X-Custom', 'Value' => 'abc'), $b[0]['Headers'] ?? array(), true) && ($b[0]['ReplyTo'] ?? '') === 'reply@example.com',
	'each batch entry carries headers and Reply-To like a single send (B2)');
$ids = array_filter($b[0]['Headers'] ?? array(), function ($h) { return in_array($h['Name'], array('Message-ID', 'X-PM-KeepID'), true); });
check(empty($ids), 'a batch entry never carries the pinned Message-ID, which belongs to one message (B15)');
check($res['failed_recipients'] === array('r3@example.com', 'r500@example.com'),
	'a non-zero ErrorCode fails that recipient; a failed call fails its chunk', json_encode($res['failed_recipients']));
check($res['success'] === false, 'a batch with failures is not a success');

section('Batch with attachments goes per recipient');

pw_mock(array(pw_json(array('ErrorCode' => 0)), pw_json(array('ErrorCode' => 0), 429), pw_json(array('ErrorCode' => 0)),
	pw_json(array('ErrorCode' => 406, 'Message' => 'inactive'), 422)));
$res = (new PostmarkProvider())->sendBatch(pw_message(), array('a@example.com', 'b@example.com', 'c@example.com'));
$uris = array_map(function ($h) { return (string)$h['request']->getUri(); }, $GLOBALS['pw_history']);
check($uris === array_fill(0, 4, 'https://api.postmarkapp.com/email'), 'each recipient is its own POST /email, never /email/batch', json_encode($uris));
check(count(pw_body(0)['Attachments'] ?? array()) === 2 && (pw_body(0)['To'] ?? '') === 'a@example.com', 'each carries the attachments once');
check(count($GLOBALS['pw_history']) === 4 && (pw_body(2)['To'] ?? '') === 'b@example.com', 'a 429 on one recipient is retried');
check($res['failed_recipients'] === array('c@example.com') && $res['success'] === false, 'a refused recipient is reported failed');

harness_finish();
