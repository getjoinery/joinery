<?php

function admin_email_view_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	$session = SessionControl::get_instance();
	$session->set_return();

	$email_id = $input['eml_email_id'] ?? $input['eml_email_id'] ?? NULL;
	$email = new Email($email_id, TRUE);
	$email->assert_found();

	$page_vars = array();
	$page_vars['session'] = $session;
	$page_vars['email'] = $email;

	return LogicResult::render($page_vars);
}
?>
