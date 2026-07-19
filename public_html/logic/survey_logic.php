<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function survey_logic(array $input): LogicResult{
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

	if(!empty($input['survey_id'])){
		$survey_id = LibraryFunctions::decode($input['survey_id']);
	}
	else{
		return LogicResult::error('Survey id is missing.');
	}

	$survey = new Survey($survey_id, TRUE);
	$page_vars['survey'] = $survey;
	
	$numperpage = 30;
	$offset = 0;
	if(!empty($input['offset'])){
		$offset = $input['offset'];
	}

	$sort = 'survey_question_id';
	if(!empty($input['sort'])){
		$sort = $input['sort'];
	}

	$sdirection = 'DESC';
	if(!empty($input['sdirection'])){
		$sdirection = $input['sdirection'];
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


	$invalid_messages = array();
	if (!empty($_POST)) {
		foreach($survey_questions as $survey_question){
			$question = new Question($survey_question->get('srq_qst_question_id'), TRUE);

			// Every question on this page is validated, including one whose
			// field is absent from the post entirely. Validating only the
			// fields that arrived would mean a required question could be
			// satisfied by omitting it: the browser enforces required, and
			// anything posting directly would simply leave the field out and
			// be redirected to the finish page as though the survey were
			// complete.
			$raw_answer = $input['question_'.$question->key] ?? NULL;
			$valid = $question->validate_answers($raw_answer);
			if($valid != 'valid'){
				$invalid_messages[] = $valid;
				continue;
			}

			// An optional question left blank passes validation but has nothing
			// to record, and must not overwrite an existing answer with an
			// empty one.
			if($raw_answer === NULL || $raw_answer === '' || (is_array($raw_answer) && count($raw_answer) == 0)){
				continue;
			}

			$answer = is_array($raw_answer) ? implode(',', $raw_answer) : $raw_answer;
			$answer = strip_tags(trim($answer));

			$survey_answer = new SurveyAnswer(NULL);
			$survey_answer->set('sva_svy_survey_id', $survey->key);
			$survey_answer->set('sva_create_time', 'now()');
			$survey_answer->set('sva_qst_question_id', $question->key);
			$survey_answer->set('sva_usr_user_id', $session->get_user_id());
			$survey_answer->set('sva_answer', $answer);

			// Re-answering updates the row already there rather than adding a
			// second one, which the (survey, question, user) uniqueness would
			// refuse anyway. check_for_duplicates() hands back the existing
			// row, so there is no need to look it up a second time.
			$existing = $survey_answer->check_for_duplicates();
			if($existing){
				$survey_answer = $existing;
				$survey_answer->set('sva_answer', $answer);
			}
			$survey_answer->save();
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

function survey_logic_api() {
    return [
        'requires_session' => true,
        'description' => 'Submit survey response',
    ];
}
?>

