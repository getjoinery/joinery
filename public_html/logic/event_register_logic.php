<?php
/**
 * API entry point for event registration: load event by id and delegate to event_logic.
 */
require_once(PathHelper::getIncludePath('data/events_class.php'));
require_once(PathHelper::getThemeFilePath('event_logic.php', 'logic'));

function event_register_logic(array $input): LogicResult {
	$event_id = $input['evt_event_id'] ?? null;
	if (!$event_id) {
		require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
		return LogicResult::error('evt_event_id is required');
	}

	$event = new Event($event_id, TRUE);
	return event_logic(array_merge($input, [
		'slug' => $event->get('evt_link'),
		'date' => $input['instance_date'] ?? null,
	]));
}

function event_register_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Register for an event',
	];
}

function event_register_logic_descriptor(): array {
	return [
		'description'      => 'Register the current user for an event.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => [
			'evt_event_id' => ['type' => 'int', 'required' => true, 'label' => 'Event ID'],
			'instance_date' => ['type' => 'date', 'required' => false, 'label' => 'Instance date (recurring events)'],
		],
	];
}
?>
