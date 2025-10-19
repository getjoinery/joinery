<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/questions_class.php'));
require_once(PathHelper::getIncludePath('data/question_options_class.php'));

function admin_question_logic($get_vars, $post_vars) {
	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$question = new Question($get_vars['qst_question_id'], TRUE);

	if($get_vars['action'] == 'delete'){
		$question->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$question->soft_delete();

		return LogicResult::redirect("/admin/admin_questions");
	}
	else if($get_vars['action'] == 'undelete'){
		$question->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$question->soft_delete();

		return LogicResult::redirect("/admin/admin_questions");
	}

	$valid = '';
	if($post_vars){
		$valid = $question->validate_answers($post_vars['question_'.$question->key]);
	}

	$page_vars = array(
		'session' => $session,
		'question' => $question,
		'valid' => $valid,
	);

	return LogicResult::render($page_vars);
}
