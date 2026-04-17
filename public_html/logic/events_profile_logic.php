<?php
/**
 * Events profile logic — full event history with status filtering and pagination.
 *
 * @version 1.0
 */

function events_profile_logic($get_vars, $post_vars) {
	$page_vars = array();
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));
	require_once(PathHelper::getIncludePath('data/event_registrants_class.php'));
	require_once(PathHelper::getIncludePath('data/event_sessions_class.php'));

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;
	$session->check_permission(0);

	$user = new User($session->get_user_id(), TRUE);
	$page_vars['user'] = $user;

	// Status filter
	$status_filter = isset($get_vars['status']) ? $get_vars['status'] : 'all';
	$page_vars['status_filter'] = $status_filter;

	// Load all registrations, then filter in PHP since status depends on event + registrant data
	$event_registrants = new MultiEventRegistrant(
		array('user_id' => $user->key, 'deleted' => false),
		array('evr_create_time' => 'DESC')
	);
	$event_registrants->load();

	$all_events = array();
	$now_utc = gmdate('Y-m-d H:i:s');

	foreach ($event_registrants as $event_registrant) {
		$event = new Event($event_registrant->get('evr_evt_event_id'), TRUE);
		if (!$event || $event->get('evt_delete_time')) {
			continue;
		}

		$tevent = array();
		$tevent['event_name'] = $event->get('evt_name');

		// Build time display
		$next_session = $event->get_next_session();
		$time = '';
		$tz = $event->get('evt_timezone');
		if ($next_session) {
			$time = '<b>Next session: ';
			if ($tz != $session->get_timezone()) {
				$time .= $next_session->get_time_string($session->get_timezone());
			} else {
				$time .= $next_session->get_time_string($tz);
			}
			$time .= '</b>';
		} elseif ($event->get('evt_status') != Event::STATUS_COMPLETED && $event->get('evt_status') != Event::STATUS_CANCELED) {
			if ($tz != $session->get_timezone()) {
				$time .= $event->get_time_string($session->get_timezone());
			} else {
				$time .= $event->get_time_string($tz);
			}
		}
		$tevent['event_time'] = $time;

		// Calendar links for active events
		$tevent['calendar_links'] = array();
		if ($event->get('evt_status') != Event::STATUS_COMPLETED && $event->get('evt_status') != Event::STATUS_CANCELED) {
			$tevent['calendar_links'] = $event->get_add_to_calendar_links();
		}

		// Event link
		if ($event->get('evt_session_display_type') == 2) {
			$tevent['event_link'] = '/profile/event_sessions_course?evt_event_id=' . $event->key;
		} else {
			$tevent['event_link'] = '/profile/event_sessions?evt_event_id=' . $event->key;
		}

		// Determine status
		if ($event_registrant->get('evr_expires_time') && $event_registrant->get('evr_expires_time') < $now_utc) {
			$tevent['event_status'] = 'Expired';
			$tevent['event_expires'] = '';
		} elseif ($event->get('evt_status') == Event::STATUS_ACTIVE) {
			$tevent['event_status'] = 'Active';
			$tevent['event_expires'] = '';
			if ($event_registrant->get('evr_expires_time')) {
				$tevent['event_expires'] = LibraryFunctions::convert_time($event_registrant->get('evr_expires_time'), 'UTC', $session->get_timezone());
			}
		} elseif ($event->get('evt_status') == Event::STATUS_CANCELED) {
			$tevent['event_status'] = 'Canceled';
			$tevent['event_expires'] = '';
		} elseif ($event->get('evt_status') == Event::STATUS_COMPLETED) {
			$tevent['event_status'] = 'Completed';
			$tevent['event_expires'] = '';
		} else {
			$tevent['event_status'] = 'Active';
			$tevent['event_expires'] = '';
		}

		// Apply status filter
		if ($status_filter != 'all' && strtolower($tevent['event_status']) != strtolower($status_filter)) {
			continue;
		}

		$all_events[] = $tevent;
	}

	// Manual pagination on filtered results
	$numperpage = 10;
	$total = count($all_events);
	$page_offset = isset($get_vars['offset']) ? max(0, (int)$get_vars['offset']) : 0;
	$pager = new Pager(array('numrecords' => $total, 'numperpage' => $numperpage, 'offset' => $page_offset));
	$page_vars['event_registrations'] = array_slice($all_events, $page_offset, $numperpage);
	$page_vars['num_events'] = $total;
	$page_vars['pager'] = $pager;

	$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);

	return LogicResult::render($page_vars);
}
?>
