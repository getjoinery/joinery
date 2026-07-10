<?php
/**
 * EventRegistrationFulfillment — fulfills an event-ticket product by registering
 * the buyer for the event (single event) or for every event in a bundle group.
 *
 * Owns everything event-specific about a ticket purchase: creating the
 * registrant(s), writing the order item's registrant link, the "You're
 * registered" notification, the event.registered admin signal, auto-attaching a
 * required pre-purchase survey, and surfacing an optional post-purchase survey
 * on the confirmation page. The store never has to know events exist.
 *
 * MOVED-TO-PLUGIN (phase 4): moves to event_manager and registers from its
 * serve.php once events are a plugin. Lives in core while events are core.
 */
require_once(PathHelper::getIncludePath('plugins/store/includes/FulfillmentRegistry.php'));

class EventRegistrationFulfillment implements FulfillmentProvider {

    public function key(): string {
        return 'event_registration';
    }

    public function label(): string {
        return 'Event registration';
    }

    public function options(): array {
        require_once(PathHelper::getIncludePath('data/events_class.php'));
        $events = new MultiEvent(array(), array('start_time' => 'DESC'), NULL, NULL);
        $events->load();
        return $events->get_dropdown_array();
    }

    public function extraRequirements(Product $product, int $ref): array {
        $out = array();
        if ($ref <= 0) {
            return $out; // bundles don't auto-attach a survey requirement
        }
        require_once(PathHelper::getIncludePath('data/events_class.php'));
        $event = new Event($ref, TRUE);
        if ($event->get('evt_svy_survey_id') && $event->get('evt_survey_display') === 'required_before_purchase') {
            require_once(PathHelper::getIncludePath('plugins/store/includes/requirements/AbstractProductRequirement.php'));
            require_once(PathHelper::getIncludePath('plugins/store/includes/requirements/SurveyRequirement.php'));
            $out[] = AbstractProductRequirement::createInstance('SurveyRequirement', array(
                'survey_id' => $event->get('evt_svy_survey_id'),
                'event_id'  => $event->key,
            ));
        }
        return $out;
    }

    public function fulfill(User $user, Product $product, OrderItem $order_item, Order $order, int $ref): array {
        require_once(PathHelper::getIncludePath('data/events_class.php'));

        if ($ref > 0) {
            // Single event ticket.
            $event = new Event($ref, TRUE);
            $registrant = $event->add_registrant($user->key, $order_item, NULL, $product->get('pro_expires'));
            $order_item->set('odi_evr_event_registrant_id', $registrant->key);
            $order_item->save();

            $this->notify_and_signal($user, $product, $order, $event);

            $result = array(
                'ref_id' => $registrant->key,
                'label'  => $event->get('evt_name'),
                'labels' => null,
            );
            // Optional post-purchase survey shown on the confirmation page.
            if ($event->get('evt_svy_survey_id') && $event->get('evt_survey_display') === 'optional_at_confirmation') {
                $result['confirmation_survey'] = array(
                    'survey_id'  => $event->get('evt_svy_survey_id'),
                    'event_id'   => $event->key,
                    'event_name' => $event->get('evt_name'),
                );
            }
            return $result;
        }

        // Event bundle: register for every event in the bundle group.
        if ($product->get('pro_grp_group_id')) {
            require_once(PathHelper::getIncludePath('data/groups_class.php'));
            $group = new Group($product->get('pro_grp_group_id'), TRUE);
            $labels = array();
            $last_registrant = null;
            foreach ($group->get_member_list() as $group_member) {
                $bundle_event = new Event($group_member->get('grm_foreign_key_id'), TRUE);
                $labels[] = $bundle_event->get('evt_name');
                $last_registrant = $bundle_event->add_registrant($user->key, $order_item, $product->get('pro_grp_group_id'), NULL);
                $last_registrant->save();
            }
            return array(
                'ref_id' => $last_registrant ? $last_registrant->key : null,
                'label'  => null,
                'labels' => $labels,
            );
        }

        return array('ref_id' => null, 'label' => null, 'labels' => null);
    }

    public function displayReference(int $ref): string {
        if ($ref <= 0) {
            return '';
        }
        require_once(PathHelper::getIncludePath('data/events_class.php'));
        try {
            $event = new Event($ref, TRUE);
        } catch (\Throwable $e) {
            return 'Event #' . $ref;
        }
        if (!$event->key) {
            return 'Event #' . $ref;
        }
        return '<a href="/admin/admin_event?evt_event_id=' . $event->key . '">'
            . htmlspecialchars($event->get('evt_name')) . '</a>';
    }

    /** Best-effort buyer notification + admin signal for a single-event ticket. */
    private function notify_and_signal(User $user, Product $product, Order $order, Event $event): void {
        try {
            require_once(PathHelper::getIncludePath('data/notifications_class.php'));
            Notification::create_notification(
                $user->key,
                'event',
                "You're registered for " . $event->get('evt_name'),
                null,
                '/event/' . $event->get('evt_link'),
                null
            );
        } catch (\Throwable $e) { /* notification system not available */ }

        try {
            require_once(PathHelper::getIncludePath('includes/SignalBus.php'));
            SignalBus::dispatch('event.registered', array(
                'event_id'        => $event->key,
                'product_id'      => $product->key,
                'product_name'    => $product->get('pro_name'),
                'user_id'         => $user->key,
                'registrant_name' => $user->display_name(),
                'order_id'        => $order->key,
                'source_user_id'  => $user->key,
            ));
        } catch (\Throwable $e) { /* signal bus not available */ }
    }
}
