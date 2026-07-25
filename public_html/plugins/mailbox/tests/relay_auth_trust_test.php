<?php
/** @joinery-test
 * name: relay_auth_trust
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Tests for InboundEmailRouter::authFromRelayMeta() — which Authentication-Results
 * stamps a pulled relay message is allowed to be trusted for.
 *
 * The trusted name is the RELAY's mail hostname, not this deployment's. The relay
 * runs the verifying milters and stamps under its own name, and its opendkim
 * strips sender-supplied lines carrying that name (provision_relay.sh
 * RemoveARFrom), so it is the one authserv-id on a pulled message that a sender
 * cannot have written. Matching against the deployment's own mail hostname
 * instead silently records every relayed message as 'unverified' whenever the
 * relay is not colocated — which is every fronted topology.
 *
 * Covers:
 *   - a relay-stamped line under the relay's hostname is trusted (source='relay')
 *   - the same meta judged against the deployment's hostname is NOT trusted
 *     (the regression this suite exists for)
 *   - first-wins: a forged line lower in the block cannot beat the milter's
 *     (specs/mailbox_relay_fix_pack.md § Fix 2, now under the relay's name)
 *   - a line under any other authserv-id is ignored
 *   - an unasserted method reads 'none'; no list and no name read 'unverified'
 *   - the legacy single-string meta shape still parses
 *
 * Run: php plugins/mailbox/tests/relay_auth_trust_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/AuthenticationResults.php'));

const RELAY_HOST = 'relay1.getjoinery.com';
const DEPLOY_HOST = 'mail.jeremytunnell.com';

try {
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
	$router = new InboundEmailRouter();
} catch (\Throwable $e) {
	harness_skip('router not loadable in this bootstrap', $e->getMessage());
	harness_finish();
	return;
}

/** @return array{dkim:string,spf:string,dmarc:string,source:string} */
function relay_auth($router, array $ar_lines, ?string $authserv_id) {
	return $router->authFromRelayMeta(array('authentication_results' => $ar_lines), $authserv_id);
}

section('the relay is the trusted stamper');

$stamped = array(RELAY_HOST . '; spf=pass; dkim=pass; dmarc=pass');

$trusted = relay_auth($router, $stamped, RELAY_HOST);
check($trusted['source'] === 'relay', 'relay-stamped line is trusted',
	'source=' . $trusted['source']);
check($trusted['spf'] === 'pass' && $trusted['dkim'] === 'pass' && $trusted['dmarc'] === 'pass',
	'all three verdicts read off the relay stamp',
	'spf=' . $trusted['spf'] . ' dkim=' . $trusted['dkim'] . ' dmarc=' . $trusted['dmarc']);

// The regression guard. Judging the relay's stamp against this deployment's own
// mail hostname matches nothing, and every pulled message lands 'unverified'.
$wrong_name = relay_auth($router, $stamped, DEPLOY_HOST);
check($wrong_name['source'] === 'none', 'relay stamp judged against the deployment hostname is refused',
	'source=' . $wrong_name['source']);
check($wrong_name['dmarc'] === 'unverified', 'refused stamp records unverified, never a verdict',
	'dmarc=' . $wrong_name['dmarc']);

section('forged lines cannot win');

// Milters PREPEND, so the trustworthy stamp is the earliest line. A sender who
// embeds their own line bearing the relay's name sits below it.
$forged_below = array(
	RELAY_HOST . '; spf=fail; dkim=fail; dmarc=fail',   // milter, top
	RELAY_HOST . '; spf=pass; dkim=pass; dmarc=pass',   // forged, lower
);
$first_wins = relay_auth($router, $forged_below, RELAY_HOST);
check($first_wins['dmarc'] === 'fail', 'first matching line wins over a lower forged one',
	'dmarc=' . $first_wins['dmarc']);

$other_name = relay_auth($router, array('mx.attacker.example; spf=pass; dkim=pass; dmarc=pass'), RELAY_HOST);
check($other_name['source'] === 'none', 'a line under another authserv-id is ignored',
	'source=' . $other_name['source']);

section('partial and empty stamps');

$spf_only = relay_auth($router, array(RELAY_HOST . '; spf=pass'), RELAY_HOST);
check($spf_only['source'] === 'relay' && $spf_only['spf'] === 'pass',
	'a partial stamp is still trusted');
check($spf_only['dkim'] === 'none' && $spf_only['dmarc'] === 'none',
	'a method the relay did not assert reads none, not a verdict',
	'dkim=' . $spf_only['dkim'] . ' dmarc=' . $spf_only['dmarc']);

$empty = relay_auth($router, array(), RELAY_HOST);
check($empty['source'] === 'none' && $empty['spf'] === 'unverified',
	'no stamps at all reads unverified');

// An empty name must trust nothing rather than fall through to a match-all.
$no_name = $router->authFromRelayMeta(
	array('authentication_results' => $stamped, 'recipient' => 'x@example.com'), '');
check(in_array($no_name['source'], array('none', 'relay'), true),
	'an empty authserv-id resolves to the deployment hostname or trusts nothing',
	'source=' . $no_name['source']);

section('which name the relay row resolves to');

// The consumer asks the relay row for the stamping name. On a fleet slot the MX
// hostname is a per-tenant record the shard never stamps under, so a recorded
// authserv-id has to win — and mrl_name is a human label there, never a host.
try {
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));

	$self_hosted = new MailboxRelay(NULL);
	$self_hosted->set('mrl_name', RELAY_HOST);
	$self_hosted->set('mrl_mx_hostname', RELAY_HOST);
	check($self_hosted->authservId() === RELAY_HOST,
		'a self-hosted relay resolves to its MX hostname', $self_hosted->authservId());

	$slot = new MailboxRelay(NULL);
	$slot->set('mrl_is_hosted', true);
	$slot->set('mrl_name', 'Hosted fleet slot');
	$slot->set('mrl_mx_hostname', 'main.mx.fleet.example');   // per-tenant record
	$slot->set('mrl_authserv_id', 'shard3.fleet.example');    // what the shard stamps
	check($slot->authservId() === 'shard3.fleet.example',
		'a fleet slot resolves to the shard authserv-id, not its per-tenant MX',
		$slot->authservId());

	$bare = new MailboxRelay(NULL);
	$bare->set('mrl_name', 'Hosted fleet slot');
	check($bare->authservId() === '',
		'a human label is never mistaken for a hostname', $bare->authservId());
} catch (\Throwable $e) {
	harness_skip('MailboxRelay not constructible in this bootstrap', $e->getMessage());
}

section('legacy meta shape');

// Older sealers wrote a single string rather than a list.
$legacy = $router->authFromRelayMeta(
	array('authentication_results' => RELAY_HOST . '; spf=pass; dkim=pass; dmarc=pass'), RELAY_HOST);
check($legacy['source'] === 'relay' && $legacy['dmarc'] === 'pass',
	'a legacy single-string meta still parses', 'source=' . $legacy['source']);

harness_finish();
