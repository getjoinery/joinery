<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/surveys_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	if (isset($_REQUEST['svy_survey_id'])) {
		$survey = new Survey($_REQUEST['svy_survey_id'], TRUE);
	} else {
		$survey = new Survey(NULL);
	}

	if($_POST){
		$editable_fields = array('svy_name');

		foreach($editable_fields as $field) {
			$survey->set($field, $_REQUEST[$field]);
		}
		
		$survey->prepare();
		$survey->save();
		$survey->load();
		
		LibraryFunctions::redirect('/admin/admin_survey?svy_survey_id='.$survey->key);
		exit;
	}


	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 35,
		'breadcrumbs' => array(
			'Surveys'=>'/admin/admin_surveys', 
			'New Survey' => '',
		),
		'session' => $session,
	)
	);	

	
	$pageoptions['title'] = "New Survey";
	$page->begin_box($pageoptions);

	// Editing an existing email
	$formwriter = new FormWriterMaster('form1');
	
	$validation_rules = array();
	$validation_rules['svy_name']['required']['value'] = 'true';	
	echo $formwriter->set_validate($validation_rules);	



	echo $formwriter->begin_form('form', 'POST', '/admin/admin_survey_edit');

	if($survey->key){
		echo $formwriter->hiddeninput('svy_survey_id', $survey->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}
	
	echo $formwriter->textinput('Survey name', 'svy_name', NULL, 100, $survey->get('svy_name'), '', 255, '');	
	


	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();

	$page->admin_footer();

?>
