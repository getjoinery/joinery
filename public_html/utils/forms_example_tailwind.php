<?php

	require_once(__DIR__ . '/../includes/PathHelper.php');
	require_once(PathHelper::getIncludePath('/includes/SessionControl.php'));
	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	$session = SessionControl::get_instance();

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Homepage',
	);
	$page->public_header($hoptions);
	
	// Add Tailwind CSS manually since we're not using the Tailwind theme
	echo '<link rel="stylesheet" type="text/css" href="/theme/tailwind/includes/output.css">';

	echo PublicPage::BeginPage('Tailwind forms example');
	
	
	require_once(PathHelper::getIncludePath('/includes/FormWriterTailwind.php'));
	$formwriter = new FormWriterTailwind('form1');
	
	$validation_rules = array();
	$validation_rules['ccd_code']['required']['value'] = 'true';
	$validation_rules['ccd_is_active']['required']['value'] = 'true';	
	$validation_rules['cmt_body']['required']['value'] = 'true';	
	$validation_rules['products_list']['required']['value'] = 'true';
	$validation_rules['single_checkbox']['required']['value'] = 'true';
	$validation_rules['interval']['required']['value'] = 'true';
	
	echo $formwriter->begin_form('', 'post', '');
	echo $formwriter->textinput('Coupon code', 'ccd_code', NULL, 100, NULL, 'test text', 255);
	$active_options = array('1'=>'Yes', '0'=>'No');
	echo $formwriter->dropinput("Active?", "ccd_is_active", "", $active_options, NULL, "");
	echo $formwriter->textbox("Body text", "cmt_body", "", 10, 50, "Test message text", "Type your message here");

	// Use test data for demonstration
	$optionvals = array('1'=>'Test Option 1', '2'=>'Test Option 2', '3'=>'Test Option 3', '4'=>'Test Option 4');
	$checkedvals = array('2', '3'); // Pre-select options 2 and 3
	$disabledvals = array();
	$readonlyvals = array(); 
	echo $formwriter->checkboxList("Valid products for this code", 'products_list', "", $optionvals, $checkedvals, $disabledvals, $readonlyvals);	
	
	
	echo $formwriter->datetimeinput('Start time', 'ccd_start_time', 'sm:col-span-6', LibraryFunctions::convert_time('UTC', $session->get_timezone(), $session->get_timezone(), 'Y-m-d h:ia'), '', '', '');
	

	echo $formwriter->text('To:', 'Text only field', 'something something here is some text to test out columns', 'sm:col-span-6');
	echo $formwriter->textinput('Coupon code', 'ccd_code2', 'sm:col-span-6', 100, 'test text', '', 255);	
	echo $formwriter->checkboxinput("Single checkbox", "single_checkbox2", "sm:col-span-6", "left", NULL, 1, "Check to filter out disabled users");
	echo $formwriter->textinput('Input prefix horizontal', 'ccd_code4', 'sm:col-span-6', 100, 'test text', '', 255, '', TRUE, 'https://');	
	$active_options2 = array('1'=>'Yes', '0'=>'No');
	echo $formwriter->dropinput("Active?", "ccd_is_active2", "sm:col-span-6", $active_options2, NULL, '');	

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Cancel', 'secondary');
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form(true);


	
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
	
?>