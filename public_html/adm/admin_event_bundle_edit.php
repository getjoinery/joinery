<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/groups_class.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));

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
		return;
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

	// FormWriter V2 with model and edit_primary_key_value
	$formwriter = $page->getFormWriter('form1', 'v2', [
		'model' => $group,
		'edit_primary_key_value' => $group->key
	]);

	$formwriter->begin_form();

	$formwriter->textinput('grp_name', 'Bundle name', [
		'validation' => ['required' => true, 'maxlength' => 255]
	]);

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

	$formwriter->checkboxList('event_list', 'Events to include in bundle', [
		'options' => $optionvals,
		'checked' => $checkedvals,
		'validation' => ['required' => true]
	]);

	$formwriter->submitbutton('btn_submit', 'Submit');
	$formwriter->end_form();

	$page->admin_footer();

?>
