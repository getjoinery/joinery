<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_survey_user_answers_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/surveys_class.php'));
	require_once(PathHelper::getIncludePath('data/questions_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/survey_answers_class.php'));

	$session = SessionControl::get_instance();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'question_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'ASC', 0, '');

	$survey_id = LibraryFunctions::fetch_variable('svy_survey_id', 'DESC', 1, 'You must pass a survey.');
	$user_id = LibraryFunctions::fetch_variable('usr_user_id', 'DESC', 1, 'You must pass a user.');
	$survey = new Survey($survey_id, TRUE);
	$user = new User($user_id, TRUE);

	$answers = new MultiSurveyAnswer(
		array('svy_survey_id' => $survey->key, 'usr_user_id' => $user->key),
		array($sort=>$sdirection)
	);
	$numrecords = $answers->count_all();
	$answers->load();

	// Return data for view
	$result = new LogicResult();
	$result->data = array(
		'survey' => $survey,
		'user' => $user,
		'answers' => $answers,
		'numrecords' => $numrecords,
		'numperpage' => $numperpage,
		'offset' => $offset,
		'sort' => $sort,
		'sdirection' => $sdirection,
	);

	return $result;
}
?>
