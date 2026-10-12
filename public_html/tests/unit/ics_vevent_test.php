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
 *  - a line over 75 octets folds, and unfolds back to the same text
 *  - a cancelled thing says so; an empty optional field is left out
 *  - the UID is {kind}-{id}[-{instance}]@{site host}, the same every time
 *  - an Event still maps onto it with the UID and fields it always had
 *
 * Run: php tests/unit/ics_vevent_test.php
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
