<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	
	PathHelper::requireOnce('includes/AdminPage.php');
	
	PathHelper::requireOnce('includes/LibraryFunctions.php');

	PathHelper::requireOnce('data/event_types_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(7);
	
	if (isset($_REQUEST['ety_event_type_id'])) {
		$event_type = new EventType($_REQUEST['ety_event_type_id'], TRUE);
	} else {
		$event_type = new EventType(NULL);
	}

	if ($_POST) {
		// Submitting a product edit

		$editable_fields = array('ety_name');

		foreach($editable_fields as $field) {
			$event_type->set($field, $_POST[$field]);
		}

		$event_type->save();

		LibraryFunctions::redirect('/admin/admin_event_types');	
		return;
	} 

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'events',
		'page_title' => 'Event Types',
		'readable_title' => 'Event Types',
		'breadcrumbs' => array(
			'Events'=>'/admin/admin_events', 
			'Event Types' => '',
		),
		'session' => $session,
	)
	);
	

	
	$options['title'] = 'Edit Event Type';
	$page->begin_box($options);

	// Editing an existing product
	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	
	$validation_rules = array();
	$validation_rules['ety_name']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);				
	
	
	echo $formwriter->begin_form('form1', 'POST', '/admin/admin_event_type_edit');
	if($event_type->key){
		echo $formwriter->hiddeninput('ety_event_type_id', $event_type->key);
	}
	echo $formwriter->textinput('Event Type Name', 'ety_name', NULL, 100, $event_type->get('ety_name'), '', 255, '');

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();
	
	$page->end_box();


	$page->admin_footer();

?>
