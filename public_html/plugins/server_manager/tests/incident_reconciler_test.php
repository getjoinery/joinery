<?php
/** @joinery-test
 * name: incident_reconciler
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The reconciler and the first plane source (incident_triage.md WP2).
 *
 * What is worth testing, on a throwaway node, with the signals caught so no
 * real superadmin is told anything:
 *   - plane:site_down opens one critical incident when uptime monitoring has
 *     stored 'down', with what the check saw as evidence, and tells the
 *     superadmins once: never per tick;
 *   - a changed detail refreshes the incident with no event and no signal;
 *   - the site answering again clears it, with an event, and as nobody had
 *     looked it is resolved on the same pass, saying so;
 *   - down again within the hour reopens the same incident as new and tells
 *     them again; an ignored one reopens still ignored and silent;
 *   - resolved while still happening and still happening a day later goes
 *     back to new, says so on its timeline and tells them; younger, ignored
 *     or cleared ones are left alone;
 *   - after the hour it is a new incident with the next id;
 *   - monitoring switched off clears it, saying so;
 *   - a node in an install state is not watched: nothing opens on it, and an
 *     active incident on it is cleared, saying so;
 *   - a warning source sends incident.opened, a critical one
 *     incident.opened_critical;
 *   - a source that throws leaves its incident as it was;
 *   - a second pass while one holds the lock does nothing;
 *   - a node removed from the dashboard has every active incident cleared,
 *     an agent's case too, saying so; a live node's are left alone;
 *   - a node that goes takes its incidents and their timelines with it, by
 *     its model or (at the next pass) by raw SQL, and its deletion waits for
 *     a running pass;
 *   - a full pass leaves a test's fixture nodes alone, and a harness process
 *     rings no bell unless its suite stands in; every node a test names
 *     carries the fixture prefix.
 *
 * Run: php plugins/server_manager/tests/incident_reconciler_test.php
 *
 * @version 1.5 - a full pass leaves fixture nodes alone, removed nodes kept in it; fixture node names carry the
 *                prefix, and outside a test process no node takes it
 * @version 1.4 - a cleared incident nobody had settled is resolved (settle_cleared)
 * @version 1.3 - an unproven fix goes back to new after a day (return_unproven); Looking is gone
 * @version 1.2 - a removed node's incidents, of any source, are cleared
 * @version 1.1 - a node's deletion and its incidents (site_copy.md B41)
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$node = new ManagedNode(NULL);
$node->set('mgn_name', 'HarnessTest reconciler');
$node->set('mgn_slug', 'harnesstest-rec-' . bin2hex(random_bytes(3)));
$node->set('mgn_host', '192.0.2.43');
$node->set('mgn_ssh_user', 'root');
$node->set('mgn_site_url', 'https://reconciler.example');
$node->set('mgn_enabled', true);
$node->set('mgn_uptime_enabled', true);
$node->set('mgn_uptime_last_status', 'up');
$node->save();
$node->load();
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node->key);
$node_id = (int)$node->key;

// Hold the reconciler's lock for the whole test: the live task on this box
// (another connection) then skips its passes, so it never sees this node down
// and tells real superadmins. An advisory lock is re-entrant on one
// connection, so this test's own passes still run.
$main = DbConnector::get_instance()->get_db_link();
$main->query('SELECT pg_advisory_lock(' . IncidentReconciler::LOCK_KEY . ')');

$booted_dispatch = IncidentReconciler::$dispatch;
$signals = array();
IncidentReconciler::$dispatch = function ($signal, $payload) use (&$signals) { $signals[] = array($signal, $payload); };

$site_down = new IncidentSourceSiteDown();
$sources = array($site_down->name() => $site_down);
$pass = function (?array $srcs = null) use (&$sources, $node_id) {
	return IncidentReconciler::run($srcs ?? $sources, array($node_id));
};
$set = function (array $cols) use ($node) {
	foreach ($cols as $k => $v) { $node->set($k, $v); }
	$node->save();
	$node->load();
};
$incidents = function (string $source = 'plane:site_down') use ($node_id): array {
	$out = array();
	foreach (new MultiIncidentRecord(array('node_id' => $node_id, 'source' => $source), array('inc_incident_record_id' => 'ASC')) as $r) {
		harness_register_row('inc_incident_records', 'inc_incident_record_id', $r->key);
		$out[] = $r;
	}
	return $out;
};
$kinds = function (int $id): array {
	return array_map(function ($e) { return (string)$e->get('ine_kind'); }, IncidentEvent::for_incident($id));
};

// ---------------------------------------------------------------------------
section('A site that is up opens nothing');

$c = $pass();
check($c['opened'] === 0 && count($incidents()) === 0 && count($signals) === 0, 'Up: no incident, no signal', json_encode($c));

// ---------------------------------------------------------------------------
section('Down opens one critical incident and tells the superadmins once');

$set(array('mgn_uptime_last_status' => 'down', 'mgn_uptime_down_since' => gmdate('Y-m-d H:i:s', time() - 120),
	'mgn_uptime_down_reason' => 'HTTP 502 from https://reconciler.example'));
$c = $pass();
$rows = $incidents();
check($c['opened'] === 1 && count($rows) === 1, 'One incident opened', json_encode($c));
$inc = $rows[0] ?? null;
if ($inc !== null) {
	$detail = $inc->get('inc_detail');
	if (is_string($detail)) { $detail = json_decode($detail, true); }
	check($inc->is_open() && $inc->is_critical() && $inc->title() === 'The site does not answer'
		&& $inc->triage() === IncidentRecord::TRIAGE_NEW && (int)$inc->get('inc_node_case_id') === 1,
		'Active, critical, plainly titled, new, the first of its source on the node');
	check(($detail['What the check saw'] ?? '') === 'HTTP 502 from https://reconciler.example' && ($detail['Site'] ?? '') === 'https://reconciler.example',
		'Its evidence is what the check saw and the site', json_encode($detail));
	check($kinds((int)$inc->key) === array('opened'), 'One opened event');
	check(count($signals) === 1 && $signals[0][0] === IncidentReconciler::SIGNAL_CRITICAL
		&& (int)$signals[0][1]['incident_id'] === (int)$inc->key && count($signals[0][1]['recipients']) > 0
		&& $signals[0][1]['link'] === '/admin/server_manager/incident?id=' . (int)$inc->key,
		'One incident.opened_critical, to the superadmins, linking the incident', json_encode($signals));
}
$signals = array();
$c = $pass();
check($c['opened'] === 0 && $c['refreshed'] === 0 && count($incidents()) === 1 && count($signals) === 0,
	'Still down on the next tick: nothing new, no signal', json_encode($c));

// ---------------------------------------------------------------------------
section('A changed detail refreshes; answering again clears');

$set(array('mgn_uptime_down_reason' => 'connection refused'));
$c = $pass();
if ($inc !== null) {
	$inc->load();
	$detail = $inc->get('inc_detail');
	if (is_string($detail)) { $detail = json_decode($detail, true); }
	check($c['refreshed'] === 1 && ($detail['What the check saw'] ?? '') === 'connection refused'
		&& $kinds((int)$inc->key) === array('opened') && count($signals) === 0,
		'The evidence follows the check, with no event and no signal', json_encode($c));
}
$set(array('mgn_uptime_last_status' => 'up', 'mgn_uptime_down_since' => null, 'mgn_uptime_down_reason' => null));
$c = $pass();
if ($inc !== null) {
	$inc->load();
	$ev = IncidentEvent::for_incident((int)$inc->key);
	check($c['cleared'] === 1 && !$inc->is_open() && $kinds((int)$inc->key) === array('opened', 'cleared', 'triage')
		&& (string)$ev[1]->get('ine_text') === 'The site answers again.',
		'Cleared with an event', json_encode($c));
	check($c['settled'] === 1 && $inc->triage() === IncidentRecord::TRIAGE_RESOLVED && !$inc->needs_you()
		&& (int)$inc->get('inc_triage_usr_user_id') === 0 && (string)end($ev)->get('ine_text') === IncidentReconciler::SETTLED_TEXT,
		'Cleared before anyone looked, it is resolved on the same pass, by nobody, saying so', json_encode($c));
}

// ---------------------------------------------------------------------------
section('Back within the hour reopens the same incident');

if ($inc !== null) {
	IncidentTriage::set($inc, IncidentRecord::TRIAGE_RESOLVED, (int)make_user('rec_' . bin2hex(random_bytes(3)), 10)->key);
}
$set(array('mgn_uptime_last_status' => 'down', 'mgn_uptime_down_since' => gmdate('Y-m-d H:i:s')));
$c = $pass();
$rows = $incidents();
if ($inc !== null) {
	$inc->load();
	check($c['reopened'] === 1 && count($rows) === 1 && $inc->is_open() && $inc->triage() === IncidentRecord::TRIAGE_NEW
		&& end($rows)->key == $inc->key && array_slice($kinds((int)$inc->key), -1) === array('reopened'),
		'Reopened, the same incident, new again', json_encode($c));
	check(count($signals) === 1 && $signals[0][0] === IncidentReconciler::SIGNAL_CRITICAL, 'They are told once more');
}
$signals = array();
if ($inc !== null) {
	IncidentTriage::set($inc, IncidentRecord::TRIAGE_IGNORED, (int)make_user('rec2_' . bin2hex(random_bytes(3)), 10)->key);
	$set(array('mgn_uptime_last_status' => 'up'));
	$pass();
	$set(array('mgn_uptime_last_status' => 'down'));
	$c = $pass();
	$inc->load();
	check($c['reopened'] === 1 && $inc->is_open() && $inc->triage() === IncidentRecord::TRIAGE_IGNORED && count($signals) === 0,
		'An ignored incident reopens still ignored, and nobody is told', json_encode($c));
}

// ---------------------------------------------------------------------------
section('A fix that is still happening a day later is news again');

if ($inc !== null) {
	$resolver = (int)make_user('rec3_' . bin2hex(random_bytes(3)), 10)->key;
	$resolved_ago = function (int $seconds) use ($inc) {
		$inc->load();
		$inc->set('inc_triage_time', gmdate('Y-m-d H:i:s', time() - $seconds));
		$inc->save();
	};
	IncidentTriage::set($inc, IncidentRecord::TRIAGE_RESOLVED, $resolver);
	$resolved_ago(IncidentReconciler::PROOF_WINDOW - 3600);
	$c = $pass();
	$inc->load();
	check($c['unproven'] === 0 && $inc->awaiting_proof() && count($signals) === 0,
		'Resolved 23 hours ago and still happening: still waiting', json_encode($c));

	$resolved_ago(IncidentReconciler::PROOF_WINDOW + 60);
	$c = $pass();
	$inc->load();
	$ev = IncidentEvent::for_incident((int)$inc->key);
	$last = end($ev);
	check($c['unproven'] === 1 && $inc->is_open() && $inc->triage() === IncidentRecord::TRIAGE_NEW && $inc->needs_you()
		&& (int)$inc->get('inc_triage_usr_user_id') === 0,
		'A day later it is new again, set by nobody', json_encode($c));
	check((string)$last->get('ine_kind') === 'triage' && (string)$last->get('ine_text') === IncidentReconciler::UNPROVEN_TEXT
		&& !empty($last->data()['unproven']) && ($last->data()['from'] ?? '') === 'resolved',
		'Its timeline says it was still happening a day after it was resolved', json_encode($last->data()));
	check(strpos(IncidentViews::timeline(array($last)), 'Back to New.') !== false, 'and the timeline shows it as Back to New');
	check(count($signals) === 1 && $signals[0][0] === IncidentReconciler::SIGNAL_CRITICAL
		&& strpos((string)$signals[0][1]['summary'], IncidentReconciler::UNPROVEN_TEXT) === 0,
		'They are told once, saying why', json_encode($signals[0][1]['summary'] ?? null));
	$c = $pass();
	check($c['unproven'] === 0 && count($signals) === 1, 'The next pass sends nothing more');

	IncidentTriage::set($inc, IncidentRecord::TRIAGE_IGNORED, $resolver);
	$resolved_ago(IncidentReconciler::PROOF_WINDOW + 60);
	$c = $pass();
	$inc->load();
	check($c['unproven'] === 0 && $inc->triage() === IncidentRecord::TRIAGE_IGNORED, 'Ignored stays ignored, however long it happens');

	IncidentTriage::set($inc, IncidentRecord::TRIAGE_RESOLVED, $resolver);
	$resolved_ago(IncidentReconciler::PROOF_WINDOW + 60);
	$set(array('mgn_uptime_last_status' => 'up'));
	$c = $pass();
	$inc->load();
	check($c['cleared'] === 1 && $c['unproven'] === 0 && !$inc->is_open() && $inc->triage() === IncidentRecord::TRIAGE_RESOLVED,
		'A fix whose condition cleared on the same pass is proven, never sent back', json_encode($c));
	$set(array('mgn_uptime_last_status' => 'down'));
}
$signals = array();

// ---------------------------------------------------------------------------
section('After the hour it is a new incident');

$set(array('mgn_uptime_last_status' => 'up'));
$pass();
if ($inc !== null) {
	$inc->load();
	$inc->set('inc_closed_time', gmdate('Y-m-d H:i:s', time() - IncidentReconciler::REOPEN_WINDOW - 60));
	$inc->save();
}
$set(array('mgn_uptime_last_status' => 'down'));
$c = $pass();
$rows = $incidents();
check($c['opened'] === 1 && count($rows) === 2 && (int)$rows[1]->get('inc_node_case_id') === 2 && $rows[1]->triage() === IncidentRecord::TRIAGE_NEW
	&& count($signals) === 1, 'A new incident with the next id, and a signal', json_encode($c));
$second = $rows[1] ?? null;

// ---------------------------------------------------------------------------
section('Monitoring off, and a node no longer watched');

// What the newest cleared event said (a settle line may follow it).
$cleared_text = function (array $ev): string {
	foreach (array_reverse($ev) as $e) {
		if ((string)$e->get('ine_kind') === 'cleared') { return (string)$e->get('ine_text'); }
	}
	return '';
};

$set(array('mgn_uptime_enabled' => false));
$c = $pass();
if ($second !== null) {
	$second->load();
	$ev = IncidentEvent::for_incident((int)$second->key);
	check($c['cleared'] === 1 && !$second->is_open() && strpos($cleared_text($ev), 'turned off') !== false,
		'Monitoring switched off clears it, saying so', $cleared_text($ev));
}
$set(array('mgn_uptime_enabled' => true));
$pass();
$open_now = array_values(array_filter($incidents(), function ($r) { return $r->is_open(); }));
check(count($open_now) === 1, 'Setup: an active incident again', (string)count($open_now));
$set(array('mgn_install_state' => 'copy'));
$c = $pass();
$open_now = array_values(array_filter($incidents(), function ($r) { return $r->is_open(); }));
$ev = isset($rows) && count($incidents()) ? IncidentEvent::for_incident((int)end($incidents())->key) : array();
check($c['cleared'] === 1 && count($open_now) === 0 && strpos($cleared_text($ev), 'no longer watched') !== false,
	'A node in an install state: its active incident is cleared, saying so', json_encode($c));
$before = count($incidents());
$c = $pass();
check(count($incidents()) === $before && $c['opened'] + $c['reopened'] === 0, 'Nothing opens on it while it is in that state');
$set(array('mgn_install_state' => null, 'mgn_uptime_last_status' => 'up'));
$pass();

// ---------------------------------------------------------------------------
section('A warning source, and a source that throws');

class ReconcilerTestWarning implements IncidentSource {
	public static $holds = true;
	public static $throws = false;
	public function name(): string { return 'plane:harness_warning'; }
	public function evaluate(ManagedNode $node): ?array {
		if (self::$throws) { throw new RuntimeException('cannot decide'); }
		return self::$holds ? array('title' => 'A harness warning', 'severity' => 'warning', 'detail' => array('x' => 1)) : null;
	}
	public function cleared_text(ManagedNode $node): string { return 'gone'; }
}
$warn = new ReconcilerTestWarning();
$signals = array();
$pass(array($warn->name() => $warn));
$w = $incidents('plane:harness_warning');
check(count($w) === 1 && !$w[0]->is_critical() && count($signals) === 1 && $signals[0][0] === IncidentReconciler::SIGNAL_WARNING,
	'A warning opens with incident.opened', json_encode($signals));
ReconcilerTestWarning::$throws = true;
$c = $pass(array($warn->name() => $warn));
$w = $incidents('plane:harness_warning');
check($c['cleared'] === 0 && count($w) === 1 && $w[0]->is_open(), 'A source that throws leaves its incident as it was', json_encode($c));
ReconcilerTestWarning::$throws = false;
ReconcilerTestWarning::$holds = false;
$pass(array($warn->name() => $warn));

// ---------------------------------------------------------------------------
section('One pass at a time');

$main->query('SELECT pg_advisory_unlock(' . IncidentReconciler::LOCK_KEY . ')');
$settings = Globalvars::get_instance();
$other = new PDO('pgsql:host=localhost port=5432 dbname=' . $settings->get_setting('dbname'),
	$settings->get_setting('dbusername'), $settings->get_setting('dbpassword'));
$held = (bool)$other->query('SELECT pg_try_advisory_lock(' . IncidentReconciler::LOCK_KEY . ')')->fetchColumn();
check($held, 'Setup: another connection holds the lock');
$c = $pass();
check($c['busy'] === true, 'A pass while another holds the lock does nothing', json_encode($c));
$other->query('SELECT pg_advisory_unlock(' . IncidentReconciler::LOCK_KEY . ')');
$other = null;
$main->query('SELECT pg_advisory_lock(' . IncidentReconciler::LOCK_KEY . ')');
$c = $pass();
check($c['busy'] === false, 'Once released, a pass runs');

// ---------------------------------------------------------------------------
section('A node that goes takes its incidents and their timelines with it (site_copy.md B41)');

$down_node = function (string $tag) use ($site_down) {
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'HarnessTest reconciler ' . $tag);
	$n->set('mgn_slug', 'harnesstest-rec-' . $tag . '-' . bin2hex(random_bytes(3)));
	$n->set('mgn_host', '192.0.2.44');
	$n->set('mgn_ssh_user', 'root');
	$n->set('mgn_site_url', 'https://reconciler-' . $tag . '.example');
	$n->set('mgn_enabled', true);
	$n->set('mgn_uptime_enabled', true);
	$n->set('mgn_uptime_last_status', 'down');
	$n->save();
	$n->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $n->key);
	IncidentReconciler::run(array($site_down->name() => $site_down), array((int)$n->key));
	$inc = IncidentRecord::open_for((int)$n->key, $site_down->name());
	if ($inc) {
		harness_register_row('inc_incident_records', 'inc_incident_record_id', $inc->key);
	}
	return array($n, $inc);
};
$left = function (int $inc_id) use ($main): array {
	$i = $main->prepare('SELECT count(*) FROM inc_incident_records WHERE inc_incident_record_id = ?');
	$i->execute(array($inc_id));
	$e = $main->prepare('SELECT count(*) FROM ine_incident_events WHERE ine_inc_incident_record_id = ?');
	$e->execute(array($inc_id));
	return array((int)$i->fetchColumn(), (int)$e->fetchColumn());
};

// An agent's case on a node removed from the dashboard: the agent is refused
// from then on, so only the plane can close it.
$agent_case = function (ManagedNode $n): IncidentRecord {
	$inc = new IncidentRecord(NULL);
	$inc->set('inc_mgn_managed_node_id', (int)$n->key);
	$inc->set('inc_source', 'recipe:harness_case');
	$inc->set('inc_node_case_id', 1);
	$inc->set('inc_title', 'A harness agent case');
	$inc->save();
	$inc->load();
	harness_register_row('inc_incident_records', 'inc_incident_record_id', $inc->key);
	return $inc;
};
list($removed_node, $removed_site) = $down_node('removed');
$removed_case = $agent_case($removed_node);
$live_case = $agent_case($node);
$removed_node->soft_delete();
$n = IncidentReconciler::clear_removed(array((int)$removed_node->key, $node_id));
$removed_case->load();
$live_case->load();
$ev = IncidentEvent::for_incident((int)$removed_case->key);
check($n === ($removed_site ? 2 : 1) && !$removed_case->is_open()
	&& strpos((string)$removed_case->get('inc_close_reason'), 'removed from the dashboard') !== false
	&& (string)end($ev)->get('ine_kind') === 'cleared',
	'A removed node: its agent case and its plane incident are cleared, saying so', 'cleared ' . $n);
check($live_case->is_open(), 'An agent case on a node still listed is left alone');
check(IncidentReconciler::clear_removed(array((int)$removed_node->key)) === 0, 'A second pass finds nothing more to clear');
$live_case->permanent_delete();

list($gone, $gone_inc) = $down_node('model');
check($gone_inc !== null && $left((int)$gone_inc->key) === array(1, 1), 'Setup: a down node has an incident with its opened event');
$gone->permanent_delete();
check($gone_inc !== null && $left((int)$gone_inc->key) === array(0, 0),
	'Deleting the node deletes the incident and its timeline', $gone_inc ? json_encode($left((int)$gone_inc->key)) : '');

list($raw, $raw_inc) = $down_node('raw');
$main->prepare('DELETE FROM mgn_managed_nodes WHERE mgn_managed_node_id = ?')->execute(array((int)$raw->key));
$removed = IncidentReconciler::remove_nodeless();
check($raw_inc !== null && $removed >= 1 && $left((int)$raw_inc->key) === array(0, 0),
	'A node deleted outside its model: the next pass deletes its incident and timeline', 'removed ' . $removed);

// A pass holds the lock while it lists nodes and opens incidents on them; a
// node deleted in between would get an incident with no node.
$main->query('SELECT pg_advisory_unlock(' . IncidentReconciler::LOCK_KEY . ')');
$settings = Globalvars::get_instance();
$other = new PDO('pgsql:host=localhost port=5432 dbname=' . $settings->get_setting('dbname'),
	$settings->get_setting('dbusername'), $settings->get_setting('dbpassword'));
$other->query('SELECT pg_advisory_lock(' . IncidentReconciler::LOCK_KEY . ')');
$waits = new ManagedNode(NULL);
$waits->set('mgn_name', 'HarnessTest reconciler waits');
$waits->set('mgn_slug', 'harnesstest-rec-waits-' . bin2hex(random_bytes(3)));
$waits->set('mgn_host', '192.0.2.45');
$waits->set('mgn_ssh_user', 'root');
$waits->save();
$waits->load();
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $waits->key);
$main->exec("SET lock_timeout = '300ms'");
$refused = false;
try {
	$waits->permanent_delete();
} catch (Throwable $e) {
	$refused = true;
}
$main->exec('RESET lock_timeout');
$still = new ManagedNode((int)$waits->key, TRUE);
check($refused && $still->key, 'Deleting a node waits while a pass holds the lock');
$other->query('SELECT pg_advisory_unlock(' . IncidentReconciler::LOCK_KEY . ')');
$other = null;
$waits->permanent_delete();
$q = $main->prepare('SELECT count(*) FROM mgn_managed_nodes WHERE mgn_managed_node_id = ?');
$q->execute(array((int)$waits->key));
check((int)$q->fetchColumn() === 0, 'and goes through once the pass is done');

section('A full pass leaves a test\'s fixture nodes alone');
check($booted_dispatch !== null, 'A harness process rings no superadmin\'s bell until its suite stands in');
// Disabled, so the scheduled pass opens nothing on it while it exists.
$plain = new ManagedNode(NULL);
$plain->set('mgn_name', 'Reconciler plain ' . bin2hex(random_bytes(3)));
$plain->set('mgn_slug', 'harnesstest-rec-plain-' . bin2hex(random_bytes(3)));
$plain->set('mgn_host', '192.0.2.46');
$plain->set('mgn_ssh_user', 'root');
$plain->set('mgn_enabled', false);
$plain->save();
$plain->load();
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $plain->key);
$full = IncidentReconciler::full_pass_nodes();
check(!in_array($node_id, $full, true) && in_array((int)$plain->key, $full, true),
	'A node named HarnessTest is outside a full pass; any other is in it');
$plain->soft_delete();
check(in_array((int)$plain->key, IncidentReconciler::full_pass_nodes(), true),
	'A removed node stays in a full pass, so its cleared incidents are still settled');
check(ManagedNode::is_fixture_name('HarnessTest x') && !ManagedNode::is_fixture_name('harnesstest x')
	&& !ManagedNode::is_fixture_name('Site HarnessTest'), 'A fixture name starts with the prefix exactly');

// Outside a test process no node takes the name: nothing would watch it, and
// the harness deletes such rows. A plain PHP process stands for an operator.
$probe_name = 'HarnessTest operator ' . bin2hex(random_bytes(3));
$probe = PHP_BINARY . ' -r ' . escapeshellarg('require ' . var_export(PathHelper::getIncludePath('includes/PathHelper.php'), true) . ';'
	. ' $n = new ManagedNode(NULL); $n->set("mgn_name", ' . var_export($probe_name, true) . '); $n->set("mgn_slug", "harnesstest-op-' . bin2hex(random_bytes(3)) . '");'
	. ' $n->set("mgn_host", "192.0.2.47"); $n->set("mgn_ssh_user", "root");'
	. ' try { $n->save(); echo "saved ", $n->key; } catch (Throwable $e) { echo "refused: ", $e->getMessage(); }') . ' 2>&1';
$probe_out = (string)shell_exec($probe);
$probe_rows = $main->prepare('SELECT mgn_managed_node_id FROM mgn_managed_nodes WHERE mgn_name = ?');
$probe_rows->execute(array($probe_name));
foreach ($probe_rows->fetchAll(PDO::FETCH_COLUMN) as $leaked) { harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', (int)$leaked); }
check(strpos($probe_out, "refused: A node's name may not start 'HarnessTest '") !== false,
	'Outside a test process a node is not created with a fixture name', $probe_out);

// Every node a test saves is named with the prefix, or the scheduled pass
// rings every superadmin about it and a killed run's leftover is never reclaimed.
$root = PathHelper::getIncludePath('');
$unnamed = array();
$files = array_merge(glob($root . 'tests/*/*.php'), glob($root . 'tests/*/*/*.php'), glob($root . 'plugins/*/tests/*.php'));
foreach ($files as $file) {
	foreach (file($file) as $i => $line) {
		if (preg_match("/(set\\('mgn_name',|'mgn_name' => )/", $line, $m) && strpos($line, 'HarnessTest ') === false
			&& !($file === __FILE__ && strpos($line, 'Reconciler plain') !== false)) {
			$unnamed[] = substr($file, strlen($root)) . ':' . ($i + 1);
		}
	}
}
check(count($unnamed) === 0, 'Every node a test names starts HarnessTest', implode(', ', $unnamed));

harness_finish();
