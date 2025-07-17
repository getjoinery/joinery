<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('includes/AdminPage.php');
	
	PathHelper::requireOnce('includes/LibraryFunctions.php');

	PathHelper::requireOnce('data/emails_class.php');
	PathHelper::requireOnce('data/mailing_lists_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	if (isset($_REQUEST['eml_email_id'])) {
		$email = new Email($_REQUEST['eml_email_id'], TRUE);
	} else {
		$email = new Email(NULL);
	}

	if($_POST){
		
		if($_POST['eml_mlt_mailing_list_id'] == ''){
			$_POST['eml_mlt_mailing_list_id'] = NULL;
		}

		$editable_fields = array('eml_description', 'eml_subject', 'eml_from_address', 'eml_from_name', 'eml_message_html', 'eml_preview_text', 'eml_ctt_contact_type_id', 'eml_mlt_mailing_list_id');

		foreach($editable_fields as $field) {
			$email->set($field, $_POST[$field]);
		}
		
		if(!$email->key){
			$email->set('eml_usr_user_id',$session->get_user_id());
			$email->set('eml_status', Email::EMAIL_CREATED);
		}	
		
		$email->set('eml_reply_to',$_POST['eml_from_address']);
		$email->set('eml_message_template_html', $_POST['eml_message_template_html']);
		$email->set('eml_type', Email::TYPE_MARKETING);		
		
		$email->prepare();
		$email->save();
		$email->load();
		
		LibraryFunctions::redirect('/admin/admin_email?eml_email_id='.$email->key);
		exit;
	}

	$settings = Globalvars::get_instance();
	$sitename = $settings->get_setting('site_name');
	$defaultemailname = $settings->get_setting('defaultemailname');
	$defaultemail = $settings->get_setting('defaultemail');

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'emails-list',
		'breadcrumbs' => array(
			'Emails'=>'/admin/admin_emails', 
			'New Email' => '',
		),
		'session' => $session,
	)
	);	
	
	
	$pageoptions['title'] = "New Email";
	$page->begin_box($pageoptions);

	// Editing an existing email
	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	
	$validation_rules = array();
	$validation_rules['eml_description']['required']['value'] = 'true';
	$validation_rules['eml_description']['minlength']['value'] = 10;
	$validation_rules['eml_subject']['required']['value'] = 'true';
	$validation_rules['eml_subject']['minlength']['value'] = 10;
	$validation_rules['eml_ctt_contact_type_id']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);		
	
	echo $formwriter->begin_form('form1', 'POST', '/admin/admin_email_edit');
	if($email->key){
		echo $formwriter->hiddeninput('eml_email_id', $email->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}

	echo $formwriter->textinput('Description', 'eml_description', NULL, 100, $email->get('eml_description'), '', 255, '');
	echo $formwriter->textinput('Subject', 'eml_subject', NULL, 100, $email->get('eml_subject'), '', 255, '');	
	
	
	$contact_types = new MultiContactType(
		array('deleted'=>false),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$contact_types->load();
	$optionvals = $contact_types->get_dropdown_array();
	if($contact_types->count()){
		echo $formwriter->dropinput("Email content type (for unsubscribes)", "eml_ctt_contact_type_id", "ctrlHolder", $optionvals, $email->get('eml_ctt_contact_type_id'), '', TRUE);	
	}
	

	$mailing_lists = new MultiMailingList(
		array('deleted'=>false, 'active'=> true),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$mailing_lists->load();
	$optionvals = $mailing_lists->get_dropdown_array();
	$optionvals['Custom'] = NULL;
	echo $formwriter->dropinput("Mailing list or custom list", "eml_mlt_mailing_list_id", "ctrlHolder", $optionvals, $email->get('eml_mlt_mailing_list_id'), '', FALSE);
	
	echo $formwriter->textinput('Preview text', 'eml_preview_text', NULL, 100, $email->get('eml_preview_text'), '', 255, '');
	
	$optionvals = array($defaultemail=>$defaultemail);
	echo $formwriter->dropinput("From Address", "eml_from_address", "ctrlHolder", $optionvals, $email->get('eml_from_address'), '', FALSE);
	
	$optionvals = array($defaultemailname=>$defaultemailname);
	echo $formwriter->dropinput("From Name", "eml_from_name", "ctrlHolder", $optionvals, $email->get('eml_from_name'), '', FALSE);	
	
	$optionvals = array("Blank HTML Template"=>"blank_template", "Standard HTML Template"=>"newsletter-1");
	echo $formwriter->dropinput("Template", "eml_message_template_html", "ctrlHolder", $optionvals, $email->get('eml_message_template_html'), '', FALSE);		
	
	


	echo $formwriter->textbox('Email Body', 'eml_message_html', 'ctrlHolder', 5, 80, $email->get('eml_message_html'), '', 'yes');


	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();

	$page->end_box();

	

	$page->admin_footer();

?>
