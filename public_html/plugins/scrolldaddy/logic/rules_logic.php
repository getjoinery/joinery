<?php

function rules_logic($get_vars, $post_vars){

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/devices_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/profiles_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/rules_class.php'));

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
	if(isset($_POST['action']) && $_POST['action'] == 'delete'){
	
		$profile_choice = LibraryFunctions::fetch_variable_local($post_vars, 'profile_choice', 0, 'required', 'Profile choice is required.', 'safemode', NULL);
		$rule_id = LibraryFunctions::fetch_variable_local($post_vars, 'rule_id', 0, 'required', 'Rule choice is required.', 'safemode', NULL);
		$page_vars['profile_choice'] = $profile_choice;
	
		$device_id = LibraryFunctions::fetch_variable_local($post_vars, 'device_id', NULL, 'required', 'Device id is required.', 'safemode', 'int');
		$device = new SdDevice($device_id, TRUE);
		$device->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$page_vars['device'] = $device;

		if($profile_choice == 'primary'){
			$profile = new SdProfile($device->get('sdd_sdp_profile_id_primary'), TRUE);
		}
		else{
			$profile = new SdProfile($device->get('sdd_sdp_profile_id_secondary'), TRUE);
		}
		$page_vars['profile'] = $profile;

		$result = $profile->delete_rule($rule_id);
		
		return LogicResult::redirect('/profile/rules?device_id='.$device->key.'&profile_choice='.$profile_choice);

	}
	else if(isset($_POST['sdr_hostname'])){

		$profile_choice = LibraryFunctions::fetch_variable_local($post_vars, 'profile_choice', 0, 'required', 'Profile choice is required.', 'safemode', NULL);
		$page_vars['profile_choice'] = $profile_choice;

		$device_id = LibraryFunctions::fetch_variable_local($post_vars, 'device_id', NULL, 'required', 'Device id is required.', 'safemode', 'int');
		$device = new SdDevice($device_id, TRUE);
		$device->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$page_vars['device'] = $device;

		if($profile_choice == 'primary'){
			$profile = new SdProfile($device->get('sdd_sdp_profile_id_primary'), TRUE);
		}
		else{
			$profile = new SdProfile($device->get('sdd_sdp_profile_id_secondary'), TRUE);
		}
		$page_vars['profile'] = $profile;

		$result = $profile->add_rule($_POST['sdr_hostname'], $_POST['sdr_action']);

		return LogicResult::redirect('/profile/rules?device_id='.$device->key.'&profile_choice='.$profile_choice);
	}
	else{
		$profile_choice = LibraryFunctions::fetch_variable_local($get_vars, 'profile_choice', 0, 'required', 'Profile choice is required.', 'safemode', NULL);
		$page_vars['profile_choice'] = $profile_choice;
		
		$device_id = LibraryFunctions::fetch_variable_local($get_vars, 'device_id', NULL, 'required', 'Device id is required.', 'safemode', 'int');
		$device = new SdDevice($device_id, TRUE);
		$device->authenticate_read(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$page_vars['device'] = $device;

		if($profile_choice == 'primary'){
			$profile = new SdProfile($device->get('sdd_sdp_profile_id_primary'), TRUE);
		}
		else{
			$profile = new SdProfile($device->get('sdd_sdp_profile_id_secondary'), TRUE);
		}
		$page_vars['profile'] = $profile;

		$rules = new MultiSdRule(
				array(
					'profile_id' => $profile->key,
				),
			);
			$rules->load();

		//$page_vars['num_filters'] = $num_devices;
		//$rules_out = array();
		//foreach($rules as $rule){
		//	$filter_out[$filter->get('sdf_filter_key')] = $filter->get('sdf_is_active');
		//}

		$page_vars['rules'] = $rules;

	}

	return LogicResult::render($page_vars);
}
	
?>
