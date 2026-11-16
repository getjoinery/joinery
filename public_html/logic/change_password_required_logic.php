<?php
/**
 * /change-password-required: an account flagged to choose its own password does so here.
 *
 * @version 1.1 - the email setup line shows only while no mail provider is configured; the unused
 *               XMLHttpRequest branch is gone (the page is a plain form post)
 */
// PathHelper, Globalvars, SessionControl are pre-loaded - no need to require them

function change_password_required_logic(array $input): LogicResult{
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;

	// Must be logged in to access this page
	if (!$session->is_logged_in()) {
		return LogicResult::redirect('/login');
	}

	// Get current user
	$user = new User($session->get_user_id(), true);

	// If user doesn't need to change password, redirect to admin
	if (!$user->get('usr_force_password_change')) {
		// Clear the session cache to prevent redirect loops
		unset($_SESSION['force_password_change']);
		return LogicResult::redirect('/admin/admin_users');
	}

	if (!empty($_POST)) {
		// Validate passwords
		if (empty($input['new_password'])) {
			return LogicResult::error('Please enter a new password.');
		}

		if ($input['new_password'] !== $input['confirm_password']) {
			return LogicResult::error('Passwords do not match.');
		}

		if (strlen($input['new_password']) < 8) {
			return LogicResult::error('Password must be at least 8 characters long.');
		}

		// Update password and clear flag
		$user->set('usr_password', User::GeneratePassword($input['new_password']));
		$user->set('usr_force_password_change', false);
		$user->save();

		// Clear the session cache so check_permission won't redirect anymore
		unset($_SESSION['force_password_change']);

		return LogicResult::redirect('/admin/admin_users');
	}

	// A superadmin standing here is, on most sites, the owner of a deployment
	// that is minutes old — and the credential they are about to replace is the
	// one written to a file at install time. From here on the only way back into
	// the account is a password reset, which needs a mail provider that a fresh
	// site does not have. So this screen is where they are told.
	//
	// A link, not a redirect: someone who just chose a password is mid-task, and
	// being thrown into a settings form reads as a bug. Shown only while the
	// site has no working mail provider: a hosted site's mail is set up before
	// its owner first signs in, and telling them to set it up is untrue.
	$page_vars['show_email_setup_hint'] = Access::permits(10, $session)
		&& !change_password_required_mail_ready();

	return LogicResult::render($page_vars);
}

function change_password_required_logic_descriptor(): array {
	return [
		'auth' => ['min_user_permission' => 0],
		'description'      => 'Satisfy a forced password-change requirement for admin users.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => [
			'new_password' => ['type' => 'password', 'required' => true, 'label' => 'New password'],
			'confirm_password' => ['type' => 'password', 'required' => true, 'label' => 'Confirm password'],
		],
	];
}

/** Does the site have an outbound mail provider chosen and configured? */
function change_password_required_mail_ready(): bool {
	$provider = EmailSender::getActiveProvider();
	if ($provider === null) {
		return false;
	}
	$check = $provider::validateConfiguration();
	return !empty($check['valid']);
}
