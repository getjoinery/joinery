<?php

function devices_logic($get_vars, $post_vars){
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/devices_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/device_backups_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/services_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/filters_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/profiles_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/scheduled_blocks_class.php'));

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

	$devices = new MultiSdDevice(
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
	$deleted_devices = new MultiSdDeviceBackup(
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
			$uid = $device->get('sdd_resolver_uid');
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
	foreach($devices as $device){
		$profile = new SdProfile($device->get('sdd_sdp_profile_id_primary'), TRUE);
		$num_blocks_always[$device->key] = $profile->count_blocks();
	}
	$page_vars['num_blocks_always'] = $num_blocks_always;

	// LOAD SCHEDULED BLOCKS PER DEVICE
	$scheduled_blocks = array();
	foreach($devices as $device){
		$blocks = new MultiSdScheduledBlock(
			array('device_id' => $device->key),
			array('sdb_scheduled_block_id' => 'ASC')
		);
		$blocks->load();
		$scheduled_blocks[$device->key] = $blocks;
	}
	$page_vars['scheduled_blocks'] = $scheduled_blocks;
	
	return LogicResult::render($page_vars);	
	
}

?>
