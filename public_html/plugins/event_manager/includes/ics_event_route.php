<?php
/**
 * ICS route handler: single public event download.
 *
 * Serves /event/{slug}.ics and /event/{slug}/{date}.ics. Resolved from
 * event_manager by the core route's `plugin` option; reads the named route
 * params ($params['slug'], optional $params['date']). Plugin-active and
 * events_active gating already ran in RouteHelper::handleDynamicRoute, so this
 * file just resolves the event and emits the calendar.
 */
require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));
require_once(PathHelper::getIncludePath('plugins/event_manager/data/locations_class.php'));
require_once(PathHelper::getIncludePath('includes/IcsHelper.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$slug = $params['slug'] ?? null;
$date = $params['date'] ?? null;
if (!$slug) {
	LibraryFunctions::display_404_page();
	return;
}

$event = Event::get_by_link($slug);
if (!$event) {
	LibraryFunctions::display_404_page();
	return;
}

// Must be public.
if ($event->get('evt_visibility') != Event::VISIBILITY_PUBLIC) {
	LibraryFunctions::display_404_page();
	return;
}

$instance_date = null;
$target_event = $event;

if ($event->is_recurring_parent()) {
	if ($date) {
		// Resolve to the materialized instance or a virtual one.
		$materialized = $event->_get_materialized_instance_for_date($date);
		if ($materialized) {
			$target_event = $materialized;
			$instance_date = $date;
		} else {
			$target_event = $event->create_virtual_instance($date);
			$instance_date = $date;
		}
	} else {
		// No date: next upcoming instance.
		$next_dates = $event->compute_occurrence_dates(date('Y-m-d'), 1);
		if (!empty($next_dates)) {
			$next_date = $next_dates[0];
			$materialized = $event->_get_materialized_instance_for_date($next_date);
			$target_event = $materialized ?: $event->create_virtual_instance($next_date);
			$instance_date = $next_date;
		}
	}
}

$vevent = IcsHelper::generateVevent($target_event, $instance_date);
$ics = IcsHelper::wrapInVcalendar($vevent);
$filename = $slug . ($instance_date ? '-' . $instance_date : '') . '.ics';
IcsHelper::outputIcs($ics, $filename, false);
return true;
