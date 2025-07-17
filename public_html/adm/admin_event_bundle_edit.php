<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('includes/AdminPage.php');
	
	PathHelper::requireOnce('includes/LibraryFunctions.php');

	PathHelper::requireOnce('data/groups_class.php');
	PathHelper::requireOnce('data/events_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	if (isset($_REQUEST['grp_group_id'])) {
		$group = new Group($_REQUEST['grp_group_id'], TRUE);
	} 

	if($_POST){

		if ($group){
			$group->remove_all_members();	
		}
		else{
			$group = Group::add_group(strip_tags(trim($_POST['grp_name'])), $session->get_user_id(), 'event');
		}
	

		foreach ($_REQUEST['event_list'] as $event_id){
			$group->add_member($event_id);	
		}

		LibraryFunctions::redirect('/admin/admin_event_bundle?grp_group_id='.$group->key);
		exit;
	}

	if(!$group){
		$group = new Group(NULL);
	}

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'event-bundles',
		'breadcrumbs' => array(
			'Events'=>'/admin/admin_events', 
			'Event Bundles'=>'/admin/admin_event_bundles',
			'Edit Bundle' => '',
		),
		'session' => $session,
	)
	);	

	
	$pageoptions['title'] = "Edit Bundle";
	$page->begin_box($pageoptions);

	// Editing an existing email
	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	
	$validation_rules = array();
	$validation_rules['grp_name']['required']['value'] = 'true';	 
	$validation_rules['"event_list[]"']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);	



	echo $formwriter->begin_form('form', 'POST', '/admin/admin_event_bundle_edit');

	if($group->key){
		echo $formwriter->hiddeninput('grp_group_id', $group->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}
	
	echo $formwriter->textinput('Bundle name', 'grp_name', NULL, 100, $group->get('grp_name'), '', 255, '');	
	
	//GET ALL EVENTS
	$searches = array();
	$searches['status_not_cancelled'] = 1;
	$sort = LibraryFunctions::fetch_variable('sort', 'event_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$events = new MultiEvent(
		$searches,
		array($sort=>$sdirection));
	$events->load();
	$optionvals = $events->get_dropdown_array();	
	
	if ($group->key) {
		//FILL THE CHECKED VALUES
		$checkedvals = array();
		$group_members = $group->get_member_list();
		foreach ($group_members as $group_member){
			$checkedvals[] = $group_member->get('grm_foreign_key_id');
		}
	}
	else{
		$checkedvals = array();
	}
	$disabledvals = array();
	$readonlyvals = array(); 
	echo $formwriter->checkboxList("Events to include in bundle", 'event_list', "ctrlHolder", $optionvals, $checkedvals, $disabledvals, $readonlyvals);	


	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();

	$page->admin_footer();

?>
