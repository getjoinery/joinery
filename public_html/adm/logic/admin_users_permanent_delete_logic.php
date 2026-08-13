<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

/**
 * Logic for admin_users_permanent_delete
 * Handles cascading deletion of users with dry-run preview and confirmation
 *
 * @param array $input GET variables
 * @param array $input POST variables
 * @return LogicResult
 */
function admin_users_permanent_delete_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$page_vars = array();

	// Handle POST - Process deletion
	if (!empty($input)) {
		$usr_user_id = LibraryFunctions::fetch_variable('usr_user_id', NULL, 1, 'You must provide a user to delete here.', $input);
		$confirm = LibraryFunctions::fetch_variable('confirm', NULL, 1, 'You must confirm the action.', $input);

		if ($confirm) {
			$user = new User($usr_user_id, TRUE);
			$user->assert_can_write($session);
			$user->permanent_delete();
		}

		// Redirect after deletion
		$returnurl = $session->get_return();
		return LogicResult::redirect($returnurl);
	}

	// Handle GET - Display confirmation page with dry-run preview
	$usr_user_id = LibraryFunctions::fetch_variable('usr_user_id', NULL, 1, 'You must provide a user to edit.', $input);

	$user = new User($usr_user_id, TRUE);

	$session->set_return("/admin/admin_users");

	// Get dry-run preview
	$dry_run = $user->permanent_delete_dry_run();

	// Pass data to view
	$page_vars['user'] = $user;
	$page_vars['usr_user_id'] = $usr_user_id;
	$page_vars['dry_run'] = $dry_run;
	$page_vars['session'] = $session;

	return LogicResult::render($page_vars);
}
