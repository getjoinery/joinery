<?php
/** @joinery-test
 * name: fleet_security
 * tier: db
 * env: dev-only
 * needs: []
 * covers: [plugins/server_manager/includes/FleetSecurityView.php, plugins/server_manager/includes/FleetSecurityRunner.php, plugins/server_manager/data/fleet_security_runs_class.php, maintenance_scripts/sysadmin_tools/security_check.sh]
 */
/**
 * The fleet security page and Install security updates everywhere
 * (specs/fleet_security_patching.md WP2, WP5).
 *
 * FleetSecurityView: each machine and site container's row and verdict, from
 * arrays (red for old code serving or a critical fix waiting; amber for other
 * fixes, a reboot owed over a day, a stale or missing check; grey for nothing
 * reported). FleetSecurityRunner: the plan's order and what it leaves out, a
 * run queued one node at a time, passing a node with nothing to install or a
 * package run in the way, halting at a failed updater, and stop.
 *
 * Run: php plugins/server_manager/tests/fleet_security_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$NOW = strtotime('2026-10-22T14:00:00Z');

/** A machine's snapshot: a host report built from $hr through the processor's sanitiser. */
function fs_machine($id, $name, array $hr, $php = null) {
	return array('id' => $id, 'name' => $name, 'container' => '', 'host_id' => null,
		'host_report' => JobResultProcessor::sanitise_host_report($hr), 'php' => $php ? JobResultProcessor::status_php($php) : null);
}
function fs_container($id, $name, $cname, $host_id, $php = null) {
	return array('id' => $id, 'name' => $name, 'container' => $cname, 'host_id' => $host_id, 'host_report' => null,
		'php' => $php ? JobResultProcessor::status_php($php) : null);
}
function fs_pending(array $packages, $check = 'ok', $ok_at = '2026-10-22T13:50:00Z') {
	$crit = 0;
	foreach ($packages as $p) { if ($p['critical']) { $crit++; } }
	return array('installed_at' => '2026-10-20T00:00:00Z', 'checked_at' => '2026-10-22T13:50:00Z', 'checked_ok_at' => $ok_at, 'check' => $check,
		'check_error' => '', 'lists_at' => '2026-10-22T13:49:00Z', 'lists_error' => '', 'pending' => count($packages), 'critical' => $crit,
		'packages' => $packages, 'scheduled' => true);
}
function fs_pkg($name, $critical) {
	return array('package' => $name, 'installed' => '1.0', 'candidate' => '1.1', 'critical' => $critical);
}
$clean_hr = array('os' => array('id' => 'ubuntu', 'version' => '24.04', 'codename' => 'noble'), 'unattended_upgrades_last_run' => $NOW - 3600,
	'reboot_required' => false, 'security_pending' => fs_pending(array()), 'services_needing_restart' => array('source' => 'needrestart', 'services' => array()));
$php_current = array('packages' => array(array('name' => 'php8.5-fpm', 'version' => '8.5.4-0ubuntu1.5')),
	'fpm' => array(array('binary' => '/usr/sbin/php-fpm8.5', 'running_current' => true, 'started_at' => $NOW - 100)));
$php_old = array('packages' => array(array('name' => 'php8.5-fpm', 'version' => '8.5.4-0ubuntu1.5')),
	'fpm' => array(array('binary' => '/usr/sbin/php-fpm8.5', 'running_current' => false, 'started_at' => $NOW - 99999)));

// ---------------------------------------------------------------------------
section('The rows: one per machine and site container, with a verdict');

$row = FleetSecurityView::row(fs_machine(1, 'clean', $clean_hr, $php_current), null, $NOW);
check($row['verdict'] === 'green' && $row['os'] === 'Ubuntu 24.04' && $row['code'] === 'current' && $row['pending'] === 0 && $row['reasons'] === array(),
	'a machine on current code with nothing waiting: green, its OS, PHP and zero fixes', json_encode($row));

$row = FleetSecurityView::row(fs_machine(2, 'oldcode', $clean_hr, $php_old), null, $NOW);
check($row['verdict'] === 'red' && $row['code'] === 'old' && strpos($row['reasons'][0], 'old code is serving (php-fpm8.5)') === 0,
	'a php-fpm on a replaced binary: red, naming it', json_encode($row));

$row = FleetSecurityView::row(fs_machine(3, 'svc', array_merge($clean_hr, array('services_needing_restart' => array('source' => 'needrestart', 'services' => array('apache2.service'))))), null, $NOW);
check($row['verdict'] === 'red' && $row['code'] === 'old' && in_array('apache2.service', $row['old_code'], true),
	'a service needrestart names: old code serving, red', json_encode($row));

$row = FleetSecurityView::row(fs_machine(4, 'crit', array_merge($clean_hr, array('security_pending' => fs_pending(array(fs_pkg('php8.3-fpm', true), fs_pkg('curl', false)))))), null, $NOW);
check($row['verdict'] === 'red' && $row['pending'] === 2 && $row['critical'] === array('php8.3-fpm')
		&& in_array('a critical fix waits (php8.3-fpm)', $row['reasons'], true) && in_array('1 other fix wait for the next run', $row['reasons'], true),
	'a critical fix waiting: red, and the other fix is named as waiting for the next run', json_encode($row));
$row = FleetSecurityView::row(fs_machine(4, 'kernel', array_merge($clean_hr, array('security_pending' => fs_pending(array(fs_pkg('linux-image-6.8.0-90-generic', true), fs_pkg('curl', false)))))), null, $NOW);
check($row['verdict'] === 'amber' && $row['critical'] === array() && $row['kernel'] === array('linux-image-6.8.0-90-generic')
		&& $row['reasons'] === array('a kernel fix waits for the night, then a reboot', '1 other fix wait for the next run'),
	'a kernel fix waiting: amber, not red (nothing installs it before the night; the reboot is a person\'s)', json_encode($row));

$row = FleetSecurityView::row(fs_machine(5, 'night', array_merge($clean_hr, array('security_pending' => fs_pending(array(fs_pkg('curl', false), fs_pkg('tar', false)))))), null, $NOW);
check($row['verdict'] === 'amber' && $row['reasons'] === array('2 other fixes wait for the next run'), 'other fixes only: amber', json_encode($row));

$row = FleetSecurityView::row(fs_machine(6, 'reboot', array_merge($clean_hr, array('reboot_required' => true, 'reboot_required_since' => $NOW - 2 * 86400))), null, $NOW);
check($row['verdict'] === 'amber' && $row['reboot'] === $NOW - 2 * 86400 && in_array('a reboot owed for over a day', $row['reasons'], true),
	'a reboot owed two days: amber', json_encode($row));
$row = FleetSecurityView::row(fs_machine(6, 'reboot', array_merge($clean_hr, array('reboot_required' => true, 'reboot_required_since' => $NOW - 3600))), null, $NOW);
check($row['verdict'] === 'green' && $row['reboot'] === $NOW - 3600, 'a reboot owed an hour: shown, not amber', json_encode($row));

$row = FleetSecurityView::row(fs_machine(7, 'stale', array_merge($clean_hr, array('security_pending' => fs_pending(array(), 'failed', '2026-10-22T09:00:00Z')))), null, $NOW);
check($row['verdict'] === 'amber' && in_array('no check for fixes has passed in two hours', $row['reasons'], true), 'a check last passed five hours ago: amber', json_encode($row));

$row = FleetSecurityView::row(fs_machine(8, 'nocheck', array_merge($clean_hr, array('security_pending' => 'none'))), null, $NOW);
check($row['verdict'] === 'amber' && $row['pending'] === null && in_array('it does not look for security fixes yet', $row['reasons'], true),
	'a machine whose check never ran: amber, not checked', json_encode($row));

$row = FleetSecurityView::row(array('id' => 9, 'name' => 'silent', 'container' => '', 'host_id' => null, 'host_report' => null, 'php' => null), null, $NOW);
check($row['verdict'] === 'grey' && $row['reasons'] === array('nothing reported yet'), 'nothing reported: grey', json_encode($row));

// A container host and its sites: a site's fixes are its host's base's.
$sr = array('installed_at' => '2026-10-10T01:00:00Z', 'checked_at' => '2026-10-22T13:40:00Z', 'checked_ok_at' => '2026-10-22T13:40:00Z',
	'check' => 'ok', 'check_error' => '', 'base_version' => '2.2', 'base_id' => '88bf90ef691d', 'base_built_at' => '2026-10-21T01:42:00Z',
	'base_due' => 'fix', 'ran_at' => '2026-10-22T01:40:00Z', 'run' => 'replaced', 'run_error' => '', 'pending' => 3, 'pending_security' => 2,
	'sites' => 2, 'sites_due' => 0, 'security_packages' => array('libexpat1', 'curl'),
	'replaced' => array(array('site' => 'sa', 'down_seconds' => 12.1)), 'passed_over' => array(), 'holds' => array(), 'scheduled' => true);
$host = fs_machine(20, 'dockerhost', array_merge($clean_hr, array('site_refresh' => $sr, 'containers' => array(array('name' => 'sa', 'state' => 'running')))));
$site = fs_container(21, 'site a', 'sa', 20, $php_current);
$row = FleetSecurityView::row($site, $host, $NOW);
check($row['kind'] === 'container' && $row['host_name'] === 'dockerhost' && $row['os'] === 'base 2.2, built 2026-10-21' && $row['pending'] === 2
		&& $row['critical'] === array() && $row['verdict'] === 'amber' && $row['updated_at'] === strtotime('2026-10-22T01:40:00Z'),
	'a site container: its host\'s base, its fixes, when it was last replaced; non-critical fixes are amber', json_encode($row));
$host_crit = fs_machine(20, 'dockerhost', array_merge($clean_hr, array('site_refresh' => array_merge($sr, array('security_packages' => array('php8.5-common', 'curl'))))));
$row = FleetSecurityView::row($site, $host_crit, $NOW);
check($row['verdict'] === 'red' && $row['critical'] === array('php8.5-common'), 'its base waiting for a PHP fix: red', json_encode($row));
$row = FleetSecurityView::row(fs_container(22, 'site b', 'sb', 20, $php_old), $host, $NOW);
check($row['verdict'] === 'red' && $row['code'] === 'old', 'a site container serving old PHP: red', json_encode($row));
$row = FleetSecurityView::row(fs_container(23, 'site c', 'sc', null, $php_current), null, $NOW);
check($row['verdict'] === 'amber' && in_array('its host is not known here', $row['reasons'], true), 'a site container with no known host: amber', json_encode($row));
$row = FleetSecurityView::row($site, fs_machine(20, 'dockerhost', $clean_hr), $NOW);
check($row['verdict'] === 'amber' && in_array('its host does not replace its containers when a fix ships', $row['reasons'], true),
	'a site container whose host has no nightly replacement: amber', json_encode($row));

$rows = FleetSecurityView::rows(array(
	fs_machine(1, 'b clean', $clean_hr, $php_current), fs_machine(2, 'a old', $clean_hr, $php_old),
	array('id' => 9, 'name' => 'c silent', 'container' => '', 'host_id' => null, 'host_report' => null, 'php' => null),
	fs_machine(5, 'd night', array_merge($clean_hr, array('security_pending' => fs_pending(array(fs_pkg('curl', false)))))),
	$host, $site), $NOW);
check(array_column($rows, 'name') === array('a old', 'd night', 'site a', 'c silent', 'b clean', 'dockerhost')
		&& $rows[2]['host_name'] === 'dockerhost',
	'rows: red, amber, grey, green, then by name; a container finds its host among the snapshots (the host itself is green: its base\'s fixes are its containers\' rows)', json_encode(array_column($rows, 'name')));

// One critical list on both sides.
$sh = file_get_contents(PathHelper::getIncludePath('../maintenance_scripts/sysadmin_tools/security_check.sh'));
check(preg_match('/\[\[ "\$name" =~ \^\(([^)]+)\) \]\]/', $sh, $m) === 1
		&& '/^(' . $m[1] . ')/' === FleetSecurityView::CRITICAL_PATTERN,
	'the page\'s critical packages are security_check.sh\'s', $m[1] ?? 'no match');
check(FleetSecurityView::is_critical_package('libssl3t64') && FleetSecurityView::is_critical_package('linux-image-6.8.0-90-generic')
		&& !FleetSecurityView::is_critical_package('curl') && !FleetSecurityView::is_critical_package('libphp-embed'),
	'OpenSSL\'s library and the kernel are critical; curl is not');

// ---------------------------------------------------------------------------
section('Install security updates everywhere: one node at a time');

function fs_node(array $fields = array()) {
	$node = new ManagedNode(NULL);
	$suffix = bin2hex(random_bytes(3));
	$node->set('mgn_name', $fields['mgn_name'] ?? ('HarnessTest FS ' . $suffix));
	unset($fields['mgn_name']);
	$node->set('mgn_slug', 'harnessfs-' . $suffix);
	$node->set('mgn_host', '192.0.2.31');
	$node->set('mgn_ssh_user', 'root');
	$node->set('mgn_ssh_key_path', '/tmp/nokey');
	$node->set('mgn_agent_public_key', base64_encode(random_bytes(32)));
	$node->set('mgn_agent_version', AgentVocabulary::FLOOR);
	$node->set('mgn_agent_primitives', 'check_status,host_report,install_security_updates');
	foreach ($fields as $k => $v) { $node->set($k, $v); }
	$node->save();
	$node->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node->key);
	return $node;
}
/** Finish a node's queued job with the script's object, as the agent would. */
function fs_finish(ManagementJob $job, array $object, $status = 'completed') {
	$job->set('mjb_status', $status);
	$job->set('mjb_output', "=== [Step 1/1] install_security_updates ===\n" . json_encode(array('api_version' => '1.0',
		'data' => array('output' => json_encode($object), 'output_bytes' => 1))));
	$job->save();
}
function fs_object($outcome, $count = 0, array $extra = array()) {
	return array_merge(array('outcome' => $outcome, 'reason' => '', 'apt_update' => 'ok', 'upgrade_exit' => 0, 'upgraded' => array(),
		'upgraded_count' => $count, 'truncated' => false, 'reboot_required' => false, 'services_needing_restart' => array(),
		'repaired' => false, 'reloaded' => array(), 'still_on_old_code' => array()), $extra);
}
function fs_run_cleanup() {
	foreach (new MultiFleetSecurityRun(array()) as $r) {
		harness_register_row('fsr_fleet_security_runs', 'fsr_fleet_security_run_id', $r->key);
	}
	foreach (new MultiManagementJob(array('job_type' => 'install_security_updates')) as $j) {
		$params = $j->get('mjb_parameters');
		if (is_string($params)) { $params = json_decode($params, true); }
		if (is_array($params) && isset($params['fleet_security_run_id'])) {
			harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $j->key);
		}
	}
}

// Leave nothing of a real run in the way of the test's own.
check(FleetSecurityRun::running() === null, 'no fleet run is under way before the test (one would make start refuse)');

$host_node = fs_node(array('mgn_name' => 'HarnessTest FS b host'));
$m_node = fs_node(array('mgn_name' => 'HarnessTest FS a machine'));
$c_node = fs_node(array('mgn_name' => 'HarnessTest FS a site', 'mgn_container_name' => 'fsa'));
$old_agent = fs_node(array('mgn_name' => 'HarnessTest FS old agent', 'mgn_agent_primitives' => 'check_status,host_report'));
$off = fs_node(array('mgn_name' => 'HarnessTest FS off', 'mgn_enabled' => false));
$cands = array($c_node, $old_agent, $host_node, $off, $m_node);

$plan = FleetSecurityRunner::plan($cands);
check(array_map(function ($n) { return (string)$n->get('mgn_name'); }, $plan['nodes']) === array('HarnessTest FS a machine', 'HarnessTest FS b host', 'HarnessTest FS a site'),
	'the plan: machines by name, then site containers', json_encode(array_map(function ($n) { return $n->get('mgn_name'); }, $plan['nodes'])));
$lo = array_column($plan['left_out'], 'reason', 'name');
check(count($lo) === 2 && $lo['HarnessTest FS off'] === 'switched off' && strpos($lo['HarnessTest FS old agent'], 'install_security_updates') !== false,
	'left out by name: a node switched off, and one whose agent lacks the word', json_encode($lo));

$run = FleetSecurityRunner::start(null, $cands);
fs_run_cleanup();
$steps = $run->steps();
check($run->is_running() && count($steps) === 3 && $steps[0]['verdict'] === 'running' && $steps[1]['verdict'] === 'pending' && !empty($steps[0]['job_id']),
	'started: the first node\'s updates queued at once, the rest pending', json_encode($steps));
$job0 = new ManagementJob((int)$steps[0]['job_id'], TRUE);
check((string)$job0->get('mjb_job_type') === 'install_security_updates' && (int)$job0->get('mjb_mgn_managed_node_id') === (int)$m_node->key,
	'  the job is the node\'s own install_security_updates');
$threw = '';
try { FleetSecurityRunner::start(null, $cands); } catch (FleetSecurityRunException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'already under way') !== false, 'a second start while one runs is refused', $threw);

FleetSecurityRunner::advance($run);
check($run->steps()[0]['verdict'] === 'running' && (int)$run->get('fsr_position') === 0, 'a job not finished: the run waits');

fs_finish($job0, fs_object('installed', 3, array('reloaded' => array('php-fpm8.5'), 'reboot_required' => true)));
FleetSecurityRunner::advance($run);
$steps = $run->steps();
check($steps[0]['verdict'] === 'done' && $steps[0]['outcome'] === 'installed' && $steps[0]['changed'] === 3 && $steps[0]['reloaded'] === array('php-fpm8.5')
		&& $steps[0]['reboot_required'] === true && $steps[1]['verdict'] === 'running' && (int)$run->get('fsr_position') === 1,
	'installed: recorded with what changed and was reloaded, and the next node is queued in the same step', json_encode($steps));

fs_run_cleanup();
fs_finish(new ManagementJob((int)$steps[1]['job_id'], TRUE), fs_object('busy', 0, array('reason' => 'another package run held the lock')));
FleetSecurityRunner::advance($run);
$steps = $run->steps();
check($steps[1]['verdict'] === 'skipped' && strpos($steps[1]['reason'], 'held the lock') !== false && $steps[2]['verdict'] === 'running',
	'busy: passed as skipped, saying why, and the run goes on', json_encode($steps));

fs_run_cleanup();
fs_finish(new ManagementJob((int)$steps[2]['job_id'], TRUE), fs_object('nothing_to_install'));
FleetSecurityRunner::advance($run);
$run->load();
check((string)$run->get('fsr_status') === FleetSecurityRun::STATUS_COMPLETED && $run->steps()[2]['verdict'] === 'done' && $run->get('fsr_finish_time'),
	'nothing to install on the last node: done, and the run completed');

// A failed updater halts the run, naming the node; the rest are skipped.
$run2 = FleetSecurityRunner::start(null, $cands);
fs_run_cleanup();
fs_finish(new ManagementJob((int)$run2->steps()[0]['job_id'], TRUE), fs_object('failed', 0, array('reason' => 'unattended-upgrade reported an error')));
FleetSecurityRunner::advance($run2);
$run2->load();
$s2 = $run2->steps();
check((string)$run2->get('fsr_status') === FleetSecurityRun::STATUS_HALTED && $s2[0]['verdict'] === 'failed' && $s2[1]['verdict'] === 'skipped' && $s2[2]['verdict'] === 'skipped'
		&& strpos((string)$run2->get('fsr_halt_reason'), 'HarnessTest FS a machine: its updater reported an error') === 0,
	'a failed updater halts the run at that node; the rest are skipped', (string)$run2->get('fsr_halt_reason'));

// A job the agent failed (no result) halts it too.
$run3 = FleetSecurityRunner::start(null, $cands);
fs_run_cleanup();
$j3 = new ManagementJob((int)$run3->steps()[0]['job_id'], TRUE);
$j3->set('mjb_status', 'failed'); $j3->set('mjb_output', 'agent: script refused'); $j3->save();
FleetSecurityRunner::advance($run3);
$run3->load();
check((string)$run3->get('fsr_status') === FleetSecurityRun::STATUS_HALTED && strpos($run3->steps()[0]['reason'], 'no result to read') !== false,
	'a job that failed with no result halts the run', json_encode($run3->steps()[0]));

// Stop: what is pending is skipped, between nodes.
$run4 = FleetSecurityRunner::start(null, $cands);
fs_run_cleanup();
FleetSecurityRunner::stop($run4, null);
$run4->load();
$s4 = $run4->steps();
check((string)$run4->get('fsr_status') === FleetSecurityRun::STATUS_STOPPED && $s4[0]['verdict'] === 'running' && $s4[1]['verdict'] === 'skipped' && $s4[2]['verdict'] === 'skipped',
	'stopped: the node in hand keeps its job, the rest are skipped', json_encode($s4));
check(FleetSecurityRun::running() === null, 'nothing is left running');

// A site container whose host is replacing its containers (reviewer2 c).
$rh_node = fs_node(array('mgn_name' => 'HarnessTest FS r host'));
$group = new ManagedHost(NULL);
$group->set('mgh_slug', 'harnessfs-' . bin2hex(random_bytes(3)));
$group->set('mgh_name', 'HarnessTest FS group');
$group->set('mgh_host', '192.0.2.32');
$group->set('mgh_mgn_managed_node_id', (int)$rh_node->key);
$group->save();
$group->load();
harness_register_row('mgh_managed_hosts', 'mgh_managed_host_id', $group->key);
$rc_node = fs_node(array('mgn_name' => 'HarnessTest FS r site', 'mgn_container_name' => 'fsr', 'mgn_mgh_managed_host_id' => (int)$group->key));
$host_report = function ($running, $replacing = false) use ($rh_node) {
	$rh_node->set('mgn_last_host_report', array('site_refresh' => array('scheduled' => true, 'running' => $running, 'replaced' => array(), 'passed_over' => array(), 'holds' => array(), 'security_packages' => array()),
		'containers' => array(array('name' => 'fsr', 'state' => 'running', 'replacing' => $replacing))));
	$rh_node->save();
};
$host_report(true);
$plan = FleetSecurityRunner::plan(array($rh_node, $rc_node));
$lo = array_column($plan['left_out'], 'reason', 'name');
check(count($plan['nodes']) === 1 && strpos($lo['HarnessTest FS r site'] ?? '', 'its host is replacing its containers') === 0,
	'a container whose host is replacing its containers is left out, saying why', json_encode($lo));
$host_report(false);
check(FleetSecurityRunner::host_replacing($rc_node) === null && FleetSecurityRunner::host_replacing($rh_node) === null,
	'a host not replacing anything, and a machine: nothing in the way');
$run5 = FleetSecurityRunner::start(null, array($rh_node, $rc_node));
fs_run_cleanup();
fs_finish(new ManagementJob((int)$run5->steps()[0]['job_id'], TRUE), fs_object('nothing_to_install'));
FleetSecurityRunner::advance($run5);
check($run5->steps()[1]['verdict'] === 'running', 'the container\'s install is queued after its host\'s');
fs_run_cleanup();
$host_report(false, true);
FleetSecurityRunner::advance($run5);
$run5->load();
$s5 = $run5->steps();
check($s5[1]['verdict'] === 'skipped' && strpos($s5[1]['reason'], 'its container is being replaced') === 0
		&& (string)$run5->get('fsr_status') === FleetSecurityRun::STATUS_COMPLETED,
	'its container replaced while its install was open: passed as skipped, not waited out for an hour', json_encode($s5));

// Nothing to run.
$threw = '';
try { FleetSecurityRunner::start(null, array($old_agent, $off)); } catch (FleetSecurityRunException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'No node can install') !== false, 'a plan with no node that can run the word refuses to start', $threw);

fs_run_cleanup();
harness_finish();
