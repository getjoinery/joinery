<?php

	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('/data/surveys_class.php'));
	require_once(PathHelper::getIncludePath('/data/questions_class.php'));
	require_once(PathHelper::getIncludePath('/data/users_class.php'));
	require_once(PathHelper::getIncludePath('/data/survey_answers_class.php'));

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

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'surveys',
		'page_title' => 'Add User',
		'readable_title' => 'Add User',
		'breadcrumbs' => array(
			'Surveys'=>'/admin/admin_surveys',
			$survey->get('svy_name')=>'/admin/admin_survey?svy_survey_id='.$survey->key,
			'Survey Answers' => '',
		),
		'session' => $session,
	)
	);

	$headers = array("User", "Answer", "Last Update");
	$altlinks = array();
	$altlinks += array();
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Answers for question'. ' "'.$question->get('qst_question'). '" in survey "'.$survey->get('svy_name').'"',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	foreach ($answers as $answer){
		$user = new User($answer->get('sva_usr_user_id'), TRUE);
		$rowvalues = array();

		array_push($rowvalues, $user->display_name());
		array_push($rowvalues, $answer->get('sva_answer'));

		array_push($rowvalues, LibraryFunctions::convert_time($answer->get('svy_create_time'), "UTC", $session->get_timezone(), 'M j, Y'));

		$page->disprow($rowvalues);
	}
	$page->endtable($pager);

	$page->admin_footer();
?>

