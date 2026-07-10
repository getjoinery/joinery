<?php
/**
 * Event Manager profile-dashboard providers.
 *
 * Contributes the "upcoming events" and "pending surveys" sections to the
 * member profile page and the native-app dashboard summary. Registered from
 * event_manager's serve.php; with the plugin inactive neither section is
 * contributed (both keys simply absent — the native client renders from
 * present keys, the web profile skips the section).
 *
 * The item `data` payloads reproduce the exact keys the native dashboard has
 * always emitted — upcoming_events[{registrant_id,event_id,event_name,
 * next_session_time,expires_time,web_url}], upcoming_event_count, and
 * pending_surveys[{survey_id,event_id,event_name}] — so the app contract is
 * unchanged.
 */

/**
 * The user's registrant collection, loaded once per request. Both providers
 * below run back-to-back on every dashboard render and iterate the identical
 * collection — this keeps it a single query.
 */
function event_manager_dashboard_registrants($user) {
	static $cache = array();
	if (!array_key_exists($user->key, $cache)) {
		$regs = new MultiEventRegistrant(
			array('user_id' => $user->key, 'deleted' => false),
			array('evr_create_time' => 'DESC')
		);
		$regs->load();
		$cache[$user->key] = $regs;
	}
	return $cache[$user->key];
}

/** Upcoming (active, non-expired) event registrations — first 3, with a count stat. */
function event_manager_dashboard_upcoming_events($user) {
	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('events_active')) {
		return null;
	}
	require_once(PathHelper::getIncludePath('includes/ProfileDashboardRegistry.php'));
	require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));
	require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_registrants_class.php'));

	$session = SessionControl::get_instance();
	$now_utc = gmdate('Y-m-d H:i:s');

	$regs = event_manager_dashboard_registrants($user);

	$active = array();
	$active_count = 0;
	foreach ($regs as $reg) {
		$event = new Event($reg->get('evr_evt_event_id'), TRUE);
		if (!$event || $event->get('evt_delete_time')) {
			continue;
		}
		$is_expired = $reg->get('evr_expires_time') && $reg->get('evr_expires_time') < $now_utc;
		$is_active = !$is_expired && $event->get('evt_status') == Event::STATUS_ACTIVE;
		if (!$is_active) {
			continue;
		}
		$active_count++;
		if (count($active) >= 3) {
			continue;
		}

		$next_session = $event->get_next_session();
		$web_url = $event->get('evt_session_display_type') == 2
			? '/profile/event_sessions_course?evt_event_id=' . $event->key
			: '/profile/event_sessions?evt_event_id=' . $event->key;

		// Web display: "Next session: ..." (or the event's own time string).
		$tz = $event->get('evt_timezone');
		if ($next_session) {
			$time_html = '<b>Next session: '
				. ($tz != $session->get_timezone() ? $next_session->get_time_string($session->get_timezone()) : $next_session->get_time_string($tz))
				. '</b>';
			$sort_time = $next_session->get('evs_start_time');
		} else {
			$time_html = ($tz != $session->get_timezone() ? $event->get_time_string($session->get_timezone()) : $event->get_time_string($tz));
			$sort_time = $event->get('evt_start_time') ?: '9999-12-31';
		}

		$expires = $reg->get('evr_expires_time');
		$badge = $expires
			? 'Expires ' . LibraryFunctions::convert_time($expires, 'UTC', $session->get_timezone())
			: 'Active';

		$data = array(
			'registrant_id'     => (int)$reg->key,
			'event_id'          => (int)$event->key,
			'event_name'        => $event->get('evt_name'),
			'next_session_time' => $next_session ? $next_session->get('evs_start_time') : ($event->get('evt_start_time') ?: null),
			'expires_time'      => $expires ?: null,
			'web_url'           => $web_url,
		);

		$item = new ProfileDashboardItem(
			$data,
			$event->get('evt_name'),
			null,
			$time_html ?: null,
			$badge,
			$web_url
		);
		$active[] = array('sort_time' => $sort_time, 'item' => $item);
	}

	usort($active, function ($a, $b) {
		return strcmp($a['sort_time'], $b['sort_time']);
	});
	$items = array_map(function ($entry) { return $entry['item']; }, $active);

	$stat = new ProfileDashboardStat('upcoming_event_count', 'Upcoming Events', $active_count, '/profile/events');
	// Matches the pre-extraction web profile: the Upcoming Events card always
	// shows, with this message when the member has none.
	return new ProfileDashboardSection('upcoming_events', 'Upcoming Events', '/profile/events', $stat, $items, 'No upcoming events.');
}

/** Surveys the user still owes for events they registered for. */
function event_manager_dashboard_pending_surveys($user) {
	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('events_active')) {
		return null;
	}
	require_once(PathHelper::getIncludePath('includes/ProfileDashboardRegistry.php'));
	require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));
	require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_registrants_class.php'));

	$now_utc = gmdate('Y-m-d H:i:s');

	$regs = event_manager_dashboard_registrants($user);

	$items = array();
	foreach ($regs as $reg) {
		if ($reg->get('evr_survey_completed')) {
			continue;
		}
		$event = new Event($reg->get('evr_evt_event_id'), TRUE);
		if (!$event->get('evt_svy_survey_id')) {
			continue;
		}
		$display = $event->get('evt_survey_display');
		if ($display !== 'optional_at_confirmation' && $display !== 'after_event') {
			continue;
		}
		if ($display === 'after_event') {
			$end_time = $event->get('evt_end_time') ?: $event->get('evt_start_time');
			if ($end_time > $now_utc) {
				continue;
			}
		}
		$data = array(
			'survey_id'  => (int)$event->get('evt_svy_survey_id'),
			'event_id'   => (int)$event->key,
			'event_name' => $event->get('evt_name'),
		);
		$items[] = new ProfileDashboardItem(
			$data,
			$event->get('evt_name'),
			'Awaiting your feedback',
			null,
			null,
			'/survey?survey_id=' . (int)$event->get('evt_svy_survey_id') . '&event_id=' . (int)$event->key
		);
	}

	return new ProfileDashboardSection('pending_surveys', 'Pending Surveys', null, null, $items);
}
