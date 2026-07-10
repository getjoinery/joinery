<?php
/**
 * EventRecipientProvider — targets bulk email at an event's active registrants.
 *
 * MOVED-TO-PLUGIN (phase 4): this provider moves to event_manager and registers
 * from event_manager serve.php once events are a plugin. It lives in core while
 * the events tables are still core so recipient-group targeting keeps working.
 */
require_once(PathHelper::getIncludePath('includes/RecipientGroupProviderRegistry.php'));

class EventRecipientProvider implements RecipientGroupProvider {

    public function key(): string {
        return 'event';
    }

    public function label(): string {
        return 'Event attendees';
    }

    public function options(): array {
        require_once(PathHelper::getIncludePath('data/events_class.php'));
        $events = new MultiEvent(array(), array('start_time' => 'DESC'), NULL, NULL);
        $events->load();
        return $events->get_dropdown_array();
    }

    public function resolve(int $reference_id): array {
        require_once(PathHelper::getIncludePath('data/event_registrants_class.php'));
        $registrants = new MultiEventRegistrant(array('event_id' => $reference_id, 'expired' => false), NULL);
        $registrants->load();
        $user_ids = array();
        foreach ($registrants as $registrant) {
            $user_ids[] = (int)$registrant->get('evr_usr_user_id');
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
        return $event->get('evt_name');
    }
}
