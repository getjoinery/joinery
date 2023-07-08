<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/locations_class.php');

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

/*
		if($location->get('loc_delete_time')){
			$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_location?loc_location_id='. $location->key.'">
			<input type="hidden" class="hidden" name="action" id="action" value="undelete" />
			<input type="hidden" class="hidden" name="loc_location_id" id="loc_location_id" value="'.$location->key.'" />
			<button class="uk-button" type="submit">Undelete</button>
			</form>';
		}
		else{
			$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_location?loc_location_id='. $location->key.'">
			<input type="hidden" class="hidden" name="action" id="action" value="delete" />
			<input type="hidden" class="hidden" name="loc_location_id" id="loc_location_id" value="'.$location->key.'" />
			<button class="uk-button" type="submit">Delete</button>
			</form>';			
		}
		array_push($rowvalues, $delform);	
*/

		$page->disprow($rowvalues);
	}


	$page->endtable($pager);
	$page->admin_footer();
?>


