<?php
/** @joinery-test
 * name: dns_relocation
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * The guided DNS move (specs/wizard_dns_relocation.md Part 3) — the pure
 * parts: which destinations are offered, how the seed plan merges copied
 * reality with the deployment's own records, and the handover helpers. Each
 * merge rule guards a real wire-level defect: two SPF records is a permanent
 * SPF failure, a second DMARC is undefined behaviour, and nothing may sit
 * beside a CNAME. Network-touching pieces (visibleRecords, seed) are covered
 * by their live use, not here.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/dns/DnsRelocation.php'));

// ---------------------------------------------------------------------------
section('The offered destinations are open-API hosts, and only those');
// ---------------------------------------------------------------------------

$targets = DnsRelocation::targets();
check(isset($targets['linode']), 'Linode is offered');
check(isset($targets['cloudflare']), 'Cloudflare is offered');
check(count($targets) === 2, 'exactly the two recommended destinations', implode(',', array_keys($targets)));
foreach ($targets as $key => $class) {
	check($class::apiGateNote() === '', $key . ' is not itself gated');
	check($class::credentialMode() === DnsProvider::CREDENTIAL_API, $key . ' takes a pasted credential');
}

// ---------------------------------------------------------------------------
section('The seed plan carries copied reality verbatim, plus the plan\'s own records');
// ---------------------------------------------------------------------------

function relocation_plan_values(DnsRecordPlan $plan): array {
	$out = array();
	foreach ($plan->getRecords() as $r) {
		$out[] = $r->type . ' ' . DnsRecord::normalizeName($r->name) . ' ' . $r->value;
	}
	sort($out);
	return $out;
}

$own = new DnsRecordPlan('example.com', 'mailbox');
$own->addRecord('TXT', 'example.com', 'v=spf1 include:mailgun.org ~all');
$own->addRecord('TXT', '_dmarc.example.com', 'v=DMARC1; p=none;');
$own->addRecord('TXT', 'mx._domainkey.example.com', 'k=rsa; p=NEWKEY');
$own->addRecord('SRV', '_joinery._tcp.example.com', '0 5 443 example.com');

$copied = array(
	new DnsRecord('A', 'example.com', '203.0.113.9'),
	new DnsRecord('MX', 'example.com', 'mail.old-host.test', null, 10),
	new DnsRecord('TXT', 'example.com', 'google-site-verification=abc'),
);

$plan = DnsRelocation::seedPlan($own, $copied, 'example.com');
$values = relocation_plan_values($plan);
check(in_array('A example.com 203.0.113.9', $values, true), 'the copied A record is in the seed');
check(in_array('MX example.com mail.old-host.test', $values, true), 'the copied MX is in the seed');
check(in_array('TXT example.com google-site-verification=abc', $values, true), 'a foreign apex TXT rides along');
check(in_array('TXT example.com v=spf1 include:mailgun.org ~all', $values, true),
	'the plan SPF is added when the domain publishes none');
check(in_array('SRV _joinery._tcp.example.com 0 5 443 example.com', $values, true),
	'the plan SRV is added');
check(count($values) === 7, 'nothing else appears', implode(' | ', $values));

// ---------------------------------------------------------------------------
section('Copied reality outranks the plan wherever both speak');
// ---------------------------------------------------------------------------

$copied = array(
	new DnsRecord('TXT', 'example.com', 'v=spf1 include:other-sender.test -all'),
	new DnsRecord('TXT', '_dmarc.example.com', 'v=DMARC1; p=reject;'),
	new DnsRecord('CNAME', 'mx._domainkey.example.com', 'dkim.provider.test'),
);
$plan = DnsRelocation::seedPlan($own, $copied, 'example.com');
$values = relocation_plan_values($plan);

check(in_array('TXT example.com v=spf1 include:other-sender.test -all', $values, true)
	&& !in_array('TXT example.com v=spf1 include:mailgun.org ~all', $values, true),
	'an existing SPF is seeded verbatim and the plan SPF stands down — two SPF records is a permerror');
check(in_array('TXT _dmarc.example.com v=DMARC1; p=reject;', $values, true)
	&& !in_array('TXT _dmarc.example.com v=DMARC1; p=none;', $values, true),
	'an existing DMARC policy outranks the plan default');
check(in_array('CNAME mx._domainkey.example.com dkim.provider.test', $values, true)
	&& !in_array('TXT mx._domainkey.example.com k=rsa; p=NEWKEY', $values, true),
	'nothing coexists with a copied CNAME');
check(in_array('SRV _joinery._tcp.example.com 0 5 443 example.com', $values, true),
	'unrelated plan records still ride');

// Exact duplicates collapse, and removals have no meaning in a zone being born.
$own2 = new DnsRecordPlan('example.com', 'mailbox');
$own2->addRecord('A', 'example.com', '203.0.113.9');
$own2->addAbsent('MX', 'example.com');
$plan = DnsRelocation::seedPlan($own2, array(new DnsRecord('A', 'example.com', '203.0.113.9')), 'example.com');
$values = relocation_plan_values($plan);
check($values === array('A example.com 203.0.113.9'),
	'an exact duplicate collapses and an absent record is dropped', implode(' | ', $values));

// ---------------------------------------------------------------------------
section('The handover knows each vendor\'s answers');
// ---------------------------------------------------------------------------

$linode = new LinodeDnsDriver(array('access_token' => 'x'));
check($linode->zoneNameservers('example.com') === LinodeDnsDriver::nameservers(),
	'Linode hands over its fixed nameserver set with no API call');

check(stripos(DnsRelocation::registrarNameserverHelp('namecheap'), 'Custom DNS') !== false,
	'the Namecheap instruction names its own menu words');
check(stripos(DnsRelocation::registrarNameserverHelp('godaddy'), 'Nameservers') !== false,
	'the GoDaddy instruction names its own menu words');
check(DnsRelocation::registrarNameserverHelp('') !== '', 'an unknown registrar still gets an instruction');
check(stripos(DnsRelocation::registrarNameserverHelp('cloudflare'), 'registrar') !== false,
	'a Cloudflare-hosted source sends the operator to the registrar, not Cloudflare');

harness_finish();
