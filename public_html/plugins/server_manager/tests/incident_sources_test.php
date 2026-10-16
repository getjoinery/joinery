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
 * @version 1.6 - plane:cloud_account_key is registered (its conditions: escrow_tier2_test, B77)
 * @version 1.5 - the node's alerts are on: a hand-made node on dev is a test cloud box, which is quiet by default
 * @version 1.4 - plane:site_code_unverified: critical for a failed code check, naming each package; a warning
 *                for a running site not checked on a host that refuses unsigned code (host_restrictions D8)
 * @version 1.3 - plane:release_log_entry and plane:release_log_tail_blind (release_transparency O5)
 * @version 1.2 - plane:agent_update_refused (release_transparency O7)
 * @version 1.2 - plane:mail_gateway: quiet with no site on or a failed unit; critical when a site does not
 *                answer or its certificate is under a week; a warning for restarts and a two-week certificate
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
$node->set('mgn_alerts', 'on');
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
	'plane:release_log', 'plane:release_log_blind', 'plane:agent_update_refused',
	'plane:release_log_entry', 'plane:release_log_tail_blind', 'plane:mail_gateway', 'plane:site_code_unverified',
	'plane:cloud_account_key') as $want) {
	check(in_array($want, $names, true), $want . ' is registered');
}

// The release-log watch belongs to this management node's own node only
// (its conditions are release_statement_test's).
check((new IncidentSourceReleaseLog())->evaluate($node) === null && (new IncidentSourceReleaseLogBlind())->evaluate($node) === null
	&& (new IncidentSourceReleaseLogEntry())->evaluate($node) === null && (new IncidentSourceReleaseLogTailBlind())->evaluate($node) === null,
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
section('Mail apps gateway');

$mg = new IncidentSourceMailGateway();
$gw = function (array $over = array(), array $site = array()) {
	$g = array_merge(array('version' => '1.0.0', 'state' => 'active', 'restarts' => 0, 'up_seconds' => 86400, 'connections' => 4,
		'sites' => array(array_merge(array('site' => 'shop', 'name' => 'mail.shop.example', 'imap' => 'ok', 'submission' => 'ok',
			'days_left' => 60), $site))), $over);
	return JobResultProcessor::sanitise_host_report(array('mail_gateway' => $g));
};
$set(array('mgn_last_host_report' => JobResultProcessor::sanitise_host_report(array('mail_gateway' => 'none'))));
check($mg->evaluate($node) === null, 'Not installed: nothing');
$set(array('mgn_last_host_report' => $gw(array('state' => 'inactive', 'sites' => array()))));
check($mg->evaluate($node) === null, 'Installed with no site turned on, and stopped: nothing');
$set(array('mgn_last_host_report' => $gw()));
check($mg->evaluate($node) === null, 'Running, every site answering, certificate fine: nothing');
$set(array('mgn_last_host_report' => $gw(array('state' => 'inactive'), array('imap' => 'no_answer', 'submission' => 'no_answer'))));
$v = $mg->evaluate($node);
check($v !== null && $v['severity'] === 'critical' && $v['title'] === 'The mail apps gateway is not running',
	'A site on and the unit stopped: critical', json_encode($v));
$set(array('mgn_last_host_report' => $gw(array(), array('imap' => 'no_answer'))));
$v = $mg->evaluate($node);
check($v !== null && $v['severity'] === 'critical' && $v['title'] === 'The mail apps gateway does not answer mail programs'
	&& $v['detail']['Not answering'] === 'mail.shop.example: IMAP (993)', 'Running but not greeting on 993: critical, naming the site', json_encode($v));
$set(array('mgn_last_host_report' => $gw(array('state' => 'failed'), array('imap' => 'no_answer', 'submission' => 'no_answer'))));
check($mg->evaluate($node) === null, 'The unit failed outright: left to plane:failed_units, one incident for one fault');
$set(array('mgn_last_host_report' => $gw(array('restarts' => 5, 'up_seconds' => 120))));
$v = $mg->evaluate($node);
check($v !== null && $v['severity'] === 'warning' && $v['title'] === 'The mail apps gateway keeps restarting', 'Five restarts, up two minutes: a warning', json_encode($v));
$set(array('mgn_last_host_report' => $gw(array('restarts' => 5, 'up_seconds' => 7200))));
check($mg->evaluate($node) === null, 'Restarts long ago, up two hours since: nothing');
$set(array('mgn_last_host_report' => $gw(array(), array('days_left' => 10))));
$v = $mg->evaluate($node);
check($v !== null && $v['severity'] === 'warning' && $v['title'] === 'A mail apps certificate is about to expire', 'Ten days on the certificate: a warning', json_encode($v));
$set(array('mgn_last_host_report' => $gw(array(), array('days_left' => 3))));
check(($mg->evaluate($node)['severity'] ?? '') === 'critical', 'Three days: critical');
$set(array('mgn_last_host_report' => null));
check($mg->evaluate($node) === null, 'No report: nothing');

// ---------------------------------------------------------------------------
section('Site code unverified (host_restrictions D8)');

$code = new IncidentSourceSiteCodeUnverified();
$now = time();
$ok_at = gmdate('Y-m-d\TH:i:s\Z', $now - 3600);
$site_c = function (string $name, $check, string $state = 'running') {
	$c = array('name' => $name, 'state' => $state, 'health' => 'healthy', 'answers' => 'yes');
	if ($check !== null) { $c['code_check'] = $check; }
	return $c;
};
$hr = function (array $containers, $restrictions = array('unsigned_packages' => 'refuse')) use ($now) {
	$r = array('containers' => $containers, 'generated_at' => $now);
	if ($restrictions !== null) { $r['host_restrictions'] = $restrictions; }
	return JobResultProcessor::sanitise_host_report($r);
};
$set(array('mgn_last_host_report' => $hr(array($site_c('acme', array('state' => 'ok', 'at' => $ok_at, 'failures' => array()))))));
check($code->evaluate($node) === null, 'Every site checked and ok: nothing');
$set(array('mgn_last_host_report' => $hr(array(
	$site_c('acme', array('state' => 'failed', 'at' => $ok_at, 'failures' => array(
		array('kind' => 'core', 'name' => '', 'verdict' => 'tampered', 'file' => 'public_html/includes/MailIdentityGuard.php'),
		array('kind' => 'plugin', 'name' => 'mailbox', 'verdict' => 'extra_file', 'file' => 'includes/relay_admin.php'),
		array('kind' => 'theme', 'name' => 'pagey', 'verdict' => 'unsigned', 'file' => '<script>'),
	))),
	$site_c('other', array('state' => 'ok', 'at' => $ok_at, 'failures' => array())),
))));
$v = $code->evaluate($node);
check($v !== null && $v['severity'] === 'critical' && $v['title'] === 'Code on site acme is not what was shipped'
	&& $v['detail']['Site acme'] === 'core: a file was changed: public_html/includes/MailIdentityGuard.php; '
		. 'plugin mailbox: a file was added: includes/relay_admin.php; theme pagey: not built by Joinery (no signature)'
	&& !isset($v['detail']['Site other']),
	'A failed check: critical, naming the site and each package in plain words, and no other site', json_encode($v));
$set(array('mgn_last_host_report' => $hr(array(
	$site_c('acme', array('state' => 'failed', 'at' => $ok_at, 'failures' => array(array('kind' => 'core', 'name' => '', 'verdict' => 'missing_file', 'file' => 'x.php')))),
	$site_c('beta', array('state' => 'failed', 'at' => $ok_at, 'failures' => array())),
))));
$v = $code->evaluate($node);
check($v !== null && $v['title'] === 'Code on 2 sites is not what was shipped' && $v['detail']['Site beta'] === 'the check failed without naming a package',
	'Two sites failing: one incident naming both; a failure with no package listed is still a failure', json_encode($v));
$set(array('mgn_last_host_report' => $hr(array($site_c('acme', 'none'), $site_c('stale', array('state' => 'ok', 'at' => gmdate('Y-m-d\TH:i:s\Z', $now - 3 * 86400), 'failures' => array())),
	$site_c('weird', array('state' => 'bogus')), $site_c('stopped', 'none', 'exited'), $site_c('old', null)))));
$v = $code->evaluate($node);
check($v !== null && $v['severity'] === 'warning'
	&& strpos($v['detail']['Not checked'], 'acme: no check on record') !== false
	&& strpos($v['detail']['Not checked'], 'stale: last checked') !== false
	&& strpos($v['detail']['Not checked'], 'weird: the check could not be read') !== false
	&& strpos($v['detail']['Not checked'], 'old: no check on record') !== false
	&& strpos($v['detail']['Not checked'], 'stopped') === false,
	'A restricted host: a running site with no check, a stale one or an unreadable one is a warning; a stopped site is not', json_encode($v));
$set(array('mgn_last_host_report' => $hr(array($site_c('acme', 'none')), 'none')));
check($code->evaluate($node) === null, 'A host that does not refuse unsigned code: no check on record is nothing');
$set(array('mgn_last_host_report' => $hr(array($site_c('acme', 'none')), 'unknown')));
check(($code->evaluate($node)['severity'] ?? '') === 'warning', 'A host whose restrictions file cannot be read counts as restricted');
$set(array('mgn_last_host_report' => $hr(array($site_c('acme', array('state' => 'failed', 'at' => $ok_at,
	'failures' => array(array('kind' => 'plugin', 'name' => 'x', 'verdict' => 'tampered', 'file' => 'a.php'))))), 'none')));
check(($code->evaluate($node)['severity'] ?? '') === 'critical', 'A failed check is critical whatever the host says of its restrictions');
$set(array('mgn_last_host_report' => JobResultProcessor::sanitise_host_report(array())));
check($code->evaluate($node) === null, 'A report with no containers: nothing');
$set(array('mgn_last_host_report' => null));

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
section('Agent update refused');

$refused = new IncidentSourceAgentUpdateRefused();
$set(array('mgn_agent_update_state' => 'unlogged', 'mgn_agent_update_offered' => '1.66.0', 'mgn_agent_version' => '1.65.0'));
check($refused->evaluate($node) === null, 'An unpaired node: nothing, whatever it last said');
$set(array('mgn_agent_public_key' => base64_encode(str_repeat("\x05", 32))));
$v = $refused->evaluate($node);
check($v !== null && $v['severity'] === 'critical' && strpos($v['title'], 'not in the public log') !== false
	&& $v['detail']['Version refused'] === '1.66.0' && $v['detail']['Running'] === '1.65.0',
	'A release not in the public log: critical, naming both versions', json_encode($v));
$set(array('mgn_agent_update_state' => 'verify_failed', 'mgn_agent_update_offered' => ''));
$v = $refused->evaluate($node);
check($v !== null && $v['severity'] === 'critical' && strpos($v['detail']['Version refused'], 'not named') === 0,
	'A failed verification: critical, and an unreadable manifest names no version', json_encode($v));
$set(array('mgn_agent_update_state' => 'version_rejected', 'mgn_agent_update_offered' => '1.66.0'));
check(($refused->evaluate($node)['severity'] ?? '') === 'warning', 'A version that failed to start here: a warning');
foreach (array('', 'none', 'current', 'update_pending', 'fetch_failed', 'unsigned_build', 'no_binary') as $state) {
	$set(array('mgn_agent_update_state' => $state));
	check($refused->evaluate($node) === null, "Update state '{$state}': nothing");
}
check(strpos($refused->cleared_text($node), 'v1.66.0') !== false, 'The clear names the version now on offer');
$set(array('mgn_agent_update_state' => null, 'mgn_agent_update_offered' => null, 'mgn_agent_version' => null, 'mgn_agent_public_key' => null));

// ---------------------------------------------------------------------------
section('Support bundle refused');

$bundle = new IncidentSourceAgentBundleRefused();
$set(array('mgn_agent_bundle_state' => 'unlogged', 'mgn_agent_bundle_version' => 'abc123'));
check($bundle->evaluate($node) === null, 'An unpaired node: nothing, whatever it last said');
$set(array('mgn_agent_public_key' => base64_encode(str_repeat("\x05", 32))));
$v = $bundle->evaluate($node);
check($v !== null && $v['severity'] === 'critical' && strpos($v['title'], 'not in the public log') !== false
	&& $v['detail']['Bundle installed'] === 'abc123',
	'A bundle not in the public log: critical, naming the bundle the machine keeps', json_encode($v));
$set(array('mgn_agent_bundle_state' => 'verify_failed', 'mgn_agent_bundle_version' => ''));
$v = $bundle->evaluate($node);
check($v !== null && $v['severity'] === 'critical' && $v['detail']['Bundle installed'] === 'none',
	'A bundle that failed verification: critical', json_encode($v));
foreach (array('', 'current') as $state) {
	$set(array('mgn_agent_bundle_state' => $state));
	check($bundle->evaluate($node) === null, "Bundle state '{$state}': nothing");
}
$set(array('mgn_agent_bundle_state' => null, 'mgn_agent_bundle_version' => null, 'mgn_agent_public_key' => null));

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
