<?php
	
	// ErrorHandler.php no longer needed - using new ErrorManager system
	
	PathHelper::requireOnce('includes/AdminPage.php');
	
	PathHelper::requireOnce('includes/LibraryFunctions.php');

	PathHelper::requireOnce('data/users_class.php');
	PathHelper::requireOnce('data/contact_types_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'contact_type_id', 0, '');	
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');

	$search_criteria = array();

	//ONLY SHOW DELETED TO SUPER ADMINS
	if($_SESSION['permission'] < 10){
		$search_criteria['deleted'] = false;
	}
	
	$contact_types = new MultiContactType(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);	
	$numrecords = $contact_types->count_all();	
	$contact_types->load();
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'contact-types',
		'breadcrumbs' => array(
			'Emails'=>'/admin/admin_emails', 
			'Contact Types' => '',
		),
		'session' => $session,
	)
	);

	$headers = array("Contact Type",  "Description", "Deleted");
	$altlinks = array('New Contact Type' => '/admin/admin_contact_type_edit');
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));	
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Contact Types',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	foreach ($contact_types as $contact_type){
		
		$rowvalues = array();
		array_push($rowvalues, '<a href="/admin/admin_contact_type?ctt_contact_type_id='.$contact_type->key.'">'.$contact_type->get('ctt_name').'</a>');	
		array_push($rowvalues, $contact_type->get('ctt_description'));	
		//array_push($rowvalues, LibraryFunctions::convert_time($contact_type->get('ctt_delete_time'), 'UTC', $session->get_timezone()));
		//array_push($rowvalues, '('.$user->key.') <a href="/admin/admin_user?usr_user_id='.$user->key.'">'.$user->display_name() .'</a> ');

		$status = 'Active';
		if($contact_type->get('ctt_delete_time')) {
			$status = 'Deleted';
		} 		
		array_push($rowvalues, $status);

		$page->disprow($rowvalues);
	}

	$page->endtable($pager);
	$page->admin_footer();
?>

