<?php
// PathHelper, Globalvars, SessionControl are pre-loaded - no need to require them

function change_password_required_logic($get_vars, $post_vars){
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;

	// Check if this is an AJAX request
	$ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest';

	// Must be logged in to access this page
	if (!$session->is_logged_in()) {
		if ($ajax) {
			echo json_encode(array('success' => 0, 'error' => 'Not logged in'));
			exit();
		}
		return LogicResult::redirect('/login');
	}

	// Get current user
	$user = new User($session->get_user_id(), true);

	// If user doesn't need to change password, redirect to admin
	if (!$user->get('usr_force_password_change')) {
		// Clear the session cache to prevent redirect loops
		unset($_SESSION['force_password_change']);
		if ($ajax) {
			echo json_encode(array('success' => 1, 'redirect' => '/admin/admin_users'));
			exit();
		}
		return LogicResult::redirect('/admin/admin_users');
	}

	if ($post_vars) {
		// Validate passwords
		if (empty($post_vars['new_password'])) {
			if ($ajax) {
				require_once(PathHelper::getIncludePath('includes/Exceptions/ValidationException.php'));
				throw new ValidationException('Please enter a new password.', ['new_password' => 'Password is required']);
			}
			return LogicResult::error('Please enter a new password.');
		}

		if ($post_vars['new_password'] !== $post_vars['confirm_password']) {
			if ($ajax) {
				require_once(PathHelper::getIncludePath('includes/Exceptions/ValidationException.php'));
				throw new ValidationException('Passwords do not match.', ['confirm_password' => 'Passwords do not match']);
			}
			return LogicResult::error('Passwords do not match.');
		}

		if (strlen($post_vars['new_password']) < 8) {
			if ($ajax) {
				require_once(PathHelper::getIncludePath('includes/Exceptions/ValidationException.php'));
				throw new ValidationException('Password must be at least 8 characters long.', ['new_password' => 'Minimum 8 characters']);
			}
			return LogicResult::error('Password must be at least 8 characters long.');
		}

		// Update password and clear flag
		$user->set('usr_password', User::GeneratePassword($post_vars['new_password']));
		$user->set('usr_force_password_change', false);
		$user->save();

		// Clear the session cache so check_permission won't redirect anymore
		unset($_SESSION['force_password_change']);

		if ($ajax) {
			echo json_encode(array('success' => 1, 'redirect' => '/admin/admin_users'));
			exit();
		}
		return LogicResult::redirect('/admin/admin_users');
	}

	return LogicResult::render($page_vars);
}
?>
