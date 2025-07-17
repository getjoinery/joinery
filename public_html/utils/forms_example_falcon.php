<?php

	require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('/includes/SessionControl.php');
	PathHelper::requireOnce('/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPage.php', '/includes'));


	$session = SessionControl::get_instance();
	$session->check_permission(8);
		
	$page = new PublicPage();
	$hoptions=array(
		'is_valid_page' => $is_valid_page,
		'title'=>'Log In'
		);
	$page->public_header($hoptions,NULL);

	echo PublicPage::BeginPage('Falcon forms example');
	
	
	//$formwriter = LibraryFunctions::get_formwriter_object('form1');
				PathHelper::requireOnce('/includes/FormWriterMasterFalcon.php');
			$formwriter = new FormWriterMaster('form1');
	
	$validation_rules = array();
	$validation_rules['ccd_code']['required']['value'] = 'true';
	$validation_rules['ccd_is_active']['required']['value'] = 'true';	
	$validation_rules['cmt_body']['required']['value'] = 'true';	
	$validation_rules['"products_list[]"']['required']['value'] = 'true';	
	$validation_rules['single_checkbox']['required']['value'] = 'true';
	$validation_rules['"interval[]"']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);	


	echo $formwriter->begin_form('form1', 'POST', '/admin/admin', true);
	
	echo $formwriter->text('Text only field', 'To:', 'something something here is some text to test out columns', NULL);


    echo $formwriter->textinput('Coupon code', 'ccd_code', NULL, 100, NULL, 'test text', 255, '', TRUE, FALSE, 'text', 'default');	

	
    echo $formwriter->textinput('Input prefix', 'ccd_code3', NULL, 100, NULL, 'test text', 255, '', TRUE, 'https://', 'text', 'default');	

	
	
	$optionvals = array("Inactive"=>0, "Active"=>1);
	
	echo $formwriter->dropinput("Active?", "ccd_is_active", "", $optionvals, NULL, '', TRUE, FALSE, FALSE, FALSE, 'default');

	
	echo $formwriter->checkboxinput("Single checkbox", "single_checkbox", "", "left", NULL, 1, "Check to filter out disabled users", 'default');
	echo $formwriter->fileinput("File to Upload", "files[]", "", 30, '');



	echo $formwriter->textbox('Comment', 'cmt_body', '', 5, 80, NULL, '', 'no');

	echo $formwriter->textbox('Comment', 'cmt_body2', '', 5, 80, NULL, '', 'yes');
	
	echo $formwriter->dateinput("Date only", "dateonly", NULL, 30, NULL, "", 10);
	
	echo $formwriter->dateinput("Date only", "dateonlyh", NULL, 30, NULL, "", 10);
	
	$optionvals = array("Day"=>"0", "Week"=>"1", "Month"=>"2", "Quarter"=>"3", "Year"=>"4");
	$disabledvals = array();
	$readonlyvals = array();
	echo $formwriter->radioinput("Group by:", "interval", NULL, $optionvals, $interval, $disabledvals, $readonlyvals, 'hint');	

	echo $formwriter->textinput('City', 'city', '', 100, NULL, '', 255, '');	
	echo $formwriter->textinput('State', 'state', '', 100, NULL, '', 255, '');	
	echo $formwriter->textinput('Zip', 'zip', '', 100, NULL, '', 255, '');	

	
	//GET ALL PRODUCTS
	$searches = array();
	$sort = LibraryFunctions::fetch_variable('sort', 'product_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$products = new MultiProduct(
		$searches,
		array($sort=>$sdirection));
	$products->load();
	$optionvals = $products->get_dropdown_array();	
	
	if ($coupon_code->key) {
		//FILL THE CHECKED VALUES
		$checkedvals = array();
		$coupon_code_products = new MultiCouponCodeProduct(array(
			'coupon_code_id' => $coupon_code->key,
		));
		$coupon_code_products->load();
		foreach ($coupon_code_products as $coupon_code_product){
			$checkedvals[] = $coupon_code_product->get('ccp_pro_product_id');
		}
	}
	else{
		$checkedvals = array();
	}
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


	
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
	
?>
