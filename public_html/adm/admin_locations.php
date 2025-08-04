<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	
	// ErrorHandler.php no longer needed - using new ErrorManager system
	PathHelper::requireOnce('includes/AdminPage.php');
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	PathHelper::requireOnce('data/users_class.php');
	PathHelper::requireOnce('data/locations_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'location_id', 0, '');	
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	

	
	$search_criteria = array();
	
	//ONLY SHOW DELETED TO SUPER ADMINS
	if($_SESSION['permission'] < 10){
		$search_criteria['deleted'] = false;
	}

	$locations = new MultiLocation(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);	
	$numrecords = $locations->count_all();	
	$locations->load();
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'locations',
		'breadcrumbs' => array(
			'Emails'=>'/admin/admin_emails', 
			'Locations' => '',
		),
		'session' => $session,
	)
	);
		

	$headers = array("Location",  "Description", "Deleted");
	$altlinks = array('New Location'=>'/admin/admin_location_edit');
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));	
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Locations',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);


	foreach ($locations as $location){
		
		$rowvalues = array();
		array_push($rowvalues, '<a href="/admin/admin_location?loc_location_id='.$location->key.'">'.$location->get('loc_name').'</a>');	
		array_push($rowvalues, $location->get('loc_short_description'));	
		//array_push($rowvalues, LibraryFunctions::convert_time($location->get('loc_delete_time'), 'UTC', $session->get_timezone()));
		//array_push($rowvalues, '('.$user->key.') <a href="/admin/admin_user?usr_user_id='.$user->key.'">'.$user->display_name() .'</a> ');

		$status = 'Active';
		if($location->get('loc_delete_time')) {
			$status = 'Deleted';
		} 		
		array_push($rowvalues, $status);


		$page->disprow($rowvalues);
	}


	$page->endtable($pager);
	$page->admin_footer();
?>


