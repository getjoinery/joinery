<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_user_login_as_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	// Validate user ID is a positive integer
	$usr_user_id = (int) LibraryFunctions::fetch_variable_local($get_vars, 'usr_user_id', 0);
	if ($usr_user_id <= 0) {
		return LogicResult::error('Invalid user ID.');
	}

	// Validate target user exists and is not deleted
	if (!User::check_if_exists($usr_user_id)) {
		return LogicResult::error('User not found.');
	}
	$user = new User($usr_user_id, TRUE);
	if ($user->get('usr_delete_time')) {
		return LogicResult::error('Cannot log in as a deleted user.');
	}

	// Capture admin ID before switching session
	$admin_user_id = $session->get_user_id();

	// Audit log
	RequestLogger::log('admin', 'login_as', true, [
		'user_id' => $usr_user_id,
		'note'    => 'Admin #' . $admin_user_id . ' logged in as user #' . $usr_user_id,
	]);

	// Switch session to target user (includes session_regenerate_id)
	$session->store_session_variables($user);

	// Preserve original admin ID in session for reference
	$_SESSION['login_as_admin_id'] = $admin_user_id;

	return LogicResult::redirect('/');
}
?>
