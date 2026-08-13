<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function password_edit_logic(array $input): LogicResult{
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/EmailTemplate.php'));

	require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
	require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user = new User($session->get_user_id(), TRUE);

	$has_old_password = $user->get('usr_password') !== NULL;

	if (!empty($_POST)) {

		// Sensitive identity action (specs/mailbox_security_levels.md § 5.5):
		// re-confirm the second factor before changing the password. A no-op for
		// an account with no second factor (e.g. a first-time password set).
		$stepup = $session->require_recent_second_factor('/profile/password_edit');
		if ($stepup !== null) {
			return $stepup;
		}

		if(!isset($input['usr_password']) || !isset($input['usr_password_again'])){
			return LogicResult::error('The following required fields were not set: passwords');
		}

		if ($has_old_password) {
			// If the user doesn't have an existing password
			// then no need for them to type in their old password.
			if(!isset($input['usr_old_password'])){
				return LogicResult::error('The following required fields were not set: old password');
			}

		}

		// Only check the old password if they had one!
		if ($has_old_password && !$user->check_password($input['usr_old_password'])) {
			return LogicResult::error('Sorry, the old password you typed in was not correct.');
		}
		else {
			$user->set('usr_password', User::GeneratePassword($input['usr_password']));
			$user->save();

			// Credential event (specs/mailbox_security_levels.md § 6.6): a password
			// change ends EVERY vault window on every session everywhere and alerts
			// the account — the remote kill switch (change your password from your
			// phone and every window dies with it). Best-effort alert.
			require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
			VaultUnlock::lockAll($user->key);
			try {
				require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
				$to = (string)$user->get('usr_email');
				if ($to !== '') {
					$settings = Globalvars::get_instance();
					EmailSender::quickSend(
						$to,
						trim((string)$settings->get_setting('site_name') . ' security alert'),
						"Your account password was just changed. If this was you, no action is needed. "
						. "If this was NOT you, reset your password immediately and review your account security."
					);
				}
			} catch (\Throwable $e) {
				error_log('password_edit: alert email failed for user ' . $user->key . ': ' . $e->getMessage());
			}

			$msgtext = '<p>Your password has been updated!</p>';
			$message = new DisplayMessage($msgtxt, 'Success', '/\/profile\/password_edit.*/', DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, "addressbox", TRUE);
			$session->save_message($message);
		}
	}



	if ($has_old_password) {
		$page_vars['page_title'] = 'Change Password';
	}
	else {
		$page_vars['page_title'] = 'Set Password';
	}
	$page_vars['has_old_password'] = $has_old_password;
	$page_vars['user'] = $user;

	return LogicResult::render($page_vars);
}

/**
 * Form builder — single source for the web password form and the JSON form
 * definition (GET /api/v1/form/password_edit). The old-password field appears
 * only when the user has a password to verify.
 */
function password_edit_logic_form($formwriter, $user = null, $input = []) {
	$has_old_password = $user && $user->get('usr_password') !== NULL;

	if ($has_old_password) {
		$formwriter->passwordinput('usr_old_password', 'Old Password', [
			'required' => true,
		]);
	}
	$formwriter->passwordinput('usr_password', 'New Password', [
		'required' => true,
		'helptext' => 'Must be at least 5 characters.',
	]);
	$formwriter->passwordinput('usr_password_again', 'Retype New Password', [
		'required' => true,
	]);

	$formwriter->submitbutton('btn_submit', 'Submit');
}

function password_edit_logic_descriptor(): array {
	return [
		'description'      => 'Change the current user\'s password.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => [
			'usr_old_password' => ['type' => 'password', 'required' => false, 'label' => 'Current password'],
			'usr_password' => ['type' => 'password', 'required' => true, 'label' => 'New password'],
			'usr_password_again' => ['type' => 'password', 'required' => true, 'label' => 'Confirm new password'],
		],
	];
}
?>
