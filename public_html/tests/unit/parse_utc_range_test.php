<?php
/** @joinery-test
 * name: parse_utc_range
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Unit test for LibraryFunctions::parse_utc_range() — the shared UTC
 * date-range parser behind the calendar/scheduling feed actions
 * (calendar_feed, availability_preview, bookings/booking_slots).
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

harness_boot();

section('valid input passes through');
$r = LibraryFunctions::parse_utc_range(['start' => '2026-07-01 00:00:00', 'end' => '2026-07-31 00:00:00']);
ok('full datetimes accepted', $r === ['2026-07-01 00:00:00', '2026-07-31 00:00:00']);

$r = LibraryFunctions::parse_utc_range(['start' => '2026-07-01', 'end' => '2026-07-31']);
ok('date-only bounds extend to midnight', $r === ['2026-07-01 00:00:00', '2026-07-31 00:00:00']);

section('defaults');
$r = LibraryFunctions::parse_utc_range([]);
ok('missing bounds default to a valid range', is_array($r) && $r[0] < $r[1]);
ok('default start is -7 days midnight', $r[0] === gmdate('Y-m-d 00:00:00', strtotime(gmdate('Y-m-d') . ' -7 days')));
ok('default end is +45 days midnight', $r[1] === gmdate('Y-m-d 00:00:00', strtotime(gmdate('Y-m-d') . ' +45 days')));

$r = LibraryFunctions::parse_utc_range([], '+0 days');
ok('custom start shift honored (today midnight)', $r[0] === gmdate('Y-m-d 00:00:00'));

$r = LibraryFunctions::parse_utc_range(['start' => '2026-07-01'], '-7 days', '+45 days');
ok('one bound given, other defaults', is_array($r) && $r[0] === '2026-07-01 00:00:00');

section('invalid input returns NULL');
ok('malformed start', LibraryFunctions::parse_utc_range(['start' => 'not-a-date', 'end' => '2026-07-31']) === NULL);
ok('malformed end', LibraryFunctions::parse_utc_range(['start' => '2026-07-01', 'end' => '31/07/2026']) === NULL);
ok('sql-ish injection string', LibraryFunctions::parse_utc_range(['start' => "2026-07-01' OR 1=1", 'end' => '2026-07-31']) === NULL);
ok('reversed range', LibraryFunctions::parse_utc_range(['start' => '2026-07-31', 'end' => '2026-07-01']) === NULL);
ok('empty range (start == end)', LibraryFunctions::parse_utc_range(['start' => '2026-07-01', 'end' => '2026-07-01']) === NULL);

harness_finish();
