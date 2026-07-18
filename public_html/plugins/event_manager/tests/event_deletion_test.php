<?php
/** @joinery-test
 * name: event_deletion
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Event deletion cascade — what goes with an event when it is permanently
 * deleted.
 *
 * Event carries no permanent_delete() override. Registrants, sessions, and
 * waiting-list entries are removed by the deletion rules registered for
 * evt_events, and nothing in the Event class says so — which makes the
 * behaviour invisible at the call site and easy to "restore" by hand. A
 * hand-written override lived here for years doing exactly that; it was
 * removed because it duplicated the cascade and, for two of those years, was
 * pointed at the wrong table.
 *
 * So this suite asserts the cascade from the outside: the rules exist, and a
 * real delete really takes the children with it. If someone reintroduces an
 * override, or a rule is dropped, the failure lands here rather than as orphan
 * rows nobody notices until a report joins against a missing event.
 *
 * Sections: the declared rules; the executed cascade; and blast radius — an
 * unrelated event's rows must survive.
 *
 * Run: php plugins/event_manager/tests/event_deletion_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));
require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_registrants_class.php'));
require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_waiting_lists_class.php'));

$db = DbConnector::get_instance()->get_db_link();

/** An event, registered for teardown in case the delete under test never runs. */
function ed_make_event($name) {
	$event = new Event(NULL);
	$event->set('evt_name', 'HarnessTest ' . $name . ' ' . bin2hex(random_bytes(3)));
	$event->set('evt_start_time', gmdate('Y-m-d H:i:s', time() + 86400));
	$event->set('evt_end_time', gmdate('Y-m-d H:i:s', time() + 90000));
	$event->set('evt_status', Event::STATUS_ACTIVE);
	$event->save();
	$event->load();
	harness_register_row('evt_events', 'evt_event_id', $event->key);
	return $event;
}

function ed_seat($event, $user) {
	$reg = new EventRegistrant(NULL);
	$reg->set('evr_evt_event_id', $event->key);
	$reg->set('evr_usr_user_id', $user->key);
	$reg->save();
	$reg->load();
	harness_register_row('evr_event_registrants', 'evr_event_registrant_id', $reg->key);
	return $reg;
}

function ed_waitlist($event, $user) {
	$wl = new WaitingList(NULL);
	$wl->set('ewl_evt_event_id', $event->key);
	$wl->set('ewl_usr_user_id', $user->key);
	$wl->save();
	$wl->load();
	harness_register_row('ewl_waiting_lists', 'ewl_waiting_list_id', $wl->key);
	return $wl;
}

/** Count rows in $table pointing at $event_id through $column. */
function ed_count($table, $column, $event_id) {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare("SELECT COUNT(*) FROM $table WHERE $column = ?");
	$q->execute(array($event_id));
	return (int)$q->fetchColumn();
}

// ---------------------------------------------------------------------------
section('The declared rules');

$q = $db->prepare("SELECT del_target_table, del_target_column, del_action
	FROM del_deletion_rules WHERE del_source_table = 'evt_events'");
$q->execute();
$rules = array();
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
	$rules[$row['del_target_table']] = $row;
}

foreach (array(
	'evr_event_registrants' => 'evr_evt_event_id',
	'evs_event_sessions'    => 'evs_evt_event_id',
	'ewl_waiting_lists'     => 'ewl_evt_event_id',
) as $table => $column) {
	check(isset($rules[$table]), "a deletion rule exists for $table",
		'rules found: ' . implode(', ', array_keys($rules)));
	if (isset($rules[$table])) {
		check($rules[$table]['del_target_column'] === $column,
			"the $table rule points at $column",
			'actual: ' . $rules[$table]['del_target_column']);
		check($rules[$table]['del_action'] === 'cascade',
			"the $table rule cascades rather than orphaning or blocking",
			'action: ' . $rules[$table]['del_action']);
	}
}

// The cascade is the whole mechanism, so an override on Event would silently
// take over from it. There should not be one.
check(!method_exists('Event', 'permanent_delete')
	|| (new ReflectionMethod('Event', 'permanent_delete'))->getDeclaringClass()->getName() !== 'Event',
	'Event does not override permanent_delete — the deletion rules own the cascade');

// ---------------------------------------------------------------------------
section('The executed cascade');

$doomed = ed_make_event('Doomed');
$doomed_id = $doomed->key;
$u1 = make_user('EvDelU1');
$u2 = make_user('EvDelU2');

ed_seat($doomed, $u1);
ed_seat($doomed, $u2);
ed_waitlist($doomed, $u1);

check(ed_count('evr_event_registrants', 'evr_evt_event_id', $doomed_id) === 2,
	'the event starts with two registrants');
check(ed_count('ewl_waiting_lists', 'ewl_evt_event_id', $doomed_id) === 1,
	'the event starts with one waiting-list entry');

// A bystander that must be untouched.
$survivor = ed_make_event('Survivor');
$survivor_id = $survivor->key;
ed_seat($survivor, $u1);
ed_waitlist($survivor, $u2);

$threw = null;
try {
	$doomed->permanent_delete();
} catch (\Throwable $e) {
	$threw = get_class($e) . ': ' . $e->getMessage();
}
if ($db->inTransaction()) { $db->rollBack(); }

check($threw === null, 'deleting an event with registrants and a waiting list does not throw',
	'threw: ' . var_export($threw, true));

$q = $db->prepare("SELECT COUNT(*) FROM evt_events WHERE evt_event_id = ?");
$q->execute(array($doomed_id));
check((int)$q->fetchColumn() === 0, 'the event row is gone');

check(ed_count('evr_event_registrants', 'evr_evt_event_id', $doomed_id) === 0,
	'its registrants went with it — no rows pointing at a missing event',
	'orphans: ' . ed_count('evr_event_registrants', 'evr_evt_event_id', $doomed_id));
check(ed_count('ewl_waiting_lists', 'ewl_evt_event_id', $doomed_id) === 0,
	'its waiting-list entries went with it',
	'orphans: ' . ed_count('ewl_waiting_lists', 'ewl_evt_event_id', $doomed_id));

// ---------------------------------------------------------------------------
section('Blast radius');

check(ed_count('evr_event_registrants', 'evr_evt_event_id', $survivor_id) === 1,
	'the unrelated event keeps its registrant');
check(ed_count('ewl_waiting_lists', 'ewl_evt_event_id', $survivor_id) === 1,
	'the unrelated event keeps its waiting-list entry');

$q = $db->prepare("SELECT COUNT(*) FROM evt_events WHERE evt_event_id = ?");
$q->execute(array($survivor_id));
check((int)$q->fetchColumn() === 1, 'the unrelated event still exists');

// The users are people, not event data: deleting an event must not delete them.
$q = $db->prepare("SELECT COUNT(*) FROM usr_users WHERE usr_user_id IN (?, ?)");
$q->execute(array($u1->key, $u2->key));
check((int)$q->fetchColumn() === 2,
	'deleting an event does not delete the people who registered for it');

harness_finish();
