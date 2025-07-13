<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');


	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_registrants_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/address_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/log_form_errors_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/emails_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/email_recipients_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_logs_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/orders_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/messages_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_waiting_lists_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$event = new Event($_REQUEST['evt_event_id'], TRUE);

	if($_REQUEST['action'] == 'delete'){
		$event->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$event->soft_delete();

		header("Location: /admin/admin_events");
		exit();				
	}
	else if($_REQUEST['action'] == 'undelete'){
		$event->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$event->undelete();

		header("Location: /admin/admin_events");
		exit();				
	}

	if($_POST['action'] == 'remove_from_event'){

		$eventregistrant = new EventRegistrant($_POST['evr_event_registrant_id'], TRUE);
		$eventregistrant->remove();

		$returnurl = $session->get_return();
		header("Location: $returnurl");
		exit();				
	}

	if($_POST['action'] == 'remove_from_waiting_list'){

		$waiting_list = new WaitingList($_POST['ewl_waiting_list_id'], TRUE);
		$waiting_list->remove();

		$returnurl = $session->get_return();
		header("Location: $returnurl");
		exit();				
	}
/*
	$form_errors = new MultiFormError(
		array('event_id'=>$event->key),
		NULL,
		10,
		0);
	$form_errors->load();

	$phonereveals = new MultiEventLog(
		array('event_id'=>$event->key, 'event' => EventLog::SHOW_PHONE)
		);
	$numphonereveal = $phonereveals->count_all();

	$websiteclick = new MultiEventLog(
		array('event_id'=>$event->key, 'event' => EventLog::WEBSITE_CLICK)
		);
	$numwebsiteclick = $websiteclick->count_all();
	*/


	//REGISTRANTS
	$rnumperpage = 50;
	$roffset = LibraryFunctions::fetch_variable('roffset', 0, 0, '');
	$rsort = LibraryFunctions::fetch_variable('rsort', 'event_registrant_id', 0, '');
	$rsdirection = LibraryFunctions::fetch_variable('rsdirection', 'DESC', 0, '');
	$rsearchterm = LibraryFunctions::fetch_variable('rsearchterm', '', 0, '');
	$rsearch_criteria = array();
	$rsearch_criteria['event_id'] = $event->key;
	
	$event_registrants = new MultiEventRegistrant(
		$rsearch_criteria,
		array($rsort=>$rsdirection),
		$rnumperpage,
		$roffset
		);
	$numregistrants = $event_registrants->count_all();
	$event_registrants->load();
	
	$rpager = new Pager(array('numrecords'=>$numregistrants, 'numperpage'=> $rnumperpage), 'r');

	//WAITING LIST
	$wnumperpage = 20;
	$woffset = LibraryFunctions::fetch_variable('woffset', 0, 0, '');
	$wsort = LibraryFunctions::fetch_variable('wsort', 'waiting_list_id', 0, '');
	$wsdirection = LibraryFunctions::fetch_variable('wsdirection', 'DESC', 0, '');
	$wsearchterm = LibraryFunctions::fetch_variable('wsearchterm', '', 0, '');
	$wsearch_criteria = array();
	$wsearch_criteria['event_id'] = $event->key;
	$waiting_lists = new MultiWaitingList(		
		$wsearch_criteria,
		array($wsort=>$wsdirection),
		$wnumperpage,
		$woffset);
	$numwaitinglist = $waiting_lists->count_all();
	$waiting_lists->load();
	$wpager = new Pager(array('numrecords'=>$numwaitinglist, 'numperpage'=> $wnumperpage), 'w');
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'events',
		'page_title' => 'Event',
		'readable_title' => 'Event',
		'breadcrumbs' => array(
			'Events'=>'/admin/admin_events', 
			$event->get('evt_name') => '/admin/admin_event?evt_event_id='.$event->key,
			'Registrants'=>'',
		),
		'session' => $session,
	)
	);	

	$settings = Globalvars::get_instance();
	$webDir = $settings->get_setting('webDir');



		$options['title'] = $event->get('evt_name');
			$options['altlinks'] = array();
			if(!$event->get('evt_delete_time')) {
				if($_SESSION['permission'] > 7){
					$options['altlinks'] += array('Edit Event' => '/admin/admin_event_edit?evt_event_id='.$event->key);
				}
			}
			else {
				//echo '<a class="dropdown-item" href="/admin/admin_events_undelete?evt_event_id='.$event->key.'">Undelete</a>';
			}

		if(!$event->get('evt_delete_time') && $_SESSION['permission'] >= 8) {
			$options['altlinks']['Soft Delete'] = '/admin/admin_event?action=delete&evt_event_id='.$event->key;
		}
		else if($event->get('evt_delete_time') && $_SESSION['permission'] >= 8) {
			$options['altlinks']['Undelete'] = '/admin/admin_event?action=undelete&evt_event_id='.$event->key;
		}
		$options['altlinks']['Registrant Emails'] = '/admin/admin_event_emails?evt_event_id='.$event->key;
			
		$page->begin_box($options);
	?>
	

              <p class="text-muted text-center"><?php echo $event->get_time_string('event', 'M j, Y'); ?></p>
			  
			  <p class="text-center">
			  <?php
				if($event->get('evt_delete_time')){
					echo 'Status: Deleted at '.LibraryFunctions::convert_time($event->get('evt_delete_time'), 'UTC', $session->get_timezone()).'<br />';
				}
				else if($event->get('evt_visibility') == 0) {
					echo '<b>Private</b> <a href="' . $event->get_url() . '">'.$event->get_url('short').'</a><br />';
				} 
				else if($event->get('evt_visibility') == 1){
					echo '<b>Public:</b> <a href="' . $event->get_url() . '">'.$event->get_url('short').'</a><br />';
				}
				else{
					echo '<b>Public but unlisted:</b> <a href="' . $event->get_url() . '">'.$event->get_url('short').'</a><br />';
				}		
				
				//echo '<b>Sessions page:</b> <a href="/profile/event_sessions_course?event_id='.$event->key.'">'.$settings->get_setting('webDir').'/profile/event_sessions_course?event_id='.$event->key.'</a><br />';
				?>
				</p>
			  <p class="text-center">
			  <?php
				if($event->get('evt_is_accepting_signups')) {
					echo '<b>Registration open</b><br />';
				} 
				else{
					echo '<b>Registration closed</b><br />';
				}		
				?>
				</p>


<?php $page->end_box();

	?>
	<ul class="nav nav-tabs">
	  <li class="nav-item"><a class="nav-link active" id="home-tab" href="#"  aria-selected="true">Registrants</a></li>
	  <li class="nav-item"><a class="nav-link" id="profile-tab" href="/admin/admin_event_sessions?evt_event_id=<?php echo $event->key; ?>" role="tab" aria-selected="false">Sessions</a></li>
	</ul>
	<?php



	$headers = array("Registrant", "Registered on", "Order", "Email Verified",  "Expires", "Action");
	$altlinks = array();
	if(!$event->get('evt_delete_time')) {
		if($_SESSION['permission'] >= 8){
			$altlinks +=  array('Email registrants' => '/admin/admin_users_message?evt_event_id='.$event->key);
			//echo '<a class="dropdown-item" href="/admin/admin_users_message?evt_event_id='.$event->key.'">Send email to all</a>';
		}
	}
	$box_vars =	array(
		'altlinks' => $altlinks,
		'title' => "Registrants (".$numregistrants.')'
	);
	$page->tableheader($headers, $box_vars, $rpager);

	$registrant_emails = '';
	foreach($event_registrants as $event_registrant){

		$registrant = new User($event_registrant->get('evr_usr_user_id'), TRUE);
		
		$registrant_emails .= $registrant->display_name() . ' &lt;'.$registrant->get('usr_email'). '&gt;, ';

		$rowvalues=array();
		array_push($rowvalues, '<a href="/admin/admin_user?usr_user_id='. $registrant->key. '">'.$registrant->display_name() . '</a>');
		array_push($rowvalues, LibraryFunctions::convert_time($event_registrant->get('evr_create_time'), 'UTC', $session->get_timezone()));
		//array_push($rowvalues, LibraryFunctions::bool_to_english($registrant->get('evr_is_default'), "Default", " dsasd"));
		//array_push($rowvalues, LibraryFunctions::bool_to_english($registrant->get('evr_is_verified'), "Verified", 'Not Verified [<a class="sortlink" href="/admin/admin_phone_verify?evr_registrant_id='. $registrant->key. '">Verify</a>]'));

		if($event_registrant->get('evr_ord_order_id')){
			$order = new Order($event_registrant->get('evr_ord_order_id'), TRUE);
		}

		$order_items = new MultiOrderItem(array('registrant_id' => $event_registrant->key));
		$order_items->load();
		
		$row = '';
		$total_paid = 0;
		foreach ($order_items as $order_item){	
			$row .= '<a href="/admin/admin_order?ord_order_id=' . $order_item->get('odi_ord_order_id') . '">Order# '.$order_item->get('odi_ord_order_id').'</a> ($'. $order_item->get('odi_price'). ')';
			//ADD AN ASTERISK IF THE ORDER HAS A REFUND
			$order = $order_item->get_order();
			if($order->get('ord_refund_amount')){
				$row .= '*'; 
			}
			$row .= '<br>';
		}
		array_push($rowvalues, $row);
		
		
		$evr_verified = LibraryFunctions::bool_to_english($registrant->get('usr_email_is_verified'),"Verified", "Unverified");
		array_push($rowvalues, $evr_verified);	
		
		if($event_registrant->get('evr_expires_time') && $event_registrant->get('evr_expires_time') < date("Y-m-d H:i:s")){
			array_push($rowvalues, 'Expired: '.LibraryFunctions::convert_time($event_registrant->get('evr_expires_time'), 'UTC', $session->get_timezone()));
		}
		else{
			array_push($rowvalues, LibraryFunctions::convert_time($event_registrant->get('evr_expires_time'), 'UTC', $session->get_timezone()));
		}
/*
		$reginfo = '';
		if($event_registrant->get('evr_recording_consent')){
			$reginfo .= 'Recording consent: '.LibraryFunctions::bool_to_english($event_registrant->get('evr_recording_consent'),"Yes", "No"). '<br />';
		}		
		if(!is_null($event_registrant->get('evr_first_event'))){ 
			$reginfo .= '<br>First Event: '. LibraryFunctions::bool_to_english($event_registrant->get('evr_first_event'),"Yes", "No") . '<br />';
		}
		
		if($event_registrant->get('evr_other_events')){
			$reginfo .= '<br>Other events attended: '. $event_registrant->get('evr_other_events'). '<br />';
		}
		if($event_registrant->get('evr_health_notes')){
			$reginfo .= '<br>Health notes: '. $event_registrant->get('evr_health_notes');
		}		


		if($event_registrant->get('evr_extra_info_completed') || !$event->get('evt_collect_extra_info')){
			array_push($rowvalues, $reginfo);
		}
		else{
			$act_code = Activation::CheckForActiveCode($registrant->key, Activation::EMAIL_VERIFY);
			if($act_code){
				$line = 'Not Answered <a href="/profile/event_register_finish?act_code='.$act_code->act_code.'&userid='.$registrant->key.'&eventregistrantid='.$event_registrant->key.'">link</a>';
			}
			else{
				$line = 'Not Answered';				
			}
			array_push($rowvalues, $line);
		}
	*/	
		
		$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_event?evt_event_id='. $event->key.'">
		<input type="hidden" class="hidden" name="action" id="action" value="remove_from_event" />
		<input type="hidden" class="hidden" name="evr_event_registrant_id" id="evr_event_registrant_id" value="'.$event_registrant->key.'" />
		'.$formwriter->new_form_button('Remove', 'secondary').'
		</form>';
		array_push($rowvalues, $delform);			

        $page->disprow($rowvalues);
	}

	$page->endtable($rpager);
	
	if($numwaitinglist){
		
		$headers = array("User", "Registered on", "Action");
		$altlinks = array();
		if(!$event->get('evt_delete_time')) {
			if($_SESSION['permission'] >= 8){
				$altlinks +=  array('Email waiting list' => '/admin/admin_users_message?waiting_list=1&evt_event_id='.$event->key);
				//echo '<a class="dropdown-item" href="/admin/admin_users_message?evt_event_id='.$event->key.'">Send email to all</a>';
			}
		}
		$box_vars =	array(
			'altlinks' => $altlinks,
			'title' => "Waiting List (".$numwaitinglist.')'
		);
		$page->tableheader($headers, $box_vars, $wpager);
		
		foreach($waiting_lists as $waiting_list){

			$registrant = new User($waiting_list->get('ewl_usr_user_id'), TRUE);

			$rowvalues=array();
			array_push($rowvalues, '<a href="/admin/admin_user?usr_user_id='. $registrant->key. '">'.$registrant->display_name() . '</a>');
			array_push($rowvalues, LibraryFunctions::convert_time($waiting_list->get('ewl_create_time'), 'UTC', $session->get_timezone()));

			
			$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_event?evt_event_id='. $event->key.'">
			<input type="hidden" class="hidden" name="action" id="action" value="remove_from_waiting_list" />
			<input type="hidden" class="hidden" name="ewl_waiting_list_id" id="ewl_waiting_list_id" value="'.$waiting_list->key.'" />
			'.$formwriter->new_form_button('Remove', 'secondary').'
			</form>';
			array_push($rowvalues, $delform);			

			$page->disprow($rowvalues);
		}

		$page->endtable($wpager);	
	}
	
	
	//MESSAGES
	$mnumperpage = 20;
	$moffset = LibraryFunctions::fetch_variable('moffset', 0, 0, '');
	$msort = LibraryFunctions::fetch_variable('msort', 'message_id', 0, '');
	$msdirection = LibraryFunctions::fetch_variable('msdirection', 'DESC', 0, '');
	$msearchterm = LibraryFunctions::fetch_variable('msearchterm', '', 0, '');
	$msearch_criteria = array();
	$msearch_criteria['event_id_only'] = $event->key;
	$messages = new MultiMessage(		
		$msearch_criteria,
		array($msort=>$msdirection),
		$mnumperpage,
		$moffset);
	$nummessages = $messages->count_all();
	$messages->load();
	$mpager = new Pager(array('numrecords'=>$nummessages, 'numperpage'=> $mnumperpage), 'w');
	
	
	$headers = array("Sender", "Message", "Time");
	$altlinks = array();
	if(!$event->get('evt_delete_time')) {
		if($_SESSION['permission'] >= 8){
			$altlinks +=  array('Email registrants' => '/admin/admin_users_message?evt_event_id='.$event->key);
			//echo '<a class="dropdown-item" href="/admin/admin_users_message?evt_event_id='.$event->key.'">Send email to all</a>';
		}
	}
	$box_vars =	array(
		'altlinks' => $altlinks,
		'title' => "Messages to registrants"
	);
	
	$page->tableheader($headers, $box_vars, $mpager);

	foreach($messages as $message){
		$user = new User($message->get('msg_usr_user_id_sender'), TRUE);

		$rowvalues=array();
		array_push($rowvalues, $user->display_name());
		array_push($rowvalues, '<a href="/admin/admin_message?msg_message_id='.$message->key.'">'.$message->display_title(). '...</a>');
		array_push($rowvalues, LibraryFunctions::convert_time($message->get('msg_sent_time'), 'UTC', $session->get_timezone()));
        $page->disprow($rowvalues);
	}

	$page->endtable($mpager);	
	
	/*
	$pageoptions['title'] = "Emails of all registrants";
	$page->begin_box($pageoptions);
	echo '<p>'.$registrant_emails. '';	
	$page->end_box();
	*/
/*
	?>




		<h2>Recurring Emails Sent</h2>

<?php

	$page->tableheader(array('Send Time', 'Email Address', 'Template'), 'recurring_mail_table');

	foreach (RecurringMailer::GetSentEmails($event->key) as $email) {
		$page->disprow(
			array(
				LibraryFunctions::FormatTimestampForEvent(new DateTime($email['ers_send_time']), $session),
				$email['ers_evt_email'],
				$email['ers_template_name'])
			);
	}

	$page->endtable();
*/
/*
?>

	<h2>Errors</h2>
	<?php
	$page->tableheader(
		array(
			"Error",
			),
		"admin_table");

	foreach ($form_errors as $form_error) {
		$rowvalues = array();

		array_push($rowvalues, '(' .$form_error->key.')<a href="/admin/admin_form_error?lfe_log_form_error_id=' . $form_error->key . '"> '. $form_error->display_time($session). '</a> (' . $form_error->get('lfe_page') . ')');
		$page->disprow($rowvalues);
	}
	$page->endtable();

	?>
	
	<h2>Contact Emails</h2>
	<?php
	$emails = new MultiEmail(
		array('event_id' => $event->key)
		);
	$emails->load();

	$page->tableheader(
		array(
			"Subject", "Status", "Sent Date", "Recipients"
			),
		"admin_table");

	foreach ($emails as $email) {
		$rowvalues = array();

		array_push($rowvalues, '('.$email->key.') '.$email->get('eml_subject'));
		array_push($rowvalues, $email->get_status_text());
		array_push($rowvalues, LibraryFunctions::convert_time( $email->get('eml_sent_time'), "UTC", $session->get_timezone()));

		$emails = new MultiEmailRecipient(
			array('email_id' => $email->key, 'sent' => TRUE)
			);
		$numemails = $emails->count_all();

		array_push($rowvalues, $numemails);
		$page->disprow($rowvalues);
	}
	$page->endtable();

*/


	$page->admin_footer();

?>
