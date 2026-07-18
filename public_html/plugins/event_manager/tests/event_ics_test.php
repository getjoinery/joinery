<?php
/** @joinery-test
 * name: event_ics
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * ICS feeds — which dates are handed out as calendar entries, and what they say.
 *
 * An ICS URL is a second public entry point into the same events the web pages
 * serve, and it accepts the same slug-plus-date. The two must agree: a URL that
 * 404s as a page and still returns a calendar entry publishes an occurrence that
 * does not exist, into software that will remind people to attend it. Both
 * routes resolve a date through Event::resolve_instance_for_date() for exactly
 * that reason, so the resolver's contract is tested directly and then both
 * routes are driven end to end to confirm they actually use it.
 *
 * The handlers finish through exit(), so they are run in a subprocess via
 * support/ics_route_runner.php rather than called in-process.
 *
 * A materialized instance deliberately wins over the pattern: once a row exists
 * people may be registered against it, so it stays resolvable even if the
 * pattern later stops producing that date.
 *
 * Sections: the resolver contract; per-event ICS; the calendar feed; ICS content.
 *
 * Run: php plugins/event_manager/tests/event_ics_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));
require_once(PathHelper::getIncludePath('includes/IcsHelper.php'));

$RUNNER = __DIR__ . '/support/ics_route_runner.php';

/** A public event, optionally recurring. */
function ics_make_event($name, $start_local, array $extra = array(), $tz = 'America/New_York') {
	$event = new Event(NULL);
	$event->set('evt_name', 'HarnessTest ' . $name . ' ' . bin2hex(random_bytes(3)));
	$event->set('evt_timezone', $tz);

	$start_utc = new DateTime($start_local, new DateTimeZone($tz));
	$start_utc->setTimezone(new DateTimeZone('UTC'));
	$end_utc = clone $start_utc;
	$end_utc->modify('+2 hours');
	$event->set('evt_start_time', $start_utc->format('Y-m-d H:i:s'));
	$event->set('evt_end_time', $end_utc->format('Y-m-d H:i:s'));
	$event->set('evt_status', Event::STATUS_ACTIVE);
	$event->set('evt_visibility', Event::VISIBILITY_PUBLIC);

	foreach ($extra as $field => $value) {
		$event->set($field, $value);
	}

	// Set the slug explicitly: prepare() is what generates one and it is not
	// guaranteed to run ahead of save(), so a fixture that skips this is
	// reachable by id but not by URL — which is what these routes take.
	$event->set('evt_link', $event->create_url($event->get('evt_name')));

	$event->save();
	$event->load();
	harness_register_row('evt_events', 'evt_event_id', $event->key);
	return $event;
}

/** Run a route handler in a subprocess; returns its output. */
function ics_fetch($which, $slug = '', $date = '') {
	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($GLOBALS['RUNNER'])
		. ' ' . escapeshellarg($which)
		. ' ' . escapeshellarg($slug)
		. ' ' . escapeshellarg($date) . ' 2>/dev/null';
	return (string) shell_exec($cmd);
}

function ics_is_calendar($out) { return strpos($out, 'BEGIN:VCALENDAR') !== false; }
function ics_is_404($out) { return !ics_is_calendar($out) && stripos($out, 'not found') !== false; }

// A weekly Monday series, two months out so "next upcoming" is deterministic.
$monday = date('Y-m-d', strtotime('next monday +35 days'));
$series = ics_make_event('IcsSeries', $monday . ' 18:00:00', array(
	'evt_recurrence_type' => 'weekly',
	'evt_recurrence_interval' => 1,
));
$series_slug = $series->get('evt_link');

$one_off = ics_make_event('IcsOneOff', date('Y-m-d', strtotime('+40 days')) . ' 09:00:00');
$one_off_slug = $one_off->get('evt_link');

$next_monday = date('Y-m-d', strtotime($monday . ' +7 days'));
$a_tuesday = date('Y-m-d', strtotime($monday . ' +1 day'));

harness_defer(function () use ($series) {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare("SELECT evt_event_id FROM evt_events WHERE evt_parent_event_id = ?");
	$q->execute(array($series->key));
	foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
		harness_register_row('evt_events', 'evt_event_id', $id);
	}
});

// ---------------------------------------------------------------------------
section('The resolver contract');

$resolved = $series->resolve_instance_for_date($next_monday);
check($resolved !== null, 'a date on the pattern resolves to an occurrence');
check($resolved && !($resolved instanceof Event),
	'an unmaterialized occurrence resolves to a virtual instance');

check($series->resolve_instance_for_date($a_tuesday) === null,
	'a date off the pattern resolves to nothing');

// Shape and impossibility are rejected before any date arithmetic runs.
check($series->resolve_instance_for_date('not-a-date') === null,
	'a malformed date resolves to nothing');
check($series->resolve_instance_for_date('') === null,
	'an empty date resolves to nothing');
check($series->resolve_instance_for_date(null) === null,
	'a missing date resolves to nothing');
check($series->resolve_instance_for_date('2026-13-01') === null,
	'an out-of-range month resolves to nothing');

// 2026-02-31 parses under strtotime, which rolls it forward to March 3. Left
// unchecked that would serve one date's occurrence under another date's URL.
check($series->resolve_instance_for_date('2026-02-31') === null,
	'a well-formed but impossible date resolves to nothing, not the date it rolls to');

check($one_off->resolve_instance_for_date($a_tuesday) === null,
	'a non-recurring event resolves no dated occurrence');

// A materialized row wins, and keeps winning if the pattern later excludes it.
$materialized = $series->materialize_instance($next_monday);
harness_register_row('evt_events', 'evt_event_id', $materialized->key);

$resolved = $series->resolve_instance_for_date($next_monday);
check($resolved instanceof Event,
	'a materialized date resolves to the real row, not a virtual instance');
check($resolved && (int)$resolved->key === (int)$materialized->key,
	'the resolved row is the materialized one');

$series->end_series($monday);
$series->load();
check(!$series->date_matches_pattern($next_monday),
	'ending the series takes the materialized date off the pattern');
$resolved = $series->resolve_instance_for_date($next_monday);
check($resolved instanceof Event,
	'a materialized date stays resolvable after the pattern stops producing it',
	'people may already be registered against that row');

// Put the series back so the route sections have a live pattern to work with.
$series->set('evt_recurrence_end_date', null);
$series->save();
$series->load();

// ---------------------------------------------------------------------------
section('Per-event ICS');

$out = ics_fetch('event', $series_slug, $next_monday);
check(ics_is_calendar($out), 'a real occurrence is served as a calendar entry');

$out = ics_fetch('event', $series_slug, $a_tuesday);
check(ics_is_404($out),
	'a date off the pattern is refused rather than served',
	'the event page 404s the same URL');

$out = ics_fetch('event', $series_slug, '2026-02-31');
check(ics_is_404($out), 'an impossible date is refused');

$out = ics_fetch('event', $series_slug, 'garbage');
check(ics_is_404($out), 'a malformed date is refused rather than raising an error',
	substr(preg_replace('/\s+/', ' ', $out), 0, 120));

$out = ics_fetch('event', $one_off_slug, $a_tuesday);
check(ics_is_404($out),
	'a date against a non-recurring event is refused');

$out = ics_fetch('event', $one_off_slug);
check(ics_is_calendar($out), 'a non-recurring event is served without a date');

$out = ics_fetch('event', 'no-such-event-slug-xyz');
check(ics_is_404($out), 'an unknown slug is refused');

$out = ics_fetch('event');
check(ics_is_404($out), 'a missing slug is refused');

// Slug lookup itself must not answer an empty question. Multi option keys are
// read with isset(), which is false for NULL, so a null slug would drop the
// filter and leave an unfiltered query whose first row gets returned — a
// lookup for nothing answering with somebody else's event.
check(Event::get_by_link(null) === false,
	'looking up a null slug finds nothing rather than an arbitrary event');
check(Event::get_by_link('') === false,
	'looking up an empty slug finds nothing');
check(Event::get_by_link('no-such-event-slug-xyz') === false,
	'looking up an unknown slug finds nothing');
$found = Event::get_by_link($one_off_slug);
check($found && (int)$found->key === (int)$one_off->key,
	'looking up a real slug finds that event');

// A bare recurring slug hands out the next upcoming occurrence, never the
// parent template — the template's date is the pattern start, which may be long
// past, and it is not an event anyone can attend.
$out = ics_fetch('event', $series_slug);
check(ics_is_calendar($out), 'a bare recurring slug is served');
check(strpos($out, 'DTSTART') !== false, 'the served entry carries a start time');

// Private events are not public calendar data.
$private = ics_make_event('IcsPrivate', date('Y-m-d', strtotime('+40 days')) . ' 09:00:00',
	array('evt_visibility' => 0));
$out = ics_fetch('event', $private->get('evt_link'));
check(ics_is_404($out), 'a non-public event is not served as a calendar entry');

// A series whose occurrences are all in the past has nothing to hand out.
$finished = ics_make_event('IcsFinished', '2020-03-02 18:00:00', array(
	'evt_recurrence_type' => 'weekly',
	'evt_recurrence_interval' => 1,
	'evt_recurrence_end_date' => '2020-04-30',
));
$out = ics_fetch('event', $finished->get('evt_link'));
check(ics_is_404($out),
	'a finished series is refused rather than serving the parent template');

// ---------------------------------------------------------------------------
section('The calendar feed');

$feed = ics_fetch('calendar');
check(ics_is_calendar($feed), 'the calendar feed is served');
check(strpos($feed, 'END:VCALENDAR') !== false, 'the feed is closed properly');

$one_off_uid_count = substr_count($feed, 'UID:');
check($one_off_uid_count > 0, 'the feed carries at least one entry',
	'entries: ' . $one_off_uid_count);
check(strpos($feed, $one_off->get('evt_name')) !== false,
	'an upcoming public event appears in the feed');
check(strpos($feed, $private->get('evt_name')) === false,
	'a non-public event does not appear in the feed');
check(strpos($feed, $finished->get('evt_name')) === false,
	'a finished series does not appear in the feed');

// The parent template must not be emitted alongside its own instances.
$name_hits = substr_count($feed, $series->get('evt_name'));
check($name_hits > 0, 'the recurring series contributes instances to the feed',
	'hits: ' . $name_hits);

// Every UID in the feed must be distinct, or calendar clients collapse separate
// occurrences into one entry and silently drop the rest.
preg_match_all('/^UID:(.*)$/m', $feed, $m);
$uids = array_map('trim', $m[1]);
check(count($uids) === count(array_unique($uids)),
	'every entry in the feed has a distinct UID',
	'entries: ' . count($uids) . ' distinct: ' . count(array_unique($uids)));

// ---------------------------------------------------------------------------
section('ICS content');

$virtual = $series->create_virtual_instance($next_monday);
$vevent_virtual = IcsHelper::generateVevent($virtual, $next_monday);
$vevent_real = IcsHelper::generateVevent($one_off);

check(strpos($vevent_virtual, 'BEGIN:VEVENT') !== false,
	'a virtual instance produces an entry');
check(strpos($vevent_real, 'BEGIN:VEVENT') !== false,
	'a stored event produces an entry');

// The UID identifies the occurrence, not the series, or a client shows one
// entry for the whole series.
preg_match('/UID:(.*)/', $vevent_virtual, $mv);
preg_match('/UID:(.*)/', IcsHelper::generateVevent($series->create_virtual_instance(
	date('Y-m-d', strtotime($next_monday . ' +7 days'))), date('Y-m-d', strtotime($next_monday . ' +7 days'))), $mv2);
check(trim($mv[1]) !== trim($mv2[1]),
	'two occurrences of one series get different UIDs',
	trim($mv[1]) . ' vs ' . trim($mv2[1]));

// Times are published as UTC, which is what the Z suffix asserts.
check(preg_match('/DTSTART:\d{8}T\d{6}Z/', $vevent_virtual) === 1,
	'the start time is published as UTC',
	$vevent_virtual);
check(preg_match('/DTEND:\d{8}T\d{6}Z/', $vevent_virtual) === 1,
	'the end time is published as UTC');

// RFC 5545 reserves comma, semicolon and backslash inside text values; an
// unescaped one truncates the field and corrupts the rest of the entry.
$tricky = ics_make_event('IcsEscape, semi; back\\slash',
	date('Y-m-d', strtotime('+41 days')) . ' 09:00:00');
$vevent_tricky = IcsHelper::generateVevent($tricky);
preg_match('/SUMMARY:(.*)/', $vevent_tricky, $ms);
$summary = isset($ms[1]) ? $ms[1] : '';
check(strpos($summary, '\\,') !== false, 'a comma in the title is escaped', $summary);
check(strpos($summary, '\\;') !== false, 'a semicolon in the title is escaped', $summary);
check(preg_match('/[^\\\\],[^\\\\]/', $summary) === 0,
	'no bare comma survives into the entry', $summary);

$wrapped = IcsHelper::wrapInVcalendar($vevent_real);
check(strpos($wrapped, 'BEGIN:VCALENDAR') === 0, 'a wrapped calendar starts correctly');
check(substr(rtrim($wrapped), -13) === 'END:VCALENDAR', 'a wrapped calendar ends correctly',
	'tail: ' . var_export(substr(rtrim($wrapped), -20), true));
check(strpos($wrapped, 'VERSION:2.0') !== false, 'the calendar declares its version');

harness_finish();
