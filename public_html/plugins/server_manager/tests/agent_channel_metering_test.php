<?php
/** @joinery-test
 * name: agent_channel_metering
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The agent channel fills the rate bucket it is checked against.
 *
 * apiv1.php refuses /api/v1/agent/* when the `api_agent` bucket is over its
 * limit. For that to mean anything, requests on the channel must write
 * `api_agent` rows — for a long time nothing did, so the counter was always
 * zero and the limit could never fire. This test pins:
 *   - the metering rule: everything counts except a successful claim, the
 *     steady-state poll (tens of thousands a day on a modest fleet);
 *   - that real requests to the channel land as rows with their outcome;
 *   - that the check in apiv1.php has a writer in the tree.
 *
 * The rows this test's own requests create are removed in cleanup.
 *
 * Run: php plugins/server_manager/tests/agent_channel_metering_test.php
 *
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

// ---------------------------------------------------------------------------
section('The check has a writer');
// ---------------------------------------------------------------------------

$api  = file_get_contents(PathHelper::getIncludePath('api/apiv1.php'));
$chan = file_get_contents(PathHelper::getIncludePath('plugins/server_manager/includes/AgentChannelEndpoint.php'));
check(strpos($api, "RequestLogger::check_rate_limit('api_agent'") !== false, 'apiv1.php still checks the api_agent bucket');
check(strpos($chan, "RequestLogger::log('api_agent'") !== false, 'AgentChannelEndpoint writes api_agent rows');

// Cleanup: only the rows this test's requests produced.
$db->prepare("DELETE FROM rql_request_logs WHERE rql_request_log_id > ? AND rql_feature = 'api_agent'")->execute([$mark]);

harness_finish();
