<?php
/** @joinery-test
 * name: calendar_core
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Phase 1 (Personal calendar core) checkpoint tests.
 *
 *   php tests/calendar/calendar_core_test.php
 *
 * Covers: CalendarItem construction + visibility stripping, the busy-projection
 * overlap merge, CalendarItemSource registry discovery, and CalendarSubject
 * resolution of a user to name/timezone.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarItem.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarItemSourceRegistry.php'));

section('1.1 CalendarItem value object');
$item = new CalendarItem([
    'start_utc' => '2026-07-01 14:00:00',
    'end_utc'   => '2026-07-01 15:00:00',
    'type'      => CalendarItem::TYPE_EVENT,
    'title'     => 'Dentist',
    'url'       => '/event/dentist',
    'source'    => 'events',
    'source_key'=> 'events:evt-9',
]);
ok('item carries title at details level', $item->title === 'Dentist');
ok('event type maps to a colour', $item->getColor() === CalendarItem::TYPE_COLORS[CalendarItem::TYPE_EVENT]);
$arr = $item->toArray();
ok('toArray exposes start/end/color/source_key', $arr['start'] === '2026-07-01 14:00:00' && $arr['source_key'] === 'events:evt-9');

section('1.2 / projection boundary: visibility stripping');
$busy = $item->atVisibility(CalendarItem::VIS_BUSY);
ok('busy copy drops the title', $busy->title === null);
ok('busy copy drops the url', $busy->url === null);
ok('original item is untouched (no leak by mutation)', $item->title === 'Dentist');

section('1.3 busy projection overlap merge');
$merged = CalendarItemSourceRegistry::mergeBlocks([
    ['start' => '2026-07-01 10:00:00', 'end' => '2026-07-01 11:00:00'],
    ['start' => '2026-07-01 10:30:00', 'end' => '2026-07-01 12:00:00'],   // overlaps prev
    ['start' => '2026-07-01 14:00:00', 'end' => '2026-07-01 15:00:00'],   // separate
    ['start' => '2026-07-01 09:00:00', 'end' => '2026-07-01 09:30:00'],   // earlier, out of order
]);
ok('overlapping ranges merge to one', count($merged) === 3);
ok('merge keeps sorted order', $merged[0]['start'] === '2026-07-01 09:00:00');
ok('merged range spans both inputs', $merged[1]['start'] === '2026-07-01 10:00:00' && $merged[1]['end'] === '2026-07-01 12:00:00');
ok('zero-length / invalid ranges dropped', count(CalendarItemSourceRegistry::mergeBlocks([['start'=>'x','end'=>'x']])) === 0);

section('1.2 registry discovery');
$sources = CalendarItemSourceRegistry::getSources();
// EventItemSource belongs to the event_manager plugin, so it is only discovered
// when that plugin is active. With it inactive the calendar simply has no event source.
$event_manager_active = class_exists('PluginHelper') && PluginHelper::isPluginActive('event_manager');
if ($event_manager_active) {
    ok('EventItemSource discovered under key "events"', isset($sources['events']));
    ok('discovered entries implement the contract', isset($sources['events']) && $sources['events'] instanceof CalendarItemSource);
} else {
    ok('EventItemSource absent when event_manager inactive', !isset($sources['events']));
}

section('1.1 CalendarSubject resolution');
$dblink = DbConnector::get_instance()->get_db_link();
$row = $dblink->query("SELECT usr_user_id FROM usr_users WHERE usr_delete_time IS NULL ORDER BY usr_user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($row) {
    $subject = CalendarSubject::user($row['usr_user_id']);
    ok('subject key formats as user:id', $subject->getKey() === 'user:' . (int)$row['usr_user_id']);
    ok('resolves a non-empty display name', strlen($subject->getDisplayName()) > 0 && $subject->getDisplayName() !== 'Unknown');
    ok('resolves a timezone', strlen($subject->getTimezone()) > 0);
    ok('getUserId returns the id for a user subject', $subject->getUserId() === (int)$row['usr_user_id']);
    $items = CalendarItemSourceRegistry::getItems($subject, '2020-01-01 00:00:00', '2030-01-01 00:00:00', CalendarItem::VIS_BUSY);
    ok('aggregated busy items never carry a title', array_reduce($items, function($c,$i){ return $c && $i->title === null; }, true));
} else {
    harness_skip('no users in DB to resolve');
}

harness_finish();
