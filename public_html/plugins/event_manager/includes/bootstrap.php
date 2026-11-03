<?php
/**
 * Event Manager plugin bootstrap - the plugin's declared load point (the
 * top-level `bootstrap` key in plugin.json), loaded once per request or
 * command by PluginBootstraps whenever the plugin is active. Registrations
 * only.
 *
 * Registers the event_registration fulfilment provider (the store and
 * event_manager seam), so a product that sells event places is known to
 * every save of it, on a page or on the command line.
 *
 * @version 1.0
 */

if (class_exists('FulfillmentRegistry')) {
	require_once(PathHelper::getIncludePath('plugins/event_manager/includes/fulfillment_providers/EventRegistrationFulfillment.php'));
	FulfillmentRegistry::register(new EventRegistrationFulfillment());
}
