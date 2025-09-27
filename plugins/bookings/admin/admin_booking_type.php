<?php

	PathHelper::requireOnce('includes/AdminPage.php');
	
	PathHelper::requireOnce('includes/LibraryFunctions.php');

	require_once(PathHelper::getIncludePath('plugins/bookings/data/bookings_class.php'));
	require_once(PathHelper::getIncludePath('plugins/bookings/data/booking_types_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance(); 

	$booking_type = new BookingType($_GET['bkt_booking_type_id'], TRUE);
	if($booking_type->get('bkt_usr_user_id')){
		$user = new User($booking_type->get('bkt_usr_user_id'), true);
	}
	
	/*
	if($_REQUEST['action'] == 'delete'){
		$booking_type->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$booking_type->soft_delete();

		header("Location: /admin/admin_bookings");
		exit();				
	}
	else if($_REQUEST['action'] == 'undelete'){
		$booking_type->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$booking_type->soft_delete();

		header("Location: /admin/admin_bookings");
		exit();				
	}
	*/

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'booking-types',
		'breadcrumbs' => array(
			'Bookings'=>'/admin/admin_bookings', 
			'Booking Types'.$booking_type->key =>'',
		),
		'session' => $session,
	)
	);	
	
	$options['title'] = 'Booking '.$booking_type->key;
	//$options['altlinks'] = array('Edit Booking' => '/admin/admin_booking_edit?bkt_booking_id='.$booking_type->key);
	//$options['altlinks'] += array('Delete Booking' => '/admin/admin_booking_permanent_delete?bkt_booking_id='.$booking_type->key);
	if(!$booking_type->get('bkt_delete_time') && $_SESSION['permission'] >= 8) {
		//$options['altlinks']['Soft Delete'] = '/admin/admin_booking?action=delete&bkt_booking_id='.$booking_type->key;
	}

	$page->begin_box($options);
	
	echo '<strong>Type: </strong> '.$booking_type->get('bkt_name').'<br />';
	echo '<strong>Created:</strong> '.LibraryFunctions::convert_time($booking_type->get('bkt_create_time'), 'UTC', $session->get_timezone()) .'<br />';

	echo '<strong>Provider: </strong>'.$user->display_name().'<br />';

	if($booking_type->get('bkt_delete_time')){
		echo 'Status: Deleted at '.LibraryFunctions::convert_time($booking_type->get('bkt_delete_time'), 'UTC', $session->get_timezone()).'<br />';
	}
	else if($booking_type->get('bkt_status')) {
		echo '<strong>Active</strong><br>';
	} 
	else{
		echo '<strong>Inactive</strong><br>';
	}  	
	
	echo '<strong>Link:</strong> <a href="'.$booking_type->get('bkt_schedule_link').'">'.$booking_type->get('bkt_schedule_link').'</a><br />';	

	if($booking_type->get('bkt_description_plain')){
		echo '<iframe src="'.$booking_type->get('bkt_description_plain').'" width="600%" height="300" style="border:1px solid black;"></iframe>';
	}
	$page->end_box();		
	
	$page->admin_footer();
?>

