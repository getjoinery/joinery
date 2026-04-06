<?php

function rules_logic($get_vars, $post_vars){

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/devices_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/profiles_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/rules_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/scheduled_blocks_class.php'));

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

	// Determine context: base profile (device_id) or scheduled block (block_id)
	// Check both POST and GET — the hidden input carries it on form submit, query string on GET
	$block_id = LibraryFunctions::fetch_variable_local($post_vars, 'block_id', NULL, '', '', 'safemode', 'int');
	if (!$block_id) {
		$block_id = LibraryFunctions::fetch_variable_local($get_vars, 'block_id', NULL, '', '', 'safemode', 'int');
	}
	$page_vars['block_id'] = $block_id;
	$page_vars['context'] = $block_id ? 'block' : 'base';

	if(isset($_POST['action']) && $_POST['action'] == 'delete'){
		$rule_id = LibraryFunctions::fetch_variable_local($post_vars, 'rule_id', 0, 'required', 'Rule choice is required.', 'safemode', NULL);

		if($block_id){
			$block = new SdScheduledBlock($block_id, TRUE);
			$block->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
			$block->delete_rule($rule_id);
			$device_id = $block->get('sdb_sdd_device_id');
			return LogicResult::redirect('/profile/scrolldaddy/rules?device_id='.$device_id.'&block_id='.$block_id);
		}
		else{
			$device_id = LibraryFunctions::fetch_variable_local($post_vars, 'device_id', NULL, 'required', 'Device id is required.', 'safemode', 'int');
			$device = new SdDevice($device_id, TRUE);
			$device->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
			$profile = new SdProfile($device->get('sdd_sdp_profile_id_primary'), TRUE);
			$profile->delete_rule($rule_id);
			return LogicResult::redirect('/profile/scrolldaddy/rules?device_id='.$device->key);
		}
	}
	else if(isset($_POST['sdr_hostname'])){

		if($block_id){
			$block = new SdScheduledBlock($block_id, TRUE);
			$block->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
			$block->add_rule($_POST['sdr_hostname'], $_POST['sdr_action']);
			$device_id = $block->get('sdb_sdd_device_id');
			return LogicResult::redirect('/profile/scrolldaddy/rules?device_id='.$device_id.'&block_id='.$block_id);
		}
		else{
			$device_id = LibraryFunctions::fetch_variable_local($post_vars, 'device_id', NULL, 'required', 'Device id is required.', 'safemode', 'int');
			$device = new SdDevice($device_id, TRUE);
			$device->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
			$profile = new SdProfile($device->get('sdd_sdp_profile_id_primary'), TRUE);
			$profile->add_rule($_POST['sdr_hostname'], $_POST['sdr_action']);
			return LogicResult::redirect('/profile/scrolldaddy/rules?device_id='.$device->key);
		}
	}
	else{
		$device_id = LibraryFunctions::fetch_variable_local($get_vars, 'device_id', NULL, 'required', 'Device id is required.', 'safemode', 'int');
		$device = new SdDevice($device_id, TRUE);
		$device->authenticate_read(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$page_vars['device'] = $device;

		if($block_id){
			$block = new SdScheduledBlock($block_id, TRUE);
			$block->authenticate_read(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
			$page_vars['block'] = $block;

			$rules = new MultiSdScheduledBlockRule(
				array('block_id' => $block->key)
			);
			$rules->load();
		}
		else{
			$profile = new SdProfile($device->get('sdd_sdp_profile_id_primary'), TRUE);
			$page_vars['profile'] = $profile;

			$rules = new MultiSdRule(
				array('profile_id' => $profile->key)
			);
			$rules->load();
		}

		$page_vars['rules'] = $rules;
	}

	return LogicResult::render($page_vars);
}

?>
