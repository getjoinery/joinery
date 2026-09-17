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
 * The detail fields (location, link, notes) round-trip through the setter,
 * ride the feed at details and are stripped at busy, the link rule refuses
 * anything but http(s), and the .ics importer maps LOCATION/DESCRIPTION/URL.
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarItemSourceRegistry.php'));
require_once(PathHelper::getIncludePath('includes/calendar/item_sources/NativeCalendarItemSource.php'));
require_once(PathHelper::getIncludePath('data/entries_class.php'));

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
harness_register_row('cal_entries', 'cal_entry_id', (int)$entry->key);
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

section('Details: location, link, notes (specs/calendar_entry_details.md)');
$entry->set_detail_fields('  Room 4B ', 'https://meet.example.com/abc?pwd=1', "Bring ID\nConfirmation 77");
$entry->save();
$again = new CalendarEntry($entry->key, true);
ok('location trimmed and stored', $again->get('cal_location') === 'Room 4B');
ok('link stored verbatim', $again->get('cal_link') === 'https://meet.example.com/abc?pwd=1');
ok('notes keep their newline', $again->get('cal_notes') === "Bring ID\nConfirmation 77");

$src0 = new NativeCalendarItemSource();
$with = null;
foreach ($src0->getItems($subject, $range_start, $range_end, CalendarItem::VIS_DETAILS) as $i) {
    if ($i->source_key === 'native:cal-' . $entry->key) { $with = $i; }
}
ok('feed item carries location and link at details', $with && $with->location === 'Room 4B' && $with->link === 'https://meet.example.com/abc?pwd=1');
$arr = $with ? $with->toArray() : [];
ok('toArray() exposes location and link', ($arr['location'] ?? null) === 'Room 4B' && ($arr['link'] ?? null) === 'https://meet.example.com/abc?pwd=1');
$stripped = $with ? $with->atVisibility(CalendarItem::VIS_BUSY) : null;
ok('busy visibility strips location and link with the title', $stripped && $stripped->location === null && $stripped->link === null && $stripped->title === null);

$entry->set_detail_fields('', '', '');
$entry->save();
$cleared = new CalendarEntry($entry->key, true);
ok('empty strings clear all three to NULL', $cleared->get('cal_location') === null && $cleared->get('cal_link') === null && $cleared->get('cal_notes') === null);

$refused = 0;
foreach (['javascript:alert(1)', 'ftp://files.example.com/x', 'meet.example.com/abc', 'not a link', 'https://' . str_repeat('a', 2050)] as $bad) {
    try { CalendarEntry::normalize_link($bad); } catch (CalendarEntryException $e) { $refused++; }
}
ok('non-http(s), relative, junk and over-long links are refused', $refused === 5, $refused);
ok('a plain https link passes', CalendarEntry::normalize_link(' https://example.com/a?b=c ') === 'https://example.com/a?b=c');
ok('empty link normalizes to NULL', CalendarEntry::normalize_link('') === null && CalendarEntry::normalize_link(null) === null);
$long = new CalendarEntry(NULL);
$long->set_detail_fields(str_repeat('L', 300), null, str_repeat('N', 12000));
ok('location and notes are capped', mb_strlen($long->get('cal_location')) === 255 && mb_strlen($long->get('cal_notes')) === CalendarEntry::NOTES_MAX_LENGTH);

section('ICS import maps LOCATION / DESCRIPTION / URL');
require_once(PathHelper::getIncludePath('includes/calendar/IcsImporter.php'));
$uid_ok  = 'zzdetail-ok-' . bin2hex(random_bytes(4));
$uid_bad = 'zzdetail-bad-' . bin2hex(random_bytes(4));
$ics = "BEGIN:VCALENDAR\nVERSION:2.0\n"
     . "BEGIN:VEVENT\nUID:$uid_ok\nSUMMARY:Site visit\nDTSTART:20300601T090000Z\nDTEND:20300601T100000Z\n"
     . "LOCATION:12 Main St\, Springfield\nDESCRIPTION:Gate code 4411\\nAsk for Pat\nURL:https://tickets.example.com/v/9\nEND:VEVENT\n"
     . "BEGIN:VEVENT\nUID:$uid_bad\nSUMMARY:Odd link\nDTSTART:20300602T090000Z\nDTEND:20300602T100000Z\n"
     . "URL:ftp://files.example.com/x\nEND:VEVENT\n"
     . "END:VCALENDAR\n";
$summary = IcsImporter::import(IcsImporter::parse($ics), $subject, 'UTC');
$imported = [];
foreach (new MultiCalendarEntry(['subject_type' => $subject->type, 'subject_id' => $subject->id, 'deleted' => false, 'source' => 'ical_import']) as $r) {
    if (in_array($r->get('cal_source_event_id'), [$uid_ok, $uid_bad], true)) {
        harness_register_row('cal_entries', 'cal_entry_id', (int)$r->key);
        $imported[$r->get('cal_source_event_id')] = $r;
    }
}
ok('both events imported', (int)$summary['created'] === 2 && count($imported) === 2, json_encode($summary));
$okrow = $imported[$uid_ok] ?? null;
ok('LOCATION -> cal_location (unescaped)', $okrow && $okrow->get('cal_location') === '12 Main St, Springfield');
ok('DESCRIPTION -> cal_notes with its newline', $okrow && $okrow->get('cal_notes') === "Gate code 4411\nAsk for Pat");
ok('URL -> cal_link', $okrow && $okrow->get('cal_link') === 'https://tickets.example.com/v/9');
$badrow = $imported[$uid_bad] ?? null;
ok('a non-http(s) URL is left out, the event still imports', $badrow && $badrow->get('cal_link') === null);
ok('and is reported as a warning', !empty($summary['warnings']) && strpos(implode(' ', $summary['warnings']), 'link') !== false, json_encode($summary['warnings'] ?? []));

section('Non-blocking entry: on feed, not blocking');
$free = new CalendarEntry(NULL);
$free->set('cal_subject_type', $subject->type);
$free->set('cal_subject_id', $subject->id);
$free->set('cal_start_utc', gmdate('Y-m-d H:i:s', strtotime('+3 days 09:00')));
$free->set('cal_end_utc', gmdate('Y-m-d H:i:s', strtotime('+3 days 09:30')));
$free->set('cal_blocks_availability', false);
$free->set('cal_title', 'Reminder');
$free->save();
harness_register_row('cal_entries', 'cal_entry_id', (int)$free->key);

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
