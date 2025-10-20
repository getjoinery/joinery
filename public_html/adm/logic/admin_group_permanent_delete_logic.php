<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

/**
 * Logic for admin_group_permanent_delete
 * Handles cascading deletion of groups with confirmation
 *
 * @param array $get_vars GET variables
 * @param array $post_vars POST variables
 * @return LogicResult
 */
function admin_group_permanent_delete_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/groups_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$page_vars = array();

	// Handle POST - Process deletion
	if (!empty($post_vars['confirm'])) {
		$grp_group_id = LibraryFunctions::fetch_variable('grp_group_id', NULL, 1, 'You must provide a group to delete here.', $post_vars);
		$confirm = LibraryFunctions::fetch_variable('confirm', NULL, 1, 'You must confirm the action.', $post_vars);

		if ($confirm) {
			$group = new Group($grp_group_id, TRUE);
			$group->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
			$group->permanent_delete();
		}

		// Redirect after deletion
		$returnurl = $session->get_return();
		return LogicResult::redirect($returnurl);
	}

	// Handle GET - Display confirmation page
	$grp_group_id = LibraryFunctions::fetch_variable('grp_group_id', NULL, 1, 'You must provide a group to edit.', $get_vars);

	$group = new Group($grp_group_id, TRUE);

	$session->set_return("/admin/admin_groups");

	// Pass data to view
	$page_vars['group'] = $group;
	$page_vars['grp_group_id'] = $grp_group_id;
	$page_vars['session'] = $session;

	return LogicResult::render($page_vars);
}
