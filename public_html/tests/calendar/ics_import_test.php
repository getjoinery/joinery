<?php
/**
 * IcsImporter checkpoint tests — the .ics reader/importer that mirrors IcsHelper.
 *
 *   php tests/calendar/ics_import_test.php
 *
 * Covers the two pure stages (no DB): RRULE → native recurrence translation
 * (including the expressible-subset boundary), the format parser (unfolding,
 * params, text unescaping, all-day, EXDATE), and a round-trip that pins the
 * reader to IcsHelper's writer so the two stay on the same format contract.
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('data/calendar_entry_class.php'));
require_once(PathHelper::getIncludePath('includes/IcsHelper.php'));
require_once(PathHelper::getIncludePath('includes/calendar/IcsImporter.php'));

if (empty($_SERVER['HTTP_HOST'])) { $_SERVER['HTTP_HOST'] = 'test.local'; }

$tests = 0; $failures = 0;
function check($label, $cond) {
    global $tests, $failures; $tests++;
    echo ($cond ? "  PASS: " : "  FAIL: ") . "$label\n";
    if (!$cond) { $failures++; }
}

// =============================================================================
// translateRecurrence() — expressible subset boundary
// =============================================================================
echo "translateRecurrence:\n";

$r = IcsImporter::translateRecurrence(['FREQ'=>'WEEKLY','BYDAY'=>'MO,WE','INTERVAL'=>'2'], '2026-06-01 09:00:00');
check('weekly MO,WE interval 2 → weekly/2 days 1,3',
      $r && $r['type']==='weekly' && $r['interval']===2 && $r['days_of_week']==='1,3' && $r['week_of_month']===null);

$r = IcsImporter::translateRecurrence(['FREQ'=>'DAILY','INTERVAL'=>'3'], '2026-06-01 09:00:00');
check('daily interval 3 → daily/3', $r && $r['type']==='daily' && $r['interval']===3);

$r = IcsImporter::translateRecurrence(['FREQ'=>'MONTHLY','BYDAY'=>'2TU'], '2026-06-09 09:00:00');
check('monthly 2TU → week 2, day 2', $r && $r['type']==='monthly' && $r['week_of_month']===2 && $r['days_of_week']==='2');

$r = IcsImporter::translateRecurrence(['FREQ'=>'MONTHLY','BYDAY'=>'-1FR'], '2026-06-26 09:00:00');
check('monthly -1FR → week -1, day 5', $r && $r['week_of_month']===-1 && $r['days_of_week']==='5');

$r = IcsImporter::translateRecurrence(['FREQ'=>'YEARLY'], '2026-03-10 09:00:00');
check('yearly → yearly/1', $r && $r['type']==='yearly' && $r['interval']===1);

// Not natively expressible → null
check('monthly 1MO,3MO (multiple ordinals) → null',
      IcsImporter::translateRecurrence(['FREQ'=>'MONTHLY','BYDAY'=>'1MO,3MO'], '2026-06-01 09:00:00') === null);
check('BYSETPOS → null',
      IcsImporter::translateRecurrence(['FREQ'=>'MONTHLY','BYDAY'=>'MO','BYSETPOS'=>'-1'], '2026-06-01 09:00:00') === null);
check('weekly with ordinal BYDAY (2MO) → null',
      IcsImporter::translateRecurrence(['FREQ'=>'WEEKLY','BYDAY'=>'2MO'], '2026-06-01 09:00:00') === null);
check('monthly BYMONTHDAY not matching start day → null',
      IcsImporter::translateRecurrence(['FREQ'=>'MONTHLY','BYMONTHDAY'=>'20'], '2026-06-15 09:00:00') === null);
check('unknown FREQ (SECONDLY) → null',
      IcsImporter::translateRecurrence(['FREQ'=>'SECONDLY'], '2026-06-01 09:00:00') === null);

// End conditions
$r = IcsImporter::translateRecurrence(['FREQ'=>'DAILY','UNTIL'=>'20261231T000000Z'], '2026-06-01 09:00:00');
check('UNTIL → end_date 2026-12-31', $r && $r['end_date']==='2026-12-31');

$r = IcsImporter::translateRecurrence(['FREQ'=>'WEEKLY','BYDAY'=>'MO,WE','COUNT'=>'4'], '2026-07-06 09:00:00');
// Mon Jul 6, Wed Jul 8, Mon Jul 13, Wed Jul 15 → 4th = Jul 15 (matches nth_occurrence_date engine)
check('COUNT 4 weekly Mon/Wed → end_date 2026-07-15', $r && $r['end_date']==='2026-07-15');

// =============================================================================
// parse() — unfolding, params, unescaping, all-day, EXDATE
// =============================================================================
echo "parse:\n";

$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
     . "BEGIN:VTIMEZONE\r\nTZID:America/New_York\r\nEND:VTIMEZONE\r\n"
     . "BEGIN:VEVENT\r\n"
     . "UID:abc-123\r\n"
     . "SUMMARY:Lunch with Dana\\, Pat\\; and others\r\n"
     . "DTSTART;TZID=America/New_York:20260601T120000\r\n"
     . "DTEND;TZID=America/New_York:20260601T130000\r\n"
     . "DESCRIPTION:line one\\nline two that is very very very very very very ver\r\n y long and folded\r\n"
     . "RRULE:FREQ=WEEKLY;BYDAY=MO\r\n"
     . "EXDATE;TZID=America/New_York:20260608T120000\r\n"
     . "EXDATE;TZID=America/New_York:20260615T120000\r\n"
     . "BEGIN:VALARM\r\nACTION:DISPLAY\r\nEND:VALARM\r\n"
     . "END:VEVENT\r\nEND:VCALENDAR\r\n";

$parsed = IcsImporter::parse($ics);
check('one event parsed', count($parsed['events']) === 1);
$ev = $parsed['events'][0];
check('UID parsed', ($ev['props']['UID']['value'] ?? null) === 'abc-123');
check('SUMMARY unescaped (comma + semicolon)', ($ev['props']['SUMMARY']['value'] ?? null) === 'Lunch with Dana, Pat; and others');
check('DTSTART TZID param parsed', ($ev['props']['DTSTART']['params']['TZID'] ?? null) === 'America/New_York');
check('DESCRIPTION unfolded + newline-unescaped',
      strpos($ev['props']['DESCRIPTION']['value'] ?? '', "line one\nline two") === 0
      && strpos($ev['props']['DESCRIPTION']['value'] ?? '', 'folded') !== false
      && strpos($ev['props']['DESCRIPTION']['value'] ?? '', ' long') !== false);
check('RRULE captured as raw value', ($ev['props']['RRULE']['value'] ?? null) === 'FREQ=WEEKLY;BYDAY=MO');
check('two EXDATEs accumulated', count($ev['exdates']) === 2);
check('VALARM not leaked into event props', !isset($ev['props']['ACTION']));

// All-day (VALUE=DATE)
$ics2 = "BEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:d1\nSUMMARY:Holiday\nDTSTART;VALUE=DATE:20260704\nDTEND;VALUE=DATE:20260705\nEND:VEVENT\nEND:VCALENDAR\n";
$p2 = IcsImporter::parse($ics2);
check('all-day event parses (bare LF, VALUE=DATE)',
      count($p2['events'])===1 && ($p2['events'][0]['props']['DTSTART']['params']['VALUE'] ?? null)==='DATE');

// =============================================================================
// Round-trip — IcsHelper writes, IcsImporter reads back the same contract
// =============================================================================
echo "round-trip (IcsHelper → IcsImporter):\n";

$src = new stdClass();
$src->evt_event_id   = 42;
$src->evt_name       = 'Team Sync; "weekly", room A';
$src->evt_start_time = '2026-06-01 09:00:00';
$src->evt_end_time   = '2026-06-01 10:00:00';

$vevent = IcsHelper::generateVevent($src);
$ics3   = IcsHelper::wrapInVcalendar($vevent);
$p3     = IcsImporter::parse($ics3);

check('round-trip: one event', count($p3['events']) === 1);
$e3 = $p3['events'][0]['props'] ?? [];
check('round-trip: SUMMARY survives escaping', ($e3['SUMMARY']['value'] ?? null) === 'Team Sync; "weekly", room A');
check('round-trip: DTSTART is UTC 09:00', ($e3['DTSTART']['value'] ?? null) === '20260601T090000Z');
check('round-trip: DTEND is UTC 10:00', ($e3['DTEND']['value'] ?? null) === '20260601T100000Z');

// =============================================================================
// _mapTimes() — timezone resolution to UTC + local wall-clock (no DB)
// =============================================================================
echo "_mapTimes (timezone math):\n";

$mapTimes = new ReflectionMethod('IcsImporter', '_mapTimes');
$mapTimes->setAccessible(true);
function map_props($ics_snippet) {
    $full = "BEGIN:VCALENDAR\nBEGIN:VEVENT\n" . $ics_snippet . "\nEND:VEVENT\nEND:VCALENDAR\n";
    $p = IcsImporter::parse($full);
    return $p['events'][0]['props'];
}
$USER_TZ = 'America/New_York';

// TZID floating-in-zone: June → EDT (UTC-4). 12:00 local = 16:00 UTC.
$m = $mapTimes->invoke(null, map_props("UID:t1\nDTSTART;TZID=America/New_York:20260601T120000\nDTEND;TZID=America/New_York:20260601T133000"), $USER_TZ);
check('TZID start 12:00 EDT → 16:00 UTC', $m['start_utc'] === '2026-06-01 16:00:00');
check('TZID end 13:30 EDT → 17:30 UTC', $m['end_utc'] === '2026-06-01 17:30:00');
check('TZID stores the event timezone', $m['timezone'] === 'America/New_York' && $m['all_day'] === false);

// UTC instant → local wall-clock in user tz (16:00Z = 12:00 EDT).
$m = $mapTimes->invoke(null, map_props("UID:t2\nDTSTART:20260601T160000Z\nDTEND:20260601T170000Z"), $USER_TZ);
check('UTC start kept; local derived to 12:00', $m['start_utc'] === '2026-06-01 16:00:00' && $m['start_local'] === '2026-06-01 12:00:00');

// All-day: local midnight to next-day midnight; DTEND honored.
$m = $mapTimes->invoke(null, map_props("UID:t3\nDTSTART;VALUE=DATE:20260704\nDTEND;VALUE=DATE:20260705"), $USER_TZ);
check('all-day start_local midnight', $m['all_day'] === true && $m['start_local'] === '2026-07-04 00:00:00');
check('all-day end_local next-day midnight', $m['end_local'] === '2026-07-05 00:00:00');

// DURATION when no DTEND: PT1H30M after a UTC start.
$m = $mapTimes->invoke(null, map_props("UID:t4\nDTSTART:20260601T120000Z\nDURATION:PT1H30M"), $USER_TZ);
check('DURATION PT1H30M → end 13:30 UTC', $m['end_utc'] === '2026-06-01 13:30:00');

// Unrecognized TZID (Outlook Windows name) → fall back to user tz + warning.
$m = $mapTimes->invoke(null, map_props("UID:t5\nDTSTART;TZID=Pacific Standard Time:20260601T120000\nDTEND;TZID=Pacific Standard Time:20260601T130000"), $USER_TZ);
check('unknown TZID falls back to user tz', $m['timezone'] === $USER_TZ && !empty($m['warning']));

echo "\n" . ($tests - $failures) . "/" . $tests . " passed\n";
exit($failures ? 1 : 0);
