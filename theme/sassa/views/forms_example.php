<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/PathHelper.php');
PathHelper::requireOnce('includes/ThemeHelper.php');
	ThemeHelper::includeThemeFile('includes/PublicPage');


	$session = SessionControl::get_instance();
	$session->check_permission(8);
		
	$page = new PublicPage(TRUE);
	$hoptions=array(
		'is_valid_page' => $is_valid_page,
		'title'=>'Log In'
		);
	$page->public_header($hoptions,NULL);

	echo PublicPage::BeginPage('Log In');
	
	
	$formwriter = LibraryFunctions::get_formwriter_object();
	
	

	
	$validation_rules = array();
	$validation_rules['ccd_code']['required']['value'] = 'true';
	$validation_rules['ccd_is_active']['required']['value'] = 'true';	
	$validation_rules['cmt_body']['required']['value'] = 'true';	
	$validation_rules['"products_list[]"']['required']['value'] = 'true';	
	$validation_rules['single_checkbox']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);	


	echo $formwriter->begin_form('contact-form style2', 'POST', '/admin/admin', true);
	

	
	echo $formwriter->text('Text only field', 'To:', 'something something here is some text to test out columns', NULL);


	echo $formwriter->textinput('', 'ccd_code', NULL, 100, NULL, 'Coupon code', 255, '');	
	
	echo $formwriter->textinput('', 'website', NULL, 100, NULL, 'Website', 255, '', '', 'www.text.com/');	
	
	
	
	
	$optionvals = array("Inactive"=>0, "Active"=>1);
	echo $formwriter->dropinput("", "ccd_is_active", "", $optionvals, NULL, '', TRUE);
	
	echo $formwriter->checkboxinput("Single checkbox", "single_checkbox", "sm:col-span-6", "left", NULL, 1, "Check to filter out disabled users");
	
	echo $formwriter->toggleinput("Facebook", "single_toggle", '', 0, 1, '');
	
	echo $formwriter->fileinput("File to Upload", "files[]", "sm:col-span-6", 30, '');

	echo $formwriter->textbox('Comment', 'cmt_body', 'sm:col-span-6', 5, 80, NULL, '', 'no');

	echo $formwriter->textbox('Comment', 'cmt_body2', 'sm:col-span-6', 5, 80, NULL, '', 'yes');
	
	echo $formwriter->dateinput("Date only", "startdate", NULL, 30, NULL, "", 10);
	
	$optionvals = array("Day"=>"0", "Week"=>"1", "Month"=>"2", "Quarter"=>"3", "Year"=>"4");
	$disabledvals = array();
	$readonlyvals = array();
	echo $formwriter->radioinput("Group by:", "interval", NULL, $optionvals, $interval, $disabledvals , $readonlyvals, 'hint');	

	echo $formwriter->textinput('City', 'city', 'sm:col-span-2', 100, NULL, '', 255, '');	
	echo $formwriter->textinput('State', 'state', 'sm:col-span-2', 100, NULL, '', 255, '');	
	echo $formwriter->textinput('Zip', 'zip', 'sm:col-span-2', 100, NULL, '', 255, '');	

	
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
	
	
	echo $formwriter->datetimeinput('Start time', 'ccd_start_time', 'sm:col-span-6', LibraryFunctions::convert_time('UTC', $session->get_timezone(), $session->get_timezone(), 'Y-m-d h:ia'), '', '', '');

	 
	echo $formwriter->datetimeinput('End time', 'ccd_end_time', 'sm:col-span-3', LibraryFunctions::convert_time('UTC', $session->get_timezone(), $session->get_timezone(), 'Y-m-d h:ia'), '', '', '');
	
	

	echo $formwriter->start_buttons('form-btn col-6');
	echo $formwriter->new_form_button('Submit', 'th-btn');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form(true);

	

	
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
	
?>
