<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

/**
 * Logic for admin_post_permanent_delete
 * Handles permanent deletion of blog posts with confirmation
 *
 * @param array $input GET variables
 * @param array $input POST variables
 * @return LogicResult
 */
function admin_post_permanent_delete_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/posts_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$page_vars = array();

	// Handle POST - Process deletion
	if (!empty($input['confirm'])) {
		$pst_post_id = LibraryFunctions::fetch_variable('pst_post_id', NULL, 1, 'You must provide a post to delete here.', $input);
		$confirm = LibraryFunctions::fetch_variable('confirm', NULL, 1, 'You must confirm the action.', $input);

		if ($confirm) {
			$post = new Post($pst_post_id, TRUE);
			$post->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
			$post->permanent_delete();
		}

		// Redirect after deletion
		$returnurl = $session->get_return();
		return LogicResult::redirect($returnurl);
	}

	// Handle GET - Display confirmation page
	$pst_post_id = LibraryFunctions::fetch_variable('pst_post_id', NULL, 1, 'You must provide a post to edit.', $input);

	$post = new Post($pst_post_id, TRUE);

	$session->set_return("/admin/admin_posts");

	// Pass data to view
	$page_vars['post'] = $post;
	$page_vars['pst_post_id'] = $pst_post_id;
	$page_vars['session'] = $session;

	return LogicResult::render($page_vars);
}
