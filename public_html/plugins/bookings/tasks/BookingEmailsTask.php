<?php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('plugins/bookings/data/bookings_class.php'));
require_once(PathHelper::getIncludePath('plugins/bookings/data/booking_types_class.php'));
require_once(PathHelper::getIncludePath('plugins/bookings/data/booking_email_class.php'));

/**
 * Sends booking reminders (at each configured offset before start) and a
 * follow-up after the booking ends. Idempotent: every send is recorded in
 * bke_booking_emails keyed by booking + kind + offset + booking start time, so
 * re-runs send nothing and a rescheduled booking (new start time) earns a fresh
 * set of reminders. Suppressed for canceled / no-show bookings and types with
 * native emails turned off.
 */
class BookingEmailsTask implements ScheduledTaskInterface {

	public function run(array $config) {
		$grace_hours = (int)($config['followup_grace_hours'] ?? 24);
		$now = gmdate('Y-m-d H:i:s');
		$now_ts = time();
		$sent = 0;

		$bookings = new MultiBooking(['status' => Booking::BOOKING_STATUS_BOOKED, 'deleted' => false]);
		$bookings->load();

		foreach ($bookings as $booking) {
			if ($booking->get('bkn_is_no_show')) { continue; }
			if (!$booking->get('bkn_start_time')) { continue; }

			$type = new BookingType($booking->get('bkn_bkt_booking_type_id'), TRUE);
			if (!$type->key || !$type->get('bkt_send_native_emails')) { continue; }

			$start = $booking->get('bkn_start_time');
			$end = $booking->get('bkn_end_time') ?: $start;
			$start_ts = strtotime($start);

			// Reminders before start.
			foreach ($type->reminder_offsets() as $offset) {
				$send_at = $start_ts - $offset * 60;
				if ($now_ts >= $send_at && $now_ts < $start_ts) {
					if (!$this->alreadySent($booking->key, 'reminder', $offset, $start)) {
						if ($this->sendReminder($booking, $type, $offset)) {
							$this->logSend($booking->key, 'reminder', $offset, $start);
							$sent++;
						}
					}
				}
			}

			// Follow-up after end (within the grace window).
			$end_ts = strtotime($end);
			if ($now_ts >= $end_ts && $now_ts < $end_ts + $grace_hours * 3600) {
				if (!$this->alreadySent($booking->key, 'followup', 0, $start)) {
					if ($this->sendFollowup($booking, $type)) {
						$this->logSend($booking->key, 'followup', 0, $start);
						$sent++;
					}
				}
			}
		}

		return array('status' => 'success', 'message' => "Sent {$sent} booking email(s).");
	}

	private function alreadySent($booking_id, $kind, $offset, $start) {
		$log = new MultiBookingEmail(['booking_id' => $booking_id, 'kind' => $kind, 'deleted' => false]);
		$log->load();
		foreach ($log as $row) {
			if ((int)$row->get('bke_offset_minutes') === (int)$offset
				&& $row->get('bke_booking_start_time') === $start) {
				return true;
			}
		}
		return false;
	}

	private function logSend($booking_id, $kind, $offset, $start) {
		$row = new BookingEmail(NULL);
		$row->set('bke_bkn_booking_id', $booking_id);
		$row->set('bke_kind', $kind);
		$row->set('bke_offset_minutes', $offset);
		$row->set('bke_booking_start_time', $start);
		$row->save();
	}

	private function sendReminder($booking, $type, $offset) {
		$client = new User($booking->get('bkn_usr_user_id_client'), TRUE);
		if (!$client->key || !$client->get('usr_email')) { return false; }
		$tz = $booking->get('bkn_invitee_timezone') ?: ($client->get('usr_timezone') ?: 'UTC');
		$when = LibraryFunctions::convert_time($booking->get('bkn_start_time'), 'UTC', $tz, 'l, M j, Y g:i A T');
		$manage = $this->baseUrl() . '/booking/manage?token=' . $booking->get('bkn_action_token');
		$body = '<p>Reminder: your booking is coming up.</p>'
			. '<p><strong>' . htmlspecialchars($type->get('bkt_name')) . '</strong><br>' . htmlspecialchars($when) . '</p>'
			. '<p><a href="' . htmlspecialchars($manage) . '">Manage this booking</a></p>';
		try {
			(new EmailSender())->send(EmailMessage::create($client->get('usr_email'), 'Reminder: ' . $type->get('bkt_name'), $body));
			return true;
		} catch (Exception $e) { error_log('booking reminder failed: ' . $e->getMessage()); return false; }
	}

	private function sendFollowup($booking, $type) {
		$client = new User($booking->get('bkn_usr_user_id_client'), TRUE);
		if (!$client->key || !$client->get('usr_email')) { return false; }
		$body = '<p>Thanks for meeting!</p><p>We hope your <strong>' . htmlspecialchars($type->get('bkt_name')) . '</strong> went well.</p>';
		if ($type->get('bkt_slug')) {
			$body .= '<p><a href="' . htmlspecialchars($this->baseUrl() . '/book/' . $type->get('bkt_slug')) . '">Book again</a></p>';
		}
		try {
			(new EmailSender())->send(EmailMessage::create($client->get('usr_email'), 'Following up: ' . $type->get('bkt_name'), $body));
			return true;
		} catch (Exception $e) { error_log('booking followup failed: ' . $e->getMessage()); return false; }
	}

	private function baseUrl() {
		$settings = Globalvars::get_instance();
		return rtrim($settings->get_setting('site_url') ?: 'https://dev.getjoinery.com', '/');
	}
}
