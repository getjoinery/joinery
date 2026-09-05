<?php
/** @joinery-test
 * name: management_job_rerun
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * ManagementJob::rerun() — a re-run does what the original did.
 *
 * The property under test: re-running a job queues a fresh pending job for the
 * same node with the same unit of work in the same shape. A primitive job's
 * envelope (primitive + params, and the record params the plane keeps) is
 * copied; a step job's steps are copied. A job with neither is refused, because
 * the failure this guards against was exactly that: the re-run path read only
 * `steps`, so a primitive job re-ran as a zero-step job that completed green
 * having done nothing.
 *
 * Throwaway node + job rows are created and permanently removed in cleanup.
 *
 * Run: php plugins/server_manager/tests/management_job_rerun_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));

$db = DbConnector::get_instance()->get_db_link();
$node_id = null;
$job_ids = [];
$created_by = 1;

try {
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'zz-rerun-test-node');
	$n->set('mgn_slug', 'zz-rerun-test-' . getmypid());
	$n->set('mgn_host', '203.0.113.20');
	$n->save();
	$node_id = (int)$n->key;
	check($node_id > 0, 'throwaway node created');

	// ---------------------------------------------------------------------
	section('A primitive job re-runs as a primitive job');
	// ---------------------------------------------------------------------

	$params = ['scope' => 'full', 'target_id' => 7];
	$record = ['scope' => 'full', 'target_id' => 7, 'victim_node_id' => 99];
	$orig = ManagementJob::createPrimitiveJob($node_id, 'backup_run', 'backup_run', $params, $created_by, $record);
	$job_ids[] = (int)$orig->key;
	$orig->set('mjb_status', 'completed');
	$orig->save();

	$again = $orig->rerun($created_by);
	$job_ids[] = (int)$again->key;
	check($again->key && $again->key != $orig->key, 'a new job row was created');
	check($again->isPrimitiveJob(), 'the re-run is a primitive job');
	$cmd = json_decode($again->get('mjb_commands'), true);
	check(($cmd['primitive'] ?? null) === 'backup_run', 'same primitive');
	check(($cmd['params'] ?? null) == $params, 'same envelope params');
	check(json_decode($again->get('mjb_parameters'), true) == $record, 'record params survive (caller context like victim_node_id)');
	check($again->get('mjb_status') === 'pending', 'queued as pending');
	check((int)$again->get('mjb_mgn_node_id') === $node_id, 'same node');
	check($again->get('mjb_job_type') === 'backup_run', 'same job type');
	check((int)$again->get('mjb_total_steps') === 1 && (int)$again->get('mjb_current_step') === 0, 'progress reset');
	check((int)$again->get('mjb_created_by') === $created_by, 'attributed to the re-runner');

	// The defect, stated as a check: the old page logic read commands['steps'] ?? []
	// and handed that to createJob. On a primitive job that is the tripwire step
	// alone — no envelope — which is not a primitive job.
	$old_page_logic = json_decode($orig->get('mjb_commands'), true)['steps'] ?? [];
	check(!isset($old_page_logic['primitive']), 'the steps key of a primitive job carries no envelope (what the old re-run replayed)');

	// ---------------------------------------------------------------------
	section('A step job re-runs as a step job');
	// ---------------------------------------------------------------------

	$steps = [
		['label' => 'one', 'type' => 'ssh', 'cmd' => 'true'],
		['label' => 'two', 'type' => 'ssh', 'cmd' => 'true'],
		['label' => 'tidy', 'type' => 'ssh', 'cmd' => 'true', 'teardown' => true],
	];
	$sorig = ManagementJob::createJob($node_id, 'check_status', $steps, ['note' => 'x'], $created_by);
	$job_ids[] = (int)$sorig->key;
	$sorig->set('mjb_status', 'failed');
	$sorig->save();

	$sagain = $sorig->rerun($created_by);
	$job_ids[] = (int)$sagain->key;
	check(!$sagain->isPrimitiveJob(), 'the re-run is a step job');
	check(json_decode($sagain->get('mjb_commands'), true)['steps'] == $steps, 'same steps, teardown included');
	check(json_decode($sagain->get('mjb_parameters'), true) == ['note' => 'x'], 'same parameters');
	check((int)$sagain->get('mjb_total_steps') === 2, 'main-phase step count (teardown excluded)');
	check($sagain->get('mjb_status') === 'pending', 'queued as pending');

	// ---------------------------------------------------------------------
	section('A job with no work in it is refused');
	// ---------------------------------------------------------------------

	$empty = new ManagementJob(NULL);
	$empty->set('mjb_mgn_node_id', $node_id);
	$empty->set('mjb_job_type', 'check_status');
	$empty->set('mjb_status', 'completed');
	$empty->set('mjb_commands', json_encode(['steps' => []]));
	$empty->set('mjb_total_steps', 0);
	$empty->set('mjb_current_step', 0);
	$empty->set('mjb_created_by', $created_by);
	$empty->save();
	$job_ids[] = (int)$empty->key;

	$refused = false;
	try {
		$z = $empty->rerun($created_by);
		$job_ids[] = (int)$z->key;
	} catch (ManagementJobException $e) {
		$refused = true;
	}
	check($refused, 'a zero-step, non-primitive job refuses to re-run rather than queueing a green no-op');

} finally {
	foreach ($job_ids as $jid) {
		if ($jid) $db->prepare('DELETE FROM mjb_management_jobs WHERE mjb_id = ?')->execute([$jid]);
	}
	if ($node_id) {
		$db->prepare('DELETE FROM mgn_managed_nodes WHERE mgn_id = ?')->execute([$node_id]);
	}
}

section('managed_by_from() names the plane this site is a node of, and nothing else');

check(ManagedNode::managed_by_from(['status' => 'connected', 'url' => 'https://dev.getjoinery.com/', 'node_id' => 33])
      === 'https://dev.getjoinery.com', 'a connected state names its management node, without the trailing slash');
check(ManagedNode::managed_by_from(['status' => 'pending', 'url' => 'https://dev.getjoinery.com']) === null,
      'a join still pending is not a manager yet');
check(ManagedNode::managed_by_from(['status' => 'rejected', 'url' => 'https://dev.getjoinery.com']) === null,
      'a rejected join is no manager');
check(ManagedNode::managed_by_from(['status' => 'connected']) === null, 'connected with no URL names nobody');
check(ManagedNode::managed_by_from(null) === null, 'no state is managed by nobody');

section('transcript() shows a script primitive\'s output as text, and leaves everything else alone');

// What the plane stores for a script primitive: the envelope it built from the
// node's result on the first line, then the agent's log. What a person should
// see is the transcript, then the log.
$script_job = new ManagementJob(NULL);
$script_job->set('mjb_output', json_encode(['api_version' => '1.0', 'data' => [
	'output' => "Line one\nRESULT: PASS\n", 'output_bytes' => 21,
]]) . "\n\n=== Agent log ===\nclaimed job 1\n");
$shown = $script_job->transcript();
check(strpos($shown, "Line one\nRESULT: PASS\n") === 0, 'the transcript comes first, as text', $shown);
check(strpos($shown, 'api_version') === false, 'the envelope itself is not shown');
check(strpos($shown, "=== Agent log ===\nclaimed job 1") !== false, 'the agent log follows the transcript untouched');

$cut_job = new ManagementJob(NULL);
$cut_job->set('mjb_output', json_encode(['api_version' => '1.0', 'data' => [
	'output' => 'head', 'output_bytes' => 900000, 'output_truncated' => true,
]]) . "\n");
check(strpos($cut_job->transcript(), '[Output truncated by the agent — 900000 bytes were produced.]') !== false,
      'a capped transcript says how much the agent produced', $cut_job->transcript());

// A structured result (check_status, list_backups) has no transcript; its
// fields are shown as readable JSON so the page still says what came back.
$data_job = new ManagementJob(NULL);
$data_job->set('mjb_output', json_encode(['api_version' => '1.0', 'data' => ['disk_pct' => 41, 'load' => '0.3']]) . "\n");
check(strpos($data_job->transcript(), "\"disk_pct\": 41") !== false, 'a structured result is shown as readable JSON', $data_job->transcript());

// A step job's output was never an envelope and is returned as it is.
$step_job = new ManagementJob(NULL);
$step_job->set('mjb_output', "$ ls\nfile\n");
check($step_job->transcript() === "$ ls\nfile\n", 'a step job\'s output is returned unchanged');

$odd_job = new ManagementJob(NULL);
$odd_job->set('mjb_output', "{not json\nmore\n");
check($odd_job->transcript() === "{not json\nmore\n", 'output that merely starts with a brace is returned unchanged');

$empty_job = new ManagementJob(NULL);
check($empty_job->transcript() === '', 'no output reads as no output');

harness_finish();
