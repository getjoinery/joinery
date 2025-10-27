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
$formwriter = $page->getFormWriter('form1', 'v2', [
	'model' => $email
]);

$formwriter->begin_form();
if($email->key){
	$formwriter->hiddeninput('eml_email_id', ['value' => $email->key]);
	$formwriter->hiddeninput('action', ['value' => 'edit']);
}

$formwriter->textinput('eml_description', 'Description', [
	'validation' => ['required' => true, 'minlength' => 10]
]);
$formwriter->textinput('eml_subject', 'Subject', [
	'validation' => ['required' => true, 'minlength' => 10]
]);

$optionvals = $contact_types->get_dropdown_array();
if($contact_types->count()){
	$formwriter->dropinput("eml_ctt_contact_type_id", "Email content type (for unsubscribes)", [
		'options' => $optionvals,
		'validation' => ['required' => true]
	]);
}

$optionvals = $mailing_lists->get_dropdown_array();
$optionvals['Custom'] = NULL;
$formwriter->dropinput("eml_mlt_mailing_list_id", "Mailing list or custom list", [
	'options' => $optionvals
]);

$formwriter->textinput('eml_preview_text', 'Preview text');

$optionvals = array($defaultemail=>$defaultemail);
$formwriter->dropinput("eml_from_address", "From Address", [
	'options' => $optionvals
]);

$optionvals = array($defaultemailname=>$defaultemailname);
$formwriter->dropinput("eml_from_name", "From Name", [
	'options' => $optionvals
]);

$optionvals = array("Blank HTML Template"=>"blank_template", "Standard HTML Template"=>"newsletter-1");
$formwriter->dropinput("eml_message_template_html", "Template", [
	'options' => $optionvals
]);

$formwriter->textbox('eml_message_html', 'Email Body', [
	'htmlmode' => 'yes'
]);

$formwriter->submitbutton('btn_submit', 'Submit');
$formwriter->end_form();

$page->end_box();

$page->admin_footer();

?>
