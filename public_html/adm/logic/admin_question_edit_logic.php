<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_question_edit_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/questions_class.php'));
	require_once(PathHelper::getIncludePath('data/question_options_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);

	// ✅ CRITICAL: Check for question ID from multiple sources
	// Priority: edit_primary_key_value (main form) > qst_question_id in POST (option form) > qst_question_id in GET (page load)
	if (isset($input['edit_primary_key_value'])) {
		// Main form submission with edit_primary_key_value
		$question = new Question($input['edit_primary_key_value'], TRUE);
	} elseif (isset($input['qst_question_id'])) {
		// Option form submission with qst_question_id
		$question = new Question($input['qst_question_id'], TRUE);
	} elseif (isset($input['qst_question_id'])) {
		// Initial page load from URL parameter
		$question = new Question($input['qst_question_id'], TRUE);
	} else {
		// Creating new question
		$question = new Question(NULL);
	}

	if(isset($input['action']) && $input['action'] == 'add_question_option'){
		if(!isset($question->key)){
			return LogicResult::error("You cannot add a question option when there is no question.  Submit your question first.");
		}
		$question_option = new QuestionOption(NULL);
		$question_option->set('qop_qst_question_id', $question->key);
		$question_option->set('qop_question_option_label', $input['qop_question_option_label']);
		$question_option->set('qop_question_option_value', $input['qop_question_option_value']);
		$question_option->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$question_option->prepare();
		$question_option->save();

		//$returnurl = $session->get_return();
		return LogicResult::redirect("/admin/admin_question_edit?qst_question_id=".$question->key);
	}
	if(isset($input['action']) && $input['action'] == 'remove_question_option'){
		$question_option = new QuestionOption($input['qop_question_option_id'], TRUE);
		$question_option->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$question_option->permanent_delete();

		//$returnurl = $session->get_return();
		return LogicResult::redirect("/admin/admin_question_edit?qst_question_id=".$question->key);
	}

	// Main form submission - exclude if this is an option action
	if($input && !isset($input['action'])){

		// Add-only logic - set defaults for new questions
		if (!$question->key) {
			$question->set('qst_is_published', true);
			$question->set('qst_published_time', 'NOW()');
		}

		$editable_fields = array('qst_type', 'qst_question');

		foreach($editable_fields as $field) {
			$question->set($field, $input[$field]);
		}

		//VALIDATION
		$validation_array = [];

		// Required-ness is the qst_is_required column — the blob carries only
		// the value rules (integer, decimal, lengths, bounds).
		$question->set('qst_is_required',
			isset($input['validation_options']) && is_array($input['validation_options'])
			&& in_array('required', $input['validation_options']));

		if(isset($input['validation_options']) && is_array($input['validation_options'])){
			foreach ($input['validation_options'] as $option){
				// Handle if $option is a string value
				if(is_string($option)) {
					if($option == 'required'){
						continue;
					}
					$validation_array[$option] = $option;
					if($option == 'decimal' || $option == 'integer'){
						if(isset($input['max_value']) && $input['max_value'] != ''){
							$validation_array['max_value'] = $input['max_value'];
						}
						if(isset($input['min_value']) && $input['min_value'] != ''){
							$validation_array['min_value'] = $input['min_value'];
						}
					}
				}
			}
		}
		if(isset($input['max_length']) && $input['max_length'] != ''){
			$validation_array['max_length'] = $input['max_length'];
		}
		if(isset($input['min_length']) && $input['min_length'] != ''){
			$validation_array['min_length'] = $input['min_length'];
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
