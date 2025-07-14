<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	
	PathHelper::requireOnce('includes/ErrorHandler.php');
	PathHelper::requireOnce('includes/AdminPage.php');
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	PathHelper::requireOnce('data/users_class.php');
	PathHelper::requireOnce('data/phone_number_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'user_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');


	$search_criteria = array();
	if(strstr($searchterm, '@')){
		$search_criteria['email_like'] = $searchterm;
	}
	else if ($searchterm != ''){
		$fsearch = trim(preg_replace('/\s+/', ' ', $searchterm));
		$fsearch = str_replace(' ', ' | ', $fsearch);

		$user_id_list = array();


		$phonesearch = preg_replace('/[^0-9]/', '', $searchterm);
		if(strlen($phonesearch) >= 7) {
			$phone_numbers = new MultiPhoneNumber(
				array('phone_number_like'=>$phonesearch),
				NULL);
			$numphonerecords = $phone_numbers->count_all();
			if($numphonerecords) {
				$phone_numbers->load();
				foreach($phone_numbers as $phone_number) {
					array_push($user_id_list, $phone_number->get('phn_usr_user_id'));
				}
			}
		}



		$search_criteria['user_id_list'] = $user_id_list;
		if(strstr($searchterm, ' ')) {
			$search_criteria['name_like'] = $fsearch;
		} 
		else {
			$search_criteria['first_name_like'] = $fsearch;
			$search_criteria['last_name_like'] = $fsearch;
			$search_criteria['nickname_like'] = $fsearch;
		}

		if(is_numeric($searchterm) && (int)$searchterm > 0 && (int)$searchterm < 2147483647) {
			$search_criteria['user_id'] = (int)$searchterm;
		}

	}
	else{
		$search_criteria['not_system_users'] = true;
	}

	//ONLY SHOW DELETED TO SUPER ADMINS
	if($_SESSION['permission'] < 10){
		$search_criteria['deleted'] = false;
	}
	
	$users = new MultiUser(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset,
		'OR');
	$numrecords = $users->count_all();
	$users->load();

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'users-list',
		'page_title' => 'Users',
		'readable_title' => 'Users',
		'breadcrumbs' => array(
			'All Users'=>'',
		),
		'session' => $session,
	)
	);	


	$headers = array("User", "Email", "Signup Date", "Email Verified");
	
	$altlinks = array();
	if($_SESSION['permission'] == 10){
		$altlinks = array('Add User'=>'/admin/admin_user_add');
	}
	
	if($searchterm){
		$title = 'Users matching "'.$searchterm.'"';
	}
	else{
		$title = 'User list';
	}
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => $title,
		'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	foreach ($users as $user){
		
		$deleted_status = '';
		if($user->get('usr_delete_time')) {
			$deleted_status = ' DELETED ';
		}

		$rowvalues = array();

		array_push($rowvalues, "<a href='/admin/admin_user?usr_user_id=$user->key'>".$user->display_name()."</a> ".$deleted_status);
		array_push($rowvalues, $user->get('usr_email'));
		array_push($rowvalues, LibraryFunctions::convert_time($user->get('usr_signup_date'), "UTC", $session->get_timezone(), 'M j, Y')); 


		if($user->get('usr_email_is_verified')) {
			$status = 'Verified';
		} 
		else {
			$status = 'Unverified';
		}


		array_push($rowvalues, $status);

		$page->disprow($rowvalues);
	}
	$page->endtable($pager);



	$page->admin_footer();
?>


