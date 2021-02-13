<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/groups_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/group_members_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	
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
		'menu-id'=> 2,
		'page_title' => 'Event Bundle',
		'readable_title' => 'Event Bundle',
		'breadcrumbs' => array(
			'Events'=>'/admin/admin_events', 
			'Events in bundle: '. $group->get('grp_name') => '',
		),
		'session' => $session,
	)
	);



	$headers = array('Event', 'Action');
	$altlinks = array();
	if(!$group->get('grp_delete_time')) {
		$altlinks +=  array('Edit bundle' => '/admin/admin_event_bundle_edit?grp_group_id='.$group->key);
		//echo '<a class="dropdown-item" href="/admin/admin_users_message?evt_event_id='.$event->key.'">Send email to all</a>';
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
		$event = new Event($group_member->get('grm_evt_event_id'), TRUE);

		$rowvalues = array();
		array_push($rowvalues, $event->get('evt_name'));
		array_push($rowvalues, $delform);

		$page->disprow($rowvalues);
	}
	$page->endtable($pager);

	$page->admin_footer();
?>


