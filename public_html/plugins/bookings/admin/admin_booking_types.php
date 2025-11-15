<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('plugins/bookings/data/bookings_class.php'));
	require_once(PathHelper::getIncludePath('plugins/bookings/data/booking_types_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'booking_type_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');

	$search_criteria = array();

	$bookings = new MultiBookingType(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);	
	$numrecords = $bookings->count_all();	
	$bookings->load();
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'booking-types',
		'breadcrumbs' => array(
			'Surveys'=>'/admin/admin_surveys', 
			'Bookings'=>'', 
		),
		'session' => $session,
	)
	);	

	$headers = array("Booking Type", "Status");
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

		$rowvalues = array();
		array_push($rowvalues, '<a href="/admin/admin_booking_type?bkt_booking_type_id='.$booking->key.'">'.$booking->get('bkt_name').'</a>');	

		//array_push($rowvalues, LibraryFunctions::convert_time($booking->get('bkn_start_time'), 'UTC', $session->get_timezone()));

		if($booking->get('bkn_status') == BookingType::BOOKING_STATUS_ACTIVE) {
			$status = 'Active';
		} 
		else if($booking->get('bkn_status') == BookingType::BOOKING_STATUS_INACTIVE) {
			$status = 'Inactive';
		} 
		
		array_push($rowvalues, $status);

		$page->disprow($rowvalues);
	}

	$page->endtable($pager);	
	$page->admin_footer();
?>

