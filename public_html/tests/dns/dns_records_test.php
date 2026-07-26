<?php
/** @joinery-test
 * name: dns_records
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * The DNS record vocabulary and the general driver quirks.
 *
 * Each of these is a real defect this platform has produced or could produce:
 * a TXT value over 255 bytes published unquoted (accepted, stored, never
 * served), a record written into a same-named sibling zone, a proxied A record
 * that resolves to the wrong address, and a credential that outlives its
 * publish. None of them needs a live provider to test.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/dns/DnsDriverRegistry.php'));
require_once(PathHelper::getIncludePath('includes/dns/DnsRecordPlan.php'));

// ---------------------------------------------------------------------------
section('The vocabulary refuses what it must never publish');
// ---------------------------------------------------------------------------

foreach (array('A', 'AAAA', 'CNAME', 'MX', 'TXT', 'CAA') as $type) {
	$value = ($type === 'MX' || $type === 'CNAME') ? 'mail.example.com'
		: ($type === 'CAA' ? '0 issue "letsencrypt.org"' : ($type === 'AAAA' ? '2001:db8::1' : '1.2.3.4'));
	$ok = true;
	try { new DnsRecord($type, 'example.com', $value); } catch (Throwable $e) { $ok = false; }
	check($ok, $type . ' is part of the vocabulary');
}

foreach (array('NS', 'SOA', 'SRV', 'DNSKEY') as $type) {
	$refused = false;
	try { new DnsRecord($type, 'example.com', 'ns1.example.com'); }
	catch (DnsRecordException $e) { $refused = true; }
	check($refused, $type . ' can never be expressed in a plan');
}

$refused = false;
try { DnsRecordPlan::fromArray(array('domain' => 'example.com', 'owner' => 'x',
	'records' => array(array('type' => 'NS', 'name' => 'example.com', 'value' => 'ns1.evil.test')))); }
catch (DnsRecordException $e) { $refused = true; }
check($refused, 'a plan rebuilt from a payload cannot smuggle an NS record in');

// ---------------------------------------------------------------------------
section('TXT values are compared canonically, whatever the provider returns');
// ---------------------------------------------------------------------------

$long = str_repeat('k', 400);
$planned = new DnsRecord('TXT', 'sel._domainkey.example.com', $long);

$as_two_quoted = new DnsRecord('TXT', 'sel._domainkey.example.com',
	'"' . substr($long, 0, 255) . '" "' . substr($long, 255) . '"');
check($planned->isSatisfiedBy($as_two_quoted),
	'a value returned as adjacent quoted strings satisfies the same planned record');

$as_joined = new DnsRecord('TXT', 'sel._domainkey.example.com', $long);
check($planned->isSatisfiedBy($as_joined), 'a value returned already joined satisfies it too');

$as_concat = new DnsRecord('TXT', 'sel._domainkey.example.com',
	'"' . substr($long, 0, 255) . '""' . substr($long, 255) . '"');
check($planned->isSatisfiedBy($as_concat), 'quoted strings with no space between them are the same record');

$different = new DnsRecord('TXT', 'sel._domainkey.example.com', str_repeat('j', 400));
check(!$planned->isSatisfiedBy($different), 'a genuinely different value does not satisfy it');

// ---------------------------------------------------------------------------
section('Long TXT values are split into 255-byte character-strings');
// ---------------------------------------------------------------------------

$chunks = DnsDriverBase::txtChunks($long);
check(count($chunks) === 2, 'a 400-byte value becomes two character-strings', 'got ' . count($chunks));
check(strlen($chunks[0]) === 255, 'the first is exactly 255 bytes', 'got ' . strlen($chunks[0]));
check(implode('', $chunks) === $long, 'the chunks reassemble to the original');

$quoted = DnsDriverBase::quoteTxt($long);
check(substr_count($quoted, '"') === 4, 'the wire form is two quoted strings', $quoted);
check(DnsRecord::canonicalValue('TXT', $quoted) === $long, 'and canonicalizes straight back');

$short = 'v=spf1 include:mailgun.org -all';
check(DnsDriverBase::quoteTxt($short) === '"' . $short . '"', 'a short value is one quoted string');

$escaped = DnsDriverBase::quoteTxt('has "quotes" in it');
check(DnsRecord::canonicalValue('TXT', $escaped) === 'has "quotes" in it',
	'embedded quotes survive the round trip', $escaped);

// Every driver either splits an over-length value or declares that the vendor
// does it. A driver doing neither is the exact silent failure this guards.
foreach (DnsDriverRegistry::all() as $key => $class) {
	$driver = new $class(array('access_token' => 'unused'));
	$wire = $driver->txtWireValue($long);
	$handled = $class::txtChunkingIsAutomatic()
		? ($wire === $long)
		: (substr_count($wire, '"') >= 4);
	check($handled, $key . ' handles an over-length TXT value', substr($wire, 0, 60) . '…');

	$short_wire = $driver->txtWireValue($short);
	check(DnsRecord::canonicalValue('TXT', $short_wire) === $short,
		$key . ' round-trips a short TXT value unchanged', $short_wire);
}

// ---------------------------------------------------------------------------
section('Zone resolution is longest-suffix, and cannot hit a sibling');
// ---------------------------------------------------------------------------

$zones = array('example.com' => 'z1', 'sub.example.com' => 'z2', 'example.net' => 'z3');

check(DnsDriverBase::matchZone('mail.example.com', $zones) === 'z1',
	'mail.example.com resolves to the example.com zone');
check(DnsDriverBase::matchZone('a.sub.example.com', $zones) === 'z2',
	'a.sub.example.com prefers the more specific sub.example.com zone');
check(DnsDriverBase::matchZone('example.com', $zones) === 'z1', 'the apex resolves to its own zone');
check(DnsDriverBase::matchZone('example.net', $zones) === 'z3',
	'a same-named sibling TLD resolves to its own zone, not example.com');
check(DnsDriverBase::matchZone('notexample.com', $zones) === null,
	'notexample.com matches nothing — a suffix must fall on a label boundary');
check(DnsDriverBase::matchZone('example.org', $zones) === null,
	'a domain no visible zone covers resolves to nothing at all');

check(DnsDriverBase::relativeName('mail.example.com', 'example.com') === 'mail', 'relative name under a zone');
check(DnsDriverBase::relativeName('example.com', 'example.com') === '@', 'the apex is @ by default');
check(DnsDriverBase::relativeName('example.com', 'example.com', '') === '', 'and empty where a vendor wants that');
check(DnsDriverBase::absoluteName('mail', 'example.com') === 'mail.example.com', 'and back again');
check(DnsDriverBase::absoluteName('@', 'example.com') === 'example.com', 'the apex round-trips');

// ---------------------------------------------------------------------------
section('A domain is configured where its DNS already lives');
// ---------------------------------------------------------------------------

// The box leads with the host the domain actually uses, worked out from its NS
// records. Most vendors assign per-zone nameserver names, so identification is
// by fragment — an exact list would match none of these.
$hosts = array(
	'cloudflare'       => array('chuck.ns.cloudflare.com', 'cortney.ns.cloudflare.com'),
	'route53'          => array('ns-123.awsdns-45.org', 'ns-2000.awsdns-58.co.uk'),
	'google_cloud_dns' => array('ns-cloud-a1.googledomains.com'),
	'azure_dns'        => array('ns1-01.azure-dns.com', 'ns2-01.azure-dns.net'),
	'gandi'            => array('ns-93-a.gandi.net'),
	'godaddy'          => array('ns37.domaincontrol.com', 'ns38.domaincontrol.com'),
	'namecheap'        => array('dns1.registrar-servers.com'),
	'hetzner'          => array('hydrogen.ns.hetzner.com', 'helium.ns.hetzner.de'),
	'porkbun'          => array('curitiba.ns.porkbun.com'),
	'desec'            => array('ns1.desec.io', 'ns2.desec.org'),
	'namecom'          => array('ns1ex.name.com'),
	'vultr'            => array('ns1.vultr.com'),
	'digitalocean'     => array('ns1.digitalocean.com'),
	'dnsimple'         => array('ns1.dnsimple.com', 'ns2.dnsimple-edge.net'),
	'linode'           => array('ns1.linode.com', 'ns2.linode.com'),
);
foreach ($hosts as $expected => $ns) {
	check(DnsDriverRegistry::identifyHost($ns) === $expected,
		'nameservers like ' . $ns[0] . ' identify ' . $expected,
		var_export(DnsDriverRegistry::identifyHost($ns), true));
}

// Every shipped driver is identifiable, or the box can never lead with it.
foreach (DnsDriverRegistry::all() as $key => $class) {
	check(!empty($class::nameserverSuffixes()), $key . ' declares how to recognise its nameservers');
}

check(DnsDriverRegistry::identifyHost(array('ns1.some-small-registrar.test')) === null,
	'a host no driver serves identifies as nothing, rather than guessing');
check(DnsDriverRegistry::identifyHost(array()) === null,
	'and so does a domain that answered with no NS records at all');

// Trailing dots and case come straight off the wire; neither is a difference.
check(DnsDriverRegistry::identifyHost(array('CHUCK.NS.Cloudflare.COM.')) === 'cloudflare',
	'identification is case- and trailing-dot-insensitive');

// ---------------------------------------------------------------------------
section('The box shows nothing when it can do nothing');
// ---------------------------------------------------------------------------

// A domain whose DNS host has no driver here gets the page it has always had:
// the checks below, each carrying its record. An empty box explaining what
// cannot happen would be one more thing to read and no help at all.
require_once(PathHelper::getIncludePath('includes/dns/dns_publish_box.php'));

class DnsBoxProbe {
	public $boxes = array();
	public function begin_box($o) { $this->boxes[] = $o['title']; }
	public function end_box() {}
	public function getFormWriter($id, $o = array()) { return new DnsNullForm(); }
}
class DnsNullForm {
	public function begin_form() { return ''; }
	public function end_form() { return ''; }
	public function __call($m, $a) { return ''; }
}

$plan = new DnsRecordPlan('example.com', 'test');
$plan->addRecord('TXT', 'example.com', 'v=spf1 -all');

$unknown = array('plan' => $plan, 'state' => DnsPublishBox::STATE_UNKNOWN_HOST,
	'domain' => 'example.com', 'return_url' => '/x', 'provider_key' => '', 'provider_label' => '',
	'provider_class' => null, 'provider_options' => array(), 'show_chooser' => false,
	'rows' => array(), 'counts' => array(), 'accounts' => array(), 'live_ns' => array(),
	'detected_key' => '', 'detected_label' => '', 'prerequisite' => '', 'credential_fields' => array());

$probe = new DnsBoxProbe();
ob_start();
dns_publish_box_render($probe, $unknown);
$html = trim(ob_get_clean());
check($html === '' && empty($probe->boxes),
	'an undrivable DNS host renders no box at all, not an empty one',
	strlen($html) . ' bytes, ' . count($probe->boxes) . ' box(es)');

$none = array_merge($unknown, array('plan' => null, 'state' => DnsPublishBox::STATE_NO_PROVIDER));
$probe = new DnsBoxProbe();
ob_start();
dns_publish_box_render($probe, $none);
check(trim(ob_get_clean()) === '' && empty($probe->boxes), 'and neither does a page with no plan');

// ---------------------------------------------------------------------------
section('A TTL the plan never asked for is not a difference');
// ---------------------------------------------------------------------------

$planned = new DnsRecord('A', 'mail.example.com', '1.2.3.4');           // no TTL
$live    = new DnsRecord('A', 'mail.example.com', '1.2.3.4', 86400);
check($planned->isSatisfiedBy($live), 'a zone left on default TTLs shows no permanent diff noise');

$planned_ttl = new DnsRecord('A', 'mail.example.com', '1.2.3.4', 300);
check(!$planned_ttl->isSatisfiedBy($live), 'a TTL the plan DID ask for is compared');

$mx_any = new DnsRecord('MX', 'example.com', 'mail.example.com');
$mx_20  = new DnsRecord('MX', 'example.com', 'mail.example.com', null, 20);
check($mx_any->isSatisfiedBy($mx_20), 'an unstated MX priority accepts whatever is published');
$mx_10 = new DnsRecord('MX', 'example.com', 'mail.example.com', null, 10);
check(!$mx_10->isSatisfiedBy($mx_20), 'a stated MX priority is compared');
check($mx_10->describe() === 'MX example.com → 10 mail.example.com',
	'MX priority reads as its own field, never baked into the value', $mx_10->describe());

// ---------------------------------------------------------------------------
section('Names and values normalize the way DNS means them');
// ---------------------------------------------------------------------------

$upper = new DnsRecord('txt', 'Mail.Example.COM.', 'v=spf1 -all');
check($upper->name === 'mail.example.com', 'names lowercase and lose the trailing dot', $upper->name);
check($upper->type === 'TXT', 'types uppercase');

$cname = new DnsRecord('CNAME', 'a.example.com', 'Target.Example.COM.');
check($cname->value === 'target.example.com', 'hostname targets lose case and the trailing dot', $cname->value);

$caa = DnsDriverBase::parseCaa('0 issue "letsencrypt.org"');
check($caa['flags'] === 0 && $caa['tag'] === 'issue' && $caa['value'] === 'letsencrypt.org',
	'CAA decomposes into flags, tag and value');
check(DnsDriverBase::formatCaa(0, 'issue', 'letsencrypt.org') === '0 issue "letsencrypt.org"',
	'and composes back into the presentation form');

// ---------------------------------------------------------------------------
section('Cloudflare never turns the orange cloud on');
// ---------------------------------------------------------------------------

// A proxied A record makes the world resolve Cloudflare's address instead of
// the server's — mail breaks and the SSL gate never sees the real address. The
// write succeeds and the wrong thing resolves, which is the whole failure mode
// the reconciler exists to end.
$cf = new CloudflareDnsDriver(array('api_token' => 'unused'));
$to_api = new ReflectionMethod('CloudflareDnsDriver', 'toApi');
$to_api->setAccessible(true);

foreach (array(
	array('A', 'mail.example.com', '1.2.3.4'),
	array('AAAA', 'mail.example.com', '2001:db8::1'),
	array('CNAME', 'www.example.com', 'example.com'),
) as $spec) {
	$body = $to_api->invoke($cf, new DnsRecord($spec[0], $spec[1], $spec[2]));
	check(array_key_exists('proxied', $body) && $body['proxied'] === false,
		'a ' . $spec[0] . ' record is written DNS-only', json_encode($body));
}

$txt_body = $to_api->invoke($cf, new DnsRecord('TXT', 'example.com', 'v=spf1 -all'));
check(!array_key_exists('proxied', $txt_body), 'proxying is not asserted on record types that have none');

// ---------------------------------------------------------------------------
section('A driver credential cannot end up at rest');
// ---------------------------------------------------------------------------

$secret = 'tok_' . str_repeat('s', 24);
foreach (DnsDriverRegistry::all() as $key => $class) {
	$driver = new $class(array('access_token' => $secret, 'api_token' => $secret,
		'api_key' => $secret, 'secret_key' => $secret, 'api_secret' => $secret,
		'secret_access_key' => $secret));

	$refused = false;
	try { serialize($driver); } catch (Throwable $e) { $refused = true; }
	check($refused, $key . ' refuses to be serialized');

	check(strpos(print_r($driver, true), $secret) === false,
		$key . ' redacts its credential from print_r output');
	check(strpos(var_export($driver->__debugInfo(), true), $secret) === false,
		$key . ' redacts its credential from debug output');
}

// Nothing in the DNS subsystem may write a credential anywhere. The ownership
// table is the only thing it persists, and its columns are all public facts.
require_once(PathHelper::getIncludePath('data/dns_records_class.php'));
$secretish = array('token', 'secret', 'password', 'credential', 'api_key', 'access');
$leaky = array();
foreach (array_keys(ManagedDnsRecord::$field_specifications) as $column) {
	foreach ($secretish as $word) {
		if (strpos($column, $word) !== false) { $leaky[] = $column; }
	}
}
check(empty($leaky), 'the ownership table has no column that could hold a credential', implode(', ', $leaky));

harness_finish();
