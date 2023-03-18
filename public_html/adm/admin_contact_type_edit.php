<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/contact_types_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/contact_types_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	if (isset($_REQUEST['ctt_contact_type_id'])) {
		$contact_type = new ContactType($_REQUEST['ctt_contact_type_id'], TRUE);
	} else {
		$contact_type = new ContactType(NULL);
	}

	if($_POST){

		$editable_fields = array('ctt_description', 'ctt_mailchimp_list_id');

		foreach($editable_fields as $field) {
			$contact_type->set($field, $_POST[$field]);
		}
		
		$contact_type->prepare();
		$contact_type->save();
		$contact_type->load();
		
		LibraryFunctions::redirect('/admin/admin_contact_type?ctt_contact_type_id='.$contact_type->key);
		exit;
	}

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 11,
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
	$formwriter = new FormWriterMaster('form1');
	
	$validation_rules = array();
	echo $formwriter->set_validate($validation_rules);		
	
	echo $formwriter->begin_form('form1', 'POST', '/admin/admin_contact_type_edit');
	if($contact_type->key){
		echo $formwriter->hiddeninput('ctt_contact_type_id', $contact_type->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}
	

	echo $formwriter->textinput('Description', 'ctt_description', NULL, 100, $contact_type->get('ctt_description'), '', 255, '');
	echo $formwriter->textinput('Mailchimp List ID', 'ctt_mailchimp_list_id', NULL, 100, $contact_type->get('ctt_mailchimp_list_id'), '', 255, '');	
	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();

	$page->end_box();

	

	$page->admin_footer();

?>
