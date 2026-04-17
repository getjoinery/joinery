<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/surveys_class.php'));
require_once(PathHelper::getIncludePath('data/questions_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/survey_answers_class.php'));

function admin_survey_answers_logic($get_vars, $post_vars) {
	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'question_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'ASC', 0, '');

	$survey_id = LibraryFunctions::fetch_variable('survey_id', 'DESC', 1, 'You must pass a survey.');
	$question_id = LibraryFunctions::fetch_variable('question_id', 'DESC', 1, 'You must pass a question.');
	$survey = new Survey($survey_id, TRUE);
	$question = new Question($question_id, TRUE);

	//$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');

	$answers = new MultiSurveyAnswer(
		array('survey_id' => $survey->key, 'question_id' => $question->key),  //SEARCH CRITERIA
		array($sort=>$sdirection),  //SORT AND DIRECTION array($usrsort=>$usrsdirection)
		//$numperpage,  //NUM PER PAGE
		//$offset,  //OFFSET
		//'OR'
		);
	$numrecords = $answers->count_all();
	$answers->load();

	$page_vars = array(
		'session' => $session,
		'survey' => $survey,
		'question' => $question,
		'answers' => $answers,
		'numrecords' => $numrecords,
		'numperpage' => $numperpage,
	);

	return LogicResult::render($page_vars);
}
