<?php
/**
 * EventRegistrationGate — gates content on registration for an event.
 *
 * A file or video gated to this provider is viewable only by users with an
 * active (non-expired) registration for the referenced event.
 *
 * Registered from event_manager's serve.php.
 */
require_once(PathHelper::getIncludePath('includes/AccessGateRegistry.php'));

class EventRegistrationGate implements AccessGateProvider {

    public function key(): string {
        return 'event_registration';
    }

    public function label(): string {
        return 'Event registration';
    }

    public function options(): array {
        require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));
        $events = new MultiEvent(array(), array('start_time' => 'DESC'), NULL, NULL);
        $events->load();
        return $events->get_dropdown_array();
    }

    public function userMayAccess(int $user_id, int $ref): bool {
        require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_registrants_class.php'));
        $registrations = new MultiEventRegistrant(
            array('user_id' => $user_id, 'event_id' => $ref, 'expired' => false),
            NULL, NULL, NULL
        );
        return $registrations->count_all() > 0;
    }
}
