<?php
require_once(PathHelper::getIncludePath('includes/calendar/CalendarItemSource.php'));
require_once(PathHelper::getIncludePath('plugins/bookings/data/bookings_class.php'));
require_once(PathHelper::getIncludePath('plugins/bookings/data/booking_types_class.php'));

/**
 * Projects confirmed bookings (and active paid holds) where the subject is the
 * host onto their calendar, and into the busy projection. This is what makes a
 * booked slot disappear from future availability and appear on the host's
 * personal calendar — the one feature implementing both outcomes from one item.
 */
class BookingItemSource implements CalendarItemSource {

	public static function getKey(): string {
		return 'bookings';
	}

	public function getItems(
		CalendarSubject $subject,
		string $start_utc,
		string $end_utc,
		string $visibility
	): array {
		$host_id = $subject->getUserId();
		if (!$host_id) {
			return [];
		}

		$items = [];
		// BOOKED confirmations and CREATED (paid holds) both occupy the slot.
		foreach ([Booking::BOOKING_STATUS_BOOKED, Booking::BOOKING_STATUS_CREATED] as $status) {
			$bookings = new MultiBooking([
				'user_id_booked' => $host_id,
				'status' => $status,
				'deleted' => false,
			]);
			$bookings->load();
			foreach ($bookings as $b) {
				// A hold that has expired no longer occupies the slot.
				if ($status === Booking::BOOKING_STATUS_CREATED) {
					$exp = $b->get('bkn_hold_expires_time');
					if (!$exp || $exp < gmdate('Y-m-d H:i:s')) {
						continue;
					}
				}
				$s = $b->get('bkn_start_time');
				$e = $b->get('bkn_end_time') ?: $s;
				if (!$s || !($s < $end_utc && $e > $start_utc)) {
					continue;
				}
				$title = 'Booking';
				if ($b->get('bkn_bkt_booking_type_id')) {
					$type = new BookingType($b->get('bkn_bkt_booking_type_id'), true);
					if ($type->key && $type->get('bkt_name')) {
						$title = $type->get('bkt_name');
					}
				}
				$items[] = new CalendarItem([
					'start_utc'           => $s,
					'end_utc'             => $e,
					'type'                => CalendarItem::TYPE_BOOKING,
					'title'               => $title,
					'url'                 => null,
					'blocks_availability' => true,
					'visibility'          => $visibility,
					'source'              => self::getKey(),
					'source_key'          => 'bookings:bkn-' . $b->key,
				]);
			}
		}
		return $items;
	}
}
