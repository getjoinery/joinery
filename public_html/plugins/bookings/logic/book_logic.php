<?php

/**
 * Public booking page logic.
 *
 * GET: resolve the booking type by its vanity slug, expose it (and the host) to
 * the slot_picker + form. POST: create the booking race-safely — a per-host
 * advisory lock plus a conflict re-check against the live availability ensure
 * two simultaneous submissions for one slot yield exactly one booking — then
 * send confirmations and notify the host.
 */
function book_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
	require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/notifications_class.php'));
	require_once(PathHelper::getIncludePath('plugins/bookings/data/booking_types_class.php'));
	require_once(PathHelper::getIncludePath('plugins/bookings/data/bookings_class.php'));
	require_once(PathHelper::getIncludePath('plugins/bookings/includes/SchedulingProviderRegistry.php'));

	$settings = Globalvars::get_instance();
	$session = SessionControl::get_instance();

	$slug = $input['slug'] ?? '';
	$type = $slug ? BookingType::GetBySlug($slug) : false;

	$page_vars = array('session' => $session, 'settings' => $settings, 'errors' => array(), 'old' => array());

	if (!$type || !$type->is_active() || !$settings->get_setting('bookings_active')) {
		$page_vars['is_valid_page'] = false;
		$page_vars['type'] = false;
		return LogicResult::render($page_vars);
	}

	$host = new User($type->get('bkt_usr_user_id'), TRUE);
	$page_vars['type'] = $type;
	$page_vars['host'] = $host;

	// Confirmation view after a successful booking (PRG target).
	if (!empty($input['confirmed'])) {
		$booking = Booking::GetByToken($input['confirmed']);
		if ($booking) { $page_vars['confirmed_booking'] = $booking; }
		return LogicResult::render($page_vars);
	}

	if (isset($_POST['book_submit'])) {
		$slot_start = LibraryFunctions::fetch_variable_local($input, 'slot_start', '', '', '', 'safemode', NULL);
		$name  = trim(LibraryFunctions::fetch_variable_local($input, 'invitee_name', '', '', '', 'safemode', NULL));
		$email = trim(LibraryFunctions::fetch_variable_local($input, 'invitee_email', '', '', '', 'safemode', NULL));
		$notes = trim(LibraryFunctions::fetch_variable_local($input, 'invitee_notes', '', '', '', 'safemode', NULL));
		$invitee_tz = LibraryFunctions::fetch_variable_local($input, 'invitee_timezone', '', '', '', 'safemode', NULL);
		$page_vars['old'] = array('name' => $name, 'email' => $email, 'notes' => $notes);

		if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $slot_start)) {
			$page_vars['errors'][] = 'Please pick a time.';
		}
		if ($name === '') { $page_vars['errors'][] = 'Please enter your name.'; }
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $page_vars['errors'][] = 'Please enter a valid email.'; }
		if (!empty($page_vars['errors'])) {
			return LogicResult::render($page_vars);
		}

		// Match-or-create the invitee by email (an inactive record is fine).
		$client = User::GetByEmail($email);
		if (!$client) {
			$parts = preg_split('/\s+/', $name, 2);
			$client = new User(NULL);
			$client->set('usr_email', $email);
			$client->set('usr_first_name', $parts[0] !== '' ? $parts[0] : 'Guest');
			if (!empty($parts[1])) { $client->set('usr_last_name', $parts[1]); }
			if ($invitee_tz) { $client->set('usr_timezone', $invitee_tz); }
			$client->set('usr_permission', 0);
			try {
				$client->save();
			} catch (Exception $e) {
				$page_vars['errors'][] = 'We couldn\'t use that email address. Please double-check it.';
				return LogicResult::render($page_vars);
			}
		}

		$provider = SchedulingProviderRegistry::get($type->get('bkt_provider'));

		// Race-safe creation: serialize per host, then re-check the slot is still open.
		$dblink = DbConnector::get_instance()->get_db_link();
		$booking = null;
		$conflict = false;
		try {
			$dblink->beginTransaction();
			$lock = $dblink->prepare('SELECT pg_advisory_xact_lock(?)');
			$lock->execute([(int)$type->get('bkt_usr_user_id')]);

			$day = substr($slot_start, 0, 10);
			$check = $provider->getAvailableSlots($type, $day . ' 00:00:00', $day . ' 23:59:59');
			$open = false;
			foreach ($check as $s) { if ($s['start'] === $slot_start) { $open = true; break; } }

			if (!$open) {
				$conflict = true;
				$dblink->rollBack();
			} else {
				$booking = $provider->createBooking($type, array(
					'user_id'  => $client->key,
					'notes'    => $notes,
					'timezone' => $invitee_tz,
					'utm'      => array(
						'source'   => $input['utm_source'] ?? null,
						'medium'   => $input['utm_medium'] ?? null,
						'campaign' => $input['utm_campaign'] ?? null,
						'content'  => $input['utm_content'] ?? null,
						'term'     => $input['utm_term'] ?? null,
					),
				), $slot_start);
				$dblink->commit();
			}
		} catch (Exception $e) {
			if ($dblink->inTransaction()) { $dblink->rollBack(); }
			$page_vars['errors'][] = 'Could not complete the booking. Please try again.';
			return LogicResult::render($page_vars);
		}

		if ($conflict) {
			$page_vars['errors'][] = 'Sorry — that time was just taken. Please pick another.';
			return LogicResult::render($page_vars);
		}

		// Store intake survey answers against the invitee.
		booking_save_survey_answers($type->get('bkt_svy_survey_id'), $client->key, $input);

		// Side effects outside the transaction.
		booking_send_confirmation($booking, $type, $host, $client, $settings);
		Notification::create_notification(
			$host->key, 'booking', 'New booking',
			$client->display_name() . ' booked ' . $type->get('bkt_name') . '.',
			'/profile/calendar', $client->key
		);

		return LogicResult::redirect('/book/' . $slug . '?confirmed=' . $booking->get('bkn_action_token'));
	}

	return LogicResult::render($page_vars);
}

/** Persist intake survey answers (one per question) against the invitee user. */
function booking_save_survey_answers($survey_id, $user_id, $input) {
	if (!$survey_id || !$user_id) { return; }
	require_once(PathHelper::getIncludePath('data/survey_questions_class.php'));
	require_once(PathHelper::getIncludePath('data/questions_class.php'));
	require_once(PathHelper::getIncludePath('data/survey_answers_class.php'));

	$sq = new MultiSurveyQuestion(['survey_id' => $survey_id, 'deleted' => false]);
	$sq->load();
	foreach ($sq as $row) {
		$q = new Question($row->get('srq_qst_question_id'), TRUE);
		if (!$q->key) { continue; }
		$key = 'question_' . $q->key;
		if (!isset($input[$key]) || $input[$key] === '' || $input[$key] === array()) { continue; }
		$answer = is_array($input[$key]) ? implode(',', $input[$key]) : $input[$key];
		$sa = new SurveyAnswer(NULL);
		$sa->set('sva_svy_survey_id', $survey_id);
		$sa->set('sva_qst_question_id', $q->key);
		$sa->set('sva_usr_user_id', $user_id);
		$sa->set('sva_answer', $answer);
		$sa->set('sva_create_time', 'now()');
		try { $sa->save(); } catch (Exception $e) { error_log('booking survey answer failed: ' . $e->getMessage()); }
	}
}

/** Confirmation emails to invitee + host, with an ICS invite and manage link. */
function booking_send_confirmation($booking, $type, $host, $client, $settings) {
	if (!$type->get('bkt_send_native_emails')) { return; }

	$base = rtrim($settings->get_setting('site_url') ?: ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), '/');
	$manage_url = $base . '/booking/manage?token=' . $booking->get('bkn_action_token');

	$client_tz = $client->get('usr_timezone') ?: 'UTC';
	$host_tz   = $host->get('usr_timezone') ?: 'UTC';
	$when_client = LibraryFunctions::convert_time($booking->get('bkn_start_time'), 'UTC', $client_tz, 'l, M j, Y g:i A T');
	$when_host   = LibraryFunctions::convert_time($booking->get('bkn_start_time'), 'UTC', $host_tz, 'l, M j, Y g:i A T');

	$ics = booking_build_ics($booking, $type, $host);

	// Invitee
	$body = '<p>Your booking is confirmed.</p>'
		. '<p><strong>' . htmlspecialchars($type->get('bkt_name')) . '</strong><br>' . htmlspecialchars($when_client) . '</p>'
		. ($type->get('bkt_location_details') ? '<p>Location: ' . htmlspecialchars($type->get('bkt_location_details')) . '</p>' : '')
		. '<p>Need to make a change? <a href="' . htmlspecialchars($manage_url) . '">Cancel or reschedule</a>.</p>';
	$msg = EmailMessage::create($client->get('usr_email'), 'Booking confirmed: ' . $type->get('bkt_name'), $body);
	$msg->attachData($ics, 'invite.ics', 'text/calendar');
	try { (new EmailSender())->send($msg); } catch (Exception $e) { error_log('booking confirm (invitee) failed: ' . $e->getMessage()); }

	// Host
	if ($host->get('usr_email')) {
		$hbody = '<p>New booking.</p>'
			. '<p><strong>' . htmlspecialchars($type->get('bkt_name')) . '</strong><br>' . htmlspecialchars($when_host) . '</p>'
			. '<p>With: ' . htmlspecialchars($client->display_name()) . ' (' . htmlspecialchars($client->get('usr_email')) . ')</p>'
			. ($booking->get('bkn_notes') ? '<p>Notes: ' . htmlspecialchars($booking->get('bkn_notes')) . '</p>' : '');
		$hmsg = EmailMessage::create($host->get('usr_email'), 'New booking: ' . $type->get('bkt_name'), $hbody);
		$hmsg->attachData($ics, 'invite.ics', 'text/calendar');
		try { (new EmailSender())->send($hmsg); } catch (Exception $e) { error_log('booking confirm (host) failed: ' . $e->getMessage()); }
	}
}

/** Minimal RFC5545 VEVENT for a booking. */
function booking_build_ics($booking, $type, $host) {
	$fmt = function ($utc) { return gmdate('Ymd\THis\Z', strtotime($utc)); };
	$uid = 'booking-' . $booking->key . '@joinery';
	$summary = str_replace(["\r", "\n"], ' ', $type->get('bkt_name'));
	$lines = array(
		'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Joinery//Bookings//EN', 'BEGIN:VEVENT',
		'UID:' . $uid,
		'DTSTAMP:' . $fmt(gmdate('Y-m-d H:i:s')),
		'DTSTART:' . $fmt($booking->get('bkn_start_time')),
		'DTEND:' . $fmt($booking->get('bkn_end_time')),
		'SUMMARY:' . $summary,
	);
	if ($type->get('bkt_location_details')) {
		$lines[] = 'LOCATION:' . str_replace(["\r", "\n"], ' ', $type->get('bkt_location_details'));
	}
	$lines[] = 'END:VEVENT';
	$lines[] = 'END:VCALENDAR';
	return implode("\r\n", $lines);
}
