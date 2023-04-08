<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/groups_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/group_members_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();
	
	
	if($_POST['action'] == 'add_to_group'){
		//ADD THE USER TO A GROUP
		$group = new Group($_POST['grp_group_id'], TRUE);
		$group->add_member($user->key);
		header("Location: /admin/admin_group_members?grp_group_id=".$group->key);
		exit();			
	}
	else if($_POST['action'] == 'remove_from_group'){
		$groupmember = new GroupMember($_POST['grm_group_member_id'], TRUE);
		$groupmember->remove();
		header("Location: /admin/admin_group_members?grp_group_id=".$groupmember->get('grm_grp_group_id'));
		exit();				
	}	
	
	$grp_group_id = LibraryFunctions::fetch_variable('grp_group_id', 0, 0, '');
	$group = new Group($grp_group_id, TRUE);

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'group_member_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');

	//$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');



	$group_members = new MultiGroupMember(
		array('group_id' => $group->key),  //SEARCH CRITERIA
		array($sort=>$sdirection),  //SORT AND DIRECTION array($usrsort=>$usrsdirection)
		$numperpage,  //NUM PER PAGE
		$offset,  //OFFSET
		'AND'  //AND OR OR
	);
	$numrecords = $group_members->count_all();
	$group_members->load();




	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'groups',
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
		if($group->get_member_count() > 0){
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

	foreach ($group_members as $group_member){
		$user = new User($group_member->get('grm_foreign_key_id'), TRUE);

		$rowvalues = array();
/*
		$edit_link = '';
		if($_SESSION['permission'] > 8){
			$edit_link = "<a href='/admin/admin_group_member_edit?grm_group_member_id=$group_member->key' class='sortlink'>[edit]</a>";
		}
		array_push($rowvalues, "($group_member->key) <a href='/admin/admin_group_member_users?grm_group_member_id=$group_member->key'>".$group_member->get('grm_name')."</a> ".
		$edit_link);
*/
		array_push($rowvalues, $user->display_name());


		$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_user?usr_user_id='. $user->key.'">
		<input type="hidden" class="hidden" name="action" id="action" value="remove_from_group" />
		<input type="hidden" class="hidden" name="grm_group_member_id" value="'.$group_member->key.'" />
		<button type="submit">Remove</button>
		</form>';

		array_push($rowvalues, $delform);

		$page->disprow($rowvalues);
	}
	$page->endtable($pager);

	$page->admin_footer();
?>


