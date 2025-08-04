<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	
	// ErrorHandler.php no longer needed - using new ErrorManager system
	
	PathHelper::requireOnce('includes/AdminPage.php');
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');

	PathHelper::requireOnce('data/surveys_class.php');
	PathHelper::requireOnce('data/survey_answers_class.php');
	PathHelper::requireOnce('data/survey_questions_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 30; 
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'survey_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$search_criteria = array();

	//$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');


	$svy_survey_id = LibraryFunctions::fetch_variable('svy_survey_id', 0, 0, '');
	$survey = new Survey($svy_survey_id, TRUE);
	$users = $survey->get_users_who_answered();
	

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'surveys',
		'page_title' => 'Add User',
		'readable_title' => 'Add User',
		'breadcrumbs' => array(
			'Surveys'=> '/admin/admin_surveys',
			$survey->get('svy_name'). ' answers'=>''
		),
		'session' => $session,
	)
	);



	$headers = array("Survey", "# Answers", "Last Update");
	$altlinks = array();
	//$altlinks += array('Add Survey'=> '/admin/admin_survey_edit');
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => $survey->get('svy_name'). ' answers',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);	

	foreach ($users as $userrow){
		$user = new User($userrow->sva_usr_user_id, TRUE);

		$rowvalues = array();
		array_push($rowvalues, $user->display_name());
		array_push($rowvalues, '<a href="/admin/admin_survey_user_answers?svy_survey_id='.$survey->key.'&usr_user_id='.$user->key.'">Answered '. $userrow->count . ' questions</a>');
		$page->disprow($rowvalues);
	}
	$page->endtable($pager);

	$page->admin_footer();
?>


