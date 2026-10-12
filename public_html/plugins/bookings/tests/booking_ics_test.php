<?php
/** @joinery-test
 * name: booking_ics
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * The invite attached to a booking confirmation (booking_build_ics) is one VEVENT
 * written by IcsHelper, so it escapes its text, folds its lines, and carries the UID
 * booking-{id}@{site host}: the form a copy of the booking on the host's own
 * calendar is recognised by (CalendarItemSourceRegistry drops the duplicate by the
 * source_key bookings:bkn-{id}; the UID is how an outside calendar names the same one).
 *
 * Run: php plugins/bookings/tests/booking_ics_test.php
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/bookings/logic/book_logic.php'));

/** A stand-in with the two methods the invite reads. */
class BookingIcsRow {
	public $key;
	private $v;
	function __construct($key, array $v) { $this->key = $key; $this->v = $v; }
	function get($f) { return $this->v[$f] ?? null; }
}

$settings = Globalvars::get_instance();
$host = rtrim(preg_replace('#^https?://#', '', (string)($settings->get_setting('webDir') ?: ($_SERVER['HTTP_HOST'] ?? ''))), '/');

section('The booking invite');
$booking = new BookingIcsRow(12, array('bkn_start_time' => '2026-10-12 14:30:00', 'bkn_end_time' => '2026-10-12 15:00:00'));
$type = new BookingIcsRow(3, array('bty_name' => 'Intro call, with Sam; first visit, and a very long title so that the line has to fold somewhere past the limit',
	'bty_location_details' => "Room 4, Main St;\nsecond floor"));
$ics = booking_build_ics($booking, $type, null);

check(strpos($ics, 'UID:booking-12@' . $host) !== false, 'the UID is booking-{id}@{site host}, not @joinery', $ics);
check(strpos($ics, '@joinery') === false, 'and nothing says @joinery');
check(strpos($ics, 'DTSTART:20261012T143000Z') !== false && strpos($ics, 'DTEND:20261012T150000Z') !== false, 'the times are the booking\'s, in UTC');
check(strpos($ics, 'SUMMARY:Intro call\\, with Sam\; first visit') !== false, 'the title is escaped');
check(strpos($ics, 'LOCATION:Room 4\\, Main St\;\\nsecond floor') !== false, 'a location with a newline is escaped, not flattened');
check(strpos($ics, "BEGIN:VCALENDAR\r\n") === 0 && substr($ics, -strlen("END:VCALENDAR")) === 'END:VCALENDAR', 'it is a complete calendar');
check(array_filter(explode("\r\n", $ics), function ($l) { return strlen($l) > 75; }) === array(), 'no line is over 75 octets');

$no_place = booking_build_ics($booking, new BookingIcsRow(3, array('bty_name' => 'Plain call')), null);
check(strpos($no_place, 'LOCATION') === false, 'a booking type with no location writes none');

harness_finish();
