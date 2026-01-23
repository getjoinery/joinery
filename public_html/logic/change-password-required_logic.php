<?php
// PathHelper, Globalvars, SessionControl are pre-loaded - no need to require them

function change_password_required_logic($get_vars, $post_vars){
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;

	// Must be logged in to access this page
	if (!$session->is_logged_in()) {
		header('Location: /login');
		exit();
	}

	// Get current user
	$user = new User($session->get_user_id(), true);

	// If user doesn't need to change password, redirect to admin
	if (!$user->get('usr_force_password_change')) {
		header('Location: /admin/admin_users');
		exit();
	}

	if ($post_vars) {
		// Validate passwords
		if (empty($post_vars['new_password'])) {
			throw new SystemDisplayableError('Please enter a new password.');
		}

		if ($post_vars['new_password'] !== $post_vars['confirm_password']) {
			throw new SystemDisplayableError('Passwords do not match.');
		}

		if (strlen($post_vars['new_password']) < 8) {
			throw new SystemDisplayableError('Password must be at least 8 characters long.');
		}

		// Update password and clear flag
		$user->set('usr_password', User::GeneratePassword($post_vars['new_password']));
		$user->set('usr_force_password_change', false);
		$user->save();

		// Clear the session cache so check_permission won't redirect anymore
		unset($_SESSION['force_password_change']);

		header('Location: /admin/admin_users');
		exit();
	}

	return LogicResult::render($page_vars);
}
?>
