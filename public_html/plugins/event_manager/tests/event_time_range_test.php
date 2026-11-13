<?php
/** @joinery-test
 * name: event_time_range
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * An event or session that ends before it starts.
 *
 * The model's rule refuses one (prepare(), which the edit forms run and every
 * save runs), for events and for sessions alike. A row saved before
 * that rule existed can still be on a site: its page shows no add-to-calendar
 * links rather than failing (the calendar library refuses a backwards range,
 * which once ended the event page with a fatal error).
 *
 * Run: php tests/run.php db --filter=event_time_range
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));
require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_sessions_class.php'));

$db = DbConnector::get_instance()->get_db_link();

$event = new Event(NULL);
$event->set('evt_name', 'HarnessTest time range ' . bin2hex(random_bytes(3)));
$event->set('evt_timezone', 'America/New_York');
$event->set('evt_start_time_local', '2030-03-04 18:00:00');
$event->set('evt_end_time_local', '2030-03-04 20:00:00');
$event->set('evt_status', Event::STATUS_ACTIVE);
$event->set('evt_visibility', Event::VISIBILITY_PUBLIC);
$event->set('evt_show_add_to_calendar_link', true);
$event->set('evt_link', $event->create_url($event->get('evt_name')));
$event->save();
$event->load();
harness_register_row('evt_events', 'evt_event_id', $event->key);

section('the model rule refuses a backwards range');
$refused = function (callable $fn) {
	try { $fn(); return false; } catch (\Throwable $e) { return $e->getMessage(); }
};
$msg = $refused(function () use ($event) {
	$e = new Event($event->key, TRUE);
	$e->set('evt_end_time_local', '2030-03-04 17:00:00');
	$e->prepare();
});
check($msg !== false && strpos($msg, 'ends before it starts') !== false, 'an event that ends before it starts is refused', (string)$msg);

$msg = $refused(function () use ($event) {
	$s = new EventSession(NULL);
	$s->set('evs_evt_event_id', $event->key);
	$s->set('evs_session_number', 1);
	$s->set('evs_title', 'Backwards');
	$s->set('evs_start_time_local', '2030-03-04 18:00:00');
	$s->set('evs_end_time_local', '2030-03-04 17:30:00');
	$s->prepare();
});
check($msg !== false && strpos($msg, 'session ends before it starts') !== false, 'a session that ends before it starts is refused', (string)$msg);

$s = new EventSession(NULL);
$s->set('evs_evt_event_id', $event->key);
$s->set('evs_session_number', 2);
$s->set('evs_title', 'Forwards');
$s->set('evs_start_time_local', '2030-03-04 18:00:00');
$s->set('evs_end_time_local', '2030-03-04 19:00:00');
$s->save();
$s->load();
harness_register_row('evs_event_sessions', 'evs_event_session_id', $s->key);
check((bool)$s->key, 'a session that ends after it starts saves');
check($refused(function () use ($s) { $s->prepare(); }) === false, 'and passes the rule');

section('a row saved before the rule offers no calendar links');
$links = (new Event($event->key, TRUE))->get_add_to_calendar_links();
check(!empty($links['google']), 'a well-formed event offers calendar links');

$db->prepare("UPDATE evt_events SET evt_end_time = evt_start_time - interval '1 hour' WHERE evt_event_id = ?")->execute(array($event->key));
$threw = null;
try { $links = (new Event($event->key, TRUE))->get_add_to_calendar_links(); } catch (\Throwable $e) { $threw = $e; }
check($threw === null, 'a backwards event does not fail its page', $threw ? $threw->getMessage() : '');
check($threw === null && $links === array(), 'and offers no calendar links');

$links = (new EventSession($s->key, TRUE))->get_add_to_calendar_links();
check(!empty($links['google']) && strpos($links['google'], 'dates=20300304T230000Z/20300305T000000Z') !== false,
	'a well-formed session\'s calendar entry ends at its end time, not its start', $links['google'] ?? '');

// Sessions read their local times, the source of truth.
$db->prepare("UPDATE evs_event_sessions SET evs_end_time_local = evs_start_time_local - interval '1 hour' WHERE evs_event_session_id = ?")->execute(array($s->key));
$threw = null;
try { $links = (new EventSession($s->key, TRUE))->get_add_to_calendar_links(); } catch (\Throwable $e) { $threw = $e; }
check($threw === null, 'a backwards session does not fail', $threw ? $threw->getMessage() : '');
check($threw === null && $links === array(), 'and offers no calendar links');

harness_finish();
