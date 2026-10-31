<?php
/**
 * API action: test_mode — turn test mode on or off for this browser session
 * (TestMode), and say where it stands.
 *
 * POST /api/v1/action/test_mode (browser session):
 *   action  'on' (superadmin only) or 'off' (anyone); absent just reads
 *
 * The /test_mode page runs the same function for its form and the banner's
 * Turn off button.
 *
 * @version 1.0
 */

function test_mode_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$action = (string)($input['action'] ?? '');
	$message = '';

	if ($action === 'on') {
		if (!TestMode::turn_on($session)) {
			return LogicResult::error('Only a superadmin can turn on test mode.');
		}
		$message = 'Test mode is on in this browser until you turn it off or sign out.';
	} elseif ($action === 'off') {
		TestMode::turn_off();
		$message = 'Test mode is off in this browser.';
		// The banner's button goes back to the page it was pressed on.
		$return = (string)($input['return'] ?? '');
		if ($return !== '' && $return[0] === '/' && (strlen($return) < 2 || ($return[1] !== '/' && $return[1] !== '\\'))) {
			return LogicResult::redirect($return);
		}
	} elseif ($action !== '') {
		return LogicResult::error('Unknown action: say on or off.');
	}

	return LogicResult::render(array(
		'on'          => TestMode::session_on(),
		'site_wide'   => TestMode::site_wide(),
		'can_turn_on' => TestMode::may_turn_on($session),
		'message'     => $message,
	));
}

function test_mode_logic_descriptor(): array {
	return [
		'description' => 'Turn test mode on (superadmin) or off for this browser session, or read whether it is on. In test mode payments use the providers\' test accounts.',
		'mutates'     => true,
		'requires_session' => true,
		'auth'        => [
			'requires_browser_session' => true,
			'min_user_permission'      => 0,
		],
		'input'       => [
			'action' => ['type' => 'string', 'required' => false],
			'return' => ['type' => 'string', 'required' => false],
		],
	];
}
