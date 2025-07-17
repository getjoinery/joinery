<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('includes/ErrorHandler.php');
	
	PathHelper::requireOnce('includes/AdminPage.php');
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');

	PathHelper::requireOnce('data/coupon_codes_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$coupon_code = new CouponCode($_GET['ccd_coupon_code_id'], TRUE);
	
	if($_REQUEST['action'] == 'remove'){
		$coupon_code->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$coupon_code->permanent_delete();

		//$returncoupon_code = $session->get_return();
		header("Location: /admin/admin_coupon_codes");
		exit();		
	}	
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'coupons',
		'page_title' => 'Coupon Codes',
		'readable_title' => 'Coupon Codes',
		'breadcrumbs' => array(
			'Products'=>'/admin/admin_products', 
			'Coupon Codes'=>'/admin/admin_coupon_codes', 
			$coupon_code->get('ccd_code') => '',
		),
		'session' => $session,
	)
	);
	
	$options['title'] = 'Coupon Code';
	$options['altlinks'] = array('Edit Coupon Code'=>'/admin/admin_coupon_code_edit?ccd_coupon_code_id='.$coupon_code->key);
	$options['altlinks'] += array('Delete Coupon Code' => '/admin/admin_coupon_code?action=remove&ccd_coupon_code_id='.$coupon_code->key);
	$page->begin_box($options);


	echo '<br /><strong>Code:</strong> '.$coupon_code->get('ccd_code') . ' (' . LibraryFunctions::bool_to_english($coupon_code->get('ccd_is_active'), "Active", "Inactive") . ')<br />';		
	
	echo '<strong>Created:</strong> '.LibraryFunctions::convert_time($coupon_code->get('ccd_create_time'), 'UTC', $session->get_timezone()) .'<br />';

	
	$settings = Globalvars::get_instance();
	$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];
	
	$stackable = '(Not stackable)';
	if($coupon_code->get('ccd_is_stackable')){
		$stackable = '(Stackable)';
	}
	
	echo '<br /><strong>Applies to:</strong> ';
	if($coupon_code->get('ccd_applies_to') == 0){
		echo 'All products';
	}
	else if($coupon_code->get('ccd_applies_to') == 1){
		echo 'Subscriptions only';
	}
	else if($coupon_code->get('ccd_applies_to') == 2){
		echo 'Single purchases only';
	}
	else if($coupon_code->get('ccd_applies_to') == 3){
		echo 'Custom';
	}
	echo '<br />';
	
	echo '<br /><strong>Max uses:</strong> ';
	if($coupon_code->get('ccd_max_uses') > 0){
		echo $coupon_code->get('ccd_max_uses');
	}	
	else{
		echo 'Unlimited';
	}
	
	if($coupon_code->get('ccd_amount_discount')){
		echo '<br /><strong>Discount:</strong> '.$currency_symbol.$coupon_code->get('ccd_amount_discount') .$stackable.'<br />';	
	}
	else{
		echo '<br /><strong>Discount:</strong> '.$coupon_code->get('ccd_percent_discount') .'%'.$stackable.'<br />';	
	}
	
	if($coupon_code->get('ccd_start_time')){
		echo '<br /><strong>Start time:</strong> '.LibraryFunctions::convert_time($coupon_code->get('ccd_start_time'), 'UTC', $session->get_timezone());
	}

	if($coupon_code->get('ccd_start_time')){
		echo '<br /><strong>End time:</strong> '.LibraryFunctions::convert_time($coupon_code->get('ccd_end_time'), 'UTC', $session->get_timezone());
	}
	
	echo '<br />';
	
	$page->end_box();

	$page->admin_footer();
?>


