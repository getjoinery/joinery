<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once('/var/www/html/test/public_html/theme/default/includes/FormWriterPublic.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/files_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);





	if($_POST){
		
		print_r($_POST);
		
	}

	$page = new PublicPage(TRUE);
	$page->public_header(array(
	'title' => 'Form Test'
	));
	
	echo PublicPage::BeginPage('Form Test');

	echo '<div class="section-lg">
		<div class="container">';

	// Editing an existing event
	$formwriter = new FormWriterPublic('form1');
	
	$validation_rules = array();
	$validation_rules['evt_name']['required']['value'] = 'true';
	$validation_rules['evt_type']['required']['value'] = 'true';
	$validation_rules['evt_fil_file_id']['required']['value'] = 'true';
	$validation_rules['evt_short_description']['required']['value'] = 'true';
	$validation_rules['evt_start_time']['required']['value'] = 'true';
	$validation_rules['"pro_requirements[]"']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);		
	
	
	echo $formwriter->begin_form('form1', 'POST', '/utils/form_test');

	
	echo $formwriter->textinput('Event name', 'evt_name', NULL, 100, NULL, '', 255, '');
	
	$optionvals = array("Online Course"=>1, "Retreat"=>2);
	echo $formwriter->dropinput("Event type", "evt_type", "ctrlHolder", $optionvals, NULL, '', TRUE);

	$files = new MultiFile(
		array('deleted'=>false, 'picture'=>true),
		array('file_id' => 'DESC'),		//SORT BY => DIRECTION
		2,  //NUM PER PAGE
		NULL);  //OFFSET
	$files->load();
	$optionvals = $files->get_image_dropdown_array();
	echo $formwriter->imageinput("Main image", "evt_fil_file_id", "ctrlHolder", $optionvals, NULL, '', TRUE, TRUE, FALSE, TRUE);	
	
	echo $formwriter->textbox('Event short description (no html)', 'evt_short_description', 'ctrlHolder', 5, 80, NULL, '', 'yes');

	echo $formwriter->hiddeninput('evt_collect_extra_info', '0');
	
	echo $formwriter->datetimeinput('Event start time', 'evt_start_time', 'ctrlHolder', NULL, '', '', '');

	$optionvals = array(
		'Name' => 1, 
		'Email' => 64,
		//'Phone Number' => 2,
		//'Date of Birth' => 4,
		//'Address' => 8,
		//'GDPR Notice' => 16,
		'Consent to record' => 32,
		'User Chooses Price' => 128,
		'Newsletter Signup' => 256,
		'Comment' => 512
	);

	$checkedvals = array();
	$readonlyvals = array(); //DEFAULT
	$disabledvals = array();
	
	echo $formwriter->checkboxList("Info to collect at purchase", 'pro_requirements', "ctrlHolder", $optionvals, $checkedvals, $disabledvals, $readonlyvals);
 
	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit', 'button');
	echo $formwriter->end_buttons();

	echo $formwriter->end_form();

	echo '</div></div>';


	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
