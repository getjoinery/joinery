<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once (LibraryFunctions::get_logic_file_path('survey_logic.php'));
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php'));
	require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php'));


	$page = new PublicPageTW(TRUE);
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Surveys'
	));
	echo PublicPageTW::BeginPage($survey->get('svy_name'));
	echo PublicPageTW::BeginPanel();

	$formwriter = new FormWriterMasterTW('form1');
	echo $formwriter->begin_form('form1', 'POST', '/survey', true);

	if($invalid_messages){
		foreach ($invalid_messages as $invalid_message){
			echo $invalid_message. '<br>';
		}
	}

	foreach ($survey_questions as $survey_question){
		$question = new Question($survey_question->get('srq_qst_question_id'), TRUE);
		
		foreach ($survey_answers as $survey_answer){
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
		
		echo $formwriter->hiddeninput('survey_id', LibraryFunctions::encode($survey->key));
		$validation_rules = array();
		$validation_rules = $question->output_js_validation($validation_rules);
		echo $formwriter->set_validate($validation_rules);
		echo $question->output_question($formwriter,$answer_fill);
	}


	echo $formwriter->new_form_button('Submit');	
	echo $formwriter->end_form();
  
	echo PublicPageTW::EndPanel();
	echo PublicPageTW::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

