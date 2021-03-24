<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/surveys_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 30; 
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'survey_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');

	//$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');



	$surveys = new MultiSurvey(
		NULL,  //SEARCH CRITERIA
		array($sort=>$sdirection),  //SORT AND DIRECTION array($usrsort=>$usrsdirection)
		$numperpage,  //NUM PER PAGE
		$offset,  //OFFSET
		'OR');
	$numrecords = $surveys->count_all();
	$surveys->load();




	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 35,
		'page_title' => 'Add User',
		'readable_title' => 'Add User',
		'breadcrumbs' => array(
			'Users'=>'/admin/admin_users', 
			'Surveys' => '',
		),
		'session' => $session,
	)
	);



	$headers = array("Survey", "# Users", "Last Update", "Action");
	$altlinks = array();
	$altlinks += array('Add Survey'=> '/admin/admin_survey_edit');
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Surveys',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);	

	foreach ($surveys as $survey){

		$rowvalues = array();


		array_push($rowvalues, "<a href='/admin/admin_survey?svy_survey_id=$survey->key'>".$survey->get('svy_name')."</a> ");
		
		/*$numusers = (string)$survey->get_member_count();
		array_push($rowvalues, $numusers);

		array_push($rowvalues, LibraryFunctions::convert_time($survey->get('svy_update_time'), "UTC", $session->get_timezone(), 'M j, Y')); */

		$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_survey_permanent_delete?svy_survey_id='. $survey->key.'">
		<input type="hidden" class="hidden" name="action" value="removesurvey" />
		<input type="hidden" class="hidden" name="svy_survey_id" value="'.$survey->key.'" />
		<button type="submit">Delete</button>
		</form>';

		array_push($rowvalues, $delform);
		
		$page->disprow($rowvalues);
	}
	$page->endtable($pager);

	$page->admin_footer();
?>


