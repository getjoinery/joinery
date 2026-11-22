<?php
/** @joinery-test
 * name: provider_wire_ses
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * SesProvider on the wire (specs/email_provider_sdk_removal.md, WP5), over a
 * mocked transport: what it sends to SESv2 and how it reads the answers.
 *
 * Simple vs Raw (Raw.Data base64), the SigV4 header on each attempt (and a
 * fresh one on a retry), both error-code naming sets and both message key
 * spellings, the region check, the encoded identity path, DKIM tokens to
 * CNAMEs, and typed-in keys only. No network is touched.
 *
 * Run: php tests/run.php --filter=provider_wire_ses
 *
 * @version 1.1 - Simple Body is an object even with no parts (B20); a send needs a MessageId; a quoted From
 *   display name; a transport failure is a failed send (T1)
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getComposerAutoloadPath());

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

$GLOBALS['ses_history'] = array();
$GLOBALS['ses_now'] = 1700000000;

function ses_mock(array $queue): void {
	$GLOBALS['ses_history'] = array();
	$stack = HandlerStack::create(new MockHandler($queue));
	$stack->push(Middleware::history($GLOBALS['ses_history']));
	ProviderHttp::useTestHandler($stack);
}

function ses_req(int $i = 0) { return $GLOBALS['ses_history'][$i]['request'] ?? null; }
function ses_body(int $i = 0): array { $r = ses_req($i); return $r ? (json_decode((string)$r->getBody(), true) ?: array()) : array(); }
function ses_json(int $status, array $body = array(), array $headers = array()): Response {
	return new Response($status, $headers + array('Content-Type' => 'application/json'), json_encode($body ?: new stdClass()));
}

ProviderHttp::useTestSleep(function ($s) { $GLOBALS['ses_now'] += $s; });
SesProvider::useTestClock(function () { return $GLOBALS['ses_now']; });
harness_defer(function () {
	ProviderHttp::useTestHandler(null); ProviderHttp::useTestSleep(null); SesProvider::useTestClock(null);
});

harness_set_setting_mem('ses_access_key_id', 'AKIDTESTKEY');
harness_set_setting_mem('ses_secret_access_key', 'secretTESTKEY');
harness_set_setting_mem('ses_region', 'eu-west-2');
harness_set_setting_mem('ses_configuration_set', 'cfgset');

$simple = (new EmailMessage())->from('sender@example.com', 'Sender')->to('to@example.com', 'To Person')
	->subject('Hello')->html('<p>Hi</p>')->text('Hi')->replyTo('reply@example.com');

section('Simple send');

ses_mock(array(ses_json(200, array('MessageId' => 'abc'))));
check((new SesProvider())->send($simple) === true, 'a 200 is a successful send');
$req = ses_req();
check($req && $req->getMethod() === 'POST' && (string)$req->getUri() === 'https://email.eu-west-2.amazonaws.com/v2/email/outbound-emails',
	'POST to the region\'s SESv2 outbound-emails endpoint', $req ? (string)$req->getUri() : '');
$b = ses_body();
check(($b['FromEmailAddress'] ?? '') === 'Sender <sender@example.com>', 'FromEmailAddress carries the name');
check(($b['Destination']['ToAddresses'] ?? null) === array('To Person <to@example.com>'), 'Destination.ToAddresses');
check(($b['Content']['Simple']['Subject'] ?? null) === array('Data' => 'Hello', 'Charset' => 'UTF-8'), 'Simple subject, UTF-8');
check(($b['Content']['Simple']['Body']['Html']['Data'] ?? '') === '<p>Hi</p>' && isset($b['Content']['Simple']['Body']['Text']),
	'Simple body carries Html and Text');
check(($b['ReplyToAddresses'] ?? null) === array('reply@example.com'), 'ReplyToAddresses');
check(($b['ConfigurationSetName'] ?? '') === 'cfgset', 'ConfigurationSetName');

$auth = $req ? $req->getHeaderLine('Authorization') : '';
check(strpos($auth, 'AWS4-HMAC-SHA256 Credential=AKIDTESTKEY/20231114/eu-west-2/ses/aws4_request') === 0,
	'signed for service ses in the configured region', $auth);
check(strpos($auth, 'SignedHeaders=content-type;host;x-amz-date,') !== false,
	'signs only content-type, host and x-amz-date', $auth);
check($req && $req->getHeaderLine('x-amz-date') === '20231114T221320Z', 'x-amz-date comes from the clock');
check(strpos($auth, 'secretTESTKEY') === false, 'the secret never goes on the wire');

// The signature is what AwsSigV4 computes for exactly these bytes.
$expect = AwsSigV4::signHeaders(array('method' => 'POST', 'host' => 'email.eu-west-2.amazonaws.com',
	'path' => '/v2/email/outbound-emails', 'headers' => array('content-type' => 'application/json'),
	'payload_hash' => hash('sha256', (string)$req->getBody()), 'service' => 'ses', 'region' => 'eu-west-2',
	'access_key' => 'AKIDTESTKEY', 'secret_key' => 'secretTESTKEY', 'time' => 1700000000, 'double_encode_path' => true));
check($auth === $expect['Authorization'], 'the Authorization header matches the signer over the sent body');

section('Raw send: attachments, headers or a pinned Message-ID');

$raw_msg = (new EmailMessage())->from('sender@example.com')->to('to@example.com')->subject('Raw')
	->text('body')->attachData('FILEBYTES', 'f.txt', 'text/plain');
ses_mock(array(ses_json(200, array('MessageId' => 'r1'))));
check((new SesProvider())->send($raw_msg) === true, 'a raw send succeeds');
$b = ses_body();
check(!isset($b['Content']['Simple']) && isset($b['Content']['Raw']['Data']), 'an attachment switches to Content.Raw');
$mime = base64_decode((string)($b['Content']['Raw']['Data'] ?? ''), true);
check(is_string($mime) && strpos($mime, 'Subject: Raw') !== false, 'Raw.Data is the MIME, base64-encoded');
check(!isset($b['FromEmailAddress']), 'raw sends leave From to the MIME header');

ses_mock(array(ses_json(200, array('MessageId' => 'm')), ses_json(200, array('MessageId' => 'm'))));
$r = (new SesProvider())->relayRawMessage("From: a@example.com\r\nSubject: R\r\n\r\nx", 'bounce@example.com', array('x@example.com', 'y@example.com'));
check($r === array('x@example.com' => true, 'y@example.com' => true), 'raw relay sends one request per destination');
check(base64_decode((string)(ses_body(1)['Content']['Raw']['Data'] ?? '')) === "From: a@example.com\r\nSubject: R\r\n\r\nx",
	'raw relay sends the MIME base64-encoded');
check((ses_body(1)['Destination']['ToAddresses'] ?? null) === array('y@example.com'), 'each relay names its own destination');

section('A retry carries a fresh signature');

$GLOBALS['ses_now'] = 1700000000;
ses_mock(array(ses_json(400, array('__type' => 'ThrottlingException', 'message' => 'slow down')), ses_json(200, array('MessageId' => 'm'))));
check((new SesProvider())->send($simple) === true, 'a Throttling answer is retried, and the retry succeeds');
check(count($GLOBALS['ses_history']) === 2, 'two attempts were made');
$d1 = ses_req(0)->getHeaderLine('x-amz-date');
$d2 = ses_req(1)->getHeaderLine('x-amz-date');
check($d1 !== $d2 && ses_req(0)->getHeaderLine('Authorization') !== ses_req(1)->getHeaderLine('Authorization'),
	'the second attempt is signed at its own time', $d1 . ' / ' . $d2);

ses_mock(array(ses_json(503), ses_json(500), ses_json(200, array('MessageId' => 'm'))));
check((new SesProvider())->send($simple) === true && count($GLOBALS['ses_history']) === 3, 'a 5xx is retried twice');

ses_mock(array(ses_json(400, array('message' => 'Bad')), ses_json(200, array('MessageId' => 'm'))));
check((new SesProvider())->send($simple) === false && count($GLOBALS['ses_history']) === 1, 'a plain 400 is not retried and fails the send');

section('Error codes: both naming sets, both message spellings');

$cases = array(
	array(ses_json(403, array('message' => 'The security token included in the request is invalid.'), array('x-amzn-ErrorType' => 'UnrecognizedClientException:http://internal.amazon.com/coral/')), 'Credentials Rejected'),
	array(ses_json(403, array('__type' => 'InvalidSignatureException', 'message' => 'sig')), 'Credentials Rejected'),
	array(ses_json(403, array('__type' => 'InvalidClientTokenId', 'Message' => 'old')), 'Credentials Rejected'),
	array(ses_json(403, array('__type' => 'com.amazonaws.ses#SignatureDoesNotMatch')), 'Credentials Rejected'),
	array(ses_json(403, array('__type' => 'AccessDeniedException', 'Message' => 'no')), 'Access Denied'),
);
foreach ($cases as $i => $c) {
	ses_mock(array($c[0]));
	$v = SesProvider::validateApiConnection();
	check($v['success'] === false && $v['label'] === $c[1], 'error case ' . ($i + 1) . ' reads as ' . $c[1], $v['label']);
}

ses_mock(array(ses_json(400, array('__type' => 'BadRequestException', 'Message' => 'Capital M message'))));
$v = SesProvider::validateApiConnection();
check($v['label'] === 'API Connection Failed' && strpos((string)$v['error'], 'Capital M message') !== false
	&& strpos((string)$v['error'], '400') !== false, 'another error shows the status and SES\'s Message', (string)$v['error']);

ses_mock(array(new ConnectException('x', new Request('GET', 'https://email.eu-west-2.amazonaws.com/'), null, array('errno' => 6)),
	new ConnectException('x', new Request('GET', 'https://email.eu-west-2.amazonaws.com/'), null, array('errno' => 6)),
	new ConnectException('x', new Request('GET', 'https://email.eu-west-2.amazonaws.com/'), null, array('errno' => 6))));
$v = SesProvider::validateApiConnection();
check($v['label'] === 'Endpoint Unreachable', 'a transport failure reads as Endpoint Unreachable');

ses_mock(array(ses_json(200, array('ProductionAccessEnabled' => false, 'SendingEnabled' => true,
	'SendQuota' => array('Max24HourSend' => 200, 'SentLast24Hours' => 3, 'MaxSendRate' => 1)))));
$v = SesProvider::validateApiConnection();
check($v['success'] === true && $v['label'] === 'API Connection Valid', 'GetAccount 200 validates');
check(($v['details']['Production Access'] ?? '') === 'No (Sandbox)' && ($v['details']['24h Quota'] ?? '') === '3 / 200',
	'account details are read from the JSON');
check(!isset($v['details']['Credentials']), 'no credential-source line');
check((string)ses_req()->getUri() === 'https://email.eu-west-2.amazonaws.com/v2/email/account' && ses_req()->getMethod() === 'GET',
	'validation is GET /v2/email/account');
check(strpos(ses_req()->getHeaderLine('Authorization'), 'SignedHeaders=host;x-amz-date,') !== false,
	'a GET with no body signs host and x-amz-date only');

section('DKIM: encoded identity path, tokens to CNAMEs');

ses_mock(array(ses_json(200, array('DkimAttributes' => array('Tokens' => array('t1', 't2', 't3'))))));
$d = SesProvider::getDkimStatus('mail.example.com');
check($d['status'] === 'ok' && count($d['records']) === 3, 'three tokens give three records');
check(($d['records'][0] ?? null) === array('type' => 'CNAME', 'name' => 't1._domainkey.mail.example.com', 'value' => 't1.dkim.amazonses.com'),
	'a token becomes its CNAME');

ses_mock(array(ses_json(200, array())));
SesProvider::getDkimStatus('a b@example.com');
$path = ses_req()->getUri()->getPath();
check($path === '/v2/email/identities/a%20b%40example.com', 'the identity is URL-encoded in the path', $path);
$auth = ses_req()->getHeaderLine('Authorization');
$expect = AwsSigV4::signHeaders(array('method' => 'GET', 'host' => 'email.eu-west-2.amazonaws.com',
	'path' => '/v2/email/identities/a%20b%40example.com', 'service' => 'ses', 'region' => 'eu-west-2',
	'access_key' => 'AKIDTESTKEY', 'secret_key' => 'secretTESTKEY', 'time' => $GLOBALS['ses_now'], 'double_encode_path' => true));
check($auth === $expect['Authorization'], 'the encoded path is signed double-encoded');

ses_mock(array(ses_json(404, array('message' => 'not found'), array('x-amzn-ErrorType' => 'NotFoundException:'))));
check(SesProvider::getDkimStatus('nope.example.com')['status'] === 'not_registered', 'NotFoundException reads as not_registered');

ses_mock(array(ses_json(403, array('__type' => 'AccessDeniedException'))));
check(SesProvider::getDkimStatus('x.example.com')['status'] === 'unreachable', 'another error reads as unreachable');

section('Region check');

check(SesProvider::regionError('us-east-1') === null, 'us-east-1 is accepted');
check(SesProvider::regionError('us-gov-west-1') === null, 'GovCloud is accepted');
check(SesProvider::regionError('ap-southeast-5') === null, 'a region shape AWS adds later is accepted');
check(strpos((string)SesProvider::regionError('cn-north-1'), 'China') !== false, 'a cn- region is refused by name');
check(strpos((string)SesProvider::regionError('us-iso-east-1'), 'isolated') !== false, 'an -iso region is refused by name');
check(SesProvider::regionError('evil.com/x') !== null, 'a non-region is refused');

harness_set_setting_mem('ses_region', 'cn-north-1');
ses_mock(array(ses_json(200, array('MessageId' => 'm'))));
check((new SesProvider())->send($simple) === false && count($GLOBALS['ses_history']) === 0,
	'a refused region sends nothing');
$cfg = SesProvider::validateConfiguration();
check(!$cfg['valid'], 'a refused region fails the configuration check');
harness_set_setting_mem('ses_region', 'eu-west-2');

section('Body shape, success, quoting, transport failure');

$nobody = (new EmailMessage())->from('sender@example.com', 'Smith, Jo')->to('to@example.com')->subject('Hello');
ses_mock(array(ses_json(200, array('MessageId' => 'm'))));
(new SesProvider())->send($nobody);
$raw = ses_req() ? (string)ses_req()->getBody() : '';
check(strpos($raw, '"Body":{}') !== false, 'a message with neither HTML nor text sends Body as an object, never a list (B20)', $raw);
check((ses_body()['FromEmailAddress'] ?? '') === '"Smith, Jo" <sender@example.com>', 'a From display name with a comma is quoted');

ses_mock(array(ses_json(200, array())));
check((new SesProvider())->send($simple) === false, 'a 200 without a MessageId is not a send');
ses_mock(array(new Response(200, array(), 'not json')));
check((new SesProvider())->send($simple) === false, 'a 200 whose body does not parse is not a send');

ses_mock(array(new \GuzzleHttp\Exception\ConnectException('down', new \GuzzleHttp\Psr7\Request('POST', 'https://email.eu-west-2.amazonaws.com/v2/email/outbound-emails'), null, array('errno' => 28))));
check((new SesProvider())->send($simple) === false && count($GLOBALS['ses_history']) === 1,
	'a timeout is a failed send, never retried (SES may have the message)');

section('Typed-in keys only (Q1)');

check(!method_exists('SesProvider', 'hasCredentials'), 'hasCredentials() is gone');
check(SesProvider::validateConfiguration()['valid'] === true, 'both keys and a region validate');
harness_set_setting_mem('ses_secret_access_key', 'x');
$ref = new ReflectionProperty('Globalvars', 'settings');
$arr = $ref->getValue(Globalvars::get_instance());
if (harness_stored_setting_is_blank('ses_secret_access_key')) {
	unset($arr['ses_secret_access_key']);
	$ref->setValue(Globalvars::get_instance(), $arr);
	$cfg = SesProvider::validateConfiguration();
	check(!$cfg['valid'] && strpos(implode(' ', $cfg['errors']), 'secret access key missing') !== false,
		'an access key without a secret is refused');
} else {
	harness_skip('an access key without a secret is refused', 'this box stores an SES secret');
}

$src = file_get_contents(PathHelper::getIncludePath('includes/email_providers/SesProvider.php'));

harness_finish();
