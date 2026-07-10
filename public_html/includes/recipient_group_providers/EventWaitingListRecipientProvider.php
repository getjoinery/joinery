<?php
/**
 * EventWaitingListRecipientProvider — targets bulk email at an event's waiting list.
 *
 * MOVED-TO-PLUGIN (phase 4): moves to event_manager and registers from its
 * serve.php once events are a plugin. Lives in core while the events tables are.
 */
require_once(PathHelper::getIncludePath('includes/RecipientGroupProviderRegistry.php'));

class EventWaitingListRecipientProvider implements RecipientGroupProvider {

    public function key(): string {
        return 'event_waiting_list';
    }

    public function label(): string {
        return 'Event waiting list';
    }

    public function options(): array {
        require_once(PathHelper::getIncludePath('data/events_class.php'));
        $events = new MultiEvent(array(), array('start_time' => 'DESC'), NULL, NULL);
        $events->load();
        return $events->get_dropdown_array();
    }

    public function resolve(int $reference_id): array {
        require_once(PathHelper::getIncludePath('data/event_waiting_lists_class.php'));
        $waiting = new MultiWaitingList(array('event_id' => $reference_id), NULL);
        $waiting->load();
        $user_ids = array();
        foreach ($waiting as $entry) {
            $user_ids[] = (int)$entry->get('ewl_usr_user_id');
        }
        return $user_ids;
    }

    public function reference_label(int $reference_id): string {
        require_once(PathHelper::getIncludePath('data/events_class.php'));
        try {
            $event = new Event($reference_id, TRUE);
        } catch (\Throwable $e) {
            return 'Event #' . $reference_id;
        }
        if (!$event->key) {
            return 'Event #' . $reference_id;
        }
        return $event->get('evt_name') . ' (waiting list)';
    }
}
