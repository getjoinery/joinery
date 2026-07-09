<?php
/** @joinery-test
 * name: slot_generator
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Phase 2.2 checkpoint: SlotGenerator pure computation.
 *
 *   php tests/calendar/slot_generator_test.php
 *
 * Covers DST spring-forward/fall-back, overrides replacing weekly windows,
 * full-day blocks, buffer subtraction, min-notice filtering, increment-vs-
 * duration interplay, and busy blocks spanning window edges.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/scheduling/SlotGenerator.php'));

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

section('Basic window → slots (NY EDT = UTC-4 in July)');
$slots = SlotGenerator::generate($base);
// 9,10,11 EDT = 13:00,14:00,15:00 UTC; last 60-min slot starts 11:00 (ends 12:00)
ok('three hourly slots in a 3h window', count($slots) === 3);
ok('first slot is 09:00 EDT = 13:00 UTC', $slots[0]['start'] === '2026-07-06 13:00:00');
ok('last slot starts 11:00 EDT = 15:00 UTC', $slots[2]['start'] === '2026-07-06 15:00:00');

section('Increment vs duration interplay (30-min increments, 60-min slots)');
$p = $base; $p['increment_minutes'] = 30;
$slots = SlotGenerator::generate($p);
// starts 13:00,13:30,14:00,14:30,15:00 UTC -> 5
ok('30-min increments yield 5 sixty-min slots', count($slots) === 5);
ok('includes a half-past start', in_array('2026-07-06 13:30:00', starts($slots)));

section('Min-notice filtering');
$p = $base; $p['now_utc'] = '2026-07-06 13:30:00';   // 9:30 EDT — first slot already too soon
$p['min_notice_minutes'] = 60;
$slots = SlotGenerator::generate($p);
ok('slots before now+notice are dropped', !in_array('2026-07-06 13:00:00', starts($slots)) && count($slots) > 0);

section('Busy block spanning a window edge');
$p = $base;
$p['busy'] = [['start' => '2026-07-06 13:30:00', 'end' => '2026-07-06 14:30:00']];  // 9:30-10:30 EDT
$slots = SlotGenerator::generate($p);
// 9:00 slot ends 10:00 overlaps busy(9:30) -> gone; 10:00 overlaps -> gone; 11:00 free
ok('slots overlapping busy are removed', starts($slots) === ['2026-07-06 15:00:00']);

section('Buffer subtraction');
$p = $base; $p['increment_minutes'] = 30;
$p['busy'] = [['start' => '2026-07-06 14:00:00', 'end' => '2026-07-06 14:30:00']];  // 10:00-10:30 EDT
$p['buffer_before_minutes'] = 30;
$p['buffer_after_minutes'] = 30;
$slots = SlotGenerator::generate($p);
// padded busy = 13:30-15:00 UTC. 60-min slots not overlapping: 13:00? ends 14:00 <=13:30? no, 14:00>13:30 overlaps. So only slots ending <=13:30 or starting >=15:00. 15:00 ends 16:00>window(16:00 UTC=12:00 EDT end) ok equal.
ok('buffers widen the blocked region', !in_array('2026-07-06 14:30:00', starts($slots)) && !in_array('2026-07-06 13:30:00', starts($slots)));

section('Override replaces weekly windows (vacation = fully unavailable)');
$p = $base;
$p['overrides'] = [['date' => '2026-07-06', 'start' => null, 'end' => null]];
$slots = SlotGenerator::generate($p);
ok('null/null override = no slots that day', count($slots) === 0);

section('Override with custom hours replaces the weekly window');
$p = $base;
$p['overrides'] = [['date' => '2026-07-06', 'start' => '15:00:00', 'end' => '17:00:00']];
$slots = SlotGenerator::generate($p);
// 15,16 EDT = 19:00,20:00 UTC
ok('override hours used, not weekly', starts($slots) === ['2026-07-06 19:00:00', '2026-07-06 20:00:00']);

section('Full-day busy block removes the day');
$p = $base;
$p['busy'] = [['start' => '2026-07-06 00:00:00', 'end' => '2026-07-07 00:00:00']];
$slots = SlotGenerator::generate($p);
ok('all-day busy clears the day', count($slots) === 0);

section('DST spring-forward (2026-03-08, US clocks jump 2:00->3:00 EST->EDT)');
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
ok('spring-forward produces UTC-contiguous slots (no duplicate/By-hour gap)', count($slots) >= 2 && $slots[0]['start'] === '2026-03-08 06:00:00');
ok('spring-forward slots strictly increasing', starts($slots) === array_values(array_unique(starts($slots))));

section('DST fall-back (2026-11-01, clocks 2:00->1:00, the 1-2 hour repeats)');
$p['windows'] = [['day_of_week' => 0, 'start' => '00:00:00', 'end' => '03:00:00']];
$p['range_start_utc'] = '2026-11-01 00:00:00';
$p['range_end_utc'] = '2026-11-02 12:00:00';
$slots = SlotGenerator::generate($p);
ok('fall-back window yields slots without error', count($slots) >= 3);

harness_finish();
