<?php
/** @joinery-test
 * name: provider_http
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * ProviderHttp, the one request helper every email and DNS provider uses
 * (specs/email_provider_sdk_removal.md, WP1), over a mocked transport.
 *
 * Pins the rules a provider relies on: an HTTP error status comes back
 * rather than throwing; only failures where the service cannot have read the
 * request are retried by default (429, curl 6/7/35, never 28/52); a 5xx only
 * on request; the signer runs on every attempt; redirects are never followed;
 * credentials never reach an exception message. Then Smtp2Go, the helper's
 * first user, keeps its error wording.
 *
 * Run: php tests/run.php --filter=provider_http
 *
 * @version 1.1 - a refused header value is a ProviderHttpException without the value (B14)
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

$GLOBALS['ph_history'] = array();
$GLOBALS['ph_sleeps'] = array();

/** Answer the next requests with $queue; every request lands in ph_history. */
function ph_mock(array $queue): MockHandler {
	$GLOBALS['ph_history'] = array();
	$GLOBALS['ph_sleeps'] = array();
	$mock = new MockHandler($queue);
	$stack = HandlerStack::create($mock);
	$stack->push(Middleware::history($GLOBALS['ph_history']));
	ProviderHttp::useTestHandler($stack);
	return $mock;
}

function ph_connect_error(int $errno): ConnectException {
	return new ConnectException('cURL error ' . $errno . ' for https://api.example.test/x?api_key=SECRETQUERY',
		new Request('POST', 'https://api.example.test/x'), null, array('errno' => $errno));
}

function ph_count(): int { return count($GLOBALS['ph_history']); }

ProviderHttp::useTestSleep(function ($s) { $GLOBALS['ph_sleeps'][] = $s; });
harness_defer(function () { ProviderHttp::useTestHandler(null); ProviderHttp::useTestSleep(null); });

section('An HTTP error status comes back, never thrown');

ph_mock(array(new Response(404, array('X-Thing' => 'v'), '{"message":"nope"}')));
$r = ProviderHttp::request('GET', 'https://api.example.test/x');
check($r->status === 404, 'a 404 is returned as status 404');
check(($r->json()['message'] ?? '') === 'nope', 'json() decodes the body');
check($r->header('x-thing') === 'v', 'header() is case-insensitive');
check(!$r->ok(), 'ok() is false for a 404');

ph_mock(array(new Response(200, array(), 'not json')));
check(ProviderHttp::request('GET', 'https://api.example.test/x')->json() === null, 'json() is null for a non-JSON body');

section('A failure with no response throws ProviderHttpException');

ph_mock(array(ph_connect_error(28)));
$thrown = null;
try { ProviderHttp::request('POST', 'https://api.example.test/x', array(
	'headers' => array('X-Api-Key' => 'SECRETHEADER'), 'auth' => array('api', 'SECRETPASS'),
	'query' => array('api_key' => 'SECRETQUERY'), 'json' => array('a' => 1))); }
catch (ProviderHttpException $e) { $thrown = $e; }
check($thrown !== null, 'a timeout throws ProviderHttpException');
check($thrown && $thrown->getCode() === 28, 'the exception carries the curl error number');
$msg = $thrown ? $thrown->getMessage() : '';
check(strpos($msg, 'api.example.test') !== false, 'the message names the host', $msg);
check(strpos($msg, 'SECRETHEADER') === false && strpos($msg, 'SECRETPASS') === false
	&& strpos($msg, 'SECRETQUERY') === false, 'no header, auth or query value reaches the message', $msg);

ph_mock(array(new Response(200)));
$thrown = null;
try { ProviderHttp::request('POST', 'https://api.example.test/x', array('headers' => array('X-Api-Key' => "SECRET\x01KEY"))); }
catch (ProviderHttpException $e) { $thrown = $e; }
check($thrown !== null, 'an error Guzzle raises before sending (a refused header value) is a ProviderHttpException');
check($thrown && strpos($thrown->getMessage(), 'SECRET') === false, 'and its message never quotes the value', $thrown ? $thrown->getMessage() : '');

section('Retries: only where the service cannot have read the request');

ph_mock(array(new Response(429), new Response(200, array(), 'ok')));
$r = ProviderHttp::request('POST', 'https://api.example.test/x', array('retries' => 2, 'json' => array()));
check($r->status === 200 && ph_count() === 2, 'a 429 is retried');
check($GLOBALS['ph_sleeps'] === array(1), 'the first backoff is 1 s');

ph_mock(array(new Response(503), new Response(200)));
$r = ProviderHttp::request('POST', 'https://api.example.test/x', array('retries' => 2));
check($r->status === 503 && ph_count() === 1, 'a 5xx is not retried by default');

ph_mock(array(new Response(503), new Response(502), new Response(200)));
$r = ProviderHttp::request('POST', 'https://api.example.test/x', array('retries' => 2, 'retry_5xx' => true));
check($r->status === 200 && ph_count() === 3, 'a 5xx is retried with retry_5xx');
check($GLOBALS['ph_sleeps'] === array(1, 2), 'backoff is 1 s then 2 s');

ph_mock(array(new Response(429), new Response(429), new Response(429)));
$r = ProviderHttp::request('POST', 'https://api.example.test/x', array('retries' => 2));
check($r->status === 429 && ph_count() === 3, 'retries stop after the number asked for, returning the last answer');

foreach (array(6, 7, 35) as $errno) {
	ph_mock(array(ph_connect_error($errno), new Response(200)));
	$r = ProviderHttp::request('POST', 'https://api.example.test/x', array('retries' => 2));
	check($r->status === 200, 'curl error ' . $errno . ' is retried');
}
foreach (array(28, 52) as $errno) {
	ph_mock(array(ph_connect_error($errno), new Response(200)));
	$threw = false;
	try { ProviderHttp::request('POST', 'https://api.example.test/x', array('retries' => 2)); }
	catch (ProviderHttpException $e) { $threw = true; }
	check($threw && ph_count() === 1, 'curl error ' . $errno . ' is never retried (the service may have the message)');
}

ph_mock(array(new Response(400, array(), '{"code":"Throttling"}'), new Response(200)));
$r = ProviderHttp::request('POST', 'https://api.example.test/x', array('retries' => 2,
	'retry_if' => function (ProviderHttpResponse $resp) { return ($resp->json()['code'] ?? '') === 'Throttling'; }));
check($r->status === 200 && ph_count() === 2, 'retry_if adds a service\'s own try-again signal');

section('The signer runs on every attempt');

$calls = array();
ph_mock(array(new Response(429), new Response(200)));
ProviderHttp::request('POST', 'https://api.example.test/x', array(
	'retries' => 1,
	'headers' => array('Content-Type' => 'application/json'),
	'json' => array('k' => 'v'),
	'sign' => function (array $headers, string $body) use (&$calls) {
		$calls[] = array($headers, $body);
		return array('Authorization' => 'sig-' . count($calls));
	},
));
check(count($calls) === 2, 'sign is called once per attempt');
check(($calls[0][1] ?? '') === '{"k":"v"}', 'sign sees the exact body bytes');
check(isset($calls[0][0]['Content-Type']), 'sign sees the caller\'s headers');
$sent = array_map(function ($h) { return $h['request']->getHeaderLine('Authorization'); }, $GLOBALS['ph_history']);
check($sent === array('sig-1', 'sig-2'), 'each attempt carries its own fresh signature', json_encode($sent));

section('Redirects are not followed');

ph_mock(array(new Response(302, array('Location' => 'https://elsewhere.example/steal')), new Response(200)));
$r = ProviderHttp::request('POST', 'https://api.example.test/x', array('headers' => array('api-key' => 'SECRET')));
check($r->status === 302, 'a 3xx comes back to the caller');
check(ph_count() === 1, 'the redirect target is never asked, so it never sees the key header');

section('Bodies and query');

ph_mock(array(new Response(200)));
ProviderHttp::request('GET', 'https://api.example.test/x', array('query' => array('to' => array('a@x', 'b@x'), 'n' => 1)));
check($GLOBALS['ph_history'][0]['request']->getUri()->getQuery() === 'to=a%40x&to=b%40x&n=1',
	'a list value repeats its name in the query');

ph_mock(array(new Response(200)));
ProviderHttp::request('POST', 'https://api.example.test/x', array('form' => array('a' => 'b c')));
$req = $GLOBALS['ph_history'][0]['request'];
check((string)$req->getBody() === 'a=b%20c' && $req->getHeaderLine('Content-Type') === 'application/x-www-form-urlencoded',
	'form bodies are urlencoded');

ph_mock(array(new Response(200)));
ProviderHttp::request('POST', 'https://api.example.test/x', array('auth' => array('api', 'k')));
check($GLOBALS['ph_history'][0]['request']->getHeaderLine('Authorization') === 'Basic ' . base64_encode('api:k'),
	'auth sends HTTP basic');

section('The test seam refuses outside the CLI');

$src = file_get_contents(PathHelper::getIncludePath('includes/ProviderHttp.php'));
check(substr_count($src, "php_sapi_name() !== 'cli'") >= 2, 'useTestHandler and useTestSleep both check the SAPI');

section('Smtp2Go on the helper keeps its wording');

harness_set_setting_mem('smtp2go_api_key', 'api-TESTKEY');
$post = new ReflectionMethod('Smtp2GoProvider', 'post');

ph_mock(array(new Response(200, array(), '{"data":{"succeeded":1,"failed":0}}')));
$data = $post->invoke(null, 'email/send', array('x' => 1));
check(($data['succeeded'] ?? 0) === 1, 'a 2xx returns the envelope\'s data');
$req = $GLOBALS['ph_history'][0]['request'];
check((string)$req->getUri() === 'https://api.smtp2go.com/v3/email/send', 'Smtp2Go posts to its v3 endpoint');
check($req->getHeaderLine('X-Smtp2go-Api-Key') === 'api-TESTKEY', 'the key travels in X-Smtp2go-Api-Key');

ph_mock(array(new Response(400, array(), '{"data":{"error":"Sender not verified"}}')));
$msg = '';
$code = null;
try { $post->invoke(null, 'email/send', array()); } catch (RuntimeException $e) { $msg = $e->getMessage(); $code = $e->getCode(); }
check($msg === 'SMTP2GO email/send failed (400): Sender not verified', 'a non-2xx throws with SMTP2GO\'s own words', $msg);
check($code === 400, 'the status is the exception code');

ph_mock(array(new Response(200, array(), '{"data":{"error":"Quota exceeded"}}')));
$msg = '';
try { $post->invoke(null, 'email/send', array()); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
check($msg === 'SMTP2GO email/send: Quota exceeded', 'a data.error on a 2xx still throws', $msg);

$m = (new EmailMessage())->from('a@example.com')->to('b@example.com')->subject('s')->text('t');
$m->attachData('x', 'x.txt', 'text/plain');
$ref = new ReflectionProperty('EmailMessage', 'attachments');
$atts = $ref->getValue($m);
$atts[] = array('path' => '/nonexistent/file.pdf', 'name' => 'file.pdf');
$ref->setValue($m, $atts);
ph_mock(array(new Response(200, array(), '{"data":{"succeeded":1,"failed":0}}')));
$ok = (new Smtp2GoProvider())->send($m);
check($ok === false && ph_count() === 0, 'an attachment that cannot be read fails the send, and nothing is sent (B6)');

harness_finish();
