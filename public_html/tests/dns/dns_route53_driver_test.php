<?php
/** @joinery-test
 * name: dns_route53_driver
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * The Route 53 driver against its REST/XML API, over a mocked transport
 * (specs/email_provider_sdk_removal.md, WP7). No network is touched.
 *
 * Pins what the AWS SDK used to hide: a list with one child is still a list,
 * IsTruncated "false" ends a paging loop (a non-empty string is not true),
 * values are XML-escaped in the change request, a throttling 400 is retried,
 * and every request is SigV4-signed with the headers Route 53 needs.
 *
 * Run: php tests/run.php --filter=dns_route53_driver
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/dns/DnsDriverRegistry.php'));

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

const R53_NS = 'https://route53.amazonaws.com/doc/2013-04-01/';

$GLOBALS['r53_history'] = array();

function r53_mock(array $queue): MockHandler {
	$GLOBALS['r53_history'] = array();
	$mock = new MockHandler($queue);
	$stack = HandlerStack::create($mock);
	$stack->push(Middleware::history($GLOBALS['r53_history']));
	ProviderHttp::useTestHandler($stack);
	return $mock;
}

function r53_xml(string $inner, int $status = 200): Response {
	return new Response($status, array('Content-Type' => 'text/xml'),
		'<?xml version="1.0" encoding="UTF-8"?>' . $inner);
}

function r53_zones(string $truncated = 'false'): Response {
	return r53_xml('<ListHostedZonesResponse xmlns="' . R53_NS . '"><HostedZones>'
		. '<HostedZone><Id>/hostedzone/ZPRIV</Id><Name>example.com.</Name><Config><PrivateZone>true</PrivateZone></Config></HostedZone>'
		. '<HostedZone><Id>/hostedzone/Z123</Id><Name>example.com.</Name><Config><PrivateZone>false</PrivateZone></Config></HostedZone>'
		. '</HostedZones><IsTruncated>' . $truncated . '</IsTruncated><MaxItems>100</MaxItems></ListHostedZonesResponse>');
}

function r53_rrsets(string $sets, string $truncated = 'false', string $next = ''): Response {
	return r53_xml('<ListResourceRecordSetsResponse xmlns="' . R53_NS . '"><ResourceRecordSets>' . $sets
		. '</ResourceRecordSets><IsTruncated>' . $truncated . '</IsTruncated>' . $next
		. '<MaxItems>300</MaxItems></ListResourceRecordSetsResponse>');
}

function r53_set(string $name, string $type, array $values, int $ttl = 300): string {
	$rr = '';
	foreach ($values as $v) { $rr .= '<ResourceRecord><Value>' . htmlspecialchars($v, ENT_XML1) . '</Value></ResourceRecord>'; }
	return '<ResourceRecordSet><Name>' . $name . '</Name><Type>' . $type . '</Type><TTL>' . $ttl
		. '</TTL><ResourceRecords>' . $rr . '</ResourceRecords></ResourceRecordSet>';
}

function r53_error(string $code, string $message, int $status = 400): Response {
	return r53_xml('<ErrorResponse xmlns="' . R53_NS . '"><Error><Type>Sender</Type><Code>' . $code
		. '</Code><Message>' . $message . '</Message></Error><RequestId>r</RequestId></ErrorResponse>', $status);
}

function r53_driver(string $token = ''): Route53DnsDriver {
	return new Route53DnsDriver(array('access_key_id' => 'AKIDTEST', 'secret_access_key' => 'SECRETTEST',
		'session_token' => $token));
}

function r53_req(int $i) { return $GLOBALS['r53_history'][$i]['request'] ?? null; }

ProviderHttp::useTestSleep(function ($s) {});
harness_defer(function () { ProviderHttp::useTestHandler(null); ProviderHttp::useTestSleep(null); });

section('Zones: one-child lists, string flags, private zones skipped');

r53_mock(array(r53_zones('false'),
	r53_rrsets(r53_set('_dmarc.example.com.', 'TXT', array('"v=DMARC1; p=none"')))));
$driver = r53_driver();
check($driver->zoneFor('mail.example.com') === 'example.com', 'the public zone is found for a subdomain');
$records = $driver->listRecords('example.com');
check(count($GLOBALS['r53_history']) === 2, 'IsTruncated "false" ends both loops (one zones page, one records page)');
check(count($records) === 1, 'a ResourceRecordSets with one child reads as a list of one', (string)count($records));
$req = r53_req(1);
check($req && $req->getUri()->getPath() === '/2013-04-01/hostedzone/Z123/rrset',
	'records are read from the public zone, not the private one with the same name');
check($req && strpos($req->getUri()->getQuery(), 'maxitems=300') !== false, 'the record listing asks for 300 per page');

section('Paging: IsTruncated "true" follows the next record');

r53_mock(array(r53_zones(),
	r53_rrsets(r53_set('a.example.com.', 'A', array('192.0.2.1')), 'true',
		'<NextRecordName>b.example.com.</NextRecordName><NextRecordType>A</NextRecordType>'),
	r53_rrsets(r53_set('b.example.com.', 'A', array('192.0.2.2', '192.0.2.3')))));
$records = r53_driver()->listRecords('example.com');
check(count($records) === 3, 'both pages are read, a two-value set giving two records');
$q = r53_req(2) ? r53_req(2)->getUri()->getQuery() : '';
check(strpos($q, 'name=b.example.com.') !== false && strpos($q, 'type=A') !== false,
	'the second page starts at NextRecordName / NextRecordType', $q);

section('Signing');

r53_mock(array(r53_zones()));
r53_driver('SESSIONTOKEN')->zoneFor('example.com');
$req = r53_req(0);
$auth = $req ? $req->getHeaderLine('Authorization') : '';
check(strpos($auth, 'AWS4-HMAC-SHA256 Credential=AKIDTEST/') === 0, 'the request carries a SigV4 Authorization', $auth);
check(strpos($auth, '/us-east-1/route53/aws4_request') !== false, 'signed for route53 in us-east-1');
check(strpos($auth, 'SignedHeaders=host;x-amz-date;x-amz-security-token,') !== false,
	'host, x-amz-date and the session token are signed', $auth);
check($req && $req->getHeaderLine('x-amz-security-token') === 'SESSIONTOKEN', 'the session token is sent');
check($req && $req->getHeaderLine('x-amz-date') !== '', 'x-amz-date is sent');
check($req && $req->getUri()->getHost() === 'route53.amazonaws.com', 'requests go to route53.amazonaws.com');

section('Writing a TXT value: XML-escaped, namespaced');

r53_mock(array(r53_zones(), r53_rrsets(''),
	r53_xml('<ChangeResourceRecordSetsResponse xmlns="' . R53_NS . '"><ChangeInfo><Id>/change/C1</Id><Status>PENDING</Status></ChangeInfo></ChangeResourceRecordSetsResponse>')));
r53_driver()->createRecord('example.com', new DnsRecord(DnsRecord::TYPE_TXT, '_x.example.com', 'v=x a<b & c'));
$req = r53_req(2);
$body = $req ? (string)$req->getBody() : '';
check($req && $req->getMethod() === 'POST', 'a write is a POST');
check(strpos($body, 'xmlns="' . R53_NS . '"') !== false, 'the change request carries the 2013-04-01 namespace');
check(strpos($body, '&lt;') !== false && strpos($body, '&amp;') !== false && strpos($body, '<b') === false,
	'the value is XML-escaped', $body);
$parsed = @simplexml_load_string($body);
check($parsed !== false, 'the change request is well-formed XML');
$sent_value = $parsed ? (string)$parsed->ChangeBatch->Changes->Change->ResourceRecordSet->ResourceRecords->ResourceRecord->Value : '';
check($sent_value === '"v=x a<b & c"',
	'the value reads back whole, quotes included', $sent_value);
check($parsed && (string)$parsed->ChangeBatch->Changes->Change->Action === 'UPSERT', 'a create is an UPSERT');
$auth = $req ? $req->getHeaderLine('Authorization') : '';
check(strpos($auth, 'SignedHeaders=content-type;host;x-amz-date,') !== false, 'a write signs its content-type', $auth);

section('Throttling and refusals');

r53_mock(array(r53_zones(), r53_error('Throttling', 'Rate exceeded'), r53_rrsets('')));
$records = r53_driver()->listRecords('example.com');
check(count($GLOBALS['r53_history']) === 3 && $records === array(), 'a 400 Throttling is retried');

r53_mock(array(r53_zones(), r53_error('PriorRequestNotComplete', 'busy'), r53_rrsets('')));
r53_driver()->listRecords('example.com');
check(count($GLOBALS['r53_history']) === 3, 'a 400 PriorRequestNotComplete is retried');

r53_mock(array(r53_zones(), r53_error('InvalidInput', 'Bad name'), r53_rrsets('')));
$msg = '';
try { r53_driver()->listRecords('example.com'); } catch (DnsProviderException $e) { $msg = $e->getMessage(); }
check($msg === 'Route 53 refused the request (InvalidInput): Bad name', 'another 400 is not retried and keeps the wording', $msg);
check(count($GLOBALS['r53_history']) === 2, 'only one attempt for a plain refusal');

r53_mock(array(r53_error('InvalidClientTokenId', 'The security token included in the request is invalid.', 403)));
$msg = '';
try { r53_driver()->zoneFor('example.com'); } catch (DnsProviderException $e) { $msg = $e->getMessage(); }
check(strpos($msg, 'Route 53 refused the request (InvalidClientTokenId): ') === 0, 'a wrong key reads as a refusal with its code', $msg);

r53_mock(array(r53_zones(), r53_xml('<InvalidChangeBatch xmlns="' . R53_NS . '"><Messages><Message>Tried to delete a set that does not exist</Message></Messages></InvalidChangeBatch>', 400)));
$msg = '';
try { r53_driver()->listRecords('example.com'); } catch (DnsProviderException $e) { $msg = $e->getMessage(); }
check($msg === 'Route 53 refused the request (InvalidChangeBatch): Tried to delete a set that does not exist',
	'an InvalidChangeBatch answer names its messages', $msg);

$src = file_get_contents(PathHelper::getIncludePath('includes/dns/drivers/Route53DnsDriver.php'));

harness_finish();
