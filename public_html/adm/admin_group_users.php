<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/groups_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/group_users_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();
	
	if($_POST['action'] == 'add'){
		$group_user = new GroupUser(NULL);
		$group_user->set('gru_name', $_POST['gru_name']);
		$group_user->set('gru_usr_user_id_created', $session->get_user_id()); 
		$group_user->prepare();
		if(!$group_user->check_for_duplicates()){
			$group_user->save();
		}
		else{
			throw new GroupUserException('There is already a group_user with that name.');
		}
	}
	
	$grp_group_id = LibraryFunctions::fetch_variable('grp_group_id', 0, 0, '');
	$group = new Group($grp_group_id, TRUE);

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'group_user_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');

	//$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');



	$group_users = new MultiGroupUser(
		array('group_id' => $group->key),  //SEARCH CRITERIA
		array($sort=>$sdirection),  //SORT AND DIRECTION array($usrsort=>$usrsdirection)
		$numperpage,  //NUM PER PAGE
		$offset,  //OFFSET
		'AND'  //AND OR OR
	);
	$numrecords = $group_users->count_all();
	$group_users->load();




	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 1,
		'page_title' => 'Users in Group',
		'readable_title' => 'Users in Group',
		'breadcrumbs' => array(
			'Groups'=>'/admin/admin_groups', 
			'Users in '. $group->get('grp_name') => '',
		),
		'session' => $session,
	)
	);



	$headers = array('User', 'Action');
	$altlinks = array();
	if(!$group->get('grp_delete_time')) {
		if($group->get_count() > 0){
			$altlinks +=  array('Email group' => '/admin/admin_users_message?grp_group_id='.$group->key);
			//echo '<a class="dropdown-item" href="/admin/admin_users_message?evt_event_id='.$event->key.'">Send email to all</a>';
		} 
	}	
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Users in '. $group->get('grp_name'),
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	foreach ($group_users as $group_user){
		$user = new User($group_user->get('gru_usr_user_id'), TRUE);

		$rowvalues = array();
/*
		$edit_link = '';
		if($_SESSION['permission'] > 8){
			$edit_link = "<a href='/admin/admin_group_user_edit?gru_group_user_id=$group_user->key' class='sortlink'>[edit]</a>";
		}
		array_push($rowvalues, "($group_user->key) <a href='/admin/admin_group_user_users?gru_group_user_id=$group_user->key'>".$group_user->get('gru_name')."</a> ".
		$edit_link);
*/
		array_push($rowvalues, $user->display_name());


		$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_user?usr_user_id='. $user->key.'">
		<input type="hidden" class="hidden" name="action" id="action" value="remove" />
		<input type="hidden" class="hidden" name="gru_group_user_id" id="evs_event_session_id" value="'.$group_user->key.'" />
		<button type="submit">Remove</button>
		</form>';

		array_push($rowvalues, $delform);

		$page->disprow($rowvalues);
	}
	$page->endtable($pager);

	$page->admin_footer();
?>


