<?php

function device_edit_logic($get_vars, $post_vars){

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/devices_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/profiles_class.php'));

	$page_vars = array();

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;
	$session->check_permission(0);
	$session->set_return();

	$user = new User($session->get_user_id(), TRUE);
	$page_vars['user'] = $user;

	$tier = SubscriptionTier::GetUserTier($user->key);
	if(!$tier){
		return LogicResult::error("You do not have an active subscription.");
	}
	$page_vars['tier'] = $tier;

	$devices = new MultiSdDevice(
		array(
		'user_id' => $user->key,
		'deleted' => false
		),
	);
	$num_devices = $devices->count_all();
	$page_vars['num_devices'] = $num_devices;

	$device = null;
	if($_REQUEST['device_id']){
		$device = new SdDevice($_REQUEST['device_id'], TRUE);
		$device->authenticate_read(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$page_vars['device'] = $device;
	}

	if(isset($_POST['device_name'])){

		if($device){
			// Edit existing device
			$device_name = LibraryFunctions::fetch_variable_local($post_vars, 'device_name', 0, 'required', 'Device name is required.', 'safemode', NULL);

			// Add user prefix if not already present
			if(!preg_match('/^user\d+-/', $device_name)){
				$device_name = 'user'.$user->key . '-' . $device_name;
			}

			$device->set('sdd_timezone', strip_tags($_POST['sdd_timezone']));
			$device->set('sdd_device_name', $device_name);
			$device->set('sdd_allow_device_edits', $_POST['sdd_allow_device_edits']);
			$device->set('sdd_log_queries', isset($_POST['sdd_log_queries']) && $_POST['sdd_log_queries'] === '1');
			$device->prepare();
			$device->save();

			return LogicResult::redirect('/profile/devices');
		}
		else{
			// Create new device — check device limit
			$max_devices = SubscriptionTier::getUserFeature($user->key, 'scrolldaddy_max_devices', 0);
			$current_devices = new MultiSdDevice([
				'user_id' => $user->key,
				'deleted' => false
			]);
			if($current_devices->count_all() >= $max_devices){
				return LogicResult::error("You have reached your device limit of {$max_devices}.");
			}

			$empty_device = new SdDevice(NULL);
			$empty_device->save();
			$empty_device->load();

			// Create the primary profile
			$profile_name = 'user'.$user->key . '-'.$empty_device->key.'-profile1';
			$profile1 = SdProfile::createProfile($profile_name, $user);

			$profile2 = null;
			$device = SdDevice::createDevice($empty_device, $profile1, $profile2, $_POST);
		}

		return LogicResult::redirect('/profile/devices');
	}
	else{

		if(!$device){
			$device = new SdDevice(NULL);
			$device->set('sdd_timezone', 'America/New_York');
			$page_vars['device'] = $device;
		}

	}

	return LogicResult::render($page_vars);
}

?>
