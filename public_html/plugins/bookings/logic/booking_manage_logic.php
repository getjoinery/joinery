<?php

/**
 * Invitee self-service: cancel or reschedule a booking. No login — the random
 * action token is the credential. Enforces the booking type's invitee
 * cancel/reschedule notice window and shows its policy text. Reschedule moves
 * the same booking row to a new slot (re-checked race-safely); the old time is
 * freed automatically because the row no longer occupies it.
 */
function booking_manage_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
	require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/notifications_class.php'));
	require_once(PathHelper::getIncludePath('plugins/bookings/data/bookings_class.php'));
	require_once(PathHelper::getIncludePath('plugins/bookings/data/booking_types_class.php'));
	require_once(PathHelper::getIncludePath('plugins/bookings/includes/SchedulingProviderRegistry.php'));
	require_once(PathHelper::getIncludePath('plugins/bookings/logic/book_logic.php'));   // confirmation helpers

	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance();

	$token = $input['token'] ?? '';
	$booking = $token ? Booking::GetByToken($token) : false;

	$page_vars = array('session' => $session, 'settings' => $settings, 'errors' => array());

	if (!$booking) {
		$page_vars['is_valid_page'] = false;
		$page_vars['booking'] = false;
		return LogicResult::render($page_vars);
	}

	$type = new BookingType($booking->get('bkn_bkt_booking_type_id'), TRUE);
	$host = new User($booking->get('bkn_usr_user_id_booked'), TRUE);
	$client = new User($booking->get('bkn_usr_user_id_client'), TRUE);
	$page_vars['booking'] = $booking;
	$page_vars['type'] = $type;
	$page_vars['host'] = $host;
	$page_vars['client'] = $client;

	$already_done = in_array((int)$booking->get('bkn_status'), array(Booking::BOOKING_STATUS_CANCELED, Booking::BOOKING_STATUS_COMPLETED), true);
	$page_vars['already_done'] = $already_done;

	// Notice-window check shared by cancel + reschedule.
	$notice = (int)$type->get('bkt_cancel_notice_minutes');
	$within_notice = $notice > 0 && strtotime($booking->get('bkn_start_time')) < (time() + $notice * 60);

	if (!$already_done && isset($_POST['cancel_booking'])) {
		if ($within_notice) {
			$page_vars['errors'][] = 'This booking can no longer be canceled online (too close to the start time).';
			return LogicResult::render($page_vars);
		}
		$reason = trim(LibraryFunctions::fetch_variable_local($input, 'cancel_reason', '', '', '', 'safemode', NULL));
		$booking->set('bkn_status', Booking::BOOKING_STATUS_CANCELED);
		$booking->set('bkn_canceled_by', 'invitee');
		if ($reason !== '') { $booking->set('bkn_cancel_reason', $reason); }
		$booking->set('bkn_update_time', gmdate('Y-m-d H:i:s'));
		$booking->save();

		booking_notify_cancellation($booking, $type, $host, $client, $settings, 'invitee');
		return LogicResult::redirect('/booking/manage?token=' . $token . '&canceled=1');
	}

	if (!$already_done && isset($_POST['reschedule_booking'])) {
		if ($within_notice) {
			$page_vars['errors'][] = 'This booking can no longer be rescheduled online (too close to the start time).';
			return LogicResult::render($page_vars);
		}
		$slot_start = LibraryFunctions::fetch_variable_local($input, 'slot_start', '', '', '', 'safemode', NULL);
		if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $slot_start)) {
			$page_vars['errors'][] = 'Please pick a new time.';
			return LogicResult::render($page_vars);
		}

		$provider = SchedulingProviderRegistry::get($type->get('bkt_provider'));
		$dblink = DbConnector::get_instance()->get_db_link();
		$conflict = false;
		try {
			$dblink->beginTransaction();
			$dblink->prepare('SELECT pg_advisory_xact_lock(?)')->execute([(int)$host->key]);
			$day = substr($slot_start, 0, 10);
			$open = false;
			foreach ($provider->getAvailableSlots($type, $day . ' 00:00:00', $day . ' 23:59:59') as $s) {
				if ($s['start'] === $slot_start) { $open = true; break; }
			}
			if (!$open) {
				$conflict = true; $dblink->rollBack();
			} else {
				$dur = (int)($type->get('bkt_duration_minutes') ?: 30);
				$booking->set('bkn_start_time', $slot_start);
				$booking->set('bkn_end_time', gmdate('Y-m-d H:i:s', strtotime($slot_start) + $dur * 60));
				$booking->set('bkn_status', Booking::BOOKING_STATUS_BOOKED);
				$booking->set('bkn_update_time', gmdate('Y-m-d H:i:s'));
				$booking->save();
				$dblink->commit();
			}
		} catch (Exception $e) {
			if ($dblink->inTransaction()) { $dblink->rollBack(); }
			$page_vars['errors'][] = 'Could not reschedule. Please try again.';
			return LogicResult::render($page_vars);
		}
		if ($conflict) {
			$page_vars['errors'][] = 'Sorry — that time was just taken. Please pick another.';
			return LogicResult::render($page_vars);
		}

		booking_send_confirmation($booking, $type, $host, $client, $settings);
		Notification::create_notification($host->key, 'booking', 'Booking rescheduled',
			$client->display_name() . ' rescheduled ' . $type->get('bkt_name') . '.', '/profile/calendar', $client->key);
		return LogicResult::redirect('/booking/manage?token=' . $token . '&rescheduled=1');
	}

	$page_vars['canceled'] = !empty($input['canceled']);
	$page_vars['rescheduled'] = !empty($input['rescheduled']);
	$page_vars['within_notice'] = $within_notice;
	return LogicResult::render($page_vars);
}

/** Notify the other party when a booking is canceled, with a rebook link for invitees. */
function booking_notify_cancellation($booking, $type, $host, $client, $settings, $by) {
	$base = rtrim($settings->get_setting('site_url') ?: ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), '/');
	$rebook = $base . '/book/' . $type->get('bkt_slug');

	// Tell the host when the invitee cancels; tell the invitee when the host/admin cancels.
	if ($by === 'invitee' && $host->get('usr_email')) {
		$when = LibraryFunctions::convert_time($booking->get('bkn_start_time'), 'UTC', $host->get('usr_timezone') ?: 'UTC', 'l, M j, Y g:i A T');
		$body = '<p>A booking was canceled.</p><p><strong>' . htmlspecialchars($type->get('bkt_name')) . '</strong><br>'
			. htmlspecialchars($when) . '</p><p>By: ' . htmlspecialchars($client->display_name()) . '</p>'
			. ($booking->get('bkn_cancel_reason') ? '<p>Reason: ' . htmlspecialchars($booking->get('bkn_cancel_reason')) . '</p>' : '');
		try { (new EmailSender())->send(EmailMessage::create($host->get('usr_email'), 'Booking canceled: ' . $type->get('bkt_name'), $body)); }
		catch (Exception $e) { error_log('cancel notify host failed: ' . $e->getMessage()); }
	} elseif ($by !== 'invitee' && $client->get('usr_email')) {
		$when = LibraryFunctions::convert_time($booking->get('bkn_start_time'), 'UTC', $client->get('usr_timezone') ?: 'UTC', 'l, M j, Y g:i A T');
		$body = '<p>Your booking was canceled.</p><p><strong>' . htmlspecialchars($type->get('bkt_name')) . '</strong><br>'
			. htmlspecialchars($when) . '</p><p><a href="' . htmlspecialchars($rebook) . '">Pick a new time</a></p>';
		try { (new EmailSender())->send(EmailMessage::create($client->get('usr_email'), 'Your booking was canceled', $body)); }
		catch (Exception $e) { error_log('cancel notify invitee failed: ' . $e->getMessage()); }
	}
}
