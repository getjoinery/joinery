<?php
/** @joinery-test
 * name: data_root_stopped
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A data root stopped by hand (joinery_data_root.sh stop; reviewer2 B17 D2),
 * as the management node reads it from a host report:
 *
 *   - JobResultProcessor::host_report_data_root_stopped() keeps {at, services}
 *     in its own shape and nothing else; anything not in it is null.
 *   - plane:data_root_stopped is a warning on the machine whose report says
 *     so, never on a report older than the stale limit.
 *   - plane:site_down and plane:data_root_locked stand down for the machine
 *     and for every site container on it while the record holds, and their
 *     cleared text says why; they come back once it is gone.
 *   - A site that went down after its machine's last report: the machine is
 *     asked for one every REBOOT_REFRESH_SECONDS (report_wanted), and the
 *     alarms wait for it (awaiting_report), for HOLD_SECONDS at most, then
 *     open as before (reviewer2 F1).
 *
 * Throwaway rows are permanently removed in cleanup.
 *
 * Run: php plugins/server_manager/tests/data_root_stopped_test.php
 *
 * @version 1.1 - reports are stored sanitised, as JobResultProcessor stores them
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

section('The host report\'s record');

$clean = JobResultProcessor::host_report_data_root_stopped(array(
	'at' => '2026-10-16T17:02:03Z',
	'services' => array('postgresql.service', 'syslog.socket', 'bad;unit.service', 7, 'x'),
	'extra' => '<script>',
));
check($clean === array('at' => '2026-10-16 17:02:03', 'services' => array('postgresql.service', 'syslog.socket')),
	'a stop keeps its time, as UTC, and the units it names; nothing else', json_encode($clean));
check(JobResultProcessor::host_report_data_root_stopped($clean) === $clean,
	'the stored shape reads back the same: read twice, a stop is still a stop (found live on drs1, 2026-10-17)', json_encode(JobResultProcessor::host_report_data_root_stopped($clean)));
check(JobResultProcessor::host_report_data_root_stopped(null) === null
	&& JobResultProcessor::host_report_data_root_stopped(array('at' => 'yesterday')) === null
	&& JobResultProcessor::host_report_data_root_stopped('stopped') === null,
	'not stopped, or not the shape: null');
$sanitised = JobResultProcessor::sanitise_host_report(array('data_root_stopped' => array('at' => '2026-10-16T17:02:03Z', 'services' => array())));
check(($sanitised['data_root_stopped']['at'] ?? null) === '2026-10-16 17:02:03'
	&& JobResultProcessor::sanitise_host_report(array())['data_root_stopped'] === null,
	'the stored report carries it, and an older report without it reads as not stopped');

section('The incidents');

$suffix = bin2hex(random_bytes(3));
$mk_node = function (string $tag, array $fields = array()) use ($suffix): ManagedNode {
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'HarnessTest stopped ' . $tag . ' ' . $suffix);
	$n->set('mgn_slug', 'harnessstopped-' . $tag . '-' . $suffix);
	$n->set('mgn_host', '192.0.2.62');
	$n->set('mgn_ssh_user', 'root');
	$n->set('mgn_enabled', true);
	foreach ($fields as $k => $v) { $n->set($k, $v); }
	$n->save();
	$n->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $n->key);
	return $n;
};
// Stored the way JobResultProcessor stores a host report: sanitised first, so
// the readers see the stored shape ('YYYY-MM-DD HH:MM:SS'), not the agent's.
$report = function (ManagedNode $n, $stopped, int $age_seconds = 60) {
	$n->set('mgn_last_host_report', json_encode(JobResultProcessor::sanitise_host_report(array('data_root_stopped' => $stopped))));
	$n->set('mgn_last_host_report_time', gmdate('Y-m-d H:i:s', time() - $age_seconds));
	$n->save();
};
$stop = array('at' => '2026-10-16T17:02:03Z', 'services' => array('postgresql.service', 'apache2.service'));

$agent = array('mgn_agent_public_key' => base64_encode(random_bytes(32)), 'mgn_agent_version' => AgentVocabulary::FLOOR,
	'mgn_agent_primitives' => 'host_report,check_status');
$host_node = $mk_node('host', array('mgn_uptime_enabled' => true, 'mgn_uptime_last_status' => 'down',
	'mgn_site_url' => 'https://stopped-host.example') + $agent);
$mgh = new ManagedHost(NULL);
$mgh->set('mgh_name', 'HarnessTest stopped host ' . $suffix);
$mgh->set('mgh_slug', 'harnessstopped-' . $suffix);
$mgh->set('mgh_host', '192.0.2.62');
$mgh->set('mgh_mgn_managed_node_id', (int)$host_node->key);
$mgh->save();
$mgh->load();
harness_register_row('mgh_managed_hosts', 'mgh_managed_host_id', $mgh->key);
$site = $mk_node('site', array('mgn_container_name' => 'harnessst' . $suffix, 'mgn_mgh_managed_host_id' => (int)$mgh->key,
	'mgn_uptime_enabled' => true, 'mgn_uptime_last_status' => 'down', 'mgn_site_url' => 'https://stopped-site.example'));
$locked = $mk_node('locked', array('mgn_uptime_enabled' => true, 'mgn_uptime_last_status' => 'locked'));

$stopped_src = new IncidentSourceDataRootStopped();
$down_src = new IncidentSourceSiteDown();
$locked_src = new IncidentSourceDataRootLocked();

$report($host_node, $stop);
$report($locked, $stop);
$inc = $stopped_src->evaluate($host_node);
check(is_array($inc) && $inc['severity'] === IncidentRecord::SEVERITY_WARNING
	&& $inc['detail']['Stopped'] === '2026-10-16 17:02:03 UTC' && $inc['detail']['Stopped with it'] === 'postgresql.service, apache2.service',
	'a machine whose data root was stopped by hand has a warning, saying when and with what', json_encode($inc));
check($stopped_src->evaluate($site) === null, 'its site containers do not each repeat it');
check($down_src->evaluate($host_node) === null && $down_src->evaluate($site) === null,
	'the machine\'s site and its site containers are not "site down" while it is stopped');
check($locked_src->evaluate($locked) === null, 'a stopped encrypted data root is not "locked, waiting for its key"');
check(strpos($down_src->cleared_text($site), 'stopped by hand') !== false && strpos($locked_src->cleared_text($locked), 'stopped by hand') !== false,
	'an alarm already open closes saying the data root was stopped by hand');

$report($host_node, $stop, JobResultProcessor::STATUS_STALE_AFTER_SECONDS + 600);
check($stopped_src->evaluate($host_node) === null && is_array($down_src->evaluate($site)),
	'a stop named only in a stale report is not trusted: the outage is an alarm again');

$report($host_node, null);
$report($locked, null);
check($stopped_src->evaluate($host_node) === null, 'started again: the warning clears');
check(is_array($down_src->evaluate($host_node)) && is_array($down_src->evaluate($site)) && is_array($locked_src->evaluate($locked)),
	'and a site that still does not answer, or a data root still locked, is an alarm again');
check(strpos($stopped_src->cleared_text($host_node), 'started again') !== false, 'and says the data root was started again');
$report($host_node, $stop, JobResultProcessor::STATUS_STALE_AFTER_SECONDS + 600);
check(strpos($stopped_src->cleared_text($host_node), 'too old to say') !== false,
	'a stop named only in a stale report clears saying the report is too old to say, not that it was started (N1)');

section('The alarms wait for the report they asked for (reviewer2 F1)');

$site->set('mgn_uptime_down_since', gmdate('Y-m-d H:i:s', time() - 60));
$site->save();
$report($host_node, null, 300);
check(IncidentSourceDataRootStopped::report_wanted($host_node) === true,
	'a site container on the machine went down after its last report: the machine\'s report is wanted');
check(IncidentSourceDataRootStopped::awaiting_report($site, 'mgn_uptime_down_since') === true && $down_src->evaluate($site) === null,
	'and its site-down alarm waits for that report');
require_once(PathHelper::getIncludePath('plugins/server_manager/tasks/RunNodeUptimeChecks.php'));
$task = new RunNodeUptimeChecks();
$queued = $task->refresh_status_facts(array($host_node), gmdate('Y-m-d H:i:s'));
$asked = new MultiManagementJob(array('mjb_mgn_managed_node_id' => (int)$host_node->key, 'mjb_job_type' => 'host_report'));
$job_ids = array();
foreach ($asked as $j) { $job_ids[] = (int)$j->key; harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $j->key); }
foreach (new MultiManagementJob(array('mjb_mgn_managed_node_id' => (int)$host_node->key)) as $j) {
	harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $j->key);
}
check(count($job_ids) === 1, 'the uptime task asks the machine for its report at once, not on the hourly cadence', 'queued ' . $queued);
$report($host_node, $stop, 0);
check($down_src->evaluate($site) === null && IncidentSourceDataRootStopped::report_wanted($host_node) === false,
	'the report comes back stopped by hand: no alarm, and no more asking');
$report($host_node, null, 0);
check(is_array($down_src->evaluate($site)), 'it comes back with no stop: the alarm opens at once');
$report($host_node, null, 300);
$site->set('mgn_uptime_down_since', gmdate('Y-m-d H:i:s', time() - IncidentSourceDataRootStopped::HOLD_SECONDS - 5));
$site->save();
check(is_array($down_src->evaluate($site)), 'no report within HOLD_SECONDS: the alarm opens, whatever the agent is doing');
check(IncidentSourceDataRootStopped::report_wanted($host_node) === false,
	'and the machine is no longer asked every two minutes for as long as the site stays down');
$locked->set('mgn_uptime_locked_since', gmdate('Y-m-d H:i:s', time() - 60));
$report($locked, null, 300);
check(is_array($locked_src->evaluate($locked)), 'a machine whose agent does not report is never waited for');

$host_node->set('mgn_enabled', false);
$report($host_node, $stop);
check($stopped_src->evaluate($host_node) === null, 'a disabled node is not watched');

harness_finish();
