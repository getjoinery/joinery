<?php
/** @joinery-test
 * name: agent_channel_moved_machine
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A switch-over's old machine keeps its channel (B14).
 *
 * At the take-over the copy's machine takes the node's id, and the old
 * machine's key moves to the copy's row, which is retired. The old machine is
 * not told and goes on signing as the node, while a switch by DNS still has
 * work for it (the forward, then its quiet). Real signed requests to the
 * channel pin:
 *   - the old machine, signing as the node with its own key, is the retired
 *     row: its claim takes the retired row's job, addressed to the node's id
 *     (the agent refuses a job for any other), and records its poll there;
 *   - the node's own machine is still the node, and after going back the
 *     copy's machine, which took the node's id, is the copy's row;
 *   - a key that is not the retired row's of the node's live switch-over is
 *     refused, and so is the old machine once the switch-over has ended;
 *   - a body naming the retired row's id, signed as the node, is refused.
 * The rows this test makes are removed in cleanup.
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/http.php');
harness_boot();

$tag = bin2hex(random_bytes(3));
$mk = function (string $suffix, string $public_key, array $set = array()) use ($tag) {
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'HarnessTest moved ' . $suffix);
	$n->set('mgn_slug', 'harnessmoved-' . $suffix . '-' . $tag);
	$n->set('mgn_host', '127.0.0.1');
	$n->set('mgn_uptime_enabled', false);
	$n->set('mgn_agent_public_key', base64_encode($public_key));
	foreach ($set as $k => $v) {
		$n->set($k, $v);
	}
	$n->save();
	$n->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $n->key);
	return $n;
};
// After the take-over: the node holds the copy's machine's key, the retired
// row the old machine's.
$copy_machine = sodium_crypto_sign_keypair();
$old_machine = sodium_crypto_sign_keypair();
$stranger = sodium_crypto_sign_keypair();
$node = $mk('node', sodium_crypto_sign_publickey($copy_machine));
$retired = $mk('retired', sodium_crypto_sign_publickey($old_machine), array('mgn_install_state' => 'retired'));
$other = $mk('other', sodium_crypto_sign_publickey($stranger), array('mgn_install_state' => 'retired'));

$copy = new SiteCopy(NULL);
foreach (array('scp_source_node_id' => (int)$node->key, 'scp_copy_node_id' => (int)$retired->key, 'scp_release' => '0.8.1',
		'scp_status' => SiteCopy::STATUS_SWITCHED) as $k => $v) {
	$copy->set($k, $v);
}
$copy->save();
harness_register_row('scp_site_copies', 'scp_site_copy_id', $copy->key);

$job = ManagementJob::createPrimitiveJob((int)$retired->key, 'site_quiet', 'site_quiet', array('action' => 'forward', 'to' => '198.51.100.9'), null);
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $job->key);

/** A claim signed as node $as with $secret, its body naming $body_id. */
$claim = function (int $as, string $secret, ?int $body_id = null) {
	$raw = json_encode(array('node_id' => $body_id ?? $as, 'agent_version' => '1.84.2'));
	$ts = (string)time();
	$nonce = bin2hex(random_bytes(8));
	$msg = "joinery-agent-v1\nPOST\n/api/v1/agent/claim\n{$as}\n{$ts}\n{$nonce}\n" . hash('sha256', $raw);
	$r = harness_request('POST', '/api/v1/agent/claim', array('body' => $raw, 'headers' => array(
		'Content-Type: application/json',
		'X-Joinery-Agent-Node: ' . $as,
		'X-Joinery-Agent-Timestamp: ' . $ts,
		'X-Joinery-Agent-Nonce: ' . $nonce,
		'X-Joinery-Agent-Signature: ' . base64_encode(sodium_crypto_sign_detached($msg, $secret)),
	)));
	$r['json'] = json_decode((string)$r['body'], true);
	return $r;
};
$poll = function (ManagedNode $n) { $n->load(); return (string)$n->get('mgn_agent_last_poll'); };

// ---------------------------------------------------------------------------
section('The old machine, signing as the node, is the retired row');
// ---------------------------------------------------------------------------

$r = $claim((int)$node->key, sodium_crypto_sign_secretkey($old_machine));
$got = (int)($r['json']['data']['job']['job_id'] ?? $r['json']['job']['job_id'] ?? 0);
check($r['status'] === 200 && $got === (int)$job->key, 'its claim verifies and takes the retired row\'s job',
	$r['status'] . ' ' . substr((string)$r['body'], 0, 300));
$addressed = (int)($r['json']['data']['job']['node_id'] ?? $r['json']['job']['node_id'] ?? 0);
check($addressed === (int)$node->key, 'the job is addressed to the node id it signed as, which the machine holds as its own',
	'addressed to ' . $addressed);
check($poll($retired) !== '' && $poll($node) === '', 'the poll is recorded on the retired row, not the node');

// ---------------------------------------------------------------------------
section('Everything else is as it was');
// ---------------------------------------------------------------------------

$r = $claim((int)$node->key, sodium_crypto_sign_secretkey($copy_machine));
check($r['status'] === 200 && $poll($node) !== '', 'the node\'s own machine is the node', $r['status'] . ' ' . substr((string)$r['body'], 0, 200));
$r = $claim((int)$node->key, sodium_crypto_sign_secretkey($stranger));
check($r['status'] === 401, 'a retired row\'s key that is not this switch-over\'s is refused', (string)$r['status']);
$r = $claim((int)$node->key, sodium_crypto_sign_secretkey($old_machine), (int)$retired->key);
check($r['status'] === 401, 'a body naming the retired row, signed as the node, is refused', (string)$r['status']);
// Going back after the take-over swaps the rows again: the copy's machine,
// which took the node's id, holds the copy's row's key.
$retired->set('mgn_agent_public_key', base64_encode(sodium_crypto_sign_publickey($copy_machine)));
$retired->set('mgn_install_state', 'copy');
$retired->save();
$node->set('mgn_agent_public_key', base64_encode(sodium_crypto_sign_publickey($old_machine)));
$node->save();
$copy->set('scp_status', SiteCopy::STATUS_RETURNING);
$copy->save();
$before = $poll($retired);
sleep(1);
$r = $claim((int)$node->key, sodium_crypto_sign_secretkey($copy_machine));
check($r['status'] === 200 && $poll($retired) !== $before, 'after going back, the copy\'s machine signing as the node is the copy\'s row',
	$r['status'] . ' ' . substr((string)$r['body'], 0, 200));
$copy->set('scp_status', SiteCopy::STATUS_FINISHED);
$copy->save();
$r = $claim((int)$node->key, sodium_crypto_sign_secretkey($copy_machine));
check($r['status'] === 401, 'once the switch-over has ended, the other machine is refused', (string)$r['status']);

harness_finish();
