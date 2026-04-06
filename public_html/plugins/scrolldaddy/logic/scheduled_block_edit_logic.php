<?php

function scheduled_block_edit_logic($get_vars, $post_vars){

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/devices_class.php'));
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

	if(isset($_POST['action']) && $_POST['action'] == 'delete'){
		// DELETE A SCHEDULED BLOCK
		$block_id = LibraryFunctions::fetch_variable_local($post_vars, 'block_id', NULL, 'required', 'Block id is required.', 'safemode', 'int');
		$block = new SdScheduledBlock($block_id, TRUE);
		$block->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));

		$device_id = $block->get('sdb_sdd_device_id');
		$block->permanent_delete();

		return LogicResult::redirect('/profile/devices');
	}
	else if(isset($_POST['action']) && $_POST['action'] == 'edit'){
		// CREATE OR EDIT A SCHEDULED BLOCK
		$device_id = LibraryFunctions::fetch_variable_local($post_vars, 'device_id', NULL, 'required', 'Device id is required.', 'safemode', 'int');
		$device = new SdDevice($device_id, TRUE);
		$device->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));

		$block_id = LibraryFunctions::fetch_variable_local($post_vars, 'block_id', NULL, '', '', 'safemode', 'int');

		if($block_id){
			// Edit existing
			$block = new SdScheduledBlock($block_id, TRUE);
			$block->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		}
		else{
			// Create new
			$block = new SdScheduledBlock(NULL);
			$block->set('sdb_sdd_device_id', $device->key);
			$block->set('sdb_is_active', true);
			$block->save();
			$block->load();
		}

		// Save schedule fields
		$name = LibraryFunctions::fetch_variable_local($post_vars, 'sdb_name', '', '', '', 'safemode', NULL);
		$start_time = LibraryFunctions::fetch_variable_local($post_vars, 'start_time', '', '', '', 'safemode', NULL);
		$end_time = LibraryFunctions::fetch_variable_local($post_vars, 'end_time', '', '', '', 'safemode', NULL);
		$days_blocked = isset($post_vars['days_blocked']) ? $post_vars['days_blocked'] : array();

		$block->set('sdb_name', $name);

		if($start_time !== '' && $end_time !== '' && !empty($days_blocked)){
			$block->set('sdb_schedule_start', strip_tags($start_time));
			$block->set('sdb_schedule_end', strip_tags($end_time));
			$block->set('sdb_schedule_days', json_encode($days_blocked));
			$block->set('sdb_schedule_timezone', $device->get('sdd_timezone'));
		}

		$block->save();

		// Update filter and service rules
		$block->update_filters($post_vars);
		$block->update_services($post_vars);

		return LogicResult::redirect('/profile/devices');
	}
	else{
		// GET - LOAD FORM DATA
		$device_id = LibraryFunctions::fetch_variable_local($get_vars, 'device_id', NULL, 'required', 'Device id is required.', 'safemode', 'int');
		$device = new SdDevice($device_id, TRUE);
		$device->authenticate_read(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$page_vars['device'] = $device;

		$block_id = LibraryFunctions::fetch_variable_local($get_vars, 'block_id', NULL, '', '', 'safemode', 'int');

		if($block_id){
			$block = new SdScheduledBlock($block_id, TRUE);
			$block->authenticate_read(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		}
		else{
			// New block — empty object
			$block = new SdScheduledBlock(NULL);
		}
		$page_vars['block'] = $block;

		// Load existing filter and service rules for display
		$page_vars['filter_rules'] = $block_id ? $block->get_filter_rules() : array();
		$page_vars['service_rules'] = $block_id ? $block->get_service_rules() : array();
	}

	return LogicResult::render($page_vars);
}

?>
