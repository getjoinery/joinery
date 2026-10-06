<?php
/** @joinery-test
 * name: hold_stopped_site
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A container site held stopped on its host (specs/multi_tenant_docker_hosts.md
 * WP7), on throwaway rows: a host node, its ManagedHost, and two site rows on it.
 *
 *   - a host report naming a container held makes that site's row 'held';
 *     its neighbour, running, stays a working site;
 *   - a held site is not watched: an incident open on it is cleared, saying
 *     so, and nothing opens while it is held;
 *   - a row in another install state (a switch-over's retired old container)
 *     is left as the switch-over made it;
 *   - a hold_container stop that holds the container (even one whose docker
 *     stop timed out) marks the row at once and writes the hold into the
 *     host's last report; who, when and why are read back from the job
 *     (hold_words); after a start, a later hand hold names no one;
 *   - the Hold stopped action refuses an empty reason, and records the
 *     reason on the job, never in what the host is sent;
 *   - a start that took makes the row a working site again, and a host
 *     report no longer naming the hold does the same;
 *   - a container the host report does not mention is left alone.
 *
 * Run: php plugins/server_manager/tests/hold_stopped_site_test.php
 *
 * @version 1.1 - a stop that timed out still holds; a hand hold after a start names no one (reviewer2 B1, B2)
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/server_manager/logic/node_detail_actions_logic.php'));
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$suffix = bin2hex(random_bytes(3));
$mk_node = function (string $tag, array $fields = array()) use ($suffix): ManagedNode {
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'HarnessTest hold ' . $tag . ' ' . $suffix);
	$n->set('mgn_slug', 'harnesshold-' . $tag . '-' . $suffix);
	$n->set('mgn_host', '192.0.2.61');
	$n->set('mgn_ssh_user', 'root');
	$n->set('mgn_enabled', true);
	foreach ($fields as $k => $v) { $n->set($k, $v); }
	$n->save();
	$n->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $n->key);
	return $n;
};

// The host node is a paired agent reporting every word the plane can build.
$words = array();
foreach (get_class_methods('JobCommandBuilder') as $m) {
	if (preg_match('/^build_([a-z0-9_]+)_primitive$/', $m, $mm)) { $words[] = $mm[1]; }
}
$host_node = $mk_node('host', array(
	'mgn_agent_public_key' => base64_encode(random_bytes(32)),
	'mgn_agent_version'    => AgentVocabulary::FLOOR,
	'mgn_agent_primitives' => implode(',', $words),
));
$mgh = new ManagedHost(NULL);
$mgh->set('mgh_name', 'HarnessTest hold host ' . $suffix);
$mgh->set('mgh_slug', 'harnesshold-' . $suffix);
$mgh->set('mgh_host', '192.0.2.61');
$mgh->set('mgh_mgn_managed_node_id', (int)$host_node->key);
$mgh->save();
$mgh->load();
harness_register_row('mgh_managed_hosts', 'mgh_managed_host_id', $mgh->key);

$c_a = 'harnessha' . $suffix;
$c_b = 'harnesshb' . $suffix;
$c_old = 'harnessho' . $suffix;
$site_a = $mk_node('a', array('mgn_container_name' => $c_a, 'mgn_mgh_managed_host_id' => (int)$mgh->key,
	'mgn_uptime_enabled' => true, 'mgn_uptime_last_status' => 'up', 'mgn_site_url' => 'https://hold-a.example'));
$site_b = $mk_node('b', array('mgn_container_name' => $c_b, 'mgn_mgh_managed_host_id' => (int)$mgh->key));
$old = $mk_node('old', array('mgn_container_name' => $c_old, 'mgn_mgh_managed_host_id' => (int)$mgh->key,
	'mgn_install_state' => 'retired'));

$state = function (ManagedNode $n): string {
	$n->load();
	return trim((string)$n->get('mgn_install_state'));
};
$job = function (string $type, $output, array $params = null) use ($host_node): ManagementJob {
	$j = new ManagementJob(NULL);
	$j->set('mjb_mgn_managed_node_id', (int)$host_node->key);
	$j->set('mjb_job_type', $type);
	$j->set('mjb_status', 'completed');
	$j->set('mjb_commands', array());
	$j->set('mjb_parameters', $params ? json_encode($params) : null);
	$j->set('mjb_output', $output);
	$j->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
	$j->save();
	$j->load();
	return $j;   // registered for cleanup by the sweep at the end
};
$envelope = function (array $object): string {
	return json_encode(array('api_version' => '1.0', 'data' => array('output' => json_encode($object) . "\n", 'output_bytes' => 200)));
};
$host_report = function (array $containers) use ($job, $envelope) {
	$j = $job('host_report', $envelope(array('generated_at' => time(), 'containers' => $containers)));
	JobResultProcessor::process($j);
};
$container = function (string $name, string $state, bool $held = false): array {
	$c = array('name' => $name, 'state' => $state, 'health' => 'none', 'answers' => $state === 'running' ? 'yes' : 'no');
	if ($held) { $c['held'] = true; }
	return $c;
};
$hold_result = function (string $name, string $action, bool $done, bool $held, string $st, array $params) use ($job, $envelope) {
	$j = $job('hold_container', $envelope(array('container' => $name, 'action' => $action, 'done' => $done,
		'held' => $held, 'state' => $st, 'restart' => $held ? 'no' : 'unless-stopped')), $params);
	JobResultProcessor::process($j);
	return $j;
};
$reported_held = function (string $name) use ($host_node): ?bool {
	$host_node->load();
	$r = json_decode((string)$host_node->get('mgn_last_host_report'), true);
	foreach ((array)($r['containers'] ?? array()) as $c) {
		if (($c['name'] ?? '') === $name) { return !empty($c['held']); }
	}
	return null;
};

// Hold the reconciler's lock, so the live task never sees these rows.
$db = DbConnector::get_instance()->get_db_link();
$db->query('SELECT pg_advisory_lock(' . IncidentReconciler::LOCK_KEY . ')');
IncidentReconciler::$dispatch = function ($signal, $payload) {};

try {
	// -----------------------------------------------------------------------
	section('A host report decides which site rows are held');

	$host_report(array($container($c_a, 'exited', true), $container($c_b, 'running'), $container($c_old, 'exited', true)));
	check($state($site_a) === 'held' && $site_a->install_state_label() === 'Held stopped',
		'a container the host holds makes its row Held stopped', $state($site_a));
	check(!$site_a->is_operational(), 'a held site is not a working site, so automation leaves it alone');
	check($state($site_b) === '', 'its running neighbour stays a working site');
	check($state($old) === 'retired', 'a switch-over\'s retired old container keeps its state');
	check(JobCommandBuilder::install_state_color('held') === 'secondary', 'a held site is grey');

	// -----------------------------------------------------------------------
	section('Nothing watches a held site');

	$host_report(array($container($c_a, 'running'), $container($c_b, 'running')));
	check($state($site_a) === '', 'the host no longer holding it makes it a working site again');
	$site_a->set('mgn_uptime_last_status', 'down');
	$site_a->save();
	$sources = array('plane:site_down' => new IncidentSourceSiteDown());
	IncidentReconciler::run($sources, array((int)$site_a->key));
	$open = IncidentRecord::open_for((int)$site_a->key, 'plane:site_down');
	check($open !== null, 'Setup: a down working site has an open incident');
	$host_report(array($container($c_a, 'exited', true), $container($c_b, 'running')));
	$c = IncidentReconciler::run($sources, array((int)$site_a->key));
	$text = '';
	if ($open) {
		foreach (array_reverse(IncidentEvent::for_incident((int)$open->key)) as $e) {
			if ((string)$e->get('ine_kind') === 'cleared') { $text = (string)$e->get('ine_text'); break; }
		}
	}
	check($c['cleared'] === 1 && strpos($text, 'held stopped') !== false,
		'holding it clears its incident, saying the site is held stopped', $text);
	$c = IncidentReconciler::run($sources, array((int)$site_a->key));
	check($c['opened'] + $c['reopened'] === 0 && IncidentRecord::open_for((int)$site_a->key, 'plane:site_down') === null,
		'nothing opens on it while it is held, though its check still says down');

	// -----------------------------------------------------------------------
	section('A hold from here takes effect at once, and says who, when and why');

	check($state($site_b) === '', 'Setup: site b is a working site');
	// The script writes the hold before docker stop, so a stop that timed out
	// still leaves the container held (done false, held true, still running).
	$hold_result($c_b, 'stop', false, true, 'running', array('action' => 'stop', 'name' => $c_b, 'reason' => 'Stop timed out'));
	check($state($site_b) === 'held' && $reported_held($c_b) === true, 'a stop that timed out still holds it, and the row follows');
	check(substr(ManagementJob::hold_words((int)$host_node->key, $c_b), -strlen(': Stop timed out')) === ': Stop timed out',
		'and that job is who held it', ManagementJob::hold_words((int)$host_node->key, $c_b));

	$hold_result($c_b, 'stop', true, true, 'exited', array('action' => 'stop', 'name' => $c_b, 'reason' => 'Unpaid since September'));
	check($state($site_b) === 'held', 'a stop that took makes the row held before the next host report');
	check($reported_held($c_b) === true, 'and writes the hold into the host\'s last report');
	$words = ManagementJob::hold_words((int)$host_node->key, $c_b);
	check(strpos($words, 'Held by ') === 0 && substr($words, -strlen(': Unpaid since September')) === ': Unpaid since September',
		'who held it, when and why are read back from the job', $words);

	$hold_result($c_old, 'stop', true, true, 'exited', array('action' => 'stop', 'name' => $c_old, 'site_copy_id' => 77));
	$words = ManagementJob::hold_words((int)$host_node->key, $c_old);
	check(strpos($words, 'switch-over of site copy #77') !== false, 'a switch-over\'s hold says so', $words);
	check($state($old) === 'retired', 'and leaves its retired row as it was');

	// -----------------------------------------------------------------------
	section('The Hold stopped action');

	$session = SessionControl::get_instance();
	$base_url = '/admin/server_manager/node_detail?mgn_managed_node_id=' . (int)$host_node->key;
	$jobs = function () use ($db, $host_node): int {
		$q = $db->prepare("SELECT count(*) FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = 'hold_container'");
		$q->execute(array((int)$host_node->key));
		return (int)$q->fetchColumn();
	};
	$before = $jobs();
	$_POST = array('action' => 'hold_container', 'op' => 'stop', 'name' => $c_a, 'reason' => "  \n ", SmAdminCsrf::FIELD => SmAdminCsrf::token());
	$r = NodeDetailActions::dispatch($host_node, $session, $base_url, '/\/admin\/server_manager/');
	check($r === $base_url . '&tab=overview' && $jobs() === $before, 'an empty reason is refused, and nothing is queued', (string)$r);

	$_POST = array('action' => 'hold_container', 'op' => 'stop', 'name' => $c_a, 'reason' => "Abuse\n report  #12", SmAdminCsrf::FIELD => SmAdminCsrf::token());
	$r = NodeDetailActions::dispatch($host_node, $session, $base_url, '/\/admin\/server_manager/');
	check(strpos((string)$r, '/admin/server_manager/job_detail?job_id=') === 0 && $jobs() === $before + 1, 'a reason given queues the job', (string)$r);
	if (preg_match('/job_id=(\d+)/', (string)$r, $m)) {
		$q = new ManagementJob((int)$m[1], TRUE);
		$params = json_decode((string)$q->get('mjb_parameters'), true);
		$sent = json_decode((string)$q->get('mjb_commands'), true);
		check(($params['reason'] ?? '') === 'Abuse report #12' && $params['action'] === 'stop' && $params['name'] === $c_a,
			'the job records the reason, on one line', json_encode($params));
		check(strpos(json_encode($sent), 'Abuse') === false, 'the host is never sent the reason', json_encode($sent));
	}
	$_POST = array();

	// -----------------------------------------------------------------------
	section('Started again');

	$hold_result($c_b, 'start', true, false, 'running', array('action' => 'start', 'name' => $c_b));
	check($state($site_b) === '' && $reported_held($c_b) === false, 'a start that took makes it a working site again, and the report says so');
	check(ManagementJob::hold_words((int)$host_node->key, $c_b) === '', 'and no one is named as holding it');
	$host_report(array($container($c_b, 'exited', true)));
	check($state($site_b) === 'held' && ManagementJob::hold_words((int)$host_node->key, $c_b) === '',
		'a later hold placed on the host by hand is followed, and names no one from an earlier hold',
		ManagementJob::hold_words((int)$host_node->key, $c_b));
	$host_report(array($container($c_b, 'running')));
	$host_report(array($container($c_b, 'running')));
	check($state($site_a) === 'held', 'a container the report does not mention is left as it was');
} finally {
	$db->query('SELECT pg_advisory_unlock(' . IncidentReconciler::LOCK_KEY . ')');
	// Every job on the throwaway host, whatever queued it.
	$q = $db->prepare('SELECT mjb_management_job_id FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ?');
	$q->execute(array((int)$host_node->key));
	foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $jid) {
		harness_register_row('mjb_management_jobs', 'mjb_management_job_id', (int)$jid);
	}
	foreach (array($site_a, $site_b, $old) as $n) {
		foreach (new MultiIncidentRecord(array('node_id' => (int)$n->key)) as $r) {
			harness_register_row('inc_incident_records', 'inc_incident_record_id', $r->key);
		}
	}
}

harness_finish();
