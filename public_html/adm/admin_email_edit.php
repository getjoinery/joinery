<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/emails_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	if (isset($_REQUEST['eml_email_id'])) {
		$email = new Email($_REQUEST['eml_email_id'], TRUE);
	} else {
		$email = new Email(NULL);
	}

	if($_POST){

		$editable_fields = array('eml_description', 'eml_subject', 'eml_from_address', 'eml_from_name', 'eml_message_html', 'eml_preview_text');

		foreach($editable_fields as $field) {
			$email->set($field, $_REQUEST[$field]);
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
		'menu-id'=> 11,
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
	$formwriter = new FormWriterMaster('form1');
	
	$validation_rules = array();
	$validation_rules['eml_description']['required']['value'] = 'true';
	$validation_rules['eml_description']['minlength']['value'] = 10;
	$validation_rules['eml_subject']['required']['value'] = 'true';
	$validation_rules['eml_subject']['minlength']['value'] = 10;
	echo $formwriter->set_validate($validation_rules);		
	
	echo $formwriter->begin_form('form1', 'POST', '/admin/admin_email_edit');
	if($email->key){
		echo $formwriter->hiddeninput('eml_email_id', $email->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}

	echo $formwriter->textinput('Description', 'eml_description', NULL, 100, $email->get('eml_description'), '', 255, '');
	echo $formwriter->textinput('Subject', 'eml_subject', NULL, 100, $email->get('eml_subject'), '', 255, '');	
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
