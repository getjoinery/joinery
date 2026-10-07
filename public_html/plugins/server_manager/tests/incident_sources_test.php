<?php
/** @joinery-test
 * name: incident_sources
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * This management node's own incident sources (incident_triage.md WP3).
 *
 * For each source, on a throwaway node: the condition holds exactly when the
 * stored facts say it does, with the severity the spec gives it, and does not
 * hold otherwise; and every source is registered from the bootstrap. The
 * three backup sources split NodeMonitorHealth::fleet_backup_health() by its
 * kind, so for any state exactly one of them (or none) holds, matching the
 * dashboard's panel. One reconciler pass over all of them on the node runs
 * clean (signals caught, the reconciler's lock held so the live task on this
 * box never sees the node).
 *
 * Run: php plugins/server_manager/tests/incident_sources_test.php
 *
 * @version 1.1 - plane:machine_transfer is registered (its conditions: machine_transfer_test)
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$main = DbConnector::get_instance()->get_db_link();
$main->query('SELECT pg_advisory_lock(' . IncidentReconciler::LOCK_KEY . ')');
$signals = array();
IncidentReconciler::$dispatch = function ($signal, $payload) use (&$signals) { $signals[] = $signal; };

$node = new ManagedNode(NULL);
$node->set('mgn_name', 'HarnessTest incident sources');
$node->set('mgn_slug', 'harnesstest-src-' . bin2hex(random_bytes(3)));
$node->set('mgn_host', '192.0.2.44');
$node->set('mgn_ssh_user', 'root');
$node->set('mgn_site_url', 'https://sources.example');
$node->set('mgn_enabled', true);
$node->set('mgn_uptime_enabled', true);
$node->set('mgn_uptime_last_status', 'up');
$node->set('mgn_uptime_last_check', gmdate('Y-m-d H:i:s'));
$node->set('mgn_uptime_last_conclusive', gmdate('Y-m-d H:i:s'));
$node->save();
$node->load();
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node->key);
$node_id = (int)$node->key;

$set = function (array $cols) use ($node) {
	foreach ($cols as $k => $v) { $node->set($k, $v); }
	$node->save();
	$node->load();
	IncidentBackupVerdict::forget();
};

// ---------------------------------------------------------------------------
section('Every source is registered');

$names = array_keys(IncidentSources::all());
foreach (array('plane:site_down', 'plane:backup_failed', 'plane:backups_stopped', 'plane:backup_unverified', 'plane:failed_units',
	'plane:certificate', 'plane:agent_silent', 'plane:unmanageable', 'plane:monitoring_broken', 'plane:machine_transfer',
	'plane:release_log', 'plane:release_log_blind') as $want) {
	check(in_array($want, $names, true), $want . ' is registered');
}

// The release-log watch belongs to this management node's own node only
// (its conditions are release_statement_test's).
check((new IncidentSourceReleaseLog())->evaluate($node) === null && (new IncidentSourceReleaseLogBlind())->evaluate($node) === null,
	'The release-log sources say nothing about any other node');

// ---------------------------------------------------------------------------
section('Failed units');

$units = new IncidentSourceFailedUnits();
$set(array('mgn_last_host_report' => JobResultProcessor::sanitise_host_report(array('failed_units' => array('fail2ban.service', 'x.service')))));
$v = $units->evaluate($node);
check($v !== null && $v['severity'] === 'warning' && $v['title'] === '2 services on the machine failed'
	&& $v['detail']['Failed units'] === 'fail2ban.service, x.service', 'A report naming failed units holds, a warning', json_encode($v));
$set(array('mgn_last_host_report' => JobResultProcessor::sanitise_host_report(array('failed_units' => array()))));
check($units->evaluate($node) === null, 'A report naming none does not');
$set(array('mgn_last_host_report' => JobResultProcessor::sanitise_host_report(array())));
check($units->evaluate($node) === null, 'A report that could not list units does not');
$set(array('mgn_last_host_report' => null));
check($units->evaluate($node) === null, 'No report does not');

// ---------------------------------------------------------------------------
section('Certificate');

$cert = new IncidentSourceCertificate();
check($cert->evaluate($node) === null, 'No stored problem: nothing');
$set(array('mgn_cert_problem' => array('reason' => 'overdue', 'title' => 'TLS certificate renewal is overdue',
	'not_after' => time() + 20 * 86400, 'detail' => array('Host' => 'sources.example'))));
$v = $cert->evaluate($node);
check($v !== null && $v['severity'] === 'warning' && $v['title'] === 'TLS certificate renewal is overdue' && $v['detail']['Host'] === 'sources.example',
	'Overdue with 20 days left: a warning, with the stored diagnosis', json_encode($v));
$set(array('mgn_cert_problem' => array('reason' => 'expiring', 'title' => 'The TLS certificate expires soon', 'not_after' => time() + 3 * 86400, 'detail' => array())));
check(($cert->evaluate($node)['severity'] ?? '') === 'critical', 'Three days left: critical');
$set(array('mgn_cert_problem' => array('reason' => 'uncovered', 'title' => 'The origin certificate does not cover sources.example', 'detail' => array())));
check(($cert->evaluate($node)['severity'] ?? '') === 'warning', 'Another name\'s certificate: a warning');
$set(array('mgn_uptime_enabled' => false));
check($cert->evaluate($node) === null && strpos($cert->cleared_text($node), 'turned off') !== false,
	'Not watched while uptime monitoring (which checks it) is off, and says so when it clears');
$set(array('mgn_uptime_enabled' => true, 'mgn_cert_problem' => null));

// ---------------------------------------------------------------------------
section('Agent silent');

$silent = new IncidentSourceAgentSilent();
check($silent->evaluate($node) === null, 'An unpaired node: nothing');
$set(array('mgn_agent_public_key' => base64_encode(str_repeat("\x05", 32))));
check($silent->evaluate($node) === null, 'Paired, never checked in: nothing yet');
$set(array('mgn_agent_last_poll' => gmdate('Y-m-d H:i:s', time() - 3600)));
check($silent->evaluate($node) === null, 'Last check-in an hour ago: nothing');
$set(array('mgn_agent_last_poll' => gmdate('Y-m-d H:i:s', time() - 3 * 3600)));
$v = $silent->evaluate($node);
check($v !== null && $v['severity'] === 'critical', 'Three hours silent: critical', json_encode($v));
$set(array('mgn_agent_quiet_time' => gmdate('Y-m-d H:i:s', time() - 2 * 3600)));
check($silent->evaluate($node) === null && $silent->cleared_text($node) === 'Its owner switched the agent off.',
	'Switched off by its owner after its last check-in: nothing, and the clear says why');
$set(array('mgn_agent_quiet_time' => null, 'mgn_agent_last_poll' => gmdate('Y-m-d H:i:s'), 'mgn_agent_public_key' => null));

// ---------------------------------------------------------------------------
section('Unmanageable');

$um = new IncidentSourceUnmanageable();
check($um->evaluate($node) === null, 'Scripts verify (no state): nothing');
$set(array('mgn_script_trust' => 'untrusted_manifest', 'mgn_script_trust_since' => gmdate('Y-m-d H:i:s', time() - 600)));
$v = $um->evaluate($node);
check($v !== null && $v['severity'] === 'critical' && $v['title'] === 'This node can no longer be managed', 'An unverifiable manifest: critical', json_encode($v));
$set(array('mgn_script_trust' => 'untrusted_file'));
check(($um->evaluate($node)['title'] ?? '') === 'A file on this node does not match its release', 'A file that does not match its release says so');
$set(array('mgn_script_trust' => 'ok'));
check($um->evaluate($node) === null, 'Verified again: nothing');

// ---------------------------------------------------------------------------
section('Monitoring broken');

$mb = new IncidentSourceMonitoringBroken();
check($mb->evaluate($node) === null, 'Concluding checks: nothing');
$set(array('mgn_uptime_last_error' => 'monitoring host could not resolve sources.example (timeout)'));
$v = $mb->evaluate($node);
check($v !== null && $v['severity'] === 'warning' && strpos($v['detail']['Why'], 'could not resolve') !== false,
	'A check that cannot conclude: a warning, saying why', json_encode($v));
$set(array('mgn_uptime_enabled' => false));
check($mb->evaluate($node) === null && strpos($mb->cleared_text($node), 'is off') !== false, 'Monitoring off: nothing, and the clear says so');
$set(array('mgn_uptime_enabled' => true, 'mgn_uptime_last_error' => null));

// ---------------------------------------------------------------------------
section('Backups: one kind at a time, as the dashboard judges');

$backup_sources = array('failed' => new IncidentSourceBackupFailed(), 'stopped' => new IncidentSourceBackupsStopped(),
	'unverified' => new IncidentSourceBackupUnverified());
$holding = function () use ($backup_sources, $node): array {
	$out = array();
	foreach ($backup_sources as $kind => $src) {
		if ($src->evaluate($node) !== null) { $out[] = $kind; }
	}
	return $out;
};
check($holding() === array(), 'A node with no site root is not backed up from here: no backup incident');
check(strpos($backup_sources['failed']->cleared_text($node), 'no longer backed up from here') !== false,
	'and a clear on such a node says it is no longer backed up from here');
$set(array('mgn_web_root' => '/var/www/html/sources/public_html', 'mgn_create_time' => gmdate('Y-m-d H:i:s', time() - 10 * 86400),
	'mgn_last_status_data' => array('backup_recovery_state' => 'missing'), 'mgn_backup_recovery_fpr' => ''));
check($holding() === array('stopped') && IncidentBackupVerdict::for_node($node)['kind'] === 'stopped',
	'No recovery key on the node: backups cannot run, so backups have stopped', json_encode($holding()));
// From here on the node holds a proven key, so each case is judged on its runs.
$set(array('mgn_last_status_data' => array('backup_recovery_state' => 'proven'), 'mgn_backup_recovery_fpr' => str_repeat('ab', 16)));
$kinds_seen = array();
foreach (array(
	array('failed', gmdate('Y-m-d H:i:s', time() - 3600)),
	array('success', gmdate('Y-m-d H:i:s', time() - 3600)),
	array('success', gmdate('Y-m-d H:i:s', time() - 5 * 86400)),
	array('warning', gmdate('Y-m-d H:i:s', time() - 3600)),
	array(null, null),
) as $case) {
	$set(array('mgn_last_backup_outcome' => $case[0], 'mgn_last_backup_time' => $case[1]));
	$health = IncidentBackupVerdict::for_node($node);
	$expect = ($health !== null && !empty($health['is_problem'])) ? array($health['kind']) : array();
	$kinds_seen[$health['kind'] ?? 'none'] = true;
	$label = 'outcome ' . var_export($case[0], true) . ($case[1] ? ', ' . round((time() - strtotime($case[1] . ' UTC')) / 3600) . 'h ago' : ', never')
		. ': the dashboard says ' . ($health['label'] ?? 'nothing');
	check($holding() === $expect, $label . '; exactly its kind holds (' . implode(',', $expect ?: array('none')) . ')',
		json_encode(array('holding' => $holding(), 'kind' => $health['kind'] ?? null)));
	if ($expect) {
		$v = $backup_sources[$expect[0]]->evaluate($node);
		check($v['title'] === $health['label'] && $v['severity'] === ($expect[0] === 'unverified' ? 'warning' : 'critical'),
			'with the dashboard\'s label and the spec\'s severity');
	}
}
ksort($kinds_seen);
check(array_keys($kinds_seen) === array('failed', 'ok', 'stopped', 'unverified'),
	'The cases reached every kind: failed, ok, stopped and unverified', json_encode(array_keys($kinds_seen)));
$set(array('mgn_backup_policy' => array('enabled' => false), 'mgn_last_backup_outcome' => 'failed', 'mgn_last_backup_time' => gmdate('Y-m-d H:i:s')));
check($holding() === array(), 'Fleet backups switched off for the node: somebody\'s decision, no incident');
$set(array('mgn_backup_policy' => null, 'mgn_last_backup_outcome' => null, 'mgn_last_backup_time' => null, 'mgn_web_root' => null));

// ---------------------------------------------------------------------------
section('One pass with every source');

$c = IncidentReconciler::run(null, array($node_id));
check($c['busy'] === false && $c['opened'] === 0, 'A healthy node opens nothing', json_encode($c));
$set(array('mgn_last_host_report' => JobResultProcessor::sanitise_host_report(array('failed_units' => array('x.service'))),
	'mgn_script_trust' => 'untrusted_manifest'));
$c = IncidentReconciler::run(null, array($node_id));
$open = array();
foreach (new MultiIncidentRecord(array('node_id' => $node_id, 'status' => 'open')) as $r) {
	harness_register_row('inc_incident_records', 'inc_incident_record_id', $r->key);
	$open[] = (string)$r->get('inc_source');
}
sort($open);
check($open === array('plane:failed_units', 'plane:unmanageable') && $c['opened'] === 2,
	'Two conditions, two incidents', json_encode($open));
check(count($signals) === 2 && in_array(IncidentReconciler::SIGNAL_CRITICAL, $signals, true) && in_array(IncidentReconciler::SIGNAL_WARNING, $signals, true),
	'One signal each, by severity', json_encode($signals));
$set(array('mgn_last_host_report' => null, 'mgn_script_trust' => 'ok'));
$c = IncidentReconciler::run(null, array($node_id));
check($c['cleared'] === 2, 'Both clear when the facts do', json_encode($c));

IncidentReconciler::$dispatch = null;
$main->query('SELECT pg_advisory_unlock(' . IncidentReconciler::LOCK_KEY . ')');
harness_finish();
