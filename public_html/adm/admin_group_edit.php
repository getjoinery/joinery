<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/groups_class.php');

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
		exit;
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

	// Editing an existing email
	$formwriter = new FormWriterMaster('form1');
	
	$validation_rules = array();
	$validation_rules['grp_name']['required']['value'] = 'true';	
	echo $formwriter->set_validate($validation_rules);	



	echo $formwriter->begin_form('form', 'POST', '/admin/admin_group_edit');

	if($group->key){
		echo $formwriter->hiddeninput('grp_group_id', $group->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}
	
	echo $formwriter->textinput('Group name', 'grp_name', NULL, 100, $group->get('grp_name'), '', 255, '');	
	


	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();

	$page->admin_footer();

?>
