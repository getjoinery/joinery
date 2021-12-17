<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/coupon_codes_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$coupon_code = new CouponCode($_GET['ccd_coupon_code_id'], TRUE);
	
	if($_REQUEST['action'] == 'remove'){
		$coupon_code->authenticate_write($session);
		$coupon_code->permanent_delete();

		//$returncoupon_code = $session->get_return();
		header("Location: /admin/admin_coupon_codes");
		exit();		
	}	
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 32,
		'page_title' => 'Coupon Codes',
		'readable_title' => 'Coupon Codes',
		'breadcrumbs' => array(
			'Products'=>'/admin/admin_products', 
			'Coupon Codes'=>'/admin/admin_coupon_codes', 
			'Coupon Code',
		),
		'session' => $session,
	)
	);
	
	$options['title'] = 'CouponCode';
	$options['altlinks'] = array('Edit Coupon Code'=>'/admin/admin_coupon_code_edit?ccd_coupon_code_id='.$coupon_code->key);
	$options['altlinks'] += array('Delete Coupon Code' => '/admin/admin_coupon_code?action=remove&ccd_coupon_code_id='.$coupon_code->key);
	$page->begin_box($options);


	
	
	echo '<strong>Created:</strong> '.LibraryFunctions::convert_time($coupon_code->get('ccd_create_time'), 'UTC', $session->get_timezone()) .'<br />';

	echo '<br /><strong>Code:</strong> '.$coupon_code->get('ccd_code') .'<br />';	
	
	echo '<br /><strong>Status:</strong> '.$coupon_code->get('ccd_is_active')? "Active" : "Inactive" .'<br />';	
	
	echo '<br /><strong>Discount amount:</strong> '.$coupon_code->get('ccd_amount_discount') .'<br />';	
	echo '<br /><strong>Discount percent:</strong> '.$coupon_code->get('ccd_percent_discount') .'<br />';	
	
	echo '<br /><strong>Start time:</strong> '.LibraryFunctions::convert_time($coupon_code->get('ccd_start_time'), 'UTC', $session->get_timezone());

	echo '<br /><strong>End time:</strong> '.LibraryFunctions::convert_time($coupon_code->get('ccd_end_time'), 'UTC', $session->get_timezone());
	
	echo '<br />';
	
	$page->end_box();

	$page->admin_footer();
?>


