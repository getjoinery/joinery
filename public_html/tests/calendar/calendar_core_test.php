<?php
/**
 * Phase 1 (Personal calendar core) checkpoint tests.
 *
 *   php tests/calendar/calendar_core_test.php
 *
 * Covers: CalendarItem construction + visibility stripping, the busy-projection
 * overlap merge, CalendarItemSource registry discovery, and CalendarSubject
 * resolution of a user to name/timezone.
 */

require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarItem.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarItemSourceRegistry.php'));

$tests = 0;
$failures = 0;
function check($label, $cond) {
    global $tests, $failures;
    $tests++;
    if ($cond) {
        echo "  PASS: $label\n";
    } else {
        $failures++;
        echo "  FAIL: $label\n";
    }
}

echo "1.1 CalendarItem value object\n";
$item = new CalendarItem([
    'start_utc' => '2026-07-01 14:00:00',
    'end_utc'   => '2026-07-01 15:00:00',
    'type'      => CalendarItem::TYPE_EVENT,
    'title'     => 'Dentist',
    'url'       => '/event/dentist',
    'source'    => 'events',
    'source_key'=> 'events:evt-9',
]);
check('item carries title at details level', $item->title === 'Dentist');
check('event type maps to a colour', $item->getColor() === CalendarItem::TYPE_COLORS[CalendarItem::TYPE_EVENT]);
$arr = $item->toArray();
check('toArray exposes start/end/color/source_key', $arr['start'] === '2026-07-01 14:00:00' && $arr['source_key'] === 'events:evt-9');

echo "\n1.2 / projection boundary: visibility stripping\n";
$busy = $item->atVisibility(CalendarItem::VIS_BUSY);
check('busy copy drops the title', $busy->title === null);
check('busy copy drops the url', $busy->url === null);
check('original item is untouched (no leak by mutation)', $item->title === 'Dentist');

echo "\n1.3 busy projection overlap merge\n";
$merged = CalendarItemSourceRegistry::mergeBlocks([
    ['start' => '2026-07-01 10:00:00', 'end' => '2026-07-01 11:00:00'],
    ['start' => '2026-07-01 10:30:00', 'end' => '2026-07-01 12:00:00'],   // overlaps prev
    ['start' => '2026-07-01 14:00:00', 'end' => '2026-07-01 15:00:00'],   // separate
    ['start' => '2026-07-01 09:00:00', 'end' => '2026-07-01 09:30:00'],   // earlier, out of order
]);
check('overlapping ranges merge to one', count($merged) === 3);
check('merge keeps sorted order', $merged[0]['start'] === '2026-07-01 09:00:00');
check('merged range spans both inputs', $merged[1]['start'] === '2026-07-01 10:00:00' && $merged[1]['end'] === '2026-07-01 12:00:00');
check('zero-length / invalid ranges dropped', count(CalendarItemSourceRegistry::mergeBlocks([['start'=>'x','end'=>'x']])) === 0);

echo "\n1.2 registry discovery\n";
$sources = CalendarItemSourceRegistry::getSources();
check('EventItemSource discovered under key "events"', isset($sources['events']));
check('discovered entries implement the contract', isset($sources['events']) && $sources['events'] instanceof CalendarItemSource);

echo "\n1.1 CalendarSubject resolution\n";
$dblink = DbConnector::get_instance()->get_db_link();
$row = $dblink->query("SELECT usr_user_id FROM usr_users WHERE usr_delete_time IS NULL ORDER BY usr_user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($row) {
    $subject = CalendarSubject::user($row['usr_user_id']);
    check('subject key formats as user:id', $subject->getKey() === 'user:' . (int)$row['usr_user_id']);
    check('resolves a non-empty display name', strlen($subject->getDisplayName()) > 0 && $subject->getDisplayName() !== 'Unknown');
    check('resolves a timezone', strlen($subject->getTimezone()) > 0);
    check('getUserId returns the id for a user subject', $subject->getUserId() === (int)$row['usr_user_id']);
    $items = CalendarItemSourceRegistry::getItems($subject, '2020-01-01 00:00:00', '2030-01-01 00:00:00', CalendarItem::VIS_BUSY);
    check('aggregated busy items never carry a title', array_reduce($items, function($c,$i){ return $c && $i->title === null; }, true));
} else {
    echo "  SKIP: no users in DB to resolve\n";
}

echo "\n--------------------------------------\n";
echo "Total: $tests  Failures: $failures\n";
exit($failures ? 1 : 0);
