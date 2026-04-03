<?php

function devices_logic($get_vars, $post_vars){
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/ctlddevices_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/ctlddevice_backups_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/ctldservices_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/ctldfilters_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/ctldprofiles_class.php'));

	$page_vars = array();

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	if(!$session->is_logged_in()){
		return LogicResult::redirect('/login');
	}
	$session->check_permission(0);
	$session->set_return();

	$user = new User($session->get_user_id(), TRUE);
	$page_vars['user'] = $user;

	$tier = SubscriptionTier::GetUserTier($user->key);
	$page_vars['tier'] = $tier;

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

	// FETCH LAST-SEEN FROM DNS SERVER
	$dns_internal_url = $settings->get_setting('scrolldaddy_dns_internal_url');
	$dns_api_key = $settings->get_setting('scrolldaddy_dns_api_key');
	$last_seen = array();
	if($dns_internal_url && $dns_api_key){
		foreach($devices as $device){
			$uid = $device->get('cdd_resolver_uid');
			if(!$uid) continue;
			$url = rtrim($dns_internal_url, '/') . '/device/' . $uid . '/seen';
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 2);
			curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-Key: ' . $dns_api_key]);
			$response = curl_exec($ch);
			curl_close($ch);
			if($response){
				$data = json_decode($response, true);
				if($data && isset($data['seen'])){
					$last_seen[$device->key] = $data;
				}
			}
		}
	}
	$page_vars['last_seen'] = $last_seen;
	
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
