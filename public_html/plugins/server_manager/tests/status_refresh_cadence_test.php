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
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/tasks/RunNodeUptimeChecks.php'));

$tag = substr(md5(uniqid('', true)), 0, 6);
function srt_node($tag, $suffix, array $fields) {
	$node = new ManagedNode(NULL);
	$node->set('mgn_name', "harnesstest status refresh $suffix $tag");
	$node->set('mgn_slug', "harnesstest-srt-$suffix-$tag");
	$node->set('mgn_host', '127.0.0.1');
	$node->set('mgn_enabled', true);
	$node->set('mgn_uptime_enabled', false);
	foreach ($fields as $k => $v) { $node->set($k, $v); }
	$node->save();
	$node->load();
	harness_register_row('mgn_managed_nodes', 'mgn_id', $node->key);
	return $node;
}
function srt_jobs($node) {
	$n = 0;
	foreach (new MultiManagementJob(array('node_id' => (int)$node->key, 'job_type' => 'check_status')) as $j) {
		harness_register_row('mjb_management_jobs', 'mjb_id', $j->key);
		$n++;
	}
	return $n;
}

$stale_at = gmdate('Y-m-d H:i:s', time() - 2 * 86400);
$stale = srt_node($tag, 'stale', array('mgn_agent_public_key' => 'harnesstest-key', 'mgn_agent_primitives' => 'check_status', 'mgn_last_status_check' => $stale_at));
$never = srt_node($tag, 'never', array('mgn_agent_public_key' => 'harnesstest-key', 'mgn_agent_primitives' => 'check_status'));
$fresh = srt_node($tag, 'fresh', array('mgn_agent_public_key' => 'harnesstest-key', 'mgn_agent_primitives' => 'check_status', 'mgn_last_status_check' => gmdate('Y-m-d H:i:s')));
$noagent = srt_node($tag, 'noagent', array('mgn_last_status_check' => $stale_at));
$off = srt_node($tag, 'off', array('mgn_enabled' => false, 'mgn_agent_public_key' => 'harnesstest-key', 'mgn_agent_primitives' => 'check_status', 'mgn_last_status_check' => $stale_at));
$nodes = array($stale, $never, $fresh, $noagent, $off);

section('A stale or never-measured agent node gets one check_status queued');
$task = new RunNodeUptimeChecks();
$queued = $task->refresh_status_facts($nodes, gmdate('Y-m-d H:i:s'));
check($queued === 2, 'two nodes were refreshed: the stale one and the never-measured one', "queued=$queued");
check(srt_jobs($stale) === 1, 'the stale node has one check_status job');
check(srt_jobs($never) === 1, 'the never-measured node has one check_status job');
check(srt_jobs($fresh) === 0, 'a node measured just now gets none');
check(srt_jobs($noagent) === 0, 'a node with no agent gets none — there is nothing to ask');
check(srt_jobs($off) === 0, 'a disabled node gets none');

section('The window dedupes: a second pass queues nothing while the job is open');
$queued = $task->refresh_status_facts($nodes, gmdate('Y-m-d H:i:s'));
check($queued === 0, 'nothing queued on the second pass', "queued=$queued");
check(srt_jobs($stale) === 1, 'the stale node still has exactly one job');

harness_finish();
