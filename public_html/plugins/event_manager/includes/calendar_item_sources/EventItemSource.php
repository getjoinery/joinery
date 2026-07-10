<?php
require_once(PathHelper::getIncludePath('includes/calendar/CalendarItemSource.php'));
require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));
require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_registrants_class.php'));

/**
 * Projects a subject's event commitments onto their calendar: events they lead,
 * plus events they are actively registered for. Recurring events expand through
 * Event::get_instances_for_range() so unmaterialized (virtual) occurrences show
 * up too. Every event commitment blocks availability.
 */
class EventItemSource implements CalendarItemSource {

    public static function getKey(): string {
        return 'events';
    }

    public function getItems(
        CalendarSubject $subject,
        string $start_utc,
        string $end_utc,
        string $visibility
    ): array {
        // Events are owned by users; nothing to project for other subject types yet.
        $user_id = $subject->getUserId();
        if (!$user_id) {
            return [];
        }

        $start_date = substr($start_utc, 0, 10);
        // Pad the end date by one day so an instance late on the last day is caught.
        $end_date = date('Y-m-d', strtotime(substr($end_utc, 0, 10) . ' +1 day'));

        $items = [];
        $seen = [];   // source_key dedup across led + registered

        $events = $this->collectEvents($user_id);
        foreach ($events as $event) {
            foreach ($this->itemsForEvent($event, $start_utc, $end_utc, $start_date, $end_date, $visibility) as $item) {
                if (isset($seen[$item->source_key])) {
                    continue;
                }
                $seen[$item->source_key] = true;
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Distinct Event objects the user leads or is actively registered for.
     * Materialized recurring children are excluded from the led set — they are
     * surfaced by their parent's expansion instead.
     *
     * @return Event[]
     */
    private function collectEvents(int $user_id): array {
        $events = [];

        $led = new MultiEvent([
            'user_id_leader' => $user_id,
            'deleted' => false,
            'exclude_materialized_instances' => true,
        ]);
        $led->load();
        foreach ($led as $event) {
            $events[$event->key] = $event;
        }

        $registrations = new MultiEventRegistrant([
            'user_id' => $user_id,
            'deleted' => false,
            'expired' => false,
        ]);
        $registrations->load();
        foreach ($registrations as $reg) {
            $eid = $reg->get('evr_evt_event_id');
            if (!$eid || isset($events[$eid])) {
                continue;
            }
            $event = new Event($eid, true);
            if ($event->key && !$event->get('evt_delete_time')) {
                $events[$eid] = $event;
            }
        }

        return array_values($events);
    }

    /**
     * Build CalendarItems for one event within the window, expanding recurrence.
     *
     * @return CalendarItem[]
     */
    private function itemsForEvent(
        Event $event,
        string $start_utc,
        string $end_utc,
        string $start_date,
        string $end_date,
        string $visibility
    ): array {
        $out = [];

        if ($event->is_recurring_parent()) {
            foreach ($event->get_instances_for_range($start_date, $end_date) as $instance) {
                // Materialized instances are Event objects; virtual ones are stdClass.
                if (is_a($instance, 'Event')) {
                    $i_start = $instance->get('evt_start_time');
                    $i_end   = $instance->get('evt_end_time');
                    $i_name  = $instance->get('evt_name');
                    $i_link  = $instance->get('evt_link');
                    $i_date  = $instance->get('evt_materialized_instance_date');
                } else {
                    $i_start = $instance->evt_start_time ?? null;
                    $i_end   = $instance->evt_end_time ?? null;
                    $i_name  = $instance->evt_name ?? null;
                    $i_link  = $instance->evt_link ?? null;
                    $i_date  = $instance->instance_date ?? null;
                }
                if (!$this->overlaps($i_start, $i_end, $start_utc, $end_utc)) {
                    continue;
                }
                $url = $i_link ? ('/event/' . $i_link . ($i_date ? '/' . $i_date : '')) : null;
                $key = 'events:evt-' . $event->key . '-' . ($i_date ?: $i_start);
                $out[] = $this->makeItem($key, $i_start, $i_end, $i_name, $url, $visibility);
            }
            return $out;
        }

        $i_start = $event->get('evt_start_time');
        $i_end   = $event->get('evt_end_time');
        if (!$this->overlaps($i_start, $i_end, $start_utc, $end_utc)) {
            return $out;
        }
        $link = $event->get('evt_link');
        $url  = $link ? ('/event/' . $link) : null;
        $key  = 'events:evt-' . $event->key;
        $out[] = $this->makeItem($key, $i_start, $i_end, $event->get('evt_name'), $url, $visibility);

        return $out;
    }

    private function makeItem($key, $start, $end, $name, $url, string $visibility): CalendarItem {
        $item = new CalendarItem([
            'start_utc'           => $start,
            'end_utc'             => $end ?: $start,
            'type'                => CalendarItem::TYPE_EVENT,
            'title'               => $name,
            'url'                 => $url,
            'blocks_availability' => true,
            'visibility'          => $visibility,
            'source'              => self::getKey(),
            'source_key'          => $key,
        ]);
        return $item;
    }

    /** True when [a_start,a_end] intersects [b_start,b_end] (UTC strings). */
    private function overlaps($a_start, $a_end, $b_start, $b_end): bool {
        if (!$a_start) {
            return false;
        }
        if (!$a_end) {
            $a_end = $a_start;
        }
        return $a_start < $b_end && $a_end > $b_start;
    }
}
