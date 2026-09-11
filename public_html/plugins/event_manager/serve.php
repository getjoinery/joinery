<?php
/**
 * Event Manager plugin request bootstrap.
 *
 * Core loads this on every request for the active event_manager plugin (inside
 * an output buffer that is discarded — only the static registrations persist).
 * It wires the events toolkit into the platform's extension points. Top-level
 * event URLs are declared in core serve.php with the `plugin => 'event_manager'`
 * option, not here — plugins cannot own top-level dynamic routes.
 *
 * The calendar item source (includes/calendar_item_sources/EventItemSource.php)
 * needs no registration here — CalendarItemSourceRegistry auto-discovers it by
 * scanning plugin item-source directories.
 *
 * @version 1.0.0
 */

// ---- SEO entities: event + location (moved out of core SeoPageMetadata defaults) ----
require_once(PathHelper::getIncludePath('data/seo_page_metadata_class.php'));
SeoPageMetadata::register_entity_class(
	'event', 'Event', 'MultiEvent',
	'plugins/event_manager/data/events_class.php', 'event',
	'/plugins/event_manager/admin/admin_event_edit?evt_event_id=', 'article'
);
SeoPageMetadata::register_entity_class(
	'location', 'Location', 'MultiLocation',
	'plugins/event_manager/data/locations_class.php', 'location',
	'/plugins/event_manager/admin/admin_location_edit?loc_location_id=', 'website'
);

// ---- Tier gated-content summary: Events (moved out of core defaults) ----
require_once(PathHelper::getIncludePath('includes/TierGatedContentRegistry.php'));
TierGatedContentRegistry::register('Events', 'evt_events', 'evt_tier_min_level', 'evt_delete_time');

// ---- Entity photos: event + location (moved out of core EntityPhotoRegistry defaults) ----
require_once(PathHelper::getIncludePath('includes/EntityPhotoRegistry.php'));
EntityPhotoRegistry::register('event', 'Event', 'plugins/event_manager/data/events_class.php');
EntityPhotoRegistry::register('location', 'Location', 'plugins/event_manager/data/locations_class.php');

// ---- Email recipient-group providers: event + event waiting list ----
require_once(PathHelper::getIncludePath('includes/RecipientGroupProviderRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/event_manager/includes/recipient_group_providers/EventRecipientProvider.php'));
require_once(PathHelper::getIncludePath('plugins/event_manager/includes/recipient_group_providers/EventWaitingListRecipientProvider.php'));
RecipientGroupProviderRegistry::register(new EventRecipientProvider());
RecipientGroupProviderRegistry::register(new EventWaitingListRecipientProvider());

// ---- Content access gate: event registration (files/videos gated on registration) ----
require_once(PathHelper::getIncludePath('includes/AccessGateRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/event_manager/includes/access_gate_providers/EventRegistrationGate.php'));
AccessGateRegistry::register(new EventRegistrationGate());

// ---- Message context resolver: event (labels entity-attached messages) ----
require_once(PathHelper::getIncludePath('includes/MessageContextRegistry.php'));
MessageContextRegistry::register('event', function (int $id): ?array {
	require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));
	try {
		$event = new Event($id, TRUE);
	} catch (\Throwable $e) {
		return null;
	}
	if (!$event->key) {
		return null;
	}
	return [
		'label' => '(' . $event->key . ') ' . $event->get('evt_name'),
		'url'   => '/plugins/event_manager/admin/admin_event?evt_event_id=' . $event->key,
	];
});

// ---- Product fulfillment: event registration (the store↔event_manager seam) ----
require_once(PathHelper::getIncludePath('plugins/store/includes/FulfillmentRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/event_manager/includes/fulfillment_providers/EventRegistrationFulfillment.php'));
FulfillmentRegistry::register(new EventRegistrationFulfillment());

// ---- Profile dashboard sections: upcoming events + pending surveys ----
// The web profile and the native-app dashboard summary iterate this registry;
// with event_manager inactive nothing is contributed and the sections are absent.
require_once(PathHelper::getIncludePath('includes/ProfileDashboardRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/event_manager/includes/profile_dashboard_provider.php'));
ProfileDashboardRegistry::register('upcoming_events', 'event_manager_dashboard_upcoming_events');
ProfileDashboardRegistry::register('pending_surveys', 'event_manager_dashboard_pending_surveys');

// ---- Admin-user detail panel: event registrations (add/remove + session visits) ----
require_once(PathHelper::getIncludePath('includes/AdminUserPanelRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/event_manager/includes/admin_user_panels/EventsPanel.php'));
AdminUserPanelRegistry::register(new EventsPanel());
