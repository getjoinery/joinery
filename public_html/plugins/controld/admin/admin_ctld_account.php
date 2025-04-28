<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage.php');
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
	
	$account = new CtldAccount($_REQUEST['account_id'], TRUE);
	$user = new User($account->get('cda_usr_user_id'), TRUE);

	$devices = new MultiCtldDevice(
		array(
		'user_id' => $user_id, 
		), 
		
	);
	$num_devices = $devices->count_all();
	$devices->load();
	$num_devices = $num_devices;
	$page_vars['devices'] = $devices;
	
	//SUBSCRIPTIONS
	$subscriptions = new MultiOrderItem(
	array('user_id' => $user->key), //SEARCH CRITERIA
	array('order_item_id' => 'DESC'),  // SORT, SORT DIRECTION
	5, //NUMBER PER PAGE
	NULL //OFFSET
	);
	$subscriptions->load();	
	$numsubscriptions = $subscriptions->count_all();
	$subscription_list = array();
	foreach($subscriptions as $subscription){
		$subscription_list[] = '<a href="/admin/admin_order?ord_order_id='.$subscription->get('odi_ord_order_id').'">'.$subscription->readable_subscription_status().'</a>';
	}
	
	
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
		$profile = new CtldProfile($device->get('cdd_cdp_ctldprofile_id_primary'), TRUE);
		$num_blocks_always[$device->key] += $profile->count_blocks();
	}
	$page_vars['num_blocks_always'] = $num_blocks_always;


	//COUNT THE SCHEDULED BLOCKS
	$num_blocks_scheduled = array();
	
	foreach($devices as $device){
		//CHECK FOR ACTIVATION
		$device->check_activate();
		
		$profile = new CtldProfile($device->get('cdd_cdp_ctldprofile_id_secondary'), TRUE);
		$num_blocks_scheduled[$device->key] += $profile->count_blocks();

		$scheduled_string[$device->key] = 'No schedule set';
		if($profile->get('cdp_schedule_start')){
			
			$scheduled_string['primary'][$device->key] = '<span class="duration">'.$device->get_schedule_string('primary').'</span>';
			$scheduled_string['secondary'][$device->key] = '<span class="duration">'.$device->get_schedule_string('secondary').'</span>';
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
		echo '<br>';
		echo '('.$numsubscriptions.') <br>'.implode('<br>', $subscription_list);
		echo '<br>';

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



	$headers = array("Device Name", "Status", "Default blocklist", "Scheduled blocklist",  "Schedule", "Active profile", "Editable");
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
		
		//CHECK FOR ACTIVATION
		$device->check_activate();

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
			
		$rules_primary = new MultiCtldRule(
			array(
				'profile_id' =>  $device->get('cdd_cdp_ctldprofile_id_primary'),
			),
		);
		$rules_primary->load();

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

		$rules_secondary = new MultiCtldRule(
			array(
				'profile_id' =>  $device->get('cdd_cdp_ctldprofile_id_secondary'),
			),
		);
		$rules_secondary->load();			

			$primary_array = array();
			$secondary_array = array();
		if($_GET['remotestatus'] == $device->key){
			$cd = new ControlDHelper();
			$profile_primary = new CtldProfile($device->get('cdd_cdp_ctldprofile_id_primary'), TRUE);
			$profile_secondary = new CtldProfile($device->get('cdd_cdp_ctldprofile_id_secondary'), TRUE);
			

			$r_services_primary = $cd->listServicesOnProfile($profile_primary->get('cdp_profile_id'));
			foreach($r_services_primary['body']['services'] as $r){
				if($r['action']['status']){
					$primary_array[] = $r['PK'];
				}
			}
			$r_services_secondary = $cd->listServicesOnProfile($profile_secondary->get('cdp_profile_id'));
			foreach($r_services_secondary['body']['services'] as $r){
				if($r['action']['status']){
					$secondary_array[] = $r['PK'];
				}
			}	

			$r_filters_primary = $cd->listNativeFilters($profile_primary->get('cdp_profile_id'));
			foreach($r_filters_primary['body']['filters'] as $r){
				if($r['action']){
					$primary_array[] = $r['PK'];
				}
			}			
			$r_filters_secondary = $cd->listNativeFilters($profile_secondary->get('cdp_profile_id'));

			foreach($r_filters_secondary['body']['filters'] as $r){
				if($r['status']){
					$secondary_array[] = $r['PK'];
				}
			}	

			$r_rules_primary = $cd->listRules($profile_primary->get('cdp_profile_id'));
			foreach($r_rules_primary['body']['rules'] as $r){
				$primary_array[] = $r['PK'];
			}	
	
			$r_rules_secondary = $cd->listRules($profile_secondary->get('cdp_profile_id'));

			foreach($r_rules_secondary['body']['rules'] as $r){
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
		
		foreach($rules_primary as $rule){
			if($rule->get('cdr_rule_action') == 0){
				$primary_out[] =$rule->get('cdr_rule_hostname') . '(blocked)';
			}
			else if($rule->get('cdr_rule_action') == 1){
				$primary_out[] =$rule->get('cdr_rule_hostname') . '(allowed)';
			}
		}
		array_push($rowvalues, $num_blocks_always[$device->key]. ' ('.implode(', ', $primary_out).')' . ' <br>(Remote: '.implode(', ', $primary_array).')' );


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

		foreach($rules_secondary as $rule){
			if($rule->get('cdr_rule_action') == 0){
				$secondary_out[] =$rule->get('cdr_rule_hostname') . '(blocked)';
			}
			else if($rule->get('cdr_rule_action') == 1){
				$secondary_out[] =$rule->get('cdr_rule_hostname') . '(allowed)';
			}
		}

		
		array_push($rowvalues, $num_blocks_scheduled[$device->key]. ' ('.implode(', ', $secondary_out).')' . ' <br>(Remote: '.implode(', ', $secondary_array).')' );
		
		array_push($rowvalues, $scheduled_string['secondary'][$device->key]. ' (' . $device->get('cdd_timezone').')');
		
		array_push($rowvalues, $device->get_active_profile('readable'));
		
		if($device->get('cdd_allow_device_edits')){
			array_push($rowvalues, 'Edits All');
		}
		else{
			if($device->are_filters_editable()){
				array_push($rowvalues, 'Edits Sunday (ON now)');
			}
			else{
				array_push($rowvalues, 'Edits Sunday (OFF now)');
			}
		}

		




        $page->disprow($rowvalues);
	}

	$page->endtable($rpager);
	
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
