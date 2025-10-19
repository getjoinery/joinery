<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_email_edit_logic.php'));

$page_vars = process_logic(admin_email_edit_logic($_GET, $_POST));
extract($page_vars);

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
$formwriter = $page->getFormWriter('form1');

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

$optionvals = $contact_types->get_dropdown_array();
if($contact_types->count()){
	echo $formwriter->dropinput("Email content type (for unsubscribes)", "eml_ctt_contact_type_id", "ctrlHolder", $optionvals, $email->get('eml_ctt_contact_type_id'), '', TRUE);
}

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
