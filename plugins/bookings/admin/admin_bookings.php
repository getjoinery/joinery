<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	
	require_once(LibraryFunctions::get_plugin_file_path('bookings_class.php', 'bookings', 'data'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'booking_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');
	
	
	$search_criteria = array();
	
	//ONLY SHOW DELETED TO SUPER ADMINS
	if($_SESSION['permission'] < 10){
		$search_criteria['deleted'] = false;
	}

	$bookings = new MultiBooking(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);	
	$numrecords = $bookings->count_all();	
	$bookings->load();
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'bookings',
		'breadcrumbs' => array(
			'Surveys'=>'/admin/admin_surveys', 
			'Bookings'=>'', 
		),
		'session' => $session,
	)
	);	
	

	$headers = array("Booking", "Booking Time", "Status");
	$altlinks = array('Sync with Calendly'=>'/utils/calendly_synchronize');
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Bookings',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	foreach ($bookings as $booking){
		if($booking->get('bkn_usr_user_id_booked')){
			$booked_user = new User($booking->get('bkn_usr_user_id_booked'), TRUE);
		}
		else{
			$booked_user = new User(NULL);
		}
		
		if($booking->get('bkn_usr_user_id_client')){
			$client_user = new User($booking->get('bkn_usr_user_id_client'), TRUE);
		}
		else{
			$client_user = new User(NULL);
		}
		
		$rowvalues = array();
		array_push($rowvalues, "<a href='/admin/admin_booking?bkn_booking_id=$booking->key'>".$client_user->display_name()."</a>");	
		//array_push($rowvalues, $booking->get('bkn_type'));
		array_push($rowvalues, LibraryFunctions::convert_time($booking->get('bkn_start_time'), 'UTC', $session->get_timezone()));
		//array_push($rowvalues, LibraryFunctions::convert_time($booking->get('bkn_published_time'), 'UTC', $session->get_timezone()));

		if($booking->get('bkn_status') == Booking::BOOKING_STATUS_CREATED) {
			$status = 'Created';
		} 
		else if($booking->get('bkn_status') == Booking::BOOKING_STATUS_BOOKED) {
			$status = 'Booked';
		} 
		else if($booking->get('bkn_status') == Booking::BOOKING_STATUS_COMPLETED) {
			$status = 'Completed';
		} 
		else if($booking->get('bkn_status') == Booking::BOOKING_STATUS_CANCELED) {
			$status = 'Canceled';
		} 		
		array_push($rowvalues, $status);

		$page->disprow($rowvalues);
	}

	$page->endtable($pager);	
	$page->admin_footer();
?>

