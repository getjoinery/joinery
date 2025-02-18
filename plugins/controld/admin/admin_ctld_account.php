<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');


	require_once(__DIR__.'/../data/ctldaccounts_class.php');
	require_once(__DIR__.'/../data/ctlddevices_class.php');
	require_once(__DIR__.'/../data/ctldfilters_class.php');
	require_once(__DIR__.'/../data/ctldprofiles_class.php');
	require_once(__DIR__.'/../data/ctldservices_class.php');
	require_once(__DIR__.'/../includes/ControlDHelper.php');



	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();
	
	function convertToAmPmManual($militaryTime) {
		// Split the time into hours and minutes
		list($hours, $minutes) = explode(":", $militaryTime);

		// Validate input
		if (!is_numeric($hours) || !is_numeric($minutes) || $hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
			return "Invalid time format";
		}

		// Determine AM or PM
		$period = $hours >= 12 ? "PM" : "AM";

		// Convert hours to 12-hour format
		$hours = $hours % 12;
		$hours = $hours == 0 ? 12 : $hours; // Handle midnight and noon

		// Format the time
		return sprintf("%d:%02d %s", $hours, $minutes, $period);
	}
	
	$account = new CtldAccount($_REQUEST['account_id'], TRUE);
	

	$devices = new MultiCtldDevice(
		array(
		'user_id' => $account->get('cda_usr_user_id'), 
		), 
		
	);
	$num_devices = $devices->count_all();
	$devices->load();
	$num_devices = $num_devices;
	$page_vars['devices'] = $devices;
	
	
	//DELETED DEVICES
	$deleted_devices = new MultiCtldDeviceBackup(
		array(
		'user_id' => $user->key
		), 
		
	);
	$num_deleted_devices = $deleted_devices->count_all();
	$deleted_devices->load();
	$page_vars['num_deleted_devices'] = $num_deleted_devices;
	$page_vars['deleted_devices'] = $deleted_devices;	
	
	
	//COUNT THE ALWAYS ON BLOCKS
	$num_blocks_always = array();
	foreach($devices as $device){
		$filters = new MultiCtldFilter(
			array(
				'profile_id' => $device->get('cdd_cdp_ctldprofile_id_primary'),
				'active' => true,
			),
		);
		$num_blocks_always[$device->key] = $filters->count_all();

		$services = new MultiCtldService(
			array(
				'profile_id' => $device->get('cdd_cdp_ctldprofile_id_primary'),
				'active' => true,
			),
		);
		$num_blocks_always[$device->key] += $services->count_all();	

	}
	$page_vars['num_blocks_always'] = $num_blocks_always;
	
	
	

	
	
	
	
	
	
	
	

	//COUNT THE SCHEDULED BLOCKS
	$num_blocks_scheduled = array();
	
	foreach($devices as $device){
		$filters = new MultiCtldFilter(
			array(
				'profile_id' => $device->get('cdd_cdp_ctldprofile_id_secondary'),
				'active' => true,
			),
		);
		$num_blocks_scheduled[$device->key] = $filters->count_all();

		$services = new MultiCtldService(
			array(
				'profile_id' => $device->get('cdd_cdp_ctldprofile_id_secondary'),
				'active' => true,
			),
		);
		$num_blocks_scheduled[$device->key] += $services->count_all();	

		$profile = new CtldProfile($device->get('cdd_cdp_ctldprofile_id_secondary'), TRUE);
		$scheduled_string[$device->key] = 'No schedule set';
		if($profile->get('cdp_schedule_start')){
			
			$scheduled_string[$device->key] = '<span class="duration">'.convertToAmPmManual($profile->get('cdp_schedule_start')) . ' - ' . convertToAmPmManual($profile->get('cdp_schedule_end')) . '</span> '. implode(', ', unserialize($profile->get('cdp_schedule_days'))) ;
		}
	}
	$page_vars['scheduled_string'] = $scheduled_string;
	$page_vars['num_blocks_scheduled'] = $num_blocks_scheduled;


	$wpager = new Pager(array('numrecords'=>$numwaitinglist, 'numperpage'=> $wnumperpage), 'w');
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'events',
		'page_title' => 'Account',
		'readable_title' => 'Account',
		'breadcrumbs' => array(
			'Accounts'=>'/plugins/controld/admin/admin_ctld_accounts', 
			//$event->get('evt_name') => '/admin/admin_event?evt_event_id='.$event->key,
			'Registrants'=>'',
		),
		'session' => $session,
	)
	);	

	$settings = Globalvars::get_instance();
	$webDir = $settings->get_setting('webDir');


/*
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
			*/
		$page->begin_box($options);
	?>
	

              <p class="text-muted text-center"><?php echo $account->readable_plan_name(); ?></p>
			  
			  <p class="text-center">
			  <?php
			  
		if($account->get('cda_delete_time')) {
			$status = 'Deleted';
		} 
		else {
			if($account->get('cda_is_active')) {
				$status = 'Active';
			}
			else{
				$status = 'Inactive';
			}
		}		
		echo $status;

		if($account->get('cda_renewal_time')){
			echo LibraryFunctions::convert_time($account->get(cda_renewal_time), 'UTC', $session->get_timezone());
		}
		else{
			echo 'n/a';
		}
			  /*
				if($event->get('evt_delete_time')){
					echo 'Status: Deleted at '.LibraryFunctions::convert_time($event->get('evt_delete_time'), 'UTC', $session->get_timezone()).'<br />';
				}
				else if($event->get('evt_visibility') == 0) {
					echo '<b>Private</b> <a href="' . $event->get_url() . '">'.$event->get_url('full').'</a><br />';
				} 
				else if($event->get('evt_visibility') == 1){
					echo '<b>Public:</b> <a href="' . $event->get_url() . '">'.$event->get_url('full').'</a><br />';
				}
				else{
					echo '<b>Public but unlisted:</b> <a href="' . $event->get_url() . '">'.$event->get_url('full').'</a><br />';
				}		
				
				//echo '<b>Sessions page:</b> <a href="/profile/event_sessions_course?event_id='.$event->key.'">'.$settings->get_setting('webDir').'/profile/event_sessions_course?event_id='.$event->key.'</a><br />';
				*/
				?>
				</p>



<?php $page->end_box();
/*
	?>
	<nav class="uk-navbar-container" uk-navbar>
		<div class="uk-navbar-left">
			<ul class="uk-navbar-nav">
				<li class="uk-active"><a href="">Registrants</a></li>
				<li class="uk-parent"><a href="/admin/admin_event_sessions?evt_event_id=<?php echo $event->key; ?>">Sessions</a></li>
			</ul>
		</div>
	</nav>
	
	<?php
*/


	$headers = array("Device Name", "Status", "Always on Blocks", "Scheduled Blocks",  "Schedule", "Action");
	$altlinks = array();
	/*
	if(!$event->get('evt_delete_time')) {
		if($_SESSION['permission'] >= 8){
			$altlinks +=  array('Email registrants' => '/admin/admin_users_message?evt_event_id='.$event->key);
			//echo '<a class="dropdown-item" href="/admin/admin_users_message?evt_event_id='.$event->key.'">Send email to all</a>';
		}
	}
	*/
	$box_vars =	array(
		'altlinks' => $altlinks,
		'title' => "Devices (".$num_devices.')'
	);
	$page->tableheader($headers, $box_vars, $rpager);

	$registrant_emails = '';
	foreach($devices as $device){

		$filters_primary = new MultiCtldFilter(
				array(
					'profile_id' => $device->get('cdd_cdp_ctldprofile_id_primary'),
				),
			);
			//$num_filters = $filters->count_all();
			$filters_primary->load();
	
	
		$services_primary = new MultiCtldService(
				array(
					'profile_id' => $device->get('cdd_cdp_ctldprofile_id_primary'),
				),
			);
			//$num_services = $services->count_all();
			$services_primary->load();


		$filters_secondary = new MultiCtldFilter(
				array(
					'profile_id' => $device->get('cdd_cdp_ctldprofile_id_secondary'),
				),
			);
			//$num_filters = $filters->count_all();
			$filters_secondary->load();
	
	
		$services_secondary = new MultiCtldService(
				array(
					'profile_id' => $device->get('cdd_cdp_ctldprofile_id_secondary'),
				),
			);
			//$num_services = $services->count_all();
			$services_secondary->load();		

			$primary_array = array();
			$secondary_array = array();
		if($_GET['remotestatus'] == 60){
			$cd = new ControlDHelper();
			$profile_primary = new CtldProfile($device->get('cdd_cdp_ctldprofile_id_primary'), TRUE);
			$profile_secondary = new CtldProfile($device->get('cdd_cdp_ctldprofile_id_secondary'), TRUE);
			

			$r_services_primary = $cd->listServicesOnProfile($profile_primary->get('cdp_profile_id'));
			foreach($r_services_primary['body']['services'] as $r){
				$primary_array[] = $r['PK'];
			}
			$r_services_secondary = $cd->listServicesOnProfile($profile_secondary->get('cdp_profile_id'));
			foreach($r_services_secondary['body']['services'] as $r){
				$secondary_array[] = $r['PK'];
			}	
			
			$r_filters_primary = $cd->listNativeFilters($profile_primary->get('cdp_profile_id'));
			foreach($r_filters_primary['body']['services'] as $r){
				$primary_array[] = $r['PK'];
			}			
			$r_filters_secondary = $cd->listNativeFilters($profile_secondary->get('cdp_profile_id'));
			foreach($r_filters_secondary['body']['services'] as $r){
				$secondary_array[] = $r['PK'];
			}	
		}
		else{
			$primary_array[] = '<a href="/plugins/controld/admin/admin_ctld_account?account_id='.$account->key.'&remotestatus='.$device->key.'">Check</a>';
			$secondary_array[] = '<a href="/plugins/controld/admin/admin_ctld_account?account_id='.$account->key.'&remotestatus='.$device->key.'">Check</a>';			
		}



		$rowvalues=array();
		array_push($rowvalues, $device->get_readable_name());

		if($device->get('cdd_delete_time')) {
			$status = 'Deleted';
		} 
		else {
			if($device->get('cdd_is_active')) {
				$status = 'Active';
			}
			else{
				$status = 'Inactive ('.$device->get('cdd_device_id').')';
			}
		}		
		array_push($rowvalues, $status);

		$primary_out = array();
		foreach($filters_primary as $filter){
			if($filter->get('cdf_is_active')){
				$primary_out[] =$filter->get('cdf_filter_pk');
			}
		}
		
		foreach($services_primary as $service){
			if($service->get('cds_is_active')){
				$primary_out[] =$service->get('cds_service_pk');
			}
		}
		array_push($rowvalues, $num_blocks_always[$device->key]. ' ('.implode(', ', $primary_out).')' . ' (Remote: '.implode(', ', $primary_array).')' );


		$secondary_out = array();
		foreach($filters_secondary as $filter){
			if($filter->get('cdf_is_active')){
				$secondary_out[] =$filter->get('cdf_filter_pk');
			}
		}
		foreach($services_secondary as $service){
			if($service->get('cds_is_active')){
				$secondary_out[] =$service->get('cds_service_pk');
			}
		}
		
		array_push($rowvalues, $num_blocks_scheduled[$device->key]. ' ('.implode(', ', $secondary_out).')' . ' (Remote: '.implode(', ', $secondary_array).')' );
		
		array_push($rowvalues, $scheduled_string[$device->key]. ' (' . $device->get('cdd_timezone').')');

		



		/*
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

		
		$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_event?evt_event_id='. $event->key.'">
		<input type="hidden" class="hidden" name="action" id="action" value="remove_from_event" />
		<input type="hidden" class="hidden" name="evr_event_registrant_id" id="evr_event_registrant_id" value="'.$event_registrant->key.'" />
		<button class="uk-button" type="submit">Remove</button>
		</form>';
		array_push($rowvalues, $delform);			
		*/
        $page->disprow($rowvalues);
	}

	$page->endtable($rpager);
	
	/*
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
			<button class="uk-button" type="submit">Remove</button>
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
