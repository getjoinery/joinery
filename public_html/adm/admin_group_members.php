<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/groups_class.php'));
	require_once(PathHelper::getIncludePath('data/group_members_class.php'));

	require_once(PathHelper::getIncludePath('adm/logic/admin_group_members_logic.php'));

	$page_vars = process_logic(admin_group_members_logic(array_merge($_GET, $_POST)));

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

		$delform = AdminPage::action_button('Remove', '/admin/admin_user', [
			'hidden'  => ['action' => 'remove_from_group', 'grm_group_member_id' => $group_member->key, 'usr_user_id' => $user->key],
			'confirm' => 'Remove this user from the group?',
		]);

		array_push($rowvalues, $delform);

		$page->disprow($rowvalues);
	}
	$page->endtable($pager);

	//EMAILS TO THE GROUP
	$group_emails = new MultiEmail(
		array('recipient_group' => array('provider' => 'group', 'reference_id' => $group->key), 'deleted' => false),
		array('email_id' => 'DESC'),
		20,
		(int)LibraryFunctions::fetch_variable('eoffset', 0, 0, ''));
	$epager = new Pager(array('numrecords'=>$group_emails->count_all(), 'numperpage'=> 20), 'e');
	$page->email_audience_table($group_emails, array(
		'altlinks' => $altlinks,
		'title' => 'Emails to '. $group->get('grp_name'),
	), $epager);

	$page->admin_footer();
?>

