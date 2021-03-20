<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/surveys_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/survey_questions_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/questions_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();
	
	if($_POST['action'] == 'add'){
		$survey_question = new SurveyQuestion(NULL);
		$survey_question->set('srq_svy_survey_id', $_REQUEST['svy_survey_id']);
		$survey_question->set('srq_qst_question_id', $_REQUEST['qst_question_id']); 
		$survey_question->prepare();
		$survey_question->save();
	}

	$svy_survey_id = LibraryFunctions::fetch_variable('svy_survey_id', 0, 0, '');
	$survey = new Survey($svy_survey_id, TRUE);

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
		'menu-id'=> 35,
		'page_title' => 'Users in Survey',
		'readable_title' => 'Users in Survey',
		'breadcrumbs' => array(
			'Surveys'=>'/admin/admin_surveys', 
			'Users in '. $survey->get('svy_name') => '',
		),
		'session' => $session,
	)
	);



	$headers = array('User', 'Action');
	$altlinks = array();

	//$altlinks +=  array('Email survey' => '/admin/admin_users_message?svy_survey_id='.$survey->key);

	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Survey: '. $survey->get('svy_name'),
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	foreach ($survey_questions as $survey_question){
		$question = new Question($survey_question->get('srq_qst_question_id'), TRUE);

		$rowvalues = array();
		array_push($rowvalues, $question->get('qst_question'));


		$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_user?usr_user_id='. $user->key.'">
		<input type="hidden" class="hidden" name="action" id="action" value="remove" />
		<input type="hidden" class="hidden" name="grm_survey_member_id" value="'.$survey_member->key.'" />
		<button type="submit">Remove</button>
		</form>';

		array_push($rowvalues, $delform);

		$page->disprow($rowvalues);
	}
	
	echo '<tr><td colspan="3">';
	$formwriter = new FormWriterMaster('form3');
	//$validation_rules = array();
	//$validation_rules['evt_event_id']['required']['value'] = 'true';
	//echo $formwriter->set_validate($validation_rules);
	echo $formwriter->begin_form('form2', 'POST', '/admin/admin_survey?svy_survey_id='. $survey->key);
	
	$questions = new MultiQuestion(
		array('deleted'=>false),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$questions->load();
	
	$optionvals = $questions->get_dropdown_array();
	echo $formwriter->hiddeninput('action', 'add');
	echo $formwriter->dropinput("Add a question", "qst_question_id", "ctrlHolder", $optionvals, NULL, '', TRUE);
	echo $formwriter->new_form_button('Add');
	echo $formwriter->end_form();	
	echo '</td></tr>';			
	
	$page->endtable($pager);

	$page->admin_footer();
?>


