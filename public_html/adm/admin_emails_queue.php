<?php
	
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('data/emails_class.php'));
	
	$session = SessionControl::get_instance();
	//$session->set_return();
	$session->check_permission(8);
	
	$eml_email_id = LibraryFunctions::fetch_variable('eml_email_id', 0, TRUE, 'Email id is required');
	
	$email = new Email($eml_email_id, TRUE);
	if($email->get('eml_delete_time')){
		throw new SystemDisplayableError('This email is deleted.');
		exit();		
	}
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'emails-list',
		'breadcrumbs' => array(
			'Emails'=>'/admin/admin_emails', 
			$email->get('eml_subject') => '',
		),
		'session' => $session,
	)
	);		
	
	$pageoptions['title'] = "New Email";
	$page->begin_box($pageoptions);

	// The audience expands onto the email and the scheduled sender takes it
	// from here. A missing mail service is the sender's to report.
	$total_num_queued = $email->queue();

	if($total_num_queued > 0){
		echo '<p>Your email was successfully queued to '.$total_num_queued.' recipients.  <a href="/admin/admin_emails">Return to the email page</a>';
	}
	else{
		echo '<p>Your email was NOT queued.  There were no recipients.  <a href="/admin/admin_emails">Return to the email page</a>';
	}

$page->end_box();
$page->admin_footer();
exit();		

?>
