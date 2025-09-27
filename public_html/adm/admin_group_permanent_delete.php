<?php
	
	// ErrorHandler.php no longer needed - using new ErrorManager system
	
	PathHelper::requireOnce('includes/AdminPage.php');
	
	PathHelper::requireOnce('includes/LibraryFunctions.php');

	PathHelper::requireOnce('data/groups_class.php');
	
if ($_POST['confirm']){

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$grp_group_id = LibraryFunctions::fetch_variable('grp_group_id', NULL, 1, 'You must provide a group to delete here.');
	$confirm = LibraryFunctions::fetch_variable('confirm', NULL, 1, 'You must confirm the action.');	
	
	if ($confirm) {
		$group = new Group($grp_group_id, TRUE);
		$group->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$group->permanent_delete();
	}

	//NOW REDIRECT
	$session = SessionControl::get_instance();
	$returnurl = $session->get_return();
	header("Location: $returnurl");
	exit();

}
else{
	$grp_group_id = LibraryFunctions::fetch_variable('grp_group_id', NULL, 1, 'You must provide a group to edit.');

	$group = new Group($grp_group_id, TRUE);
	
	$session = SessionControl::get_instance();
	$session->set_return("/admin/admin_groups");

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'groups',
		'page_title' => 'Group',
		'readable_title' => 'Delete Group',
		'breadcrumbs' => array(
			'Groups'=>'/admin/admin_groups', 
			'Delete ' . $group->get('grp_name') => '',
		),
		'session' => $session,
	)
	);

	$pageoptions['title'] = 'Delete Group '.$group->get('grp_name');
	$page->begin_box($pageoptions);

	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	echo $formwriter->begin_form("form", "post", "/admin/admin_group_permanent_delete");

	echo '<fieldset><h4>Confirm Delete</h4>';
		echo '<div class="fields full">';
		echo '<p>WARNING:  This will permanently delete this group ('.$group->get('grp_name') . ').</p>';

	echo $formwriter->hiddeninput("confirm", 1);
	echo $formwriter->hiddeninput("grp_group_id", $grp_group_id);

			echo $formwriter->start_buttons();
		echo $formwriter->new_form_button('Submit');
		echo $formwriter->end_buttons();

		echo '</div>';
	echo '</fieldset>';
	echo $formwriter->end_form();

	$page->end_box();

	$page->admin_footer();

}
?>
