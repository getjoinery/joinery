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
 * @version 1.3 - the action column's vocabulary and colour grading
 * @version 1.2 - a skipped record is never green, and Apply is gated while the
 *                selection would publish nothing
 * @version 1.1 - rate-limit handling: retry budget, write safety, the operator
 *                message, and that the credential never reaches the log
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/dns/DnsDriverRegistry.php'));
require_once(PathHelper::getIncludePath('includes/dns/DnsRecordPlan.php'));

// FormWriter's CSRF setup starts a session, and PHP cannot start one after the
// first byte of output. The rendering section at the end of this file builds a
// FormWriter, so the session is established here — before any check prints —
// rather than warning its way through the run.
if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

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

// ---------------------------------------------------------------------------
section('Every credential a driver asks for says where to get it');
// ---------------------------------------------------------------------------

// A field asking for an API token with no guide is the friction this exists to
// remove, so an API driver without one fails here rather than shipping quietly.
// The checks are about whether a guide can be trusted on screen: a stale deep
// link teaches the operator that the box does not know what it is talking about,
// which costs more than no link at all.
$placeholders = array('YOUR_', 'your_', 'example.com', 'TODO', 'FIXME', 'xxx');

foreach (DnsDriverRegistry::all() as $key => $class) {
	$guide = $class::credentialGuide();

	if ($class::credentialMode() === DnsProvider::CREDENTIAL_OAUTH2) {
		// An OAuth driver collects nothing, so it has no field to hang a guide
		// on — its registration guide lives on the OAuth provider instead.
		check($class::credentialFields() === array(),
			$key . ' is OAuth2 and collects no credential fields');
		continue;
	}

	check(is_array($guide), $key . ' declares a credential guide');
	if (!is_array($guide)) {
		continue;
	}

	check(trim((string)($guide['title'] ?? '')) !== '', $key . ' guide has a title');
	check(count($guide['steps'] ?? array()) >= 2, $key . ' guide has at least two steps');

	$url = (string)($guide['url'] ?? '');
	check($url === '' || stripos($url, 'https://') === 0,
		$key . ' guide links over https or not at all', $url);

	$blob = $guide['title'] . ' ' . implode(' ', $guide['steps'] ?? array())
		. ' ' . (string)($guide['caution'] ?? '');
	$found = array();
	foreach ($placeholders as $needle) {
		if (strpos($blob, $needle) !== false) { $found[] = $needle; }
	}
	check(empty($found), $key . ' guide has no placeholder text', implode(', ', $found));

	// The copy rows exist for values we hand the vendor. A credential must never
	// travel that way — nothing here is ours to give.
	foreach ($guide['copy'] ?? array() as $row) {
		$value = strtolower((string)($row['value'] ?? ''));
		$leak = false;
		foreach (array('secret', 'token', 'api_key', 'password') as $word) {
			if (strpos($value, $word) !== false) { $leak = true; }
		}
		check(!$leak, $key . ' guide copy row carries no credential-shaped value');
	}
}

// ---------------------------------------------------------------------------
section('An OAuth app registration is declared once and reachable everywhere');
// ---------------------------------------------------------------------------

require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderRegistry.php'));

// This is the assertion that would have caught DigitalOcean and DNSimple: they
// had settings slots and no way to fill them, because the admin page carried its
// own hardcoded list. Every provider now declares its own fields, and the page
// renders whatever the registry returns.
$declared = json_decode(file_get_contents(PathHelper::getIncludePath('settings.json')), true);
$declared_names = array();
foreach ($declared['settings'] ?? array() as $row) {
	$declared_names[$row['name']] = true;
}

$providers = OAuth2ProviderRegistry::all();
check(count($providers) > 0, 'the OAuth provider registry discovers providers');

foreach ($providers as $key => $provider_class) {
	$fields = $provider_class::configFields();
	check(count($fields) >= 2, $key . ' declares at least a client id and secret');

	$has_secret = false;
	foreach ($fields as $setting => $spec) {
		check(isset($declared_names[$setting]),
			$key . ': ' . $setting . ' is declared in settings.json');
		check(trim((string)($spec['label'] ?? '')) !== '',
			$key . ': ' . $setting . ' has a label to render');
		if (!empty($spec['secret'])) { $has_secret = true; }
	}
	check($has_secret, $key . ' marks its client secret as a secret');

	$guide = $provider_class::configGuide();
	check(is_array($guide), $key . ' declares an app registration guide');
	if (!is_array($guide)) {
		continue;
	}
	check(count($guide['steps'] ?? array()) >= 2, $key . ' registration guide has steps');

	// The vendor's own form asks for the callback URL, so every guide offers it
	// as a copy row rather than describing it.
	$has_callback = false;
	foreach ($guide['copy'] ?? array() as $row) {
		if (stripos((string)($row['label'] ?? ''), 'callback') !== false) { $has_callback = true; }
	}
	check($has_callback, $key . ' registration guide offers the callback URL to copy');
}

// ---------------------------------------------------------------------------
section('A guide renders as an inert template, never as markup it was handed');
// ---------------------------------------------------------------------------

require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));

$fw = new FormWriterV2HTML5('guide_test');
ob_start();
$fw->passwordinput('some_token', 'Some token', array(
	'help_modal' => array(
		'title' => 'Get a <token>',
		'url'   => 'https://vendor.test/tokens',
		'steps'   => array('Open "settings" & pick New', 'Copy it'),
		'caution' => 'Not the other key.',
		'copy'    => array(array('label' => 'Callback URL', 'value' => 'https://site.test/oauth_callback')),
	),
));
$html = ob_get_clean();

check(strpos($html, 'data-jy-help="some_token_help"') !== false, 'the field emits a help trigger');
check(strpos($html, '<template id="some_token_help">') !== false, 'the guide is emitted as a template');
check(strpos($html, 'Get a &lt;token&gt;') !== false, 'guide text is escaped, not injected');
check(strpos($html, 'jy-help-guide-caution') !== false
	&& strpos($html, '<li>Not the other key.</li>') === false,
	'a caution renders outside the numbered steps');
check(strpos($html, '<token>') === false, 'no raw markup from guide text reaches the page');
check(strpos($html, 'data-jy-copy="https://site.test/oauth_callback"') !== false,
	'a copy row becomes a click-to-copy button');

// A javascript: url in a guide must never become an href, even though guides are
// authored data — the cost of the check is nothing and the failure is silent.
ob_start();
$fw->passwordinput('other_token', 'Other token', array(
	'help_modal' => array(
		'title' => 'Bad link',
		'url'   => 'javascript:alert(1)',
		'steps' => array('one', 'two'),
	),
));
$bad = ob_get_clean();
check(strpos($bad, 'javascript:') === false, 'a non-https guide url is not rendered as a link');

ob_start();
$fw->textinput('plain_field', 'Plain field', array());
$plain = ob_get_clean();
check(strpos($plain, 'data-jy-help') === false, 'a field with no guide emits no trigger');

// ===================================================================
section('Rate limiting (429)');
// ===================================================================
//
// A publish that hit Cloudflare's 429 reached the operator as the vendor's own
// sentence — "please wait and consider throttling your request speed" — which is
// a machine talking to a machine. Nothing was logged either, so afterwards there
// was no status, no trace id and no Retry-After to work from.
//
// Handled in DnsDriverBase so all fifteen drivers get it, not the one that
// happened to fail.

require_once(PathHelper::getIncludePath('includes/dns/DnsDriverBase.php'));

use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;

/** Answers every request with one canned response, and counts the attempts. */
class RateLimitedHttp {
	public $calls = 0;
	private $response;
	public function __construct(Psr7Response $response) { $this->response = $response; }
	public function request($method, $url, $options = array()) {
		$this->calls++;
		throw new GuzzleRequestException('rate limited',
			new Psr7Request($method, $url), $this->response);
	}
}

class RateLimitProbeDriver extends DnsDriverBase {
	public static function getKey(): string { return 'probe'; }
	public static function getLabel(): string { return 'ProbeDNS'; }
	public static function credentialType(): string { return DnsProvider::CREDENTIAL_API; }
	public static function credentialFields(): array { return array(); }
	public function accounts(): array { return array(); }
	public function zoneFor(string $domain): ?string { return $domain; }
	public function listRecords(string $zone): array { return array(); }
	public function createRecord(string $zone, DnsRecord $record): void {}
	public function updateRecord(string $zone, DnsRecord $live, DnsRecord $desired): void {}
	public function deleteRecord(string $zone, DnsRecord $live): void {}
	protected function authHeaders(): array {
		return array('Authorization' => 'Bearer ' . $this->cred('api_token'));
	}
	/** Reach the protected plumbing from the test. */
	public function probe(string $method, string $url) { return $this->request($method, $url); }
}

$cf_body = '{"success":false,"errors":[{"code":971,'
	. '"message":"Please wait and consider throttling your request speed"}]}';

$make = function (array $headers) use ($cf_body) {
	$http = new RateLimitedHttp(new Psr7Response(429, $headers, $cf_body));
	$driver = new RateLimitProbeDriver(array('api_token' => 'shhh-secret-token'), null);
	// The fake client replaces Guzzle after construction; the constructor's own
	// client is never used.
	$ref = new ReflectionProperty('DnsDriverBase', 'http');
	$ref->setAccessible(true);
	$ref->setValue($driver, $http);
	return array($driver, $http);
};

// A READ is free to repeat — nothing changed, so the only cost is the wait.
list($driver, $http) = $make(array('Retry-After' => '1', 'CF-RAY' => 'abc123-ATL'));
$caught = null;
try { $driver->probe('GET', 'https://api.example.com/client/v4/zones'); }
catch (Throwable $e) { $caught = $e; }
check($caught instanceof DnsRateLimitedException, 'a 429 raises a rate-limit error of its own type',
	$caught ? get_class($caught) : 'nothing thrown');
check($http->calls === 1 + DnsDriverBase::RATE_LIMIT_MAX_RETRIES,
	'a rate-limited read is retried within the budget', 'calls: ' . $http->calls);
check($caught->getRetryAfter() === 1, 'the vendor Retry-After is carried on the exception');

// A WRITE is never retried: the vendor may have applied it before deciding to
// rate-limit the response, and repeating a create would leave a duplicate.
list($driver, $http) = $make(array('Retry-After' => '1'));
$caught = null;
try { $driver->probe('POST', 'https://api.example.com/client/v4/zones/z/dns_records'); }
catch (Throwable $e) { $caught = $e; }
check($http->calls === 1, 'a rate-limited write is attempted exactly once', 'calls: ' . $http->calls);
check(stripos($caught->getMessage(), 'may already have been updated') !== false,
	'and says records may already have been written');

// A read says the opposite, and that is the first thing an operator needs.
list($driver, $http) = $make(array());
$caught = null;
try { $driver->probe('GET', 'https://api.example.com/client/v4/zones'); }
catch (Throwable $e) { $caught = $e; }
check(stripos($caught->getMessage(), 'nothing was changed') !== false,
	'a rate-limited read states plainly that nothing changed');

// The message has to be actionable, and has to correct the natural misreading
// that the server is being blocked.
$msg = $caught->getMessage();
check(stripos($msg, 'your account, not this server') !== false,
	'names the account as what is limited, not the server');
check(stripos($msg, 'shared with the ProbeDNS website') !== false,
	'and warns that the vendor dashboard spends the same quota');
check(stripos($msg, 'throttling your request speed') === false,
	'the vendor sentence written for a program is not what the operator reads');

// Retry-After in HTTP-date form, which vendors are entitled to send.
list($driver, $http) = $make(array('Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 30)));
$caught = null;
try { $driver->probe('GET', 'https://api.example.com/x'); }
catch (Throwable $e) { $caught = $e; }
check($caught->getRetryAfter() >= 28 && $caught->getRetryAfter() <= 31,
	'a date-form Retry-After is converted to seconds', (string)$caught->getRetryAfter());
// Beyond the budget the operator is told, never held: this runs inside a page
// request and a spinner is worse than a sentence.
check($http->calls === 1, 'a wait longer than the budget is reported rather than slept through',
	'calls: ' . $http->calls);

// THE CREDENTIAL NEVER REACHES THE LOG. The whole subsystem's promise is that
// the token exists only for the length of one request.
$log = tempnam(sys_get_temp_dir(), 'dnslog');
$prev = ini_set('error_log', $log);
list($driver, $http) = $make(array('CF-RAY' => 'ray-9', 'Retry-After' => '1'));
try { $driver->probe('GET', 'https://api.example.com/client/v4/zones?token=shhh-secret-token'); }
catch (Throwable $e) { /* expected */ }
ini_set('error_log', $prev === false ? '' : $prev);
$written = (string)@file_get_contents($log);
@unlink($log);
check(strpos($written, 'shhh-secret-token') === false,
	'the credential is absent from the log, including from the query string');
check(strpos($written, 'status=429') !== false, 'the status is logged');
check(strpos($written, 'CF-RAY=ray-9') !== false, 'the vendor trace id is logged');
check(strpos($written, 'dns_publish') !== false && strpos($written, 'probe') !== false,
	'the line is greppable by subsystem and driver');

// ===================================================================
section('A skipped record is never reported as a success');
// ===================================================================
//
// The adopt and cutover confirmations are unticked by default on purpose: they
// are the only choices in the box that can take mail down. But submitting with
// none ticked skipped every record needing one and reported it in green, with a
// bare count and no reason — so an operator could press Apply, read success, and
// have published nothing.

require_once(PathHelper::getIncludePath('includes/dns/DnsPublishBox.php'));
require_once(PathHelper::getIncludePath('includes/dns/DnsReconciler.php'));

$result_row = function (string $action, bool $ok, string $reason = '') {
	return array(
		'key'    => 'k' . $action . $reason,
		'record' => new DnsRecord('TXT', 'example.com', 'v=spf1 -all'),
		'action' => $action,
		'ok'     => $ok,
		'reason' => $reason,
	);
};
$publish = function (array $results, string $error = '') {
	return array('results' => $results, 'error' => $error, 'accounts' => array());
};

$all_skipped = $publish(array(
	$result_row('skipped', true, 'This change redirects traffic that already flows, so it needs its own confirmation.'),
	$result_row('skipped', true, 'A record already exists here that the platform does not own.'),
));
check(DnsPublishBox::resultSeverity($all_skipped) !== DisplayMessage::MESSAGE_ANNOUNCEMENT,
	'a publish that skipped everything is never green');
check(DnsPublishBox::resultSeverity($all_skipped) === DisplayMessage::MESSAGE_WARNING,
	'it warns — nothing failed, but nothing the operator asked for happened');

$summary = DnsPublishBox::summarizeResults($all_skipped);
check(stripos($summary, 'nothing was published') !== false,
	'and the summary leads with that, rather than a count', $summary);
check(stripos($summary, 'redirects traffic') !== false,
	'the reason each record was skipped is named, not just tallied');
check(stripos($summary, 'v=spf1 -all') !== false, 'and the records themselves are named');

// A clean publish is still green — the fix must not turn every result amber.
$all_written = $publish(array($result_row('created', true), $result_row('updated', true)));
check(DnsPublishBox::resultSeverity($all_written) === DisplayMessage::MESSAGE_ANNOUNCEMENT,
	'a publish where everything landed is still a success');
$nothing_to_do = $publish(array($result_row('unchanged', true), $result_row('matches', true)));
check(DnsPublishBox::resultSeverity($nothing_to_do) === DisplayMessage::MESSAGE_ANNOUNCEMENT,
	'a diff with nothing to change is still a success');

// Partial: some landed, some were skipped for want of a tick.
$partial = $publish(array($result_row('created', true), $result_row('skipped', true, 'needs confirmation')));
check(DnsPublishBox::resultSeverity($partial) === DisplayMessage::MESSAGE_WARNING,
	'a partial publish warns');
check(stripos(DnsPublishBox::summarizeResults($partial), 'nothing was published') === false,
	'but is not described as nothing published, because something was');

// A real failure still reads as one.
$failed = $publish(array($result_row('failed', false, 'refused')));
check(DnsPublishBox::resultSeverity($failed) === DisplayMessage::MESSAGE_ERROR,
	'a publish where nothing reached the provider is an error');

// ===================================================================
section('Apply is disabled while it would publish nothing');
// ===================================================================
//
// Being told afterwards is a poor second to not being able to do it. The test is
// PER RECORD: a conflicting MX is both a cutover and an overwrite, so ticking one
// of its two boxes still publishes nothing.

require_once(PathHelper::getIncludePath('includes/dns/dns_publish_box.php'));

$gate_html = function (array $rows) {
	ob_start();
	dns_publish_box_apply_gate($rows);
	return ob_get_clean();
};
$row = function (string $outcome, bool $cutover, string $key) {
	return array('key' => $key, 'outcome' => $outcome, 'cutover' => $cutover,
		'record' => new DnsRecord('TXT', 'example.com', 'x'), 'live' => array());
};

// Nothing gated: an ordinary set of additions must never disable the button.
check($gate_html(array($row(DnsReconciler::MISSING, false, 'a'))) === '',
	'a diff of plain additions emits no gate at all');

// One free write alongside gated ones: pressing Apply still publishes something.
check($gate_html(array($row(DnsReconciler::MISSING, false, 'a'),
	$row(DnsReconciler::CONFLICTS, false, 'b'))) === '',
	'a diff with any unconditional write emits no gate');

// Everything gated: the button must start disabled.
$html = $gate_html(array($row(DnsReconciler::CONFLICTS, false, 'b'),
	$row(DnsReconciler::MISSING, true, 'c')));
check($html !== '', 'a diff where every record needs a tick emits the gate');
check(strpos($html, 'btn.disabled=!any') !== false, 'the gate drives the button disabled state');
check(strpos($html, 'publish nothing') !== false, 'and says why the button is off');

// The per-record shape is what makes a conflicting cutover correct.
$both = $gate_html(array($row(DnsReconciler::CONFLICTS, true, 'mx')));
check(strpos($both, '"cutover":true') !== false && strpos($both, '"adopt":true') !== false,
	'a record that is both a cutover and an overwrite carries both gates');
check(strpos($both, '(!g.cutover||c[g.key])&&(!g.adopt||a[g.key])') !== false,
	'and the rule requires every gate on a record, not any one box anywhere');

// Unchanged records are not writes: a diff of only matches plus one gated record
// must not count the matches as a reason to enable Apply.
$matches_only = $gate_html(array($row(DnsReconciler::MATCHES, false, 'm'),
	$row(DnsReconciler::CONFLICTS, false, 'b')));
check($matches_only !== '', 'an already-correct record does not count as a pending write');

// ---------------------------------------------------------------------------
section('The action column says what applying does, in verbs, coloured by stakes');
// ---------------------------------------------------------------------------

// A BADGE IS READ AS AN ADJECTIVE. Sitting alone in a column, "Correct" said
// this record IS correct — the opposite of why it was listed — and wore the
// same blue as Add, which reads as optional detail. Every label here has to be
// a word that can only be a verb.
$badge = function ($outcome) { return dns_publish_box_badge($outcome); };

foreach (array(
	DnsReconciler::MISSING   => 'Add',
	DnsReconciler::DIFFERS   => 'Change',
	DnsReconciler::CONFLICTS => 'Replace',
) as $outcome => $verb) {
	check(strpos($badge($outcome), '>' . $verb . '<') !== false,
		$outcome . ' reads as the verb "' . $verb . '"', $badge($outcome));
}
foreach (array(DnsReconciler::MISSING, DnsReconciler::DIFFERS, DnsReconciler::CONFLICTS) as $outcome) {
	check(stripos($badge($outcome), 'correct') === false,
		$outcome . ' never uses a word that can be read as "this is fine"');
}

// Colour grades by what is at stake. Overwriting a live value is not the same
// as filling an empty name, and must not wear the same colour as it.
check(strpos($badge(DnsReconciler::MISSING), 'badge-subtle-primary') !== false,
	'Add carries no alarm colour — nothing is there to lose');
check(strpos($badge(DnsReconciler::DIFFERS), 'warning') !== false,
	'Change is amber: a live value goes away');
check(strpos($badge(DnsReconciler::DIFFERS), 'badge-subtle-primary') === false,
	'and is no longer blue, which reads as optional detail');
check($badge(DnsReconciler::DIFFERS) !== $badge(DnsReconciler::CONFLICTS),
	'Replace stays stronger than Change — it destroys a record we did not create');

// The headline uses the same verbs, so the summary and the rows agree.
$headline = function (array $counts) {
	return dns_publish_box_headline(array('counts' => $counts, 'provider_label' => 'Cloudflare'));
};
check($headline(array(DnsReconciler::DIFFERS => 2)) === 'Change 2 DNS records at Cloudflare',
	'the headline verb matches the badge', $headline(array(DnsReconciler::DIFFERS => 2)));
check($headline(array(DnsReconciler::MISSING => 2, DnsReconciler::DIFFERS => 1)) ===
	'Add 2 and change 1 DNS records at Cloudflare',
	'and reads as one sentence when several kinds are pending',
	$headline(array(DnsReconciler::MISSING => 2, DnsReconciler::DIFFERS => 1)));

// The consequence line has to say the current value goes away — that is the
// part an operator needs before pressing Apply on a record that already works.
$consequence = dns_publish_box_consequence(array('outcome' => DnsReconciler::DIFFERS, 'owned' => true));
check(stripos($consequence, 'overwrit') !== false,
	'and the row explains that the existing value is overwritten', $consequence);

harness_finish();
