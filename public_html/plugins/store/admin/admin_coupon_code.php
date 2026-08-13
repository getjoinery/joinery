<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('plugins/store/data/coupon_codes_class.php'));

	require_once(PathHelper::getIncludePath('plugins/store/admin/logic/admin_coupon_code_logic.php'));

	$page_vars = process_logic(admin_coupon_code_logic(array_merge($_GET, $_POST)));

	extract($page_vars);

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'coupons',
		'page_title' => 'Coupon Codes',
		'readable_title' => 'Coupon Codes',
		'breadcrumbs' => array(
			'Products'=>'/plugins/store/admin/admin_products',
			'Coupon Codes'=>'/plugins/store/admin/admin_coupon_codes',
			$coupon_code->get('ccd_code') => '',
		),
		'session' => $session,
	)
	);

	$options['title'] = 'Coupon Code';
	$options['altlinks'] = array('Edit Coupon Code'=>'/plugins/store/admin/admin_coupon_code_edit?ccd_coupon_code_id='.$coupon_code->key);
	$options['altlinks'] += array('Delete Coupon Code' => array('post' => '/plugins/store/admin/admin_coupon_code', 'hidden' => array('action' => 'remove', 'ccd_coupon_code_id' => $coupon_code->key)));
	$page->begin_box($options);

	echo '<br /><strong>Code:</strong> '.htmlspecialchars($coupon_code->get('ccd_code')) . ' (' . LibraryFunctions::bool_to_english($coupon_code->get('ccd_is_active'), "Active", "Inactive") . ')<br />';

	echo '<strong>Created:</strong> '.$coupon_code->get_local('ccd_create_time') .'<br />';

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
		echo htmlspecialchars($coupon_code->get('ccd_max_uses'));
	}
	else{
		echo 'Unlimited';
	}

	if($coupon_code->get('ccd_amount_discount')){
		echo '<br /><strong>Discount:</strong> '.$currency_symbol.htmlspecialchars($coupon_code->get('ccd_amount_discount')) .$stackable.'<br />';
	}
	else{
		echo '<br /><strong>Discount:</strong> '.htmlspecialchars($coupon_code->get('ccd_percent_discount')) .'%'.$stackable.'<br />';
	}

	if($coupon_code->get('ccd_start_time')){
		echo '<br /><strong>Start time:</strong> '.$coupon_code->get_local('ccd_start_time');
	}

	if($coupon_code->get('ccd_start_time')){
		echo '<br /><strong>End time:</strong> '.$coupon_code->get_local('ccd_end_time');
	}

	echo '<br />';

	$page->end_box();

	$page->admin_footer();
?>

