<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

/**
 * Logic for admin_file_delete
 * Handles permanent deletion of files with confirmation
 *
 * @param array $get_vars GET variables
 * @param array $post_vars POST variables
 * @return LogicResult
 */
function admin_file_delete_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$page_vars = array();

	// Handle POST - Process deletion
	if (!empty($post_vars['confirm'])) {
		$fil_file_id = LibraryFunctions::fetch_variable('fil_file_id', NULL, 1, 'You must provide a file to delete.', $post_vars);
		$confirm = LibraryFunctions::fetch_variable('confirm', NULL, 1, 'You must confirm the action.', $post_vars);

		if ($confirm) {
			$file = new File($fil_file_id, TRUE);
			$file->permanent_delete();
		}

		return LogicResult::redirect('/admin/admin_files');
	}

	// Handle GET - Display confirmation page
	$fil_file_id = LibraryFunctions::fetch_variable('fil_file_id', NULL, 1, 'You must provide a file to delete.', $get_vars);

	$file = new File($fil_file_id, TRUE);
	$user = new User($file->get('fil_usr_user_id'), TRUE);

	$session->set_return("/admin/admin_files");

	$page_vars['file'] = $file;
	$page_vars['user'] = $user;
	$page_vars['fil_file_id'] = $fil_file_id;
	$page_vars['session'] = $session;

	return LogicResult::render($page_vars);
}
?>
