<?php

/**
 * Host "my bookings": the logged-in host's upcoming bookings, with host-side
 * cancel (the invitee is emailed a rebook link). The notice-minutes rule does
 * not bind hosts.
 */
function my_bookings_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('plugins/bookings/data/bookings_class.php'));
	require_once(PathHelper::getIncludePath('plugins/bookings/data/booking_types_class.php'));
	require_once(PathHelper::getIncludePath('plugins/bookings/logic/booking_manage_logic.php'));   // booking_notify_cancellation

	$settings = Globalvars::get_instance();
	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$session->set_return();
	$user_id = $session->get_user_id();

	if (isset($_POST['cancel_booking'])) {
		$bid = LibraryFunctions::fetch_variable_local($input, 'bkn_booking_id', NULL, 'required', 'Booking id required.', 'safemode', 'int');
		$reason = trim(LibraryFunctions::fetch_variable_local($input, 'cancel_reason', '', '', '', 'safemode', NULL));
		$booking = new Booking($bid, TRUE);
		// The success banner is earned only by an actual cancellation: a missing
		// id or someone else's booking gets an error, not a lie. PRG both ways.
		if (!$booking->key || (int)$booking->get('bkn_usr_user_id_booked') !== (int)$user_id) {
			return LogicResult::redirect('/profile/bookings/my_bookings?cancel_error=1');
		}
		$booking->set('bkn_status', Booking::BOOKING_STATUS_CANCELED);
		$booking->set('bkn_canceled_by', 'host');
		if ($reason !== '') { $booking->set('bkn_cancel_reason', $reason); }
		$booking->set('bkn_update_time', gmdate('Y-m-d H:i:s'));
		$booking->save();
		$type = new BookingType($booking->get('bkn_bkt_booking_type_id'), TRUE);
		$host = new User($user_id, TRUE);
		$client = new User($booking->get('bkn_usr_user_id_client'), TRUE);
		if ($type->key) { booking_notify_cancellation($booking, $type, $host, $client, $settings, 'host'); }
		return LogicResult::redirect('/profile/bookings/my_bookings?canceled=1');
	}

	$now = gmdate('Y-m-d H:i:s');
	$bookings = new MultiBooking(array(
		'user_id_booked' => $user_id,
		'status' => Booking::BOOKING_STATUS_BOOKED,
		'deleted' => false,
		'start_after' => $now,
	), array('start_time' => 'ASC'));
	$bookings->load();

	return LogicResult::render(array(
		'session' => $session, 'bookings' => $bookings, 'canceled' => !empty($input['canceled']),
		'cancel_error' => !empty($input['cancel_error']),
	));
}
