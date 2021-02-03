<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/EmailTemplate.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/emails_class.php');
	
	$session = SessionControl::get_instance();
	//$session->set_return();
	$session->check_permission(8);

	
	$eml_email_id = LibraryFunctions::fetch_variable('eml_email_id', 0, TRUE, 'Email id is required');
	
	$email = new Email($eml_email_id, TRUE);
	$user = new User($email->get('eml_usr_user_id'), TRUE);
		
		
	//TODO NEED TO INTEGRATE THE MAILGUN CLASS WITH THE EMAIL CLASS
	$settings = Globalvars::get_instance();
	$email_outer_template = $settings->get_setting('bulk_outer_template');
	$email_footer_template = $settings->get_setting('bulk_footer');

	$email_template = new EmailTemplate($email->get('eml_message_template_html'), $user, $email_outer_template, $email_footer_template);		
	$email_template->fill_template(array(
			'subject' => 'TEST: '.$email->get('eml_subject'),
			'preview_text' => $email->get('eml_preview_text'),
			'body' => $email->get('eml_message_html'),
	));
	$email_template->email_subject = 'TEST: '.$email->get('eml_subject');
	$email_template->email_from = $email->get('eml_from_address');
	$email_template->email_from_name = $email->get('eml_from_name');
	
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 11,
		'breadcrumbs' => array(
			'Emails'=>'/admin/admin_emails',
			'Email test' => ''
		),
		'session' => $session,
	)
	);		
	
	$pageoptions['title'] = "Send Test Email";
	$page->begin_box($pageoptions);	
	
	if($_GET['sendtest']){
		echo '<p><b>Sending test email to '.$user->display_name().'</b></p>';
		$result = $email_template->send(FALSE);

		if($result){
			echo '<p><b>Send succeeded.</b></p>';
		}
		else{
			 echo '<p><b>Send failed.</b></p>';
		}
	} 
	else{
		if($email_template->email_html){
			echo $email_template->email_html;
		}
		else{
			echo $email->get('eml_message_html');
		}
	}
	

	echo '<a href="/admin/admin_email?eml_email_id='.$email->key.'"><< back</a><br>';
	
	$page->end_box();
			
$page->admin_footer();	
	
	
?>