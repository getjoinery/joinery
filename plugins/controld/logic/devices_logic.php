<?php

function devices_logic($get_vars, $post_vars){
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/includes/ControlDHelper.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldaccounts_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctlddevices_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctlddevice_backups_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldservices_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldfilters_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldprofiles_class.php');

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
	
	//CHECK FOR ACTIVATION 
	foreach($devices as $device){
		//CHECK FOR ACTIVATION
		$device->check_activate();
	}
	
	//COUNT THE ALWAYS ON BLOCKS
	$num_blocks_always = array();
	$num_blocks_scheduled = array();
	foreach($devices as $device){
		$profile = new CtldProfile($device->get('cdd_cdp_ctldprofile_id_primary'), TRUE);
		$num_blocks_always[$device->key] += $profile->count_blocks();

		if($device->get('cdd_cdp_ctldprofile_id_secondary')){
			$profile = new CtldProfile($device->get('cdd_cdp_ctldprofile_id_secondary'), TRUE);
			$num_blocks_scheduled[$device->key] += $profile->count_blocks();
		}
	}
	$page_vars['num_blocks_always'] = $num_blocks_always;
	$page_vars['num_blocks_scheduled'] = $num_blocks_scheduled;

	//SCHEDULE STRING
	foreach($devices as $device){

		if($profile->get('cdp_schedule_start')){
			
			$primary_arr = $device->get_time_to_active_profile('primary');
			if(!$primary_arr){
				$primary_time = '';
			}
			else if($primary_arr['hours'] == 0 && $primary_arr['minutes'] == 0){
				$primary_time = 'Active';
			}
			else{
				$primary_time = 'Active in '.$primary_arr['hours'].' hours, '.$primary_arr['minutes'].' minutes';
			}
			$scheduled_string['primary'][$device->key] = '<span class="duration">'.$primary_time.'</span>';
			
			if($device->get('cdd_cdp_ctldprofile_id_secondary')){
				$secondary_arr = $device->get_time_to_active_profile('secondary');
				if(!$secondary_arr){
					$primary_time = '';
				}
				else if($secondary_arr['hours'] == 0 && $secondary_arr['minutes'] == 0){
					$secondary_time = 'Active';
				}
				else{
					$secondary_time = 'Active in '.$secondary_arr['hours'].' hours, '.$secondary_arr['minutes'].' minutes';
				}
				$scheduled_string['secondary'][$device->key] = '<span class="duration">'.$secondary_time.'</span>';
			}
			
		}
	}
	$page_vars['scheduled_string'] = $scheduled_string;
	
	return LogicResult::render($page_vars);	
	
}

?>
