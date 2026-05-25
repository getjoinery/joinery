<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_event_type_edit_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/event_types_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(7);

	if (isset($input['ety_event_type_id'])) {
		$event_type = new EventType($input['ety_event_type_id'], TRUE);
	} else {
		$event_type = new EventType(NULL);
	}

	if ($input) {
		// Submitting a product edit

		$editable_fields = array('ety_name');

		foreach($editable_fields as $field) {
			$event_type->set($field, $input[$field]);
		}

		$event_type->save();

		return LogicResult::redirect('/admin/admin_event_types');
	}

	$page_vars = array(
		'event_type' => $event_type,
		'session' => $session,
	);

	return LogicResult::render($page_vars);
}

?>
