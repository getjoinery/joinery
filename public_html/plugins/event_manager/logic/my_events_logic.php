<?php
/**
 * API action: my_events — the owner's status-filtered event registrations as JSON.
 *
 * POST /api/v1/action/my_events (session key). Params: status (all /
 * active / expired / canceled / completed, default all), offset (10/page).
 * Shares events_profile_logic.php's query path and status derivation.
 *
 * @version 1.0.0
 */


function my_events_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));
	require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_registrants_class.php'));
	require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_sessions_class.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$user = new User($session->get_user_id(), TRUE);
	$status_filter = isset($input['status']) ? (string)$input['status'] : 'all';
	$now_utc = gmdate('Y-m-d H:i:s');

	$event_registrants = new MultiEventRegistrant(
		array('user_id' => $user->key, 'deleted' => false),
		array('evr_create_time' => 'DESC')
	);
	$event_registrants->load();

	$all_events = array();
	foreach ($event_registrants as $event_registrant) {
		$event = new Event($event_registrant->get('evr_evt_event_id'), TRUE);
		if (!$event || $event->get('evt_delete_time')) continue;

		if ($event_registrant->get('evr_expires_time') && $event_registrant->get('evr_expires_time') < $now_utc) {
			$status = 'expired';
		} elseif ($event->get('evt_status') == Event::STATUS_ACTIVE) {
			$status = 'active';
		} elseif ($event->get('evt_status') == Event::STATUS_CANCELED) {
			$status = 'canceled';
		} elseif ($event->get('evt_status') == Event::STATUS_COMPLETED) {
			$status = 'completed';
		} else {
			$status = 'active';
		}

		if ($status_filter != 'all' && $status_filter != $status) continue;

		$next_session = $event->get_next_session();
		$session_url = $event->get('evt_session_display_type') == 2
			? '/profile/event_sessions_course?evt_event_id=' . $event->key
			: '/profile/event_sessions?evt_event_id=' . $event->key;

		$all_events[] = array(
			'registrant_id'     => (int)$event_registrant->key,
			'event_id'          => (int)$event->key,
			'event_name'        => $event->get('evt_name'),
			'session_display_type' => (int)$event->get('evt_session_display_type'),
			'next_session_time' => $next_session ? $next_session->get('evs_start_time') : null,
			'status'            => $status,
			'expires_time'      => $status === 'active' ? ($event_registrant->get('evr_expires_time') ?: null) : null,
			'web_url'           => $session_url,
		);
	}

	$numperpage = 10;
	$total = count($all_events);
	$page_offset = isset($input['offset']) ? max(0, (int)$input['offset']) : 0;

	return LogicResult::render(array(
		'registrations' => array_slice($all_events, $page_offset, $numperpage),
		'total_count'   => $total,
		'offset'        => $page_offset,
		'per_page'      => $numperpage,
		'status_filter' => $status_filter,
	));
}

function my_events_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Status-filtered, paginated event registration list for the signed-in owner',
	];
}

?>
