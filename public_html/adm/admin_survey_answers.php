<?php

	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('/data/surveys_class.php'));
	require_once(PathHelper::getIncludePath('/data/questions_class.php'));
	require_once(PathHelper::getIncludePath('/data/users_class.php'));
	require_once(PathHelper::getIncludePath('/data/survey_answers_class.php'));

	require_once(PathHelper::getIncludePath('adm/logic/admin_survey_answers_logic.php'));

	$page_vars = process_logic(admin_survey_answers_logic($_GET, $_POST));

	extract($page_vars);

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

