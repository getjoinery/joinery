<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/EmailTemplate.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/emails_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/email_recipients_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/groups_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/group_members_class.php');
	
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
		'menu-id'=> 11,
		'breadcrumbs' => array(
			'Emails'=>'/admin/admin_emails', 
			$email->get('eml_subject') => '',
		),
		'session' => $session,
	)
	);		
	
	$pageoptions['title'] = "New Email";
	$page->begin_box($pageoptions);
	
	//WRITE THE RECIPIENTS TO ERC_EMAIL_RECIPIENTS
	//REMOVE DUPLICATES
	$recipient_groups = $email->get_recipient_groups();
	
	$has_recipients = FALSE;

	$queued_recipients = array();
	foreach($recipient_groups as $recipient_group){

		$group = new Group($recipient_group->erg_grp_group_id, TRUE);
		$members = $group->get_member_list();
			
		foreach($members as $member){
			$user= new User($member->get('grm_foreign_key_id'), TRUE);
			
			if($user->get('usr_contact_preferences') == 0 && $email->get('eml_type') == Email::TYPE_MARKETING){
				echo '<font color="red">Unsubscribed: '.$user->display_name() .'</font><br>';
			}
			else{
				if(in_array($user->key, $queued_recipients)){
					echo 'Duplicate: '.$user->display_name() .'<br>';
				}
				else{
					echo 'Recipient: '.$user->display_name() .'<br>';
					$queued_recipients[] = $user->key;
					$has_recipients = TRUE;
					
					$recipient = new EmailRecipient(NULL);
					$recipient->set('erc_email', $user->get('usr_email'));
					$recipient->set('erc_usr_user_id', $user->key);
					$recipient->set('erc_name', $user->display_name());
					$recipient->set('erc_eml_email_id', $email->key);
					$recipient->prepare();
					$recipient->save();					
				}
			}
		}
	}
	
	if($has_recipients){
		$email->set('eml_scheduled_time', 'now()');
		$email->set('eml_status', Email::EMAIL_QUEUED);
		$email->save();
		echo '<p>Your email was successfully queued to '.count($queued_recipients).' recipients.  <a href="/admin/admin_emails">Return to the emails page</a>';
	}
	else{
		echo '<p>Your email was NOT queued.  There were no recipients.  <a href="/admin/admin_emails">Return to the emails page</a>';
	}

$page->end_box();
$page->admin_footer();
exit();		
	
	
?>