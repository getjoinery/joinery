<?php
/** @joinery-test
 * name: booking_flow
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The public booking flow: picking a time, holding it, and changing it later.
 *
 * A booking system has one property everything else hangs off — a time that is
 * taken must stop being offered, to everyone, immediately. Three separate
 * mechanisms have to agree for that to hold: BookingItemSource has to project
 * the new row into the host's busy blocks, SlotGenerator has to subtract those
 * blocks from the working hours, and book_logic has to re-check the slot inside
 * its advisory lock rather than trusting the slot the browser posted. Any one
 * of them failing quietly produces a double booking that nobody notices until
 * two people show up. Each is pinned here through the real flow rather than in
 * isolation, because the failure mode is the seam between them.
 *
 * The second property is that the invitee's emailed link is a credential. There
 * is no login on the manage page: whoever holds bkn_action_token can cancel or
 * reschedule. So the token has to be unguessable, has to resolve to exactly one
 * booking, and must not act on any other.
 *
 * Times are the third area. Everything is stored UTC and rendered in somebody's
 * zone — the host's for caps and their calendar, the invitee's for the times on
 * their confirmation. The provider is exercised across a DST boundary here (the
 * pure generator has its own suite); what this adds is that the working hours
 * an admin typed as wall-clock still mean 9am local on both sides of the change.
 *
 * Sections: resolving the page; what the form refuses; a taken slot disappears;
 * the double-booking guard; times in two zones; per-period caps; intake
 * questions; and the manage link.
 *
 * Run: php plugins/bookings/tests/booking_flow_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/logic.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/schedule_class.php'));
require_once(PathHelper::getIncludePath('data/schedule_window_class.php'));
require_once(PathHelper::getIncludePath('data/surveys_class.php'));
require_once(PathHelper::getIncludePath('data/questions_class.php'));
require_once(PathHelper::getIncludePath('data/survey_questions_class.php'));
require_once(PathHelper::getIncludePath('data/survey_answers_class.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
require_once(PathHelper::getIncludePath('plugins/bookings/data/booking_types_class.php'));
require_once(PathHelper::getIncludePath('plugins/bookings/data/bookings_class.php'));
require_once(PathHelper::getIncludePath('plugins/bookings/includes/SchedulingProviderRegistry.php'));

if (session_id() === '') { @session_start(); }
if (!isset($_SERVER['REQUEST_URI'])) { $_SERVER['REQUEST_URI'] = '/'; }

$db = DbConnector::get_instance()->get_db_link();
$RUN = bin2hex(random_bytes(4));

// Rows this suite creates indirectly — invitees and bookings are made by the
// logic under test, not by the fixture builders, so they are swept by owner at
// the end rather than registered one at a time.
$BK_TYPE_IDS = array();
$BK_HOST_IDS = array();

// --- Fixture builders -------------------------------------------------------

function bk_host($suffix, $tz = 'UTC') {
	global $BK_HOST_IDS;
	$host = make_user('Bk' . $suffix, 5);
	$host->set('usr_timezone', $tz);
	$host->save();
	$host->load();
	$BK_HOST_IDS[] = $host->key;
	return $host;
}

function bk_schedule($host, $tz = 'UTC') {
	$s = new Schedule(NULL);
	$s->set('sch_subject_type', 'user');
	$s->set('sch_subject_id', $host->key);
	$s->set('sch_timezone', $tz);
	$s->save();
	$s->load();
	harness_register_row('sch_schedules', 'sch_schedule_id', $s->key);
	return $s;
}

function bk_window($schedule, $day_of_week, $start = '09:00:00', $end = '12:00:00') {
	$w = new ScheduleWindow(NULL);
	$w->set('scw_sch_schedule_id', $schedule->key);
	$w->set('scw_day_of_week', $day_of_week);
	$w->set('scw_start_time', $start);
	$w->set('scw_end_time', $end);
	$w->save();
	$w->load();
	harness_register_row('scw_schedule_windows', 'scw_schedule_window_id', $w->key);
	return $w;
}

/** An active native booking type on $host, emails suppressed. */
function bk_type($host, array $overrides = array()) {
	global $BK_TYPE_IDS, $RUN;
	static $n = 0;
	$n++;
	$t = new BookingType(NULL);
	$t->set('bkt_usr_user_id', $host->key);
	$t->set('bkt_name', 'HarnessTest Booking ' . $n);
	$t->set('bkt_slug', 'harnesstest-' . $RUN . '-' . $n);
	$t->set('bkt_status', BookingType::BOOKING_STATUS_ACTIVE);
	$t->set('bkt_duration_minutes', 60);
	$t->set('bkt_slot_increment_minutes', 60);
	$t->set('bkt_min_notice_minutes', 0);
	$t->set('bkt_rolling_days', 300);
	// Sending is a side effect of a successful booking, not part of what this
	// suite asserts; leaving it on would put real mail in the queue on every run.
	$t->set('bkt_send_native_emails', false);
	foreach ($overrides as $k => $v) { $t->set($k, $v); }
	$t->save();
	$t->load();
	harness_register_row('bkt_booking_types', 'bkt_booking_type_id', $t->key);
	$BK_TYPE_IDS[] = $t->key;
	return $t;
}

/** A date $days_ahead from now, plus its day-of-week, for windowing a schedule. */
function bk_future_day($days_ahead = 10) {
	$date = gmdate('Y-m-d', strtotime('+' . $days_ahead . ' days'));
	return array($date, (int)gmdate('w', strtotime($date)));
}

/** Open slot start times for a type on one UTC day. */
function bk_slots($type, $day) {
	$provider = SchedulingProviderRegistry::get($type->get('bkt_provider'));
	$slots = $provider->getAvailableSlots($type, $day . ' 00:00:00', $day . ' 23:59:59');
	return array_column($slots, 'start');
}

/** Submit the public booking form. Returns the LogicResult. */
function bk_submit($slug, $slot_start, array $extra = array()) {
	global $RUN;
	static $n = 0;
	$n++;
	$data = array_merge(array(
		'slug'             => $slug,
		'book_submit'      => '1',
		'slot_start'       => $slot_start,
		'invitee_name'     => 'Harness Invitee' . $n,
		'invitee_email'    => 'bkflow_' . $RUN . '_' . $n . '@getjoinery.com',
		'invitee_notes'    => '',
		'invitee_timezone' => 'UTC',
	), $extra);
	return harness_call_logic('plugins/bookings/logic/book_logic.php', 'book_logic', $data, 'POST');
}

/** Live (non-canceled, non-deleted) booking rows for a type. */
function bk_rows($type) {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare('SELECT * FROM bkn_bookings WHERE bkn_bkt_booking_type_id = ? AND bkn_delete_time IS NULL ORDER BY bkn_booking_id');
	$q->execute(array($type->key));
	return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** The booking a submission created, resolved through its confirmation redirect. */
function bk_from_redirect($result) {
	if (!$result->redirect || strpos($result->redirect, 'confirmed=') === false) { return false; }
	$token = substr($result->redirect, strpos($result->redirect, 'confirmed=') + 10);
	return Booking::GetByToken($token);
}

function bk_errors($result) {
	return isset($result->data['errors']) ? $result->data['errors'] : array();
}

/** Sign the CLI session in as $user, or out when passed null. */
function bk_signin($user) {
	if ($user === null) {
		unset($_SESSION['usr_user_id'], $_SESSION['loggedin'], $_SESSION['permission']);
		return;
	}
	$_SESSION['usr_user_id'] = $user->key;
	$_SESSION['loggedin'] = TRUE;
	$_SESSION['permission'] = (int)$user->get('usr_permission');
}


// ============================================================================
section('Resolving the public booking page');

$host = bk_host('Host', 'UTC');
$sched = bk_schedule($host, 'UTC');
list($day, $dow) = bk_future_day(10);
bk_window($sched, $dow, '09:00:00', '12:00:00');
$type = bk_type($host);

$res = harness_call_logic('plugins/bookings/logic/book_logic.php', 'book_logic', array('slug' => $type->get('bkt_slug')), 'GET');
check(!isset($res->data['is_valid_page']) || $res->data['is_valid_page'] !== false,
	'An active slug renders the booking page');
check(isset($res->data['type']) && $res->data['type'] && $res->data['type']->key == $type->key,
	'The page resolves the booking type from the slug');
check(isset($res->data['host']) && $res->data['host']->key == $host->key,
	'The page resolves the host whose schedule is being offered');

$res = harness_call_logic('plugins/bookings/logic/book_logic.php', 'book_logic', array('slug' => 'no-such-slug-' . $RUN), 'GET');
check(isset($res->data['is_valid_page']) && $res->data['is_valid_page'] === false,
	'An unknown slug is not a valid page');

$res = harness_call_logic('plugins/bookings/logic/book_logic.php', 'book_logic', array(), 'GET');
check(isset($res->data['is_valid_page']) && $res->data['is_valid_page'] === false,
	'No slug at all is not a valid page');

// A type an admin has switched off must stop taking bookings, not just stop
// being linked to — the URL stays valid and guessable after deactivation.
$off = bk_type($host, array('bkt_status' => BookingType::BOOKING_STATUS_INACTIVE));
$res = harness_call_logic('plugins/bookings/logic/book_logic.php', 'book_logic', array('slug' => $off->get('bkt_slug')), 'GET');
check(isset($res->data['is_valid_page']) && $res->data['is_valid_page'] === false,
	'An inactive booking type is not bookable through its URL');
$res = harness_call_logic('plugins/bookings/logic/booking_slots_logic.php', 'booking_slots_logic',
	array('slug' => $off->get('bkt_slug'), 'start' => $day . ' 00:00:00', 'end' => $day . ' 23:59:59'), 'GET');
check(isset($res->data['slots']) && count($res->data['slots']) === 0,
	'The public slot endpoint offers nothing for an inactive type');

$res = harness_call_logic('plugins/bookings/logic/booking_slots_logic.php', 'booking_slots_logic',
	array('slug' => $type->get('bkt_slug'), 'start' => $day . ' 00:00:00', 'end' => $day . ' 23:59:59'), 'GET');
check(isset($res->data['slots']) && count($res->data['slots']) === 3,
	'The public slot endpoint offers the three open hours', json_encode(array_column($res->data['slots'], 'start')));

// The endpoint is called by public, possibly cached, pages: a caller supplying
// nonsense must get an empty list rather than an error page or a stack trace.
$res = harness_call_logic('plugins/bookings/logic/booking_slots_logic.php', 'booking_slots_logic',
	array('slug' => $type->get('bkt_slug'), 'start' => 'not-a-date', 'end' => 'also-not'), 'GET');
check(isset($res->data['slots']) && count($res->data['slots']) === 0,
	'A malformed range yields no slots rather than an error');

// Switching the whole subsystem off closes the public door everywhere.
harness_set_setting_mem('bookings_active', '0');
$res = harness_call_logic('plugins/bookings/logic/book_logic.php', 'book_logic', array('slug' => $type->get('bkt_slug')), 'GET');
check(isset($res->data['is_valid_page']) && $res->data['is_valid_page'] === false,
	'With bookings switched off, no booking page is valid');
$res = harness_call_logic('plugins/bookings/logic/booking_slots_logic.php', 'booking_slots_logic',
	array('slug' => $type->get('bkt_slug'), 'start' => $day . ' 00:00:00', 'end' => $day . ' 23:59:59'), 'GET');
check(isset($res->data['slots']) && count($res->data['slots']) === 0,
	'With bookings switched off, the slot endpoint offers nothing');
harness_set_setting_mem('bookings_active', '1');


// ============================================================================
section('What the booking form refuses');

$slots = bk_slots($type, $day);
check(count($slots) === 3, 'A 9-12 window at 60-minute slots offers three times', json_encode($slots));
check($slots[0] === $day . ' 09:00:00', 'The first slot is the start of the window', $slots[0]);

$before = count(bk_rows($type));

$res = bk_submit($type->get('bkt_slug'), 'tomorrow at noon');
check(in_array('Please pick a time.', bk_errors($res)), 'A slot that is not a UTC timestamp is refused');
check(!$res->redirect, 'A refused submission does not redirect to the confirmation');

$res = bk_submit($type->get('bkt_slug'), $slots[0], array('invitee_name' => '   '));
check(in_array('Please enter your name.', bk_errors($res)), 'A blank name is refused');

$res = bk_submit($type->get('bkt_slug'), $slots[0], array('invitee_email' => 'not-an-email'));
check(in_array('Please enter a valid email.', bk_errors($res)), 'An unparseable email is refused');

// Being handed the form back with empty fields is the difference between a
// typo and starting over, so the accepted values have to survive the round trip.
$res = bk_submit($type->get('bkt_slug'), $slots[0], array(
	'invitee_email' => 'not-an-email', 'invitee_name' => 'Jo Smith', 'invitee_notes' => 'about the roof',
));
check(isset($res->data['old']['name']) && $res->data['old']['name'] === 'Jo Smith',
	'The name is handed back with the errors');
check(isset($res->data['old']['notes']) && $res->data['old']['notes'] === 'about the roof',
	'The notes are handed back with the errors');

check(count(bk_rows($type)) === $before, 'None of the refused submissions created a booking');
check(count(bk_slots($type, $day)) === 3, 'None of the refused submissions consumed a slot');

// A slot that parses but is not on offer — outside the working hours — must be
// refused by the server. The picker only ever shows real slots, so anything
// else arrives from a hand-made post.
$res = bk_submit($type->get('bkt_slug'), $day . ' 22:00:00');
check(bk_errors($res) && strpos(implode(' ', bk_errors($res)), 'just taken') !== false,
	'A time outside the host working hours is refused', json_encode(bk_errors($res)));
check(count(bk_rows($type)) === $before, 'A time outside working hours created no booking');

// Same test one step subtler: a well-formed time inside the window but off the
// increment grid (09:30 when slots start on the hour) is not a slot either.
$res = bk_submit($type->get('bkt_slug'), $day . ' 09:30:00');
check(count(bk_rows($type)) === $before, 'A time off the increment grid created no booking');


// ============================================================================
section('A taken slot stops being offered');

$res = bk_submit($type->get('bkt_slug'), $day . ' 10:00:00', array('invitee_notes' => 'first booking'));
check(!bk_errors($res), 'A valid submission is accepted', json_encode(bk_errors($res)));
check($res->redirect && strpos($res->redirect, 'confirmed=') !== false,
	'A booking redirects to its confirmation', (string)$res->redirect);

$booking = bk_from_redirect($res);
check($booking && $booking->key, 'The confirmation token resolves to the new booking');
check((int)$booking->get('bkn_status') === Booking::BOOKING_STATUS_BOOKED,
	'The new booking is BOOKED, not a pending hold');
check($booking->get('bkn_start_time') === $day . ' 10:00:00', 'It holds the time that was picked',
	(string)$booking->get('bkn_start_time'));
check($booking->get('bkn_end_time') === $day . ' 11:00:00',
	'Its end is the start plus the type duration', (string)$booking->get('bkn_end_time'));
check((int)$booking->get('bkn_usr_user_id_booked') === (int)$host->key, 'The host is recorded as the booked party');

$after = bk_slots($type, $day);
check(!in_array($day . ' 10:00:00', $after), 'The booked hour is no longer offered', json_encode($after));
check(count($after) === 2, 'The other two hours are still offered', json_encode($after));

// The invitee is matched or created from the email — a booker with no account
// still has to become a real user for the booking to point at somebody.
$client = new User($booking->get('bkn_usr_user_id_client'), TRUE);
check($client->key, 'The booking points at an invitee user record');
check($client->get('usr_email') !== '', 'The invitee record carries the email that was booked with');

// Booking again with the same email must reuse that record rather than pile up
// a new half-populated user on every appointment.
$repeat_email = $client->get('usr_email');
$res2 = bk_submit($type->get('bkt_slug'), $day . ' 11:00:00', array('invitee_email' => $repeat_email));
check(!bk_errors($res2), 'A second booking by the same person is accepted', json_encode(bk_errors($res2)));
$booking2 = bk_from_redirect($res2);
check($booking2 && (int)$booking2->get('bkn_usr_user_id_client') === (int)$client->key,
	'The returning invitee is matched to their existing record, not duplicated');
check($booking2->get('bkn_action_token') !== $booking->get('bkn_action_token'),
	'Each booking gets its own action token');
check(strlen((string)$booking->get('bkn_action_token')) >= 40,
	'The action token is long enough to be unguessable', strlen((string)$booking->get('bkn_action_token')) . ' chars');

// Cancelling has to give the time back — a canceled booking that keeps blocking
// its slot silently removes availability the host never gets back.
$booking2->set('bkn_status', Booking::BOOKING_STATUS_CANCELED);
$booking2->save();
$freed = bk_slots($type, $day);
check(in_array($day . ' 11:00:00', $freed), 'A canceled booking releases its time', json_encode($freed));
check(!in_array($day . ' 10:00:00', $freed), 'The still-live booking keeps holding its time');


// ============================================================================
section('The double-booking guard');

// The picker's slot list can be minutes old by the time the form is posted, so
// the slot the browser sends is a claim, not a fact. book_logic re-checks it
// inside a per-host advisory lock; without that re-check, two people who loaded
// the page together both get a confirmation for the same hour.
$host_d = bk_host('Dbl', 'UTC');
$sched_d = bk_schedule($host_d, 'UTC');
list($day_d, $dow_d) = bk_future_day(11);
bk_window($sched_d, $dow_d, '09:00:00', '11:00:00');
$type_d = bk_type($host_d);

$slots_d = bk_slots($type_d, $day_d);
check(count($slots_d) === 2, 'The contested day starts with two open hours', json_encode($slots_d));

$first = bk_submit($type_d->get('bkt_slug'), $day_d . ' 09:00:00');
check(!bk_errors($first), 'The first booker gets the slot', json_encode(bk_errors($first)));

$loser_email = 'bkflow_loser_' . $RUN . '@getjoinery.com';
$second = bk_submit($type_d->get('bkt_slug'), $day_d . ' 09:00:00', array('invitee_email' => $loser_email));
check(bk_errors($second), 'The second booker for the same hour is refused');
check(strpos(implode(' ', bk_errors($second)), 'just taken') !== false,
	'The refusal tells them the time was taken', json_encode(bk_errors($second)));
check(!$second->redirect, 'The losing booker is not sent to a confirmation page');

$rows = bk_rows($type_d);
check(count($rows) === 1, 'Exactly one booking exists for the contested hour', count($rows) . ' rows');

// A lost race must not mint a user: the invitee is matched-or-created only
// after the slot is confirmed free, inside the booking transaction, so the
// loser's email leaves no usr_users row behind.
$loser_q = DbConnector::get_instance()->get_db_link()->prepare(
	'SELECT usr_user_id FROM usr_users WHERE usr_email = ?');
$loser_q->execute(array($loser_email));
$loser_row = $loser_q->fetchColumn();
check($loser_row === false, 'The losing booker leaves no user row behind',
	$loser_row ? 'user id ' . $loser_row : '');
if ($loser_row) { harness_register_row('usr_users', 'usr_user_id', $loser_row); }

// The loser must still be able to take a different time — a conflict is not a
// dead end, and the failed attempt must not have consumed anything.
$third = bk_submit($type_d->get('bkt_slug'), $day_d . ' 10:00:00');
check(!bk_errors($third), 'The other hour is still bookable after the conflict', json_encode(bk_errors($third)));
check(count(bk_rows($type_d)) === 2, 'Two bookings now exist, one per hour');
check(count(bk_slots($type_d, $day_d)) === 0, 'With both hours taken, nothing is offered');

// A second booking type sharing the host must respect the same busy time: the
// host is one person, so a booking made through one type blocks the other.
$type_d2 = bk_type($host_d);
check(count(bk_slots($type_d2, $day_d)) === 0,
	'A different booking type on the same host sees the host as busy');


// ============================================================================
section('Times are stored in both zones');

// The host types working hours as wall-clock ("9am"), so what a booking becomes
// in UTC depends on the date, not on a fixed offset. Two dates are chosen by
// their actual New York offset so the assertion can name the UTC hour outright
// instead of recomputing it with the same conversion the code uses.
function bk_first_date_with_offset($tz, $wanted_offset_hours, $max_days = 280) {
	for ($d = 8; $d <= $max_days; $d++) {
		$date = gmdate('Y-m-d', strtotime('+' . $d . ' days'));
		$dtz = new DateTimeZone($tz);
		$off = $dtz->getOffset(new DateTime($date . ' 12:00:00', $dtz)) / 3600;
		if ((int)$off === $wanted_offset_hours) { return $date; }
	}
	return null;
}

$edt_date = bk_first_date_with_offset('America/New_York', -4);
$est_date = bk_first_date_with_offset('America/New_York', -5);
check($edt_date !== null, 'Found a date on New York summer time to test against', (string)$edt_date);
check($est_date !== null, 'Found a date on New York winter time to test against', (string)$est_date);

$host_tz = bk_host('Tz', 'America/New_York');
$sched_tz = bk_schedule($host_tz, 'America/New_York');
$type_tz = bk_type($host_tz);

if ($edt_date && $est_date) {
	bk_window($sched_tz, (int)gmdate('w', strtotime($edt_date)), '09:00:00', '10:00:00');
	$edt_slots = bk_slots($type_tz, $edt_date);
	check(in_array($edt_date . ' 13:00:00', $edt_slots),
		'9am New York on summer time is 13:00 UTC', json_encode($edt_slots));

	bk_window($sched_tz, (int)gmdate('w', strtotime($est_date)), '09:00:00', '10:00:00');
	$est_slots = bk_slots($type_tz, $est_date);
	check(in_array($est_date . ' 14:00:00', $est_slots),
		'The same 9am on winter time is 14:00 UTC', json_encode($est_slots));
	check($edt_date . ' 13:00:00' !== $est_date . ' 14:00:00',
		'The two sides of the change produce different UTC hours for one wall-clock time');

	// The invitee sees their own zone on the confirmation and the calendar
	// invite, so their local rendering is stored alongside the UTC instant.
	$res = bk_submit($type_tz->get('bkt_slug'), $edt_date . ' 13:00:00', array('invitee_timezone' => 'Asia/Tokyo'));
	check(!bk_errors($res), 'A booking across zones is accepted', json_encode(bk_errors($res)));
	$btz = bk_from_redirect($res);
	check($btz && $btz->get('bkn_invitee_timezone') === 'Asia/Tokyo', 'The invitee timezone is recorded');
	// 13:00 UTC is 22:00 the same day in Tokyo (UTC+9, no DST).
	check($btz && substr((string)$btz->get('bkn_start_time_local'), 11, 5) === '22:00',
		'The invitee local start is their own wall-clock time',
		(string)($btz ? $btz->get('bkn_start_time_local') : ''));
	check($btz && substr((string)$btz->get('bkn_end_time_local'), 11, 5) === '23:00',
		'The invitee local end follows the same conversion',
		(string)($btz ? $btz->get('bkn_end_time_local') : ''));
	check($btz && $btz->get('bkn_start_time') === $edt_date . ' 13:00:00',
		'The stored UTC instant is untouched by the invitee zone');
} else {
	harness_skip('New York DST comparisons', 'could not locate both offsets in the search window');
}


// ============================================================================
section('Per-period booking caps');

// A cap is a promise about the host's day, not about one booking type's day.
// It is counted in the host's zone because "two a day" means their day.
$host_c = bk_host('Cap', 'UTC');
$sched_c = bk_schedule($host_c, 'UTC');
list($day_c, $dow_c) = bk_future_day(12);
bk_window($sched_c, $dow_c, '09:00:00', '13:00:00');
$type_c = bk_type($host_c, array('bkt_max_per_day' => 2));

check(count(bk_slots($type_c, $day_c)) === 4, 'The capped day starts with four open hours');

$r1 = bk_submit($type_c->get('bkt_slug'), $day_c . ' 09:00:00');
check(!bk_errors($r1), 'The first booking under the cap is accepted', json_encode(bk_errors($r1)));
check(count(bk_slots($type_c, $day_c)) === 3, 'One booking leaves three hours (cap not yet reached)');

$r2 = bk_submit($type_c->get('bkt_slug'), $day_c . ' 10:00:00');
check(!bk_errors($r2), 'The second booking reaches the cap', json_encode(bk_errors($r2)));
check(count(bk_slots($type_c, $day_c)) === 0,
	'At the cap the rest of the day stops being offered, not just the booked hours',
	json_encode(bk_slots($type_c, $day_c)));

// A cap that only hides slots but still accepts a posted one is not a cap.
$r3 = bk_submit($type_c->get('bkt_slug'), $day_c . ' 11:00:00');
check(bk_errors($r3), 'A posted time past the daily cap is refused', json_encode(bk_errors($r3)));
check(count(bk_rows($type_c)) === 2, 'The cap holds at two bookings for the day');

// The cap is per period, so the next week is unaffected.
$day_c_next = gmdate('Y-m-d', strtotime($day_c . ' +7 days'));
check(count(bk_slots($type_c, $day_c_next)) === 4, 'The following week opens fresh under a daily cap');

// A live paid hold counts toward the cap exactly like a confirmation — slot
// availability and the cap counter answer through one predicate
// (Booking::occupies_host_time), so they cannot disagree about what a hold
// is. An expired hold counts for neither.
$host_h = bk_host('HoldCap', 'UTC');
$sched_h = bk_schedule($host_h, 'UTC');
list($day_h, $dow_h) = bk_future_day(19);
bk_window($sched_h, $dow_h, '09:00:00', '13:00:00');
$type_h = bk_type($host_h, array('bkt_max_per_day' => 1));

check(count(bk_slots($type_h, $day_h)) === 4, 'The hold-cap day starts with four open hours');

$holder = make_user('BkHoldCap');
$hold = new Booking(NULL);
$hold->set('bkn_provider', 'native');
$hold->set('bkn_bkt_booking_type_id', $type_h->key);
$hold->set('bkn_usr_user_id_booked', $host_h->key);
$hold->set('bkn_usr_user_id_client', $holder->key);
$hold->set('bkn_start_time', $day_h . ' 09:00:00');
$hold->set('bkn_end_time', $day_h . ' 10:00:00');
$hold->set('bkn_status', Booking::BOOKING_STATUS_CREATED);
$hold->set('bkn_hold_expires_time', gmdate('Y-m-d H:i:s', time() + 900));
$hold->set('bkn_action_token', Booking::make_action_token());
$hold->save();
harness_register_row('bkn_bookings', 'bkn_booking_id', $hold->key);

check(count(bk_slots($type_h, $day_h)) === 0,
	'A live paid hold consumes the daily cap, not just its own hour',
	json_encode(bk_slots($type_h, $day_h)));

$hold->set('bkn_hold_expires_time', gmdate('Y-m-d H:i:s', time() - 60));
$hold->save();
check(count(bk_slots($type_h, $day_h)) === 4,
	'An expired hold releases the cap along with its hour',
	json_encode(bk_slots($type_h, $day_h)));


// ============================================================================
section('Intake questions travel with the booking');

$host_q = bk_host('Intake', 'UTC');
$sched_q = bk_schedule($host_q, 'UTC');
list($day_q, $dow_q) = bk_future_day(13);
bk_window($sched_q, $dow_q, '09:00:00', '11:00:00');

$survey = new Survey(NULL);
$survey->set('svy_name', 'HarnessTest Intake ' . $RUN);
$survey->save();
$survey->load();
harness_register_row('svy_surveys', 'svy_survey_id', $survey->key);

$question = new Question(NULL);
$question->set('qst_question', 'What is this about?');
$question->set('qst_type', Question::TYPE_SHORT_TEXT);
$question->save();
$question->load();
harness_register_row('qst_questions', 'qst_question_id', $question->key);

$sq = new SurveyQuestion(NULL);
$sq->set('srq_svy_survey_id', $survey->key);
$sq->set('srq_qst_question_id', $question->key);
$sq->save();
$sq->load();
harness_register_row('srq_survey_questions', 'srq_survey_question_id', $sq->key);

$type_q = bk_type($host_q, array('bkt_svy_survey_id' => $survey->key));
$res = bk_submit($type_q->get('bkt_slug'), $day_q . ' 09:00:00', array(
	'question_' . $question->key => 'A leaking gutter',
));
check(!bk_errors($res), 'A booking with intake answers is accepted', json_encode(bk_errors($res)));
$bq = bk_from_redirect($res);

$ans = $db->prepare('SELECT sva_answer, sva_usr_user_id FROM sva_survey_answers WHERE sva_svy_survey_id = ? AND sva_qst_question_id = ?');
$ans->execute(array($survey->key, $question->key));
$ans_rows = $ans->fetchAll(PDO::FETCH_ASSOC);
check(count($ans_rows) === 1, 'The intake answer is stored once', count($ans_rows) . ' rows');
check($ans_rows && $ans_rows[0]['sva_answer'] === 'A leaking gutter', 'The answer text is what was submitted');
check($bq && $ans_rows && (int)$ans_rows[0]['sva_usr_user_id'] === (int)$bq->get('bkn_usr_user_id_client'),
	'The answer is filed against the invitee, not the host');
foreach ($ans_rows as $r) {
	$db->prepare('DELETE FROM sva_survey_answers WHERE sva_svy_survey_id = ? AND sva_qst_question_id = ?')
		->execute(array($survey->key, $question->key));
}

// An unanswered intake question must not store an empty row that later reads
// as "they said nothing" rather than "they were never asked".
$res = bk_submit($type_q->get('bkt_slug'), $day_q . ' 10:00:00', array('question_' . $question->key => ''));
$ans->execute(array($survey->key, $question->key));
check(count($ans->fetchAll(PDO::FETCH_ASSOC)) === 0, 'A blank intake answer stores nothing');


// ============================================================================
section('The manage link is the invitee credential');

$host_m = bk_host('Manage', 'UTC');
$sched_m = bk_schedule($host_m, 'UTC');
list($day_m, $dow_m) = bk_future_day(14);
bk_window($sched_m, $dow_m, '09:00:00', '13:00:00');
$type_m = bk_type($host_m, array('bkt_cancel_notice_minutes' => 0));

$res = bk_submit($type_m->get('bkt_slug'), $day_m . ' 09:00:00');
$bm = bk_from_redirect($res);
check($bm && $bm->key, 'A booking to manage was created');
$token = $bm->get('bkn_action_token');

$res = harness_call_logic('plugins/bookings/logic/booking_manage_logic.php', 'booking_manage_logic',
	array('token' => $token), 'GET');
check(isset($res->data['booking']) && $res->data['booking'] && $res->data['booking']->key == $bm->key,
	'The token opens its own booking');

// The token is the whole credential, so a wrong one must resolve to nothing —
// not to the most recent booking, and not to a page that still offers actions.
$res = harness_call_logic('plugins/bookings/logic/booking_manage_logic.php', 'booking_manage_logic',
	array('token' => str_repeat('a', 40)), 'GET');
check(isset($res->data['is_valid_page']) && $res->data['is_valid_page'] === false,
	'A wrong token opens nothing');
$res = harness_call_logic('plugins/bookings/logic/booking_manage_logic.php', 'booking_manage_logic',
	array(), 'GET');
check(isset($res->data['is_valid_page']) && $res->data['is_valid_page'] === false,
	'No token opens nothing');
check(Booking::GetByToken('') === false, 'An empty token resolves to no booking');
check(Booking::GetByToken(null) === false, 'A null token resolves to no booking');

// Cancel through the link.
$res = harness_call_logic('plugins/bookings/logic/booking_manage_logic.php', 'booking_manage_logic',
	array('token' => $token, 'cancel_booking' => '1', 'cancel_reason' => 'schedule clash'), 'POST');
check($res->redirect && strpos($res->redirect, 'canceled=1') !== false,
	'Cancelling redirects to the confirmation', (string)$res->redirect);
$bm->load();
check((int)$bm->get('bkn_status') === Booking::BOOKING_STATUS_CANCELED, 'The booking is canceled');
check($bm->get('bkn_canceled_by') === 'invitee', 'The cancellation is attributed to the invitee');
check($bm->get('bkn_cancel_reason') === 'schedule clash', 'The reason is kept');
check(in_array($day_m . ' 09:00:00', bk_slots($type_m, $day_m)), 'The canceled hour is offered again');

// A booking that is already finished must not be cancelable a second time —
// the emailed link lives forever in an inbox and gets clicked again.
$res = harness_call_logic('plugins/bookings/logic/booking_manage_logic.php', 'booking_manage_logic',
	array('token' => $token), 'GET');
check(isset($res->data['already_done']) && $res->data['already_done'] === true,
	'A canceled booking is shown as already resolved');

// Reschedule moves the row rather than making a second one, so the old time
// comes back and the invitee keeps one booking, not two.
$res = bk_submit($type_m->get('bkt_slug'), $day_m . ' 10:00:00', array('invitee_timezone' => 'America/New_York'));
$br = bk_from_redirect($res);
check($br && $br->key, 'A booking to reschedule was created');
$rtoken = $br->get('bkn_action_token');
$rows_before = count(bk_rows($type_m));

$res = harness_call_logic('plugins/bookings/logic/booking_manage_logic.php', 'booking_manage_logic',
	array('token' => $rtoken, 'reschedule_booking' => '1', 'slot_start' => $day_m . ' 12:00:00'), 'POST');
check($res->redirect && strpos($res->redirect, 'rescheduled=1') !== false,
	'Rescheduling redirects to the confirmation', (string)$res->redirect);
$br->load();
check($br->get('bkn_start_time') === $day_m . ' 12:00:00', 'The booking now holds the new time',
	(string)$br->get('bkn_start_time'));
check($br->get('bkn_end_time') === $day_m . ' 13:00:00', 'The end moved with it');
check(count(bk_rows($type_m)) === $rows_before, 'Rescheduling did not create a second booking');
check($br->get('bkn_action_token') === $rtoken, 'The manage link still works after a reschedule');
$slots_m = bk_slots($type_m, $day_m);
check(in_array($day_m . ' 10:00:00', $slots_m), 'The old time is released', json_encode($slots_m));
check(!in_array($day_m . ' 12:00:00', $slots_m), 'The new time is held');
// The local rendering has to be recomputed, or the invitee is emailed the old
// wall-clock time for the new appointment.
check(substr((string)$br->get('bkn_start_time_local'), 11, 5) === '08:00',
	'The invitee local time was recomputed for the new slot',
	(string)$br->get('bkn_start_time_local'));

// A reschedule onto a taken time is the same race as a first booking.
$res = bk_submit($type_m->get('bkt_slug'), $day_m . ' 11:00:00');
check(!bk_errors($res), 'A second booking fills another hour', json_encode(bk_errors($res)));
$res = harness_call_logic('plugins/bookings/logic/booking_manage_logic.php', 'booking_manage_logic',
	array('token' => $rtoken, 'reschedule_booking' => '1', 'slot_start' => $day_m . ' 11:00:00'), 'POST');
check(bk_errors($res), 'Rescheduling onto a taken hour is refused', json_encode(bk_errors($res)));
$br->load();
check($br->get('bkn_start_time') === $day_m . ' 12:00:00', 'The refused reschedule left the booking where it was');

$res = harness_call_logic('plugins/bookings/logic/booking_manage_logic.php', 'booking_manage_logic',
	array('token' => $rtoken, 'reschedule_booking' => '1', 'slot_start' => 'sometime next week'), 'POST');
check(in_array('Please pick a new time.', bk_errors($res)), 'A malformed reschedule time is refused');


// ============================================================================
section('The cancellation notice window');

// The policy exists so a host is not stood up with an hour's warning. It has to
// bind the invitee's self-service actions, which are the only ones it applies to.
$host_n = bk_host('Notice', 'UTC');
$sched_n = bk_schedule($host_n, 'UTC');
list($day_n, $dow_n) = bk_future_day(15);
bk_window($sched_n, $dow_n, '09:00:00', '11:00:00');
// Wider than the distance to the booking, so every slot on that day is inside
// the window and the rule is guaranteed to bind.
$type_n = bk_type($host_n, array('bkt_cancel_notice_minutes' => 60 * 24 * 60));

$res = bk_submit($type_n->get('bkt_slug'), $day_n . ' 09:00:00');
$bn = bk_from_redirect($res);
check($bn && $bn->key, 'A booking inside the notice window was created');

$res = harness_call_logic('plugins/bookings/logic/booking_manage_logic.php', 'booking_manage_logic',
	array('token' => $bn->get('bkn_action_token'), 'cancel_booking' => '1'), 'POST');
check(bk_errors($res), 'Cancelling inside the notice window is refused', json_encode(bk_errors($res)));
check(!$res->redirect, 'The refused cancellation does not redirect');
$bn->load();
check((int)$bn->get('bkn_status') === Booking::BOOKING_STATUS_BOOKED, 'The booking is still live');

$res = harness_call_logic('plugins/bookings/logic/booking_manage_logic.php', 'booking_manage_logic',
	array('token' => $bn->get('bkn_action_token'), 'reschedule_booking' => '1', 'slot_start' => $day_n . ' 10:00:00'), 'POST');
check(bk_errors($res), 'Rescheduling inside the notice window is refused too');
$bn->load();
check($bn->get('bkn_start_time') === $day_n . ' 09:00:00', 'The booking did not move');

// The host is not bound by the invitee notice rule — they cancel their own day.
bk_signin($host_n);
$res = harness_call_logic('plugins/bookings/logic/my_bookings_logic.php', 'my_bookings_logic',
	array('cancel_booking' => '1', 'bkn_booking_id' => $bn->key, 'cancel_reason' => 'host is ill'), 'POST');
check($res->redirect && strpos($res->redirect, 'canceled=1') !== false, 'The host can cancel from their own list');
$bn->load();
check((int)$bn->get('bkn_status') === Booking::BOOKING_STATUS_CANCELED, 'The host cancellation took effect');
check($bn->get('bkn_canceled_by') === 'host', 'The cancellation is attributed to the host');

// Another host must not be able to cancel a booking that is not on their calendar.
$res = bk_submit($type_n->get('bkt_slug'), $day_n . ' 10:00:00');
$bn2 = bk_from_redirect($res);
check($bn2 && $bn2->key, 'A booking on the first host was created for the ownership check');
bk_signin($host);
$res = harness_call_logic('plugins/bookings/logic/my_bookings_logic.php', 'my_bookings_logic',
	array('cancel_booking' => '1', 'bkn_booking_id' => $bn2->key, 'cancel_reason' => 'not mine'), 'POST');
$bn2->load();
check((int)$bn2->get('bkn_status') === Booking::BOOKING_STATUS_BOOKED,
	'A different host cannot cancel someone else\'s booking');
check($bn2->get('bkn_canceled_by') === '' || $bn2->get('bkn_canceled_by') === NULL,
	'The other host is not recorded against it');

// The refusal must be reported as a refusal — a foreign or missing id gets
// the error flag, never the success banner.
check($res->redirect && strpos($res->redirect, 'cancel_error=1') !== false
	&& strpos($res->redirect, 'canceled=1') === false,
	'The refused cancellation reports an error, not success',
	'redirect: ' . var_export($res->redirect, true));
$res = harness_call_logic('plugins/bookings/logic/my_bookings_logic.php', 'my_bookings_logic',
	array('cancel_booking' => '1', 'bkn_booking_id' => 999999999), 'POST');
check($res->redirect && strpos($res->redirect, 'cancel_error=1') !== false,
	'Cancelling a nonexistent booking reports an error, not success',
	'redirect: ' . var_export($res->redirect, true));
bk_signin(null);


// ============================================================================
section('The bookable window');

// Working hours say which hours of a day are open; the window says which days
// are open at all. They are separate gates and a booking has to clear both —
// a host with Monday hours forever still should not be booked eleven months out.
$host_w = bk_host('Window', 'UTC');
$sched_w = bk_schedule($host_w, 'UTC');
for ($d = 0; $d < 7; $d++) { bk_window($sched_w, $d, '09:00:00', '12:00:00'); }

$in7 = gmdate('Y-m-d', strtotime('+7 days'));
$yesterday = gmdate('Y-m-d', strtotime('-1 day'));

// Rolling horizon: bookable from now out to N days, and no further.
$type_roll = bk_type($host_w, array('bkt_rolling_days' => 14));
check(count(bk_slots($type_roll, $in7)) === 3, 'A day inside the rolling horizon is open');
check(count(bk_slots($type_roll, gmdate('Y-m-d', strtotime('+20 days')))) === 0,
	'A day past the rolling horizon is closed');
check(count(bk_slots($type_roll, $yesterday)) === 0, 'A day in the past is closed');
$res = bk_submit($type_roll->get('bkt_slug'), $yesterday . ' 09:00:00');
check(bk_errors($res), 'A time in the past is refused, not just hidden', json_encode(bk_errors($res)));
$res = bk_submit($type_roll->get('bkt_slug'), gmdate('Y-m-d', strtotime('+20 days')) . ' 09:00:00');
check(bk_errors($res), 'A time past the horizon is refused, not just hidden', json_encode(bk_errors($res)));

// Fixed window: a type that only runs between two dates, e.g. a conference.
$w_start = gmdate('Y-m-d', strtotime('+10 days'));
$w_end   = gmdate('Y-m-d', strtotime('+12 days'));
$type_fix = bk_type($host_w, array('bkt_window_start' => $w_start, 'bkt_window_end' => $w_end));
check(count(bk_slots($type_fix, $in7)) === 0, 'Before the fixed window opens, nothing is offered');
check(count(bk_slots($type_fix, gmdate('Y-m-d', strtotime('+11 days')))) === 3,
	'Inside the fixed window the working hours apply as normal');
check(count(bk_slots($type_fix, $w_end)) === 3, 'The last day of the window is included, not cut off');
check(count(bk_slots($type_fix, gmdate('Y-m-d', strtotime('+13 days')))) === 0,
	'After the window closes, nothing is offered');

// Minimum notice: how much warning the host insists on.
$type_notice = bk_type($host_w, array('bkt_min_notice_minutes' => 60 * 24 * 3));
check(count(bk_slots($type_notice, gmdate('Y-m-d', strtotime('+1 day')))) === 0,
	'A day inside the minimum-notice period is closed');
check(count(bk_slots($type_notice, gmdate('Y-m-d', strtotime('+5 days')))) === 3,
	'A day beyond the minimum-notice period is open');
$res = bk_submit($type_notice->get('bkt_slug'), gmdate('Y-m-d', strtotime('+1 day')) . ' 09:00:00');
check(bk_errors($res), 'A too-soon time is refused, not just hidden', json_encode(bk_errors($res)));


// ============================================================================
// Sweep the rows the logic created on its own: bookings for every type this run
// made, the invitees it minted, and the host notifications it raised. Registered
// last so it runs first — the fixture rows they point at go afterwards.
harness_defer(function () use ($BK_TYPE_IDS, $BK_HOST_IDS, $RUN) {
	$db = DbConnector::get_instance()->get_db_link();
	try {
		foreach ($BK_TYPE_IDS as $tid) {
			$db->prepare('DELETE FROM bkn_bookings WHERE bkn_bkt_booking_type_id = ?')->execute(array($tid));
		}
		foreach ($BK_HOST_IDS as $hid) {
			$db->prepare('DELETE FROM ntf_notifications WHERE ntf_usr_user_id = ?')->execute(array($hid));
		}
		$db->prepare('DELETE FROM usr_users WHERE usr_email LIKE ?')->execute(array('bkflow_' . $RUN . '_%'));
	} catch (\Throwable $e) {
		echo '  WARNING: booking sweep failed: ' . $e->getMessage() . "\n";
	}
});

harness_finish();
