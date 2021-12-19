<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/surveys_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/survey_questions_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/questions_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/question_options_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/survey_answers_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(0);

	$survey_id = LibraryFunctions::decode(LibraryFunctions::fetch_variable('survey_id', NULL, 0, 'Survey id is required'));

	$survey = new Survey($survey_id, TRUE);
	
	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'survey_question_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');

	//$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');
	
	$survey_questions = new MultiSurveyQuestion(
		array('survey_id' => $survey->key, 'deleted'=>FALSE),  //SEARCH CRITERIA
		array($sort=>$sdirection),  //SORT AND DIRECTION array($usrsort=>$usrsdirection)
		$numperpage,  //NUM PER PAGE
		$offset,  //OFFSET
		'AND'  //AND OR OR
	);
	$numrecords = $survey_questions->count_all();
	$survey_questions->load();

	if($_POST){
		$invalid_messages = array();
		foreach($survey_questions as $survey_question){
			$question = new Question($survey_question->get('srq_qst_question_id'), TRUE);
			if(isset($_POST['question_'.$question->key]) && $_POST['question_'.$question->key]){
				$valid = $question->validate_answers($_POST['question_'.$question->key]);
				if($valid == 'valid'){
					$survey_answer = new SurveyAnswer(NULL);
					$survey_answer->set('sva_svy_survey_id', $survey->key);
					$survey_answer->set('sva_create_time', 'now()');
					$survey_answer->set('sva_qst_question_id', $question->key);
					$survey_answer->set('sva_usr_user_id', $session->get_user_id());
					if(is_array($_POST['question_'.$question->key])){
						$answer = implode(',',$_POST['question_'.$question->key]);
					}
					else{
						$answer = $_POST['question_'.$question->key];
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
			LibraryFunctions::Redirect('/survey_finish?survey_id='.LibraryFunctions::encode($survey->key));
		}
	}
	

	$survey_answers = new MultiSurveyAnswer(array(
		'survey_id' => $survey->key,
		'user_id' => $session->get_user_id(),
	));	 
	$survey_answers->load();

?>

