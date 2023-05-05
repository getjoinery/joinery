<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/groups_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 30; 
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'grp_update_time', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');

	//$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');



	$groups = new MultiGroup(
		array('category'=>'user'),  //SEARCH CRITERIA
		array($sort=>$sdirection),  //SORT AND DIRECTION array($usrsort=>$usrsdirection)
		$numperpage,  //NUM PER PAGE
		$offset,  //OFFSET
		'OR');
	$numrecords = $groups->count_all();
	$groups->load();




	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'groups',
		'page_title' => 'Add User',
		'readable_title' => 'Add User',
		'breadcrumbs' => array(
			'Users'=>'/admin/admin_users', 
			'Groups' => '',
		),
		'session' => $session,
	)
	);



	$headers = array("Group", "# Users", "Last Update", "Action");
	$altlinks = array();
	$altlinks += array('Add Group'=> '/admin/admin_group_edit');
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Groups',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);	

	foreach ($groups as $group){

		$rowvalues = array();


		array_push($rowvalues, "<a href='/admin/admin_group_members?grp_group_id=$group->key'>".$group->get('grp_name')."</a> ");
		
		$numusers = (string)$group->get_member_count();
		array_push($rowvalues, $numusers);

		array_push($rowvalues, LibraryFunctions::convert_time($group->get('grp_update_time'), "UTC", $session->get_timezone(), 'M j, Y')); 

		$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_group_permanent_delete?grp_group_id='. $group->key.'">
		<input type="hidden" class="hidden" name="action" value="remove" />
		<input type="hidden" class="hidden" name="grp_group_id" value="'.$group->key.'" />
		<button type="submit">Delete</button>
		</form>';

		array_push($rowvalues, $delform);
		
		$page->disprow($rowvalues);
	}
	$page->endtable($pager);

	$page->admin_footer();
?>


