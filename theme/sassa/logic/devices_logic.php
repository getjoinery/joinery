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
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctlddevice_backups_class.php');
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


	if(!$session->is_logged_in()){
		LibraryFunctions::redirect('/login');
		exit;
	}
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
	


	
	return $page_vars;	
	
}


	
?>
