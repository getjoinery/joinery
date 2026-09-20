<?php
/** @joinery-test
 * name: entry_timezone
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * An entry written in another zone is stored in UTC correctly, keeps its
 * wall-clock and zone, comes back on the feed at the right instant, and
 * pre-fills its editor in its own zone. Drives the real web save path
 * (calendar_logic, the POST the full form makes) and the API action
 * (calendar_entry_save), both ways round the DST line.
 *
 *   php tests/calendar/entry_timezone_test.php
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
require_once(PathHelper::getIncludePath('includes/calendar/item_sources/NativeCalendarItemSource.php'));
require_once(PathHelper::getIncludePath('data/entries_class.php'));
require_once(PathHelper::getIncludePath('logic/calendar_logic.php'));
require_once(PathHelper::getIncludePath('logic/calendar_entry_save_logic.php'));

// A viewer in New York.
$user = make_user('caltz');
$user->set('usr_timezone', 'America/New_York');
$user->save();
$session = SessionControl::get_instance();
$session->set_api_user($user->key);
ok('viewer session is in New York', $session->get_timezone() === 'America/New_York');
$subject = CalendarSubject::user($user->key);
$src = new NativeCalendarItemSource();

// The web form's POST. calendar_logic reads $_POST for the branch and
// the merged input for the values; it answers with a redirect on success.
function web_save(array $fields): LogicResult {
    $_SERVER['REQUEST_URI'] = '/profile/calendar';
    $_POST = array_merge(['save_entry' => '1'], $fields);
    $result = calendar_logic($_POST);
    $_POST = [];
    return $result;
}
function newest_entry($subject): ?CalendarEntry {
    $rows = new MultiCalendarEntry(['subject_type' => $subject->type, 'subject_id' => $subject->id, 'deleted' => false]);
    $last = null;
    foreach ($rows as $e) { if (!$last || $e->key > $last->key) { $last = $e; } }
    return $last;
}

section('Web form: 9-10 AM Los Angeles in July (PDT, UTC-7)');
$r = web_save(['entry_title' => 'LA standup', 'entry_date' => '2030-07-10', 'entry_start' => '09:00:00', 'entry_end' => '10:00:00', 'entry_timezone' => 'America/Los_Angeles']);
ok('save answered with a redirect (no form error)', (bool)$r->redirect);
$la = newest_entry($subject);
harness_register_row('cal_entries', 'cal_entry_id', (int)$la->key);
ok('stored UTC start is 16:00', $la->get('cal_start_utc') === '2030-07-10 16:00:00', $la->get('cal_start_utc'));
ok('stored UTC end is 17:00',   $la->get('cal_end_utc')   === '2030-07-10 17:00:00');
ok('wall-clock kept as 09:00 local', $la->get('cal_start_local') === '2030-07-10 09:00:00');
ok('zone stored', $la->get('cal_timezone') === 'America/Los_Angeles');

section('Feed + display in the viewer\'s zone (New York, EDT)');
$item = null;
foreach ($src->getItems($subject, '2030-07-09 00:00:00', '2030-07-12 00:00:00', CalendarItem::VIS_DETAILS) as $i) {
    if ($i->entry_id === (int)$la->key) { $item = $i; }
}
ok('feed item is the UTC instant with the entry zone', $item && $item->start_utc === '2030-07-10 16:00:00' && $item->timezone === 'America/Los_Angeles');
ok('viewer sees it at 12:00 PM New York', LibraryFunctions::convert_time($item->start_utc, 'UTC', $session->get_timezone(), 'H:i') === '12:00');
// The editor pre-fills in the entry's own zone (what the view does).
ok('editor pre-fills 09:00 in the entry zone', LibraryFunctions::convert_time($la->get('cal_start_utc'), 'UTC', $la->get('cal_timezone'), 'Y-m-d H:i:s') === '2030-07-10 09:00:00');

section('Web form: same wall-clock in January (PST, UTC-8) — DST line');
$r = web_save(['entry_title' => 'LA standup winter', 'entry_date' => '2030-01-10', 'entry_start' => '09:00:00', 'entry_end' => '10:00:00', 'entry_timezone' => 'America/Los_Angeles']);
ok('save answered with a redirect', (bool)$r->redirect);
$w = newest_entry($subject);
harness_register_row('cal_entries', 'cal_entry_id', (int)$w->key);
ok('stored UTC start is 17:00 in winter', $w->get('cal_start_utc') === '2030-01-10 17:00:00', $w->get('cal_start_utc'));
ok('viewer sees it at 12:00 PM New York (both zones shifted)', LibraryFunctions::convert_time($w->get('cal_start_utc'), 'UTC', 'America/New_York', 'H:i') === '12:00');

section('Web form: no zone sent = the viewer\'s zone');
$r = web_save(['entry_title' => 'Local', 'entry_date' => '2030-07-10', 'entry_start' => '09:00:00', 'entry_end' => '10:00:00']);
$loc = newest_entry($subject);
harness_register_row('cal_entries', 'cal_entry_id', (int)$loc->key);
ok('stored in New York: 09:00 EDT = 13:00 UTC', $loc->get('cal_start_utc') === '2030-07-10 13:00:00' && $loc->get('cal_timezone') === 'America/New_York');

section('Web form: editing a Los Angeles entry keeps its zone and re-converts');
$r = web_save(['entry_id' => (string)$la->key, 'entry_title' => 'LA standup', 'entry_date' => '2030-07-10', 'entry_start' => '10:00:00', 'entry_end' => '11:00:00', 'entry_timezone' => 'America/Los_Angeles']);
$la2 = new CalendarEntry($la->key, true);
ok('moved to 10 AM LA = 17:00 UTC', $la2->get('cal_start_utc') === '2030-07-10 17:00:00' && $la2->get('cal_timezone') === 'America/Los_Angeles');

section('Web form: an unknown zone is refused, nothing written');
$before = newest_entry($subject)->key;
$r = web_save(['entry_title' => 'Nowhere', 'entry_date' => '2030-07-10', 'entry_start' => '09:00:00', 'entry_end' => '10:00:00', 'entry_timezone' => 'Mars/Olympus']);
ok('answered with the form (errors), not a redirect', !(bool)$r->redirect && !empty($r->data['errors']));
ok('no row created', newest_entry($subject)->key === $before);

section('API action: timezone parameter converts the same way');
$r = calendar_entry_save_logic(['date' => '2030-07-10', 'title' => 'Tokyo call', 'start_time' => '09:00', 'end_time' => '10:00', 'timezone' => 'Asia/Tokyo']);
ok('API save succeeded', !(bool)$r->error, (bool)$r->error ? $r->error : '');
$tk = newest_entry($subject);
harness_register_row('cal_entries', 'cal_entry_id', (int)$tk->key);
ok('9 AM Tokyo = 00:00 UTC', $tk->get('cal_start_utc') === '2030-07-10 00:00:00' && $tk->get('cal_timezone') === 'Asia/Tokyo', $tk->get('cal_start_utc'));
ok('viewer sees it at 8:00 PM the previous day, New York', LibraryFunctions::convert_time($tk->get('cal_start_utc'), 'UTC', 'America/New_York', 'Y-m-d H:i') === '2030-07-09 20:00');

harness_finish();
