<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');


	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/emails_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/email_recipient_groups_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/groups_class.php'); 
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/mailing_lists_class.php');


	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$email = new Email($_REQUEST['eml_email_id'], TRUE);

	

	if($_REQUEST['action'] == 'delete'){
		$email->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		//REMOVE THE RECIPIENTS
		EmailRecipient::DeleteAll($email->key);
		$email->soft_delete();

		header("Location: /admin/admin_emails");
		exit();				
	}
	else if($_REQUEST['action'] == 'undelete'){
		$email->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$email->undelete();

		header("Location: /admin/admin_emails");
		exit();				
	}
	else if($_REQUEST['action'] == 'unqueue'){
		$email->set('eml_status', Email::EMAIL_CREATED);
		$email->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$email->save();
		//REMOVE THE RECIPIENTS
		EmailRecipient::DeleteAll($email->key);

		header("Location: /admin/admin_emails");
		exit();				
	}
 
	if($_REQUEST['action'] == 'addgroup'){
		//ADD GROUP TO EMAIL
		$email->add_recipient_group(NULL, $_POST['grp_group_id']);
		$returnurl = $session->get_return();
		header("Location: $returnurl");
		exit();			
	}
	else if($_REQUEST['action'] == 'remove'){
		$email_recipient_group = new EmailRecipientGroup($_POST['erg_email_recipient_group_id'], TRUE);
		$email_recipient_group->permanent_delete();
		$returnurl = $session->get_return();		
		header("Location: $returnurl");
		exit();				
	}
	else if($_REQUEST['action'] == 'addevent'){
		//ADD GROUP TO EMAIL
		$email->add_recipient_group($_POST['evt_event_id'], NULL);
		$returnurl = $session->get_return();
		header("Location: $returnurl");
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



	if($email->get('eml_status') != Email::EMAIL_SENT && $email->get('eml_status') != Email::EMAIL_QUEUED){

		$pageoptions['title'] = 'Email: '.$email->get('eml_subject');
		$altlinks = array(
		'Edit'=>'/admin/admin_email_edit?eml_email_id='.$email->key,		
		'Send test'=> '/admin/admin_emails_send?send_test=1&eml_email_id='.$email->key);
		
		if($email->get('eml_status') >= 3){
			$altlinks['Add to Send Queue'] = '/admin/admin_emails_queue?eml_email_id='.$email->key;
		}
		
		if(!$email->get('eml_delete_time') && $_SESSION['permission'] >= 8) {
			$altlinks['Soft Delete'] = '/admin/admin_email?action=delete&eml_email_id='.$email->key;
		}
		$pageoptions['altlinks'] = $altlinks;
		$page->begin_box($pageoptions);
		echo '<b>'.$email->get_status_text().'</b><br /><br />';
		echo '<iframe src="/ajax/email_preview_ajax?eml_email_id='.$email->key.'" width="100%" height="300" style="border:1px solid gray;"></iframe>';			 
		$page->end_box(); 

		$headers = array("Recipients", "Count", "Action");



		
		if($email->get('eml_mlt_mailing_list_id')){
			//IF IT IS A MAILING LIST EMAIL.  DO NOT ALLOW CHANGING OF RECIPIENTS (BECAUSE OF UNSUBSCRIBE)
			$mailing_list = new MailingList($email->get('eml_mlt_mailing_list_id'), TRUE);
			
			$altlinks = array();
			 $box_vars =	array(
				'altlinks' => $altlinks,
				'title' => 'Recipients for "'. $email->get('eml_description'). '"'
			);
			$page->tableheader($headers, $box_vars);

			$rowvalues=array();
			array_push($rowvalues, $mailing_list->get('mlt_name'));
			array_push($rowvalues, $mailing_list->count_subscribed_users(). ' users');
			array_push($rowvalues, '');
			$page->disprow($rowvalues);

			$page->endtable(); 
			
		
		}
		else{
			$altlinks = array('Add Groups'=>'/admin/admin_email_recipients_modify?eml_email_id='.$email->key,
			'Exclude Groups'=>'/admin/admin_email_recipients_modify?op=remove&eml_email_id='.$email->key);
			 $box_vars =	array(
				'altlinks' => $altlinks,
				'title' => 'Recipients for "'. $email->get('eml_description'). '"'
			);
			$page->tableheader($headers, $box_vars);		
			$recipient_groups = $email->get_recipient_groups(); 
			$total = 0;
			$total_unsubscribed = 0;
			$total_duplicates = 0;
			$recipient_list = array();
			foreach($recipient_groups as $recipient_group){
				
				$group_total = 0;
				$group_unsubscribed = 0;
				$rowvalues=array();

				$add_user_list = array();
				if($recipient_group->get('erg_grp_group_id')){
					$group = new Group($recipient_group->get('erg_grp_group_id'), TRUE);
					$members = $group->get_member_list();
					foreach($members as $member){
						$add_user_list[] = $member->get('grm_foreign_key_id');
					}
					$label = $group->get('grp_name');
				}
				else if($recipient_group->get('erg_evt_event_id')){
					$event = new Event($recipient_group->get('erg_evt_event_id'), TRUE);
					$event_registrants = new MultiEventRegistrant(array('event_id' => $recipient_group->get('erg_evt_event_id'), 'expired' => false), NULL);
					//$numregistrants = $event_registrants->count_all();
					$event_registrants->load();
					foreach($event_registrants as $event_registrant){
						$add_user_list[] = $event_registrant->get('evr_usr_user_id');
					}
					$label = $event->get('evt_name');
				}
				
				$num_total = 0;
				foreach($add_user_list as $user_id){
					$user= new User($user_id, TRUE);
					if(!$user->is_unsubscribed_to_contact_type($email->get('eml_ctt_contact_type_id'))){
						$group_total++;
						$recipient_list[] = $user->key;
					}
					else{
						$group_unsubscribed++;
					}
					$num_total++;
				}
				
				if($recipient_group->get('erg_operation') == 'add'){
					array_push($rowvalues, 'Add: '. $label);
					//array_push($rowvalues, 'Users subscribed: '.$group_total . ', unsubscribed: '.$group_unsubscribed);
					array_push($rowvalues, 'Users: '.$num_total);
				}
				else{
					array_push($rowvalues, 'Excluded: '. $label);
					array_push($rowvalues, 'Users to exclude: '. $num_total);
				}
				

				$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_email?eml_email_id='.$email->key.'">
				<input type="hidden" class="hidden" name="action" id="action" value="remove" />
				<input type="hidden" class="hidden" name="erg_email_recipient_group_id" id="erg_email_recipient_group_id" value="'.$recipient_group->key.'" />
				<button type="submit">Delete</button>
				</form>';	
				array_push($rowvalues, $delform);			
				
				$page->disprow($rowvalues);

			}
			$page->endtable();
		}

		

				
	}
	else{
		$numperpage = 50;
		$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
		$sort = LibraryFunctions::fetch_variable('sort', 'email_id', 0, '');	
		$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');


		$search_criteria = array('email_id' => $email->key);

		$recipients = new MultiEmailRecipient(
			$search_criteria,
			array($sort=>$sdirection),
			$numperpage,
			$offset);
		$numrecords = $recipients->count_all();
		$recipients->load();

		if($email->get('eml_status') == Email::EMAIL_QUEUED){	
			$pageoptions['altlinks'] = array(
				'Remove From Queue'=>'/admin/admin_email?action=unqueue&eml_email_id='.$email->key
				);
		}
			 
		
		$pageoptions['title'] = 'Email: '.$email->get('eml_subject');
		$page->begin_box($pageoptions);
		$time= '';
		if($email->get('eml_delete_time')){
			echo 'Status: Deleted at '.LibraryFunctions::convert_time($email->get('eml_delete_time'), 'UTC', $session->get_timezone()).'<br />';
		}
		if($email->get('eml_status') == 10){
			$time = 'Sent: '. LibraryFunctions::convert_time($email->get('eml_sent_time'), "UTC", $session->get_timezone());
		}
		else if($email->get('eml_status') == 5){
			$time = 'Scheduled: '. LibraryFunctions::convert_time($email->get('eml_scheduled_time'), "UTC", $session->get_timezone());	
		}
		echo '<b>'.$time.'</b><br /><br />';
		echo '<iframe src="/ajax/email_preview_ajax?eml_email_id='.$email->key.'" width="100%" height="300" style="border:1px solid gray;"></iframe>';			 
		$page->end_box(); 
		
		$headers = array("Recipient", "Recipient Email", "Status");
		
		$altlinks = array();

		$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
		$table_options = array(
			//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
			'altlinks' => $altlinks,
			'title' => 'Email recipients',
			//'search_on' => TRUE
		);
		$page->tableheader($headers, $table_options, $pager);
		
		foreach($recipients as $recipient){
			$recipient_user = new User($recipient->get('erc_usr_user_id'), TRUE);
			
			$rowvalues=array();
			array_push($rowvalues, '<a href="/admin/user?usr_user_id='.$recipient_user->key.'">'.$recipient->get('erc_name').'</a>');
			array_push($rowvalues, $recipient->get('erc_email'));
			$status = 'Queued';
			if($recipient->get('erc_status') == EmailRecipient::EMAIL_SENT){
				$status = 'Sent';
			}
			else if($recipient->get('erc_status') == EmailRecipient::SEND_FAILURE){
				$status = 'Send Failed';
			}
			array_push($rowvalues, $status);
			$page->disprow($rowvalues);
		}
		
			
		$page->endtable($pager);
		
	}

	$page->admin_footer();

?>
