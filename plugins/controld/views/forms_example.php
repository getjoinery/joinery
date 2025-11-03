<?php
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	// PathHelper is already loaded
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$page = new PublicPage(TRUE);
	$hoptions=array(
		'is_valid_page' => $is_valid_page,
		'title'=>'Log In'
		);
	$page->public_header($hoptions,NULL);

	echo PublicPage::BeginPage('Log In');


	$formwriter = $page->getFormWriter('form1', 'v2', [
		'action' => '/admin/admin'
	]);




	// Note: FormWriter v2 handles validation differently - validation rules are applied per-field through options array
	// The set_validate() method from v1 is not available in v2

	$formwriter->begin_form('contact-form style2', 'POST');

	$formwriter->textinput('ccd_code', '', [
		'maxlength' => 255,
		'placeholder' => 'Coupon code'
	]);

	$formwriter->textinput('website', '', [
		'maxlength' => 255,
		'placeholder' => 'Website',
		'prefix' => 'www.text.com/'
	]);




	$optionvals = array(0=>"Inactive", 1=>"Active");
	$formwriter->dropinput("ccd_is_active", "", [
		'options' => $optionvals
	]);

	$formwriter->checkboxinput("single_checkbox", "Single checkbox", [
		'value' => 1,
		'hint' => "Check to filter out disabled users"
	]);

	// Note: toggleinput() does not exist in FormWriter v2
	// Use checkboxinput or radioinput instead

	$formwriter->fileinput("files[]", "File to Upload", [
		'maxlength' => 30
	]);

	$formwriter->textbox('cmt_body', 'Comment', [
		'rows' => 5,
		'cols' => 80,
		'use_editor' => false
	]);

	$formwriter->textbox('cmt_body2', 'Comment', [
		'rows' => 5,
		'cols' => 80,
		'use_editor' => true
	]);

	$formwriter->dateinput("startdate", "Date only", [
		'maxlength' => 30
	]);

	$optionvals = array("0"=>"Day", "1"=>"Week", "2"=>"Month", "3"=>"Quarter", "4"=>"Year");
	$disabledvals = array();
	$readonlyvals = array();
	$formwriter->radioinput("interval", "Group by:", [
		'options' => $optionvals,
		'hint' => 'hint'
	]);

	$formwriter->textinput('city', 'City', [
		'maxlength' => 255
	]);
	$formwriter->textinput('state', 'State', [
		'maxlength' => 255
	]);
	$formwriter->textinput('zip', 'Zip', [
		'maxlength' => 255
	]);


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
	$formwriter->checkboxList('products_list', "Valid products for this code", [
		'options' => $optionvals,
		'checked' => $checkedvals
	]);


	$formwriter->datetimeinput('ccd_start_time', 'Start time', [
		'value' => LibraryFunctions::convert_time('UTC', $session->get_timezone(), $session->get_timezone(), 'Y-m-d h:ia')
	]);


	$formwriter->datetimeinput('ccd_end_time', 'End time', [
		'value' => LibraryFunctions::convert_time('UTC', $session->get_timezone(), $session->get_timezone(), 'Y-m-d h:ia')
	]);

	// Note: start_buttons(), new_form_button(), and end_buttons() are v1 methods
	// Use submitbutton() instead in FormWriter v2
	$formwriter->submitbutton('submit', 'Submit', ['class' => 'btn btn-primary']);
	$formwriter->end_form(true);




	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
