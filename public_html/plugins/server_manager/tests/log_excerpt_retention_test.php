<?php
/** @joinery-test
 * name: log_excerpt_retention
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The log excerpts a site_log or log_table_tail job brought back do not live
 * on the plane for good (specs/agent_log_access.md §4): the standard retention
 * sweep hands ManagementJob::purgeLogExcerpts the window, and it blanks the
 * excerpt and keeps the row. Only the two log types; only completed jobs;
 * only past the window; the row, its type, status and timing untouched.
 *
 * Run: php plugins/server_manager/tests/log_excerpt_retention_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

function ler_node() {
	$node = new ManagedNode(NULL);
	$suffix = bin2hex(random_bytes(3));
	$node->set('mgn_name', 'HarnessTest LER ' . $suffix);
	$node->set('mgn_slug', 'harnessler-' . $suffix);
	$node->set('mgn_host', '192.0.2.30');
	$node->set('mgn_ssh_user', 'root');
	$node->set('mgn_ssh_key_path', '/tmp/nokey');
	$node->save();
	$node->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node->key);
	return $node;
}

function ler_job($node, $type, $status, $days_ago, array $result) {
	$job = new ManagementJob(NULL);
	$job->set('mjb_mgn_managed_node_id', $node->key);
	$job->set('mjb_job_type', $type);
	$job->set('mjb_status', $status);
	$job->set('mjb_commands', array('primitive' => $type, 'params' => array()));
	$job->set('mjb_output', 'transcript for ' . $type);
	$job->set('mjb_result', $result);
	$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s', time() - $days_ago * 86400));
	$job->save();
	$job->load();
	harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $job->key);
	return $job;
}

function ler_result($job) {
	$fresh = new ManagementJob($job->key, TRUE);
	$r = $fresh->get('mjb_result');
	return array($fresh, is_string($r) ? json_decode($r, true) : $r);
}

// ---------------------------------------------------------------------------
section('A. The rule is declared in the standard shape and its window is a declared setting');

$policy = ManagementJob::$retention_policy;
check(($policy['purge_method'] ?? null) === 'purgeLogExcerpts', 'ManagementJob declares the method form with purgeLogExcerpts');
check(($policy['window_setting'] ?? null) === 'server_manager_log_excerpt_retention_days', 'the window is server_manager_log_excerpt_retention_days');
$plugin = json_decode(file_get_contents(PathHelper::getIncludePath('plugins/server_manager/plugin.json')), true);
$declared = null;
foreach ($plugin['settings'] as $s) { if ($s['name'] === 'server_manager_log_excerpt_retention_days') { $declared = $s; } }
check(is_array($declared), 'the window is declared in plugin.json');
check(($declared['default'] ?? null) === '30', 'and defaults to 30 days');
check(is_callable(array('ManagementJob', 'purgeLogExcerpts')), 'the method is callable, as the sweep requires');

// ---------------------------------------------------------------------------
section('B. Past the window: the excerpt goes, the row stays');

$node = ler_node();
$excerpt = array('file' => 'error', 'present' => true, 'lines_returned' => 2, 'truncated' => false, 'text' => "line one\nline two");
$old_log     = ler_job($node, 'site_log',       'completed', 40, $excerpt);
$old_table   = ler_job($node, 'log_table_tail', 'completed', 40, array('table' => 'logins', 'rows' => array(array('log_login_id' => 1)), 'columns' => array('log_login_id')));
$recent_log  = ler_job($node, 'site_log',       'completed', 5,  $excerpt);
$old_failed  = ler_job($node, 'site_log',       'failed',    40, array('refused' => 'owner switch off'));
$old_host    = ler_job($node, 'host_report',    'completed', 40, array('failed_units' => array()));

$out = ManagementJob::purgeLogExcerpts(30);
check(is_array($out) && isset($out['removed']) && isset($out['message']), 'the method returns the sweep\'s result shape');
check($out['removed'] >= 2, 'at least the two old completed log jobs were blanked', var_export($out, true));

list($j, $r) = ler_result($old_log);
check($r === array('pruned' => true), 'an old completed site_log result is exactly {"pruned": true}', var_export($r, true));
check($j->get('mjb_output') === null || $j->get('mjb_output') === '', 'and its transcript is gone');
check($j->get('mjb_status') === 'completed' && $j->get('mjb_job_type') === 'site_log', 'the row keeps its type and status');
check((string)$j->get('mjb_completed_time') !== '', 'and its completed time: the record that it ran');

list($j, $r) = ler_result($old_table);
check($r === array('pruned' => true), 'an old completed log_table_tail result is blanked the same way', var_export($r, true));

// ---------------------------------------------------------------------------
section('C. Inside the window, not completed, or not a log job: untouched');

list($j, $r) = ler_result($recent_log);
check(($r['text'] ?? null) === "line one\nline two", 'a site_log inside the window keeps its excerpt');
list($j, $r) = ler_result($old_failed);
check(($r['refused'] ?? null) === 'owner switch off', 'a refused (failed) log job carries no excerpt and is left alone');
list($j, $r) = ler_result($old_host);
check(isset($r['failed_units']), 'a host_report result is not the sweep\'s business');

// Running again blanks nothing more: the pruned marker is idempotent.
$again = ManagementJob::purgeLogExcerpts(30);
list($j, $r) = ler_result($old_log);
check($r === array('pruned' => true), 'a second run leaves the pruned marker as it was');

harness_finish();
