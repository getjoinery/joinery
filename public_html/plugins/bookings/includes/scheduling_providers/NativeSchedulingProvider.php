<?php
require_once(PathHelper::getIncludePath('plugins/bookings/includes/SchedulingServiceProvider.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarItemSourceRegistry.php'));
require_once(PathHelper::getIncludePath('includes/scheduling/SlotGenerator.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/schedule_class.php'));
require_once(PathHelper::getIncludePath('plugins/bookings/data/bookings_class.php'));
require_once(PathHelper::getIncludePath('plugins/bookings/data/booking_types_class.php'));

/**
 * The native scheduling backend: slots come from the host's schedule via
 * SlotGenerator against the busy projection; bookings are plain bkn_bookings
 * rows. Headless — Joinery renders the picker and form. Needs no connection.
 * The only provider this spec ships; default for every booking type.
 */
class NativeSchedulingProvider implements SchedulingServiceProvider {

	public static function getKey(): string { return 'native'; }
	public static function getLabel(): string { return 'Joinery (native)'; }
	public static function getMode(): string { return 'headless'; }

	public static function getConnectionFields(): array { return []; }
	public static function getConnectUrl(?int $user_id): ?string { return null; }
	public function listEventTypes($conn): array { return []; }

	/**
	 * Open slots for a booking type within [start,end], constrained by the
	 * type's rolling/fixed window, generated against the host's schedule and
	 * busy projection, then trimmed for per-period booking caps.
	 *
	 * @return array[] list of ['start'=>UTC, 'end'=>UTC]
	 */
	public function getAvailableSlots(BookingType $type, string $start_utc, string $end_utc): array {
		$host_id = $type->get('bkt_usr_user_id');
		if (!$host_id) { return []; }

		$subject = CalendarSubject::user($host_id);
		$schedule = Schedule::for_subject($subject);
		if (!$schedule) { return []; }

		// Constrain the requested range to the bookable window.
		$now = gmdate('Y-m-d H:i:s');
		$bookable_start = $now;
		if ($type->get('bkt_window_start')) {
			$ws = $type->get('bkt_window_start') . ' 00:00:00';
			$bookable_start = max($bookable_start, $ws);
		}
		if ($type->get('bkt_window_end')) {
			$bookable_end = $type->get('bkt_window_end') . ' 23:59:59';
		} else {
			$rolling = (int)($type->get('bkt_rolling_days') ?: 60);
			$bookable_end = gmdate('Y-m-d H:i:s', strtotime($now . ' +' . $rolling . ' days'));
		}

		$eff_start = max($start_utc, $bookable_start);
		$eff_end   = min($end_utc, $bookable_end);
		if ($eff_end <= $eff_start) { return []; }

		$busy = CalendarItemSourceRegistry::getBusyBlocks($subject, $eff_start, $eff_end);

		$slots = SlotGenerator::forSchedule($schedule, $eff_start, $eff_end, [
			'duration_minutes'      => (int)($type->get('bkt_duration_minutes') ?: 30),
			'increment_minutes'     => (int)($type->get('bkt_slot_increment_minutes') ?: 30),
			'buffer_before_minutes' => (int)$type->get('bkt_buffer_before_minutes'),
			'buffer_after_minutes'  => (int)$type->get('bkt_buffer_after_minutes'),
			'min_notice_minutes'    => (int)($type->get('bkt_min_notice_minutes') ?: 0),
		], $busy);

		return $this->applyCaps($type, $schedule->get('sch_timezone'), $slots);
	}

	/**
	 * Drop slots on days/weeks that have already hit their booking cap. Caps are
	 * counted in the host's timezone, per the spec — not the generator's job.
	 */
	private function applyCaps(BookingType $type, string $tz, array $slots): array {
		$max_day  = $type->get('bkt_max_per_day');
		$max_week = $type->get('bkt_max_per_week');
		if (!$max_day && !$max_week) { return $slots; }

		// Count existing live bookings for this type, bucketed by host-local day/week.
		$day_counts = []; $week_counts = [];
		$existing = new MultiBooking(['booking_type_id' => $type->key, 'status' => Booking::BOOKING_STATUS_BOOKED, 'deleted' => false]);
		$existing->load();
		foreach ($existing as $b) {
			$local = LibraryFunctions::convert_time($b->get('bkn_start_time'), 'UTC', $tz, 'Y-m-d');
			$day_counts[$local] = ($day_counts[$local] ?? 0) + 1;
			$wk = date('o-W', strtotime($local));
			$week_counts[$wk] = ($week_counts[$wk] ?? 0) + 1;
		}

		$out = [];
		foreach ($slots as $slot) {
			$local = LibraryFunctions::convert_time($slot['start'], 'UTC', $tz, 'Y-m-d');
			$wk = date('o-W', strtotime($local));
			if ($max_day && ($day_counts[$local] ?? 0) >= $max_day) { continue; }
			if ($max_week && ($week_counts[$wk] ?? 0) >= $max_week) { continue; }
			$out[] = $slot;
		}
		return $out;
	}

	/**
	 * Create a native booking. The caller resolves the invitee user and supplies
	 * its id; the race-safe wrapper (advisory lock + conflict re-check) and the
	 * confirmation side effects (emails, calendar item) live in the booking logic
	 * that calls this. Returns a BOOKED Booking with a fresh action token.
	 *
	 * @param array $invitee ['user_id','notes','timezone','utm'=>[...],'status'?]
	 */
	public function createBooking(BookingType $type, array $invitee, string $slot_start_utc): Booking {
		$duration = (int)($type->get('bkt_duration_minutes') ?: 30);
		$end_utc = gmdate('Y-m-d H:i:s', strtotime($slot_start_utc) + $duration * 60);

		$booking = new Booking(NULL);
		$booking->set('bkn_provider', 'native');
		$booking->set('bkn_bkt_booking_type_id', $type->key);
		$booking->set('bkn_usr_user_id_booked', $type->get('bkt_usr_user_id'));
		$booking->set('bkn_usr_user_id_client', $invitee['user_id'] ?? null);
		$booking->set('bkn_pro_product_id', $type->get('bkt_pro_product_id'));
		$booking->set('bkn_start_time', $slot_start_utc);
		$booking->set('bkn_end_time', $end_utc);
		$booking->set('bkn_status', $invitee['status'] ?? Booking::BOOKING_STATUS_BOOKED);
		$booking->set('bkn_notes', $invitee['notes'] ?? '');
		$booking->set('bkn_location', $type->get('bkt_location_details'));
		$booking->set('bkn_invitee_timezone', $invitee['timezone'] ?? null);
		$booking->set('bkn_action_token', Booking::make_action_token());
		if (!empty($invitee['utm'])) {
			foreach (['source','medium','campaign','content','term'] as $k) {
				if (!empty($invitee['utm'][$k])) { $booking->set('bkn_utm_' . $k, $invitee['utm'][$k]); }
			}
		}
		$booking->save();
		return $booking;
	}

	public function cancelBooking(Booking $booking, string $reason): bool {
		$booking->set('bkn_status', Booking::BOOKING_STATUS_CANCELED);
		if ($reason !== '') { $booking->set('bkn_cancel_reason', $reason); }
		$booking->set('bkn_update_time', gmdate('Y-m-d H:i:s'));
		$booking->save();
		return true;
	}

	// Embed/webhook surface — unused by the headless native provider.
	public function getEmbedHtml(BookingType $type, array $tracking): string { return ''; }
	public function registerWebhooks($conn): void {}
	public function verifyWebhook(array $headers, string $raw_body, $conn): bool { return false; }
	public function handleWebhook(array $payload, $conn): void {}
}
