<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('adm/logic/admin_mailing_list_edit_logic.php'));

	$page_vars = process_logic(admin_mailing_list_edit_logic($_GET, $_POST));
	extract($page_vars);

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'mailing-lists',
		'breadcrumbs' => array(
			'Emails'=>'/admin/admin_emails',
			'Mailing Lists'=>'/admin/admin_mailing_lists',
			'Mailing List: '.$mailing_list->get('mlt_name')=>'/admin/admin_mailing_list?mlt_mailing_list_id='.$mailing_list->key,
			'Edit Mailing List' => '',
		),
		'session' => $session,
	)
	);

	$pageoptions['title'] = 'Edit Mailing List: '.$mailing_list->get('mlt_name');
	$page->begin_box($pageoptions);

	// Load related data
	$contact_types = new MultiContactType(
		array('deleted'=>false),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$contact_types->load();

	$templates = new MultiEmailTemplateStore(
		array('template_type' => EmailTemplateStore::TEMPLATE_TYPE_INNER),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$templates->load();

	$files = new MultiFile(
			array('deleted'=>false),
			array('file_id' => 'DESC'),		//SORT BY => DIRECTION
			NULL,  //NUM PER PAGE
			NULL);  //OFFSET
	$files->load();

	// Editing an existing mailing_list
	$formwriter = $page->getFormWriter('form1', [
		'model' => $mailing_list,
		'edit_primary_key_value' => $mailing_list->key
	]);

	$formwriter->begin_form();

	$formwriter->textinput('mlt_name', 'Name', [
		'validation' => ['required' => true]
	]);

	$formwriter->textinput('mlt_description', 'Description');

	$formwriter->dropinput('mlt_is_active', 'Active?', [
		'options' => [0 => 'Disabled', 1 => 'Active']
	]);

	$formwriter->dropinput('mlt_visibility', 'Visibility', [
		'options' => [
			0 => 'Hidden (Only admins can add people)',
			1 => 'Public (Open for registration and listed)',
			2 => 'Public but unlisted (Can only register with the link)'
		]
	]);

	if($contact_types->count()){
		$optionvals = $contact_types->get_dropdown_array();
		$formwriter->dropinput('mlt_ctt_contact_type_id', 'Email content type (for unsubscribes)', [
			'options' => $optionvals
		]);
	}

	$optionvals = $templates->get_dropdown_array();
	$formwriter->dropinput('mlt_emt_email_template_id', 'Welcome email template', [
		'options' => $optionvals,
		'empty_option' => 'No welcome email'
	]);

	$optionvals = $files->get_file_dropdown_array();
	$formwriter->dropinput('mlt_fil_file_id', 'File to include in welcome email', [
		'options' => $optionvals,
		'empty_option' => 'No file included'
	]);

	$formwriter->textinput('mlt_mailchimp_list_id', 'Mailchimp List ID');

	$formwriter->submitbutton('btn_submit', 'Submit');

	$formwriter->end_form();

	$page->end_box();

	$page->admin_footer();

?>
