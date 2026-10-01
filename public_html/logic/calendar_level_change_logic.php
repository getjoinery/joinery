<?php
/**
 * API action: calendar_level_change — make the signed-in member's calendar
 * Standard or Private (docs/calendar.md § Protection level).
 *
 * POST /api/v1/action/calendar_level_change  { level }
 *
 * The sequence and its security rules are ProtectionLevelChange's: a recent
 * second factor for a member who has one (answered as an error carrying
 * `requires_stepup`; the page runs JoineryPasskeys.withStepUp and calls
 * again), the prerequisite (Private needs a vault), lowering only with the
 * member's window open, and the level flipped FIRST. One bounded converge
 * pass runs here so a short calendar is done at once; a longer one reports
 * `remaining`, and the page drives calendar_level_batch until nothing is left.
 * A calendar with no entries yet asks no step-up: the first choice on an
 * empty scope is not a change to existing content.
 *
 * Returns { level, remaining }.
 *
 * @version 1.0
 */
function calendar_level_change_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/calendar/CalendarLevel.php'));

	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}

	$target = ProtectionLevel::fromInput($input['level'] ?? null, '');
	if ($target === null || $target === '' || !in_array($target, CalendarLevel::LEVELS, true)) {
		return LogicResult::error('That is not a protection level your calendar offers.');
	}

	$scope = new CalendarLevel($user_id);
	$existing = (new MultiCalendarEntry(array(
		'subject_type' => CalendarSubject::TYPE_USER,
		'subject_id'   => $user_id,
		'deleted'      => false,
	)))->count_all() > 0;

	$change = ProtectionLevelChange::change($scope, $target, $user_id, $existing);
	if ($change['status'] === ProtectionLevelChange::STEPUP) {
		return LogicResult::error($change['error'], array('requires_stepup' => true));
	}
	if ($change['status'] === ProtectionLevelChange::REFUSED) {
		return LogicResult::error($change['error']);
	}

	// Unchanged: the same level picked again resumes a change that stopped
	// part-way, exactly as chat does.
	$remaining = $scope->remaining();
	if ($remaining > 0) {
		$pass = ProtectionLevelChange::convergeBatch($scope);
		$remaining = $pass['remaining'];
		if ($pass['locked']) {
			return LogicResult::render(array('level' => $scope->currentLevel(), 'remaining' => $remaining, 'locked' => true));
		}
	}
	return LogicResult::render(array('level' => $scope->currentLevel(), 'remaining' => $remaining));
}

function calendar_level_change_logic_descriptor(): array {
	return array(
		'requires_session' => true,
		'mutates'          => true,
		'description'      => 'Make the signed-in member\'s calendar Standard or Private. The level flips at once and one bounded batch of entries converges; call calendar_level_batch until `remaining` is 0. Refused with `requires_stepup` when a recent second factor is needed.',
		'input'            => array(
			'level' => array('type' => 'string', 'required' => true, 'enum' => array('standard', 'private'), 'label' => 'Protection level'),
		),
	);
}
?>
