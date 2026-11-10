<?php
/** @joinery-admin class=personal */
	
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	
	$session = SessionControl::get_instance();
	//$session->set_return();
	// Queueing is a button (POST): a link is a GET any other site can make the browser follow.
	if (!LibraryFunctions::isFormSubmission()) {
		throw new SystemDisplayableError('Queue an email with the Add to Send Queue button on its page.');
	}
	
	$eml_email_id = LibraryFunctions::fetch_variable('eml_email_id', 0, TRUE, 'Email id is required');
	
	$email = new Email($eml_email_id, TRUE);
	$email->assert_found();
	if($email->get('eml_delete_time')){
		throw new SystemDisplayableError('This email is deleted.');
		exit();		
	}
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'emails-list',
		'breadcrumbs' => array(
			'Emails'=>'/plugins/email_lists/admin/admin_emails', 
			$email->get('eml_subject') => '',
		),
		'session' => $session,
	)
	);		
	
	$pageoptions['title'] = "New Email";
	$page->begin_box($pageoptions);

	// The audience expands onto the email and the scheduled sender takes it
	// from here. A missing mail service is the sender's to report; included
	// email refuses a campaign outright (CampaignRules).
	try {
		$total_num_queued = $email->queue();
	} catch (CampaignRefusedException $e) {
		$total_num_queued = null;
		echo CampaignRules::banner();
		echo '<p>Your email was NOT queued. <a href="/plugins/email_lists/admin/admin_email?eml_email_id=' . (int)$email->key . '">Return to the email</a></p>';
	}

	if($total_num_queued === null){
		// Said above.
	}
	else if($total_num_queued > 0){
		echo '<p>Your email was successfully queued to '.$total_num_queued.' recipients.  <a href="/plugins/email_lists/admin/admin_emails">Return to the email page</a>';
	}
	else{
		echo '<p>Your email was NOT queued.  There were no recipients.  <a href="/plugins/email_lists/admin/admin_emails">Return to the email page</a>';
	}

$page->end_box();
$page->admin_footer();
exit();		

?>
