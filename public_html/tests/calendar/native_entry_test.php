<?php
/** @joinery-test
 * name: native_entry
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Phase 4.1 checkpoint: native calendar entries (cal_entries) + NativeCalendarItemSource.
 *
 *   php tests/calendar/native_entry_test.php
 *
 * A seeded native entry appears on the owner's aggregated feed and, when it
 * blocks availability, in the busy projection; visibility stripping holds.
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarItemSourceRegistry.php'));
require_once(PathHelper::getIncludePath('includes/calendar/item_sources/NativeCalendarItemSource.php'));
require_once(PathHelper::getIncludePath('data/calendar_entry_class.php'));

$dblink = DbConnector::get_instance()->get_db_link();
$row = $dblink->query("SELECT usr_user_id FROM usr_users WHERE usr_delete_time IS NULL ORDER BY usr_user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$row) { harness_skip('no users'); harness_finish(); }
$subject = CalendarSubject::user($row['usr_user_id']);

$start = gmdate('Y-m-d H:i:s', strtotime('+2 days 14:00'));
$end   = gmdate('Y-m-d H:i:s', strtotime('+2 days 16:00'));
$range_start = gmdate('Y-m-d H:i:s', strtotime('+1 day'));
$range_end   = gmdate('Y-m-d H:i:s', strtotime('+4 days'));

section('Create a blocking native entry');
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
harness_register_row('cal_entries', 'cal_calendar_entry_id', (int)$entry->key);
ok('entry saved with an id', (bool)$entry->key);

CalendarItemSourceRegistry::resetCache();
$details = CalendarItemSourceRegistry::getItems($subject, $range_start, $range_end, CalendarItem::VIS_DETAILS);
$mine = array_filter($details, function($i){ return $i->source === 'native'; });
ok('native entry appears on the aggregated feed', count($mine) >= 1);
$found = false;
foreach ($mine as $i) { if ($i->title === 'Dentist' && $i->source_key === 'native:cal-' . $entry->key) { $found = true; } }
ok('entry carries its title + stable source_key at details', $found);

$busy = CalendarItemSourceRegistry::getBusyBlocks($subject, $range_start, $range_end);
$covered = false;
foreach ($busy as $b) { if ($b['start'] <= $start && $b['end'] >= $end) { $covered = true; } }
ok('blocking entry shows up in the busy projection', $covered);

$busy_items = CalendarItemSourceRegistry::getItems($subject, $range_start, $range_end, CalendarItem::VIS_BUSY);
$leak = false;
foreach ($busy_items as $i) { if ($i->source === 'native' && $i->title !== null) { $leak = true; } }
ok('native entry title is stripped at busy visibility', !$leak);

section('Non-blocking entry: on feed, not blocking');
$free = new CalendarEntry(NULL);
$free->set('cal_subject_type', $subject->type);
$free->set('cal_subject_id', $subject->id);
$free->set('cal_start_utc', gmdate('Y-m-d H:i:s', strtotime('+3 days 09:00')));
$free->set('cal_end_utc', gmdate('Y-m-d H:i:s', strtotime('+3 days 09:30')));
$free->set('cal_blocks_availability', false);
$free->set('cal_title', 'Reminder');
$free->save();
harness_register_row('cal_entries', 'cal_calendar_entry_id', (int)$free->key);

// Discriminate blocking vs non-blocking against the source directly — the merged
// registry busy projection also contains this user's events, which would mask it.
$src = new NativeCalendarItemSource();
$src_items = $src->getItems($subject, $range_start, $range_end, CalendarItem::VIS_DETAILS);
$blocking = null; $nonblocking = null;
foreach ($src_items as $i) {
    if ($i->source_key === 'native:cal-' . $entry->key) { $blocking = $i; }
    if ($i->source_key === 'native:cal-' . $free->key)  { $nonblocking = $i; }
}
ok('blocking entry reports blocks_availability=true', $blocking && $blocking->blocks_availability === true);
ok('non-blocking entry reports blocks_availability=false', $nonblocking && $nonblocking->blocks_availability === false);

// cleanup + confirm the source drops soft-deleted entries
$entry->soft_delete();
$free->soft_delete();
$after = $src->getItems($subject, $range_start, $range_end, CalendarItem::VIS_DETAILS);
$still = false;
foreach ($after as $i) { if ($i->source_key === 'native:cal-' . $entry->key) { $still = true; } }
ok('soft-deleted entry leaves the native source output', !$still);

harness_finish();
