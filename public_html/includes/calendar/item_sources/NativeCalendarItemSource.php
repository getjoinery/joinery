<?php
require_once(PathHelper::getIncludePath('includes/calendar/CalendarItemSource.php'));
require_once(PathHelper::getIncludePath('data/calendar_entry_class.php'));

/**
 * Projects native calendar entries — the appointments and blocked-out time a
 * subject creates directly on their own calendar (`cal_items`). The calendar is
 * the system of record for these, so unlike events/bookings they are fully
 * editable here; this source is still read-only like every other (authoring
 * happens through the model, not the registry).
 */
class NativeCalendarItemSource implements CalendarItemSource {

    public static function getKey(): string {
        return 'native';
    }

    public function getItems(
        CalendarSubject $subject,
        string $start_utc,
        string $end_utc,
        string $visibility
    ): array {
        $entries = new MultiCalendarEntry([
            'subject_type' => $subject->type,
            'subject_id'   => $subject->id,
            'deleted'      => false,
        ]);
        $entries->load();

        $items = [];
        foreach ($entries as $entry) {
            $s = $entry->get('cal_start_utc');
            $e = $entry->get('cal_end_utc');
            if (!$s) {
                continue;
            }
            if (!$e) {
                $e = $s;
            }
            // Keep only entries overlapping the requested window.
            if (!($s < $end_utc && $e > $start_utc)) {
                continue;
            }
            $items[] = new CalendarItem([
                'start_utc'           => $s,
                'end_utc'             => $e,
                'all_day'             => (bool)$entry->get('cal_all_day'),
                'type'                => $entry->get('cal_type') ?: CalendarItem::TYPE_PERSONAL,
                'title'               => $entry->get('cal_title') ?: 'Busy',
                'url'                 => '/profile/calendar?edit_entry=' . $entry->key,
                'blocks_availability' => (bool)$entry->get('cal_blocks_availability'),
                'visibility'          => $visibility,
                'source'              => self::getKey(),
                'source_key'          => 'native:cal-' . $entry->key,
            ]);
        }
        return $items;
    }
}
