<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function survey_logic($get_vars, $post_vars){
	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/surveys_class.php'));
	require_once(PathHelper::getIncludePath('data/survey_questions_class.php'));
	require_once(PathHelper::getIncludePath('data/questions_class.php'));
	require_once(PathHelper::getIncludePath('data/question_options_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/survey_answers_class.php'));

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	$session->check_permission(0);

	if($get_vars['survey_id']){
		$survey_id = LibraryFunctions::decode($get_vars['survey_id']);
	}
	else if($post_vars['survey_id']){
		$survey_id = LibraryFunctions::decode($post_vars['survey_id']);
	}
	else{
		throw new SystemDisplayableError('Survey id is missing.');
	}

	$survey = new Survey($survey_id, TRUE);
	$page_vars['survey'] = $survey;
	
	$numperpage = 30;
	$offset = 0;
	if($get_vars['offset']){
		$offset = $get_vars['offset'];
	}

	$sort = 'survey_question_id';
	if($get_vars['sort']){
		$sort = $get_vars['sort'];
	}
	
	$sdirection = 'DESC';
	if($get_vars['sdirection']){
		$sdirection = $get_vars['sdirection'];
	}

	
	$survey_questions = new MultiSurveyQuestion(
		array('survey_id' => $survey->key, 'deleted'=>FALSE),  //SEARCH CRITERIA
		array($sort=>$sdirection),  //SORT AND DIRECTION array($usrsort=>$usrsdirection)
		$numperpage,  //NUM PER PAGE
		$offset,  //OFFSET
		'AND'  //AND OR OR
	);
	$survey_questions->load();
	$page_vars['survey_questions'] = $survey_questions;
	$numrecords = $survey_questions->count_all();
	$page_vars['numrecords'] = $numrecords;


	if($post_vars){
		$invalid_messages = array();
		foreach($survey_questions as $survey_question){
			$question = new Question($survey_question->get('srq_qst_question_id'), TRUE);
			if(isset($post_vars['question_'.$question->key]) && $post_vars['question_'.$question->key]){
				$valid = $question->validate_answers($post_vars['question_'.$question->key]);
				if($valid == 'valid'){
					$survey_answer = new SurveyAnswer(NULL);
					$survey_answer->set('sva_svy_survey_id', $survey->key);
					$survey_answer->set('sva_create_time', 'now()');
					$survey_answer->set('sva_qst_question_id', $question->key);
					$survey_answer->set('sva_usr_user_id', $session->get_user_id());
					if(is_array($post_vars['question_'.$question->key])){
						$answer = implode(',',$post_vars['question_'.$question->key]);
					}
					else{
						$answer = $post_vars['question_'.$question->key];
					}
					$survey_answer->set('sva_answer', strip_tags(trim($answer)));
					if($survey_answer->check_for_duplicates()){
						$survey_answer = SurveyAnswer::get_answer($survey->key, $question->key, $session->get_user_id());
						$survey_answer->set('sva_answer', strip_tags(trim($answer)));
						$survey_answer->save();
					}
					else{
						$survey_answer->save();
					}
					
				}
				else{
					$invalid_messages[] = $valid;
				}
			}
		}
		if(empty($invalid_messages)){
			return LogicResult::redirect('/survey_finish?survey_id='.LibraryFunctions::encode($survey->key));
		}
	}
	$page_vars['invalid_messages'] = $invalid_messages;

	$survey_answers = new MultiSurveyAnswer(array(
		'survey_id' => $survey->key,
		'user_id' => $session->get_user_id(),
	));	 
	$survey_answers->load();
	$page_vars['survey_answers'] = $survey_answers;

	return LogicResult::render($page_vars);
}
?>

