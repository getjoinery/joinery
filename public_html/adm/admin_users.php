<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/phone_number_class.php'));
	require_once(PathHelper::getIncludePath('adm/logic/admin_users_logic.php'));

	$page_vars = process_logic(admin_users_logic($_GET, $_POST));
	extract($page_vars);

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
