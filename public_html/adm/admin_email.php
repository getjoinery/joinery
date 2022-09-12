<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');


	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/emails_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/groups_class.php');


	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();
	
	$email = new Email($_REQUEST['eml_email_id'], TRUE);
	
	$recipient_groups = $email->get_recipient_groups();
	

	if($_REQUEST['action'] == 'delete'){
		$email->authenticate_write($session);
		$email->soft_delete();

		header("Location: /admin/admin_emails");
		exit();				
	}
	else if($_REQUEST['action'] == 'undelete'){
		$email->authenticate_write($session);
		$email->soft_delete();

		header("Location: /admin/admin_emails");
		exit();				
	}
 
	if($_REQUEST['action'] == 'add'){
		//ADD GROUP TO EMAIL
		$email->add_recipient_group(NULL, $_POST['grp_group_id']);
		$returnurl = $session->get_return();
		header("Location: $returnurl");
		exit();			
	}
	else if($_REQUEST['action'] == 'remove'){
		$email->remove_recipient_group($_POST['erg_email_recipient_group_id']);
		$returnurl = $session->get_return();		
		header("Location: $returnurl");
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



	if($email->get('eml_status') != Email::EMAIL_SENT && $email->get('eml_status') != Email::EMAIL_QUEUED){

		$pageoptions['title'] = 'Email: '.$email->get('eml_subject');
		$altlinks = array('View'=>'/admin/admin_emails_test?eml_email_id='.$email->key,
		'Edit'=>'/admin/admin_email_edit?eml_email_id='.$email->key,		
		'Send test'=> '/admin/admin_emails_test?sendtest=1&eml_email_id='.$email->key);
		
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

		$headers = array("Recipient Groups", "Recipients", "Action");


		 $box_vars =	array(
			'altlinks' => '',
			'title' => "Recipients for ". $email->get('eml_description')
		);
		$page->tableheader($headers, $box_vars);
		
		$total = 0;
		$total_unsubscribed = 0;
		$total_duplicates = 0;
		$recipient_list = array();
		foreach($recipient_groups as $recipient_group){
			
			$group_total = 0;
			$group_unsubscribed = 0;
			$rowvalues=array();

			$group = new Group($recipient_group->erg_grp_group_id, TRUE);
			$members = $group->get_member_list();
			
			foreach($members as $member){
				$user= new User($member->get('grm_usr_user_id'), TRUE);
				if($user->get('usr_contact_preferences') != 0){
					$group_total++;
					$recipient_list[] = $user->key;
				}
				else{
					$group_unsubscribed++;
				}
			}
			$total += $nummembers;
			array_push($rowvalues, $group->get('grp_name'));
			array_push($rowvalues, 'Subscribed: '.$group_total . ' Unsubscribed: '.$group_unsubscribed);
			
			

			
			$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_email?eml_email_id='.$email->key.'">
			<input type="hidden" class="hidden" name="action" id="action" value="remove" />
			<input type="hidden" class="hidden" name="erg_email_recipient_group_id" id="erg_email_recipient_group_id" value="'.$recipient_group->erg_email_recipient_group_id.'" />
			<button type="submit">Delete</button>
			</form>';	
			array_push($rowvalues, $delform);			
			
			$page->disprow($rowvalues);
			$total += $group_total;
			$total_unsubscribed += $group_unsubscribed;
			$num_recipients_before_dup = count($recipient_list);
			$recipient_list = array_unique($recipient_list);
			$numrecipients = count($recipient_list);
		}
		
		echo '<tr><td colspan="3">';
		$formwriter = new FormWriterMaster('form3');
		echo $formwriter->begin_form('form3', 'POST', '/admin/admin_email?eml_email_id='. $email->key);

		
		$groups = new MultiGroup(
			array('category'=>'user', 'deleted'=>false),
			NULL,		//SORT BY => DIRECTION
			NULL,  //NUM PER PAGE
			NULL);  //OFFSET
		$groups->load();
		
		$optionvals = $groups->get_dropdown_array();
		echo $formwriter->hiddeninput('action', 'add');
		echo $formwriter->hiddeninput('eml_email_id', $email->key);
		echo $formwriter->dropinput("Add group members", "grp_group_id", "ctrlHolder", $optionvals, NULL, '', TRUE);
		echo $formwriter->new_form_button('Add group');
		

		echo '</td></tr>';
		
		$page->endtable();
		echo $formwriter->end_form();		
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
		//$altlinks = array('View'=>'/admin/admin_emails_test?eml_email_id='.$email->key);
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
