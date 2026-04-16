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
		// DELETE A SCHEDULED BLOCK (always-on blocks can't be deleted from the UI)
		$block_id = LibraryFunctions::fetch_variable_local($post_vars, 'block_id', NULL, 'required', 'Block id is required.', 'safemode', 'int');
		$block = new SdScheduledBlock($block_id, TRUE);
		$block->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));

		if($block->get('sdb_is_always_on')){
			return LogicResult::error("The always-on block cannot be deleted.");
		}

		$block->permanent_delete();

		return LogicResult::redirect('/profile/scrolldaddy/devices');
	}
	else if(isset($_POST['action']) && $_POST['action'] == 'edit'){
		// CREATE OR EDIT A BLOCK
		$device_id = LibraryFunctions::fetch_variable_local($post_vars, 'device_id', NULL, 'required', 'Device id is required.', 'safemode', 'int');
		$device = new SdDevice($device_id, TRUE);
		$device->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));

		$block_id = LibraryFunctions::fetch_variable_local($post_vars, 'block_id', NULL, '', '', 'safemode', 'int');

		if($block_id){
			// Edit existing (could be scheduled or always-on)
			$block = new SdScheduledBlock($block_id, TRUE);
			$block->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		}
		else{
			// Create new scheduled block — the always-on block is auto-created per device
			// so this path is exclusively for scheduled blocks. Check the limit against non-always-on blocks only.
			$max_blocks = SubscriptionTier::getUserFeature($user->key, 'scrolldaddy_max_scheduled_blocks', 1);
			$existing_blocks = new MultiSdScheduledBlock(['device_id' => $device->key, 'is_always_on' => false]);
			if($existing_blocks->count_all() >= $max_blocks){
				return LogicResult::error("Your plan allows {$max_blocks} scheduled block(s) per device. Upgrade to add more.");
			}

			$block = new SdScheduledBlock(NULL);
			$block->set('sdb_sdd_device_id', $device->key);
			$block->set('sdb_is_active', true);
			$block->set('sdb_is_always_on', false);
			$block->save();
			$block->load();
		}

		$is_always_on = (bool)$block->get('sdb_is_always_on');

		// Name: only editable for scheduled blocks; always-on block keeps its fixed label
		if(!$is_always_on){
			$name = LibraryFunctions::fetch_variable_local($post_vars, 'sdb_name', '', '', '', 'safemode', NULL);
			$block->set('sdb_name', $name);
		}

		// Schedule: only applies to scheduled blocks
		if(!$is_always_on){
			$start_time = LibraryFunctions::fetch_variable_local($post_vars, 'start_time', '', '', '', 'safemode', NULL);
			$end_time = LibraryFunctions::fetch_variable_local($post_vars, 'end_time', '', '', '', 'safemode', NULL);
			$days_blocked = isset($post_vars['days_blocked']) ? $post_vars['days_blocked'] : array();

			if($start_time !== '' && $end_time !== '' && !empty($days_blocked)){
				$block->set('sdb_schedule_start', strip_tags($start_time));
				$block->set('sdb_schedule_end', strip_tags($end_time));
				$block->set('sdb_schedule_days', json_encode($days_blocked));
				$block->set('sdb_schedule_timezone', $device->get('sdd_timezone'));
			}
		}

		$block->save();

		// Strip restricted filters for users without advanced_filters
		if(!SubscriptionTier::getUserFeature($user->key, 'scrolldaddy_advanced_filters', false)){
			require_once(PathHelper::getIncludePath('plugins/scrolldaddy/includes/ScrollDaddyHelper.php'));
			foreach(ScrollDaddyHelper::getRestrictedFilters() as $restricted_key){
				unset($post_vars['rule_'.$restricted_key]);
			}
		}

		// Update filter and service rules
		$block->update_filters($post_vars);
		$block->update_services($post_vars);

		return LogicResult::redirect('/profile/scrolldaddy/devices');
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
			// New scheduled block — empty object
			$block = new SdScheduledBlock(NULL);
		}
		$page_vars['block'] = $block;

		// Load existing filter/service/domain rules for display
		require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/scheduled_block_rules_class.php'));
		$page_vars['filter_rules'] = $block_id ? $block->get_filter_rules() : array();
		$page_vars['service_rules'] = $block_id ? $block->get_service_rules() : array();

		if($block_id){
			$domain_rules = new MultiSdScheduledBlockRule(['block_id' => $block->key]);
			$domain_rules->load();
			$page_vars['domain_rules'] = $domain_rules;
		}
		else{
			$page_vars['domain_rules'] = array();
		}
	}

	return LogicResult::render($page_vars);
}

?>
