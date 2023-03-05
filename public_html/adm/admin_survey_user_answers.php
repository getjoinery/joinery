<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/surveys_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/questions_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/survey_answers_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 30; 
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'question_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'ASC', 0, '');
	
	$survey_id = LibraryFunctions::fetch_variable('svy_survey_id', 'DESC', 1, 'You must pass a survey.');
	$user_id = LibraryFunctions::fetch_variable('usr_user_id', 'DESC', 1, 'You must pass a user.');
	$survey = new Survey($survey_id, TRUE);
	$user = new User($user_id, TRUE);

	//$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');



	$answers = new MultiSurveyAnswer(
		array('svy_survey_id' => $survey->key, 'usr_user_id' => $user->key),  //SEARCH CRITERIA
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
		'menu-id'=> 39,
		'page_title' => 'Add User',
		'readable_title' => 'Add User',
		'breadcrumbs' => array(
			'Surveys'=>'/admin/admin_surveys', 
			$survey->get('svy_name'). ' answers' =>'/admin/admin_survey_users?svy_survey_id='.$survey->key, 
			$user->display_name() .'\'s answers' => '',
		),
		'session' => $session,
	)
	);



	$headers = array("Question", "Answer", "Last Update");
	$altlinks = array();
	$altlinks += array();
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks, 
		'title' => $user->display_name(). '\'s answers to survey "'.$survey->get('svy_name').'"',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);	

	foreach ($answers as $answer){
		$question = new Question($answer->get('sva_qst_question_id'), TRUE);
		
		$rowvalues = array();

		array_push($rowvalues, $question->get('qst_question'));
		array_push($rowvalues, $question->get_answer_readable($answer->key));

		array_push($rowvalues, LibraryFunctions::convert_time($answer->get('sva_create_time'), "UTC", $session->get_timezone(), 'M j, Y')); 
		
		$page->disprow($rowvalues);
	}
	$page->endtable($pager);

	$page->admin_footer();
?>


