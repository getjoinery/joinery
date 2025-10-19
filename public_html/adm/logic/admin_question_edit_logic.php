<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_question_edit_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/questions_class.php'));
	require_once(PathHelper::getIncludePath('data/question_options_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);

	if ($get_vars['qst_question_id']) {
		$question = new Question($get_vars['qst_question_id'], TRUE);
	} else {
		$question = new Question(NULL);
	}

	if($get_vars['action'] == 'add_question_option'){
		if(!isset($question->key)){
			throw new SystemDisplayablePermanentError("You cannot add a question option when there is no question.  Submit your question first.");
			exit();
		}
		$question_option = new QuestionOption(NULL);
		$question_option->set('qop_qst_question_id', $question->key);
		$question_option->set('qop_question_option_label', $get_vars['qop_question_option_label']);
		$question_option->set('qop_question_option_value', $get_vars['qop_question_option_value']);
		$question_option->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$question_option->prepare();
		$question_option->save();

		//$returnurl = $session->get_return();
		return LogicResult::redirect("/admin/admin_question_edit?qst_question_id=".$question->key);
	}
	if($get_vars['action'] == 'remove_question_option'){
		$question_option = new QuestionOption($get_vars['qop_question_option_id'], TRUE);
		$question_option->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$question_option->permanent_delete();

		//$returnurl = $session->get_return();
		return LogicResult::redirect("/admin/admin_question_edit?qst_question_id=".$question->key);
	}

	if($post_vars){

		$editable_fields = array('qst_type', 'qst_question', 'qst_is_published');

		foreach($editable_fields as $field) {
			$question->set($field, $post_vars[$field]);
		}

		if($get_vars['qst_is_published']){
			if(!$question->get('qst_published_time')){
				$question->set('qst_published_time', 'NOW()');
			}
		}
		else {
			$question->set('qst_published_time', NULL);
		}

		//VALIDATION

		foreach ($get_vars['validation_options'] as $option){
			$validation_array[$option] = $option;
			if($option == 'decimal' || $option == 'integer'){
				if(isset($get_vars['max_value']) && $get_vars['max_value'] != ''){
					$validation_array['max_value'] = $get_vars['max_value'];
				}
				if(isset($get_vars['min_value']) && $get_vars['min_value'] != ''){
					$validation_array['min_value'] = $get_vars['min_value'];
				}
			}
		}
		if(isset($get_vars['max_length']) && $get_vars['max_length'] != ''){
			$validation_array['max_length'] = $get_vars['max_length'];
		}
		if(isset($get_vars['min_length']) && $get_vars['min_length'] != ''){
			$validation_array['min_length'] = $get_vars['min_length'];
		}

		$question->set('qst_validate', serialize($validation_array));

		$question->prepare();
		$question->save();
		$question->load();

		return LogicResult::redirect('/admin/admin_question?qst_question_id='. $question->key);
	}

	$page_vars = array(
		'question' => $question,
		'session' => $session,
	);

	return LogicResult::render($page_vars);
}

?>
