<?php
/**
 * API action: calendar_level_batch — converge one bounded batch of the
 * signed-in member's calendar entries to the level the calendar now promises.
 *
 * POST /api/v1/action/calendar_level_batch  {}
 *
 * calendar_level_change flips the promise at once and converts one batch; the
 * page calls this until `remaining` is 0. The target is the calendar's
 * CURRENT level, never a parameter. Raising needs only the member's public
 * key; lowering decrypts, so it needs the member's window (`locked` when it
 * is closed).
 *
 * Returns { level, converted, failed, remaining, locked }.
 *
 * @version 1.0
 */
function calendar_level_batch_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/calendar/CalendarLevel.php'));

	$user_id = (int)SessionControl::get_instance()->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}

	$scope = new CalendarLevel($user_id);
	$pass = ProtectionLevelChange::convergeBatch($scope);
	if ($pass['locked']) {
		return LogicResult::error('Unlock your vault to keep converting your calendar.',
			array('locked' => true, 'remaining' => $pass['remaining']));
	}
	return LogicResult::render(array(
		'level'     => $scope->currentLevel(),
		'converted' => $pass['converted'],
		'failed'    => $pass['failed'],
		'remaining' => $pass['remaining'],
	));
}

function calendar_level_batch_logic_descriptor(): array {
	return array(
		'requires_session' => true,
		'mutates'          => true,
		'description'      => 'Converge one bounded batch of the signed-in member\'s calendar entries to the calendar\'s current protection level. Call repeatedly until `remaining` is 0; a pass that converts nothing while rows remain means those rows cannot be converted now.',
		'input'            => array(),
	);
}
?>
