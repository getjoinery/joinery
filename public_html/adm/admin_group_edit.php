<?php
	
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/groups_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	if (isset($_REQUEST['grp_group_id'])) {
		$group = new Group($_REQUEST['grp_group_id'], TRUE);
	} else {
		$group = new Group(NULL);
	}

	if($_POST){
		Group::add_group(strip_tags($_POST['grp_name']), $session->get_user_id(), 'user');
		
		LibraryFunctions::redirect('/admin/admin_groups');
		return;
	}

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'groups',
		'breadcrumbs' => array(
			'Groups'=>'/admin/admin_groups', 
			'New Group' => '',
		),
		'session' => $session,
	)
	);	

	$pageoptions['title'] = "New Group";
	$page->begin_box($pageoptions);

	// Editing an existing group
	$formwriter = $page->getFormWriter('form1', 'v2', [
		'model' => $group
	]);

	$formwriter->begin_form();

	if($group->key){
		$formwriter->hiddeninput('grp_group_id', ['value' => $group->key]);
		$formwriter->hiddeninput('action', ['value' => 'edit']);
	}

	$formwriter->textinput('grp_name', 'Group name', [
		'validation' => ['required' => true]
	]);

	$formwriter->submitbutton('btn_submit', 'Submit');
	$formwriter->end_form();

	$page->end_box();
	$page->admin_footer();

?>
