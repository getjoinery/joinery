<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	
	PathHelper::requireOnce('includes/ErrorHandler.php');
	PathHelper::requireOnce('includes/AdminPage.php');
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	PathHelper::requireOnce('data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/mailing_lists_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'mailing_list_id', 0, '');	
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	

	
	$search_criteria = array();
	
	//ONLY SHOW DELETED TO SUPER ADMINS
	if($_SESSION['permission'] < 10){
		$search_criteria['deleted'] = false;
	}

	$mailing_lists = new MultiMailingList(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);	
	$numrecords = $mailing_lists->count_all();	
	$mailing_lists->load();
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'mailing-lists',
		'breadcrumbs' => array(
			'Emails'=>'/admin/admin_emails', 
			'Mailing Lists' => '',
		),
		'session' => $session,
	)
	);
		

	$headers = array("Mailing List",  "Description", "# Registrants");
	$altlinks = array('New Mailing List' => '/admin/admin_mailing_list_edit');
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));	
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Mailing Lists',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);


	foreach ($mailing_lists as $mailing_list){
		
		$deleted = '';
		if($mailing_list->get('mlt_delete_time')){
			$deleted = ' DELETED ';
		}
		
		$rowvalues = array();
		array_push($rowvalues, '<a href="/admin/admin_mailing_list?mlt_mailing_list_id='.$mailing_list->key.'">'.$mailing_list->get('mlt_name').'</a>' . $deleted);	
		array_push($rowvalues, $mailing_list->get('mlt_description'));	
		//array_push($rowvalues, LibraryFunctions::convert_time($mailing_list->get('mlt_delete_time'), 'UTC', $session->get_timezone()));
		//array_push($rowvalues, '('.$user->key.') <a href="/admin/admin_user?usr_user_id='.$user->key.'">'.$user->display_name() .'</a> ');

		$numusers = $mailing_list->count_subscribed_users();
		array_push($rowvalues, $numusers. ' registrants');



		$page->disprow($rowvalues);
	}


	$page->endtable($pager);
	$page->admin_footer();
?>


