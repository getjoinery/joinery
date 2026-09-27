<?php
/** @joinery-test
 * name: dns_hetzner_driver
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * The Hetzner DNS driver against the Hetzner Cloud API, over a mocked transport.
 *
 * Hetzner stores record SETS — one (name, type) holding a list of values and a
 * TTL — so the failure worth pinning is the silent one: publishing or changing
 * one value of a multi-value set and writing back a set that has lost its
 * siblings. Every write here is read back from the request the driver actually
 * sent. No network is touched.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/dns/DnsDriverRegistry.php'));

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/** A Hetzner driver that records its waits instead of sleeping. */
class HetznerDnsDriverUnderTest extends HetznerDnsDriver {
	public $pauses = array();
	protected function pause(int $seconds): void { $this->pauses[] = $seconds; }
}

/**
 * A driver whose transport answers $responses in order; $history fills with
 * every request it sent and $mock is left holding whatever went unanswered.
 */
function hetzner_with(array $responses, &$history, &$mock) {
	$history = array();
	$mock = new MockHandler($responses);
	$stack = HandlerStack::create($mock);
	$stack->push(Middleware::history($history));
	return new HetznerDnsDriverUnderTest(array('api_token' => 'test-token'), new Client(array('handler' => $stack)));
}

function hz_json(array $body, int $status = 200): Response {
	return new Response($status, array('Content-Type' => 'application/json'), json_encode($body));
}

function hz_zones(array $zones, ?int $next_page = null): Response {
	return hz_json(array('zones' => $zones, 'meta' => array('pagination' => array(
		'page' => 1, 'per_page' => 50, 'previous_page' => null, 'next_page' => $next_page,
		'last_page' => null, 'total_entries' => null))));
}

function hz_zone(int $id, string $name): array {
	return array('id' => $id, 'name' => $name, 'mode' => 'primary', 'authoritative_nameservers' => array(
		'assigned' => array('hydrogen.ns.hetzner.com.', 'oxygen.ns.hetzner.com.', 'helium.ns.hetzner.de.')));
}

function hz_rrsets(array $rrsets, ?int $next_page = null): Response {
	return hz_json(array('rrsets' => $rrsets, 'meta' => array('pagination' => array(
		'page' => 1, 'per_page' => 100, 'previous_page' => null, 'next_page' => $next_page,
		'last_page' => null, 'total_entries' => null))));
}

function hz_rrset(string $name, string $type, array $values, ?int $ttl = 3600): array {
	$records = array();
	foreach ($values as $v) { $records[] = array('value' => $v, 'comment' => ''); }
	return array('id' => $name . '/' . $type, 'name' => $name, 'type' => $type, 'ttl' => $ttl,
		'labels' => array(), 'protection' => array('change' => false), 'records' => $records, 'zone' => 42);
}

function hz_action(string $status = 'success', int $id = 7, ?array $error = null): Response {
	return hz_json(array('action' => array('id' => $id, 'command' => 'set_records', 'status' => $status,
		'progress' => $status === 'success' ? 100 : 0, 'resources' => array(), 'error' => $error,
		'started' => '2026-09-27T00:00:00Z', 'finished' => null)), 201);
}

function hz_error(int $status, string $code, string $message): Response {
	return hz_json(array('error' => array('code' => $code, 'message' => $message, 'details' => (object)array())), $status);
}

/** The request at $i as [METHOD, path+query, decoded JSON body]. */
function hz_req(array $history, int $i): array {
	$r = $history[$i]['request'];
	$uri = $r->getUri();
	return array($r->getMethod(), $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : ''),
		json_decode((string)$r->getBody(), true));
}

function hz_values(?array $body): array {
	$out = array();
	foreach ((array)($body['records'] ?? array()) as $row) { $out[] = (string)($row['value'] ?? ''); }
	sort($out);
	return $out;
}

$zone = 'example.com';
$zone_list = hz_zones(array(hz_zone(42, 'example.com')));

// ---------------------------------------------------------------------------
section('The credential is a Hetzner Cloud project token');
// ---------------------------------------------------------------------------

check(DnsDriverRegistry::get('hetzner') === 'HetznerDnsDriver', 'the driver is registered as hetzner');
$field = HetznerDnsDriver::credentialFields()['api_token'] ?? array();
check(stripos((string)($field['label'] ?? ''), 'Hetzner Cloud API token') !== false,
	'the field asks for a Hetzner Cloud API token', (string)($field['label'] ?? ''));
check(stripos((string)($field['help'] ?? ''), 'Read & Write') !== false
	&& stripos((string)($field['help'] ?? ''), 'project') !== false,
	'and says it needs Read & Write in the project that holds the zone', (string)($field['help'] ?? ''));
$guide = HetznerDnsDriver::credentialGuide();
check(strpos((string)$guide['url'], 'https://console.hetzner.com') === 0,
	'the guide opens the Hetzner Console', (string)$guide['url']);
check(stripos(implode(' ', $guide['steps']), 'Read & Write') !== false, 'and its steps choose Read & Write');

$d = hetzner_with(array($zone_list), $history, $mock);
check($d->zoneFor('mail.example.com') === 'example.com', 'a sub-host resolves to its zone');
list($method, $path) = hz_req($history, 0);
$sent = $history[0]['request'];
check($sent->getUri()->getHost() === 'api.hetzner.cloud' && strpos($path, '/v1/zones') === 0,
	'zones are listed from the Cloud API', $sent->getUri()->getHost() . $path);
check($sent->getHeaderLine('Authorization') === 'Bearer test-token', 'the token travels as a Bearer credential');
check($sent->getHeaderLine('Auth-API-Token') === '', 'and never in a DNS Console header');

// ---------------------------------------------------------------------------
section('Zones page through, and each carries its assigned nameservers');
// ---------------------------------------------------------------------------

$d = hetzner_with(array(
	hz_zones(array(hz_zone(1, 'other.test')), 2),
	hz_zones(array(hz_zone(42, 'example.com'))),
), $history, $mock);
check($d->zoneFor('example.com') === 'example.com', 'a zone on the second page is found');
check(count($history) === 2 && strpos(hz_req($history, 1)[1], 'page=2') !== false,
	'by following next_page', hz_req($history, count($history) - 1)[1]);
check($d->zoneNameservers('example.com') === array('hydrogen.ns.hetzner.com', 'oxygen.ns.hetzner.com', 'helium.ns.hetzner.de'),
	'the handover names the zone\'s assigned nameservers without trailing dots',
	json_encode($d->zoneNameservers('example.com')));
check($d->zoneFor('notexample.com') === null, 'a same-suffix sibling domain is not matched');

$d = hetzner_with(array(hz_zones(array())), $history, $mock);
$threw = false;
try { $d->listRecords('example.com'); } catch (DnsZoneNotFoundException $e) { $threw = true; }
check($threw, 'a zone the token cannot see is reported as not found');

// ---------------------------------------------------------------------------
section('Records are read out of their sets in the platform\'s spelling');
// ---------------------------------------------------------------------------

$d = hetzner_with(array(
	$zone_list,
	hz_rrsets(array(
		hz_rrset('@', 'SOA', array('hydrogen.ns.hetzner.com. dns.hetzner.com. 2026092701 86400 10800 3600000 3600')),
		hz_rrset('@', 'NS', array('hydrogen.ns.hetzner.com.')),
		hz_rrset('@', 'MX', array('10 mail.example.com.', '20 backup.example.com.'), 300),
		hz_rrset('@', 'TXT', array('"v=spf1 mx -all"'), null),
	), 2),
	hz_rrsets(array(
		hz_rrset('_joinery._tcp', 'SRV', array('0 5 443 direct.example.com.')),
		hz_rrset('www', 'CNAME', array('example.com.')),
	)),
), $history, $mock);
$records = $d->listRecords($zone);
$by = array();
foreach ($records as $r) { $by[] = $r->type . ' ' . $r->name . ' ' . ($r->priority !== null ? $r->priority . ' ' : '') . $r->value; }
sort($by);
check(!in_array('NS', array_map(function ($r) { return $r->type; }, $records), true)
	&& !in_array('SOA', array_map(function ($r) { return $r->type; }, $records), true),
	'NS and SOA are never surfaced', json_encode($by));
check(in_array('MX example.com 10 mail.example.com', $by, true) && in_array('MX example.com 20 backup.example.com', $by, true),
	'a two-value MX set reads as two records with their priorities split out', json_encode($by));
check(in_array('TXT example.com v=spf1 mx -all', $by, true), 'a quoted TXT value reads unquoted', json_encode($by));
check(in_array('SRV _joinery._tcp.example.com 0 5 443 direct.example.com', $by, true),
	'SRV reads as the canonical RDATA from the second page', json_encode($by));
check(in_array('CNAME www.example.com example.com', $by, true), 'a CNAME loses its trailing dot', json_encode($by));
$ttls = array();
foreach ($records as $r) { $ttls[$r->type] = $r->ttl; }
check($ttls['MX'] === 300 && $ttls['TXT'] === null,
	'the set TTL is carried, and a set on the zone default reads as no TTL', json_encode($ttls));

// ---------------------------------------------------------------------------
section('Publishing into an existing set keeps the values already there');
// ---------------------------------------------------------------------------

$existing_txt = hz_rrsets(array(hz_rrset('@', 'TXT', array('"google-site-verification=abc"', '"v=spf1 -all"'))));
$d = hetzner_with(array($zone_list, $existing_txt, $existing_txt, hz_action()), $history, $mock);
$d->createRecord($zone, new DnsRecord('TXT', 'example.com', 'joinery-verify=xyz'));
list($method, $path, $body) = hz_req($history, count($history) - 1);
check($method === 'POST' && substr($path, -strlen('/rrsets/%40/TXT/actions/set_records')) === '/rrsets/%40/TXT/actions/set_records',
	'an existing set is written with set_records at the apex', $method . ' ' . $path);
check(hz_values($body) === array('"google-site-verification=abc"', '"joinery-verify=xyz"', '"v=spf1 -all"'),
	'the new value is written beside both siblings, quoted', json_encode(hz_values($body)));
check(strpos(hz_req($history, 1)[1], 'name=%40') !== false && strpos(hz_req($history, 1)[1], 'type=TXT') !== false,
	'the set is read through the filtered listing, so absent is an empty list and not a 404', hz_req($history, 1)[1]);

// ---------------------------------------------------------------------------
section('Changing one value of a multi-value set replaces only that value');
// ---------------------------------------------------------------------------

$mx = hz_rrsets(array(hz_rrset('@', 'MX', array('10 mail.example.com.', '20 backup.example.com.'), 3600)));
$d = hetzner_with(array($zone_list, $mx, $mx, hz_action()), $history, $mock);
$d->updateRecord($zone,
	new DnsRecord('MX', 'example.com', 'mail.example.com', 3600, 10),
	new DnsRecord('MX', 'example.com', 'mx.newhost.example', null, 10));
list($method, $path, $body) = hz_req($history, count($history) - 1);
check(hz_values($body) === array('10 mx.newhost.example.', '20 backup.example.com.'),
	'the backup MX survives and the primary is replaced', json_encode(hz_values($body)));
check(count($history) === 4 && $mock->count() === 0,
	'with no TTL asked for, the set keeps its own and no change_ttl is sent', (string)count($history));

// A plan that asks for a different TTL sets it after the values.
$d = hetzner_with(array($zone_list, $mx, $mx, hz_action(), hz_action()), $history, $mock);
$d->updateRecord($zone,
	new DnsRecord('MX', 'example.com', 'mail.example.com', 3600, 10),
	new DnsRecord('MX', 'example.com', 'mail.example.com', 300, 10));
list($method, $path, $body) = hz_req($history, count($history) - 1);
check(substr($path, -strlen('/actions/change_ttl')) === '/actions/change_ttl' && (int)($body['ttl'] ?? 0) === 300,
	'a TTL change is sent as change_ttl', $path . ' ' . json_encode($body));

// ---------------------------------------------------------------------------
section('Removing a value keeps its siblings; removing the last deletes the set');
// ---------------------------------------------------------------------------

$d = hetzner_with(array($zone_list, $mx, $mx, hz_action()), $history, $mock);
$d->deleteRecord($zone, new DnsRecord('MX', 'example.com', 'backup.example.com', null, 20));
list($method, $path, $body) = hz_req($history, count($history) - 1);
check($method === 'POST' && substr($path, -strlen('/set_records')) === '/set_records'
	&& hz_values($body) === array('10 mail.example.com.'),
	'one MX of two is removed by rewriting the set with the other', $method . ' ' . json_encode(hz_values($body)));

$one = hz_rrsets(array(hz_rrset('_joinery._tcp', 'SRV', array('0 5 443 direct.example.com.'))));
$d = hetzner_with(array($zone_list, $one, hz_action()), $history, $mock);
$d->deleteRecord($zone, new DnsRecord('SRV', '_joinery._tcp.example.com', '0 5 443 direct.example.com'));
list($method, $path) = hz_req($history, count($history) - 1);
check($method === 'DELETE' && substr($path, -strlen('/zones/42/rrsets/_joinery._tcp/SRV')) === '/zones/42/rrsets/_joinery._tcp/SRV',
	'the last value goes by deleting the whole set', $method . ' ' . $path);

// ---------------------------------------------------------------------------
section('A new set is created with its name relative to the zone');
// ---------------------------------------------------------------------------

$none = hz_rrsets(array());
$d = hetzner_with(array($zone_list, $none, $none, hz_json(array('rrset' => hz_rrset('mail', 'A', array('203.0.113.9'), 60),
	'action' => array('id' => 9, 'status' => 'success')), 201)), $history, $mock);
$d->createRecord($zone, new DnsRecord('A', 'mail.example.com', '203.0.113.9', 30));
list($method, $path, $body) = hz_req($history, count($history) - 1);
check($method === 'POST' && substr($path, -strlen('/zones/42/rrsets')) === '/zones/42/rrsets',
	'an absent set is created on the RRSet collection', $method . ' ' . $path);
check(($body['name'] ?? '') === 'mail' && ($body['type'] ?? '') === 'A' && hz_values($body) === array('203.0.113.9'),
	'with a relative name, its type and the one value', json_encode($body));
check((int)($body['ttl'] ?? 0) === 60, 'a TTL under Hetzner\'s 60-second floor is raised to it', json_encode($body));

$d = hetzner_with(array($zone_list, $none, $none, hz_action()), $history, $mock);
$d->createRecord($zone, new DnsRecord('MX', 'example.com', 'mail.example.com', null, 10));
list($method, $path, $body) = hz_req($history, count($history) - 1);
check(($body['name'] ?? '') === '@' && !array_key_exists('ttl', (array)$body)
	&& hz_values($body) === array('10 mail.example.com.'),
	'an apex MX is named @, carries its priority and absolute target, and leaves TTL to the zone', json_encode($body));

// ---------------------------------------------------------------------------
section('A write waits for its Action');
// ---------------------------------------------------------------------------

$d = hetzner_with(array($zone_list, $none, $none,
	hz_action('running', 77),
	hz_json(array('action' => array('id' => 77, 'status' => 'running'))),
	hz_json(array('action' => array('id' => 77, 'status' => 'success'))),
), $history, $mock);
$d->createRecord($zone, new DnsRecord('A', 'www.example.com', '203.0.113.9'));
check($mock->count() === 0 && substr(hz_req($history, count($history) - 1)[1], -strlen('/v1/zones/actions/77')) === '/v1/zones/actions/77',
	'a running Action is polled until it succeeds', hz_req($history, count($history) - 1)[1]);
check($d->pauses === array(1, 1), 'one second between polls', json_encode($d->pauses));

$d = hetzner_with(array($zone_list, $none, $none,
	hz_action('error', 78, array('code' => 'invalid_input', 'message' => 'record value is invalid')),
), $history, $mock);
$msg = '';
try { $d->createRecord($zone, new DnsRecord('A', 'www.example.com', '203.0.113.9')); }
catch (DnsProviderException $e) { $msg = $e->getMessage(); }
check(strpos($msg, 'record value is invalid') !== false, 'an Action that fails is reported with Hetzner\'s reason', $msg);

$responses = array($zone_list, $none, $none, hz_action('running', 79));
for ($i = 0; $i < HetznerDnsDriver::ACTION_MAX_POLLS; $i++) {
	$responses[] = hz_json(array('action' => array('id' => 79, 'status' => 'running')));
}
$d = hetzner_with($responses, $history, $mock);
$msg = '';
try { $d->createRecord($zone, new DnsRecord('A', 'www.example.com', '203.0.113.9')); }
catch (DnsProviderException $e) { $msg = $e->getMessage(); }
check(stripos($msg, 'still applying') !== false && count($d->pauses) === HetznerDnsDriver::ACTION_MAX_POLLS,
	'an Action that outlasts the wait is said plainly rather than waited on forever', $msg);

// ---------------------------------------------------------------------------
section('A set that cannot be read is never overwritten');
// ---------------------------------------------------------------------------

$d = hetzner_with(array($zone_list, hz_error(500, 'server_error', 'backend error'), hz_action()), $history, $mock);
$threw = false;
try { $d->createRecord($zone, new DnsRecord('TXT', 'example.com', 'v=spf1 -all')); }
catch (DnsProviderException $e) { $threw = true; }
check($threw && $mock->count() === 1, 'a failed read stops the write that would have replaced the unread values',
	'responses left: ' . $mock->count());

// ---------------------------------------------------------------------------
section('Hetzner\'s refusals are said in the operator\'s terms');
// ---------------------------------------------------------------------------

$d = hetzner_with(array(hz_error(401, 'unauthorized', 'unable to authenticate')), $history, $mock);
$msg = '';
try { $d->zoneFor('example.com'); } catch (DnsProviderException $e) { $msg = $e->getMessage(); }
check(stripos($msg, 'Hetzner Cloud API token') !== false, 'a rejected token says which token is needed', $msg);

$d = hetzner_with(array($zone_list, $mx, $mx, hz_error(401, 'token_readonly', 'token is read-only')), $history, $mock);
$msg = '';
try { $d->deleteRecord($zone, new DnsRecord('MX', 'example.com', 'backup.example.com', null, 20)); }
catch (DnsProviderException $e) { $msg = $e->getMessage(); }
check(stripos($msg, 'Read & Write') !== false, 'a read-only token is told it needs Read & Write', $msg);

$d = hetzner_with(array($zone_list, $mx, $mx, hz_error(423, 'protected', 'rrset is protected')), $history, $mock);
$caught = null;
try { $d->deleteRecord($zone, new DnsRecord('MX', 'example.com', 'backup.example.com', null, 20)); }
catch (DnsProviderException $e) { $caught = $e; }
check($caught instanceof DnsManagedRecordException && $caught->getFeature() === 'Hetzner record protection',
	'a protected set names the protection to turn off', $caught ? get_class($caught) . ': ' . $caught->getMessage() : 'none');

$d = hetzner_with(array($zone_list, hz_error(422, 'incorrect_zone_mode', 'not supported for this zone mode')), $history, $mock);
$msg = '';
try { $d->listRecords($zone); } catch (DnsProviderException $e) { $msg = $e->getMessage(); }
check(stripos($msg, 'secondary mode') !== false, 'a secondary zone is explained, not reported as a bare 422', $msg);

// Every request any case above sent went to the Cloud API.
check(strpos(HetznerDnsDriver::API_BASE, 'https://api.hetzner.cloud/v1/') === 0,
	'the driver speaks only to api.hetzner.cloud', HetznerDnsDriver::API_BASE);

harness_finish();
