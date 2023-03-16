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
	
	//GET THE RECIPIENTS
	$recipient_groups = $email->get_recipient_groups('add');

	//ADD THE *ADD* LISTS TOGETHER
	$queued_recipients = array();
	foreach($recipient_groups as $recipient_group){
		
		if($recipient_group->get('erg_grp_group_id')){
			$group = new Group($recipient_group->get('erg_grp_group_id'), TRUE);
			$members = $group->get_member_list();
			foreach($members as $member){
				$user= new User($member->get('grm_foreign_key_id'), TRUE);
				$queued_recipients[] = $user->key;
			}
		}
		else{
			$event_registrants = new MultiEventRegistrant(array('event_id' => $recipient_group->get('erg_evt_event_id')), NULL);
			//$numregistrants = $event_registrants->count_all();
			$event_registrants->load();
			foreach($event_registrants as $event_registrant){
				$queued_recipients[] = $event_registrant->get('evr_usr_user_id');
			}			
		}
			
	}
	
	
	//NOW REMOVE THE RECIPIENTS WHO NEED TO BE REMOVED
	$recipient_groups = $email->get_recipient_groups('remove');
	
	$removal_list = array();
	foreach($recipient_groups as $recipient_group){

		if($recipient_group->get('erg_grp_group_id')){
			$group = new Group($recipient_group->get('erg_grp_group_id'), TRUE);
			$members = $group->get_member_list();
			foreach($members as $member){
				$user= new User($member->get('grm_foreign_key_id'), TRUE);
				$removal_list[] = $user->key;
			}
		}
		else{
			$event_registrants = new MultiEventRegistrant(array('event_id' => $recipient_group->get('erg_evt_event_id')), NULL);
			//$numregistrants = $event_registrants->count_all();
			$event_registrants->load();
			foreach($event_registrants as $event_registrant){
				$removal_list[] = $event_registrant->get('evr_usr_user_id');
			}			
		}
			
	}	
	
	//REMOVE DUPLICATES
	$queued_recipients = array_unique($queued_recipients);
	$removal_list = array_unique($removal_list);
	
	//SUBTRACT THE REMOVAL LIST AND REMOVE DUPLICATES
	$final_recipients = array_diff($queued_recipients, $removal_list);

	
	//LOAD THE RECIPIENTS INTO THE QUEUE
	$total_num_queued = 0;
	foreach($final_recipients as $final_recipient){
		$user= new User($final_recipient, TRUE);
		
		//DON'T LOAD UNSUBSCRIBED USERS
		if($user->get('usr_contact_preferences') == 0 && $email->get('eml_type') == Email::TYPE_MARKETING){
			echo '<font color="red">Unsubscribed: '.$user->display_name() .'</font><br>';
		}
		else{	
				
			if (!EmailRecipient::CheckIfExists($email->key, $user->get('usr_email'))){
				$recipient = new EmailRecipient(NULL);
				$recipient->set('erc_email', $user->get('usr_email'));
				$recipient->set('erc_usr_user_id', $user->key);
				$recipient->set('erc_name', $user->display_name());
				$recipient->set('erc_eml_email_id', $email->key);
				$recipient->prepare();
				$recipient->save();
				echo 'Recipient added: '.$user->display_name() .'<br>';	
				$total_num_queued++;
			}
			else{
				echo 'Recipient already added, skipping: '.$user->display_name() .'<br>';
				$total_num_queued++;
			}
		}			

	}
	
	//SET EMAIL STATUS TO QUEUED
	if(!empty($final_recipients)){
		$email->set('eml_scheduled_time', 'now()');
		$email->set('eml_status', Email::EMAIL_QUEUED);
		$email->save();
		echo '<p>Your email was successfully queued to '.$total_num_queued.' recipients.  <a href="/admin/admin_emails">Return to the email page</a>';
	}
	else{
		echo '<p>Your email was NOT queued.  There were no recipients.  <a href="/admin/admin_emails">Return to the email page</a>';
	}

$page->end_box();
$page->admin_footer();
exit();		
	
	
?>