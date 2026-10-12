<?php
/** @joinery-test
 * name: calendar_core
 * tier: safe
 * parallel: true
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

section('C11 a duplicate item is shown once');
class DedupSourceA implements CalendarItemSource {
    public static function getKey(): string { return 'dedup_a'; }
    public function getItems(CalendarSubject $subject, string $start_utc, string $end_utc, string $visibility): array {
        return [
            new CalendarItem(['start_utc' => '2026-07-01 14:00:00', 'end_utc' => '2026-07-01 15:00:00', 'title' => 'Native copy', 'source' => 'dedup_a', 'source_key' => 'bookings:bkn-12']),
            new CalendarItem(['start_utc' => '2026-07-02 14:00:00', 'end_utc' => '2026-07-02 15:00:00', 'title' => 'Keyless one', 'source' => 'dedup_a']),
        ];
    }
}
class DedupSourceB implements CalendarItemSource {
    public static function getKey(): string { return 'dedup_b'; }
    public function priority(): int { return -10; }   // an echo of someone else's record
    public function getItems(CalendarSubject $subject, string $start_utc, string $end_utc, string $visibility): array {
        return [
            new CalendarItem(['start_utc' => '2026-07-01 14:00:00', 'end_utc' => '2026-07-01 15:00:00', 'title' => 'External echo', 'source' => 'dedup_b', 'source_key' => 'bookings:bkn-12']),
            new CalendarItem(['start_utc' => '2026-07-02 14:00:00', 'end_utc' => '2026-07-02 15:00:00', 'title' => 'Another keyless one', 'source' => 'dedup_b']),
            new CalendarItem(['start_utc' => '2026-07-03 14:00:00', 'end_utc' => '2026-07-03 15:00:00', 'title' => 'Only here', 'source' => 'dedup_b', 'source_key' => 'external:x-1']),
        ];
    }
}
$dedup_subject = CalendarSubject::user(1);
// B is registered first and A second: only priority (A has none, so 0, above B's -10) decides the winner.
CalendarItemSourceRegistry::useSources(['dedup_b' => new DedupSourceB(), 'dedup_a' => new DedupSourceA()]);
$got = CalendarItemSourceRegistry::getItems($dedup_subject, '2026-06-01 00:00:00', '2026-08-01 00:00:00', CalendarItem::VIS_DETAILS);
$titles = array_map(function ($i) { return $i->title; }, $got);
ok('the same source_key from two sources gives one item', count(array_filter($titles, function ($t) { return in_array($t, ['Native copy', 'External echo'], true); })) === 1, json_encode($titles));
ok('the higher-priority source is the one kept', in_array('Native copy', $titles, true) && !in_array('External echo', $titles, true), json_encode($titles));
ok('items with no key are never merged', in_array('Keyless one', $titles, true) && in_array('Another keyless one', $titles, true), json_encode($titles));
ok('an item no other source has is kept', in_array('Only here', $titles, true));
$busy = CalendarItemSourceRegistry::getItems($dedup_subject, '2026-06-01 00:00:00', '2026-08-01 00:00:00', CalendarItem::VIS_BUSY);
ok('at busy visibility the duplicate is still dropped and no title leaks', count($busy) === 4 && array_reduce($busy, function ($c, $i) { return $c && $i->title === null; }, true), (string)count($busy));
CalendarItemSourceRegistry::useSources(['dedup_a' => new DedupSourceA(), 'dedup_b' => new DedupSourceB()]);
$again = array_map(function ($i) { return $i->title; }, CalendarItemSourceRegistry::getItems($dedup_subject, '2026-06-01 00:00:00', '2026-08-01 00:00:00', CalendarItem::VIS_DETAILS));
ok('the winner does not depend on registration order', in_array('Native copy', $again, true) && !in_array('External echo', $again, true), json_encode($again));
CalendarItemSourceRegistry::resetCache();

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
