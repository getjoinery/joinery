<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/coupon_codes_class.php'));

	require_once(PathHelper::getIncludePath('adm/logic/admin_coupon_code_logic.php'));

	$page_vars = process_logic(admin_coupon_code_logic($_GET, $_POST));

	extract($page_vars);

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

