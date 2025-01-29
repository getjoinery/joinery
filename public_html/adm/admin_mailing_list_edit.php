<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/mailing_lists_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/email_templates_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	if (isset($_REQUEST['mlt_mailing_list_id'])) {
		$mailing_list = new MailingList($_REQUEST['mlt_mailing_list_id'], TRUE);
	} else {
		$mailing_list = new MailingList(NULL);
	}

	if($_POST){

		$editable_fields = array('mlt_name', 'mlt_description', 'mlt_is_active', 'mlt_visibility');

		if($mailing_list->get('mlt_fil_file_id')){
			$mailing_list->set('mlt_fil_file_id', $_POST['mlt_fil_file_id']);
		}

		if($mailing_list->get('mlt_emt_email_template_id')){
			$mailing_list->set('mlt_emt_email_template_id', $_POST['mlt_emt_email_template_id']);
		}

		if($mailing_list->get('mlt_mailchimp_list_id')){
			$mailing_list->set('mlt_mailchimp_list_id', $_POST['mlt_mailchimp_list_id']);
		}
		
		if($mailing_list->get('mlt_ctt_contact_type_id')){
			$mailing_list->set('mlt_ctt_contact_type_id', $_POST['mlt_ctt_contact_type_id']);
		}
	
		foreach($editable_fields as $field) {
			$mailing_list->set($field, $_POST[$field]);
		}

		if(!$mailing_list->get('mlt_link') || $_SESSION['permission'] == 10){
			if($_POST['mlt_link']){
				$mailing_list->set('mlt_link', $mailing_list->create_url($_POST['mlt_link']));
			}
			else{
				$mailing_list->set('mlt_link', $mailing_list->create_url($mailing_list->get('mlt_name'))); 
			}
		}
		
		
		$mailing_list->prepare();
		$mailing_list->save();
		$mailing_list->load();
		
		LibraryFunctions::redirect('/admin/admin_mailing_list?mlt_mailing_list_id='.$mailing_list->key);
		exit;
	}

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
	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	
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
