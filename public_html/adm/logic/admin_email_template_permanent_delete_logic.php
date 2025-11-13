<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

/**
 * Logic for admin_email_template_permanent_delete
 * Handles permanent deletion of email templates with confirmation
 *
 * @param array $get_vars GET variables
 * @param array $post_vars POST variables
 * @return LogicResult
 */
function admin_email_template_permanent_delete_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/email_templates_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$page_vars = array();

	// Handle POST - Process deletion
	if (!empty($post_vars['confirm'])) {
		$emt_email_template_id = LibraryFunctions::fetch_variable('emt_email_template_id', NULL, 1, 'You must provide a email_template to delete here.', $post_vars);
		$confirm = LibraryFunctions::fetch_variable('confirm', NULL, 1, 'You must confirm the action.', $post_vars);

		if ($confirm) {
			$email_template = new EmailTemplateStore($emt_email_template_id, TRUE);
			$email_template->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
			$email_template->permanent_delete();
		}

		// Redirect after deletion
		$returnurl = $session->get_return();
		return LogicResult::redirect($returnurl);
	}

	// Handle GET - Display confirmation page
	$emt_email_template_id = LibraryFunctions::fetch_variable('emt_email_template_id', NULL, 1, 'You must provide a email_template to edit.', $get_vars);

	$email_template = new EmailTemplateStore($emt_email_template_id, TRUE);

	$session->set_return("/admin/admin_email_templates");

	// Pass data to view
	$page_vars['email_template'] = $email_template;
	$page_vars['emt_email_template_id'] = $emt_email_template_id;
	$page_vars['session'] = $session;

	return LogicResult::render($page_vars);
}
