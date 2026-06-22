<?php
/**
 * Phase 2.2 checkpoint: SlotGenerator pure computation.
 *
 *   php tests/calendar/slot_generator_test.php
 *
 * Covers DST spring-forward/fall-back, overrides replacing weekly windows,
 * full-day blocks, buffer subtraction, min-notice filtering, increment-vs-
 * duration interplay, and busy blocks spanning window edges.
 */

require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/scheduling/SlotGenerator.php'));

$tests = 0; $failures = 0;
function check($label, $cond) {
    global $tests, $failures; $tests++;
    echo ($cond ? "  PASS: " : "  FAIL: ") . "$label\n";
    if (!$cond) { $failures++; }
}
function starts(array $slots) { return array_map(function ($s) { return $s['start']; }, $slots); }

// A Monday 9-12 window in New York. 2026-07-06 is a Monday.
$base = [
    'timezone'        => 'America/New_York',
    'windows'         => [['day_of_week' => 1, 'start' => '09:00:00', 'end' => '12:00:00']],
    'overrides'       => [],
    'range_start_utc' => '2026-07-06 00:00:00',
    'range_end_utc'   => '2026-07-07 00:00:00',
    'duration_minutes'=> 60,
    'increment_minutes'=> 60,
    'busy'            => [],
    'now_utc'         => '2026-07-01 00:00:00',   // well before the window
];

echo "Basic window → slots (NY EDT = UTC-4 in July)\n";
$slots = SlotGenerator::generate($base);
// 9,10,11 EDT = 13:00,14:00,15:00 UTC; last 60-min slot starts 11:00 (ends 12:00)
check('three hourly slots in a 3h window', count($slots) === 3);
check('first slot is 09:00 EDT = 13:00 UTC', $slots[0]['start'] === '2026-07-06 13:00:00');
check('last slot starts 11:00 EDT = 15:00 UTC', $slots[2]['start'] === '2026-07-06 15:00:00');

echo "\nIncrement vs duration interplay (30-min increments, 60-min slots)\n";
$p = $base; $p['increment_minutes'] = 30;
$slots = SlotGenerator::generate($p);
// starts 13:00,13:30,14:00,14:30,15:00 UTC -> 5
check('30-min increments yield 5 sixty-min slots', count($slots) === 5);
check('includes a half-past start', in_array('2026-07-06 13:30:00', starts($slots)));

echo "\nMin-notice filtering\n";
$p = $base; $p['now_utc'] = '2026-07-06 13:30:00';   // 9:30 EDT — first slot already too soon
$p['min_notice_minutes'] = 60;
$slots = SlotGenerator::generate($p);
check('slots before now+notice are dropped', !in_array('2026-07-06 13:00:00', starts($slots)) && count($slots) > 0);

echo "\nBusy block spanning a window edge\n";
$p = $base;
$p['busy'] = [['start' => '2026-07-06 13:30:00', 'end' => '2026-07-06 14:30:00']];  // 9:30-10:30 EDT
$slots = SlotGenerator::generate($p);
// 9:00 slot ends 10:00 overlaps busy(9:30) -> gone; 10:00 overlaps -> gone; 11:00 free
check('slots overlapping busy are removed', starts($slots) === ['2026-07-06 15:00:00']);

echo "\nBuffer subtraction\n";
$p = $base; $p['increment_minutes'] = 30;
$p['busy'] = [['start' => '2026-07-06 14:00:00', 'end' => '2026-07-06 14:30:00']];  // 10:00-10:30 EDT
$p['buffer_before_minutes'] = 30;
$p['buffer_after_minutes'] = 30;
$slots = SlotGenerator::generate($p);
// padded busy = 13:30-15:00 UTC. 60-min slots not overlapping: 13:00? ends 14:00 <=13:30? no, 14:00>13:30 overlaps. So only slots ending <=13:30 or starting >=15:00. 15:00 ends 16:00>window(16:00 UTC=12:00 EDT end) ok equal.
check('buffers widen the blocked region', !in_array('2026-07-06 14:30:00', starts($slots)) && !in_array('2026-07-06 13:30:00', starts($slots)));

echo "\nOverride replaces weekly windows (vacation = fully unavailable)\n";
$p = $base;
$p['overrides'] = [['date' => '2026-07-06', 'start' => null, 'end' => null]];
$slots = SlotGenerator::generate($p);
check('null/null override = no slots that day', count($slots) === 0);

echo "\nOverride with custom hours replaces the weekly window\n";
$p = $base;
$p['overrides'] = [['date' => '2026-07-06', 'start' => '15:00:00', 'end' => '17:00:00']];
$slots = SlotGenerator::generate($p);
// 15,16 EDT = 19:00,20:00 UTC
check('override hours used, not weekly', starts($slots) === ['2026-07-06 19:00:00', '2026-07-06 20:00:00']);

echo "\nFull-day busy block removes the day\n";
$p = $base;
$p['busy'] = [['start' => '2026-07-06 00:00:00', 'end' => '2026-07-07 00:00:00']];
$slots = SlotGenerator::generate($p);
check('all-day busy clears the day', count($slots) === 0);

echo "\nDST spring-forward (2026-03-08, US clocks jump 2:00->3:00 EST->EDT)\n";
// A window 01:00-05:00 local on the DST date. 2:00-3:00 local does not exist.
$p = [
    'timezone' => 'America/New_York',
    'windows' => [['day_of_week' => 0, 'start' => '01:00:00', 'end' => '05:00:00']],  // Sunday
    'overrides' => [], 'busy' => [],
    'range_start_utc' => '2026-03-08 00:00:00',
    'range_end_utc'   => '2026-03-09 12:00:00',
    'duration_minutes' => 60, 'increment_minutes' => 60,
    'now_utc' => '2026-01-01 00:00:00',
];
$slots = SlotGenerator::generate($p);
// 01:00 EST=06:00 UTC; after spring forward 03:00,04:00 EDT = 07:00,08:00 UTC. The lost hour shrinks the window.
check('spring-forward produces UTC-contiguous slots (no duplicate/By-hour gap)', count($slots) >= 2 && $slots[0]['start'] === '2026-03-08 06:00:00');
check('spring-forward slots strictly increasing', starts($slots) === array_values(array_unique(starts($slots))));

echo "\nDST fall-back (2026-11-01, clocks 2:00->1:00, the 1-2 hour repeats)\n";
$p['windows'] = [['day_of_week' => 0, 'start' => '00:00:00', 'end' => '03:00:00']];
$p['range_start_utc'] = '2026-11-01 00:00:00';
$p['range_end_utc'] = '2026-11-02 12:00:00';
$slots = SlotGenerator::generate($p);
check('fall-back window yields slots without error', count($slots) >= 3);

echo "\n--------------------------------------\n";
echo "Total: $tests  Failures: $failures\n";
exit($failures ? 1 : 0);
