<?php
/**
 * API wrapper for event registration.
 * Adapts event_logic() (which requires pre-loaded $event and $instance_date)
 * to the standard ($get_vars, $post_vars) signature used by the API.
 */
require_once(PathHelper::getIncludePath('data/events_class.php'));
require_once(PathHelper::getThemeFilePath('event_logic.php', 'logic'));

function event_register_logic($get_vars, $post_vars) {
	$event_id = $post_vars['evt_event_id'] ?? $get_vars['evt_event_id'] ?? null;
	if (!$event_id) {
		require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
		return LogicResult::error('evt_event_id is required');
	}

	$event = new Event($event_id, TRUE);
	$instance_date = $post_vars['instance_date'] ?? $get_vars['instance_date'] ?? null;
	return event_logic($get_vars, $post_vars, $event, $instance_date);
}

function event_register_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Register for an event',
	];
}
?>
