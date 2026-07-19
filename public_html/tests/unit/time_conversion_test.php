<?php
/** @joinery-test
 * name: time_conversion
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The time helpers every view formats a database timestamp through.
 *
 * All stored times are UTC and every one of them is rendered in somebody's
 * zone, so `convert_time()` sits between the database and essentially every
 * date a user reads. It has one hard requirement — the instant must survive
 * the trip — and one soft one that turns out to matter just as much: an input
 * with no time in it must not produce a time.
 *
 * That second property was broken. DateTime reads the empty string as "now",
 * so a blank optional timestamp rendered as the current moment: not a visibly
 * wrong value a reader would question, but a plausible one saying the thing
 * just happened. The same held for `time_shift('')`, which reported a deadline
 * a week out for a record with no start date. Both now refuse an empty input
 * the way they already refused NULL.
 *
 * The rest of this suite pins behaviour that is correct but surprising, and
 * therefore easy to "fix" into something wrong: which argument wins when the
 * input carries its own timezone, and what a calendar-unit shift does when the
 * target month is too short.
 *
 * Runs offline, no DB.
 * Run: php tests/unit/time_conversion_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$FMT = 'Y-m-d H:i:s';
$FMTZ = 'Y-m-d H:i:s T';


section('Nothing in, nothing out');

check(LibraryFunctions::convert_time(NULL, 'UTC', 'UTC', $FMT) === FALSE,
	'convert_time(NULL) is FALSE');
check(LibraryFunctions::convert_time('', 'UTC', 'UTC', $FMT) === FALSE,
	'convert_time(empty string) is FALSE, not the current time',
	var_export(LibraryFunctions::convert_time('', 'UTC', 'UTC', $FMT), true));
check(LibraryFunctions::time_shift(NULL, '7 days', $FMT) === FALSE,
	'time_shift(NULL) is FALSE');
check(LibraryFunctions::time_shift('', '7 days', $FMT) === FALSE,
	'time_shift(empty string) is FALSE, not a week from today',
	var_export(LibraryFunctions::time_shift('', '7 days', $FMT), true));

// The distinction that makes the bug findable: a real timestamp still
// converts. A guard that refused everything would also pass the checks above.
check(LibraryFunctions::convert_time('2026-07-19 05:00:00', 'UTC', 'UTC', $FMT) === '2026-07-19 05:00:00',
	'A real timestamp still converts');
check(LibraryFunctions::time_shift('2026-07-19 05:00:00', '7 days', $FMT) === '2026-07-26 05:00:00',
	'A real timestamp still shifts');

// A string that is neither empty nor parseable throws rather than returning
// FALSE. That is deliberate and worth keeping: "no time" and "a time I cannot
// read" are different problems, and quietly rendering the second as a blank
// cell would hide bad data in a column the reader assumes is simply empty.
// Callers guard by not passing garbage, which for database timestamps is free.
$threw = false;
try {
	LibraryFunctions::convert_time('not a timestamp', 'UTC', 'UTC', $FMT);
} catch (Throwable $e) {
	$threw = true;
}
check($threw, 'An unparseable string raises rather than silently rendering something');

$threw = false;
try {
	LibraryFunctions::time_shift('not a timestamp', '7 days', $FMT);
} catch (Throwable $e) {
	$threw = true;
}
check($threw, 'time_shift raises on an unparseable string for the same reason');


section('The instant survives the conversion');

// 05:00 UTC in July is 01:00 in New York (EDT, UTC-4).
check(LibraryFunctions::convert_time('2026-07-19 05:00:00', 'UTC', 'America/New_York', $FMTZ) === '2026-07-19 01:00:00 EDT',
	'UTC to New York in summer subtracts four hours',
	LibraryFunctions::convert_time('2026-07-19 05:00:00', 'UTC', 'America/New_York', $FMTZ));

// 05:00 UTC in January is 00:00 in New York (EST, UTC-5) — same wall-clock
// input, a different offset, because the zone not the clock decides.
check(LibraryFunctions::convert_time('2026-01-19 05:00:00', 'UTC', 'America/New_York', $FMTZ) === '2026-01-19 00:00:00 EST',
	'UTC to New York in winter subtracts five hours',
	LibraryFunctions::convert_time('2026-01-19 05:00:00', 'UTC', 'America/New_York', $FMTZ));

// Round-tripping is the property callers actually rely on: display it in the
// user's zone, and the value you started with is recoverable.
$utc = '2026-07-19 05:00:00';
$local = LibraryFunctions::convert_time($utc, 'UTC', 'Asia/Tokyo', $FMT);
check(LibraryFunctions::convert_time($local, 'Asia/Tokyo', 'UTC', $FMT) === $utc,
	'A conversion out and back returns the original instant', $local);

check(LibraryFunctions::convert_time('2026-07-19 05:00:00', 'UTC', 'Asia/Tokyo', $FMT) === '2026-07-19 14:00:00',
	'Tokyo is nine hours ahead of UTC year-round');


section('Which timezone argument wins');

// A string carrying its own offset overrides $fromtz — DateTime honours the
// offset in the string and ignores the timezone argument. This is correct, and
// worth pinning because it looks like a bug from the call site: the caller
// names UTC and gets an instant that is not what the digits say.
check(LibraryFunctions::convert_time('2026-07-19T05:00:00+02:00', 'UTC', 'UTC', $FMT) === '2026-07-19 03:00:00',
	'An offset inside the string beats the from-timezone argument',
	LibraryFunctions::convert_time('2026-07-19T05:00:00+02:00', 'UTC', 'UTC', $FMT));

// A DateTime object also knows its own zone, so $fromtz is ignored for object
// input: 05:00 in New York is 09:00 UTC regardless of what the caller declares.
$dt = new DateTime('2026-07-19 05:00:00', new DateTimeZone('America/New_York'));
check(LibraryFunctions::convert_time($dt, 'UTC', 'UTC', $FMT) === '2026-07-19 09:00:00',
	'A DateTime carries its own zone; the from-timezone argument is ignored',
	LibraryFunctions::convert_time($dt, 'UTC', 'UTC', $FMT));

// And the object handed in is not left converted — callers reuse it.
check($dt->getTimezone()->getName() === 'America/New_York',
	'The caller\'s DateTime is not mutated by the conversion',
	$dt->getTimezone()->getName());

// A bare string with no offset is the ordinary case, and there $fromtz is
// authoritative — this is the pairing that makes the two rules above legible.
check(LibraryFunctions::convert_time('2026-07-19 05:00:00', 'America/New_York', 'UTC', $FMT) === '2026-07-19 09:00:00',
	'A bare string is read in the from-timezone');


section('Shifting by an interval');

check(LibraryFunctions::time_shift('2026-07-19 05:00:00', '30 minutes', $FMT) === '2026-07-19 05:30:00',
	'Minutes shift');
check(LibraryFunctions::time_shift('2026-07-19 05:00:00', '-2 hours', $FMT) === '2026-07-19 03:00:00',
	'A negative interval shifts backwards');
check(LibraryFunctions::time_shift('2026-07-19 05:00:00', '1 year', $FMT) === '2027-07-19 05:00:00',
	'Years shift');

// Calendar-unit arithmetic overflows rather than clamping: PHP adds one to the
// month and then normalises the impossible date. Jan 31 + 1 month is March 3,
// not February 28. Pinned because a caller computing a monthly renewal from a
// month-end date gets a date in the month after the one they meant, and
// because "fixing" it to clamp would be a behaviour change to catch here
// rather than in a billing run.
check(LibraryFunctions::time_shift('2027-01-31 12:00:00', '1 month', $FMT) === '2027-03-03 12:00:00',
	'A month added to Jan 31 overflows into March rather than clamping to Feb',
	LibraryFunctions::time_shift('2027-01-31 12:00:00', '1 month', $FMT));

// The same input in a leap year lands a day earlier, which is the clearest
// demonstration that the result depends on February's length.
check(LibraryFunctions::time_shift('2028-01-31 12:00:00', '1 month', $FMT) === '2028-03-02 12:00:00',
	'The overflow tracks the length of February',
	LibraryFunctions::time_shift('2028-01-31 12:00:00', '1 month', $FMT));

// A month added to a day that exists in every month is unremarkable, which is
// the contrast that shows the overflow is about month length, not about
// months in general.
check(LibraryFunctions::time_shift('2027-01-15 12:00:00', '1 month', $FMT) === '2027-02-15 12:00:00',
	'A mid-month date shifts to the same day of the next month');


section('Relative time');

$now = gmdate('Y-m-d H:i:s');
check(LibraryFunctions::time_ago_or_time($now, 'UTC', 'UTC') === 'just now',
	'A timestamp from this moment reads as just now');
check(LibraryFunctions::time_ago_or_time(gmdate('Y-m-d H:i:s', time() - 300), 'UTC', 'UTC') === '5 minutes ago',
	'Five minutes ago is counted in minutes',
	LibraryFunctions::time_ago_or_time(gmdate('Y-m-d H:i:s', time() - 300), 'UTC', 'UTC'));
check(LibraryFunctions::time_ago_or_time(gmdate('Y-m-d H:i:s', time() - 60), 'UTC', 'UTC') === '1 minute ago',
	'One minute is singular');

// Past the hour it falls back to an absolute time, so the reader gets a date
// rather than "247 minutes ago".
$old = gmdate('Y-m-d H:i:s', time() - 7200);
check(LibraryFunctions::time_ago_or_time($old, 'UTC', 'UTC', $FMT) === LibraryFunctions::convert_time($old, 'UTC', 'UTC', $FMT),
	'Beyond an hour it renders the absolute time instead');

// A clock-skewed future timestamp reads as just now rather than as a count.
// (This falls out of the under-a-minute branch rather than the explicit
// negative-age clamp, which is unreachable — removing that line changes
// nothing. The behaviour is what callers depend on, so that is what is pinned.)
$future = gmdate('Y-m-d H:i:s', time() + 120);
check(LibraryFunctions::time_ago_or_time($future, 'UTC', 'UTC') === 'just now',
	'A timestamp slightly in the future reads as just now, not a negative age',
	LibraryFunctions::time_ago_or_time($future, 'UTC', 'UTC'));

check(LibraryFunctions::time_ago_or_time('', 'UTC', 'UTC') === '',
	'An empty input produces no relative time');

harness_finish();
