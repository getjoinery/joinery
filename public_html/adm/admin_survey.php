<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/surveys_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/survey_questions_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/questions_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();
	
	if($_POST['action'] == 'addquestion'){
		$survey_question = new SurveyQuestion(NULL);
		$survey_question->set('srq_svy_survey_id', $_REQUEST['svy_survey_id']);
		$survey_question->set('srq_qst_question_id', $_REQUEST['qst_question_id']); 
		$survey_question->prepare();
		$survey_question->save();
	}
	else if($_POST['action'] == 'removequestion'){
		$survey_question = new SurveyQuestion($_REQUEST['srq_survey_question_id'], TRUE);
		$survey_question->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$survey_question->permanent_delete();
	}
	else if($_POST['action'] == 'removesurvey'){
		$survey = new Survey($_REQUEST['svy_survey_id'], TRUE);
		$survey->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$survey->permanent_delete();
	}
	
	$svy_survey_id = LibraryFunctions::fetch_variable('svy_survey_id', 0, 0, '');
	$survey = new Survey($svy_survey_id, TRUE);


	if($_REQUEST['action'] == 'delete'){
		$survey->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$survey->soft_delete();

		header("Location: /admin/admin_surveys");
		exit();				
	}
	else if($_REQUEST['action'] == 'undelete'){
		$survey->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$survey->soft_delete();

		header("Location: /admin/admin_surveys");
		exit();				
	}



	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'survey_question_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');

	//$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');



	$survey_questions = new MultiSurveyQuestion(
		array('survey_id' => $survey->key),  //SEARCH CRITERIA
		array($sort=>$sdirection),  //SORT AND DIRECTION array($usrsort=>$usrsdirection)
		$numperpage,  //NUM PER PAGE
		$offset,  //OFFSET
		'AND'  //AND OR OR
	);
	$numrecords = $survey_questions->count_all();
	$survey_questions->load();




	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'surveys',
		'page_title' => 'Users in Survey',
		'readable_title' => 'Users in Survey',
		'breadcrumbs' => array(
			'Surveys'=>'/admin/admin_surveys', 
			$survey->get('svy_name') => '',
		),
		'session' => $session,
	)
	);



	$headers = array('Question',  'Action');
	$altlinks = array();
	if(!$survey->get('svy_delete_time') && $_SESSION['permission'] >= 8) {
		$options['altlinks']['Soft Delete'] = '/admin/admin_survey?action=delete&svy_survey_id='.$survey->key;
	}
	//$altlinks +=  array('Email survey' => '/admin/admin_users_message?svy_survey_id='.$survey->key);

	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Survey: '. $survey->get('svy_name'),
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	if($survey->get('svy_delete_time')){
		echo 'Status: Deleted at '.LibraryFunctions::convert_time($survey->get('svy_delete_time'), 'UTC', $session->get_timezone()).'<br />';
	}
	else{
		echo '<p>Link: <a href="/survey?survey_id='.LibraryFunctions::encode($survey->key).'">/survey?survey_id='.LibraryFunctions::encode($survey->key).'</a></p><br />';
	}


	foreach ($survey_questions as $survey_question){
		$question = new Question($survey_question->get('srq_qst_question_id'), TRUE);

		$rowvalues = array();
		array_push($rowvalues, '<a href="/admin/admin_question?qst_question_id='.$survey_question->get('srq_qst_question_id').'">'.$question->get('qst_question').'</a>');

		//array_push($rowvalues, '<a href="/admin/admin_survey_answers?survey_id='.$survey->key.'&question_id='.$survey_question->key.'">answers</a>');
		
		
		$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_survey">
		<input type="hidden" class="hidden" name="action" id="action" value="removequestion" />
		<input type="hidden" class="hidden" name="svy_survey_id" id="action" value="'.$survey->key.'"  />
		<input type="hidden" class="hidden" name="srq_survey_question_id" value="'.$survey_question->key.'" />
		<button type="submit">Remove</button>
		</form>';
		array_push($rowvalues, $delform);

		$page->disprow($rowvalues);
	}
	$questions = new MultiQuestion(
		array('deleted'=>false),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$questions->load();
	$numquestions = $questions->count_all();
	
	if($numquestions){
		echo '<tr><td colspan="3">';
		$formwriter = LibraryFunctions::get_formwriter_object('form3, 'admin');
		//$validation_rules = array();
		//$validation_rules['evt_event_id']['required']['value'] = 'true';
		//echo $formwriter->set_validate($validation_rules);
		echo $formwriter->begin_form('form2', 'POST', '/admin/admin_survey?svy_survey_id='. $survey->key);
		

		
		$optionvals = $questions->get_dropdown_array();
		echo $formwriter->hiddeninput('action', 'addquestion');
		echo $formwriter->dropinput("Add question to survey", "qst_question_id", "ctrlHolder", $optionvals, NULL, '', TRUE);
		echo $formwriter->new_form_button('Add');
		echo $formwriter->end_form();	
		echo '</td></tr>';			
	}
	else{
		echo 'There are no questions.  <a href="/admin/admin_questions">Add one</a>.';
	}
	$page->endtable($pager);

	$page->admin_footer();
?>


