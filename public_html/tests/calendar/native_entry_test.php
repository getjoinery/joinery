<?php
/**
 * Phase 4.1 checkpoint: native calendar entries (cal_items) + NativeCalendarItemSource.
 *
 *   php tests/calendar/native_entry_test.php
 *
 * A seeded native entry appears on the owner's aggregated feed and, when it
 * blocks availability, in the busy projection; visibility stripping holds.
 */
require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarItemSourceRegistry.php'));
require_once(PathHelper::getIncludePath('includes/calendar/item_sources/NativeCalendarItemSource.php'));
require_once(PathHelper::getIncludePath('data/calendar_entry_class.php'));

$tests = 0; $failures = 0;
function check($label, $cond) {
    global $tests, $failures; $tests++;
    echo ($cond ? "  PASS: " : "  FAIL: ") . "$label\n";
    if (!$cond) { $failures++; }
}

$dblink = DbConnector::get_instance()->get_db_link();
$row = $dblink->query("SELECT usr_user_id FROM usr_users WHERE usr_delete_time IS NULL ORDER BY usr_user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$row) { echo "SKIP: no users\n"; exit(0); }
$subject = CalendarSubject::user($row['usr_user_id']);

$start = gmdate('Y-m-d H:i:s', strtotime('+2 days 14:00'));
$end   = gmdate('Y-m-d H:i:s', strtotime('+2 days 16:00'));
$range_start = gmdate('Y-m-d H:i:s', strtotime('+1 day'));
$range_end   = gmdate('Y-m-d H:i:s', strtotime('+4 days'));

echo "Create a blocking native entry\n";
$entry = new CalendarEntry(NULL);
$entry->set('cal_subject_type', $subject->type);
$entry->set('cal_subject_id', $subject->id);
$entry->set('cal_start_utc', $start);
$entry->set('cal_end_utc', $end);
$entry->set('cal_all_day', false);
$entry->set('cal_title', 'Dentist');
$entry->set('cal_blocks_availability', true);
$entry->set('cal_visibility', 'details');
$entry->set('cal_type', 'personal');
$entry->save();
check('entry saved with an id', (bool)$entry->key);

CalendarItemSourceRegistry::resetCache();
$details = CalendarItemSourceRegistry::getItems($subject, $range_start, $range_end, CalendarItem::VIS_DETAILS);
$mine = array_filter($details, function($i){ return $i->source === 'native'; });
check('native entry appears on the aggregated feed', count($mine) >= 1);
$found = false;
foreach ($mine as $i) { if ($i->title === 'Dentist' && $i->source_key === 'native:cal-' . $entry->key) { $found = true; } }
check('entry carries its title + stable source_key at details', $found);

$busy = CalendarItemSourceRegistry::getBusyBlocks($subject, $range_start, $range_end);
$covered = false;
foreach ($busy as $b) { if ($b['start'] <= $start && $b['end'] >= $end) { $covered = true; } }
check('blocking entry shows up in the busy projection', $covered);

$busy_items = CalendarItemSourceRegistry::getItems($subject, $range_start, $range_end, CalendarItem::VIS_BUSY);
$leak = false;
foreach ($busy_items as $i) { if ($i->source === 'native' && $i->title !== null) { $leak = true; } }
check('native entry title is stripped at busy visibility', !$leak);

echo "\nNon-blocking entry: on feed, not blocking\n";
$free = new CalendarEntry(NULL);
$free->set('cal_subject_type', $subject->type);
$free->set('cal_subject_id', $subject->id);
$free->set('cal_start_utc', gmdate('Y-m-d H:i:s', strtotime('+3 days 09:00')));
$free->set('cal_end_utc', gmdate('Y-m-d H:i:s', strtotime('+3 days 09:30')));
$free->set('cal_blocks_availability', false);
$free->set('cal_title', 'Reminder');
$free->save();

// Discriminate blocking vs non-blocking against the source directly — the merged
// registry busy projection also contains this user's events, which would mask it.
$src = new NativeCalendarItemSource();
$src_items = $src->getItems($subject, $range_start, $range_end, CalendarItem::VIS_DETAILS);
$blocking = null; $nonblocking = null;
foreach ($src_items as $i) {
    if ($i->source_key === 'native:cal-' . $entry->key) { $blocking = $i; }
    if ($i->source_key === 'native:cal-' . $free->key)  { $nonblocking = $i; }
}
check('blocking entry reports blocks_availability=true', $blocking && $blocking->blocks_availability === true);
check('non-blocking entry reports blocks_availability=false', $nonblocking && $nonblocking->blocks_availability === false);

// cleanup + confirm the source drops soft-deleted entries
$entry->soft_delete();
$free->soft_delete();
$after = $src->getItems($subject, $range_start, $range_end, CalendarItem::VIS_DETAILS);
$still = false;
foreach ($after as $i) { if ($i->source_key === 'native:cal-' . $entry->key) { $still = true; } }
check('soft-deleted entry leaves the native source output', !$still);

echo "\n--------------------------------------\n";
echo "Total: $tests  Failures: $failures\n";
exit($failures ? 1 : 0);
