<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/email_templates_class.php');
	
if ($_POST['confirm']){

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$emt_email_template_id = LibraryFunctions::fetch_variable('emt_email_template_id', NULL, 1, 'You must provide a email_template to delete here.');
	$confirm = LibraryFunctions::fetch_variable('confirm', NULL, 1, 'You must confirm the action.');	
	
	if ($confirm) {
		$email_template = new EmailTemplateStore($emt_email_template_id, TRUE);
		$email_template->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$email_template->permanent_delete();
	}

	//NOW REDIRECT
	$session = SessionControl::get_instance();
	$returnurl = $session->get_return();
	header("Location: $returnurl");
	exit();

}
else{
	$emt_email_template_id = LibraryFunctions::fetch_variable('emt_email_template_id', NULL, 1, 'You must provide a email_template to edit.');

	$email_template = new EmailTemplateStore($emt_email_template_id, TRUE);
	
	$session = SessionControl::get_instance();
	$session->set_return("/admin/admin_email_templates");

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'email-templates',
		'page_title' => 'EmailTemplate',
		'readable_title' => 'Delete EmailTemplate',
		'breadcrumbs' => array(
			'EmailTemplates'=>'/admin/admin_email_templates', 
			'Delete ' . $email_template->get('emt_name') => '',
		),
		'session' => $session,
	)
	);

	
	$pageoptions['title'] = 'Delete Email Template '.$email_template->get('emt_name');
	$page->begin_box($pageoptions);


	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	echo $formwriter->begin_form("form", "post", "/admin/admin_email_template_permanent_delete");

	echo '<fieldset><h4>Confirm Delete</h4>';
		echo '<div class="fields full">';
		echo '<p>WARNING:  This will permanently delete this email_template ('.$email_template->get('emt_name') . ').</p>';

	echo $formwriter->hiddeninput("confirm", 1);
	echo $formwriter->hiddeninput("emt_email_template_id", $emt_email_template_id);

			echo $formwriter->start_buttons();
		echo $formwriter->new_form_button('Submit');
		echo $formwriter->end_buttons();

		echo '</div>';
	echo '</fieldset>';
	echo $formwriter->end_form();

	$page->end_box();

	$page->admin_footer();


}
?>
