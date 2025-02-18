<?php

function devices_logic($get_vars, $post_vars){
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($siteDir . '/plugins/controld/includes/ControlDHelper.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldaccounts_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctlddevices_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldservices_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldfilters_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldprofiles_class.php');


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
	
	$page_vars = array();
	
	$settings = Globalvars::get_instance(); 
	$page_vars['settings'] = $settings;

	
	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;
	$session->check_permission(0);
	$session->set_return();
	
	
	$user = new User($session->get_user_id(), TRUE);	
	$page_vars['user'] = $user;
	
	$account = CtldAccount::GetByColumn('cda_usr_user_id', $user->key);


	$page_vars['account'] = $account;


	$devices = new MultiCtldDevice(
		array(
		'user_id' => $user->key, 
		'deleted' => false
		), 
		
	);
	$num_devices = $devices->count_all();
	$devices->load();
	$page_vars['num_devices'] = $num_devices;
	$page_vars['devices'] = $devices;
	
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
	


	/*	
	//ORDERS
	$numperpage = 5;
	$conoffset = LibraryFunctions::fetch_variable('conoffset', 0, 0, '');
	$consort = LibraryFunctions::fetch_variable('consort', 'ord_order_id', 0, '');	
	$consdirection = LibraryFunctions::fetch_variable('consdirection', 'DESC', 0, '');
	$search_criteria = NULL;
	
	$search_criteria = array();
	$search_criteria['user_id'] = $session->get_user_id();
	$search_criteria['deleted'] = false;
	

	$orders = new MultiOrder(
		$search_criteria,
		array($consort=>$consdirection),
		$numperpage,
		$conoffset);
	$numorders = $orders->count_all();
	$orders->load();
	$page_vars['numorders'] = $numorders;
	$page_vars['orders'] = $orders;
	*/
	

	
	//SUBSCRIPTIONS
	/*
	$subscriptions = new MultiOrderItem(
	array('user_id' => $user->key, 'is_subscription' => true), //SEARCH CRITERIA
	array('order_item_id' => 'DESC'),  // SORT, SORT DIRECTION
	5, //NUMBER PER PAGE
	NULL //OFFSET
	);
	$subscriptions->load();	
	$page_vars['subscriptions'] = $subscriptions;
	
	
	$user_subscribed_list = array();
	$search_criteria = array('deleted' => false, 'user_id' => $user->key);
	$user_lists = new MultiMailingListRegistrant(
		$search_criteria);	
	$user_lists->load();
	
	foreach ($user_lists as $user_list){
		$mailing_list = new MailingList($user_list->get('mlr_mlt_mailing_list_id'), TRUE);
		$user_subscribed_list[] = $mailing_list->get('mlt_name');
	}	



	$page_vars['user_subscribed_list'] = $user_subscribed_list;
	
	
	$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);
	*/
	
	return $page_vars;	
	
}


	
?>
