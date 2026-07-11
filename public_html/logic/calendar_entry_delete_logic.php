<?php
/**
 * API action: calendar_entry_delete — delete a native calendar entry.
 *
 * POST /api/v1/action/calendar_entry_delete (session key). Params:
 *   entry_id         int (required)
 *   scope            'this' | 'future' | 'all' — recurring parents only;
 *                    defaults to 'all'
 *   occurrence_date  Y-m-d — required for scope 'this' / 'future'
 *
 * Standalone entries soft-delete; recurring parents go through the same
 * scope-aware helper as the web form (exception row / series truncation /
 * whole-series soft delete).
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../includes/PathHelper.php');

function calendar_entry_delete_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
	require_once(PathHelper::getIncludePath('data/calendar_entry_class.php'));
	require_once(PathHelper::getIncludePath('data/calendar_entry_exception_class.php'));
	require_once(PathHelper::getIncludePath('logic/calendar_logic.php')); // shared _calendar_* helpers

	$session = SessionControl::get_instance();
	$user_id = $session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}

	$eid   = intval($input['entry_id'] ?? 0);
	$scope = (string)($input['scope'] ?? 'all');
	$odate = trim((string)($input['occurrence_date'] ?? ''));

	if (!$eid) {
		return LogicResult::error('entry_id is required.');
	}

	$entry = new CalendarEntry($eid, true);
	if (!$entry->key || $entry->get('cal_delete_time')) {
		return LogicResult::error('Entry not found.');
	}

	try {
		$entry->authenticate_write([
			'current_user_id'         => $user_id,
			'current_user_permission' => $session->get_permission(),
		]);
	} catch (SystemAuthenticationError $e) {
		return LogicResult::error('Entry not found.');
	}

	if ($entry->is_recurring_parent()) {
		if (in_array($scope, array('this', 'future'), true) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $odate)) {
			return LogicResult::error('occurrence_date is required for that scope.');
		}
		_calendar_delete_recurring($entry, $scope, $odate);
	} else {
		$entry->soft_delete();
	}

	return LogicResult::render(array('deleted' => true));
}

function calendar_entry_delete_logic_descriptor(): array {
	// entry_id stays optional at the boundary so the logic's own "entry_id is
	// required" ActionError remains the single source of that behavior.
	return [
		'description' => 'Delete a native calendar entry (scope aware for recurring series).',
		'mutates'     => true,
		'auth'        => [
			'requires_session' => true,
		],
		'input'       => [
			'entry_id'        => ['type' => 'int',    'required' => false, 'label' => 'Entry ID'],
			'scope'           => ['type' => 'string', 'required' => false, 'enum' => ['this', 'future', 'all'], 'label' => 'Recurring delete scope'],
			'occurrence_date' => ['type' => 'string', 'required' => false, 'label' => 'Occurrence date (required for this/future)'],
		],
	];
}

?>
