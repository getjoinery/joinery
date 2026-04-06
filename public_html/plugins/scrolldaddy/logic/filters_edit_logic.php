<?php

function filters_edit_logic($get_vars, $post_vars){

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
	$page_vars['tier'] = $tier;

	if(isset($_POST['action'])){
		$device_id = LibraryFunctions::fetch_variable_local($post_vars, 'device_id', NULL, 'required', 'Device id is required.', 'safemode', 'int');
		$device = new SdDevice($device_id, TRUE);
		$device->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$page_vars['device'] = $device;

		$profile = new SdProfile($device->get('sdd_sdp_profile_id_primary'), TRUE);
		$page_vars['profile'] = $profile;

		//CHANGE DROPDOWN STRUCTURE
		if(isset($_POST['block_malware'])){
			if($_POST['block_malware'] != 0){
				$new_key = 'block_'.$_POST['block_malware'];
				$_POST['block_'.$_POST['block_malware']] = 1;
				$_POST['block_malware'] = 0;
			}
		}

		if(isset($_POST['block_ads'])){
			if($_POST['block_ads'] != 0){
				$new_key = 'block_'.$_POST['block_ads'];
				$_POST['block_'.$_POST['block_ads']] = 1;
				$_POST['block_ads'] = 0;
			}
		}

		$profile->update_remote_filters($_POST);
		$profile->update_remote_services($_POST);

		return LogicResult::redirect('/profile/devices');
	}
	else{
		$device_id = LibraryFunctions::fetch_variable_local($get_vars, 'device_id', NULL, 'required', 'Device id is required.', 'safemode', 'int');
		$device = new SdDevice($device_id, TRUE);
		$device->authenticate_read(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$page_vars['device'] = $device;

		$profile = new SdProfile($device->get('sdd_sdp_profile_id_primary'), TRUE);
		$page_vars['profile'] = $profile;

		$filters = new MultiSdFilter(
				array(
					'profile_id' => $profile->key,
				),
			);
			//$num_filters = $filters->count_all();
			$filters->load();

		//$page_vars['num_filters'] = $num_devices;
		$filter_out = array();

		foreach($filters as $filter){
			$filter_out[$filter->get('sdf_filter_key')] = $filter->get('sdf_is_active');
		}

		//DROPDOWN FORMATTING
		if($filter_out['ads']){
			$filter_out['ads'] = 'ads';
		}
		else if($filter_out['ads_medium']){
			$filter_out['ads'] = 'ads_medium';
		}
		else if($filter_out['ads_small']){
			$filter_out['ads'] = 'ads_small';
		}

		//DROPDOWN FORMATTING
		if($filter_out['malware']){
			$filter_out['malware'] = 'malware';
		}
		else if($filter_out['ip_malware']){
			$filter_out['malware'] = 'ip_malware';
		}
		else if($filter_out['ai_malware']){
			$filter_out['malware'] = 'ai_malware';
		}

		$page_vars['filters'] = $filter_out;

		$services = new MultiSdService(
				array(
					'profile_id' => $profile->key,
				),
			);
			//$num_services = $services->count_all();
			$services->load();

		//$page_vars['num_services'] = $num_devices;
		$service_out = array();
		foreach($services as $service){
			$service_out[$service->get('sds_service_key')] = $service->get('sds_is_active');
		}

		$page_vars['services'] = $service_out;

	}

	return LogicResult::render($page_vars);
}

?>
