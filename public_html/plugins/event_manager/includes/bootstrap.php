<?php
/**
 * Event Manager plugin bootstrap - the plugin's declared load point (the
 * top-level `bootstrap` key in plugin.json), loaded once per request or
 * command by PluginBootstraps whenever the plugin is active. Registrations
 * only.
 *
 * Registers the event_registration fulfilment provider (the store and
 * event_manager seam), so a product that sells event places is known to
 * every save of it, on a page or on the command line; and events and
 * locations as SEO entities, so the command-line sitemap and SEO sweep know
 * them too.
 *
 * @version 1.1 - events and locations register as SEO entities here
 * @version 1.0
 */

if (class_exists('FulfillmentRegistry')) {
	require_once(PathHelper::getIncludePath('plugins/event_manager/includes/fulfillment_providers/EventRegistrationFulfillment.php'));
	FulfillmentRegistry::register(new EventRegistrationFulfillment());
}

// ---- SEO entities: event + location ----
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
