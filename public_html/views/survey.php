<?php

	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('survey_logic.php', 'logic'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	$page_vars = process_logic(survey_logic($_GET, $_POST));
	$survey = $page_vars['survey'];

	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Surveys'
	));
	echo PublicPage::BeginPage($survey->get('svy_name'));
	echo PublicPage::BeginPanel();

	$formwriter = $page->getFormWriter('form1');
	$formwriter->begin_form([
		'id' => 'form1',
		'method' => 'POST',
		'action' => '/survey',
		'ajax' => true
	]);

	if($invalid_messages){
		foreach ($invalid_messages as $invalid_message){
			echo $invalid_message. '<br>';
		}
	}

	$validation_rules = array();
	foreach ($page_vars['survey_questions'] as $survey_question){
		$question = new Question($survey_question->get('srq_qst_question_id'), TRUE);

		foreach ($page_vars['survey_answers'] as $survey_answer){
			$answer_fill = NULL;
			if($survey_answer->get('sva_qst_question_id') == $question->key){
				if($question->get('qst_type') == Question::TYPE_CHECKBOX_LIST){
					$answer_fill = explode(',', $survey_answer->get('sva_answer'));
					break;
				}
				else{
					$answer_fill = $survey_answer->get('sva_answer');
					break;
				}

			}
		}

		$formwriter->hiddeninput('survey_id', ['value' => LibraryFunctions::encode($survey->key)]);
		$validation_rules = $question->output_js_validation($validation_rules);

		echo $question->output_question($formwriter,$answer_fill);
	}

	// Note: Questions may still use old validation API via output_js_validation()
	// This is handled by the Question class and doesn't need conversion here
	if(!empty($validation_rules)){
	}

	$formwriter->submitbutton('submit', 'Submit', [
		'class' => 'btn btn-primary'
	]);

	$formwriter->end_form();

	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>
