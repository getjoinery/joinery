<?php
/** @joinery-test
 * name: agent_vocabulary_words
 * tier: db
 * env: dev-only
 * needs: []
 */

/**
 * The plane half of the words specs/agent_recipes_and_vocabulary.md adds:
 * restart_unit, restart_container, run_installer, file_head, schema_probe,
 * reclaim_managed_file.
 *
 *   - Each builder sends a name and closed values, refuses anything outside
 *     them here (the node refuses again), and refuses a node that does not
 *     report the word with the one standard sentence.
 *   - Each mirrored list (RESTART_UNIT_UNITS, RUN_INSTALLER_CORE,
 *     FILE_HEAD_FILES) matches the agent's own compiled list, read from the
 *     agent source when this box has it: three copies on purpose, never three
 *     lists.
 *   - Each result handler keeps compiled facts only, bounded, and turns a
 *     restart the node did not accept, or an installer that never reached its
 *     ok line, into a failed job.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

function avw_node(array $fields = array()) {
	$node = new ManagedNode(NULL);
	$suffix = bin2hex(random_bytes(3));
	$node->set('mgn_name', 'HarnessTest AVW ' . $suffix);
	$node->set('mgn_slug', 'harnessavw-' . $suffix);
	$node->set('mgn_host', '192.0.2.40');
	$node->set('mgn_web_root', '/var/www/html/avw/public_html');
	$node->set('mgn_agent_public_key', base64_encode(random_bytes(32)));
	$node->set('mgn_agent_version', AgentVocabulary::FLOOR);
	$node->set('mgn_agent_primitives', 'apply_update,check_status,file_head,host_report,reclaim_managed_file,restart_container,restart_unit,run_installer,schema_probe');
	$node->set('mgn_agent_log_access', 'on');
	foreach ($fields as $k => $v) { $node->set($k, $v); }
	$node->save();
	$node->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node->key);
	return $node;
}

function avw_refused(callable $fn) {
	try { $fn(); } catch (Exception $e) { return $e->getMessage(); }
	return '';
}

function avw_job($node, $type, $object, $params = null) {
	$job = new ManagementJob(NULL);
	$job->set('mjb_mgn_managed_node_id', $node->key);
	$job->set('mjb_job_type', $type);
	$job->set('mjb_status', 'completed');
	$job->set('mjb_commands', array());
	if ($params !== null) { $job->set('mjb_parameters', json_encode($params)); }
	$data = is_string($object) ? array('output' => $object, 'output_bytes' => strlen($object)) : $object;
	$job->set('mjb_output', "=== [Step 1/1] word ===\n" . json_encode(array('api_version' => '1.0', 'data' => $data)));
	$job->save();
	$job->load();
	harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $job->key);
	return $job;
}

$node = avw_node();
$bare = avw_node(array('mgn_agent_primitives' => 'apply_update,check_status'));

// ---------------------------------------------------------------------------
section('Builders: a name and closed values, nothing else');

check(JobCommandBuilder::build_restart_unit($node, 'php-fpm') === array('primitive' => 'restart_unit', 'params' => array('unit' => 'php-fpm')),
	'restart_unit sends the unit name only');
foreach (array('joinery-agent', 'sshd', 'apache2.service', 'cron;reboot', '') as $bad) {
	check(avw_refused(function () use ($node, $bad) { JobCommandBuilder::build_restart_unit($node, $bad); }) !== '',
		"restart_unit refuses '{$bad}' here");
}
check(JobCommandBuilder::build_restart_container($node, 'site_2') === array('primitive' => 'restart_container', 'params' => array('name' => 'site_2')),
	'restart_container sends the container name only');
foreach (array('-rf', 'Site', '../x', str_repeat('a', 51)) as $bad) {
	check(avw_refused(function () use ($node, $bad) { JobCommandBuilder::build_restart_container($node, $bad); }) !== '',
		"restart_container refuses '" . substr($bad, 0, 12) . "'");
}
check(JobCommandBuilder::build_run_installer($node, 'render_vhost.sh')['params'] === array('name' => 'render_vhost.sh')
	&& JobCommandBuilder::build_run_installer($node, 'plugin:mailbox')['params'] === array('name' => 'plugin:mailbox'),
	'run_installer sends a core installer name or plugin:NAME');
foreach (array('install.sh', '../install_agent.sh', 'plugin:../x', 'plugin:Mail', '/bin/sh') as $bad) {
	check(avw_refused(function () use ($node, $bad) { JobCommandBuilder::build_run_installer($node, $bad); }) !== '',
		"run_installer refuses '{$bad}'");
}
check(JobCommandBuilder::build_file_head($node, 'postfix_main', 50) === array('primitive' => 'file_head', 'params' => array('file' => 'postfix_main', 'lines' => 50)),
	'file_head sends a listed name and a line count');
check(JobCommandBuilder::build_file_head($node, 'apache_site', 10, 'othersite')['params']['site'] === 'othersite',
	'and a site slug when one is named');
foreach (array(array('/etc/shadow', 10), array('postfix_domains', 10), array('postfix_main', 0), array('postfix_main', 401)) as $bad) {
	check(avw_refused(function () use ($node, $bad) { JobCommandBuilder::build_file_head($node, $bad[0], $bad[1]); }) !== '',
		'file_head refuses ' . var_export($bad, true));
}
$off = avw_node(array('mgn_agent_log_access' => 'off'));
check(strpos(avw_refused(function () use ($off) { JobCommandBuilder::build_file_head($off, 'postfix_main'); }), 'agent_log_access') !== false,
	'file_head is behind the owner\'s log switch, as site_log is');
check(JobCommandBuilder::build_schema_probe($node, 'usr_users') === array('primitive' => 'schema_probe', 'params' => array('table' => 'usr_users')),
	'schema_probe sends a table name, never SQL');
foreach (array('Users', 'usr_users; drop table x', 'pg_catalog.pg_authid', '') as $bad) {
	check(avw_refused(function () use ($node, $bad) { JobCommandBuilder::build_schema_probe($node, $bad); }) !== '',
		"schema_probe refuses '{$bad}'");
}
check(JobCommandBuilder::build_reclaim_managed_file($node, 'apache_mpm_event') === array('primitive' => 'reclaim_managed_file', 'params' => array('file' => 'apache_mpm_event')),
	'reclaim_managed_file sends a resettable name only');
foreach (array('apache2_conf', 'apache_site_ssl', 'postfix_main', 'sysctl_security', 'docker_daemon', '/etc/passwd') as $bad) {
	check(avw_refused(function () use ($node, $bad) { JobCommandBuilder::build_reclaim_managed_file($node, $bad); }) !== '',
		"reclaim_managed_file refuses '{$bad}' here: it is never resettable");
}
foreach (array_keys(JobCommandBuilder::RECLAIM_FILES) as $f) {
	check(array_key_exists($f, JobCommandBuilder::FILE_HEAD_FILES), "resettable {$f} is also readable");
}
foreach (array('restart_unit' => array('cron'), 'restart_container' => array('x'), 'run_installer' => array('render_vhost.sh'),
		'file_head' => array('postfix_main'), 'schema_probe' => array('usr_users'),
		'reclaim_managed_file' => array('apache_mpm_event')) as $word => $args) {
	$msg = avw_refused(function () use ($bare, $word, $args) { call_user_func_array(array('JobCommandBuilder', 'build_' . $word), array_merge(array($bare), $args)); });
	check(strpos($msg, 'update this node') !== false && strpos($msg, $word) !== false,
		"a node that does not report {$word} is refused with the standard state", $msg);
}

// ---------------------------------------------------------------------------
section('Mirrored lists match the agent\'s own');

$src = AgentDistPublisher::sourcePath();
if (!$src || !is_dir($src . '/primitives')) {
	harness_skip('mirror parity', 'no agent source on this box');
} else {
	$go_list = function ($file, $var) use ($src) {
		$code = (string)file_get_contents($src . '/primitives/' . $file);
		if (!preg_match('/' . preg_quote($var, '/') . '\s*=\s*\[\]string\{(.*?)\}/s', $code, $m)) { return null; }
		preg_match_all('/"([^"]+)"/', $m[1], $mm);
		return $mm[1];
	};
	check($go_list('operate_restart_unit.go', 'restartUnitUnits') === array_keys(JobCommandBuilder::RESTART_UNIT_UNITS),
		'RESTART_UNIT_UNITS is the agent\'s restartUnitUnits, in order');
	check($go_list('operate_run_installer.go', 'runInstallerCore') === array_keys(JobCommandBuilder::RUN_INSTALLER_CORE),
		'RUN_INSTALLER_CORE is the agent\'s runInstallerCore, in order');
	check($go_list('operate_reclaim_managed_file.go', 'reclaimFiles') === array_keys(JobCommandBuilder::RECLAIM_FILES),
		'RECLAIM_FILES is the agent\'s reclaimFiles, in order');
	$fh = (string)file_get_contents($src . '/primitives/observe_file_head.go');
	preg_match_all('/^\t"([a-z0-9_]+)":\s+\{Path:/m', $fh, $mm);
	$agent_files = $mm[1];
	$plane_files = array_keys(JobCommandBuilder::FILE_HEAD_FILES);
	sort($agent_files); sort($plane_files);
	check($agent_files === $plane_files && count($plane_files) === 27,
		'FILE_HEAD_FILES names exactly the agent\'s readable list', implode(',', array_diff($agent_files, $plane_files)) . ' / ' . implode(',', array_diff($plane_files, $agent_files)));
}

// ---------------------------------------------------------------------------
section('Results: compiled facts, and a failure called a failure');

$ok = avw_job($node, 'restart_unit', json_encode(array('unit' => 'php8.3-fpm.service', 'absent' => false,
	'before' => array('active_state' => 'active', 'sub_state' => 'running', 'result' => 'success'), 'restarted' => true,
	'after' => array('active_state' => 'active', 'sub_state' => 'running', 'result' => 'success<script>'))));
JobResultProcessor::process($ok);
$ok->load();
$r = json_decode((string)$ok->get('mjb_result'), true);
check($r['read'] === true && $r['target'] === 'php8.3-fpm.service' && $r['restarted'] === true
	&& strpos(json_encode($r), '<') === false && $ok->get('mjb_status') === 'completed',
	'an accepted restart is recorded, its states reduced to safe words', var_export($r, true));
$queued = 0;
foreach (new MultiManagementJob(array('node_id' => (int)$node->key, 'job_type' => 'host_report')) as $j) {
	harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $j->key);
	$queued++;
}
check($queued === 1, 'and a host report is queued so the Host card re-measures');

$no = avw_job($node, 'restart_container', json_encode(array('container' => 'site1', 'before' => array('state' => 'exited', 'health' => 'none'),
	'restarted' => false, 'after' => array('state' => 'exited', 'health' => 'none'))));
JobResultProcessor::process($no);
$no->load();
check($no->get('mjb_status') === 'failed' && strpos((string)$no->get('mjb_error_message'), 'not accepted') !== false,
	'a restart the node did not accept is a failed job');

$ri = avw_job($node, 'run_installer', "core installers: running render_vhost.sh\ncore installers: render_vhost.sh: ok\n", array('name' => 'render_vhost.sh'));
JobResultProcessor::process($ri);
$ri->load();
check($ri->get('mjb_status') === 'completed' && json_decode((string)$ri->get('mjb_result'), true)['ran'] === true,
	'an installer that reached its ok line is a completed job');
$ri_bad = avw_job($node, 'run_installer', "host installers: another run holds the lock (pid 4 since x) - waited 600s, leaving it to that one\n", array('name' => 'host_housekeeping.sh'));
JobResultProcessor::process($ri_bad);
$ri_bad->load();
check($ri_bad->get('mjb_status') === 'failed' && strpos((string)$ri_bad->get('mjb_error_message'), 'another run holds the lock') !== false,
	'one that never ran is failed, naming why', (string)$ri_bad->get('mjb_error_message'));
$ri_plugin = avw_job($node, 'run_installer', "plugin installers: mailbox: running host_installer.sh\nplugin installers: mailbox: ok\n", array('name' => 'plugin:mailbox'));
JobResultProcessor::process($ri_plugin);
$ri_plugin->load();
check($ri_plugin->get('mjb_status') === 'completed', 'a plugin installer\'s ok line counts for plugin:NAME');

$rc_job = avw_job($node, 'reclaim_managed_file', "reclaim: moved /etc/apache2/mods-available/mpm_event.conf to /etc/apache2/mods-available/mpm_event.conf.reclaimed-20260923120000\ncore installers: running host_housekeeping.sh\ncore installers: host_housekeeping.sh: ok\nreclaim: /etc/apache2/mods-available/mpm_event.conf is the platform's again; the previous version is kept at /etc/apache2/mods-available/mpm_event.conf.reclaimed-20260923120000\n", array('file' => 'apache_mpm_event'));
JobResultProcessor::process($rc_job);
$rc_job->load();
$rr = json_decode((string)$rc_job->get('mjb_result'), true);
check($rc_job->get('mjb_status') === 'completed' && $rr['name'] === 'host_housekeeping.sh' && $rr['ran'] === true && count($rr['reclaim']) === 2,
	'a reset is judged by its owner\'s ok line and keeps the node\'s own account of what moved', var_export($rr, true));

$fh_job = avw_job($node, 'file_head', array('file' => 'rspamd_redis', 'path' => '/etc/rspamd/local.d/redis.conf', 'present' => true,
	'size_bytes' => 40, 'modified_time' => '2026-09-23T10:00:00Z', 'lines_returned' => 2, 'truncated' => false,
	'text' => "servers = \"127.0.0.1:6379\";\npassword\n"));
JobResultProcessor::process($fh_job);
$f = json_decode((string)$fh_job->get('mjb_result'), true);
check($f['read'] === true && $f['file'] === 'rspamd_redis' && $f['path'] === '/etc/rspamd/local.d/redis.conf' && $f['lines_returned'] === 2,
	'a file_head result keeps the name, the path and the lines', var_export($f, true));
check(in_array('file_head', ManagementJob::LOG_EXCERPT_TYPES, true), 'and its text ages out on the log-excerpt window');

$sp = avw_job($node, 'schema_probe', array('table' => 'usr_users', 'exists' => true, 'row_count' => 42, 'row_count_exact' => true,
	'columns' => array(array('name' => 'usr_user_id', 'type' => 'bigint', 'nullable' => false), array('name' => 'Bad;Name', 'type' => 'text<x>', 'nullable' => true)),
	'indexes' => array(array('name' => 'usr_users_pkey', 'definition' => 'CREATE UNIQUE INDEX ...'))));
JobResultProcessor::process($sp);
$p = json_decode((string)$sp->get('mjb_result'), true);
check($p['exists'] === true && $p['row_count'] === 42 && $p['columns'][0]['nullable'] === false
	&& $p['columns'][1]['name'] === 'badname' && $p['columns'][1]['type'] === 'textx',
	'a schema_probe result keeps names, types and a count, sanitised', var_export($p, true));

harness_finish();
