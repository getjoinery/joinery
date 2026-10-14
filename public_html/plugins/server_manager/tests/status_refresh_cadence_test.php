<?php
/** @joinery-test
 * name: status_refresh_cadence
 * tier: db
 * env: any
 * needs: []
 */

/**
 * An agent node's status facts (version, certificate, disk, memory) are
 * measured only by a check_status job. Testing day 2026-09-07 found every
 * fleet node carrying the version it had answered days earlier, because
 * nothing queued that job on a cadence (defect B6). The uptime pass now does:
 * one check_status per stale agent node per window, deduped against the job
 * table, and none for a node measured recently or without the primitive.
 *
 * Measured recently means a check_status JOB completed inside the window.
 * mgn_last_status_check is no proof: the probe stamps it whenever it reads a
 * site's health document, and on 2026-09-22 every fleet site looked fresh
 * that way while its agent had not been asked for days (the plugin checks
 * never reached the dashboard).
 *
 * @version 1.5 - the scheduled pass leaves a test's fixture nodes alone (RunNodeUptimeChecks::watched_nodes())
 * @version 1.4 - a machine asked to reboot is asked for its host report every REBOOT_REFRESH_SECONDS until it is seen back
 * @version 1.3 - a host report saying a data root run is under way is asked again after
 *                DATA_ROOT_RUN_REFRESH_SECONDS, not the hour (B62)
 * @version 1.2 - a refused or failed answer inside the window is cover; a cancelled job is not
 * @version 1.1 - the probe-stamped node still gets its check_status; a node with a recently
 *                completed check_status job gets none
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_nodes_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_jobs_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/tasks/RunNodeUptimeChecks.php'));

$tag = substr(md5(uniqid('', true)), 0, 6);
function srt_node($tag, $suffix, array $fields) {
	$node = new ManagedNode(NULL);
	$node->set('mgn_name', "HarnessTest status refresh $suffix $tag");
	$node->set('mgn_slug', "harnesstest-srt-$suffix-$tag");
	$node->set('mgn_host', '127.0.0.1');
	$node->set('mgn_enabled', true);
	$node->set('mgn_uptime_enabled', false);
	// A paired agent at the version floor: below it a node is offered
	// apply_update only (AgentVocabulary), which is not what this test is about.
	if (!empty($fields['mgn_agent_public_key']) && !isset($fields['mgn_agent_version'])) {
		$node->set('mgn_agent_version', AgentVocabulary::FLOOR);
	}
	foreach ($fields as $k => $v) { $node->set($k, $v); }
	$node->save();
	$node->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node->key);
	return $node;
}
function srt_jobs($node, $type = 'check_status') {
	$n = 0;
	foreach (new MultiManagementJob(array('node_id' => (int)$node->key, 'job_type' => $type)) as $j) {
		harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $j->key);
		$n++;
	}
	return $n;
}

$stale_at = gmdate('Y-m-d H:i:s', time() - 2 * 86400);
$stale = srt_node($tag, 'stale', array('mgn_agent_public_key' => 'harnesstest-key', 'mgn_agent_primitives' => 'check_status', 'mgn_last_status_check' => $stale_at));
$never = srt_node($tag, 'never', array('mgn_agent_public_key' => 'harnesstest-key', 'mgn_agent_primitives' => 'check_status'));
$probed = srt_node($tag, 'probed', array('mgn_agent_public_key' => 'harnesstest-key', 'mgn_agent_primitives' => 'check_status', 'mgn_last_status_check' => gmdate('Y-m-d H:i:s')));
$fresh = srt_node($tag, 'fresh', array('mgn_agent_public_key' => 'harnesstest-key', 'mgn_agent_primitives' => 'check_status', 'mgn_last_status_check' => $stale_at));
$done = ManagementJob::createFromBuild($fresh->key, 'check_status', JobCommandBuilder::build_check_status_primitive($fresh), null, null);
$done->set('mjb_status', 'completed');
$done->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
$done->save();
$noagent = srt_node($tag, 'noagent', array('mgn_last_status_check' => $stale_at));
$off = srt_node($tag, 'off', array('mgn_enabled' => false, 'mgn_agent_public_key' => 'harnesstest-key', 'mgn_agent_primitives' => 'check_status', 'mgn_last_status_check' => $stale_at));
$nodes = array($stale, $never, $probed, $fresh, $noagent, $off);

section('A stale or never-measured agent node gets one check_status queued');
$task = new RunNodeUptimeChecks();
$queued = $task->refresh_status_facts($nodes, gmdate('Y-m-d H:i:s'));
check($queued === 3, 'three nodes were refreshed: the stale one, the never-measured one, and the one only the probe stamped', "queued=$queued");
check(srt_jobs($stale) === 1, 'the stale node has one check_status job');
check(srt_jobs($never) === 1, 'the never-measured node has one check_status job');
check(srt_jobs($probed) === 1, 'a node whose stamp is fresh only from the probe still gets its check_status');
check(srt_jobs($fresh) === 1, 'a node whose check_status completed just now gets no second one');
check(srt_jobs($noagent) === 0, 'a node with no agent gets none — there is nothing to ask');
check(srt_jobs($off) === 0, 'a disabled node gets none');

section('The window dedupes: a second pass queues nothing while the job is open');
$queued = $task->refresh_status_facts($nodes, gmdate('Y-m-d H:i:s'));
check($queued === 0, 'nothing queued on the second pass', "queued=$queued");
check(srt_jobs($stale) === 1, 'the stale node still has exactly one job');
check(srt_jobs($stale, 'host_report') === 0, 'a node whose vocabulary lacks host_report is never asked for one');

section('host_report rides the same cadence, on its own stamp');
$both_stale = srt_node($tag, 'bothstale', array('mgn_agent_public_key' => 'harnesstest-key', 'mgn_agent_primitives' => 'check_status,host_report',
	'mgn_last_status_check' => $stale_at, 'mgn_last_host_report_time' => $stale_at));
$host_fresh = srt_node($tag, 'hostfresh', array('mgn_agent_public_key' => 'harnesstest-key', 'mgn_agent_primitives' => 'check_status,host_report',
	'mgn_last_status_check' => $stale_at, 'mgn_last_host_report_time' => gmdate('Y-m-d H:i:s')));
$host_never = srt_node($tag, 'hostnever', array('mgn_agent_public_key' => 'harnesstest-key', 'mgn_agent_primitives' => 'check_status,host_report'));
$host_never_done = ManagementJob::createFromBuild($host_never->key, 'check_status', JobCommandBuilder::build_check_status_primitive($host_never), null, null);
$host_never_done->set('mjb_status', 'completed');
$host_never_done->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
$host_never_done->save();
$queued = $task->refresh_status_facts(array($both_stale, $host_fresh, $host_never), gmdate('Y-m-d H:i:s'));
check($queued === 4, 'four jobs: both for the node stale on both stamps, one status for the host-fresh node, one host for the status-fresh node', "queued=$queued");
check(srt_jobs($both_stale) === 1 && srt_jobs($both_stale, 'host_report') === 1, 'the node stale on both gets one of each');
check(srt_jobs($host_fresh) === 1 && srt_jobs($host_fresh, 'host_report') === 0, 'a fresh host report is not asked for again');
check(srt_jobs($host_never) === 1 && srt_jobs($host_never, 'host_report') === 1, 'a node that never reported its host gets one, and its fresh status none');
$queued = $task->refresh_status_facts(array($both_stale, $host_fresh, $host_never), gmdate('Y-m-d H:i:s'));
check($queued === 0, 'the window dedupes host reports too', "queued=$queued");

section('A refused or failed answer inside the window is cover too');
// Dev 2026-10-02..07: the agent refused host_report.sh (edited after the last
// publish), the stamp never advanced, and only completed jobs counted as
// cover — so the hourly refresh asked again every cron minute, 923 times.
$refused = srt_node($tag, 'refused', array('mgn_agent_public_key' => 'harnesstest-key', 'mgn_agent_primitives' => 'check_status,host_report',
	'mgn_last_status_check' => $stale_at, 'mgn_last_host_report_time' => $stale_at));
$refused_job = ManagementJob::createFromBuild($refused->key, 'host_report', JobCommandBuilder::build_host_report_primitive($refused), null, null);
$refused_job->set('mjb_status', 'failed');
$refused_job->set('mjb_agent_outcome', 'refused');
$refused_job->set('mjb_error_message', 'Refused by the node: file does not match its signed hash');
$refused_job->set('mjb_completed_time', gmdate('Y-m-d H:i:s', time() - 60));
$refused_job->save();
$queued = $task->refresh_status_facts(array($refused), gmdate('Y-m-d H:i:s'));
check($queued === 1, 'only the check_status is queued; the host_report refused a minute ago is not asked again', "queued=$queued");
check(srt_jobs($refused, 'host_report') === 1, 'the refused node still has exactly one host_report job');
// Once the window has passed, the question is asked again — once.
$refused_job->set('mjb_completed_time', gmdate('Y-m-d H:i:s', time() - RunNodeUptimeChecks::STATUS_REFRESH_SECONDS - 60));
$refused_job->save();
$queued = $task->refresh_status_facts(array($refused), gmdate('Y-m-d H:i:s'));
check($queued === 1, 'a refusal older than the window earns one new host_report', "queued=$queued");
check(srt_jobs($refused, 'host_report') === 2, 'the refused node now has two host_report jobs');
// A cancelled job was never answered; it is no cover.
$cancelled = srt_node($tag, 'cancelled', array('mgn_agent_public_key' => 'harnesstest-key', 'mgn_agent_primitives' => 'host_report',
	'mgn_last_host_report_time' => $stale_at));
$cancelled_job = ManagementJob::createFromBuild($cancelled->key, 'host_report', JobCommandBuilder::build_host_report_primitive($cancelled), null, null);
$cancelled_job->set('mjb_status', 'cancelled');
$cancelled_job->save();
$queued = $task->refresh_status_facts(array($cancelled), gmdate('Y-m-d H:i:s'));
check($queued === 1, 'a cancelled host_report is no cover: the node is asked', "queued=$queued");

section('While its data root is being encrypted, a machine is asked every few minutes (B62)');
// The run ends on its own, hours after the job that started it; its end is
// what calls for the enrolment and changes what the node page offers.
$six_min = gmdate('Y-m-d H:i:s', time() - RunNodeUptimeChecks::DATA_ROOT_RUN_REFRESH_SECONDS - 60);
$run_report = function (string $reencrypt) {
	return json_encode(array('data_root_encryption' => array('backing' => 'luks-file', 'open' => true, 'holder' => '',
		'reencrypt' => $reencrypt, 'reencrypt_percent' => $reencrypt === '' ? '' : '42.0')));
};
$running = srt_node($tag, 'drrun', array('mgn_agent_public_key' => 'harnesstest-key', 'mgn_agent_primitives' => 'host_report',
	'mgn_last_host_report_time' => $six_min, 'mgn_last_host_report' => $run_report('encrypt')));
$settled = srt_node($tag, 'drdone', array('mgn_agent_public_key' => 'harnesstest-key', 'mgn_agent_primitives' => 'host_report',
	'mgn_last_host_report_time' => $six_min, 'mgn_last_host_report' => $run_report('')));
foreach (array($running, $settled) as $n) {
	$j = ManagementJob::createFromBuild($n->key, 'host_report', JobCommandBuilder::build_host_report_primitive($n), null, null);
	$j->set('mjb_status', 'completed');
	$j->set('mjb_completed_time', $six_min);
	$j->save();
}
check(RunNodeUptimeChecks::data_root_run_under_way($running) && !RunNodeUptimeChecks::data_root_run_under_way($settled),
	'a report with an encryption under way says so; one without does not');
$queued = $task->refresh_status_facts(array($running, $settled), gmdate('Y-m-d H:i:s'));
check($queued === 1 && srt_jobs($running, 'host_report') === 2 && srt_jobs($settled, 'host_report') === 1,
	'six minutes after its last report, the encrypting machine is asked again; a settled one waits out the hour', "queued=$queued");
$queued = $task->refresh_status_facts(array($running), gmdate('Y-m-d H:i:s'));
check($queued === 0, 'and asked once: the open job is cover', "queued=$queued");

section('A machine asked to reboot is asked every two minutes until it is seen back (agent 1.76.0)');
// The reboot_machine job keeps the boot id it was asked from; a host report
// with a new one is the machine back (JobResultProcessor::reboot_seen).
$three_min = gmdate('Y-m-d H:i:s', time() - RunNodeUptimeChecks::REBOOT_REFRESH_SECONDS - 60);
$rebooting = srt_node($tag, 'reboot', array('mgn_agent_public_key' => 'harnesstest-key', 'mgn_agent_primitives' => 'host_report,reboot_machine',
	'mgn_last_host_report_time' => $three_min, 'mgn_last_host_report' => json_encode(array('boot_id' => '11111111-2222-4333-8444-555555555555'))));
$rj = ManagementJob::createFromBuild($rebooting->key, 'reboot_machine', JobCommandBuilder::build_reboot_machine_primitive($rebooting), null, null);
$rj->set('mjb_status', 'completed');
$rj->set('mjb_result', json_encode(array('read' => true, 'scheduled' => true, 'boot_id_before' => '11111111-2222-4333-8444-555555555555', 'came_back_at' => null)));
$rj->save();
$hj = ManagementJob::createFromBuild($rebooting->key, 'host_report', JobCommandBuilder::build_host_report_primitive($rebooting), null, null);
$hj->set('mjb_status', 'completed');
$hj->set('mjb_completed_time', $three_min);
$hj->save();
check(JobResultProcessor::reboot_awaited($rebooting), 'a scheduled reboot not yet seen is awaited');
$queued = $task->refresh_status_facts(array($rebooting), gmdate('Y-m-d H:i:s'));
check($queued === 1 && srt_jobs($rebooting, 'host_report') === 2,
	'three minutes after its last report, a rebooting machine is asked again, not after the hour', "queued=$queued");
$rr = json_decode((string)$rj->get('mjb_result'), true);
$rr['came_back_at'] = gmdate('Y-m-d H:i:s');
$rj->set('mjb_result', json_encode($rr));
$rj->save();
check(!JobResultProcessor::reboot_awaited($rebooting), 'once it is seen back, nothing is awaited and the hour applies again');

// The scheduled pass never sees a suite's own nodes: it queued check_status
// and host_report for them mid-run, and those jobs outlived the nodes.
$watched = array_map(function ($n) { return (int)$n->key; }, RunNodeUptimeChecks::watched_nodes());
check(!in_array((int)$rebooting->key, $watched, true)
	&& count(array_filter(RunNodeUptimeChecks::watched_nodes(), function ($n) { return ManagedNode::is_fixture_name((string)$n->get('mgn_name')); })) === 0,
	'the scheduled pass leaves every test fixture node alone');

harness_finish();
