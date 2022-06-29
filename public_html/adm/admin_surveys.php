<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/surveys_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/survey_answers_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/survey_questions_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 30; 
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'survey_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$search_criteria = array();

	//$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');



	$surveys = new MultiSurvey(
		$search_criteria,  //SEARCH CRITERIA
		array($sort=>$sdirection),  //SORT AND DIRECTION array($usrsort=>$usrsdirection)
		$numperpage,  //NUM PER PAGE
		$offset,  //OFFSET
		'OR');
	$numrecords = $surveys->count_all();
	$surveys->load();





	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 39,
		'page_title' => 'Add User',
		'readable_title' => 'Add User',
		'breadcrumbs' => array(
			'Surveys'=>''
		),
		'session' => $session,
	)
	);



	$headers = array("Survey", "# Questions", "Last Update", "Action");
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
		
		$survey_questions = new MultiSurveyQuestion(
			array('survey_id' => $survey->key),  //SEARCH CRITERIA
			array($sort=>$sdirection),  //SORT AND DIRECTION array($usrsort=>$usrsdirection)
			$numperpage,  //NUM PER PAGE
			$offset,  //OFFSET
			'AND'  //AND OR OR
		);
		$num_questions = $survey_questions->count_all();

		$rowvalues = array();


		array_push($rowvalues, "<a href='/admin/admin_survey?svy_survey_id=$survey->key'>".$survey->get('svy_name')."</a> ");
		
		array_push($rowvalues, $num_questions);

		array_push($rowvalues, LibraryFunctions::convert_time($survey->get('svy_update_time'), "UTC", $session->get_timezone(), 'M j, Y')); 

		if($survey->get('svy_delete_time')){
			array_push($rowvalues, '<b>Deleted</b>');
		}
		else{
			$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_survey_permanent_delete?svy_survey_id='. $survey->key.'">
			<input type="hidden" class="hidden" name="action" value="removesurvey" />
			<input type="hidden" class="hidden" name="svy_survey_id" value="'.$survey->key.'" />
			<button type="submit">Delete</button>
			</form>';
			array_push($rowvalues, $delform);
		}


		
		$page->disprow($rowvalues);
	}
	$page->endtable($pager);

	$page->admin_footer();
?>


