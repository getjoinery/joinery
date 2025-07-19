<?php

	require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('/includes/SessionControl.php');
	PathHelper::requireOnce('/includes/LibraryFunctions.php');
	require_once(__DIR__ . '/../includes/AdminPage-uikit3.php');


	$session = SessionControl::get_instance();
	$session->check_permission(8);
		
	$page = new AdminPage();
	$hoptions=array(
		'menu-id'=> 'forms-example',
		'page_title' => 'UIKit Forms Example',
		'readable_title' => 'UIKit Forms Example',
		'breadcrumbs' => array(
			'UIKit Forms Example'=>'',
		),
		'session' => $session,
	);
	$page->admin_header($hoptions);
	
	
	PathHelper::requireOnce('/includes/FormWriterMasterUIkit.php');
	$formwriter = new FormWriterMasterUIkit('form1');
	
	$validation_rules = array();
	$validation_rules['ccd_code']['required']['value'] = 'true';
	$validation_rules['ccd_is_active']['required']['value'] = 'true';	
	$validation_rules['cmt_body']['required']['value'] = 'true';	
	$validation_rules['"products_list[]"']['required']['value'] = 'true';	
	$validation_rules['single_checkbox']['required']['value'] = 'true';
	$validation_rules['"interval[]"']['required']['value'] = 'true';
	
	echo $formwriter->begin_form('', 'post', '');
	echo $formwriter->textinput('Coupon code', 'ccd_code', NULL, 100, NULL, 'test text', 255);
	$active_options = array('Yes'=>'1', 'No'=>'0');
	echo $formwriter->dropinput("Active?", "ccd_is_active", "", $active_options, NULL, "");
	echo $formwriter->textbox("Body text", "cmt_body", "", 10, 50, "Test message text", "Type your message here");

	// Use test data for demonstration
	$optionvals = array('Test Option 1'=>'1', 'Test Option 2'=>'2', 'Test Option 3'=>'3', 'Test Option 4'=>'4');
	$checkedvals = array('2', '3'); // Pre-select options 2 and 3
	$disabledvals = array();
	$readonlyvals = array(); 
	echo $formwriter->checkboxList("Valid products for this code", 'products_list', "", $optionvals, $checkedvals, $disabledvals, $readonlyvals);	
	
	
	echo $formwriter->datetimeinput('Start time', 'ccd_start_time', '', LibraryFunctions::convert_time('UTC', $session->get_timezone(), $session->get_timezone(), 'Y-m-d h:ia'), '', '', '', 'default');
	
	echo $formwriter->datetimeinput('Start time', 'ccd_start_time2', '', LibraryFunctions::convert_time('UTC', $session->get_timezone(), $session->get_timezone(), 'Y-m-d h:ia'), '', '', '','horizontal');

	 
	echo $formwriter->datetimeinput2('End time', 'ccd_end_time', '', LibraryFunctions::convert_time('UTC', $session->get_timezone(), $session->get_timezone(), 'Y-m-d h:ia'), '', '', '', 'default');
	

	echo $formwriter->text('Text only field', 'To:', 'something something here is some text to test out columns', NULL, 'horizontal');
	echo $formwriter->textinput('Coupon code', 'ccd_code2', NULL, 100, NULL, 'test text', 255, '', TRUE, FALSE, 'text', 'horizontal');	
	echo $formwriter->checkboxinput("Single checkbox", "single_checkbox2", "", "left", NULL, 1, "Check to filter out disabled users", 'horizontal');
	echo $formwriter->textinput('Input prefix horizontal', 'ccd_code4', NULL, 100, NULL, 'test text', 255, '', TRUE, 'https://', 'text', 'horizontal');	
	echo $formwriter->dropinput("Active?", "ccd_is_active2", "", $optionvals, NULL, '', TRUE, FALSE, FALSE, FALSE, 'horizontal');

	echo $formwriter->datetimeinput('Start time', 'ccd_start_time3', '', LibraryFunctions::convert_time('UTC', $session->get_timezone(), $session->get_timezone(), 'Y-m-d h:ia'), '', '', '','row');


	echo $formwriter->datetimeinput2('End time', 'ccd_end_time2', '', LibraryFunctions::convert_time('UTC', $session->get_timezone(), $session->get_timezone(), 'Y-m-d h:ia'), '', '', '', 'horizontal');	

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Cancel', 'secondary');
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form(true);


	
	$page->admin_footer();
	
?>