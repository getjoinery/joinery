<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once(PathHelper::getIncludePath('plugins/bookings/data/bookings_class.php'));
	require_once(PathHelper::getIncludePath('plugins/bookings/data/booking_types_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance(); 

	$booking = new Booking($_GET['bkn_booking_id'], TRUE);
	$booking_type = BookingType::GetByCalendlyUri($booking->get('bkn_type'));
	if($booking->get('bkn_usr_user_id_booked')){
		$booked_user = new User($booking->get('bkn_usr_user_id_booked'), true);
	}
	if($booking->get('bkn_usr_user_id_client')){
		$client_user = new User($booking->get('bkn_usr_user_id_client'), true);
	}
	/*
	if($_REQUEST['action'] == 'delete'){
		$booking->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$booking->soft_delete();

		header("Location: /admin/admin_bookings");
		exit();				
	}
	else if($_REQUEST['action'] == 'undelete'){
		$booking->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$booking->soft_delete();

		header("Location: /admin/admin_bookings");
		exit();				
	}
	*/
	
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'bookings',
		'breadcrumbs' => array(
			'Bookings'=>'/admin/admin_bookings', 
			'Booking '.$booking->key =>'',
		),
		'session' => $session,
	)
	);	
	
	$options['title'] = 'Booking '.$booking->key;
	//$options['altlinks'] = array('Edit Booking' => '/admin/admin_booking_edit?bkn_booking_id='.$booking->key);
	//$options['altlinks'] += array('Delete Booking' => '/admin/admin_booking_permanent_delete?bkn_booking_id='.$booking->key);
	if(!$booking->get('bkn_delete_time') && $_SESSION['permission'] >= 8) {
		//$options['altlinks']['Soft Delete'] = '/admin/admin_booking?action=delete&bkn_booking_id='.$booking->key;
	}

	$page->begin_box($options);
	
	echo '<strong>Type: </strong> '.$booking_type->get('bkt_name').'<br />';
	echo '<strong>Created:</strong> '.LibraryFunctions::convert_time($booking->get('bkn_create_time'), 'UTC', $session->get_timezone()) .'<br />';
	echo '<strong>Starts:</strong> '.LibraryFunctions::convert_time($booking->get('bkn_start_time'), 'UTC', $session->get_timezone()) .'<br />';
	echo '<strong>Ends:</strong> '.LibraryFunctions::convert_time($booking->get('bkn_end_time'), 'UTC', $session->get_timezone()) .'<br />';
	
	if($booking->get('bkn_usr_user_id_booked')){
		echo '<strong>Provider: </strong>'.$booked_user->display_name().'<br />';
	}
	if($booking->get('bkn_usr_user_id_client')){
		echo '<strong>Client: </strong>'.$client_user->display_name().'<br />';
	}
	
	if($booking->get('bkn_delete_time')){
		echo 'Status: Deleted at '.LibraryFunctions::convert_time($booking->get('bkn_delete_time'), 'UTC', $session->get_timezone()).'<br />';
	}
	else if($booking->get('bkn_status') == Booking::BOOKING_STATUS_CREATED) {
		echo '<strong>Created</strong><br>';
		echo '<strong>Location: </strong>'.$booking->get('bkn_location').'<br>';
		echo '<strong>Cancel link:</strong> <a href="'.$booking->get('bkn_cancel_link').'">'.$booking->get('bkn_cancel_link').'</a></p><br />';
		echo '<strong>Reschedule link:</strong> <a href="'.$booking->get('bkn_reschedule_link').'">'.$booking->get('bkn_reschedule_link').'</a></p><br />';
	} 
	else if($booking->get('bkn_status') == Booking::BOOKING_STATUS_BOOKED) {
		echo '<strong>Active</strong><br>';
		echo '<strong>Location: </strong>'.$booking->get('bkn_location').'<br>';
		echo '<strong>Cancel link:</strong> <a href="'.$booking->get('bkn_cancel_link').'">'.$booking->get('bkn_cancel_link').'</a></p><br />';
		echo '<strong>Reschedule link:</strong> <a href="'.$booking->get('bkn_reschedule_link').'">'.$booking->get('bkn_reschedule_link').'</a></p><br />';
	} 
	else if($booking->get('bkn_status') == Booking::BOOKING_STATUS_COMPLETED) {
		echo '<strong>Completed</strong><br>';
	} 
	else if($booking->get('bkn_status') == Booking::BOOKING_STATUS_CANCELED) {
		echo '<strong>Canceled</strong><br>';
	} 	
	
	//echo '<strong>Link:</strong> <a href="'.$booking->get_url().'">'.$settings->get_setting('webDir').$booking->get_url().'</a><br />';	

	//echo '<iframe src="'.$booking->get_url().'" width="100%" height="500" style="border:1px solid black;"></iframe>';

	$page->end_box();		
	
	$page->admin_footer();
?>

