<?php
/** @joinery-test
 * name: agent_channel_metering
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The agent channel fills the rate buckets it is checked against.
 *
 * The channel refuses a request when its bucket is over its limit, and a
 * request that a node's signature proves goes in that node's own bucket
 * (`api_agent_node`, keyed node:ID); one that proves no node goes in its
 * address's (`api_agent`). A multi-tenant host's sites share one address
 * (specs/multi_tenant_docker_hosts.md S23). This test pins:
 *   - the metering rule: everything counts except a successful claim, the
 *     steady-state poll (tens of thousands a day on a modest fleet);
 *   - that real requests to the channel land as rows with their outcome, an
 *     unsigned one under its address and a signed one under its node;
 *   - that an address over its limit refuses what proves no node and still
 *     lets in a request whose signature verifies;
 *   - that a node over its own limit is refused, and its neighbour is not;
 *   - that each check has a writer in the tree.
 *
 * The rows this test's own requests create are removed in cleanup.
 *
 * Run: php plugins/server_manager/tests/agent_channel_metering_test.php
 *
 * @version 1.1 - per node for a signed request, per address for the rest (S23)
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/http.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/AgentChannelEndpoint.php'));

$db = DbConnector::get_instance()->get_db_link();

// ---------------------------------------------------------------------------
section('The metering rule');
// ---------------------------------------------------------------------------

check(AgentChannelEndpoint::meterOutcome('claim', 200) === false, 'a successful claim (the poll) is not counted');
check(AgentChannelEndpoint::meterOutcome('claim', 401) === true, 'a refused claim counts');
check(AgentChannelEndpoint::meterOutcome('claim', 400) === true, 'a malformed claim counts');
check(AgentChannelEndpoint::meterOutcome('claim', 429) === true, 'a throttled claim counts');
check(AgentChannelEndpoint::meterOutcome('job_status', 200) === false, 'a successful job_status (asked through an approval wait) is not counted');
check(AgentChannelEndpoint::meterOutcome('job_status', 401) === true, 'a refused job_status counts');
foreach (['join', 'join_status', 'result', 'leave', 'quiet', 'artifact', '', 'nonsense'] as $ep) {
	check(AgentChannelEndpoint::meterOutcome($ep, 200) === true && AgentChannelEndpoint::meterOutcome($ep, 404) === true,
		"'{$ep}' counts whatever the outcome");
}

// ---------------------------------------------------------------------------
section('Requests on the channel land as api_agent rows');
// ---------------------------------------------------------------------------

$mark = (int)$db->query("SELECT COALESCE(MAX(rql_request_log_id), 0) FROM rql_request_logs")->fetchColumn();

$rows_since = function () use ($db, $mark) {
	$st = $db->prepare("SELECT rql_action, rql_was_success, rql_status_code FROM rql_request_logs
		WHERE rql_request_log_id > ? AND rql_feature = 'api_agent' ORDER BY rql_request_log_id");
	$st->execute([$mark]);
	return $st->fetchAll(PDO::FETCH_ASSOC);
};

// An unsigned claim: refused 401, and that refusal is exactly what a flood looks like.
$r = harness_request('POST', '/api/v1/agent/claim', ['body' => ['node_id' => 1]]);
check($r['status'] === 401, 'unsigned claim is refused (401), got ' . $r['status']);

// A malformed join_status: 400.
$r2 = harness_request('POST', '/api/v1/agent/join_status', ['body' => ['nope' => true]]);
check($r2['status'] === 400, 'malformed join_status is refused (400), got ' . $r2['status']);

// An unknown endpoint: 404.
$r3 = harness_request('POST', '/api/v1/agent/bogus_thing', ['body' => []]);
check($r3['status'] === 404, 'unknown agent endpoint is 404, got ' . $r3['status']);

// A GET: 405.
$r4 = harness_request('GET', '/api/v1/agent/claim');
check($r4['status'] === 405, 'GET on the channel is 405, got ' . $r4['status']);

// The rows are written at shutdown; give php-fpm a moment to finish them.
$rows = [];
for ($i = 0; $i < 20; $i++) {
	$rows = $rows_since();
	if (count($rows) >= 4) break;
	usleep(150000);
}
$by_action = [];
foreach ($rows as $row) {
	$by_action[$row['rql_action']][] = $row;
}
check(count($rows) === 4, 'exactly one row per request (got ' . count($rows) . ')');
check(isset($by_action['claim']) && count($by_action['claim']) === 2, 'both claim attempts recorded (the refusal and the 405)');
check(isset($by_action['join_status']), 'join_status recorded');
check(isset($by_action['bogus_thing']), 'unknown endpoint recorded under its (sanitized) name');
$all_failures = true;
$codes = [];
foreach ($rows as $row) {
	if ($row['rql_was_success'] === true || $row['rql_was_success'] === 't' || $row['rql_was_success'] === '1') $all_failures = false;
	$codes[] = (int)$row['rql_status_code'];
}
check($all_failures, 'every refused request is recorded as a failure');
sort($codes);
check($codes === [400, 401, 404, 405], 'status codes recorded with the rows (' . implode(',', $codes) . ')');

// The address the plane sees this test's requests come from.
$st = $db->prepare("SELECT rql_ip_address FROM rql_request_logs WHERE rql_request_log_id > ? AND rql_feature = 'api_agent' LIMIT 1");
$st->execute([$mark]);
$address = (string)$st->fetchColumn();
check($address !== '', 'the plane recorded the address the requests came from');

// ---------------------------------------------------------------------------
section('A request a node signed counts toward that node, never its address');
// ---------------------------------------------------------------------------

$mk_signed_node = function (string $tag) use ($db) {
	$pair = sodium_crypto_sign_keypair();
	$node = new ManagedNode(NULL);
	$node->set('mgn_name', 'HarnessTest metering ' . $tag);
	$node->set('mgn_slug', 'harnessmeter-' . $tag . '-' . bin2hex(random_bytes(3)));
	$node->set('mgn_host', '127.0.0.1');
	$node->set('mgn_uptime_enabled', false);
	$node->set('mgn_agent_public_key', base64_encode(sodium_crypto_sign_publickey($pair)));
	$node->save();
	$node->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node->key);
	return array($node, sodium_crypto_sign_secretkey($pair));
};
// A signed result for a job that does not exist: the signature verifies, and
// the endpoint refuses it after (404). Nothing on the plane changes.
$signed_result = function (array $who) {
	list($node, $secret) = $who;
	$raw = json_encode(array('node_id' => (int)$node->key, 'job_id' => 2147483000, 'status' => 'completed'));
	$ts = (string)time();
	$nonce = bin2hex(random_bytes(8));
	$msg = "joinery-agent-v1\nPOST\n/api/v1/agent/result\n" . (int)$node->key . "\n{$ts}\n{$nonce}\n" . hash('sha256', $raw);
	return harness_request('POST', '/api/v1/agent/result', array('body' => $raw, 'headers' => array(
		'Content-Type: application/json',
		'X-Joinery-Agent-Node: ' . (int)$node->key,
		'X-Joinery-Agent-Timestamp: ' . $ts,
		'X-Joinery-Agent-Nonce: ' . $nonce,
		'X-Joinery-Agent-Signature: ' . base64_encode(sodium_crypto_sign_detached($msg, $secret)),
	)));
};
$rows_for = function (string $feature, string $where = '', array $params = array()) use ($db, $mark) {
	$st = $db->prepare("SELECT rql_action, rql_key, rql_status_code FROM rql_request_logs
		WHERE rql_request_log_id > ? AND rql_feature = ? AND rql_action <> 'harness_fill' " . $where . " ORDER BY rql_request_log_id");
	$st->execute(array_merge([$mark, $feature], $params));
	return $st->fetchAll(PDO::FETCH_ASSOC);
};
$wait_rows = function (string $feature, int $n, string $where = '', array $params = array()) use ($rows_for) {
	for ($i = 0; $i < 20; $i++) {
		$got = $rows_for($feature, $where, $params);
		if (count($got) >= $n) { return $got; }
		usleep(150000);
	}
	return $rows_for($feature, $where, $params);
};

$one = $mk_signed_node('one');
$two = $mk_signed_node('two');
$unsigned_before = count($rows_for('api_agent'));
$r = $signed_result($one);
check($r['status'] === 404, 'a signed result for no job verifies, then is refused 404, got ' . $r['status'] . ' ' . substr($r['body'], 0, 200));
$node_rows = $wait_rows('api_agent_node', 1, 'AND rql_key = ?', ['node:' . (int)$one[0]->key]);
check(count($node_rows) === 1 && $node_rows[0]['rql_action'] === 'result' && (int)$node_rows[0]['rql_status_code'] === 404,
	'it is recorded once, under its node', json_encode($node_rows));
check(count($rows_for('api_agent')) === $unsigned_before, 'and nothing under its address');

// ---------------------------------------------------------------------------
section('An address over its limit refuses what proves no node, and lets a signed neighbour in');
// ---------------------------------------------------------------------------

$limit = AgentChannelEndpoint::rate_limit();
$db->prepare("INSERT INTO rql_request_logs (rql_feature, rql_action, rql_ip_address, rql_was_success)
	SELECT 'api_agent', 'harness_fill', ?, false FROM generate_series(1, ?)")->execute([$address, $limit]);
$r = harness_request('POST', '/api/v1/agent/claim', ['body' => ['node_id' => 1]]);
check($r['status'] === 429 && strpos($r['body'], 'This address') !== false,
	'an unsigned claim from the address is refused 429, naming the address, got ' . $r['status']);
$r = harness_request('POST', '/api/v1/agent/join_status', ['body' => ['nope' => true]]);
check($r['status'] === 429, 'a join_status (it proves no node) is refused 429, got ' . $r['status']);
$r = $signed_result($two);
check($r['status'] === 404, 'a signed request from the same address still gets in (404 for no job), got ' . $r['status']);
check(count($wait_rows('api_agent_node', 1, 'AND rql_key = ?', ['node:' . (int)$two[0]->key])) === 1,
	'and is recorded under its node');
usleep(300000);
check(count($rows_for('api_agent')) === $unsigned_before, 'the refused requests are not recorded, so the bucket drains');
$db->prepare("DELETE FROM rql_request_logs WHERE rql_request_log_id > ? AND rql_action = 'harness_fill'")->execute([$mark]);

// ---------------------------------------------------------------------------
section('A node over its own limit is refused, and its neighbour is not');
// ---------------------------------------------------------------------------

$db->prepare("INSERT INTO rql_request_logs (rql_feature, rql_action, rql_ip_address, rql_was_success, rql_key)
	SELECT 'api_agent_node', 'harness_fill', ?, true, ? FROM generate_series(1, ?)")->execute([$address, 'node:' . (int)$one[0]->key, $limit]);
$before_one = count($rows_for('api_agent_node', 'AND rql_key = ?', ['node:' . (int)$one[0]->key]));
$r = $signed_result($one);
check($r['status'] === 429 && strpos($r['body'], 'This node') !== false,
	'the node over its limit is refused 429, naming the node, got ' . $r['status'] . ' ' . substr($r['body'], 0, 200));
$r = $signed_result($two);
check($r['status'] === 404, 'its neighbour on the same address is not, got ' . $r['status']);
usleep(300000);
check(count($rows_for('api_agent_node', 'AND rql_key = ?', ['node:' . (int)$one[0]->key])) === $before_one,
	'the refused request is not recorded');

// ---------------------------------------------------------------------------
section('Each check has a writer');
// ---------------------------------------------------------------------------

$api  = file_get_contents(PathHelper::getIncludePath('api/apiv1.php'));
$chan = file_get_contents(PathHelper::getIncludePath('plugins/server_manager/includes/AgentChannelEndpoint.php'));
check(strpos($chan, "RequestLogger::rate_limit_state('api_agent'") !== false
	&& strpos($chan, "RequestLogger::log('api_agent'") !== false, 'the endpoint checks and writes the address\'s bucket');
check(strpos($chan, "RequestLogger::rate_limit_state('api_agent_node'") !== false
	&& strpos($chan, "RequestLogger::log('api_agent_node'") !== false, 'the endpoint checks and writes each node\'s bucket');
check(strpos($api, "rate_limit_state('api_agent'") === false, 'apiv1.php no longer checks the address alone');

// Cleanup: only the rows this test's requests produced.
$db->prepare("DELETE FROM rql_request_logs WHERE rql_request_log_id > ? AND rql_feature IN ('api_agent', 'api_agent_node')")->execute([$mark]);

harness_finish();
