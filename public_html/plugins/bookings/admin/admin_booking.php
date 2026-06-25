<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/bookings/logic/admin_booking_logic.php'));

$page_vars = process_logic(admin_booking_logic(array_merge($_GET, $_POST)));
extract($page_vars);

$status_labels = array(
	Booking::BOOKING_STATUS_CREATED => 'Hold',
	Booking::BOOKING_STATUS_BOOKED => 'Booked',
	Booking::BOOKING_STATUS_COMPLETED => 'Completed',
	Booking::BOOKING_STATUS_CANCELED => 'Canceled',
	Booking::BOOKING_STATUS_NEEDS_ATTENTION => 'Needs attention',
);
$tz = $session->get_timezone();
$active = in_array((int)$booking->get('bkn_status'), array(Booking::BOOKING_STATUS_BOOKED, Booking::BOOKING_STATUS_CREATED), true);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'bookings',
	'breadcrumbs' => array('Bookings' => '/plugins/bookings/admin/admin_bookings', 'Booking' => ''),
	'session' => $session,
));

$page->begin_box(array('title' => 'Booking #' . $booking->key));

echo '<table class="table">';
echo '<tr><th>Type</th><td>' . htmlspecialchars($type->get('bkt_name') ?: '—') . '</td></tr>';
echo '<tr><th>When</th><td>' . LibraryFunctions::convert_time($booking->get('bkn_start_time'), 'UTC', $tz, 'l, M j, Y g:i A T')
	. ' – ' . LibraryFunctions::convert_time($booking->get('bkn_end_time'), 'UTC', $tz, 'g:i A T') . '</td></tr>';
echo '<tr><th>Host</th><td>' . htmlspecialchars($host->display_name()) . '</td></tr>';
echo '<tr><th>Invitee</th><td>' . htmlspecialchars($client->display_name()) . ' (' . htmlspecialchars($client->get('usr_email')) . ')</td></tr>';
echo '<tr><th>Status</th><td>' . ($status_labels[(int)$booking->get('bkn_status')] ?? 'Unknown')
	. ($booking->get('bkn_is_no_show') ? ' · No-show' : '') . '</td></tr>';
if ($booking->get('bkn_notes')) { echo '<tr><th>Notes</th><td>' . nl2br(htmlspecialchars($booking->get('bkn_notes'))) . '</td></tr>'; }
if ($booking->get('bkn_location')) { echo '<tr><th>Location</th><td>' . nl2br(htmlspecialchars($booking->get('bkn_location'))) . '</td></tr>'; }
if ($booking->get('bkn_cancel_reason')) { echo '<tr><th>Cancel reason</th><td>' . htmlspecialchars($booking->get('bkn_cancel_reason')) . ' (' . htmlspecialchars($booking->get('bkn_canceled_by')) . ')</td></tr>'; }
echo '</table>';

// Intake survey answers (against the invitee).
if ($type->key && $type->get('bkt_svy_survey_id') && $client->key) {
	require_once(PathHelper::getIncludePath('data/survey_answers_class.php'));
	require_once(PathHelper::getIncludePath('data/questions_class.php'));
	$answers = new MultiSurveyAnswer(array('survey_id' => $type->get('bkt_svy_survey_id'), 'user_id' => $client->key));
	$answers->load();
	if (count($answers)) {
		echo '<hr><h4>Intake answers</h4><table class="table">';
		foreach ($answers as $a) {
			$q = new Question($a->get('sva_qst_question_id'), TRUE);
			$qtext = $q->key ? $q->get('qst_question') : ('Question #' . $a->get('sva_qst_question_id'));
			echo '<tr><th>' . htmlspecialchars($qtext) . '</th><td>' . nl2br(htmlspecialchars($a->get('sva_answer'))) . '</td></tr>';
		}
		echo '</table>';
	}
}

if ($active) {
	echo '<hr><div class="bkn-admin-actions">';

	echo '<form method="post" class="bkn-admin-form">';
	echo '<input type="hidden" name="bkn_booking_id" value="' . $booking->key . '">';
	echo '<label>Cancel reason<br><input type="text" name="cancel_reason" placeholder="Optional"></label>';
	echo '<button class="btn btn-danger" name="cancel_booking" value="1" type="submit">Cancel booking</button>';
	echo '</form>';

	echo '<form method="post"><input type="hidden" name="bkn_booking_id" value="' . $booking->key . '">';
	echo '<button class="btn btn-secondary" name="mark_no_show" value="1" type="submit">Mark no-show</button></form>';

	echo '<form method="post"><input type="hidden" name="bkn_booking_id" value="' . $booking->key . '">';
	echo '<button class="btn btn-secondary" name="mark_completed" value="1" type="submit">Mark completed</button></form>';

	echo '</div>';
}

$page->end_box();
$page->admin_footer();
