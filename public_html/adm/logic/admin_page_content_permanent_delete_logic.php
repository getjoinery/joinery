<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

/**
 * Logic for admin_page_content_permanent_delete
 * Handles permanent deletion of page content blocks with confirmation
 *
 * @param array $input GET variables
 * @param array $input POST variables
 * @return LogicResult
 */
function admin_page_content_permanent_delete_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/page_contents_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$page_vars = array();

	// Handle POST - Process deletion
	if (!empty($input['confirm'])) {
		$pac_page_content_id = LibraryFunctions::fetch_variable('pac_page_content_id', NULL, 1, 'You must provide a page_content to delete here.', $input);
		$confirm = LibraryFunctions::fetch_variable('confirm', NULL, 1, 'You must confirm the action.', $input);

		if ($confirm) {
			$page_content = new PageContent($pac_page_content_id, TRUE);
			$page_content->assert_can_write($session);
			$page_content->permanent_delete();
		}

		// Redirect after deletion
		$returnurl = $session->get_return();
		return LogicResult::redirect($returnurl);
	}

	// Handle GET - Display confirmation page
	$pac_page_content_id = LibraryFunctions::fetch_variable('pac_page_content_id', NULL, 1, 'You must provide a page_content to edit.', $input);

	$page_content = new PageContent($pac_page_content_id, TRUE);

	$session->set_return("/admin/admin_page_contents");

	// Pass data to view
	$page_vars['page_content'] = $page_content;
	$page_vars['pac_page_content_id'] = $pac_page_content_id;
	$page_vars['session'] = $session;

	return LogicResult::render($page_vars);
}
