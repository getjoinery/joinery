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

// A date against an event that has no recurrence describes an occurrence that
// does not exist. The event page 404s this; the feed agrees.
if ($date && !$event->is_recurring_parent()) {
	LibraryFunctions::display_404_page();
	return;
}

if ($event->is_recurring_parent()) {
	if ($date) {
		// Shared with the event page so a date resolves the same way in both: a
		// URL that 404s as a page must not still hand out a calendar entry for
		// an occurrence that never happens.
		$resolved = $event->resolve_instance_for_date($date);
		if (!$resolved) {
			LibraryFunctions::display_404_page();
			return;
		}
		$target_event = $resolved;
		$instance_date = $date;
	} else {
		// No date: next upcoming instance.
		$next_dates = $event->compute_occurrence_dates(date('Y-m-d'), 1);
		if (empty($next_dates)) {
			// A finished series has nothing to hand out. Emitting the parent
			// here would publish the series template as if it were an event,
			// carrying the pattern's start date as its time.
			LibraryFunctions::display_404_page();
			return;
		}
		$next_date = $next_dates[0];
		$materialized = $event->_get_materialized_instance_for_date($next_date);
		$target_event = $materialized ?: $event->create_virtual_instance($next_date);
		$instance_date = $next_date;
	}
}

$vevent = IcsHelper::generateVevent($target_event, $instance_date);
$ics = IcsHelper::wrapInVcalendar($vevent);
$filename = $slug . ($instance_date ? '-' . $instance_date : '') . '.ics';
IcsHelper::outputIcs($ics, $filename, false);
return true;
