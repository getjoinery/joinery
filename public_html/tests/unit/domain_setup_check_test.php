<?php
/** @joinery-test
 * name: domain_setup_check
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * The domain_setup_check action answers, for a domain typed on a public page,
 * the facts that decide how an install differs from the fresh-domain
 * walkthrough: registrar (RDAP), DNS host and whether the wizard can write
 * there, website present, mail present, sender policy present. The
 * classification and the RDAP parsing are tested here against fixed inputs;
 * no resolver or registry is asked.
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/logic.php');
harness_boot();
require_once(PathHelper::getIncludePath('logic/domain_setup_check_logic.php'));
require_once(PathHelper::getIncludePath('includes/dns/RdapLookup.php'));

// ---------------------------------------------------------------------------
section('RDAP: the registrar entity is found, nested or not, and named');

$verisign_like = array(
	'objectClassName' => 'domain',
	'ldhName' => 'EXAMPLE.COM',
	'entities' => array(
		array(
			'objectClassName' => 'entity',
			'handle' => '146',
			'roles' => array('registrar'),
			'publicIds' => array(array('type' => 'IANA Registrar ID', 'identifier' => '146')),
			'vcardArray' => array('vcard', array(
				array('version', new stdClass(), 'text', '4.0'),
				array('fn', new stdClass(), 'text', 'GoDaddy.com, LLC'),
			)),
			'entities' => array(
				array('roles' => array('abuse'), 'vcardArray' => array('vcard', array(
					array('fn', new stdClass(), 'text', 'Abuse Contact'),
				))),
			),
		),
	),
);
$reg = RdapLookup::parseRegistrar(json_decode(json_encode($verisign_like), true));
check($reg !== null && $reg['name'] === 'GoDaddy.com, LLC' && $reg['iana_id'] === '146' && $reg['display'] === 'GoDaddy',
	'a top-level registrar entity yields its name, IANA id and display name', json_encode($reg));

$nested = array('entities' => array(array(
	'roles' => array('registrant'),
	'entities' => array(array(
		'roles' => array('Registrar'),
		'vcardArray' => array('vcard', array(array('fn', array(), 'text', 'NameCheap, Inc.'))),
	)),
)));
$reg = RdapLookup::parseRegistrar($nested);
check($reg !== null && $reg['display'] === 'Namecheap', 'a registrar nested under another entity is found, role case-insensitively', json_encode($reg));

$handle_only = array('entities' => array(array('roles' => array('registrar'), 'handle' => 'TUCOWS')));
$reg = RdapLookup::parseRegistrar($handle_only);
check($reg !== null && $reg['name'] === 'TUCOWS', 'a registrar with no vcard falls back to its handle', json_encode($reg));

check(RdapLookup::parseRegistrar(array('entities' => array(array('roles' => array('registrant'))))) === null,
	'a document with no registrar entity yields null');

foreach (array(
	'Squarespace Domains II LLC' => 'Squarespace',
	'Cloudflare, Inc.' => 'Cloudflare',
	'Porkbun LLC' => 'Porkbun',
	'Tucows Domains Inc.' => 'Tucows',
	'Gandi SAS' => 'Gandi',
	'Some Small Registrar Ltd' => 'Some Small Registrar',
) as $raw => $want) {
	check(RdapLookup::displayName($raw) === $want, "display name for '$raw' is '$want'", RdapLookup::displayName($raw));
}

section('RDAP: the fetch seam drives registrar()');
RdapLookup::useFetcher(function (string $url) use ($verisign_like) {
	return (strpos($url, 'rdap.org/domain/example.com') !== false) ? json_decode(json_encode($verisign_like), true) : null;
});
$reg = RdapLookup::registrar('Example.COM.');
check($reg !== null && $reg['display'] === 'GoDaddy', 'the domain is normalised before the redirector is asked', json_encode($reg));
check(RdapLookup::registrar('nothing.example') === null, 'a registry that answers nothing yields null');
RdapLookup::useFetcher(null);

// ---------------------------------------------------------------------------
section('Classification: a fresh domain at Namecheap');

$r = domain_setup_check_classify('smithfamily.com',
	array('dns1.registrar-servers.com', 'dns2.registrar-servers.com'),
	array(),
	array('name' => 'NameCheap, Inc.', 'display' => 'Namecheap', 'iana_id' => '1068'));
check($r['situation'] === 'fresh', 'nothing at the apex is a fresh domain', $r['situation']);
check($r['dns_host']['key'] === 'namecheap' && $r['dns_host']['mode'] === 'gated' && $r['dns_host']['gate_note'] !== '',
	'Namecheap is identified from its nameservers and reported as a gated host', json_encode($r['dns_host']));
check($r['registrar_is_dns_host'] === true, 'registrar and DNS host are recognised as one company');
check($r['nameservers_locked'] === false, 'Namecheap does not lock the nameservers');
check($r['website']['present'] === false && $r['mail']['present'] === false && $r['spf'] === false,
	'no website, mail or sender policy reported');

section('Classification: a lived-in domain at Cloudflare, bought at GoDaddy');

$apex = array(
	new DnsRecord(DnsRecord::TYPE_A, 'smith.org', '203.0.113.10'),
	new DnsRecord(DnsRecord::TYPE_MX, 'smith.org', 'aspmx.l.google.com', null, 1),
	new DnsRecord(DnsRecord::TYPE_TXT, 'smith.org', 'v=spf1 include:_spf.google.com ~all'),
	new DnsRecord(DnsRecord::TYPE_TXT, 'smith.org', 'google-site-verification=abc'),
);
$r = domain_setup_check_classify('smith.org', array('chuck.ns.cloudflare.com', 'lola.ns.cloudflare.com'), $apex,
	array('name' => 'GoDaddy.com, LLC', 'display' => 'GoDaddy', 'iana_id' => '146'));
check($r['situation'] === 'existing', 'a website, mail or sender policy makes it an existing domain');
check($r['dns_host']['key'] === 'cloudflare' && $r['dns_host']['mode'] === 'writable', 'Cloudflare is identified and writable', json_encode($r['dns_host']));
check($r['website']['present'] === true && $r['website']['addresses'] === array('203.0.113.10'), 'the apex address is reported as a website');
check($r['mail']['present'] === true && $r['mail']['targets'] === array('aspmx.l.google.com'), 'the MX target is reported as mail elsewhere');
check($r['spf'] === true, 'a v=spf1 TXT is reported; a verification TXT is not');
check($r['registrar_is_dns_host'] === false, 'GoDaddy and Cloudflare are different companies');
check($r['nameservers_locked'] === false, 'a domain merely hosted at Cloudflare can still move its nameservers');

$r = domain_setup_check_classify('smith.org', array('chuck.ns.cloudflare.com'), array(),
	array('name' => 'Cloudflare, Inc.', 'display' => 'Cloudflare', 'iana_id' => '1910'));
check($r['nameservers_locked'] === true && $r['registrar_is_dns_host'] === true,
	'a domain registered AT Cloudflare cannot move its nameservers', json_encode($r));

section('Classification: the path the automatic writer allows');

$ns = array('namecheap' => array('dns1.registrar-servers.com'), 'cloudflare' => array('chuck.ns.cloudflare.com'),
	'linode' => array('ns1.linode.com'), 'godaddy' => array('ns01.domaincontrol.com'), 'unknown' => array('ext-dns1.squarespace.com'));
$site = array(new DnsRecord(DnsRecord::TYPE_A, 'x.com', '203.0.113.10'));
$cases = array(
	array('namecheap', array(), 'linode',       'fresh at Namecheap: move the nameservers to Linode'),
	array('unknown',   array(), 'linode',       'fresh at an unknown host: move the nameservers to Linode'),
	array('cloudflare', array(), 'auto',        'fresh at Cloudflare: DNS stays, the wizard writes everything'),
	array('linode',    array(), 'linode_token', 'fresh at Linode: the deploy-form token does it all'),
	array('linode',    $site,   'linode_token', 'existing at Linode: still the token path'),
	array('cloudflare', $site,  'auto',         'existing at Cloudflare: the wizard writes'),
	array('namecheap', $site,   'gated',        'existing at Namecheap: gated'),
	array('godaddy',   $site,   'gated',        'existing at GoDaddy: gated'),
	array('unknown',   $site,   'manual',       'existing at an unknown host: by hand'),
);
foreach ($cases as list($host, $apex_records, $want, $label)) {
	$r = domain_setup_check_classify('x.com', $ns[$host], $apex_records, null);
	check($r['path'] === $want, $label, $r['path']);
}
$r = domain_setup_check_classify('x.com', $ns['cloudflare'], array(),
	array('name' => 'Cloudflare, Inc.', 'display' => 'Cloudflare', 'iana_id' => '1910'));
check($r['path'] === 'auto', 'fresh and registered at Cloudflare: writable, so auto (never a move it cannot make)', $r['path']);

section('Classification: edge cases');

$r = domain_setup_check_classify('smith.co', array('ext-dns1.squarespace.com'), array(), null);
check($r['dns_host']['key'] === '' && $r['dns_host']['mode'] === 'unknown' && $r['dns_host']['label'] === '',
	'an unrecognised host is reported as unknown, not guessed', json_encode($r['dns_host']));
check($r['registrar'] === null && $r['registrar_is_dns_host'] === false, 'no registrar answer is null, and never "the same company"');

$r = domain_setup_check_classify('smith.co', array(), array(), null);
check($r['nameservers'] === array() && $r['situation'] === 'fresh', 'a domain whose nameservers answer nothing reports an empty list and is fresh');

// ---------------------------------------------------------------------------
section('The action refuses what is not a domain before doing any work');

foreach (array('', 'not a domain', 'smithfamily', 'bad_label.com', '-lead.com', str_repeat('a', 64) . '.com') as $bad) {
	$result = harness_call_logic('logic/domain_setup_check_logic.php',
		'domain_setup_check_logic', array('domain' => $bad));
	check(!empty($result->error), "'" . $bad . "' is refused with a message", (string)$result->error);
}
check(domain_setup_check_is_name('smith-family.co.uk') && domain_setup_check_is_name('xn--bcher-kva.example'),
	'hyphenated and punycode labels are accepted');

section('The descriptor makes the action guest-reachable through the browser credential only');
$d = domain_setup_check_logic_descriptor();
check(($d['auth']['allow_guest'] ?? false) === true && ($d['auth']['requires_browser_session'] ?? false) === true
	&& ($d['mutates'] ?? true) === false, 'allow_guest, requires_browser_session, no mutation', json_encode($d['auth'] ?? null));

harness_finish();
