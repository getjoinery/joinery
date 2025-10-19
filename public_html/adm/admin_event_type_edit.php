<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('adm/logic/admin_event_type_edit_logic.php'));

	$page_vars = process_logic(admin_event_type_edit_logic($_GET, $_POST));
	extract($page_vars);

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
	$formwriter = $page->getFormWriter('form1');

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
