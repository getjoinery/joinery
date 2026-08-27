/**
 * Exercises the reader's timestamp ladder under Node.
 *
 * The helpers live inside mailbox_reader.js's IIFE, which needs a DOM, so the
 * block is sliced out by its boundary markers and evaluated on its own. A slice
 * that no longer resolves is itself a failure: if the helpers are renamed or
 * moved apart, this gate must be updated with them rather than quietly passing.
 *
 * What is under guard (specs/mailbox_timestamp_ladder.md) — a timestamp answers
 * a different question as mail ages, so the format coarsens in four steps:
 *   - under an hour: how long ago, because that is the only thing being asked
 *   - under twelve hours: the clock time
 *   - under six months: the hour and the date, minutes dropped as noise
 *   - older: the date and year alone
 * plus the two edges that read as bugs when they are wrong — a message dated
 * fractionally in the future (routine clock skew) and the singular minute.
 *
 * Every case is built as a LOCAL time offset from a fixed 'now', because the
 * ladder's boundaries and its am/pm rendering are both local-time facts; a UTC
 * literal would pass or fail with the machine's zone.
 *
 * @version 1.0
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(join(here, '..', 'assets', 'mailbox_reader.js'), 'utf8');

const START = '// ── The timestamp ladder ─';
const END = '// A calendar date in the viewer\'s own timezone';
const from = source.indexOf(START);
const to = source.indexOf(END);
if (from < 0 || to < 0 || to <= from) {
	console.log('  FAIL: could not slice the timestamp ladder out of mailbox_reader.js'
		+ ' (markers moved — update this gate alongside them)');
	console.log('RESULT: FAIL 0 1');
	process.exit(1);
}

const block = source.slice(from, to);
const helpers = new Function(block
	+ '; return { fmtTime: fmtTime, clockTime: clockTime, hourOfDay: hourOfDay,'
	+ ' sixMonthsBefore: sixMonthsBefore };')();

let passed = 0;
let failed = 0;

function eq(label, actual, expected) {
	if (actual === expected) {
		console.log('  PASS: ' + label);
		passed++;
	} else {
		console.log('  FAIL: ' + label + ' (got ' + JSON.stringify(actual)
			+ ', want ' + JSON.stringify(expected) + ')');
		failed++;
	}
}

function ok(label, cond) { eq(label, !!cond, true); }

/**
 * The ISO-ish UTC string the server would send for a local time $minutes ago.
 * fmtTime re-reads it as UTC, so the round trip lands back on the intended
 * local instant whatever zone this machine runs in.
 */
function agoIso(minutes) {
	const d = new Date(Date.now() - minutes * 60000);
	const p = (n) => String(n).padStart(2, '0');
	return d.getUTCFullYear() + '-' + p(d.getUTCMonth() + 1) + '-' + p(d.getUTCDate())
		+ ' ' + p(d.getUTCHours()) + ':' + p(d.getUTCMinutes()) + ':' + p(d.getUTCSeconds());
}

/** What the ladder should print for a local instant $minutes ago. */
function localAt(minutes) { return new Date(Date.now() - minutes * 60000); }

const HOUR = 60, DAY = 60 * 24;

console.log('== rung 1: under an hour, how long ago ==');
eq('one minute is singular', helpers.fmtTime(agoIso(1)), '1 minute ago');
eq('two minutes', helpers.fmtTime(agoIso(2)), '2 minutes ago');
eq('fifty-nine minutes is still relative', helpers.fmtTime(agoIso(59)), '59 minutes ago');
eq('under a minute is not "0 minutes ago"', helpers.fmtTime(agoIso(0)), 'just now');
// Clock skew between browser and server routinely dates a new message ahead of
// now; "-1 minutes ago" reads as a bug rather than as a fresh message.
eq('a message dated slightly in the future', helpers.fmtTime(agoIso(-3)), 'just now');

console.log('== rung 2: under twelve hours, the clock ==');
eq('exactly an hour crosses to the clock',
	helpers.fmtTime(agoIso(HOUR)), helpers.clockTime(localAt(HOUR)));
eq('eleven hours is still the clock',
	helpers.fmtTime(agoIso(11 * HOUR)), helpers.clockTime(localAt(11 * HOUR)));
ok('the clock carries minutes and a lowercase meridiem',
	/^\d{1,2}:\d{2} (am|pm)$/.test(helpers.clockTime(localAt(HOUR))));
ok('the hour is never zero-padded', helpers.clockTime(new Date(2020, 0, 3, 9, 5)) === '9:05 am');
eq('noon is 12pm, not 0pm', helpers.clockTime(new Date(2020, 0, 3, 12, 0)), '12:00 pm');
eq('midnight is 12am, not 0am', helpers.clockTime(new Date(2020, 0, 3, 0, 30)), '12:30 am');

console.log('== rung 3: under six months, the hour and the date ==');
ok('twelve hours crosses to hour-and-date',
	/^\d{1,2}(am|pm) /.test(helpers.fmtTime(agoIso(12 * HOUR))));
ok('a month ago is hour-and-date', /^\d{1,2}(am|pm) /.test(helpers.fmtTime(agoIso(30 * DAY))));
eq('the hour alone, no minutes', helpers.hourOfDay(new Date(2020, 0, 3, 15, 45)), '3pm');
ok('and no year on this rung', !/, \d{4}$/.test(helpers.fmtTime(agoIso(30 * DAY))));

console.log('== rung 4: older than six months, the date alone ==');
const old = helpers.fmtTime(agoIso(300 * DAY));
ok('a year ago carries a four-digit year', /, \d{4}$/.test(old));
ok('and drops the time entirely', !/(am|pm)/.test(old));
ok('seven months ago is on the last rung', /, \d{4}$/.test(helpers.fmtTime(agoIso(213 * DAY))));
ok('five months ago is not', !/, \d{4}$/.test(helpers.fmtTime(agoIso(150 * DAY))));

console.log('== the six-month boundary ==');
const now = new Date(2026, 7, 27, 12, 0);  // 27 Aug 2026, local
const back = helpers.sixMonthsBefore(now);
ok('lands six months earlier', back < now);
ok('and roughly half a year back, not a day or a decade',
	(now - back) > 150 * DAY * 60000 && (now - back) < 200 * DAY * 60000);

console.log('== degenerate input ==');
eq('empty', helpers.fmtTime(''), '');
eq('null', helpers.fmtTime(null), '');
// An unparseable value is echoed, never rendered as 1970 or NaN — a wrong date
// stated confidently is worse than an obviously raw one.
eq('unparseable is echoed, not invented', helpers.fmtTime('not a date'), 'not a date');

console.log('');
if (failed === 0) {
	console.log('RESULT: PASS ' + passed + ' ' + failed);
	process.exit(0);
}
console.log('RESULT: FAIL ' + passed + ' ' + failed);
process.exit(1);
