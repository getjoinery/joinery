<?php
/** @joinery-test
 * name: incident_triage
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Incident triage (incident_triage.md WP1).
 *
 * What is worth testing:
 *   - an agent case arrives as an incident: a plain title of the plane's own
 *     words, a severity, triage new, and a timeline in the node's own times;
 *     its close is a cleared event, and a case first heard closed still
 *     opened and still needs a look;
 *   - triage is a person's and the condition is the source's: every allowed
 *     change records one event with who made it, the same change twice
 *     records nothing, and no triage touches inc_status;
 *   - a snooze needs an offered length, and one that has ended reads as new
 *     and sits in Needs you, not Snoozed;
 *   - what is refused: a "do" value nobody offered, an empty note, an id that
 *     is no incident, and the API action below the superadmin floor;
 *   - Resolve all cleared resolves only what cleared and still needs you;
 *   - a note given with a triage (what fixed it) lands on each incident the
 *     triage reached, after its triage event, and a blank one adds nothing;
 *   - the header line's count and colour for each mix, and the menu count's
 *     registry (zeros and a failing counter show nothing);
 *   - the carry-over maps read and unread cases exactly.
 *
 * Throwaway node, user and incident rows are removed in cleanup (incidents
 * and their events cascade from the node).
 *
 * Run: php plugins/server_manager/tests/incident_triage_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

if (session_status() === PHP_SESSION_NONE) {
	@session_start();
}

$node = new ManagedNode(NULL);
$node->set('mgn_name', 'Incident triage test');
$node->set('mgn_slug', 'harnesstest-inc-' . bin2hex(random_bytes(3)));
$node->set('mgn_host', '192.0.2.42');
$node->set('mgn_ssh_user', 'root');
$node->set('mgn_agent_public_key', base64_encode(str_repeat("\x03", 32)));
$node->set('mgn_agent_version', '1.28.0');
$node->set('mgn_agent_primitives', 'check_status,host_converge,host_report');
$node->set('mgn_agent_recipes', 'fail2ban:report-only,disk_headroom:armed,service_health:armed');
$node->save();
$node->load();
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node->key);
$node_id = (int)$node->key;

$admin = make_user('inc_triage_' . bin2hex(random_bytes(3)), 10);
$uid = (int)$admin->key;

function inc_case(array $over = []): array {
	return array_merge([
		'id' => 1, 'source' => 'recipe:fail2ban', 'recipe' => 'fail2ban', 'status' => 'open',
		'opened' => '2026-09-14T12:10:00Z', 'reason' => '3 attempts since the check last passed and it still fails (fail2ban is inactive)',
		'notes' => 0, 'body' => null,
	], $over);
}

function inc_for(int $node_id, string $source): ?IncidentRecord {
	foreach (new MultiIncidentRecord(['node_id' => $node_id, 'source' => $source], ['inc_incident_record_id' => 'DESC'], 1) as $r) {
		harness_register_row('inc_incident_records', 'inc_incident_record_id', $r->key);
		return $r;
	}
	return null;
}

function kinds(int $id): array {
	return array_map(function ($e) { return (string)$e->get('ine_kind'); }, IncidentEvent::for_incident($id));
}

// ---------------------------------------------------------------------------
section('An agent case arrives as an incident');

AgentChannelEndpoint::intake_cases($node, ['recipe:fail2ban' => inc_case()]);
$f2b = inc_for($node_id, 'recipe:fail2ban');
check($f2b !== null, 'Setup: the case is stored');
if ($f2b !== null) {
	check($f2b->title() === 'fail2ban is not running' && (string)$f2b->get('inc_title') === 'fail2ban is not running',
		'It carries the plane\'s plain title, stored', (string)$f2b->get('inc_title'));
	check((string)$f2b->get('inc_severity') === IncidentRecord::SEVERITY_WARNING && $f2b->triage() === IncidentRecord::TRIAGE_NEW
		&& $f2b->needs_you(), 'An agent case is a warning, starts new, and needs you');
	$events = IncidentEvent::for_incident((int)$f2b->key);
	check(kinds((int)$f2b->key) === ['opened'] && (string)$events[0]->get('ine_time') === '2026-09-14 12:10:00'
		&& strpos((string)$events[0]->get('ine_text'), 'fail2ban is inactive') !== false,
		'Its timeline opens at the node\'s own time with the node\'s reason', json_encode(kinds((int)$f2b->key)));

	AgentChannelEndpoint::intake_cases($node, ['recipe:fail2ban' => inc_case(['notes' => 3, 'last_note' => 'still failing', 'last_note_time' => '2026-09-14T12:40:00Z'])]);
	check(kinds((int)$f2b->key) === ['opened'], 'A failing tick folds into the incident and adds no event');

	AgentChannelEndpoint::intake_cases($node, ['recipe:fail2ban' => inc_case(['status' => 'closed', 'closed' => '2026-09-14T13:00:00Z',
		'close_reason' => 'the check passes: fail2ban is active'])]);
	$f2b->load();
	check(kinds((int)$f2b->key) === ['opened', 'cleared'] && !$f2b->is_open() && $f2b->needs_you(),
		'The node\'s close is a cleared event; cleared while new still needs a look', json_encode(kinds((int)$f2b->key)));
}

AgentChannelEndpoint::intake_cases($node, ['recipe:disk_headroom' => inc_case(['id' => 7, 'source' => 'recipe:disk_headroom', 'recipe' => 'disk_headroom',
	'status' => 'closed', 'closed' => '2026-09-14T12:20:00Z', 'close_reason' => 'the check passes'])]);
$disk = inc_for($node_id, 'recipe:disk_headroom');
check($disk !== null && kinds((int)$disk->key) === ['opened', 'cleared'] && $disk->triage() === IncidentRecord::TRIAGE_NEW,
	'A case first heard already closed still opened, cleared, and is new', $disk ? json_encode(kinds((int)$disk->key)) : 'missing');

// ---------------------------------------------------------------------------
section('Triage is a person\'s, and every change is one event');

AgentChannelEndpoint::intake_cases($node, ['recipe:service_health' => inc_case(['id' => 3, 'source' => 'recipe:service_health', 'recipe' => 'service_health'])]);
$svc = inc_for($node_id, 'recipe:service_health');
check($svc !== null && $svc->is_open(), 'Setup: an open incident');
if ($svc !== null) {
	$id = (int)$svc->key;
	check(IncidentTriage::set($svc, IncidentRecord::TRIAGE_LOOKING, $uid) === true, 'Setting Looking changes it');
	$svc->load();
	$ev = IncidentEvent::for_incident($id);
	$last = end($ev);
	check($svc->triage() === IncidentRecord::TRIAGE_LOOKING && (int)$svc->get('inc_triage_usr_user_id') === $uid
		&& (string)$last->get('ine_kind') === 'triage' && (int)$last->get('ine_usr_user_id') === $uid
		&& $last->data() == ['from' => 'new', 'to' => 'looking'],
		'It records who, and one triage event from new to looking', json_encode($last->data()));
	$before = count(IncidentEvent::for_incident($id));
	check(IncidentTriage::set($svc, IncidentRecord::TRIAGE_LOOKING, $uid) === false && count(IncidentEvent::for_incident($id)) === $before,
		'Setting the same triage again records nothing');
	foreach ([IncidentRecord::TRIAGE_RESOLVED, IncidentRecord::TRIAGE_IGNORED, IncidentRecord::TRIAGE_NEW, IncidentRecord::TRIAGE_LOOKING] as $state) {
		IncidentTriage::set($svc, $state, $uid);
		$svc->load();
		check($svc->triage() === $state && $svc->is_open(), 'To ' . $state . ': the triage changes and the condition stays the source\'s');
	}
	check(!in_array('cleared', kinds($id), true), 'No triage ever wrote a cleared event');

	// A snooze.
	$refused = '';
	try { IncidentTriage::set($svc, IncidentRecord::TRIAGE_SNOOZED, $uid, 5); } catch (IncidentTriageException $e) { $refused = $e->getMessage(); }
	check($refused !== '', 'A snooze of a length nobody offered is refused', $refused);
	IncidentTriage::set($svc, IncidentRecord::TRIAGE_SNOOZED, $uid, 24);
	$svc->load();
	$until = strtotime((string)$svc->get('inc_snooze_until') . ' UTC');
	check($svc->triage() === IncidentRecord::TRIAGE_SNOOZED && !$svc->needs_you() && abs($until - (time() + 86400)) < 120,
		'Snoozed for a day: it does not need you until then');
	$in = function (string $view) use ($node_id, $id): bool {
		foreach (new MultiIncidentRecord(['node_id' => $node_id, 'view' => $view]) as $r) {
			if ((int)$r->key === $id) { return true; }
		}
		return false;
	};
	check($in('snoozed') && !$in('needs_you') && !$in('new'), 'A running snooze is in Snoozed only');
	// Its time comes.
	$svc->set('inc_snooze_until', gmdate('Y-m-d H:i:s', time() - 60));
	$svc->save();
	$svc->load();
	check($svc->triage() === IncidentRecord::TRIAGE_NEW && $svc->needs_you() && $in('needs_you') && $in('new') && !$in('snoozed'),
		'An ended snooze reads as new, in Needs you and New, not Snoozed');
	$before = count(IncidentEvent::for_incident($id));
	check(IncidentTriage::set($svc, IncidentRecord::TRIAGE_NEW, $uid) === false && count(IncidentEvent::for_incident($id)) === $before,
		'Marking an ended snooze new changes nothing: it already reads that way');
}

// ---------------------------------------------------------------------------
section('What is refused');

$bad = '';
try { IncidentTriage::parse_do('snoozed'); } catch (IncidentTriageException $e) { $bad = 'refused'; }
check($bad === 'refused', 'A bare snoozed (no length) is not a "do" value');
$bad = '';
try { IncidentTriage::parse_do('delete'); } catch (IncidentTriageException $e) { $bad = 'refused'; }
check($bad === 'refused', 'An unknown "do" value is refused');
check(IncidentTriage::parse_do('snooze_168') === [IncidentRecord::TRIAGE_SNOOZED, 168] && IncidentTriage::parse_do('resolved') === [IncidentRecord::TRIAGE_RESOLVED, 0],
	'The offered values parse');
$r = IncidentTriage::apply([0, -3, 999999999], 'resolved', $uid);
check($r === ['changed' => 0, 'missing' => 3], 'Ids that are no incident are counted, never created', json_encode($r));
check(IncidentTriage::load(999999999) === null, 'Loading a missing incident is null');
if ($svc !== null) {
	$bad = '';
	try { IncidentTriage::note($svc, "  \n ", $uid); } catch (IncidentTriageException $e) { $bad = 'refused'; }
	check($bad === 'refused', 'An empty note is refused');
	IncidentTriage::note($svc, str_repeat('n', IncidentTriage::NOTE_MAX + 50), $uid);
	$ev = IncidentEvent::for_incident((int)$svc->key);
	$last = end($ev);
	check((string)$last->get('ine_kind') === 'note' && mb_strlen((string)$last->get('ine_text')) === IncidentTriage::NOTE_MAX,
		'A long note is capped');
}

// The API action, with no superadmin signed in (the CLI has no session user).
require_once(PathHelper::getIncludePath('plugins/server_manager/logic/incident_triage_logic.php'));
$_SERVER['REQUEST_METHOD'] = 'POST';
$res = incident_triage_logic(['id' => $svc ? (int)$svc->key : 0, 'do' => 'resolved']);
unset($_SERVER['REQUEST_METHOD']);
if ($svc !== null) { $svc->load(); }
check((string)$res->error !== '' && ($svc === null || $svc->triage() !== IncidentRecord::TRIAGE_RESOLVED),
	'The API action refuses a caller who is not a superadmin and changes nothing', (string)$res->error);
$res = incident_triage_logic(['id' => $svc ? (int)$svc->key : 0, 'do' => 'resolved']);
check((string)$res->error !== '', 'The API action refuses a GET', (string)$res->error);

// ---------------------------------------------------------------------------
section('Resolve all cleared');

if ($f2b !== null && $disk !== null && $svc !== null) {
	IncidentTriage::set($svc, IncidentRecord::TRIAGE_NEW, $uid);
	$svc_events = count(IncidentEvent::for_incident((int)$svc->key));
	IncidentTriage::resolve_all_cleared($uid, ['node_id' => $node_id], 'Fixed by the cleared fix');
	$f2b->load(); $disk->load(); $svc->load();
	check($f2b->triage() === IncidentRecord::TRIAGE_RESOLVED && $disk->triage() === IncidentRecord::TRIAGE_RESOLVED,
		'Both cleared incidents that needed a look are resolved');
	check($svc->triage() === IncidentRecord::TRIAGE_NEW, 'An incident still happening is left as it was');
	$ev = IncidentEvent::for_incident((int)$f2b->key);
	$last = end($ev);
	check((string)$last->get('ine_kind') === 'note' && (string)$last->get('ine_text') === 'Fixed by the cleared fix',
		'Resolve all cleared puts its note on each one it resolved');
	check(count(IncidentEvent::for_incident((int)$svc->key)) === $svc_events, 'and nothing on the one it left alone');
}

// ---------------------------------------------------------------------------
section('A note with a triage');

if ($svc !== null && $disk !== null) {
	$before = count(IncidentEvent::for_incident((int)$svc->key));
	IncidentTriage::apply([(int)$svc->key, (int)$disk->key], 'resolved', $uid, "  Fixed in 0.8.456  ");
	$ev = array_slice(IncidentEvent::for_incident((int)$svc->key), $before);
	check(count($ev) === 2 && (string)$ev[0]->get('ine_kind') === 'triage' && (string)$ev[1]->get('ine_kind') === 'note'
		&& (string)$ev[1]->get('ine_text') === 'Fixed in 0.8.456',
		'Resolving with a note records the triage, then the note, trimmed');
	$ev = IncidentEvent::for_incident((int)$disk->key);
	$last = end($ev);
	check((string)$last->get('ine_text') === 'Fixed in 0.8.456',
		'An incident already resolved in the selection still gets the note');
	$before = count(IncidentEvent::for_incident((int)$svc->key));
	IncidentTriage::apply([(int)$svc->key], 'new', $uid, " \n ");
	check(count(IncidentEvent::for_incident((int)$svc->key)) === $before + 1, 'A blank note adds nothing beyond the triage');
	IncidentTriage::set($svc, IncidentRecord::TRIAGE_NEW, $uid);
}

// ---------------------------------------------------------------------------
section('The header line and the node\'s count');

$c = IncidentRecord::needs_you_counts($node_id);
check($c === ['needs_you' => 1, 'active' => 1, 'critical' => 0], 'The node: one needs you, still happening, none critical', json_encode($c));
if ($svc !== null) {
	$svc->set('inc_severity', IncidentRecord::SEVERITY_CRITICAL);
	$svc->save();
	$c = IncidentRecord::needs_you_counts($node_id);
	check($c['critical'] === 1, 'A critical one still happening is counted as critical');
}
check(strpos(IncidentNotice::line_for(['needs_you' => 3, 'active' => 1, 'critical' => 1], 0), 'alert-danger') !== false,
	'Red when a critical incident that needs you is still happening');
check(strpos(IncidentNotice::line_for(['needs_you' => 3, 'active' => 0, 'critical' => 0], 0), 'alert-warning') !== false
	&& strpos(IncidentNotice::line_for(['needs_you' => 3, 'active' => 0, 'critical' => 0], 0), '3 incidents need you.') !== false,
	'Amber when incidents need you and none is critical; no "still happening" when all cleared');
$only_reports = IncidentNotice::line_for(['needs_you' => 0, 'active' => 0, 'critical' => 0], 2);
check(strpos($only_reports, 'alert-info') !== false && strpos($only_reports, '2 new problem reports from sites.') !== false
	&& strpos($only_reports, 'href="/plugins/bug_reports/admin/admin_bug_reports"') !== false,
	'Blue with only problem reports waiting, linked to their own page');
$one = IncidentNotice::line_for(['needs_you' => 1, 'active' => 1, 'critical' => 0], 1);
check(strpos($one, '1 incident needs you, 1 still happening. 1 new problem report from sites.') !== false, 'Singulars read right', $one);
check(IncidentNotice::line_for(['needs_you' => 0, 'active' => 0, 'critical' => 0], 0) === '', 'Absent when nothing needs you');

// ---------------------------------------------------------------------------
section('The menu count registry');

AdminMenuCounts::register('harness-zero', function () { return 0; });
AdminMenuCounts::register('harness-three', function () { return 3; });
AdminMenuCounts::register('harness-broken', function () { throw new RuntimeException('no'); });
$all = AdminMenuCounts::all();
check(($all['harness-three'] ?? null) === 3 && !isset($all['harness-zero']) && !isset($all['harness-broken']),
	'A count shows, a zero shows nothing, and a counter that throws shows nothing', json_encode($all));
check(isset($all['server-manager-incidents']) === (IncidentNotice::menu_count() > 0),
	'Server Manager registers the Incidents count from its bootstrap');

// ---------------------------------------------------------------------------
section('The carry-over maps today\'s cases exactly');

$db = DbConnector::get_instance()->get_db_link();
$q = $db->query("SELECT count(*) FROM information_schema.columns WHERE table_name = 'inc_incident_records'
	AND column_name IN ('inc_human_note', 'inc_read_time', 'inc_read_by')");
if ((int)$q->fetchColumn() !== 3) {
	harness_skip('The carry-over', 'the old case columns are not on this database');
} else {
	require_once(PathHelper::getIncludePath('migrations/incident_cases_carry_triage.php'));
	$make = function (int $case_id, string $status, ?int $read_by, ?string $note) use ($db, $node_id) {
		$q = $db->prepare("INSERT INTO inc_incident_records (inc_mgn_managed_node_id, inc_source, inc_node_case_id, inc_status,
				inc_opened_time, inc_closed_time, inc_reason, inc_human_note, inc_read_time, inc_read_by)
			VALUES (?, 'recipe:carry', ?, ?, '2026-09-01 10:00:00', ?, 'the check fails', ?, ?, ?) RETURNING inc_incident_record_id");
		$q->execute([$node_id, $case_id, $status, $status === 'closed' ? '2026-09-01 11:00:00' : null, $note,
			$read_by ? '2026-09-01 12:00:00' : null, $read_by]);
		$id = (int)$q->fetchColumn();
		harness_register_row('inc_incident_records', 'inc_incident_record_id', $id);
		return $id;
	};
	$read_closed = $make(101, 'closed', $uid, 'fixed by hand');
	$read_open   = $make(102, 'open', $uid, null);
	$unread      = $make(103, 'closed', null, null);
	ob_start();
	incident_cases_carry_triage();
	ob_end_clean();
	$load = function (int $id) { return new IncidentRecord($id, TRUE); };
	check($load($read_closed)->triage() === 'resolved' && kinds($read_closed) === ['opened', 'cleared', 'note', 'triage'],
		'Read and cleared is resolved, with its note and the read as events', json_encode(kinds($read_closed)));
	check($load($read_open)->triage() === 'looking' && kinds($read_open) === ['opened', 'triage']
		&& (int)$load($read_open)->get('inc_triage_usr_user_id') === $uid,
		'Read and still active is looking, by the person who read it', json_encode(kinds($read_open)));
	check($load($unread)->triage() === 'new' && kinds($unread) === ['opened', 'cleared'],
		'Unread is new', json_encode(kinds($unread)));
	check($load($unread)->title() === 'The agent\'s carry check keeps failing', 'A carried case gets the plane\'s title');
	ob_start();
	incident_cases_carry_triage();
	ob_end_clean();
	check(count(IncidentEvent::for_incident($read_closed)) === 4, 'Running it again changes nothing');
}

harness_finish();
