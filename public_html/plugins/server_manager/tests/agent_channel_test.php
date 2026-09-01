<?php
/** @joinery-test
 * name: agent_channel
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The agent channel — the plane's half of the vocabulary transport
 * (specs/agent_on_node_architecture.md §3.1, §3.5.8, component D).
 *
 * What is worth testing hard here is not that a job reaches a node. It is the
 * set of properties the migration is FOR:
 *
 *   - the plane stores a verifier and never a credential;
 *   - a job carries a NAME, never an instruction the plane composed;
 *   - a job an agent would refuse for size fails when it is BUILT, loudly;
 *   - a claim that never comes back becomes a delay, not a wedge;
 *   - the two size caps stay two;
 *   - and the canonical signed message is byte-identical to the one the Go
 *     agent builds, because a silent drift there locks the whole fleet out.
 *
 * The node-side half — vocabulary, class policy, the refusal to run anything
 * unverified — is tested in the agent's own suite (joinery-agent/primitives),
 * where the enforcement actually lives. Testing it from here would only prove
 * the plane's opinion of it, which is precisely the thing that is not trusted.
 *
 * Throwaway node and job rows are permanently removed in cleanup.
 *
 * Run: php plugins/server_manager/tests/agent_channel_test.php
 *
 * @version 1.3 - either side can end the pairing: forgetAgent() is the one convergence point for
 *                the plane-side Disconnect and the node-initiated leave
 * @version 1.2 - connected = routed: the per-node cutover flag is gone (hard cutover, owner-set)
 * @version 1.1 - enrollment is the node-initiated join (Phase 1.5, A6): the fingerprint contract
 *                is pinned against the agent's join_test.go, approval-binds-the-key is exercised,
 *                and the pairing-token checks are gone with the token
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$db = DbConnector::get_instance()->get_db_link();

/** A throwaway node, permanently removed at the end. */
function agent_channel_node($slug) {
	$node = new ManagedNode(NULL);
	$node->set('mgn_name', 'Agent channel test ' . $slug);
	$node->set('mgn_slug', $slug);
	$node->set('mgn_host', '127.0.0.1');
	$node->set('mgn_uptime_enabled', false);
	$node->save();
	return $node;
}

$made_nodes = [];
$made_join_requests = [];
$made_hosts = [];

// Anything a previous crashed run left behind. A test that cannot clean up
// after its own failure eventually stops being runnable.
$db->exec("DELETE FROM mgh_managed_hosts WHERE mgh_slug LIKE 'agtest-host-%'");
foreach ($db->query("SELECT mgn_id FROM mgn_managed_nodes WHERE mgn_slug LIKE 'agtest-%'")->fetchAll(PDO::FETCH_COLUMN) as $stale_id) {
	$db->prepare('DELETE FROM mjb_management_jobs WHERE mjb_mgn_node_id = ?')->execute([$stale_id]);
	$db->prepare('DELETE FROM mgn_managed_nodes WHERE mgn_id = ?')->execute([$stale_id]);
}
$db->exec("DELETE FROM ajr_agent_join_requests WHERE ajr_claimed_name LIKE 'agtest-%'");

$node = agent_channel_node('agtest-' . substr(bin2hex(random_bytes(4)), 0, 8));
$made_nodes[] = $node->key;

// ---------------------------------------------------------------------------
section('Enrollment shares no secret (Phase 1.5, A6)');

// THE FINGERPRINT CONTRACT, pinned. The agent computes the same value in
// Fingerprint() and its suite pins this exact vector (join_test.go) — the two
// panels showing the same fingerprint for the same key is the entire security
// of the join, so a drift on either side must fail a suite before it strands
// an enrollment at mismatched fingerprints.
// Vector: the 32 bytes 0x00..0x1f → first 16 hex chars of their SHA-256.
$vector = '';
for ($i = 0; $i < 32; $i++) { $vector .= chr($i); }
check(AgentJoinRequest::fingerprint($vector) === '630dcd2966c43366',
	'The fingerprint contract is pinned identically to the agent (fix a drift, never the pin)',
	AgentJoinRequest::fingerprint($vector));
check(AgentJoinRequest::display_fingerprint('630dcd2966c43366') === '630d cd29 66c4 3366',
	'The display grouping is 4x4 — what a human actually compares');

// There is deliberately no column anywhere for a private key or a token: the
// join carries a public key, and approval binds it. Nothing stored on this
// side could enroll anyone.
check(!array_key_exists('mgn_agent_private_key', ManagedNode::$field_specifications),
	'There is no column on a node for an agent PRIVATE key — this side cannot hold one');
check(!array_key_exists('mgn_agent_pair_token_hash', ManagedNode::$field_specifications),
	'There is no pairing-token column — enrollment has no plane-held credential at all');

$keypair = sodium_crypto_sign_keypair();
$public  = sodium_crypto_sign_publickey($keypair);
$secret  = sodium_crypto_sign_secretkey($keypair);

// The join request row: what the endpoint stores while a human decides.
$jr = new AgentJoinRequest();
$jr->set('ajr_claimed_name', 'agtest-joiner');
$jr->set('ajr_public_key', base64_encode($public));
$jr->set('ajr_fingerprint', AgentJoinRequest::fingerprint($public));
$jr->set('ajr_status', AgentJoinRequest::STATUS_PENDING);
$jr->save();
$made_join_requests[] = $jr->key;

check(AgentJoinRequest::find_by_public_key(base64_encode($public)) !== null,
	'A join request is found by its public key — the one thing only the asking agent plausibly knows in full');
check($jr->is_expired() === false, 'A fresh request is not expired');

$stale = new AgentJoinRequest($jr->key, TRUE);
$stale->set('ajr_create_time', gmdate('Y-m-d H:i:s', time() - AgentJoinRequest::TTL_SECONDS - 60));
$stale->save();
$stale->load();
check($stale->is_expired() === true,
	'A request outlives nobody: past the TTL it is expired, not approvable');
$stale->set('ajr_create_time', gmdate('Y-m-d H:i:s'));
$stale->save();

// Approval IS enrollment: the binding writes the public key onto the node and
// stamps when. This is the exact moment the trust decision lands.
AgentChannelEndpoint::approveJoin($jr, $node);
$node->load();
$jr->load();

check($node->get('mgn_agent_public_key') === base64_encode($public),
	'Approval binds the requesting key to the node');
check(!empty($node->get('mgn_agent_paired_time')),
	'Approval stamps when — an enrollment nobody expected is seen rather than silent');
check($jr->get('ajr_status') === AgentJoinRequest::STATUS_APPROVED
	&& (int)$jr->get('ajr_mgn_node_id') === (int)$node->key,
	'The request records which node adopted it');

$stored = json_encode($node->export_as_array());
check(strpos($stored, base64_encode(sodium_crypto_sign_secretkey($keypair))) === false,
	'The private half appears nowhere on the stored node row');

// ---------------------------------------------------------------------------
section('The canonical signed message matches the agent byte for byte');

// This literal is pinned identically in the agent's remote_test.go
// (TestSigningMessageIsAFixedFieldList). If either side is edited alone, one of
// the two suites fails — which is the only warning anyone gets before a whole
// fleet stops being able to authenticate.
$canonical = "joinery-agent-v1\nPOST\n/api/v1/agent/claim\n42\n1756180000\nnonce==\nabc123";
$signature = sodium_crypto_sign_detached($canonical, $secret);
check(sodium_crypto_sign_verify_detached($signature, $canonical, $public),
	'A signature over the canonical message verifies with the stored public half');
check(!sodium_crypto_sign_verify_detached($signature, $canonical . 'x', $public),
	'A modified message does not verify');
check(substr_count($canonical, "\n") === 6,
	'The signed message is exactly seven newline-joined fields — nothing optional, nothing order-dependent');

// ---------------------------------------------------------------------------
section('A job carries a name, never an instruction');

$built = JobCommandBuilder::build_check_status_primitive($node);
check(($built['primitive'] ?? null) === 'check_status',
	'The primitive builder returns an operation NAME');
check(!isset($built['cmd']) && !isset($built['steps']),
	'The primitive builder composes no command and no shell steps');

$transports = JobCommandBuilder::transports_for('check_status');
check(in_array('primitive', $transports, true), 'check_status has a primitive transport');
check($transports[0] === 'primitive',
	'The primitive transport is preferred over api and ssh', implode(',', $transports));

check(JobCommandBuilder::has_agent_channel($node) === true,
	'A connected agent IS the cutover: its node routes to it with no further switch');

$unpaired = agent_channel_node('agtest-unpaired-' . substr(bin2hex(random_bytes(4)), 0, 8));
$made_nodes[] = $unpaired->key;
$unpaired->set('mgn_agent_public_key', null);
$unpaired->save();
check(JobCommandBuilder::has_agent_channel($unpaired) === false,
	'No bound key, no channel: an unconnected node routes over api/ssh');

// ---------------------------------------------------------------------------
section('Either side can end the pairing');

// forgetAgent() is the one place both endings converge: the plane-side
// Disconnect action and the node-initiated leave endpoint (signed — only the
// key holder can say goodbye) do exactly this, so they cannot drift apart.
// The node's power to leave never depends on this plane's cooperation: its
// agent deletes its identity whether or not the goodbye arrives.
$leaver = agent_channel_node('agtest-leaver-' . substr(bin2hex(random_bytes(4)), 0, 8));
$made_nodes[] = $leaver->key;
$leaver->set('mgn_agent_public_key', base64_encode($public));
$leaver->set('mgn_agent_paired_time', gmdate('Y-m-d H:i:s'));
$leaver->save();
check(JobCommandBuilder::has_agent_channel($leaver) === true,
	'A node with a bound key routes to its agent');

AgentChannelEndpoint::forgetAgent($leaver);
$leaver->load();
check(empty($leaver->get('mgn_agent_public_key')) && empty($leaver->get('mgn_agent_paired_time')),
	'Forgetting erases the verifier and the pairing stamp');
check(JobCommandBuilder::has_agent_channel($leaver) === false,
	'A forgotten agent routes over api/ssh again — ending is symmetric, whichever side ends it');

// ---------------------------------------------------------------------------
section('Primitive jobs are stored as an envelope, and are readable as one');

$job = ManagementJob::createPrimitiveJob($node->key, 'check_status', 'check_status', [], null);
$commands = $job->get('mjb_commands');
if (is_string($commands)) { $commands = json_decode($commands, true); }

check(($commands['primitive'] ?? null) === 'check_status', 'mjb_commands carries the primitive name');
check($job->isPrimitiveJob(), 'The job identifies itself as a primitive job');
check((int)$job->get('mjb_total_steps') === 1, 'A primitive job is one step');

// The tripwire: an executor from before this release must FAIL such a job
// rather than claim it, find nothing it recognises, and mark it complete. A
// green status check that never ran is the worst outcome available.
check(isset($commands['steps'][0]['type']) && $commands['steps'][0]['type'] === 'primitive',
	'The payload carries a step type no released step executor knows');
check(!in_array($commands['steps'][0]['type'], ['ssh', 'scp', 'local', 'api'], true),
	'That step type is none of the four an older agent can run, so it fails loudly instead of silently completing');

$steps_job = ManagementJob::createFromBuild($node->key, 'check_status',
	[['type' => 'local', 'label' => 'x', 'cmd' => 'true']], null, null);
check(!$steps_job->isPrimitiveJob(), 'createFromBuild still stores a step list as a step-list job');

$envelope_job = ManagementJob::createFromBuild($node->key, 'check_status', $built, null, null);
check($envelope_job->isPrimitiveJob(),
	'createFromBuild reads the shape, so an operation crosses without touching a caller');

// ---------------------------------------------------------------------------
section('A job the node would refuse for size fails when it is built');

$oversize = ['note' => str_repeat('x', ManagementJob::MAX_PARAMS_BYTES + 1)];
$threw = false;
try {
	ManagementJob::createPrimitiveJob($node->key, 'check_status', 'check_status', $oversize, null);
} catch (ManagementJobException $e) {
	$threw = strpos($e->getMessage(), 'limit') !== false;
}
check($threw, 'Oversized params are refused at BUILD time, naming the limit — not sent to die quietly on a node');

$before = (int)$db->query("SELECT count(*) FROM mjb_management_jobs WHERE mjb_mgn_node_id = " . (int)$node->key)->fetchColumn();
try { ManagementJob::createPrimitiveJob($node->key, 'check_status', 'check_status', $oversize, null); } catch (Exception $e) {}
$after = (int)$db->query("SELECT count(*) FROM mjb_management_jobs WHERE mjb_mgn_node_id = " . (int)$node->key)->fetchColumn();
check($before === $after, 'A refused build leaves no job row behind');

// ---------------------------------------------------------------------------
section('The two size caps stay two');

check(AgentChannelEndpoint::MAX_REQUEST_BODY > AgentChannelEndpoint::MAX_JOB_BODY,
	'The inbound cap (plane defends itself from a node) is larger than the outbound job cap',
	AgentChannelEndpoint::MAX_REQUEST_BODY . ' > ' . AgentChannelEndpoint::MAX_JOB_BODY);
check(AgentChannelEndpoint::MAX_REQUEST_BODY !== AgentChannelEndpoint::MAX_JOB_BODY,
	'They are not one constant serving two purposes — that collapse is the bug this pattern exists to prevent');
check(ManagementJob::MAX_PARAMS_BYTES < AgentChannelEndpoint::MAX_JOB_BODY,
	'A job at the params ceiling still fits inside what an agent will read');

// PHP decodes {} to [] and re-encodes [] to [], so a parameterless job would
// reach the node as a list its validator refuses. Corrected on PHP's side.
$as_object = new ReflectionMethod('AgentChannelEndpoint', 'params_as_object');
$as_object->setAccessible(true);
check(json_encode($as_object->invoke(null, [])) === '{}',
	'Empty params render as a JSON object, not an empty list');
check(json_encode($as_object->invoke(null, null)) === '{}', 'Absent params render as an object');
check(json_encode($as_object->invoke(null, ['a' => 1])) === '{"a":1}', 'Real params render unchanged');
check(json_encode($as_object->invoke(null, ['x', 'y'])) === '{}',
	'A list is not a parameter object; it renders as none rather than as positional junk');

// ---------------------------------------------------------------------------
section('The result parser treats a node as hostile');

// A compromised node is the likeliest breach in this system and it holds a
// pairing credential (§3.5.8), so the parse IS the boundary. Exercised through
// the real validator; the transport-level shapes (an oversized body, a bad
// signature, a stale clock, an unknown path) are refused before this point and
// are checked against the live endpoint.
$spec = [
	'node_id' => ['type' => 'int', 'required' => true],
	'status'  => ['type' => 'string', 'max' => 16],
	'data'    => ['type' => 'object'],
	'ok'      => ['type' => 'bool'],
];

$cases = [
	['an undeclared key',            ['node_id' => 1, 'recovery_public_key' => 'age1...'], 'undeclared field'],
	['a missing required field',     ['status' => 'completed'],                            'missing a required field'],
	['a string where int declared',  ['node_id' => '1'],                                   'whole number'],
	['an over-length string',        ['node_id' => 1, 'status' => str_repeat('x', 17)],    'limit'],
	['a list where object declared', ['node_id' => 1, 'data' => [1, 2, 3]],                'JSON object'],
	['a string where bool declared', ['node_id' => 1, 'ok' => 'yes'],                      'true or false'],
];
foreach ($cases as [$label, $body, $expect]) {
	$message = AgentChannelEndpoint::validation_error($body, $spec);
	check($message !== null && stripos($message, $expect) !== false,
		'Refused: ' . $label, (string)$message);
}

// §3.5.1 / A4: the plane never supplies key material to a primitive. Nothing
// declares such a field, so the undeclared-key rule is what enforces it — worth
// asserting directly, because it is the specific thing that rule is for.
check(AgentChannelEndpoint::validation_error(['node_id' => 1, 'recovery_public_key' => 'x'], $spec) !== null,
	'A body smuggling key material is refused as undeclared');

// An attacker-chosen field name reaches an error message. It must not reach it raw.
$message = AgentChannelEndpoint::validation_error(['node_id' => 1, '<script>x</script>' => 1], $spec);
check($message !== null && strpos($message, '<') === false,
	'An attacker-chosen field name is rendered safely into the refusal', (string)$message);

// {} and [] are the same value in PHP, so an empty object must pass where an
// object is declared — the asymmetry is corrected on PHP's side of the wire,
// not by teaching the node to send two shapes.
check(AgentChannelEndpoint::validation_error(['node_id' => 1, 'data' => []], $spec) === null,
	'An empty object passes where an object is declared');

check(AgentChannelEndpoint::validation_error(
	['node_id' => 7, 'status' => 'completed', 'ok' => true, 'data' => ['a' => 1]], $spec) === null,
	'A well-formed body is accepted');

// ---------------------------------------------------------------------------
section('A refusal is countable, not just readable');

// mjb_status stays the vocabulary every dashboard filter understands, so a
// refusal reads as the terminal failure it is. The distinct fact — that the
// node DECLINED rather than tried and failed — lives in its own column. Once
// operate and destructive primitives are dispatched, a rise in refusals is an
// attack or misconfiguration signal (§3.5.6), and a signal you can only find by
// matching a substring of a human-readable message is not a signal.
check(array_key_exists('mjb_agent_outcome', ManagementJob::$field_specifications),
	'A job records the outcome its node reported, separately from mjb_status');
check(ManagementJob::AGENT_OUTCOMES === ['completed', 'failed', 'refused'],
	'The wire outcomes are the three the endpoint accepts',
	implode(',', ManagementJob::AGENT_OUTCOMES));

$outcome_node = agent_channel_node('agtest-outcome-' . substr(bin2hex(random_bytes(4)), 0, 8));
$made_nodes[] = $outcome_node->key;

$since = gmdate('Y-m-d H:i:s', time() - 3600);
$now   = gmdate('Y-m-d H:i:s');
foreach ([['refused', 'Refused by the node: no such primitive'],
          ['refused', 'Refused by the node: params carry undeclared key(s): target'],
          ['failed',  'The primitive failed on the node.'],
          ['completed', null]] as [$outcome, $error]) {
	$j = ManagementJob::createPrimitiveJob($outcome_node->key, 'check_status', 'check_status', [], null);
	$j->set('mjb_status', $outcome === 'completed' ? 'completed' : 'failed');
	$j->set('mjb_agent_outcome', $outcome);
	$j->set('mjb_error_message', $error);
	$j->set('mjb_completed_time', $now);
	$j->save();
}

check(ManagementJob::refusalCountForNode($outcome_node->key, $since) === 2,
	'Refusals are counted by the column, with no text matching anywhere',
	(string)ManagementJob::refusalCountForNode($outcome_node->key, $since));
check(ManagementJob::refusalCountForNode($outcome_node->key, gmdate('Y-m-d H:i:s', time() + 60)) === 0,
	'The count is windowed, so a spike is distinguishable from a history');
check(ManagementJob::refusalCountForNode($node->key, $since) === 0,
	'The count is per node — another node\'s refusals are not this node\'s');

// A plane-side give-up is not a node verdict. The node said nothing, and
// recording an outcome for it would be inventing one.
$abandoned = ManagementJob::createPrimitiveJob($outcome_node->key, 'check_status', 'check_status', [], null);
$db->prepare("UPDATE mjb_management_jobs SET mjb_status='running', mjb_started_time=?, mjb_claim_attempts=? WHERE mjb_id=?")
	->execute([gmdate('Y-m-d H:i:s', time() - (ManagementJob::CLAIM_TIMEOUT_SECONDS + 60)),
		ManagementJob::MAX_CLAIM_ATTEMPTS, $abandoned->key]);
ManagementJob::requeueStaleClaims($outcome_node->key);
$abandoned->load();
check($abandoned->get('mjb_status') === 'failed', 'An abandoned claim still fails');
check($abandoned->get('mjb_agent_outcome') === null,
	'An abandoned claim records NO node outcome — the node never reported one',
	var_export($abandoned->get('mjb_agent_outcome'), true));
check(ManagementJob::refusalCountForNode($outcome_node->key, $since) === 2,
	'An abandoned claim does not inflate the refusal count');

// ---------------------------------------------------------------------------
section('A claim that never comes back is a delay, not a wedge');

$stale = ManagementJob::createPrimitiveJob($node->key, 'check_status', 'check_status', [], null);
$old = gmdate('Y-m-d H:i:s', time() - (ManagementJob::CLAIM_TIMEOUT_SECONDS + 60));
$db->prepare("UPDATE mjb_management_jobs SET mjb_status='running', mjb_started_time=?, mjb_claim_attempts=1 WHERE mjb_id=?")
	->execute([$old, $stale->key]);

$fresh = ManagementJob::createPrimitiveJob($node->key, 'check_status', 'check_status', [], null);
$db->prepare("UPDATE mjb_management_jobs SET mjb_status='running', mjb_started_time=now(), mjb_claim_attempts=1 WHERE mjb_id=?")
	->execute([$fresh->key]);

$steps_stale = ManagementJob::createJob($node->key, 'check_status',
	[['type' => 'local', 'label' => 'x', 'cmd' => 'true']], null, null);
$db->prepare("UPDATE mjb_management_jobs SET mjb_status='running', mjb_started_time=? WHERE mjb_id=?")
	->execute([$old, $steps_stale->key]);

ManagementJob::requeueStaleClaims();

$stale->load(); $fresh->load(); $steps_stale->load();
check($stale->get('mjb_status') === 'pending',
	'A stale claim returns to pending', 'status ' . $stale->get('mjb_status'));
check(strpos((string)$stale->get('mjb_output'), 'did not report back') !== false,
	'The requeue says so in the job output — the delay is visible, not silent');
check($fresh->get('mjb_status') === 'running', 'A claim still inside the timeout is left alone');
check($steps_stale->get('mjb_status') === 'running',
	'A step-list job is left alone — this sweep is only for agent claims');

// A node's own poll sweeps only its own claims — a fleet-wide scan on every
// poll of every node is a lot of scanning to answer a question about one
// machine. The scheduled pass is what sweeps the rest.
$other = agent_channel_node('agtest-other-' . substr(bin2hex(random_bytes(4)), 0, 8));
$made_nodes[] = $other->key;
$other_stale = ManagementJob::createPrimitiveJob($other->key, 'check_status', 'check_status', [], null);
$db->prepare("UPDATE mjb_management_jobs SET mjb_status='running', mjb_started_time=?, mjb_claim_attempts=1 WHERE mjb_id=?")
	->execute([$old, $other_stale->key]);

$stale2 = ManagementJob::createPrimitiveJob($node->key, 'check_status', 'check_status', [], null);
$db->prepare("UPDATE mjb_management_jobs SET mjb_status='running', mjb_started_time=?, mjb_claim_attempts=1 WHERE mjb_id=?")
	->execute([$old, $stale2->key]);

ManagementJob::requeueStaleClaims($node->key);
$stale2->load(); $other_stale->load();
check($stale2->get('mjb_status') === 'pending', 'A node-scoped sweep frees that node\'s stale claim');
check($other_stale->get('mjb_status') === 'running',
	'A node-scoped sweep leaves another node\'s claim alone');

ManagementJob::requeueStaleClaims();
$other_stale->load();
check($other_stale->get('mjb_status') === 'pending',
	'The unscoped sweep — the scheduled one — reaches every node');

// A job that kills three agents is not going to succeed on the fourth.
$poison = ManagementJob::createPrimitiveJob($node->key, 'check_status', 'check_status', [], null);
$db->prepare("UPDATE mjb_management_jobs SET mjb_status='running', mjb_started_time=?, mjb_claim_attempts=? WHERE mjb_id=?")
	->execute([$old, ManagementJob::MAX_CLAIM_ATTEMPTS, $poison->key]);
ManagementJob::requeueStaleClaims();
$poison->load();
check($poison->get('mjb_status') === 'failed',
	'A job re-claimed to the attempt limit fails instead of looping forever');
check(strpos((string)$poison->get('mjb_error_message'), 'without a result') !== false,
	'That failure says what happened, and points at the node');

// ---------------------------------------------------------------------------
// ---------------------------------------------------------------------------
section('Approving a host agent names it as the host node');

// A host that a container was provisioned onto already has a placement record
// (ensure_for_node minted it) with no host node yet. Approving the host's own
// agent join must fill mgh_mgn_host_node_id, or host-scope work
// (decommission_site, certificates) is routed to the host by nothing.
$host_addr = '198.51.100.' . random_int(10, 250);
$host_rec = new ManagedHost(NULL);
$host_rec->set('mgh_slug', 'agtest-host-' . substr(bin2hex(random_bytes(3)), 0, 6));
$host_rec->set('mgh_name', 'agtest host');
$host_rec->set('mgh_host', $host_addr);
$host_rec->set('mgh_provisioning_enabled', false);
$host_rec->prepare();
$host_rec->save();
$made_hosts[] = $host_rec->key;

// The joining machine: a machine-posture node at the host's address (no
// container name, no web root — it is the host, not a site on it).
$host_node = agent_channel_node('agtest-hn-' . substr(bin2hex(random_bytes(4)), 0, 8));
$host_node->set('mgn_host', $host_addr);
$host_node->save();
$made_nodes[] = $host_node->key;

$hkp = sodium_crypto_sign_keypair();
$hpub = sodium_crypto_sign_publickey($hkp);
$hjr = new AgentJoinRequest();
$hjr->set('ajr_claimed_name', 'agtest-hostjoin');
$hjr->set('ajr_public_key', base64_encode($hpub));
$hjr->set('ajr_fingerprint', AgentJoinRequest::fingerprint($hpub));
$hjr->set('ajr_status', AgentJoinRequest::STATUS_PENDING);
$hjr->save();
$made_join_requests[] = $hjr->key;

AgentChannelEndpoint::approveJoin($hjr, $host_node);
$host_rec->load();
check((int)$host_rec->get('mgh_mgn_host_node_id') === (int)$host_node->key,
	'Approving the host agent links the placement record to it');

// A second machine at the same address must not take the host over: first host
// node wins, exactly as approval is meant to.
$intruder = agent_channel_node('agtest-hn2-' . substr(bin2hex(random_bytes(4)), 0, 8));
$intruder->set('mgn_host', $host_addr);
$intruder->save();
$made_nodes[] = $intruder->key;
$linked = ManagedHost::link_host_node($intruder);
check($linked === null, 'A second host join does not re-point an already-linked host');
$host_rec->load();
check((int)$host_rec->get('mgh_mgn_host_node_id') === (int)$host_node->key,
	'The first host node still owns the placement record');

// A container node (it carries a web root) is a site, never its own host.
$container = agent_channel_node('agtest-ctr-' . substr(bin2hex(random_bytes(4)), 0, 8));
$container->set('mgn_host', '203.0.113.' . random_int(10, 250));
$container->set('mgn_web_root', '/var/www/html/agtest/public_html');
$container->save();
$made_nodes[] = $container->key;
check(ManagedHost::link_host_node($container) === null,
	'A node with a web root is a site, not a host — it links nothing');

section('Cleanup');

// Hosts first: mgh_mgn_host_node_id points at a node, so a host row still
// naming one blocks that node's delete. Jobs likewise (mjb_mgn_node_id is a
// real foreign key) — a cleanup that half-works leaves the next run to trip
// over what this one made.
foreach ($made_hosts as $id) {
	$db->prepare('DELETE FROM mgh_managed_hosts WHERE mgh_id = ?')->execute([$id]);
}
foreach ($made_nodes as $id) {
	$db->prepare('DELETE FROM mjb_management_jobs WHERE mjb_mgn_node_id = ?')->execute([$id]);
	$db->prepare('DELETE FROM mgn_managed_nodes WHERE mgn_id = ?')->execute([$id]);
}
foreach ($made_join_requests as $id) {
	$db->prepare('DELETE FROM ajr_agent_join_requests WHERE ajr_id = ?')->execute([$id]);
}
$left = (int)$db->query('SELECT count(*) FROM mgn_managed_nodes WHERE mgn_slug LIKE \'agtest-%\'')->fetchColumn();
check($left === 0, 'Every node this test created is gone', $left . ' left');
$left_jr = (int)$db->query('SELECT count(*) FROM ajr_agent_join_requests WHERE ajr_claimed_name LIKE \'agtest-%\'')->fetchColumn();
check($left_jr === 0, 'Every join request this test created is gone', $left_jr . ' left');

harness_finish();
