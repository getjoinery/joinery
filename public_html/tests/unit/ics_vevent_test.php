<?php
/** @joinery-test
 * name: ics_vevent
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * IcsHelper::buildVevent() and IcsHelper::uid(): the one VEVENT writer and the one
 * UID rule (core_improvements_for_plugins.md C2). Every invite and feed on the
 * platform goes through them, so a calendar app that accepts one accepts all.
 *
 *  - text is escaped (commas, semicolons, backslashes, newlines) and times are UTC
 *  - a line over 75 octets folds, and unfolds back to the same text; no fold
 *    lands inside a UTF-8 character
 *  - a cancelled thing says so; an empty optional field is left out
 *  - the UID is {kind}-{id}[-{instance}]@{site host}, the same every time
 *  - an Event still maps onto it with the UID and fields it always had
 *  - an all-day thing is written as dates, a zone's wall clock with its TZID; a
 *    repeat rule, skipped dates and a replaced occurrence are written in the
 *    start's form; free time and tentative say so
 *  - vtimezone() gives the zone's offset PHP gives, at every clock change and
 *    between them, for 30 years: as a yearly rule where the zone keeps one, one
 *    change at a time where it does not (Israel)
 *
 * Run: php tests/unit/ics_vevent_test.php
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$settings = Globalvars::get_instance();
$host = preg_replace('#^https?://#', '', (string)($settings->get_setting('webDir') ?: ($_SERVER['HTTP_HOST'] ?? '')));
$host = rtrim($host, '/');

section('uid()');
check(IcsHelper::uid('booking', 12) === 'booking-12@' . $host, 'kind-id@site host', IcsHelper::uid('booking', 12));
check(IcsHelper::uid('event', 7, '2026-10-12') === 'event-7-20261012@' . $host, 'an instance date has its dashes dropped', IcsHelper::uid('event', 7, '2026-10-12'));
check(IcsHelper::uid('event', 7) === IcsHelper::uid('event', 7), 'the same thing always gets the same UID');
check(IcsHelper::uid('event', 7) !== IcsHelper::uid('booking', 7), 'a different kind is a different UID');

section('buildVevent()');
check(IcsHelper::buildVevent(array('uid' => '', 'start_utc' => '2026-10-12 10:00:00')) === '', 'no UID, no VEVENT');
check(IcsHelper::buildVevent(array('uid' => 'x@y')) === '', 'no start, no VEVENT');

$v = IcsHelper::buildVevent(array(
	'uid' => 'booking-12@example.test',
	'start_utc' => '2026-10-12 14:30:00',
	'end_utc' => '2026-10-12 15:00:00',
	'summary' => "Intro call, with Sam; bring \\ slides",
	'description' => "Line one\nLine two\r\nLine three",
	'location' => 'Room 4, Main St; second floor',
	'url' => 'https://example.test/booking/12',
));
$lines = explode("\r\n", $v);
check($lines[0] === 'BEGIN:VEVENT' && end($lines) === 'END:VEVENT', 'a VEVENT block');
check(in_array('UID:booking-12@example.test', $lines, true), 'the UID is carried as given');
check(in_array('DTSTART:20261012T143000Z', $lines, true) && in_array('DTEND:20261012T150000Z', $lines, true), 'times are written as UTC instants');
check(in_array('SUMMARY:Intro call\\, with Sam\; bring \\\\ slides', $lines, true), 'commas, semicolons and backslashes are escaped', $v);
check(in_array('DESCRIPTION:Line one\\nLine two\\nLine three', $lines, true), 'newlines become \\n whichever way they were written', $v);
check(in_array('LOCATION:Room 4\\, Main St\; second floor', $lines, true), 'the location is escaped too');
check(in_array('URL:https://example.test/booking/12', $lines, true), 'the URL is written as it is');
check(in_array('STATUS:CONFIRMED', $lines, true), 'confirmed unless said otherwise');
check(strpos($v, 'DTSTAMP:') !== false, 'a DTSTAMP is present');

$c = IcsHelper::buildVevent(array('uid' => 'a@b', 'start_utc' => '2026-10-12 10:00:00', 'status' => 'CANCELLED'));
check(strpos($c, 'STATUS:CANCELLED') !== false && strpos($c, 'STATUS:CONFIRMED') === false, 'a cancelled thing says CANCELLED');
check(strpos($c, 'SUMMARY') === false && strpos($c, 'DTEND') === false && strpos($c, 'LOCATION') === false && strpos($c, 'URL:') === false,
	'an empty optional field is left out, not written empty');
check(strpos(IcsHelper::buildVevent(array('uid' => 'a@b', 'start_utc' => '2026-10-12 10:00:00', 'status' => 'whatever')), 'STATUS:CONFIRMED') !== false,
	'an unknown status is CONFIRMED, never something a calendar app has not heard of');

section('folding');
$long = IcsHelper::buildVevent(array('uid' => 'a@b', 'start_utc' => '2026-10-12 10:00:00', 'summary' => str_repeat('word, ', 40)));
$folded = IcsHelper::foldLines(IcsHelper::wrapInVcalendar($long));
$over = array_filter(explode("\r\n", $folded), function ($l) { return strlen($l) > 75; });
check($over === array(), 'no line is over 75 octets after folding');
check(preg_match('/\r\n [^\r\n]/', $folded) === 1, 'a long line is continued on a line that starts with a space');
check(str_replace("\r\n ", '', $folded) === IcsHelper::wrapInVcalendar($long), 'and unfolding gives the original text back');

// Two-, three- and four-byte characters at every offset: no fold may land inside one.
$wide = 'SUMMARY:' . str_repeat('é漢😀', 30);
$wide_folded = IcsHelper::foldLines($wide);
$wide_lines = explode("\r\n", $wide_folded);
check(count($wide_lines) > 1, 'a long line of wide characters folds');
check(array_filter($wide_lines, function ($l) { return strlen($l) > 75 || !mb_check_encoding($l, 'UTF-8'); }) === array(),
	'every folded line is 75 octets or under and valid UTF-8 on its own');
check(str_replace("\r\n ", '', $wide_folded) === $wide, 'and unfolding gives the original characters back');

section('dates, zones and repeats');
$day = explode("\r\n", IcsHelper::buildVevent(array('uid' => 'a@b', 'all_day' => true, 'start_date' => '2026-10-12', 'end_date' => '2026-10-14',
	'rrule' => 'FREQ=YEARLY', 'exdates' => array('2027-10-12'))));
check(in_array('DTSTART;VALUE=DATE:20261012', $day, true) && in_array('DTEND;VALUE=DATE:20261014', $day, true),
	'an all-day thing is written as dates', implode(' | ', $day));
check(in_array('RRULE:FREQ=YEARLY', $day, true) && in_array('EXDATE;VALUE=DATE:20271012', $day, true), 'its rule as given, its skipped dates as dates');
$zoned = IcsHelper::buildVevent(array('uid' => 'a@b', 'tzid' => 'America/New_York', 'start_local' => '2026-10-12 09:00:00', 'end_local' => '2026-10-12 09:30:00',
	'rrule' => 'FREQ=WEEKLY;BYDAY=MO', 'exdates' => array('2026-10-19 09:00:00', '2026-10-26 09:00:00'), 'transparent' => true, 'status' => 'TENTATIVE'));
$zl = explode("\r\n", $zoned);
check(in_array('DTSTART;TZID=America/New_York:20261012T090000', $zl, true) && in_array('DTEND;TZID=America/New_York:20261012T093000', $zl, true),
	'a zone\'s wall clock is written with its TZID', $zoned);
check(in_array('EXDATE;TZID=America/New_York:20261019T090000', $zl, true) && in_array('EXDATE;TZID=America/New_York:20261026T090000', $zl, true),
	'each skipped date on its own line, in the start\'s form');
check(in_array('TRANSP:TRANSPARENT', $zl, true) && in_array('STATUS:TENTATIVE', $zl, true), 'free time and tentative say so');
check(strpos(IcsHelper::buildVevent(array('uid' => 'a@b', 'start_utc' => '2026-10-12 10:00:00')), 'TRANSP') === false, 'busy time is the default and is left unsaid');
$over = IcsHelper::buildVevent(array('uid' => 's@b', 'all_day' => true, 'start_date' => '2026-10-20', 'end_date' => '2026-10-21',
	'recurrence_id' => array('value' => '2026-10-19 09:00:00', 'tzid' => 'America/New_York')));
check(strpos($over, "RECURRENCE-ID;TZID=America/New_York:20261019T090000\r\n") !== false && strpos($over, 'DTSTART;VALUE=DATE:20261020') !== false,
	'a replaced occurrence is named in its series\' form, whatever form the replacement takes', $over);
check(IcsHelper::buildVevent(array('uid' => 'a@b', 'all_day' => true, 'start_utc' => '2026-10-12 10:00:00')) === ''
	&& IcsHelper::buildVevent(array('uid' => 'a@b', 'tzid' => 'UTC', 'start_utc' => '2026-10-12 10:00:00')) === '',
	'a start missing from the form the times are in gives nothing');

section('vtimezone()');
/** The parts of a VTIMEZONE, each a property map. */
function vtz_parts(string $ics): array {
	$parts = array();
	$cur = null;
	foreach (explode("\r\n", $ics) as $l) {
		if ($l === 'BEGIN:STANDARD' || $l === 'BEGIN:DAYLIGHT') { $cur = array('KIND' => substr($l, 6)); continue; }
		if ($l === 'END:STANDARD' || $l === 'END:DAYLIGHT') { $parts[] = $cur; $cur = null; continue; }
		if ($cur !== null) { list($k, $v) = explode(':', $l, 2); $cur[$k] = $v; }
	}
	return $parts;
}
function vtz_seconds(string $o): int {
	$s = (int)substr($o, 1, 2) * 3600 + (int)substr($o, 3, 2) * 60 + (strlen($o) > 5 ? (int)substr($o, 5, 2) : 0);
	return $o[0] === '-' ? -$s : $s;
}
/** The offset a calendar program reads from the parts at Unix time $t: the latest onset at or before it. */
function vtz_offset_at(array $parts, int $t): int {
	$best = null;
	$earliest = null;
	foreach ($parts as $p) {
		$from = vtz_seconds($p['TZOFFSETFROM']);
		$d = $p['DTSTART'];
		$clock = array((int)substr($d, 9, 2), (int)substr($d, 11, 2), (int)substr($d, 13, 2));
		$first = gmmktime($clock[0], $clock[1], $clock[2], (int)substr($d, 4, 2), (int)substr($d, 6, 2), (int)substr($d, 0, 4));
		$onsets = array($first - $from);
		if (isset($p['RRULE']) && preg_match('/BYMONTH=(\d+);BYDAY=(-?\d)(\w\w)/', $p['RRULE'], $m)) {
			$dow = array_search($m[3], array('SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'), true);
			for ($y = (int)substr($d, 0, 4); $y <= (int)gmdate('Y', $t) + 1; $y++) {
				if ((int)$m[2] > 0) {
					$day = 1 + (($dow - (int)gmdate('w', gmmktime(0, 0, 0, (int)$m[1], 1, $y)) + 7) % 7) + 7 * ((int)$m[2] - 1);
				} else {
					$last = gmmktime(0, 0, 0, (int)$m[1] + 1, 0, $y);
					$day = (int)gmdate('j', $last) - (((int)gmdate('w', $last) - $dow + 7) % 7);
				}
				$local = gmmktime($clock[0], $clock[1], $clock[2], (int)$m[1], $day, $y);
				if ($local >= $first) { $onsets[] = $local - $from; }
			}
		}
		foreach ($onsets as $o) {
			if ($earliest === null || $o < $earliest[0]) { $earliest = array($o, $from); }
			if ($o <= $t && ($best === null || $o > $best[0])) { $best = array($o, vtz_seconds($p['TZOFFSETTO'])); }
		}
	}
	return $best === null ? $earliest[1] : $best[1];
}

$ny = IcsHelper::vtimezone('America/New_York', strtotime('2026-01-15 UTC'), strtotime('2026-12-15 UTC'));
$parts = vtz_parts($ny);
check(strpos($ny, "BEGIN:VTIMEZONE\r\nTZID:America/New_York\r\n") === 0 && count($parts) === 2
	&& in_array('FREQ=YEARLY;BYMONTH=3;BYDAY=2SU', array_column($parts, 'RRULE'), true)
	&& in_array('FREQ=YEARLY;BYMONTH=11;BYDAY=1SU', array_column($parts, 'RRULE'), true),
	'New York is one DAYLIGHT and one STANDARD part, each repeating yearly, as Google writes it', $ny);
$tokyo = vtz_parts(IcsHelper::vtimezone('Asia/Tokyo', strtotime('2026-01-15 UTC'), strtotime('2026-12-15 UTC')));
check(count($tokyo) === 1 && $tokyo[0]['TZOFFSETTO'] === '+0900' && $tokyo[0]['TZNAME'] === 'JST', 'a zone with no clock changes is one part');
$kathmandu = vtz_parts(IcsHelper::vtimezone('Asia/Kathmandu', strtotime('2026-01-15 UTC'), strtotime('2026-12-15 UTC')));
check(count($kathmandu) === 1 && $kathmandu[0]['TZOFFSETTO'] === '+0545', 'an offset with minutes is written with them');
$jerusalem = IcsHelper::vtimezone('Asia/Jerusalem', strtotime('2026-01-15 UTC'), strtotime('2026-12-15 UTC'));
check(strpos($jerusalem, 'RRULE') === false && count(vtz_parts($jerusalem)) > 50,
	'Israel\'s change (the Friday before the last Sunday of March) is no fixed week of the month, so each one is listed');
$old = vtz_parts(IcsHelper::vtimezone('America/New_York', strtotime('2004-06-01 UTC'), strtotime('2005-06-01 UTC')));
check(count($old) > 2 && !isset($old[1]['RRULE']) && $old[count($old) - 1]['DTSTART'] === '20071104T020000',
	'a rule that began in 2007 repeats from 2007; the changes before it are listed', json_encode(array_column($old, 'DTSTART')));

$horizon = gmmktime(0, 0, 0, 1, 1, (int)gmdate('Y') + 30);
foreach (array('America/New_York', 'Europe/London', 'Australia/Sydney', 'Australia/Lord_Howe', 'Atlantic/Azores', 'America/Sao_Paulo',
		'Asia/Jerusalem', 'America/Santiago', 'Africa/Casablanca', 'Asia/Tehran', 'UTC') as $zone) {
	foreach (array(strtotime('2001-06-01 UTC'), strtotime('2026-02-01 UTC')) as $from) {
		$parts = vtz_parts(IcsHelper::vtimezone($zone, $from, $from + 86400 * 400));
		$tz = new DateTimeZone($zone);
		$samples = array();
		foreach ($tz->getTransitions($from, $horizon) as $i => $tr) {
			if ($i) { $samples[] = $tr['ts'] - 1; $samples[] = $tr['ts']; }
		}
		for ($t = $from; $t < $horizon; $t += 86400 * 30) { $samples[] = $t; }
		$wrong = null;
		foreach ($samples as $t) {
			if (vtz_offset_at($parts, $t) !== $tz->getOffset(new DateTime('@' . $t))) { $wrong = gmdate('c', $t); break; }
		}
		check($wrong === null, $zone . ' from ' . gmdate('Y', $from) . ': the offset PHP gives, at every change and between them, for 30 years', (string)$wrong);
	}
}

section('an Event maps onto it');
$ev = new stdClass();
$ev->evt_event_id = 41;
$ev->evt_start_time = '2026-10-12 18:00:00';
$ev->evt_end_time = '2026-10-12 19:00:00';
$ev->evt_name = 'Potluck, spring edition';
$ev->evt_short_description = '<p>Bring a <b>dish</b>.</p>';
$ev->evt_status = 3;
$out = IcsHelper::generateVevent($ev);
check(strpos($out, 'UID:event-41@' . $host) !== false, 'the Event keeps its UID', $out);
check(strpos($out, 'SUMMARY:Potluck\\, spring edition') !== false, 'its name is the summary, escaped');
check(strpos($out, 'DESCRIPTION:Bring a dish.') !== false, 'its description has the HTML stripped');
check(strpos($out, 'STATUS:CANCELLED') !== false, 'status 3 is CANCELLED');
check(strpos(IcsHelper::generateVevent($ev, '2026-10-19'), 'UID:event-41-20261019@' . $host) !== false, 'an instance gets its date in the UID');
$ev->evt_start_time = '';
check(IcsHelper::generateVevent($ev) === '', 'an event with no start time gives nothing');

harness_finish();
