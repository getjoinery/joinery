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
	$formwriter = $page->getFormWriter('form1', 'v2', [
		'model' => $contact_type,
		'edit_primary_key_value' => $contact_type->key
	]);

	echo $formwriter->begin_form();

	$formwriter->textinput('ctt_name', 'Name');
	$formwriter->textinput('ctt_description', 'Description');
	$formwriter->textinput('ctt_mailchimp_list_id', 'Mailchimp List ID');
	$formwriter->submitbutton('btn_submit', 'Submit');
	echo $formwriter->end_form();

	$page->end_box();

	$page->admin_footer();

?>
