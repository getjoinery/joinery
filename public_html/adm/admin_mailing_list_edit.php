<?php

	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));
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

	// Editing an existing mailing_list
	$formwriter = $page->getFormWriter('form1');

	$validation_rules = array();
	$validation_rules['mlt_name']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);

	echo $formwriter->begin_form('form1', 'POST', '/admin/admin_mailing_list_edit');
	if($mailing_list->key){
		echo $formwriter->hiddeninput('mlt_mailing_list_id', $mailing_list->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}

	echo $formwriter->textinput('Name', 'mlt_name', NULL, 100, $mailing_list->get('mlt_name'), '', 255, '');
	echo $formwriter->textinput('Description', 'mlt_description', NULL, 100, $mailing_list->get('mlt_description'), '', 255, '');
	$optionvals = array("Active"=>1, "Disabled"=>0 );
	echo $formwriter->dropinput("Active?", "mlt_is_active", "ctrlHolder", $optionvals, $mailing_list->get('mlt_is_active'), '', FALSE);
	$optionvals = array("Hidden (Only admins can add people)"=>0, "Public (Open for registration and listed)"=>1, "Public but unlisted (Can only register with the link)"=>2);
	echo $formwriter->dropinput("Visibility", "mlt_visibility", "ctrlHolder", $optionvals, $mailing_list->get('mlt_visibility'), '', FALSE);

	$contact_types = new MultiContactType(
		array('deleted'=>false),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$contact_types->load();
	$optionvals = $contact_types->get_dropdown_array();
	if($contact_types->count()){
		echo $formwriter->dropinput("Email content type (for unsubscribes)", "mlt_ctt_contact_type_id", "ctrlHolder", $optionvals, $mailing_list->get('mlt_ctt_contact_type_id'), '', TRUE);
	}

	$templates = new MultiEmailTemplateStore(
		array('template_type' => EmailTemplateStore::TEMPLATE_TYPE_INNER),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$templates->load();
	$optionvals = $templates->get_dropdown_array();
	echo $formwriter->dropinput("Welcome email template", "mlt_emt_email_template_id", "ctrlHolder", $optionvals, $mailing_list->get('mlt_emt_email_template_id'), '', "No welcome email");

	$files = new MultiFile(
			array('deleted'=>false),
			array('file_id' => 'DESC'),		//SORT BY => DIRECTION
			NULL,  //NUM PER PAGE
			NULL);  //OFFSET
	$files->load();
	$optionvals = $files->get_file_dropdown_array();
	echo $formwriter->dropinput("File to include in welcome email", "mlt_fil_file_id", "ctrlHolder", $optionvals, $mailing_list->get('mlt_fil_file_id'), '', "No file included", TRUE, FALSE, TRUE);
	echo $formwriter->textinput('Mailchimp List ID', 'mlt_mailchimp_list_id', NULL, 100, $mailing_list->get('mlt_mailchimp_list_id'), '', 255, '');
	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();

	$page->end_box();

	$page->admin_footer();

?>
