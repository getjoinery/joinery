<?php
/** @joinery-test
 * name: agent_kem_key
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 *
 * A node's own X-Wing key (specs/post_quantum_sealing.md R6): its agent
 * reports it at every poll with its agent key's signature over
 * "joinery-agent-kem-v1\n" and the base64 key, and the plane keeps it only
 * when that signature verifies against the agent key it approved. A copy
 * export is sealed to it, so a key the node did not sign is never kept.
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$pair = sodium_crypto_sign_keypair();
$node = new ManagedNode(NULL);
$node->set('mgn_agent_public_key', base64_encode(sodium_crypto_sign_publickey($pair)));
$sign = function (string $kem_b64) use ($pair) {
	return base64_encode(sodium_crypto_sign_detached("joinery-agent-kem-v1\n" . $kem_b64, sodium_crypto_sign_secretkey($pair)));
};
$kem = base64_encode((new XWing())->publicKey(random_bytes(32)));

section('kept when its agent key signed it');
check(AgentChannelEndpoint::verified_kem_key($node, $kem, $sign($kem)) === array($kem, $sign($kem)),
	'a 1216-byte key with the agent key\'s signature is kept, with its signature');

section('the agent\'s own signer and this verifier agree');
// Made by the agent's KEMStatement from fixed seeds (joinery-agent
// kem_statement_test.go, which holds its copy to the same bytes).
$fx = json_decode((string)file_get_contents(__DIR__ . '/fixtures/agent_kem_statement.json'), true);
$agent_node = new ManagedNode(NULL);
$agent_node->set('mgn_agent_public_key', (string)($fx['agent_public_key'] ?? ''));
check(is_array($fx) && AgentChannelEndpoint::verified_kem_key($agent_node, (string)$fx['kem_public_key'], (string)$fx['kem_signature'])
		=== array((string)$fx['kem_public_key'], (string)$fx['kem_signature']),
	'a statement the Go agent signed verifies here, byte for byte');

section('refused otherwise');
$other = base64_encode((new XWing())->publicKey(random_bytes(32)));
check(AgentChannelEndpoint::verified_kem_key($node, $other, $sign($kem)) === null,
	'another key with this key\'s signature: a management node cannot substitute its own');
$stranger = sodium_crypto_sign_keypair();
$by_stranger = base64_encode(sodium_crypto_sign_detached("joinery-agent-kem-v1\n" . $kem, sodium_crypto_sign_secretkey($stranger)));
check(AgentChannelEndpoint::verified_kem_key($node, $kem, $by_stranger) === null, 'signed by another machine\'s key');
$short = base64_encode(random_bytes(32));
check(AgentChannelEndpoint::verified_kem_key($node, $short, $sign($short)) === null, 'an old 32-byte key, even signed');
$wrong_domain = base64_encode(sodium_crypto_sign_detached("joinery-copy-export-v1\n" . $kem, sodium_crypto_sign_secretkey($pair)));
check(AgentChannelEndpoint::verified_kem_key($node, $kem, $wrong_domain) === null, 'a signature under another domain');
check(AgentChannelEndpoint::verified_kem_key($node, '', '') === null, 'nothing reported (an agent before 1.99.0)');
$bare = new ManagedNode(NULL);
check(AgentChannelEndpoint::verified_kem_key($bare, $kem, $sign($kem)) === null, 'a node with no agent key on record');

harness_finish();
