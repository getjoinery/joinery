<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

/**
 * Logic for admin_email_template_permanent_delete
 * Handles permanent deletion of email templates with confirmation
 *
 * @param array $input GET variables
 * @param array $input POST variables
 * @return LogicResult
 */
function admin_email_template_permanent_delete_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/email_templates_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$page_vars = array();

	// Handle POST - Process deletion
	if (!empty($input['confirm'])) {
		$emt_email_template_id = LibraryFunctions::fetch_variable('emt_email_template_id', NULL, 1, 'You must provide a email_template to delete here.', $input);
		$confirm = LibraryFunctions::fetch_variable('confirm', NULL, 1, 'You must confirm the action.', $input);

		if ($confirm) {
			$email_template = new EmailTemplateStore($emt_email_template_id, TRUE);
			$email_template->assert_can_write($session);
			$email_template->permanent_delete();
		}

		// Redirect after deletion
		$returnurl = $session->get_return();
		return LogicResult::redirect($returnurl);
	}

	// Handle GET - Display confirmation page
	$emt_email_template_id = LibraryFunctions::fetch_variable('emt_email_template_id', NULL, 1, 'You must provide a email_template to edit.', $input);

	$email_template = new EmailTemplateStore($emt_email_template_id, TRUE);

	$session->set_return("/admin/admin_email_templates");

	// Pass data to view
	$page_vars['email_template'] = $email_template;
	$page_vars['emt_email_template_id'] = $emt_email_template_id;
	$page_vars['session'] = $session;

	return LogicResult::render($page_vars);
}
