<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('adm/logic/admin_contact_type_edit_logic.php'));

	$page_vars = process_logic(admin_contact_type_edit_logic($_GET, $_POST));
	extract($page_vars);

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'contact-types',
		'breadcrumbs' => array(
			'Emails'=>'/admin/admin_emails',
			'Contact Types'=>'/admin/admin_contact_types',
			'Contact Type: '.$contact_type->get('ctt_name')=>'/admin/admin_contact_type?ctt_contact_type_id='.$contact_type->key,
			'Edit Contact Type' => '',
		),
		'session' => $session,
	)
	);

	$pageoptions['title'] = 'Edit Contact Type: '.$contact_type->get('ctt_name');
	$page->begin_box($pageoptions);

	// Editing an existing contact_type
	$formwriter = $page->getFormWriter('form1');

	$validation_rules = array();
	echo $formwriter->set_validate($validation_rules);

	echo $formwriter->begin_form('form1', 'POST', '/admin/admin_contact_type_edit');
	if($contact_type->key){
		echo $formwriter->hiddeninput('ctt_contact_type_id', $contact_type->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}

	echo $formwriter->textinput('Name', 'ctt_name', NULL, 100, $contact_type->get('ctt_name'), '', 255, '');
	echo $formwriter->textinput('Description', 'ctt_description', NULL, 100, $contact_type->get('ctt_description'), '', 255, '');
	echo $formwriter->textinput('Mailchimp List ID', 'ctt_mailchimp_list_id', NULL, 100, $contact_type->get('ctt_mailchimp_list_id'), '', 255, '');
	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();

	$page->end_box();

	$page->admin_footer();

?>
