<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/groups_class.php'));
	require_once(PathHelper::getIncludePath('data/group_members_class.php'));

	require_once(PathHelper::getIncludePath('adm/logic/admin_group_members_logic.php'));

	$page_vars = process_logic(admin_group_members_logic($_GET, $_POST));

	extract($page_vars);

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

