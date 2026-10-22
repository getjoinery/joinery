<?php
/** @joinery-test
 * name: agent_test_build_hold
 * tier: db
 * env: dev-only
 * needs: [dev-web]
 * covers: [plugins/server_manager/includes/AgentChannelEndpoint.php, plugins/server_manager/data/managed_nodes_class.php, plugins/server_manager/includes/AgentLiveProofRun.php]
 */
/**
 * A node running a live-proof test build is offered none of this plane's
 * agents or bundles (specs/fleet_security_patching.md WP7, Q8), over real
 * signed artifact requests.
 *
 * The live proof's agent is built with a key made for the run in place of the
 * release keys, so it would refuse this plane's agent and bundle, and each
 * refusal is raised as an incident. With mgn_agent_test_build set the plane
 * answers as a plane that has none; the same node without it is offered this
 * plane's own, so the hold is what made the difference.
 *
 * Run: php plugins/server_manager/tests/agent_test_build_hold_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/http.php');
harness_boot();

$pair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($pair);
$node = new ManagedNode(NULL);
$node->set('mgn_name', 'HarnessTest test build hold');
$node->set('mgn_slug', 'harnesshold-' . bin2hex(random_bytes(3)));
$node->set('mgn_host', '127.0.0.1');
$node->set('mgn_uptime_enabled', false);
$node->set('mgn_agent_public_key', base64_encode(sodium_crypto_sign_publickey($pair)));
$node->set('mgn_agent_test_build', true);
$node->save();
$node->load();
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node->key);

$ask = function (array $body) use ($node, $secret) {
	$raw = json_encode(array('node_id' => (int)$node->key) + $body);
	$ts = (string)time();
	$nonce = bin2hex(random_bytes(8));
	$msg = "joinery-agent-v1\nPOST\n/api/v1/agent/artifact\n" . (int)$node->key . "\n{$ts}\n{$nonce}\n" . hash('sha256', $raw);
	$r = harness_request('POST', '/api/v1/agent/artifact', array('body' => $raw, 'headers' => array(
		'Content-Type: application/json',
		'X-Joinery-Agent-Node: ' . (int)$node->key,
		'X-Joinery-Agent-Timestamp: ' . $ts,
		'X-Joinery-Agent-Nonce: ' . $nonce,
		'X-Joinery-Agent-Signature: ' . base64_encode(sodium_crypto_sign_detached($msg, $secret)),
	)));
	return array($r['status'], json_decode((string)$r['body'], true));
};

section('A live-proof test build is offered nothing');

list($status, $j) = $ask(array('kind' => 'agent_manifest'));
check($status === 200 && ($j['data']['manifest'] ?? null) === '', 'agent_manifest: none, as a plane that has published no agent answers', $status . ' ' . json_encode($j));
list($status, $j) = $ask(array('kind' => 'agent_statement'));
check($status === 200 && ($j['data']['statement'] ?? null) === '', 'agent_statement: none', $status . ' ' . json_encode($j));
list($status, $j) = $ask(array('kind' => 'bundle_manifest'));
check($status === 200 && ($j['data']['available'] ?? null) === false, 'bundle_manifest: none available', $status . ' ' . json_encode($j));
list($status, $j) = $ask(array('kind' => 'agent_binary', 'platform' => 'linux-amd64'));
check($status === 404 && strpos((string)($j['error'] ?? ''), 'live-proof test build') !== false, 'agent_binary: refused, saying why', $status . ' ' . json_encode($j));
list($status, $j) = $ask(array('kind' => 'bundle_body'));
check($status === 404, 'bundle_body: refused', (string)$status);
check(array_diff(AgentChannelEndpoint::OWN_BUILD_KINDS, AgentChannelEndpoint::ARTIFACT_KINDS) === array()
		&& array_values(array_filter(AgentChannelEndpoint::ARTIFACT_KINDS, function ($k) { return strpos($k, 'release_') !== 0; })) == array_values(array_intersect(AgentChannelEndpoint::ARTIFACT_KINDS, AgentChannelEndpoint::OWN_BUILD_KINDS)),
	'the kinds held back are every agent and bundle kind; a site release\'s kinds are not this hold\'s');

section('The same node without the hold is offered this plane\'s own');

$node->set('mgn_agent_test_build', false);
$node->save();
$published = AgentDistPublisher::readManifest(PathHelper::getIncludePath('agent_dist'));
list($status, $j) = $ask(array('kind' => 'agent_manifest'));
if (is_array($published)) {
	check($status === 200 && ($j['data']['manifest'] ?? '') !== '', 'agent_manifest: this plane\'s published one', $status . ' ' . substr(json_encode($j), 0, 200));
} else {
	check($status === 200, 'agent_manifest answers (this plane has published no agent, so it is empty either way)');
}

section('A live-proof run adopts only its own server\'s join');

// The run reads the fingerprint over an SSH session that checks no host
// identity, so the join must also come from the server's own address and
// under the run's label (reviewer2 a). The approval itself is the live run's.
$jpair = sodium_crypto_sign_keypair();
$jpub = sodium_crypto_sign_publickey($jpair);
$join = new AgentJoinRequest(NULL);
$join->set('ajr_claimed_name', 'wp7-proof-harnes');
$join->set('ajr_public_key', base64_encode($jpub));
$join->set('ajr_fingerprint', AgentJoinRequest::fingerprint($jpub));
$join->set('ajr_source_ip', '2001:db8::77');
$join->set('ajr_addresses', '192.0.2.77');
$join->set('ajr_status', AgentJoinRequest::STATUS_PENDING);
$join->save();
$join->load();
harness_register_row('ajr_agent_join_requests', 'ajr_agent_join_request_id', $join->key);
$fp = strtolower((string)$join->get('ajr_fingerprint'));
$refused = function (array $server) use ($fp) {
	try { AgentLiveProofRun::adopt($fp, $server); } catch (Exception $e) { return $e->getMessage(); }
	return '';
};
$why = $refused(array('label' => 'wp7-proof-harnes', 'ipv4' => '192.0.2.78', 'ipv6' => '2001:db8::78/128'));
check(strpos($why, 'not adopted') !== false, 'a join from another address than the server\'s: not adopted', $why);
$why = $refused(array('label' => 'wp7-proof-harnes', 'ipv4' => '192.0.2.77', 'ipv6' => ''));
check(strpos($why, 'not adopted') !== false && strpos($why, 'came from 2001:db8::77') !== false,
	'a join from another address that REPORTS the server\'s address, under the run\'s label: not adopted (only the address this plane saw counts; reviewer2)', $why);
$why = $refused(array('label' => 'wp7-proof-other', 'ipv4' => '', 'ipv6' => '2001:db8::77/128'));
check(strpos($why, 'not adopted') !== false, 'a join from the server\'s own address under another name: not adopted', $why);
$join->load();
check((string)$join->get('ajr_status') === AgentJoinRequest::STATUS_PENDING, 'and the request is left as it was');

harness_finish();
