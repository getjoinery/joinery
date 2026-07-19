<?php
/** @joinery-test
 * name: setup_topology
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Topology-aware Setup tab verdicts (specs/mailbox_setup_topology_aware.md).
 *
 * Every prescription on the Setup tab derives from the deployment's receive
 * topology (colocated / self-hosted relay / hosted fleet slot). This test
 * drives InboundEmailSetupCheck's verdict derivations offline: DNS goes
 * through the FakeDnsBackend fixture and the topology / fleet state are
 * injected by reflection, so no relay rows are created and no network or
 * fleet API is touched.
 *
 *  - Colocated MX/SPF verdicts (the regression floor — unchanged behavior).
 *  - Fronted MX matrix: exact-match against the relay MX hostname, resolution
 *    to the relay IP, and the fleet/self-hosted split when the operator's A
 *    record is missing (INFO vs REQUIRED FAIL).
 *  - Fronted SPF: a record naming the box FAILS (it publishes the address the
 *    relay hides); the provider mechanism must be covered; never prescribes
 *    the box IP.
 *  - domain.ownership rows from fleet state: proven / pending (with the
 *    copy-ready TXT fix) / API-unreachable UNKNOWN.
 *  - Relay mail-host identity rows (A + PTR) with the operator/tenant split.
 *  - plugin.relay_enable renders INFO while DNS is still moving.
 *  - Provider getSpfMechanism shapes (static includes, local '' cases).
 *
 * Run: php tests/run.php db --filter=setup_topology
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../../../tests/lib/dns_fixtures.php');
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));

const BOX_IP   = '203.0.113.10';
const RELAY_IP = '198.51.100.7';
const RELAY_MX = 't9.mx.operator.example';
const SELF_MX  = 'relay.tenant.example';

/** Build a checker with injected topology/fleet state and a fixed box identity. */
function topo_checker(?array $topology, ?array $fleet_state = null): InboundEmailSetupCheck {
	$checker = new InboundEmailSetupCheck();
	$ref = new ReflectionClass(InboundEmailSetupCheck::class);
	$props = array(
		'publicIp'          => BOX_IP,
		'publicIpIsPrivate' => false,
		'mailHostname'      => 'mail.box.example',
		'topology'          => $topology,
		'fleet_state'       => $fleet_state,
	);
	foreach ($props as $name => $value) {
		$p = $ref->getProperty($name);
		$p->setAccessible(true);
		$p->setValue($checker, $value);
	}
	return $checker;
}

function topo(string $mode, string $mx = '', string $ip = '', bool $enabled = false): array {
	return array('mode' => $mode, 'relay' => null, 'mx_hostname' => $mx,
		'public_ip' => $ip, 'enabled' => $enabled);
}

function call_private($obj, string $method, ...$args) {
	$m = new ReflectionMethod(get_class($obj), $method);
	$m->setAccessible(true);
	return $m->invoke($obj, ...$args);
}

// One fixture map serves every section.
DnsResolver::setBackend(new FakeDnsBackend(array(
	// The box's own identity (colocated floor).
	'mail.box.example|' . DNS_A       => array(array('ip' => BOX_IP)),
	'mail.box.example|' . DNS_CNAME   => array(),
	// The relay's identity.
	RELAY_MX . '|' . DNS_A            => array(array('ip' => RELAY_IP)),
	RELAY_MX . '|' . DNS_CNAME        => array(),
	SELF_MX . '|' . DNS_A             => array(),
	'7.100.51.198.in-addr.arpa|' . DNS_PTR => array(array('target' => RELAY_MX . '.')),
	// A stale MX target still resolving to the box.
	'mail.oldhost.example|' . DNS_A   => array(array('ip' => '192.0.2.44')),
	'mail.oldhost.example|' . DNS_CNAME => array(),
	// Provider SPF policies for chain expansion.
	'mailgun.org|' . DNS_TXT          => array(array('txt' => 'v=spf1 ip4:198.51.100.99/32 -all')),
	'umbrella.example|' . DNS_TXT     => array(array('txt' => 'v=spf1 include:mailgun.org -all')),
	'otherprovider.example|' . DNS_TXT => array(array('txt' => 'v=spf1 ip4:192.0.2.55 -all')),
)));

try {

	// -----------------------------------------------------------------------
	section('colocated floor: MX and SPF verdicts unchanged');

	$c = topo_checker(topo('colocated'));
	$r = call_private($c, 'mxResult', 'example.test', 'mail.box.example', 10, 'mail.box.example');
	check($r['status'] === InboundEmailSetupCheck::PASS, 'colocated MX at the box PASSES', $r['summary']);

	$plan = call_private($c, 'spfPlan', 'example.test');
	check($plan['prescribe'] === 'record', 'colocated SPF plan prescribes a record');
	check(strpos($plan['value'], 'ip4:' . BOX_IP) !== false, 'colocated prescription carries the box IP', $plan['value']);

	$r = call_private($c, 'spfResult', 'example.test', 'v=spf1 ip4:' . BOX_IP . ' -all',
		array('prescribe' => 'record', 'value' => 'v=spf1 ip4:' . BOX_IP . ' -all', 'mechanism' => '', 'label' => ''));
	check($r['status'] === InboundEmailSetupCheck::PASS, 'colocated SPF authorizing the box PASSES', $r['summary']);

	$r = call_private($c, 'spfResult', 'example.test', 'v=spf1 ip4:192.0.2.44 -all',
		array('prescribe' => 'record', 'value' => 'v=spf1 ip4:' . BOX_IP . ' -all', 'mechanism' => '', 'label' => ''));
	check($r['status'] === InboundEmailSetupCheck::FAIL, 'colocated SPF omitting the box FAILS', $r['summary']);

	// -----------------------------------------------------------------------
	section('fronted MX matrix: exact match + relay resolution');

	$self = topo_checker(topo('self_hosted', RELAY_MX, RELAY_IP));
	$fleet = topo_checker(topo('fleet', RELAY_MX, RELAY_IP));

	$r = call_private($self, 'frontedMxResult', 'example.test', 'mail.oldhost.example', 10);
	check($r['status'] === InboundEmailSetupCheck::FAIL, 'wrong MX target FAILS under a relay', $r['summary']);
	check(($r['fix']['dns_record']['value'] ?? '') === RELAY_MX, 'the fix prescribes the relay MX hostname');
	check(($r['fix']['dns_record']['priority'] ?? null) === 10, 'MX priority rides its own field');

	$r = call_private($self, 'frontedMxResult', 'example.test', RELAY_MX, 10);
	check($r['status'] === InboundEmailSetupCheck::PASS, 'relay-targeted MX resolving to the relay PASSES', $r['summary']);

	// Same wrong-target under fleet: the owned-target heuristic must not apply.
	$r = call_private($fleet, 'frontedMxResult', 'example.test', 'mail.oldhost.example', 10);
	check($r['status'] === InboundEmailSetupCheck::FAIL, 'fleet: only the exact slot hostname is the correct target', $r['summary']);

	// Correct target, operator A record missing: tenant cannot act on a fleet
	// slot (INFO); a self-hosted tenant owns the zone (REQUIRED FAIL + A fix).
	$self2 = topo_checker(topo('self_hosted', SELF_MX, RELAY_IP));
	$fleet2 = topo_checker(topo('fleet', SELF_MX, RELAY_IP));
	$r = call_private($fleet2, 'frontedMxResult', 'example.test', SELF_MX, 10);
	check($r['status'] === InboundEmailSetupCheck::INFO, 'fleet: missing operator A record renders INFO', $r['summary']);
	check($r['fix'] === null, 'fleet: no tenant fix for an operator-owned record');
	$r = call_private($self2, 'frontedMxResult', 'example.test', SELF_MX, 10);
	check($r['status'] === InboundEmailSetupCheck::FAIL, 'self-hosted: missing A record is a REQUIRED FAIL', $r['summary']);
	check(($r['fix']['dns_record']['type'] ?? '') === 'A', 'self-hosted fix prescribes the A record');

	// -----------------------------------------------------------------------
	section('fronted SPF: never the box, always the mechanism');

	$mg_plan = array('prescribe' => 'record', 'value' => 'v=spf1 include:mailgun.org -all',
		'mechanism' => 'include:mailgun.org', 'label' => 'Mailgun');

	$r = call_private($self, 'spfResult', 'example.test', 'v=spf1 ip4:' . BOX_IP . ' include:mailgun.org -all', $mg_plan);
	check($r['status'] === InboundEmailSetupCheck::FAIL, 'SPF naming the box FAILS under a relay', $r['summary']);

	$r = call_private($self, 'spfResult', 'example.test', 'v=spf1 include:mailgun.org -all', $mg_plan);
	check($r['status'] === InboundEmailSetupCheck::PASS, 'mechanism-only SPF PASSES under a relay', $r['summary']);

	$r = call_private($self, 'spfResult', 'example.test', 'v=spf1 include:otherprovider.example -all', $mg_plan);
	check($r['status'] === InboundEmailSetupCheck::WARN, 'SPF missing the outbound mechanism WARNS', $r['summary']);
	check(($r['fix']['dns_record']['value'] ?? '') === 'v=spf1 include:mailgun.org -all',
		'the fix is the copy-ready mechanism record');

	// Chain coverage: a needed mechanism found through an intermediate include.
	check(call_private($self, 'spfCoversMechanism', 'v=spf1 include:umbrella.example -all', 'include:mailgun.org') === true,
		'mechanism coverage expands the include chain');
	check(call_private($self, 'spfCoversMechanism', 'v=spf1 a:smtp.custom.example -all', 'a:smtp.custom.example') === true,
		'a: mechanisms match by term');
	check(call_private($self, 'spfCoversMechanism', 'v=spf1 include:otherprovider.example -all', 'include:mailgun.org') === false,
		'uncovered mechanism is not matched');

	// -----------------------------------------------------------------------
	section('ownership proofs from fleet state');

	$claim = array('claim_id' => 5, 'domain' => 'd.example', 'status' => 'verified',
		'txt_host' => '_joinery-fleet-challenge.d.example', 'txt_value' => 'joinery-fleet-verify-abc');
	$ok_state = array('ok' => true, 'error' => '', 'enrolled' => true, 'claims' => array('d.example' => $claim));

	$f = topo_checker(topo('fleet', RELAY_MX, RELAY_IP), $ok_state);
	$r = call_private($f, 'ownershipResult', 'd.example');
	check($r['status'] === InboundEmailSetupCheck::PASS, 'verified proof PASSES', $r['summary']);

	$pending = $ok_state;
	$pending['claims']['d.example']['status'] = 'pending';
	$f = topo_checker(topo('fleet', RELAY_MX, RELAY_IP), $pending);
	$r = call_private($f, 'ownershipResult', 'd.example');
	check($r['status'] === InboundEmailSetupCheck::FAIL, 'pending proof FAILS with guidance', $r['summary']);
	check(strpos($r['summary'], 'Prove you own d.example') === 0, 'row speaks plainly — no claim/verify vocabulary');
	check(($r['fix']['dns_record']['type'] ?? '') === 'TXT'
		&& ($r['fix']['dns_record']['name'] ?? '') === '_joinery-fleet-challenge.d.example'
		&& ($r['fix']['dns_record']['value'] ?? '') === 'joinery-fleet-verify-abc',
		'the fix is the copy-ready TXT challenge');

	$down = array('ok' => false, 'error' => 'Could not reach the fleet service: timeout', 'enrolled' => false, 'claims' => array());
	$f = topo_checker(topo('fleet', RELAY_MX, RELAY_IP), $down);
	$r = call_private($f, 'ownershipResult', 'd.example');
	check($r['status'] === InboundEmailSetupCheck::UNKNOWN, 'fleet API failure renders one UNKNOWN row', $r['summary']);
	check(strpos((string)$r['detail'], 'timeout') !== false, 'the UNKNOWN row names the error');

	$released = array('ok' => true, 'error' => '', 'enrolled' => false, 'claims' => array());
	$f = topo_checker(topo('fleet', RELAY_MX, RELAY_IP), $released);
	$r = call_private($f, 'ownershipResult', 'd.example');
	check($r['status'] === InboundEmailSetupCheck::FAIL, 'a released slot FAILS the ownership row', $r['summary']);

	// -----------------------------------------------------------------------
	section('relay mail-host identity rows (A + PTR)');

	$rows = call_private($self, 'frontedMailHostResults');
	$by_id = array();
	foreach ($rows as $row) { $by_id[$row['id']] = $row; }
	check(($by_id['mailhost.a_record']['status'] ?? '') === InboundEmailSetupCheck::PASS,
		'relay hostname resolving to the relay PASSES', $by_id['mailhost.a_record']['summary'] ?? '');
	check(($by_id['mailhost.ptr']['status'] ?? '') === InboundEmailSetupCheck::PASS,
		'relay PTR naming the relay PASSES', $by_id['mailhost.ptr']['summary'] ?? '');

	$rows = call_private($fleet2, 'frontedMailHostResults'); // fleet, A missing
	$by_id = array();
	foreach ($rows as $row) { $by_id[$row['id']] = $row; }
	check(($by_id['mailhost.a_record']['status'] ?? '') === InboundEmailSetupCheck::INFO,
		'fleet: operator A record missing renders INFO', $by_id['mailhost.a_record']['summary'] ?? '');

	$rows = call_private($self2, 'frontedMailHostResults'); // self-hosted, A missing
	$by_id = array();
	foreach ($rows as $row) { $by_id[$row['id']] = $row; }
	check(($by_id['mailhost.a_record']['status'] ?? '') === InboundEmailSetupCheck::FAIL,
		'self-hosted: missing A record is a REQUIRED FAIL', $by_id['mailhost.a_record']['summary'] ?? '');

	// -----------------------------------------------------------------------
	section('cutover completion row');

	// Under the fixture every real domain's MX lookup returns [], so DNS can
	// never read as fully cut over here — the row must be the neutral INFO.
	$f = topo_checker(topo('fleet', RELAY_MX, RELAY_IP, false), $ok_state);
	$r = call_private($f, 'relayEnableResult');
	check($r['id'] === 'plugin.relay_enable' && $r['status'] === InboundEmailSetupCheck::INFO,
		'relay-enable row is INFO while DNS is still moving', $r['summary']);

	// -----------------------------------------------------------------------
	section('provider SPF mechanisms');

	require_once(PathHelper::getIncludePath('includes/email_providers/MailgunProvider.php'));
	require_once(PathHelper::getIncludePath('includes/email_providers/SesProvider.php'));
	require_once(PathHelper::getIncludePath('includes/email_providers/PostfixProvider.php'));
	check(MailgunProvider::getSpfMechanism('example.test') === 'include:mailgun.org', 'Mailgun mechanism');
	check(SesProvider::getSpfMechanism('example.test') === 'include:amazonses.com', 'SES mechanism');
	check(PostfixProvider::getSpfMechanism('example.test') === '', 'local sendmail has no mechanism');

} catch (\Throwable $e) {
	check(false, 'uncaught ' . get_class($e), $e->getMessage());
} finally {
	DnsResolver::clearBackend();
}

harness_finish();
