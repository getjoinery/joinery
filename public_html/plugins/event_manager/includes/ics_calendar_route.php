<?php
/**
 * ICS route handler: public calendar feed (/events/calendar.ics).
 *
 * Resolved from event_manager by the core route's `plugin` option. Plugin-active
 * and events_active gating already ran in RouteHelper::handleDynamicRoute.
 * Emits upcoming non-recurring public events plus recurring instances expanded
 * over the next six months.
 */
require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));
require_once(PathHelper::getIncludePath('plugins/event_manager/data/locations_class.php'));
require_once(PathHelper::getIncludePath('includes/IcsHelper.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$vevents = [];

// Upcoming non-recurring public events.
$events = new MultiEvent([
	'visibility' => Event::VISIBILITY_PUBLIC,
	'deleted' => false,
	'upcoming' => true,
	'exclude_recurring_parents' => true
], ['evt_start_time' => 'ASC']);
$events->load();

foreach ($events as $evt) {
	$vevent = IcsHelper::generateVevent($evt);
	if ($vevent) {
		$vevents[] = $vevent;
	}
}

// Recurring parents expanded to instances over the next 6 months.
$parents = new MultiEvent([
	'visibility' => Event::VISIBILITY_PUBLIC,
	'deleted' => false,
	'only_recurring_parents' => true
]);
$parents->load();

$range_start = date('Y-m-d');
$range_end = date('Y-m-d', strtotime('+6 months'));

foreach ($parents as $parent) {
	$instances = $parent->get_instances_for_range($range_start, $range_end);
	foreach ($instances as $instance) {
		$inst_date = null;
		if ($instance instanceof SystemBase) {
			$inst_date = $instance->get('evt_materialized_instance_date');
		} elseif (isset($instance->instance_date)) {
			$inst_date = $instance->instance_date;
		}
		$vevent = IcsHelper::generateVevent($instance, $inst_date);
		if ($vevent) {
			$vevents[] = $vevent;
		}
	}
}

$vevents_string = implode("\r\n", $vevents);
$ics = IcsHelper::wrapInVcalendar($vevents_string, true);
IcsHelper::outputIcs($ics, 'calendar.ics', true);
return true;
