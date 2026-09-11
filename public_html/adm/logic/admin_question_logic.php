<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/questions_class.php'));
require_once(PathHelper::getIncludePath('data/question_options_class.php'));

function admin_question_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	// Get question ID from GET or POST (POST when form is submitted)
	$question_id = $input['qst_question_id'] ?? $input['qst_question_id'] ?? null;
	$question = new Question($question_id, TRUE);

	// Intentional GET-action mutations — opt in to the GET-is-read-only tripwire.
	if(($input['action'] ?? '') == 'delete'){
		$question->assert_can_write($session);
		$question->soft_delete();

		return LogicResult::redirect("/admin/admin_questions");
	}
	else if(($input['action'] ?? '') == 'undelete'){
		$question->assert_can_write($session);
		$question->undelete();

		return LogicResult::redirect("/admin/admin_questions");
	}

	$valid = '';
	if(LibraryFunctions::isFormSubmission()){
		$valid = $question->validate_answers($input['question_'.$question->key]);
	}

	$page_vars = array(
		'session' => $session,
		'question' => $question,
		'valid' => $valid,
	);

	return LogicResult::render($page_vars);
}
