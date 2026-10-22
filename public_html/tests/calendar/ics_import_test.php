<?php
/** @joinery-test
 * name: ics_import
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * IcsImporter checkpoint tests — the .ics reader/importer that mirrors IcsHelper.
 *
 *   php tests/calendar/ics_import_test.php
 *
 * Covers the two pure stages (no DB): RRULE → native recurrence translation
 * (including the expressible-subset boundary), the format parser (unfolding,
 * params, text unescaping, all-day, EXDATE), and a round-trip that pins the
 * reader to IcsHelper's writer so the two stay on the same format contract.
 * nativeToRrule() is translateRecurrence() backwards: every native rule reads
 * back as itself. A UTC UNTIL counts in the series' zone, so a series ends on the
 * day its last occurrence falls on there.
 *
 * @version 1.2
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/entries_class.php'));
require_once(PathHelper::getIncludePath('includes/IcsHelper.php'));
require_once(PathHelper::getIncludePath('includes/calendar/IcsImporter.php'));

if (empty($_SERVER['HTTP_HOST'])) { $_SERVER['HTTP_HOST'] = 'test.local'; }

// =============================================================================
// translateRecurrence() — expressible subset boundary
// =============================================================================
section('translateRecurrence');

$r = IcsImporter::translateRecurrence(['FREQ'=>'WEEKLY','BYDAY'=>'MO,WE','INTERVAL'=>'2'], '2026-06-01 09:00:00');
ok('weekly MO,WE interval 2 → weekly/2 days 1,3',
      $r && $r['type']==='weekly' && $r['interval']===2 && $r['days_of_week']==='1,3' && $r['week_of_month']===null);

$r = IcsImporter::translateRecurrence(['FREQ'=>'DAILY','INTERVAL'=>'3'], '2026-06-01 09:00:00');
ok('daily interval 3 → daily/3', $r && $r['type']==='daily' && $r['interval']===3);

$r = IcsImporter::translateRecurrence(['FREQ'=>'MONTHLY','BYDAY'=>'2TU'], '2026-06-09 09:00:00');
ok('monthly 2TU → week 2, day 2', $r && $r['type']==='monthly' && $r['week_of_month']===2 && $r['days_of_week']==='2');

$r = IcsImporter::translateRecurrence(['FREQ'=>'MONTHLY','BYDAY'=>'-1FR'], '2026-06-26 09:00:00');
ok('monthly -1FR → week -1, day 5', $r && $r['week_of_month']===-1 && $r['days_of_week']==='5');

$r = IcsImporter::translateRecurrence(['FREQ'=>'YEARLY'], '2026-03-10 09:00:00');
ok('yearly → yearly/1', $r && $r['type']==='yearly' && $r['interval']===1);

// Not natively expressible → null
ok('monthly 1MO,3MO (multiple ordinals) → null',
      IcsImporter::translateRecurrence(['FREQ'=>'MONTHLY','BYDAY'=>'1MO,3MO'], '2026-06-01 09:00:00') === null);
ok('BYSETPOS → null',
      IcsImporter::translateRecurrence(['FREQ'=>'MONTHLY','BYDAY'=>'MO','BYSETPOS'=>'-1'], '2026-06-01 09:00:00') === null);
ok('weekly with ordinal BYDAY (2MO) → null',
      IcsImporter::translateRecurrence(['FREQ'=>'WEEKLY','BYDAY'=>'2MO'], '2026-06-01 09:00:00') === null);
ok('monthly BYMONTHDAY not matching start day → null',
      IcsImporter::translateRecurrence(['FREQ'=>'MONTHLY','BYMONTHDAY'=>'20'], '2026-06-15 09:00:00') === null);
ok('unknown FREQ (SECONDLY) → null',
      IcsImporter::translateRecurrence(['FREQ'=>'SECONDLY'], '2026-06-01 09:00:00') === null);

// End conditions. UNTIL is the last instant an occurrence may start: a 09:00
// series with UNTIL at midnight of the 31st has its last occurrence on the 30th.
$r = IcsImporter::translateRecurrence(['FREQ'=>'DAILY','UNTIL'=>'20261231T000000Z'], '2026-06-01 09:00:00');
ok('UNTIL at midnight of the 31st, series at 09:00 → end_date 2026-12-30', $r && $r['end_date']==='2026-12-30');
$r = IcsImporter::translateRecurrence(['FREQ'=>'DAILY','UNTIL'=>'20261231'], '2026-06-01 09:00:00');
ok('UNTIL as a date → that date', $r && $r['end_date']==='2026-12-31');
$r = IcsImporter::translateRecurrence(['FREQ'=>'DAILY','UNTIL'=>'20261231T045959Z'], '2026-06-01 09:00:00', 'America/New_York');
ok('UNTIL at 04:59Z on the 31st is 23:59 on the 30th in New York → end_date 2026-12-30 (read as a UTC date it was the 31st)',
      $r && $r['end_date']==='2026-12-30');
$r = IcsImporter::translateRecurrence(['FREQ'=>'DAILY','UNTIL'=>'20261230T235959Z'], '2026-06-01 08:00:00', 'Asia/Tokyo');
ok('UNTIL at 23:59Z on the 30th is 08:59 on the 31st in Tokyo, after an 08:00 start → end_date 2026-12-31',
      $r && $r['end_date']==='2026-12-31');

$r = IcsImporter::translateRecurrence(['FREQ'=>'WEEKLY','BYDAY'=>'MO,WE','COUNT'=>'4'], '2026-07-06 09:00:00');
// Mon Jul 6, Wed Jul 8, Mon Jul 13, Wed Jul 15 → 4th = Jul 15 (matches nth_occurrence_date engine)
ok('COUNT 4 weekly Mon/Wed → end_date 2026-07-15', $r && $r['end_date']==='2026-07-15');

// =============================================================================
// nativeToRrule() — translateRecurrence() backwards
// =============================================================================
section('nativeToRrule');

$native_cases = [
	['daily, every 3 days',               ['type'=>'daily',   'interval'=>3, 'days_of_week'=>null,  'week_of_month'=>null, 'end_date'=>null],         '2026-06-01 09:00:00', 'FREQ=DAILY;INTERVAL=3'],
	['weekly Mon/Wed/Fri until a date',   ['type'=>'weekly',  'interval'=>1, 'days_of_week'=>'1,3,5','week_of_month'=>null, 'end_date'=>'2026-08-31'], '2026-06-01 09:00:00', null],
	['weekly on the start\'s weekday',     ['type'=>'weekly',  'interval'=>2, 'days_of_week'=>null,  'week_of_month'=>null, 'end_date'=>null],         '2026-06-03 09:00:00', 'FREQ=WEEKLY;INTERVAL=2'],
	['monthly on the 2nd Tuesday',        ['type'=>'monthly', 'interval'=>1, 'days_of_week'=>'2',   'week_of_month'=>2,    'end_date'=>null],         '2026-06-09 09:00:00', 'FREQ=MONTHLY;BYDAY=2TU'],
	['monthly on the last Friday',        ['type'=>'monthly', 'interval'=>1, 'days_of_week'=>'5',   'week_of_month'=>-1,   'end_date'=>'2027-01-31'], '2026-06-26 18:30:00', null],
	['monthly on the start\'s date',       ['type'=>'monthly', 'interval'=>6, 'days_of_week'=>null,  'week_of_month'=>null, 'end_date'=>null],         '2026-01-31 09:00:00', 'FREQ=MONTHLY;INTERVAL=6'],
	['yearly',                            ['type'=>'yearly',  'interval'=>1, 'days_of_week'=>null,  'week_of_month'=>null, 'end_date'=>'2036-02-29'], '2024-02-29 00:00:00', null],
];
foreach ($native_cases as $c) {
	list($label, $native, $start, $want) = $c;
	foreach (['America/Los_Angeles', 'Asia/Tokyo', null] as $tz) {
		$rule = IcsImporter::nativeToRrule($native, $start, $tz);
		$back = IcsImporter::translateRecurrence(IcsImporter::parseRrule($rule), $start, $tz);
		if ($native['type'] === 'monthly' && $native['week_of_month'] === null) { $native['days_of_week'] = null; }
		ok($label . ' (' . ($tz ?? 'all day') . '): ' . $rule . ' reads back as the same fields',
			$back !== null && $back['type'] === $native['type'] && $back['interval'] === $native['interval']
			&& (string)$back['days_of_week'] === (string)$native['days_of_week'] && $back['week_of_month'] === $native['week_of_month']
			&& $back['end_date'] === $native['end_date']);
		if ($want !== null) { ok($label . ': written ' . $want, $rule === $want); }
	}
}
ok('an end date is a date for an all-day series',
	strpos(IcsImporter::nativeToRrule(['type'=>'daily','end_date'=>'2026-08-31'], '2026-06-01 00:00:00', null), 'UNTIL=20260831') !== false);
ok('and the UTC instant of its last second beside a zone (23:59:59 in Los Angeles is 06:59:59Z the next day)',
	strpos(IcsImporter::nativeToRrule(['type'=>'daily','end_date'=>'2026-08-31'], '2026-06-01 09:00:00', 'America/Los_Angeles'), 'UNTIL=20260901T065959Z') !== false);
ok('a monthly rule by weekday with no stored weekday uses the start\'s',
	IcsImporter::nativeToRrule(['type'=>'monthly','week_of_month'=>1,'days_of_week'=>null], '2026-06-03 09:00:00', 'UTC') === 'FREQ=MONTHLY;BYDAY=1WE');
ok('a type the calendar does not have writes nothing', IcsImporter::nativeToRrule(['type'=>'hourly'], '2026-06-01 09:00:00', 'UTC') === '');

// =============================================================================
// parse() — unfolding, params, unescaping, all-day, EXDATE
// =============================================================================
section('parse');

$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
     . "BEGIN:VTIMEZONE\r\nTZID:America/New_York\r\nEND:VTIMEZONE\r\n"
     . "BEGIN:VEVENT\r\n"
     . "UID:abc-123\r\n"
     . "SUMMARY:Lunch with Dana\\, Pat\\; and others\r\n"
     . "DTSTART;TZID=America/New_York:20260601T120000\r\n"
     . "DTEND;TZID=America/New_York:20260601T130000\r\n"
     . "DESCRIPTION:line one\\nline two that is very very very very very very ver\r\n y long and folded\r\n"
     . "LOCATION:Caf\\, Corner\r\n"
     . "URL:https://maps.example.com/p?q=1&r=2\r\n"
     . "RRULE:FREQ=WEEKLY;BYDAY=MO\r\n"
     . "EXDATE;TZID=America/New_York:20260608T120000\r\n"
     . "EXDATE;TZID=America/New_York:20260615T120000\r\n"
     . "BEGIN:VALARM\r\nACTION:DISPLAY\r\nEND:VALARM\r\n"
     . "END:VEVENT\r\nEND:VCALENDAR\r\n";

$parsed = IcsImporter::parse($ics);
ok('one event parsed', count($parsed['events']) === 1);
$ev = $parsed['events'][0];
ok('UID parsed', ($ev['props']['UID']['value'] ?? null) === 'abc-123');
ok('SUMMARY unescaped (comma + semicolon)', ($ev['props']['SUMMARY']['value'] ?? null) === 'Lunch with Dana, Pat; and others');
ok('DTSTART TZID param parsed', ($ev['props']['DTSTART']['params']['TZID'] ?? null) === 'America/New_York');
ok('DESCRIPTION unfolded + newline-unescaped',
      strpos($ev['props']['DESCRIPTION']['value'] ?? '', "line one\nline two") === 0
      && strpos($ev['props']['DESCRIPTION']['value'] ?? '', 'folded') !== false
      && strpos($ev['props']['DESCRIPTION']['value'] ?? '', ' long') !== false);
ok('RRULE captured as raw value', ($ev['props']['RRULE']['value'] ?? null) === 'FREQ=WEEKLY;BYDAY=MO');
ok('LOCATION unescaped', ($ev['props']['LOCATION']['value'] ?? null) === 'Caf, Corner');
ok('URL kept verbatim (not text-unescaped)', ($ev['props']['URL']['value'] ?? null) === 'https://maps.example.com/p?q=1&r=2');
ok('two EXDATEs accumulated', count($ev['exdates']) === 2);
ok('VALARM not leaked into event props', !isset($ev['props']['ACTION']));

// All-day (VALUE=DATE)
$ics2 = "BEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:d1\nSUMMARY:Holiday\nDTSTART;VALUE=DATE:20260704\nDTEND;VALUE=DATE:20260705\nEND:VEVENT\nEND:VCALENDAR\n";
$p2 = IcsImporter::parse($ics2);
ok('all-day event parses (bare LF, VALUE=DATE)',
      count($p2['events'])===1 && ($p2['events'][0]['props']['DTSTART']['params']['VALUE'] ?? null)==='DATE');

// Truncated file — an event still open at EOF is reported, not silently dropped
$ics_cut = "BEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:ok1\nDTSTART:20260101T090000Z\nEND:VEVENT\nBEGIN:VEVENT\nUID:cut\nDTSTART:20260102T09";
$p_cut = IcsImporter::parse($ics_cut);
ok('truncated file: complete event still parsed', count($p_cut['events']) === 1);
ok('truncated file: flagged as truncated', ($p_cut['truncated'] ?? null) === true);
$p_ok = IcsImporter::parse($ics2);
ok('well-formed file: not flagged as truncated', ($p_ok['truncated'] ?? null) === false);

// =============================================================================
// Round-trip — IcsHelper writes, IcsImporter reads back the same contract
// =============================================================================
section('round-trip (IcsHelper → IcsImporter)');

$src = new stdClass();
$src->evt_event_id   = 42;
$src->evt_name       = 'Team Sync; "weekly", room A';
$src->evt_start_time = '2026-06-01 09:00:00';
$src->evt_end_time   = '2026-06-01 10:00:00';

$vevent = IcsHelper::generateVevent($src);
$ics3   = IcsHelper::wrapInVcalendar($vevent);
$p3     = IcsImporter::parse($ics3);

ok('round-trip: one event', count($p3['events']) === 1);
$e3 = $p3['events'][0]['props'] ?? [];
ok('round-trip: SUMMARY survives escaping', ($e3['SUMMARY']['value'] ?? null) === 'Team Sync; "weekly", room A');
ok('round-trip: DTSTART is UTC 09:00', ($e3['DTSTART']['value'] ?? null) === '20260601T090000Z');
ok('round-trip: DTEND is UTC 10:00', ($e3['DTEND']['value'] ?? null) === '20260601T100000Z');

// =============================================================================
// _mapTimes() — timezone resolution to UTC + local wall-clock (no DB)
// =============================================================================
section('_mapTimes (timezone math)');

$mapTimes = new ReflectionMethod('IcsImporter', '_mapTimes');
function map_props($ics_snippet) {
    $full = "BEGIN:VCALENDAR\nBEGIN:VEVENT\n" . $ics_snippet . "\nEND:VEVENT\nEND:VCALENDAR\n";
    $p = IcsImporter::parse($full);
    return $p['events'][0]['props'];
}
$USER_TZ = 'America/New_York';

// TZID floating-in-zone: June → EDT (UTC-4). 12:00 local = 16:00 UTC.
$m = $mapTimes->invoke(null, map_props("UID:t1\nDTSTART;TZID=America/New_York:20260601T120000\nDTEND;TZID=America/New_York:20260601T133000"), $USER_TZ);
ok('TZID start 12:00 EDT → 16:00 UTC', $m['start_utc'] === '2026-06-01 16:00:00');
ok('TZID end 13:30 EDT → 17:30 UTC', $m['end_utc'] === '2026-06-01 17:30:00');
ok('TZID stores the event timezone', $m['timezone'] === 'America/New_York' && $m['all_day'] === false);

// UTC instant → local wall-clock in user tz (16:00Z = 12:00 EDT).
$m = $mapTimes->invoke(null, map_props("UID:t2\nDTSTART:20260601T160000Z\nDTEND:20260601T170000Z"), $USER_TZ);
ok('UTC start kept; local derived to 12:00', $m['start_utc'] === '2026-06-01 16:00:00' && $m['start_local'] === '2026-06-01 12:00:00');

// All-day: local midnight to next-day midnight; DTEND honored.
$m = $mapTimes->invoke(null, map_props("UID:t3\nDTSTART;VALUE=DATE:20260704\nDTEND;VALUE=DATE:20260705"), $USER_TZ);
ok('all-day start_local midnight', $m['all_day'] === true && $m['start_local'] === '2026-07-04 00:00:00');
ok('all-day end_local next-day midnight', $m['end_local'] === '2026-07-05 00:00:00');

// DURATION when no DTEND: PT1H30M after a UTC start.
$m = $mapTimes->invoke(null, map_props("UID:t4\nDTSTART:20260601T120000Z\nDURATION:PT1H30M"), $USER_TZ);
ok('DURATION PT1H30M → end 13:30 UTC', $m['end_utc'] === '2026-06-01 13:30:00');

// Unrecognized TZID (Outlook Windows name) → fall back to user tz + warning.
$m = $mapTimes->invoke(null, map_props("UID:t5\nDTSTART;TZID=Pacific Standard Time:20260601T120000\nDTEND;TZID=Pacific Standard Time:20260601T130000"), $USER_TZ);
ok('unknown TZID falls back to user tz', $m['timezone'] === $USER_TZ && !empty($m['warning']));

harness_finish();
