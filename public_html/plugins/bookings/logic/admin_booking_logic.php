<?php

/**
 * Admin booking detail + actions: cancel (notifies the invitee with a rebook
 * link), mark no-show, mark completed.
 */
function admin_booking_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('plugins/bookings/data/bookings_class.php'));
	require_once(PathHelper::getIncludePath('plugins/bookings/data/booking_types_class.php'));
	require_once(PathHelper::getIncludePath('plugins/bookings/logic/booking_manage_logic.php'));   // booking_notify_cancellation

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	$id = LibraryFunctions::fetch_variable_local($input, 'bkn_booking_id', NULL, 'required', 'Booking id required.', 'safemode', 'int');
	$booking = new Booking($id, TRUE);
	if (!$booking->key) {
		return LogicResult::redirect('/plugins/bookings/admin/admin_bookings');
	}
	$type = $booking->get('bkn_bkt_booking_type_id') ? new BookingType($booking->get('bkn_bkt_booking_type_id'), TRUE) : new BookingType(NULL);
	$host = $booking->get('bkn_usr_user_id_booked') ? new User($booking->get('bkn_usr_user_id_booked'), TRUE) : new User(NULL);
	$client = $booking->get('bkn_usr_user_id_client') ? new User($booking->get('bkn_usr_user_id_client'), TRUE) : new User(NULL);

	if (isset($_POST['cancel_booking'])) {
		$reason = trim(LibraryFunctions::fetch_variable_local($input, 'cancel_reason', '', '', '', 'safemode', NULL));
		$booking->set('bkn_status', Booking::BOOKING_STATUS_CANCELED);
		$booking->set('bkn_canceled_by', 'admin');
		if ($reason !== '') { $booking->set('bkn_cancel_reason', $reason); }
		$booking->set('bkn_update_time', gmdate('Y-m-d H:i:s'));
		$booking->save();
		if ($type->key) { booking_notify_cancellation($booking, $type, $host, $client, $settings, 'admin'); }
		return LogicResult::redirect('/plugins/bookings/admin/admin_booking?bkn_booking_id=' . $booking->key);
	}
	if (isset($_POST['mark_no_show'])) {
		$booking->set('bkn_is_no_show', true);
		$booking->set('bkn_update_time', gmdate('Y-m-d H:i:s'));
		$booking->save();
		return LogicResult::redirect('/plugins/bookings/admin/admin_booking?bkn_booking_id=' . $booking->key);
	}
	if (isset($_POST['mark_completed'])) {
		$booking->set('bkn_status', Booking::BOOKING_STATUS_COMPLETED);
		$booking->set('bkn_update_time', gmdate('Y-m-d H:i:s'));
		$booking->save();
		return LogicResult::redirect('/plugins/bookings/admin/admin_booking?bkn_booking_id=' . $booking->key);
	}

	return LogicResult::render(array(
		'session' => $session, 'booking' => $booking, 'type' => $type, 'host' => $host, 'client' => $client,
	));
}
