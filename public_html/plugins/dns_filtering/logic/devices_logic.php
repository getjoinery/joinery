<?php

function devices_logic(array $input): LogicResult{
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/devices_class.php'));
	require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/device_backups_class.php'));
	require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/scheduled_blocks_class.php'));

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

	// FETCH LAST-SEEN FROM DNS SERVER(S)
	require_once(PathHelper::getIncludePath('plugins/dns_filtering/includes/ScrollDaddyApiClient.php'));
	$last_seen = array();
	foreach($devices as $device){
		$uid = $device->get('sdd_resolver_uid');
		if(!$uid) continue;

		$path = '/device/' . $uid . '/seen';
		$data = ScrollDaddyApiClient::callPrimary($path, 'GET', 2);

		// If primary hasn't seen it, check secondary (important during install)
		if((!$data || empty($data['seen'])) && $settings->get_setting('dns_filtering_dns_secondary_internal_url')){
			$secondary_data = ScrollDaddyApiClient::callSecondary($path, 'GET', 2);
			if($secondary_data && !empty($secondary_data['seen'])){
				$data = $secondary_data;
			}
		}

		if($data && isset($data['seen'])){
			$last_seen[$device->key] = $data;
		}
	}
	$page_vars['last_seen'] = $last_seen;
	
	//COUNT THE ALWAYS-ON BLOCK CONTENTS (filters + services + rules with action=block)
	$num_blocks_always = array();
	$always_on_block_ids = array();
	$always_on_blocks = array();
	foreach($devices as $device){
		$always_on = SdScheduledBlock::getOrCreateAlwaysOnBlock($device->key);
		$always_on_block_ids[$device->key] = $always_on->key;
		$always_on_blocks[$device->key] = $always_on;

		$filter_rows = new MultiSdScheduledBlockFilter(['block_id' => $always_on->key, 'action' => 0]);
		$service_rows = new MultiSdScheduledBlockService(['block_id' => $always_on->key, 'action' => 0]);
		$rule_rows = new MultiSdScheduledBlockRule(['block_id' => $always_on->key, 'action' => 0]);

		$num_blocks_always[$device->key] = $filter_rows->count_all() + $service_rows->count_all() + $rule_rows->count_all();
	}
	$page_vars['num_blocks_always'] = $num_blocks_always;
	$page_vars['always_on_block_ids'] = $always_on_block_ids;

	// LOAD SCHEDULED BLOCKS PER DEVICE (exclude always-on — it's rendered separately)
	$scheduled_blocks = array();
	foreach($devices as $device){
		$blocks = new MultiSdScheduledBlock(
			array('device_id' => $device->key, 'is_always_on' => false),
			array('sdb_scheduled_block_id' => 'ASC')
		);
		$blocks->load();
		$scheduled_blocks[$device->key] = $blocks;
	}
	$page_vars['scheduled_blocks'] = $scheduled_blocks;

	// API: clean JSON payload — device entries with DoH/DoT endpoints, the
	// hard-block hostname list, and a per-block summary
	if($session->is_api_context()){
		require_once(PathHelper::getIncludePath('plugins/dns_filtering/includes/ScrollDaddyHelper.php'));

		$api_devices = array();
		foreach($devices as $device){
			$entry = ScrollDaddyHelper::exportDevice($device, $settings);
			$entry['last_seen'] = isset($last_seen[$device->key]) ? $last_seen[$device->key] : null;

			$blocks_summary = array(ScrollDaddyHelper::exportBlock($always_on_blocks[$device->key], false));
			foreach($scheduled_blocks[$device->key] as $block){
				$blocks_summary[] = ScrollDaddyHelper::exportBlock($block, false);
			}
			$entry['blocks'] = $blocks_summary;

			$api_devices[] = $entry;
		}

		return LogicResult::render(array(
			'num_devices' => $num_devices,
			'devices' => $api_devices,
		));
	}

	return LogicResult::render($page_vars);

}

function devices_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'List DNS-filtering devices with DoH/DoT endpoints, block summaries, and hard-block hostnames',
	];
}

?>
