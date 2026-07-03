<?php
require_once(PathHelper::getIncludePath('includes/calendar/CalendarItemSource.php'));
require_once(PathHelper::getIncludePath('data/calendar_entry_class.php'));
require_once(PathHelper::getIncludePath('data/calendar_entry_exception_class.php'));

/**
 * Projects native calendar entries — the appointments and blocked-out time a
 * subject creates directly on their own calendar (`cal_entries`). Handles both
 * standalone entries and recurring parents (which are expanded virtually).
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
        $base_opts = [
            'subject_type' => $subject->type,
            'subject_id'   => $subject->id,
            'deleted'      => false,
        ];

        $items = [];

        // 1. Non-recurring entries that overlap the window.
        $non_recurring = new MultiCalendarEntry(array_merge($base_opts, ['non_recurring_only' => true]));
        $non_recurring->load();

        foreach ($non_recurring as $entry) {
            $s = $entry->get('cal_start_utc');
            $e = $entry->get('cal_end_utc');
            if (!$s) {
                continue;
            }
            if (!$e) {
                $e = $s;
            }
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
                'entry_id'            => (int)$entry->key,
            ]);
        }

        // 2. Recurring parents whose series overlaps the window — expand via get_instances_for_range().
        $window_start_date = substr($start_utc, 0, 10); // Y-m-d (UTC date, close enough for pre-filter)
        $recurring = new MultiCalendarEntry(array_merge($base_opts, [
            'recurring_only'        => true,
            'start_utc_before'      => $end_utc,
            'end_date_null_or_gte'  => $window_start_date,
        ]));
        $recurring->load();

        // Collect parents in one pass, then load every parent's exceptions in a
        // single query (avoids an N+1 of one exception query per recurring parent).
        $parents = [];
        foreach ($recurring as $parent) {
            $parents[] = $parent;
        }

        $exc_by_parent = [];
        if ($parents) {
            $parent_ids = [];
            foreach ($parents as $p) {
                $parent_ids[] = $p->key;
            }
            $exc = new MultiCalEntryException(['cal_entry_ids' => $parent_ids]);
            $exc->load();
            foreach ($exc as $row) {
                $exc_by_parent[(int)$row->get('cex_cal_entry_id')][$row->get('cex_exception_date')] = true;
            }
        }

        foreach ($parents as $parent) {
            $parent_exc = $exc_by_parent[(int)$parent->key] ?? [];
            $instance_items = $parent->get_instances_for_range($start_utc, $end_utc, $visibility, $parent_exc);
            foreach ($instance_items as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
