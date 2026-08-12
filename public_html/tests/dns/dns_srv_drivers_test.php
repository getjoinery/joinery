<?php
/** @joinery-test
 * name: dns_srv_drivers
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * Every DNS driver's SRV mapping, pinned in both directions.
 *
 * SRV is the one record type in the vocabulary that vendors genuinely disagree
 * about. The platform stores the whole RDATA — `priority weight port target` —
 * in the record VALUE, and each driver translates that to whatever its vendor
 * models. Three shapes exist in the wild and all three are represented here:
 *
 *   - **value passthrough** (deSEC, Route53, Google Cloud, Gandi, Hetzner):
 *     the vendor takes RDATA verbatim, so there is nothing to translate.
 *   - **the MX shape** (Vultr, DNSimple, Porkbun, name.com): priority in its own
 *     numeric field, `weight port target` in the content string.
 *   - **fully decomposed** (Cloudflare, DigitalOcean, Linode, GoDaddy, Azure,
 *     Namecheap): four separate fields, and for GoDaddy and Namecheap the
 *     service and protocol labels move out of the NAME into fields of their own.
 *
 * Why this file exists: a wrong field mapping does not throw. It writes a record
 * that looks published, reads back as whatever the vendor stored, and resolves
 * to nothing — the exact silent failure that made Joinery Direct undiscoverable
 * on those providers. These checks make a mis-mapping fail here instead.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/dns/DnsDriverRegistry.php'));
require_once(PathHelper::getIncludePath('includes/dns/DnsRecordPlan.php'));

// The record every one of these drivers has to be able to express: Joinery
// Direct's capability record.
const SRV_NAME  = '_joinery._tcp.example.com';
const SRV_VALUE = '0 5 443 direct.example.com';

// ---------------------------------------------------------------------------
section('The shared split helpers');
// ---------------------------------------------------------------------------

check(DnsDriverBase::srvContent(SRV_VALUE) === '5 443 direct.example.com',
	'srvContent drops the priority, which those vendors carry in their own field');
check(DnsDriverBase::srvFromContent('5 443 direct.example.com', 0) === SRV_VALUE,
	'srvFromContent puts it back');
check(DnsDriverBase::srvFromContent(DnsDriverBase::srvContent(SRV_VALUE), 0) === SRV_VALUE,
	'and the two compose back to the canonical RDATA');

// A vendor answering something unparseable must NOT be silently normalised into
// a value that happens to match the plan — that would report a broken record as
// correct, which is the whole failure this file guards.
check(DnsDriverBase::srvFromContent('nonsense', 0) === 'nonsense',
	'an unparseable content string is returned as-is, so the diff shows a difference');

$parsed = DnsDriverBase::parseSrv(SRV_VALUE);
check($parsed['priority'] === 0 && $parsed['weight'] === 5
	&& $parsed['port'] === 443 && $parsed['target'] === 'direct.example.com',
	'parseSrv splits all four fields');

// ---------------------------------------------------------------------------
section('Every registered driver accepts SRV as publishable');
// ---------------------------------------------------------------------------

$drivers = DnsDriverRegistry::all();
check(count($drivers) > 0, 'the registry has drivers to check', (string)count($drivers));

foreach ($drivers as $key => $class) {
	check($class::supportsType(DnsRecord::TYPE_SRV),
		$class::getLabel() . ' can publish an SRV record through its API');
}

// ---------------------------------------------------------------------------
section('The MX-shaped vendors split priority out and put it back');
// ---------------------------------------------------------------------------

/**
 * Call a private/protected method on a driver instance.
 *
 * Reflection rather than a live API: the mapping is the thing under test and it
 * is a pure function of the record, so exercising it directly tests our code
 * where a live call would test the vendor's.
 */
function driver_call($driver, string $method, array $args) {
	$m = new ReflectionMethod(get_class($driver), $method);
	$m->setAccessible(true);
	return $m->invokeArgs($driver, $args);
}

$zone = 'example.com';

// --- Vultr: data = "weight port target", priority its own field -------------
$vultr = new VultrDnsDriver(array('api_key' => 'x'));
$body = driver_call($vultr, 'toApi', array($zone, new DnsRecord(DnsRecord::TYPE_SRV, SRV_NAME, SRV_VALUE)));
check(($body['data'] ?? '') === '5 443 direct.example.com',
	'Vultr sends "weight port target" as data', json_encode($body));
check((int)($body['priority'] ?? -1) === 0, 'and the priority as its own field');
$read = driver_call($vultr, 'toRecord', array($zone, array(
	'type' => 'SRV', 'name' => '_joinery._tcp', 'data' => $body['data'], 'priority' => $body['priority'])));
check($read !== null && $read->value === SRV_VALUE, 'and reads it back as the canonical RDATA',
	$read ? $read->value : 'null');

// --- DNSimple: content = "weight port target" ------------------------------
$dnsimple = new DnsimpleDnsDriver(array('access_token' => 'x'));
$body = driver_call($dnsimple, 'toApi', array($zone, new DnsRecord(DnsRecord::TYPE_SRV, SRV_NAME, SRV_VALUE)));
check(($body['content'] ?? '') === '5 443 direct.example.com',
	'DNSimple sends "weight port target" as content', json_encode($body));
check((int)($body['priority'] ?? -1) === 0, 'and the priority separately');
$read = driver_call($dnsimple, 'toRecord', array($zone, array(
	'type' => 'SRV', 'name' => '_joinery._tcp', 'content' => $body['content'], 'priority' => $body['priority'])));
check($read !== null && $read->value === SRV_VALUE, 'and reads it back canonically');

// --- Porkbun: content + prio ------------------------------------------------
$porkbun = new PorkbunDnsDriver(array('api_key' => 'x', 'secret_key' => 'y'));
$body = driver_call($porkbun, 'toApi', array($zone, new DnsRecord(DnsRecord::TYPE_SRV, SRV_NAME, SRV_VALUE)));
check(($body['content'] ?? '') === '5 443 direct.example.com',
	'Porkbun sends "weight port target" as content', json_encode($body));
check((string)($body['prio'] ?? '') === '0', 'and the priority as prio');

// --- name.com: answer + priority -------------------------------------------
$namecom = new NameComDnsDriver(array('username' => 'u', 'api_token' => 't'));
$body = driver_call($namecom, 'toApi', array($zone, new DnsRecord(DnsRecord::TYPE_SRV, SRV_NAME, SRV_VALUE)));
check(($body['answer'] ?? '') === '5 443 direct.example.com',
	'name.com sends "weight port target" as answer', json_encode($body));
check((int)($body['priority'] ?? -1) === 0, 'and the priority separately');

// ---------------------------------------------------------------------------
section('The fully-decomposed vendors carry four fields');
// ---------------------------------------------------------------------------

// --- Cloudflare: a data{} object -------------------------------------------
$cf = new CloudflareDnsDriver(array('api_token' => 'x'));
$body = driver_call($cf, 'toApi', array(new DnsRecord(DnsRecord::TYPE_SRV, SRV_NAME, SRV_VALUE)));
$data = (array)($body['data'] ?? array());
check((int)($data['priority'] ?? -1) === 0 && (int)($data['weight'] ?? -1) === 5
	&& (int)($data['port'] ?? -1) === 443 && ($data['target'] ?? '') === 'direct.example.com',
	'Cloudflare sends SRV as a four-field data object', json_encode($body));
$read = driver_call($cf, 'toRecord', array(array(
	'type' => 'SRV', 'name' => SRV_NAME, 'data' => $data, 'content' => 'ignored')));
check($read !== null && $read->value === SRV_VALUE,
	'and rebuilds the RDATA from those fields rather than trusting the rendered content');

// --- DigitalOcean: data = target, with siblings ------------------------------
$do = new DigitalOceanDnsDriver(array('api_token' => 'x'));
$body = driver_call($do, 'toApi', array($zone, new DnsRecord(DnsRecord::TYPE_SRV, SRV_NAME, SRV_VALUE)));
check(rtrim((string)($body['data'] ?? ''), '.') === 'direct.example.com'
	&& (int)($body['priority'] ?? -1) === 0 && (int)($body['weight'] ?? -1) === 5
	&& (int)($body['port'] ?? -1) === 443,
	'DigitalOcean sends the target as data with priority/weight/port beside it', json_encode($body));
$read = driver_call($do, 'toRecord', array($zone, array(
	'type' => 'SRV', 'name' => '_joinery._tcp', 'data' => 'direct.example.com',
	'priority' => 0, 'weight' => 5, 'port' => 443)));
check($read !== null && $read->value === SRV_VALUE, 'and reads it back canonically');

// --- Linode: service/protocol fields, underscore-free, name empty -----------
$linode = new LinodeDnsDriver(array('api_token' => 'x'));
$body = driver_call($linode, 'toApi', array($zone, new DnsRecord(DnsRecord::TYPE_SRV, SRV_NAME, SRV_VALUE)));
check(($body['target'] ?? '') === 'direct.example.com' && (int)($body['priority'] ?? -1) === 0
	&& (int)($body['weight'] ?? -1) === 5 && (int)($body['port'] ?? -1) === 443,
	'Linode sends the target with priority/weight/port beside it', json_encode($body));
// Linode requires service and protocol as their own fields and prepends the
// underscore itself, so they are sent bare — omitting them is a record Linode
// files at the wrong service and the capability record never resolves.
check(($body['service'] ?? null) === 'joinery' && ($body['protocol'] ?? null) === 'tcp',
	'Linode carries the service and protocol as underscore-free fields', json_encode($body));
check(($body['name'] ?? null) === '',
	'and leaves name empty, since Linode ignores it for SRV', json_encode($body));
// And a row Linode hands back — name empty, labels in their own fields — is
// reassembled into the whole name rather than reading as the bare apex.
$read = driver_call($linode, 'toRecord', array($zone, array(
	'type' => 'SRV', 'name' => '', 'service' => 'joinery', 'protocol' => 'tcp',
	'target' => 'direct.example.com', 'priority' => 0, 'weight' => 5, 'port' => 443)));
check($read !== null && $read->name === SRV_NAME && $read->value === SRV_VALUE,
	'and reads one back as the whole name and canonical RDATA',
	$read ? ($read->name . ' / ' . $read->value) : 'null');

// --- GoDaddy: service and protocol leave the NAME and become fields ---------
$gd = new GoDaddyDnsDriver(array('api_key' => 'k', 'api_secret' => 's'));
// writeRrset performs a live request, so the mapping is asserted through the
// pure helpers it builds its payload from instead.
list($service, $protocol) = driver_call($gd, 'srvLabels', array(SRV_NAME));
check($service === '_joinery' && $protocol === '_tcp',
	'GoDaddy splits the service and protocol labels out of the record name');
check(driver_call($gd, 'recordPathName', array(SRV_NAME, $zone, DnsRecord::TYPE_SRV)) === '@',
	'and addresses the record at the apex, since those labels travel as fields');
check(driver_call($gd, 'srvName', array(
		array('service' => '_joinery', 'protocol' => '_tcp', 'name' => '@'), $zone)) === SRV_NAME,
	'and puts the whole name back together on the way in');

// --- Azure: a typed SRVRecords array ----------------------------------------
$azure = new AzureDnsDriver(array('access_token' => 'x', 'subscription_id' => 's', 'resource_group' => 'g'));
$records = driver_call($azure, 'azureRecords', array(DnsRecord::TYPE_SRV, array(SRV_VALUE)));
$first = (array)(($records['SRVRecords'] ?? array())[0] ?? array());
check((int)($first['priority'] ?? -1) === 0 && (int)($first['weight'] ?? -1) === 5
	&& (int)($first['port'] ?? -1) === 443 && ($first['target'] ?? '') === 'direct.example.com',
	'Azure sends SRV as its typed four-field record', json_encode($records));
$values = driver_call($azure, 'valuesFromProperties',
	array(DnsRecord::TYPE_SRV, array('SRVRecords' => array($first))));
// The set-value carries the absolute (dotted) target, the one spelling the
// rrset base compares on both sides; the record model strips it back to the
// canonical RDATA when the diff is built.
check(($values[0] ?? '') === SRV_VALUE . '.', 'and reads it back as the dotted set-value', json_encode($values));

// --- Namecheap: an entirely separate list -----------------------------------
$nc = new NamecheapDnsDriver(array('api_user' => 'u', 'api_key' => 'k', 'username' => 'u', 'client_ip' => '1.2.3.4'));
$row = driver_call($nc, 'toSrvRow', array($zone, new DnsRecord(DnsRecord::TYPE_SRV, SRV_NAME, SRV_VALUE)));
check($row['Service'] === '_joinery' && $row['Protocol'] === '_tcp'
	&& (int)$row['Priority'] === 0 && (int)$row['Weight'] === 5
	&& (int)$row['Port'] === 443 && $row['Target'] === 'direct.example.com',
	'Namecheap builds its six-field SRV row', json_encode($row));
$back = driver_call($nc, 'srvRecord', array($zone, $row, 0));
check($back->name === SRV_NAME && $back->value === SRV_VALUE,
	'and reads one back as the same name and RDATA', $back->name . ' / ' . $back->value);
check(strpos($back->provider_id, 'srv:') === 0,
	'an SRV row is tagged so its list position can never be confused with a host-list index');

// Namecheap's SRV row has no host field, so a sub-host SRV would be written to
// the apex silently. It is refused instead.
$sub_threw = false;
try {
	driver_call($nc, 'toSrvRow', array($zone,
		new DnsRecord(DnsRecord::TYPE_SRV, '_joinery._tcp.sub.example.com', SRV_VALUE)));
} catch (DnsProviderException $e) {
	$sub_threw = true;
}
check($sub_threw, 'a sub-host SRV Namecheap cannot express is refused, not written to the apex');

// Namecheap's write replaces the ENTIRE SRV list, so a create must never build
// that replacement from a list it could not read — doing so would delete every
// SRV record the failed read did not return. A failed read fails the write.
$nc_err = '<?xml version="1.0"?><ApiResponse Status="ERROR"><Errors>'
	. '<Error Number="1">temporary failure</Error></Errors></ApiResponse>';
$nc_mock = new \GuzzleHttp\Handler\MockHandler(array(
	new \GuzzleHttp\Psr7\Response(200, array(), $nc_err),                          // the failed getsrvrecords read
	new \GuzzleHttp\Psr7\Response(200, array(), '<ApiResponse Status="OK"/>'),     // a write, if one were wrongly issued
));
$nc_client = new \GuzzleHttp\Client(array('handler' => \GuzzleHttp\HandlerStack::create($nc_mock)));
$nc2 = new NamecheapDnsDriver(array('api_user' => 'u', 'api_key' => 'k', 'username' => 'u', 'client_ip' => '1.2.3.4'), $nc_client);
$read_failed = false;
try {
	$nc2->createRecord($zone, new DnsRecord(DnsRecord::TYPE_SRV, SRV_NAME, SRV_VALUE));
} catch (DnsProviderException $e) {
	$read_failed = true;
}
check($read_failed, 'a create refuses when the SRV list cannot be read', $read_failed ? 'threw' : 'did not throw');
check($nc_mock->count() === 1,
	'and it never issued the whole-list write that would have wiped the unread records',
	'responses left: ' . $nc_mock->count());

// ---------------------------------------------------------------------------
section('The set-value SRV target is absolute, so a passthrough vendor cannot re-append the zone');
// ---------------------------------------------------------------------------

// Gandi, deSEC, Route 53 and Google Cloud take the RDATA string verbatim. A
// target without a trailing dot reads as relative: Gandi appends the zone
// (direct.example.com.example.com), deSEC and Google Cloud reject it. The one
// set-value form the rrset base produces has to be absolute for all of them.
foreach (array('gandi' => 'GandiDnsDriver', 'desec' => 'DesecDnsDriver',
		'route53' => 'Route53DnsDriver', 'gcloud' => 'GoogleCloudDnsDriver') as $label => $class) {
	if (!class_exists($class)) { continue; }
	$ref = new ReflectionClass($class);
	$driver = $ref->newInstanceWithoutConstructor();
	$value = driver_call($driver, 'rrsetValue', array(new DnsRecord(DnsRecord::TYPE_SRV, SRV_NAME, SRV_VALUE)));
	check($value === '0 5 443 direct.example.com.',
		$label . ' sends the SRV RDATA with an absolute target', $value);
}

// Hetzner is passthrough too, but a per-record API rather than a record-set one,
// so it dots the target in its own write body.
$hetzner = new HetznerDnsDriver(array('api_token' => 'x'));
$body = driver_call($hetzner, 'toApi', array($zone, new DnsRecord(DnsRecord::TYPE_SRV, SRV_NAME, SRV_VALUE)));
check(($body['value'] ?? '') === '0 5 443 direct.example.com.',
	'Hetzner sends the SRV RDATA with an absolute target', json_encode($body));

// ---------------------------------------------------------------------------
section('GoDaddy publishes one SRV service without destroying the host\'s others');
// ---------------------------------------------------------------------------

// GoDaddy addresses SRV by host, so `_sip._tcp` and `_joinery._tcp` share the
// apex address and a whole-host PUT replaces both. These checks drive the real
// read-modify-write through a mocked transport and read back the PUT body, which
// is the only place the sibling either survives or is silently erased.

/** A GoDaddy driver whose Guzzle returns the queued responses in order. */
function godaddy_with(array $responses, &$mock) {
	$mock = new \GuzzleHttp\Handler\MockHandler($responses);
	$stack = \GuzzleHttp\HandlerStack::create($mock);
	$client = new \GuzzleHttp\Client(array('handler' => $stack));
	return new GoDaddyDnsDriver(array('api_key' => 'k', 'api_secret' => 's'), $client);
}
function godaddy_srv_row(string $service, string $target, int $port): array {
	return array('type' => 'SRV', 'name' => '@', 'service' => $service, 'protocol' => '_tcp',
		'data' => $target, 'priority' => 0, 'weight' => 5, 'port' => $port, 'ttl' => 600);
}
function godaddy_json(array $rows): \GuzzleHttp\Psr7\Response {
	return new \GuzzleHttp\Psr7\Response(200, array('Content-Type' => 'application/json'), json_encode($rows));
}

$sip = godaddy_srv_row('_sip', 'sip.example.com', 5060);

// Publish _joinery._tcp into an apex that already carries _sip._tcp. The base
// reads (GET), then writeRrset reads the host again for siblings (GET), then PUTs.
$mock = null;
$gd = godaddy_with(array(
	godaddy_json(array($sip)),   // readRrset: this service absent, so a create
	godaddy_json(array($sip)),   // srvSiblingEntries: _sip is the sibling to keep
	new \GuzzleHttp\Psr7\Response(200, array(), ''),   // the PUT
), $mock);
$gd->createRecord($zone, new DnsRecord(DnsRecord::TYPE_SRV, SRV_NAME, SRV_VALUE));
$put = json_decode((string)$mock->getLastRequest()->getBody(), true);
$by_service = array();
foreach ((array)$put as $entry) { $by_service[(string)($entry['service'] ?? '')] = $entry; }
check(isset($by_service['_sip']) && rtrim((string)$by_service['_sip']['data'], '.') === 'sip.example.com',
	'publishing _joinery keeps _sip._tcp in the written set', json_encode($put));
check(isset($by_service['_joinery']) && (int)$by_service['_joinery']['port'] === 443,
	'and writes _joinery._tcp beside it, not over it', json_encode($put));

// Deleting our service when a sibling remains re-writes the host WITHOUT ours,
// rather than issuing a DELETE that would take _sip with it.
$live = new DnsRecord(DnsRecord::TYPE_SRV, SRV_NAME, SRV_VALUE);
$mock = null;
$gd = godaddy_with(array(
	godaddy_json(array($sip, godaddy_srv_row('_joinery', 'direct.example.com', 443))), // readRrset (scoped to _joinery)
	godaddy_json(array($sip, godaddy_srv_row('_joinery', 'direct.example.com', 443))), // srvSiblingEntries
	new \GuzzleHttp\Psr7\Response(200, array(), ''),   // the PUT of siblings only
), $mock);
$gd->deleteRecord($zone, $live);
$last = $mock->getLastRequest();
check($last->getMethod() === 'PUT',
	'removing our service with a sibling present re-writes the set rather than deleting the host',
	$last->getMethod());
$put = json_decode((string)$last->getBody(), true);
$services = array_map(function ($e) { return (string)($e['service'] ?? ''); }, (array)$put);
check(in_array('_sip', $services, true) && !in_array('_joinery', $services, true),
	'the re-written set keeps _sip and drops _joinery', json_encode($services));

// When ours is the only service, the host's SRV set is deleted outright.
$mock = null;
$gd = godaddy_with(array(
	godaddy_json(array(godaddy_srv_row('_joinery', 'direct.example.com', 443))), // readRrset
	godaddy_json(array(godaddy_srv_row('_joinery', 'direct.example.com', 443))), // srvSiblingEntries: none but ours
	new \GuzzleHttp\Psr7\Response(200, array(), ''),   // the DELETE
), $mock);
$gd->deleteRecord($zone, new DnsRecord(DnsRecord::TYPE_SRV, SRV_NAME, SRV_VALUE));
check($mock->getLastRequest()->getMethod() === 'DELETE',
	'with no sibling left, the host SRV set is deleted', $mock->getLastRequest()->getMethod());

harness_finish();
